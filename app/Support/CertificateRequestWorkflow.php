<?php

namespace App\Support;

use App\Models\CertificateRequest;
use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for certificate-request status transitions.
 *
 * Clerks own individual request processing. Administrators retain aggregate
 * monitoring only, so this workflow is never exposed through an admin route.
 */
final class CertificateRequestWorkflow
{
    /**
     * Canonical certificate request statuses used by the resident tracker.
     */
    private const STATUSES = [
        'Pending',
        'Needs Action',
        'For Payment',
        'Processing',
        'Ready for Claim',
        'Released',
        'Rejected',
    ];

    /**
     * Allowed status transitions for a certificate request.
     *
     * Derived from the resident tracker sequence:
     * Submitted (Pending) -> [For Payment] -> Processing -> Ready for Claim
     * -> Released, with "Needs Action" available as a correction detour and
     * "Rejected" as a terminal outcome. Released and Rejected are terminal.
     * Re-applying the current status is allowed so the note can be updated
     * without moving the request.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'Pending' => ['Needs Action', 'For Payment', 'Processing', 'Ready for Claim', 'Rejected'],
        'Needs Action' => ['For Payment', 'Processing', 'Ready for Claim', 'Rejected'],
        'For Payment' => ['Needs Action', 'Ready for Claim', 'Processing', 'Rejected'],
        'Processing' => ['For Payment', 'Needs Action', 'Ready for Claim', 'Rejected'],
        'Ready for Claim' => ['Needs Action', 'Released', 'Rejected'],
        'Released' => [],
        'Rejected' => [],
    ];

    /**
     * Statuses that end the workflow and can never be left.
     */
    private const TERMINAL_STATUSES = [
        'Released',
        'Rejected',
    ];

    /**
     * Statuses that map directly to a dedicated timestamp column.
     */
    private const STATUS_TIMESTAMPS = [
        'Processing' => 'processing_at',
        'Ready for Claim' => 'ready_for_claim_at',
        'Released' => 'released_at',
        'Rejected' => 'rejected_at',
    ];

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /**
     * The current status plus every status it may move to. Re-applying the
     * current status remains available for note-only updates.
     *
     * @return array<int, string>
     */
    public static function allowedStatuses(string $currentStatus): array
    {
        return array_values(array_unique([
            $currentStatus,
            ...(self::TRANSITIONS[$currentStatus] ?? []),
        ]));
    }

    /**
     * Statuses available through the generic status dropdown.
     *
     * Protected statuses (For Payment, Ready for Claim, Released) require dedicated
     * workflow actions (issuance, clerk payment confirmation, clerk physical release)
     * and are excluded from the generic dropdown unless they match the current status
     * (which allows re-applying the status for note updates).
     *
     * @return array<int, string>
     */
    public static function genericStatuses(string $currentStatus): array
    {
        $protected = ['For Payment', 'Ready for Claim', 'Released'];

        return array_values(array_filter(
            self::allowedStatuses($currentStatus),
            static fn (string $status) => $status === $currentStatus || ! in_array($status, $protected, true),
        ));
    }

    /**
     * Validate and persist a status update.
     *
     * @throws ValidationException when the transition is not allowed.
     */
    public static function apply(
        CertificateRequest $certificateRequest,
        string $status,
        ?string $adminNote,
    ): bool {
        $currentStatus = $certificateRequest->status;

        if ($status !== $currentStatus) {
            if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$currentStatus} request is final and cannot change status.",
                ]);
            }

            if (! in_array($status, self::TRANSITIONS[$currentStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "A request cannot move from \"{$currentStatus}\" to \"{$status}\".",
                ]);
            }
        }

        $attributes = [
            'status' => $status,
            'admin_note' => $adminNote,
        ];

        if ($status !== 'Pending' && $certificateRequest->reviewed_at === null) {
            $attributes['reviewed_at'] = now();
        }

        $timestampColumn = self::STATUS_TIMESTAMPS[$status] ?? null;

        if ($timestampColumn !== null && $certificateRequest->{$timestampColumn} === null) {
            $attributes[$timestampColumn] = now();
        }

        $certificateRequest->update($attributes);

        return $certificateRequest->wasChanged('status');
    }
}
