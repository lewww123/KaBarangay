<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use App\Models\AnnouncementAttendance;
use App\Models\Resident;
use App\Support\QrAttendanceProof;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        if ($announcement->approval_status !== 'approved' || ! $announcement->requires_attendance) {
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
     * Atomically create or update the attendance row for a resident.
     *
     * The insert-or-update is performed by the database against the
     * (announcement_id, resident_id) unique index, so concurrent manual and QR
     * submissions resolve to a single logical record instead of raising a
     * duplicate-key error.
     */
    protected function recordAttendance(
        Announcement $announcement,
        Resident $resident,
        string $status,
        ?string $method,
        ?int $verifiedBy,
    ): void {
        $now = now();

        AnnouncementAttendance::upsert(
            [[
                'barangay_id' => $announcement->barangay_id,
                'announcement_id' => $announcement->id,
                'resident_id' => $resident->id,
                'status' => $status,
                'method' => $method ?? 'manual',
                'attended_at' => $now,
                'verified_by' => $verifiedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['announcement_id', 'resident_id'],
            ['status', 'method', 'attended_at', 'verified_by', 'updated_at'],
        );
    }
}
