<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Models\Resident;
use App\Support\QrAttendanceProof;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shared behaviour for the Clerk, PWD and Senior attendance sheets.
 *
 * Attendance rows are unique per (announcement_id, resident_id), matching the
 * unique index defined in the announcements migration. Eligibility is derived
 * from the announcement audience and the resident_sector membership table.
 */
trait ManagesAnnouncementAttendance
{
    /**
     * Announcement audiences that are restricted to a resident sector slug.
     * An audience absent from this map (e.g. "all") is open to every active
     * resident in the barangay.
     */
    private const AUDIENCE_SECTOR_SLUGS = [
        'senior_citizens' => 'senior',
        'pwd' => 'pwd',
    ];

    /**
     * Attendance methods emitted by the staff attendance screens.
     */
    private const ATTENDANCE_METHODS = [
        'manual',
        'qr_scanner',
    ];

    /**
     * Attendance may only be recorded for an announcement the administrator
     * has already approved and that is explicitly marked as requiring
     * attendance. Enforced server-side so it cannot be bypassed by posting
     * directly to the mark endpoint.
     */
    protected function ensureAttendanceIsOpen(Announcement $announcement): void
    {
        if (! $announcement->attendanceIsAvailable()) {
            abort(403, 'Attendance cannot be recorded for this announcement.');
        }
    }

    /**
     * Active residents in the session barangay who are eligible for the event.
     */
    protected function eligibleResidentsQuery(Announcement $announcement, int $barangayId): Builder
    {
        $query = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active');

        $sectorSlug = self::AUDIENCE_SECTOR_SLUGS[$announcement->target_audience] ?? null;

        if ($sectorSlug !== null) {
            $query->whereHas('sectors', function (Builder $query) use ($sectorSlug) {
                $query->where('slug', $sectorSlug);
            });
        }

        return $query->orderBy('last_name');
    }

    /**
     * Resolve a resident that is allowed to be marked for the event.
     *
     * @throws ModelNotFoundException
     */
    protected function findEligibleResident(Announcement $announcement, int $residentId, int $barangayId): Resident
    {
        $resident = Resident::query()
            ->where('id', $residentId)
            ->where('barangay_id', $barangayId)
            ->firstOrFail();

        if ($resident->status !== 'active') {
            abort(403, 'This resident is not eligible for this event.');
        }

        $sectorSlug = self::AUDIENCE_SECTOR_SLUGS[$announcement->target_audience] ?? null;

        if ($sectorSlug !== null && ! $resident->sectors()->where('slug', $sectorSlug)->exists()) {
            abort(403, 'This resident is not eligible for this event.');
        }

        return $resident;
    }

    /**
     * Enforce that QR-scanner attendance carries a server-issued proof.
     *
     * Manual attendance does not require a proof. A QR-scanner submission is
     * only accepted when it presents a valid, unused proof minted by the staff
     * QR resolution endpoint for this exact (staff, resident, announcement)
     * operation. The proof is consumed here, so it cannot be replayed for a
     * second attendance record.
     *
     * @throws ValidationException
     */
    protected function ensureQrProofIsValid(
        Request $request,
        Announcement $announcement,
        Resident $resident,
        ?string $method,
    ): void {
        if ($method !== 'qr_scanner') {
            return;
        }

        $proof = (string) $request->input('qr_proof', '');

        if ($proof === '') {
            throw ValidationException::withMessages([
                'qr_proof' => 'A valid QR proof is required for QR-scanner attendance.',
            ]);
        }

        QrAttendanceProof::consume($proof, $request->user(), $resident->id, $announcement->id);
    }

    /**
     * Create the attendance row for a resident without ever replacing an
     * existing record.
     *
     * The read below is only a fast path for the common duplicate case. The
     * database unique index on (announcement_id, resident_id) remains the
     * authority for concurrent first submissions: if two requests both observe
     * no existing row, exactly one insert succeeds and the other catches the
     * unique-constraint violation, re-reads the winner, and reports a duplicate.
     *
     * Existing rows are intentionally never updated in this phase. In
     * particular, a present row cannot be changed to claimed because the
     * single status column would erase the original attendance metadata.
     *
     * @return array{outcome: string, message: string, tone: string}
     */
    protected function recordAttendance(
        Announcement $announcement,
        Resident $resident,
        string $status,
        ?string $method,
        ?int $verifiedBy,
    ): array {
        $existing = $this->findAttendanceRecord($announcement, $resident);

        if ($status === 'claimed' && ($existing === null || $existing->status !== 'present')) {
            return [
                'outcome' => 'claim_requires_presence',
                'message' => 'This resident is not present.',
                'tone' => 'warning',
            ];
        }

        if ($existing !== null) {
            return $this->existingAttendanceOutcome($existing, $status);
        }

        $now = now();

        try {
            AnnouncementAttendance::create([
                'barangay_id' => $announcement->barangay_id,
                'announcement_id' => $announcement->id,
                'resident_id' => $resident->id,
                'status' => $status,
                'method' => $method ?? 'manual',
                'attended_at' => $now,
                'verified_by' => $verifiedBy,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findAttendanceRecord($announcement, $resident);

            if ($existing === null) {
                throw $exception;
            }

            return $this->existingAttendanceOutcome($existing, $status);
        }

        return [
            'outcome' => 'recorded',
            'message' => "Attendance recorded successfully for {$resident->full_name}.",
            'tone' => 'success',
        ];
    }

    /**
     * Build the redirect payload for an attendance attempt.
     *
     * The structured outcome lets the frontend distinguish a new attendance
     * from a duplicate without inferring anything from stale page props.
     *
     * @param  array{outcome: string, message: string, tone: string}  $result
     */
    protected function attendanceResponse(array $result): RedirectResponse
    {
        return redirect()->back()->with([
            'attendance_outcome' => $result['outcome'],
            $result['tone'] => $result['message'],
        ]);
    }

    private function findAttendanceRecord(Announcement $announcement, Resident $resident): ?AnnouncementAttendance
    {
        return AnnouncementAttendance::query()
            ->where('barangay_id', $announcement->barangay_id)
            ->where('announcement_id', $announcement->id)
            ->where('resident_id', $resident->id)
            ->first();
    }

    /**
     * @return array{outcome: string, message: string, tone: string}
     */
    private function existingAttendanceOutcome(AnnouncementAttendance $existing, string $requestedStatus): array
    {
        if ($existing->status === 'claimed') {
            return [
                'outcome' => 'already_claimed',
                'message' => 'Already claimed. This attendee has already claimed their aid.',
                'tone' => 'warning',
            ];
        }

        if ($existing->status === 'present' && $requestedStatus === 'present') {
            return [
                'outcome' => 'already_present',
                'message' => 'Already present. This attendee has already been scanned.',
                'tone' => 'warning',
            ];
        }

        return [
            'outcome' => 'existing_record_conflict',
            'message' => sprintf(
                'This attendee already has an attendance record with status "%s". Changing it to "%s" would overwrite the original attendance details, which is not supported in this phase. No changes were made.',
                $existing->status,
                $requestedStatus,
            ),
            'tone' => 'warning',
        ];
    }
}
