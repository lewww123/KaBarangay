<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use App\Support\CertificateRequestDetails;
use App\Support\CertificateRequestWorkflow;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Aggregate certificate-request monitoring for administrators.
 *
 * Administrators may search, filter, page, and view aggregate counts. They
 * cannot process individual requests or change a request status; that belongs
 * to the Clerk portal and is enforced by the absence of an admin status route.
 */
class CertificateRequestController extends Controller
{
    public function index(Request $request)
    {
        $barangayId = $request->user()->barangay_id;

        $search = trim((string) $request->query('search', ''));
        $requestedStatus = trim((string) $request->query('status', ''));

        // Treat a missing, empty, "ALL", or otherwise unknown status as no
        // filter so arbitrary input can never reach the query builder.
        $statusFilter = in_array($requestedStatus, CertificateRequestWorkflow::statuses(), true)
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
                $payload = CertificateRequestDetails::decode(
                    $certificateRequest->additional_details,
                );
                $type = $payload['type']
                    ?? CertificateRequestDetails::typeForCertificate(
                        $certificateRequest->certificate,
                    );

                return [
                    'id' => $certificateRequest->id,
                    'reference' => $certificateRequest->reference,
                    'certificate' => $certificateRequest->certificate?->name ?? 'Certificate',
                    'certificate_type' => $type,
                    'resident' => [
                        'name' => $certificateRequest->resident?->full_name ?? 'Unknown resident',
                        'resident_number' => $certificateRequest->resident?->resident_number,
                    ],
                    'purpose' => $certificateRequest->purpose,
                    'fee' => (float) $certificateRequest->fee,
                    'business_name' => $certificateRequest->business_name,
                    'business_address' => $certificateRequest->business_address,
                    'additional_details' => $payload['notes'],
                    'details' => CertificateRequestDetails::displayRows(
                        $type,
                        $payload['fields'],
                    ),
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

        $byStatus = collect(CertificateRequestWorkflow::statuses())
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
            'statuses' => CertificateRequestWorkflow::statuses(),
            'filters' => [
                'search' => $search,
                'status' => $statusFilter ?? '',
            ],
        ]);
    }
}
