<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Resident;
use App\Support\OfflineCertificateContext;
use App\Support\OfflineIdentity;
use App\Support\ResidentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineCertificateController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $resident = ResidentContext::get(['barangay', 'purok']);

        return response()->json([
            'owner' => OfflineCertificateContext::owner($resident),
            'key' => OfflineCertificateContext::encryptionKey($resident),
            'scope' => OfflineIdentity::scope($request),
            'resident' => ['name' => $resident->full_name, 'barangay' => $resident->barangay?->name, 'purok' => $resident->purok?->name],
            'certificates' => Certificate::where('barangay_id', $resident->barangay_id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Certificate $certificate) => [...OfflineCertificateContext::certificate($certificate), 'revision' => OfflineCertificateContext::revision($certificate)])->all(),
            'preparedAt' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function sync(Request $request): JsonResponse
    {
        $envelope = $request->validate([
            'client_request_id' => ['required', 'uuid'],
            'catalog_revision' => ['required', 'string', 'size:64'],
            'certificate_id' => ['required', 'integer'],
            'offline_owner' => ['required', 'string', 'size:64'],
        ]);
        $resident = ResidentContext::get();
        abort_unless(hash_equals(OfflineCertificateContext::owner($resident), $envelope['offline_owner']), 403, 'Sign in to the resident account that created this local request.');
        $payload = $request->only(['certificate_id', 'catalog_revision', 'purpose', 'business_name', 'business_address', 'additional_details', 'details']);
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($resident, $request, $envelope, $hash) {
            Resident::whereKey($resident->id)->lockForUpdate()->firstOrFail();
            $existing = CertificateRequest::where('resident_id', $resident->id)->where('client_request_id', $envelope['client_request_id'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->client_payload_hash, $hash)) {
                    return response()->json(['code' => 'idempotency_conflict', 'message' => 'This local request ID was already submitted with different details. Review the existing request before submitting again.'], 409);
                }

                return $this->accepted($existing, true);
            }
            $certificate = Certificate::where('barangay_id', $resident->barangay_id)->where('is_active', true)->whereKey($envelope['certificate_id'])->lockForUpdate()->first();
            if (! $certificate) {
                return response()->json(['code' => 'catalog_changed', 'message' => 'This certificate is no longer available in your barangay. Refresh the catalog and review this draft.'], 409);
            }
            if (! hash_equals(OfflineCertificateContext::revision($certificate), $envelope['catalog_revision'])) {
                return response()->json(['code' => 'catalog_changed', 'message' => 'The certificate fee, requirements or form details changed. Refresh the catalog and review the current information before submitting.'], 409);
            }

            $created = app(CertificateRequestController::class)->createValidatedRequest($request, [
                'client_request_id' => $envelope['client_request_id'], 'client_payload_hash' => $hash,
            ]);

            return $this->accepted($created, false);
        });
    }

    private function accepted(CertificateRequest $request, bool $replayed): JsonResponse
    {
        return response()->json([
            'reference' => $request->reference, 'id' => $request->id,
            'status' => $request->status, 'fee' => $request->fee, 'replayed' => $replayed,
            'message' => 'The server accepted this request for barangay review.',
        ], $replayed ? 200 : 201);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
