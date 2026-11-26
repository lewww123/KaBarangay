<?php

namespace App\Support;

use App\Models\CertificateRequest;
use App\Models\Collection as CollectionModel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class CertificatePaymentLedger
{
    public static function normalizeOr(string $orNumber): string
    {
        return mb_strtoupper(trim($orNumber));
    }

    public static function resolveOr(mixed $value): ?string
    {
        $normalized = self::normalizeOr(is_string($value) ? $value : '');

        return $normalized === '' ? null : $normalized;
    }

    public static function assertUniqueOr(?string $orNumber, ?string $ignoreId = null): void
    {
        if ($orNumber === null || $orNumber === '') {
            return;
        }

        $duplicate = CollectionModel::query()
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereRaw('UPPER(TRIM(or_number)) = ?', [$orNumber])
            ->exists()
            || CertificateRequest::query()
                ->whereRaw('UPPER(TRIM(or_number)) = ?', [$orNumber])
                ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'or_number' => 'This OR number is already recorded.',
            ]);
        }
    }

    public static function isDuplicateOrException(QueryException $exception): bool
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $code === 1062
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    public static function assertWaiverRules(
        bool $isWaived,
        float $amount,
        ?string $reason,
        ?string $orNumber,
    ): void {
        if (! $isWaived && ($orNumber === null || $orNumber === '')) {
            throw ValidationException::withMessages([
                'or_number' => 'An OR number is required unless the collection is waived.',
            ]);
        }

        if ($isWaived && $amount !== 0.0) {
            throw ValidationException::withMessages([
                'amount' => 'A waived collection must have an amount of zero.',
            ]);
        }

        if ($isWaived && trim((string) $reason) === '') {
            throw ValidationException::withMessages([
                'waive_reason' => 'A reason is required for waived collections.',
            ]);
        }

        if (! $isWaived && $amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A non-waived collection must have an amount greater than zero.',
            ]);
        }
    }

    public static function assertRequestAmount(
        CertificateRequest $certificateRequest,
        float $amount,
        bool $isWaived,
    ): void {
        $fee = round((float) $certificateRequest->fee, 2);

        if ($fee <= 0) {
            throw ValidationException::withMessages([
                'status' => 'Only certificate requests with a fee await payment.',
            ]);
        }

        if (! $isWaived && round($amount, 2) !== $fee) {
            throw ValidationException::withMessages([
                'amount' => 'The amount must match the assessed certificate fee.',
            ]);
        }
    }

    public static function assertNoActiveCertificateCollection(
        CertificateRequest $certificateRequest,
        ?string $collectionId = null,
    ): void {
        $query = CollectionModel::query()
            ->where(function ($query) use ($certificateRequest, $collectionId) {
                $query->where('certificate_request_id', $certificateRequest->id);

                if ($certificateRequest->collection_id !== null) {
                    $query->orWhere('id', $certificateRequest->collection_id);
                }

                if ($collectionId !== null) {
                    $query->orWhere('id', $collectionId);
                }
            })
            ->where('status', '!=', 'Voided');

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'collection' => 'An active collection already exists for this certificate request.',
            ]);
        }
    }

    public static function finalizeForCertificateRequest(
        CertificateRequest $certificateRequest,
        array $payment,
        int $actorId,
    ): CollectionModel {
        $isWaived = (bool) ($payment['is_waived'] ?? false);
        $amount = round((float) ($payment['amount'] ?? 0), 2);
        $orNumber = self::resolveOr($payment['or_number'] ?? null);
        $waiveReason = $payment['waive_reason'] ?? null;

        self::assertWaiverRules($isWaived, $amount, $waiveReason, $orNumber);
        self::assertRequestAmount($certificateRequest, $amount, $isWaived);
        self::assertNoActiveCertificateCollection($certificateRequest);
        self::assertUniqueOr($orNumber);

        $certificate = $certificateRequest->certificate()->first();
        $resident = $certificateRequest->resident()->first();

        $collection = CollectionModel::create([
            'collection_date' => now()->toDateString(),
            'collection_type' => $certificate?->name ?? 'Certificate Fee',
            'amount' => $amount,
            'or_number' => $orNumber,
            'payee_name' => $resident?->full_name ?? 'Unknown Resident',
            'certificate_request_id' => $certificateRequest->id,
            'is_waived' => $isWaived,
            'waive_reason' => $isWaived ? $waiveReason : null,
            'status' => 'Verified',
            'encoded_by' => $actorId,
            'verified_by' => $actorId,
            'verified_at' => now(),
        ]);

        $certificateRequest->update([
            'collection_id' => $collection->id,
            'or_number' => $collection->or_number,
            'amount_paid' => $collection->amount,
            'is_waived' => $isWaived,
        ]);

        return $collection;
    }
}
