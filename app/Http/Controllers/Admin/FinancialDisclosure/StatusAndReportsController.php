<?php

namespace App\Http\Controllers\Admin\FinancialDisclosure;

use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\Disbursement;
use App\Models\DisclosurePeriod;
use App\Models\FinancialAttachment;
use App\Support\AllocationBalance;
use App\Support\FinancialDisclosureAudit;
use App\Support\FinancialDisclosureExport;
use App\Support\FinancialDisclosureFilters;
use App\Support\FinancialDisclosureScope;
use App\Support\FinancialDisclosureStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatusAndReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());

        $search = FinancialDisclosureFilters::string($request->query('search'));
        $year = $this->year($request->query('year'));
        $fiscalYear = $this->year($request->query('fiscal_year'));
        $month = $this->range($request->query('month'), 1, 12);
        $quarter = $this->range($request->query('quarter'), 1, 4);
        $status = FinancialDisclosureFilters::string($request->query('status'));
        $allocationId = FinancialDisclosureFilters::string($request->query('allocation_id'));

        if ($allocationId !== '' && ! FinancialDisclosureScope::allocations($barangayId)->whereKey($allocationId)->exists()) {
            $allocationId = '';
        }

        $collectionQuery = FinancialDisclosureScope::collections($barangayId);
        $disbursementQuery = FinancialDisclosureScope::disbursements($barangayId);

        $this->applyPeriod($collectionQuery, 'collection_date', $month, $quarter, $year);
        $this->applyPeriod($disbursementQuery, 'disbursement_date', $month, $quarter, $year);
        $authoritativeCollectionQuery = clone $collectionQuery;
        $authoritativeDisbursementQuery = clone $disbursementQuery;

        if ($status !== '') {
            $collectionQuery->where('status', $status);
            $disbursementQuery->where('status', $status);
        }

        if ($allocationId !== '') {
            $disbursementQuery->where('allocation_id', $allocationId);
        }

        $allocationQuery = AllocationBalance::addTotalsToQuery(FinancialDisclosureScope::allocations($barangayId))
            ->withSum([
                'disbursements as released_total_sum' => fn (Builder $disbursements) => $disbursements->where('status', AllocationBalance::RELEASED_STATUS),
            ], 'amount')
            ->with('createdBy:id,name');

        FinancialDisclosureFilters::applySearch($allocationQuery, $search, ['name', 'reference_no']);

        if ($fiscalYear !== null) {
            $allocationQuery->where('fiscal_year', $fiscalYear);
        }

        if ($allocationId !== '') {
            $allocationQuery->whereKey($allocationId);
        }

        if ($status !== '') {
            $allocationQuery->where('status', $status);
        }

        $sort = FinancialDisclosureFilters::sort($allocationQuery, $request->query('sort'), [
            'fiscal_year_desc' => ['fiscal_year', 'desc'],
            'fiscal_year_asc' => ['fiscal_year', 'asc'],
            'name_asc' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'amount_desc' => ['amount_approved', 'desc'],
            'amount_asc' => ['amount_approved', 'asc'],
        ], 'fiscal_year_desc');

        $allocations = (clone $allocationQuery)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Allocation $allocation) => $this->transformAllocation($allocation));

        $summaryAllocations = (clone $allocationQuery)->get();
        $approvedTotal = round((float) $summaryAllocations->sum('amount_approved'), 2);
        $amendmentTotal = round((float) $summaryAllocations->sum(
            fn (Allocation $allocation) => (float) ($allocation->amendments_total_sum ?? 0),
        ), 2);
        $adjustedBudget = round($approvedTotal + $amendmentTotal, 2);
        $usedTotal = round((float) $summaryAllocations->sum(
            fn (Allocation $allocation) => (float) ($allocation->used_total_sum ?? 0),
        ), 2);

        $summary = [
            'total_collections' => round((float) (clone $authoritativeCollectionQuery)
                ->where('status', 'Verified')
                ->where('is_waived', false)
                ->whereNull('void_reason')
                ->sum('amount'), 2),
            'total_disbursements' => round((float) (clone $authoritativeDisbursementQuery)
                ->where('status', 'Released')
                ->sum('amount'), 2),
            'total_approved' => $approvedTotal,
            'total_amendments' => $amendmentTotal,
            'adjusted_budget' => $adjustedBudget,
            'used_budget' => $usedTotal,
            'percentage_used' => $adjustedBudget > 0
                ? round(($usedTotal / $adjustedBudget) * 100, 2)
                : 0,
            'available_balance' => round($adjustedBudget - $usedTotal, 2),
        ];

        $auditQuery = AuditLog::query()
            ->where('barangay_id', $barangayId)
            ->where('event', 'like', FinancialDisclosureStatus::PUBLIC_EVENT_PREFIX.'%')
            ->with('actor:id,name');

        $this->applyPeriod($auditQuery, 'created_at', $month, $quarter, $year);

        $auditHistory = (clone $auditQuery)
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'audit_page')
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'entity_type' => $log->entity_type,
                'actor' => $log->actor?->name ?? 'System',
                'created_at' => $log->created_at?->format('Y-m-d H:i'),
                'context' => $log->context ?? [],
            ]);

        $allocationOptions = FinancialDisclosureScope::allocations($barangayId)
            ->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->get(['id', 'name', 'fiscal_year'])
            ->map(fn (Allocation $allocation) => [
                'id' => $allocation->id,
                'label' => $allocation->fiscal_year.' — '.$allocation->name,
            ])
            ->all();

        $periodOptions = collect()
            ->merge((clone $collectionQuery)->pluck('collection_date'))
            ->merge((clone $disbursementQuery)->pluck('disbursement_date'))
            ->filter()
            ->map(fn ($date) => [
                'year' => (int) date('Y', strtotime((string) $date)),
                'month' => (int) date('m', strtotime((string) $date)),
                'label' => date('Y-m', strtotime((string) $date)),
            ])
            ->unique('label')
            ->sortByDesc('label')
            ->values()
            ->all();

        $publicationPeriods = FinancialDisclosureScope::disclosurePeriods($barangayId)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (DisclosurePeriod $period) => [
                'id' => $period->id,
                'year' => (int) $period->year,
                'month' => (int) $period->month,
                'label' => sprintf('%d-%02d', $period->year, $period->month),
                'is_published' => (bool) $period->is_published,
                'published_at' => $period->published_at?->format('Y-m-d H:i'),
                'published_by' => $period->publishedBy?->name ?? 'Unknown',
            ])
            ->all();

        $attachments = FinancialDisclosureScope::attachments($barangayId)
            ->with('uploadedBy:id,name')
            ->orderByDesc('uploaded_at')
            ->get()
            ->map(fn (FinancialAttachment $attachment) => [
                'id' => $attachment->id,
                'title' => $attachment->title,
                'record_type' => $attachment->record_type,
                'uploaded_by' => $attachment->uploadedBy?->name ?? 'Unknown',
                'uploaded_at' => $attachment->uploaded_at?->format('Y-m-d H:i'),
                'url' => route('admin.financial-disclosure.attachments.show', $attachment->id),
                'download_url' => route('admin.financial-disclosure.attachments.download', $attachment->id),
            ])
            ->all();

        return Inertia::render('Admin/FinancialDisclosure/StatusAndReports', [
            'summary' => $summary,
            'allocations' => $allocations,
            'auditHistory' => $auditHistory,
            'publicationPeriods' => $publicationPeriods,
            'attachments' => $attachments,
            'filterOptions' => [
                'allocations' => $allocationOptions,
                'periods' => $periodOptions,
                'statuses' => array_values(array_unique(array_merge(
                    FinancialDisclosureStatus::COLLECTION_STATUSES,
                    FinancialDisclosureStatus::DISBURSEMENT_STATUSES,
                ))),
            ],
            'blockedSections' => [
                'project_status' => 'No approved project-status model or schema is available.',
                'bfdp_checklist' => 'Approved BFDP checklist criteria and scoring rules are not available.',
                'publication' => '',
                'documents' => 'Upload PDF or image documents for disclosure periods and linked records.',
                'attachments' => 'Upload PDF or image evidence for allocations, collections, and disbursements.',
            ],
            'filters' => [
                'search' => $search,
                'year' => $year ?? '',
                'fiscal_year' => $fiscalYear ?? '',
                'month' => $month ?? '',
                'quarter' => $quarter ?? '',
                'status' => $status,
                'allocation_id' => $allocationId,
                'sort' => $sort,
            ],
        ]);
    }

    public function export(Request $request, string $type): StreamedResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $year = $this->year($request->query('year'));
        $fiscalYear = $this->year($request->query('fiscal_year'));
        $month = $this->range($request->query('month'), 1, 12);
        $quarter = $this->range($request->query('quarter'), 1, 4);
        $status = FinancialDisclosureFilters::string($request->query('status'));
        $allocationId = FinancialDisclosureFilters::string($request->query('allocation_id'));
        $search = FinancialDisclosureFilters::string($request->query('search'));

        if ($allocationId !== '' && ! FinancialDisclosureScope::allocations($barangayId)->whereKey($allocationId)->exists()) {
            $allocationId = '';
        }

        $suffix = $year !== null
            ? '-'.$year.($month !== null ? '-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '')
            : '';

        return match ($type) {
            'collections' => $this->exportCollections($barangayId, $year, $month, $quarter, $status, $suffix),
            'disbursements' => $this->exportDisbursements($barangayId, $year, $month, $quarter, $status, $allocationId, $suffix),
            'allocations' => $this->exportAllocations($barangayId, $search, $allocationId, $status, $fiscalYear, $suffix),
            'status-of-funds' => $this->exportAllocations($barangayId, $search, $allocationId, $status, $fiscalYear, $suffix),
            default => abort(404),
        };
    }

    public function publication(Request $request): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'publish' => ['required', 'boolean'],
        ]);

        $publish = (bool) $validated['publish'];
        $period = FinancialDisclosureScope::disclosurePeriods($barangayId)->firstOrNew([
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
        ]);

        $period->barangay_id = $barangayId;
        $period->is_published = $publish;
        $period->published_by = $publish ? $actorId : null;
        $period->published_at = $publish ? now() : null;
        $period->save();

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            $publish ? 'disclosure_published' : 'disclosure_unpublished',
            'disclosure_period',
            sprintf('%d-%02d', $period->year, $period->month),
            entityUuid: $period->id,
            newStatus: $publish ? 'Published' : 'Unpublished',
        );

        return back()->with('success', $publish
            ? 'Disclosure period published successfully.'
            : 'Disclosure period unpublished successfully.');
    }

    private function exportCollections(
        int $barangayId,
        ?int $year,
        ?int $month,
        ?int $quarter,
        string $status,
        string $suffix,
    ): StreamedResponse {
        $query = FinancialDisclosureScope::collections($barangayId)
            ->with('certificateRequest:id,reference');

        $this->applyPeriod($query, 'collection_date', $month, $quarter, $year);

        if ($status !== '') {
            $query->where('status', $status);
            $basis = 'Raw ledger (status: '.$status.')';
        } else {
            $query->where('status', 'Verified')->whereNull('void_reason');
            $basis = 'Realized collections';
        }

        $rows = $query->orderByDesc('collection_date')
            ->get()
            ->map(fn (Collection $collection) => [
                $collection->collection_date?->format('Y-m-d'),
                $collection->collection_type,
                round((float) $collection->amount, 2),
                $collection->or_number ?? '',
                $collection->payee_name,
                $collection->certificateRequest?->reference ?? '',
                $collection->is_waived ? 'Yes' : 'No',
                $collection->waive_reason ?? '',
                $collection->status ?? '',
                $collection->void_reason ?? '',
                $basis,
            ]);

        return FinancialDisclosureExport::csv('collections'.$suffix.'.csv', [
            'Date', 'Type', 'Amount', 'OR Number', 'Payee', 'Certificate Reference',
            'Waived', 'Waiver Reason', 'Status', 'Void Reason', 'Basis',
        ], $rows);
    }

    private function exportDisbursements(
        int $barangayId,
        ?int $year,
        ?int $month,
        ?int $quarter,
        string $status,
        string $allocationId,
        string $suffix,
    ): StreamedResponse {
        $query = FinancialDisclosureScope::disbursements($barangayId)
            ->with([
                'allocation:id,name,fiscal_year',
                'preparedBy:id,name',
                'approvedBy:id,name',
                'releasedBy:id,name',
            ]);

        $this->applyPeriod($query, 'disbursement_date', $month, $quarter, $year);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($allocationId !== '') {
            $query->where('allocation_id', $allocationId);
        }

        $rows = $query->orderByDesc('disbursement_date')
            ->get()
            ->map(fn (Disbursement $disbursement) => [
                $disbursement->disbursement_date?->format('Y-m-d'),
                $disbursement->allocation?->name ?? '',
                (int) ($disbursement->allocation?->fiscal_year ?? 0),
                $disbursement->payee,
                $disbursement->purpose,
                round((float) $disbursement->amount, 2),
                $disbursement->dv_number ?? '',
                $disbursement->status ?? '',
                $disbursement->preparedBy?->name ?? '',
                $disbursement->approvedBy?->name ?? '',
                $disbursement->releasedBy?->name ?? '',
                $disbursement->void_reason ?? '',
            ]);

        return FinancialDisclosureExport::csv('disbursements'.$suffix.'.csv', [
            'Date', 'Allocation', 'Fiscal Year', 'Payee', 'Purpose', 'Amount',
            'DV Number', 'Status', 'Prepared By', 'Approved By', 'Released By', 'Void Reason',
        ], $rows);
    }

    private function exportAllocations(
        int $barangayId,
        string $search,
        string $allocationId,
        string $status,
        ?int $fiscalYear,
        string $suffix,
    ): StreamedResponse {
        $query = AllocationBalance::addTotalsToQuery(FinancialDisclosureScope::allocations($barangayId));

        FinancialDisclosureFilters::applySearch($query, $search, ['name', 'reference_no']);

        if ($fiscalYear !== null) {
            $query->where('fiscal_year', $fiscalYear);
        }

        if ($allocationId !== '') {
            $query->whereKey($allocationId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->get()
            ->map(fn (Allocation $allocation) => $this->transformAllocation($allocation))
            ->map(fn (array $row) => [
                $row['fiscal_year'],
                $row['name'],
                $row['category'],
                $row['source'],
                $row['status'],
                $row['amount_approved'],
                $row['amendments'],
                $row['amount_used'],
                $row['remaining_balance'],
                $row['percentage_used'],
            ]);

        return FinancialDisclosureExport::csv('status-of-funds'.$suffix.'.csv', [
            'Fiscal Year', 'Allocation', 'Category', 'Source', 'Status',
            'Approved', 'Amendments', 'Released Disbursements', 'Remaining Balance', 'Percentage Used',
        ], $rows);
    }

    private function year(mixed $value): ?int
    {
        return FinancialDisclosureFilters::integer($value);
    }

    private function range(mixed $value, int $minimum, int $maximum): ?int
    {
        $value = FinancialDisclosureFilters::string($value);

        if ($value === '' || preg_match('/^\d{1,2}$/', $value) !== 1) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= $minimum && $integer <= $maximum ? $integer : null;
    }

    private function applyPeriod(
        Builder $query,
        string $column,
        ?int $month,
        ?int $quarter,
        ?int $year,
    ): void {
        if ($year !== null) {
            $query->whereYear($column, $year);
        }

        if ($month !== null) {
            $query->whereMonth($column, $month);
        }

        if ($quarter !== null) {
            $startMonth = (($quarter - 1) * 3) + 1;
            $endMonth = $startMonth + 2;

            $query->where(function (Builder $group) use ($column, $startMonth, $endMonth) {
                $group->whereMonth($column, '>=', $startMonth)
                    ->whereMonth($column, '<=', $endMonth);
            });
        }
    }

    private function remainingBalance(Allocation $allocation): float
    {
        $approved = round((float) $allocation->amount_approved, 2);
        $amendments = round((float) ($allocation->amendments_total_sum ?? 0), 2);
        $used = round((float) ($allocation->used_total_sum ?? 0), 2);

        return round($approved + $amendments - $used, 2);
    }

    private function transformAllocation(Allocation $allocation): array
    {
        $approved = round((float) $allocation->amount_approved, 2);
        $amendments = round((float) ($allocation->amendments_total_sum ?? 0), 2);
        $used = round((float) ($allocation->used_total_sum ?? 0), 2);
        $released = round((float) ($allocation->released_total_sum ?? 0), 2);
        $available = round($approved + $amendments, 2);

        return [
            'id' => $allocation->id,
            'fiscal_year' => (int) $allocation->fiscal_year,
            'name' => $allocation->name,
            'category' => $allocation->category,
            'source' => $allocation->source,
            'status' => $allocation->status,
            'amount_approved' => $approved,
            'amendments' => $amendments,
            'amount_used' => $used,
            'amount_released' => $released,
            'remaining_balance' => round($available - $used, 2),
            'percentage_used' => $available > 0 ? round(($used / $available) * 100, 2) : 0,
        ];
    }
}
