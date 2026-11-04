<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesAnnouncementAttendance;
use App\Models\Announcement;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\OfflineIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfflineAttendanceController extends Controller
{
    use ManagesAnnouncementAttendance;

    private function authorizeSheet(Request $request, string $department, Announcement $announcement): void
    {
        $user = $request->user();
        abort_unless(in_array($department, ['clerk', 'senior', 'pwd'], true) && $user?->hasActiveAssignment([$department]), 403);
        abort_unless((int) $user->barangay_id === (int) $announcement->barangay_id, 404);
        if ($department !== 'clerk') {
            abort_unless($announcement->target_audience === ($department === 'senior' ? 'senior_citizens' : 'pwd'), 403);
        }
    }

    private function owner(Request $request): string
    {
        return hash_hmac('sha256', 'offline-attendance:'.$request->user()->id.':'.$request->user()->barangay_id, (string) config('app.key'));
    }

    public function context(Request $request, string $department, Announcement $announcement): JsonResponse
    {
        $this->authorizeSheet($request, $department, $announcement);
        $this->ensureAttendanceIsOpen($announcement);
        $residents = $this->eligibleResidentsQuery($announcement, $request->user()->barangay_id)
            ->with('qrCredential:id,resident_id,token,is_active,revoked_at')->limit(10001)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'resident_number']);
        abort_if($residents->count() > 10000, 422, 'This roster is too large to prepare on this device.');
        $expires = now()->addDay()->timestamp;
        $owner = $this->owner($request);

        return response()->json([
            'owner' => $owner, 'scope' => OfflineIdentity::scope($request),
            'key' => base64_encode(hash_hmac('sha256', 'attendance-key:'.$owner, (string) config('app.key'), true)),
            'event' => ['id' => $announcement->id, 'title' => $announcement->title, 'department' => $department],
            'expiresAt' => $expires * 1000,
            'grant' => Crypt::encryptString(json_encode(['owner' => $owner, 'event' => $announcement->id, 'department' => $department, 'expires' => $expires], JSON_THROW_ON_ERROR)),
            'residents' => $residents->map(fn ($resident) => [
                'id' => $resident->id, 'name' => $resident->full_name, 'number' => $resident->resident_number,
                'qrHash' => $resident->qrCredential?->is_active && ! $resident->qrCredential?->revoked_at ? hash('sha256', $resident->qrCredential->token) : null,
            ]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function sync(Request $request, string $department, Announcement $announcement): JsonResponse
    {
        $this->authorizeSheet($request, $department, $announcement);
        $data = $request->validate([
            'client_request_id' => ['required', 'uuid'], 'offline_owner' => ['required', 'string', 'size:64'],
            'grant' => ['required', 'string', 'max:4096'], 'resident_id' => ['required', 'integer'],
            'status' => ['required', 'in:present,claimed'], 'method' => ['required', 'in:manual,qr_scanner'],
            'qr_token' => ['nullable', 'required_if:method,qr_scanner', 'string', 'max:2048'],
            'captured_at' => ['required', 'date'],
        ]);
        abort_unless(hash_equals($this->owner($request), $data['offline_owner']), 403);
        try {
            $grant = json_decode(Crypt::decryptString($data['grant']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['grant' => 'Invalid offline attendance preparation.']);
        }
        abort_unless(($grant['owner'] ?? null) === $data['offline_owner'] && ($grant['event'] ?? null) === $announcement->id && ($grant['department'] ?? null) === $department, 403);
        $hash = hash('sha256', json_encode([$announcement->id, $data['resident_id'], $data['status'], $data['method'], $data['qr_token'] ?? null, $data['captured_at']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $announcement, $data, $grant, $hash): JsonResponse {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            // Serialise retries for this event; the attendance unique index also
            // protects simultaneous online/manual submissions from another device.
            $locked = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('offline_attendance_submissions')->where('user_id', $request->user()->id)->where('client_request_id', $data['client_request_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'This local request ID was already used with different details.');

                return response()->json(['result' => json_decode($receipt->result, true), 'replayed' => true]);
            }
            $this->ensureAttendanceIsOpen($locked);
            if (($grant['expires'] ?? 0) < now()->timestamp) {
                throw ValidationException::withMessages(['grant' => 'The offline roster expired. Reopen this attendance sheet online and review the pending entries.']);
            }
            $resident = $this->findEligibleResident($locked, $data['resident_id'], $request->user()->barangay_id);
            if ($data['method'] === 'qr_scanner' && ! $resident->qrCredential()->where('token', $data['qr_token'])->where('is_active', true)->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['qr_token' => 'This QR credential is no longer valid. No attendance was recorded.']);
            }
            $result = $this->recordAttendance($locked, $resident, $data['status'], $data['method'], $request->user()->id);
            DB::table('offline_attendance_submissions')->insert([
                'user_id' => $request->user()->id, 'client_request_id' => $data['client_request_id'],
                'announcement_id' => $locked->id, 'payload_hash' => $hash, 'captured_at' => Carbon::parse($data['captured_at']),
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLogger::record('attendance.offline.synced', $locked->barangay_id, $request->user()->id, 'announcement', $locked->id, ['action' => $result['outcome'], 'new_status' => $data['status']], $request->ip());

            return response()->json(['result' => $result, 'replayed' => false], 201);
        });
    }
}
