<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CertificateRequestController extends Controller
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
     * Re-applying the current status is allowed so an admin can update the note
     * without moving the request.
     */
    private const TRANSITIONS = [
        'Pending' => ['Needs Action', 'For Payment', 'Processing', 'Rejected'],
        'Needs Action' => ['For Payment', 'Processing', 'Rejected'],
        'For Payment' => ['Needs Action', 'Processing', 'Rejected'],
        'Processing' => ['For Payment', 'Needs Action', 'Ready for Claim', 'Rejected'],
        'Ready for Claim' => ['Needs Action', 'Released', 'Rejected'],
        'Released' => [],
        'Rejected' => [],
    ];

    /**
     * Statuses that end the workflow. A normal status update must never move
     * a request out of one of these statuses. The transition map leaves them
     * empty; this set makes the terminal invariant explicit so it cannot be
     * broken by editing the map alone.
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

    public function index(Request $request)
    {
        $barangayId = $request->user()->barangay_id;

        $search = trim((string) $request->query('search', ''));
        $requestedStatus = trim((string) $request->query('status', ''));

        // Treat a missing, empty, "ALL", or otherwise unknown status as no
        // filter so arbitrary input can never reach the query builder.
        $statusFilter = in_array($requestedStatus, self::STATUSES, true)
            ? $requestedStatus
            : null;

        // Every token must match at least one searchable field (AND across
        // tokens). LIKE wildcards are stripped so wildcard-only input cannot
        // silently match every request.
        $tokens = collect(preg_split('/\s+/', $search) ?: [])
            ->map(fn (string $token) => str_replace(['%', '_'], '', $token))
            ->filter(fn (string $token) => $token !== '')
            ->values();

        // Barangay scoping is applied first; the search predicate is wrapped in
        // its own grouped clause so its OR conditions can never escape the
        // administrator's barangay restriction.
        $query = CertificateRequest::query()
            ->where('barangay_id', $barangayId);

        if ($search !== '') {
            if ($tokens->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                foreach ($tokens as $token) {
                    $term = '%'.$token.'%';

                    $query->where(function ($group) use ($term) {
                        $group->where('reference', 'like', $term)
                            ->orWhere('purpose', 'like', $term)
                            ->orWhereHas('certificate', function ($certificate) use ($term) {
                                $certificate->where('name', 'like', $term);
                            })
                            ->orWhereHas('resident', function ($resident) use ($term) {
                                $resident->where('first_name', 'like', $term)
                                    ->orWhere('middle_name', 'like', $term)
                                    ->orWhere('last_name', 'like', $term)
                                    ->orWhere('suffix', 'like', $term)
                                    ->orWhere('resident_number', 'like', $term);
                            });
                    });
                }
            }
        }

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        $requests = $query
            ->with([
                'resident:id,first_name,middle_name,last_name,suffix,resident_number',
                'certificate:id,name,code',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (CertificateRequest $certificateRequest) {
                return [
                    'id' => $certificateRequest->id,
                    'reference' => $certificateRequest->reference,
                    'certificate' => $certificateRequest->certificate?->name ?? 'Certificate',
                    'resident' => [
                        'name' => $certificateRequest->resident?->full_name ?? 'Unknown resident',
                        'resident_number' => $certificateRequest->resident?->resident_number,
                    ],
                    'purpose' => $certificateRequest->purpose,
                    'fee' => (float) $certificateRequest->fee,
                    'status' => $certificateRequest->status,
                    'admin_note' => $certificateRequest->admin_note,
                    'submitted_at' => $certificateRequest->submitted_at?->format('F j, Y') ?? '',
                ];
            });

        // One grouped query replaces the previous seven per-status counts.
        // Statuses with no rows simply fall back to zero below, and the sum of
        // the groups is exactly the barangay's total request count.
        $statusCounts = CertificateRequest::query()
            ->where('barangay_id', $barangayId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = collect(self::STATUSES)
            ->mapWithKeys(fn (string $status) => [
                $status => (int) $statusCounts->get($status, 0),
            ])
            ->all();

        return Inertia::render('Admin/Certificates', [
            'requests' => $requests,
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'byStatus' => $byStatus,
            ],
            'statuses' => self::STATUSES,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter ?? '',
            ],
        ]);
    }

    public function updateStatus(Request $request, CertificateRequest $certificateRequest)
    {
        if ($certificateRequest->barangay_id !== $request->user()->barangay_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:1000', 'required_if:status,Rejected'],
        ]);

        $status = $validated['status'];
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

        // An omitted or empty note clears the existing note. The admin form
        // re-submits the current note, so intentional notes are preserved.
        $attributes = [
            'status' => $status,
            'admin_note' => $validated['admin_note'] ?? null,
        ];

        if ($status !== 'Pending' && $certificateRequest->reviewed_at === null) {
            $attributes['reviewed_at'] = now();
        }

        $timestampColumn = self::STATUS_TIMESTAMPS[$status] ?? null;

        if ($timestampColumn !== null && $certificateRequest->{$timestampColumn} === null) {
            $attributes[$timestampColumn] = now();
        }

        $certificateRequest->update($attributes);

        /*
        |--------------------------------------------------------------------------
        | Audit: certificate request status changed
        |--------------------------------------------------------------------------
        |
        | The request's barangay was verified against the acting administrator
        | above (403 otherwise). Re-applying the current status only edits the
        | admin note, so it is a no-op and records nothing. The free-text note
        | is never recorded; only the bounded status transition is written.
        |
        */

        if ($certificateRequest->wasChanged('status')) {
            AuditLogger::record(
                'certificate_request.status_changed',
                $certificateRequest->barangay_id,
                $request->user()->id,
                'certificate_request',
                $certificateRequest->id,
                [
                    'previous_status' => $currentStatus,
                    'new_status' => $certificateRequest->status,
                ],
                $request->ip(),
            );
        }

        return redirect()->back()->with('success', 'Certificate request status updated.');
    }
}
