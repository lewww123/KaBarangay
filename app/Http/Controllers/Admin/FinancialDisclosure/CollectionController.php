<?php

namespace App\Http\Controllers\Admin\FinancialDisclosure;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Collection;
use App\Support\CertificatePaymentLedger;
use App\Support\CollectionAnalytics;
use App\Support\FinancialDisclosureAudit;
use App\Support\FinancialDisclosureFilters;
use App\Support\FinancialDisclosureScope;
use App\Support\FinancialDisclosureStatus;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CollectionController extends Controller
{
    public function index(Request $request): Response
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());

        $search = FinancialDisclosureFilters::string($request->query('search'));
        $orNumber = FinancialDisclosureFilters::string($request->query('or_number'));
        $payeeName = FinancialDisclosureFilters::string($request->query('payee_name'));
        $collectionType = FinancialDisclosureFilters::string($request->query('collection_type'));
        $status = FinancialDisclosureFilters::allowed(
            $request->query('status'),
            FinancialDisclosureStatus::COLLECTION_STATUSES,
        );
        $waived = FinancialDisclosureFilters::allowed($request->query('waived'), ['1', '0']);
        $certificateLinked = FinancialDisclosureFilters::allowed($request->query('certificate'), ['1', '0']);
        $analyticsService = new CollectionAnalytics;
        $period = $analyticsService->parameters($request->query());
        $certificateIdInput = filter_var($request->query('certificate_id'), FILTER_VALIDATE_INT);
        $certificateId = $certificateIdInput !== false && $certificateIdInput > 0
            ? (int) $certificateIdInput
            : null;
        $selectedCertificate = null;

        if ($certificateId !== null) {
            $selectedCertificate = Certificate::query()
                ->whereKey($certificateId)
                ->where('barangay_id', $barangayId)
                ->first();

            if ($selectedCertificate === null) {
                $certificateId = null;
            }
        }

        $query = FinancialDisclosureScope::collections($barangayId)
            ->leftJoin('certificate_requests', 'certificate_requests.id', '=', 'collections.certificate_request_id')
            ->leftJoin('certificates', 'certificates.id', '=', 'certificate_requests.certificate_id')
            ->select('collections.*')
            ->with([
                'certificateRequest:id,reference,fee,barangay_id,certificate_id,status,ready_for_claim_at,released_at',
                'certificateRequest.certificate:id,name,code',
                'encodedBy:id,name',
                'verifiedBy:id,name',
            ]);

        $analyticsService->applyPeriod($query, $period);
        $authoritativeQuery = clone $query;

        FinancialDisclosureFilters::applySearch($query, $search, [
            'collections.or_number',
            'collections.payee_name',
            'collections.collection_type',
        ]);

        if ($orNumber !== '') {
            $query->whereRaw('UPPER(TRIM(collections.or_number)) = ?', [CertificatePaymentLedger::normalizeOr($orNumber)]);
        }

        if ($payeeName !== '') {
            $query->where('collections.payee_name', 'like', '%'.$payeeName.'%');
        }

        if ($collectionType !== '') {
            $query->where('collections.collection_type', $collectionType);
        }

        if ($status !== null) {
            $query->where('collections.status', $status);
        }

        if ($waived !== null) {
            $query->where('collections.is_waived', $waived === '1');
        }

        if ($certificateLinked !== null) {
            $query->when(
                $certificateLinked === '1',
                fn ($records) => $records->whereNotNull('collections.certificate_request_id'),
                fn ($records) => $records->whereNull('collections.certificate_request_id'),
            );
        }

        if ($certificateId !== null && $selectedCertificate !== null) {
            $query->where(function ($records) use ($certificateId, $selectedCertificate) {
                $records->where('certificate_requests.certificate_id', $certificateId)
                    ->orWhereRaw('LOWER(collections.collection_type) = LOWER(?)', [$selectedCertificate->name]);
            });
        }

        $listQuery = clone $query;
        $sort = FinancialDisclosureFilters::sort($listQuery, $request->query('sort'), [
            'date_desc' => ['collections.collection_date', 'desc'],
            'date_asc' => ['collections.collection_date', 'asc'],
            'payee_asc' => ['collections.payee_name', 'asc'],
            'payee_desc' => ['collections.payee_name', 'desc'],
            'or_asc' => ['collections.or_number', 'asc'],
            'or_desc' => ['collections.or_number', 'desc'],
            'amount_desc' => ['collections.amount', 'desc'],
            'amount_asc' => ['collections.amount', 'asc'],
        ], 'date_desc');

        $collections = $listQuery
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Collection $collection) => $this->transformCollection($collection));

        $analytics = $analyticsService->summarize($authoritativeQuery, $period);
        $summary = [
            'verified_amount' => $analytics['totals']['amount'],
            'pending_count' => $analytics['totals']['pending_count'],
            'voided_count' => $analytics['totals']['voided_count'],
            'waived_count' => $analytics['totals']['waived_count'],
        ];

        $scopeQuery = FinancialDisclosureScope::collections($barangayId);
        $filterOptions = [
            'collection_types' => (clone $scopeQuery)->distinct()->orderBy('collections.collection_type')->pluck('collections.collection_type')->filter()->values()->all(),
            'statuses' => FinancialDisclosureStatus::COLLECTION_STATUSES,
            'certificates' => Certificate::query()
                ->where('barangay_id', $barangayId)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Certificate $certificate) => [
                    'value' => (string) $certificate->id,
                    'label' => $certificate->name,
                ])
                ->all(),
        ];

        $pendingCertificates = CertificateRequest::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'For Payment')
            ->where('fee', '>', 0)
            ->whereNull('collection_id')
            ->whereNotExists(function (QueryBuilder $subquery) {
                $subquery->selectRaw(1)
                    ->from('collections')
                    ->whereColumn('collections.certificate_request_id', 'certificate_requests.id')
                    ->where('collections.status', '!=', 'Voided');
            })
            ->with([
                'resident:id,first_name,middle_name,last_name,suffix',
                'certificate:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->limit(15)
            ->get()
            ->map(fn (CertificateRequest $certificateRequest) => [
                'id' => $certificateRequest->id,
                'reference' => $certificateRequest->reference,
                'payee_name' => $certificateRequest->resident?->full_name ?? 'Resident',
                'certificate_name' => $certificateRequest->certificate?->name ?? 'Certificate',
                'amount' => round((float) $certificateRequest->fee, 2),
                'status' => 'Pending',
            ])
            ->all();

        return Inertia::render('Admin/FinancialDisclosure/Collection', [
            'collections' => $collections,
            'summary' => $summary,
            'analytics' => $analytics,
            'filterOptions' => $filterOptions,
            'pendingCertificates' => $pendingCertificates,
            'filters' => [
                'search' => $search,
                'or_number' => $orNumber,
                'payee_name' => $payeeName,
                'collection_type' => $collectionType,
                'period' => $period['period'],
                'date' => $period['date'],
                'date_from' => $period['date_from'],
                'date_to' => $period['date_to'],
                'status' => $status ?? '',
                'waived' => $waived ?? '',
                'certificate' => $certificateLinked ?? '',
                'certificate_id' => $certificateId ?? '',
                'sort' => $sort,
            ],
        ]);
    }

    public function show(Request $request, string $collection): JsonResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $record = FinancialDisclosureScope::collection($barangayId, $collection);

        return response()->json([
            'collection' => $this->transformCollection($record),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());

        $validated = $this->validatePayload($request, true);

        if (($validated['certificate_request_id'] ?? null) !== null) {
            throw ValidationException::withMessages([
                'certificate_request_id' => 'Certificate collections are recorded when the Clerk confirms payment.',
            ]);
        }

        $isWaived = (bool) $validated['is_waived'];
        $orNumber = CertificatePaymentLedger::resolveOr($validated['or_number'] ?? null);
        $amount = round((float) $validated['amount'], 2);
        CertificatePaymentLedger::assertWaiverRules($isWaived, $amount, $validated['waive_reason'] ?? null, $orNumber);

        try {
            $collection = DB::transaction(function () use ($barangayId, $actorId, $validated, $orNumber, $amount, $isWaived) {
                CertificatePaymentLedger::assertUniqueOr($orNumber);

                return Collection::create([
                    'collection_date' => $validated['collection_date'],
                    'collection_type' => $validated['collection_type'],
                    'amount' => $amount,
                    'or_number' => $orNumber,
                    'payee_name' => $validated['payee_name'],
                    'certificate_request_id' => null,
                    'is_waived' => $isWaived,
                    'waive_reason' => $isWaived ? $validated['waive_reason'] : null,
                    'status' => 'Pending',
                    'encoded_by' => $actorId,
                ]);
            });
        } catch (QueryException $exception) {
            if (! CertificatePaymentLedger::isDuplicateOrException($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'or_number' => 'This OR number is already recorded.',
            ]);
        }

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'collection_created',
            'collection',
            $collection->or_number ?? 'Waived collection',
            entityUuid: $collection->id,
            newStatus: $collection->status,
            reasonCode: $isWaived ? 'waived' : null,
        );

        return back()->with('success', 'Collection encoded successfully.');
    }

    public function update(Request $request, string $collection): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::collection($barangayId, $collection);

        if ($record->certificate_request_id !== null) {
            throw ValidationException::withMessages([
                'collection' => 'Certificate collections cannot be edited from the financial ledger.',
            ]);
        }

        if ($record->status !== 'Pending' || $record->verified_by !== null || $record->void_reason !== null) {
            throw ValidationException::withMessages([
                'collection' => 'Only pending collections can be edited.',
            ]);
        }

        $validated = $this->validatePayload($request, false);

        $isWaived = (bool) $validated['is_waived'];
        $orNumber = CertificatePaymentLedger::resolveOr($validated['or_number'] ?? null);
        CertificatePaymentLedger::assertUniqueOr($orNumber, $record->id);

        $amount = round((float) $validated['amount'], 2);
        CertificatePaymentLedger::assertWaiverRules($isWaived, $amount, $validated['waive_reason'] ?? null, $orNumber);

        try {
            DB::transaction(function () use ($record, $validated, $amount, $isWaived, $orNumber) {
                $locked = Collection::query()
                    ->whereKey($record->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== 'Pending' || $locked->verified_by !== null || $locked->void_reason !== null) {
                    throw ValidationException::withMessages([
                        'collection' => 'Only pending collections can be edited.',
                    ]);
                }

                $locked->update([
                    'collection_date' => $validated['collection_date'],
                    'collection_type' => $validated['collection_type'],
                    'amount' => $amount,
                    'or_number' => $orNumber,
                    'payee_name' => $validated['payee_name'],
                    'is_waived' => $isWaived,
                    'waive_reason' => $isWaived ? $validated['waive_reason'] : null,
                ]);

                $record->setRawAttributes($locked->getAttributes());
            });
        } catch (QueryException $exception) {
            if (! CertificatePaymentLedger::isDuplicateOrException($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'or_number' => 'This OR number is already recorded.',
            ]);
        }

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'collection_updated',
            'collection',
            $record->or_number ?? 'Waived collection',
            entityUuid: $record->id,
            newStatus: $record->status,
            reasonCode: $isWaived ? 'waived' : null,
        );

        return back()->with('success', 'Collection updated successfully.');
    }

    public function verify(Request $request, string $collection): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::collection($barangayId, $collection);

        if ($record->certificate_request_id !== null) {
            throw ValidationException::withMessages([
                'collection' => 'Certificate collections are verified by Clerk payment confirmation.',
            ]);
        }

        if ($record->encoded_by === $actorId) {
            throw ValidationException::withMessages([
                'verified_by' => 'The encoder cannot verify their own collection.',
            ]);
        }

        if (! FinancialDisclosureScope::userBelongsToBarangay($barangayId, $actorId)) {
            abort(403, 'Unauthorized barangay access.');
        }

        DB::transaction(function () use ($record, $actorId) {
            $locked = Collection::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'Pending' || $locked->void_reason !== null) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending collections can be verified.',
                ]);
            }

            $locked->update([
                'status' => 'Verified',
                'verified_by' => $actorId,
                'verified_at' => now(),
            ]);
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'collection_verified',
            'collection',
            $record->or_number ?? 'Waived collection',
            entityUuid: $record->id,
            previousStatus: 'Pending',
            newStatus: 'Verified',
        );

        return back()->with('success', 'Collection verified successfully.');
    }

    public function void(Request $request, string $collection): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::collection($barangayId, $collection);

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        $previousStatus = $record->status;

        DB::transaction(function () use ($record, $validated, $barangayId, &$previousStatus) {
            $certificateRequest = null;

            if ($record->certificate_request_id !== null) {
                $certificateRequest = CertificateRequest::query()
                    ->whereKey($record->certificate_request_id)
                    ->where('barangay_id', $barangayId)
                    ->lockForUpdate()
                    ->first();

                if ($certificateRequest === null) {
                    throw ValidationException::withMessages([
                        'collection' => 'The linked certificate request is no longer available.',
                    ]);
                }
            }

            $locked = Collection::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($certificateRequest !== null
                && (string) $locked->certificate_request_id !== (string) $certificateRequest->id) {
                throw ValidationException::withMessages([
                    'collection' => 'The certificate payment link changed before the collection could be voided.',
                ]);
            }

            if ($certificateRequest !== null && $certificateRequest->status !== 'For Payment') {
                throw ValidationException::withMessages([
                    'collection' => 'Only certificate payments awaiting claim can be voided.',
                ]);
            }

            if ($certificateRequest !== null
                && (
                    $certificateRequest->ready_for_claim_at !== null
                    || $certificateRequest->released_at !== null
                )) {
                throw ValidationException::withMessages([
                    'collection' => 'Payment proof cannot be voided after the certificate is ready for claim or released.',
                ]);
            }

            if ($locked->status === 'Voided') {
                throw ValidationException::withMessages([
                    'void_reason' => 'This collection is already voided.',
                ]);
            }

            $previousStatus = $locked->status;
            $locked->update([
                'status' => 'Voided',
                'void_reason' => $validated['void_reason'],
            ]);

            if ($certificateRequest !== null
                && $certificateRequest->collection_id !== null
                && (string) $certificateRequest->collection_id === (string) $locked->id) {
                $certificateRequest->update([
                    'collection_id' => null,
                    'or_number' => null,
                    'amount_paid' => null,
                    'is_waived' => false,
                ]);
            }

            $record->setRawAttributes($locked->getAttributes());
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'collection_voided',
            'collection',
            $record->or_number ?? 'Waived collection',
            entityUuid: $record->id,
            previousStatus: $previousStatus,
            newStatus: 'Voided',
            reasonCode: 'void_reason_supplied',
        );

        return back()->with('success', 'Collection voided successfully.');
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $rules = [
            'collection_date' => ['required', 'date', 'date_format:Y-m-d'],
            'collection_type' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'or_number' => ['nullable', 'string', 'max:255'],
            'payee_name' => ['required', 'string', 'max:255'],
            'is_waived' => ['required', 'boolean'],
            'waive_reason' => ['nullable', 'string', 'max:1000'],
        ];

        if ($creating) {
            $rules['certificate_request_id'] = ['nullable', 'integer', 'exists:certificate_requests,id'];
        }

        return $request->validate($rules);
    }

    private function transformCollection(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'collection_date' => $collection->collection_date?->format('Y-m-d'),
            'collection_type' => $collection->collection_type,
            'certificate_id' => $collection->certificateRequest?->certificate_id,
            'certificate_type' => $collection->certificateRequest?->certificate?->name ?? $collection->collection_type,
            'certificate_code' => $collection->certificateRequest?->certificate?->code,
            'amount' => round((float) $collection->amount, 2),
            'or_number' => $collection->or_number ?? null,
            'payee_name' => $collection->payee_name,
            'certificate_request_id' => $collection->certificate_request_id,
            'certificate_reference' => $collection->certificateRequest?->reference,
            'certificate_request_status' => $collection->certificateRequest?->status,
            'certificate_ready_for_claim' => $collection->certificateRequest?->ready_for_claim_at !== null,
            'certificate_released' => $collection->certificateRequest?->released_at !== null,
            'is_waived' => (bool) $collection->is_waived,
            'waive_reason' => $collection->waive_reason,
            'status' => $collection->status,
            'encoded_by' => $collection->encodedBy?->name ?? 'Unknown',
            'verified_by' => $collection->verifiedBy?->name,
            'verified_at' => $collection->verified_at?->format('Y-m-d H:i'),
            'void_reason' => $collection->void_reason,
        ];
    }
}
