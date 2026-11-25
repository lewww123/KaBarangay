<?php

namespace App\Http\Controllers\Admin\FinancialDisclosure;

use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Support\AllocationBalance;
use App\Support\FinancialDisclosureAudit;
use App\Support\FinancialDisclosureFilters;
use App\Support\FinancialDisclosureScope;
use App\Support\FinancialDisclosureStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AllocationController extends Controller
{
    public function index(Request $request): Response
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());

        $search = FinancialDisclosureFilters::string($request->query('search'));
        $fiscalYear = FinancialDisclosureFilters::integer($request->query('fiscal_year'));
        $category = FinancialDisclosureFilters::allowed(
            $request->query('category'),
            FinancialDisclosureStatus::ALLOCATION_CATEGORIES,
        );
        $fundType = FinancialDisclosureFilters::string($request->query('fund_type'));
        $source = FinancialDisclosureFilters::string($request->query('source'));
        $status = FinancialDisclosureFilters::string($request->query('status'));

        $query = AllocationBalance::addTotalsToQuery(FinancialDisclosureScope::allocations($barangayId))
            ->with('createdBy:id,name');

        FinancialDisclosureFilters::applySearch($query, $search, [
            'name',
            'reference_no',
            'fund_type',
            'source',
        ]);

        if ($fiscalYear !== null) {
            $query->where('fiscal_year', $fiscalYear);
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        if ($fundType !== '') {
            $query->where('fund_type', $fundType);
        }

        if ($source !== '') {
            $query->where('source', $source);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $sort = FinancialDisclosureFilters::sort($query, $request->query('sort'), [
            'fiscal_year_desc' => ['fiscal_year', 'desc'],
            'fiscal_year_asc' => ['fiscal_year', 'asc'],
            'name_asc' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'amount_desc' => ['amount_approved', 'desc'],
            'amount_asc' => ['amount_approved', 'asc'],
        ], 'fiscal_year_desc');

        $allocations = (clone $query)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Allocation $allocation) => $this->transformAllocation($allocation));

        $scopeQuery = FinancialDisclosureScope::allocations($barangayId);
        $latestYear = (int) (clone $scopeQuery)->max('fiscal_year');
        $reportYear = $fiscalYear ?? ($latestYear > 0 ? $latestYear : null);
        $yearQuery = clone $scopeQuery;

        if ($reportYear !== null) {
            $yearQuery->where('fiscal_year', $reportYear);
        }

        $yearAllocations = AllocationBalance::addTotalsToQuery(clone $yearQuery)->get();
        $approvedTotal = round((float) $yearAllocations->sum('amount_approved'), 2);
        $amendmentTotal = round((float) $yearAllocations->sum(
            fn (Allocation $allocation) => (float) ($allocation->amendments_total_sum ?? 0),
        ), 2);

        $summary = [
            'fiscal_year' => $reportYear,
            'total_approved' => $approvedTotal,
            'total_amendments' => $amendmentTotal,
            'adjusted_budget' => round($approvedTotal + $amendmentTotal, 2),
            'record_count' => (int) (clone $yearQuery)->count(),
            'sources' => collect(FinancialDisclosureStatus::FUND_SOURCES)
                ->map(fn (string $fundSource) => [
                    'source' => $fundSource,
                    'total' => round((float) (clone $yearQuery)->where('source', $fundSource)->sum('amount_approved'), 2),
                ])
                ->all(),
        ];

        $filterOptions = [
            'categories' => FinancialDisclosureStatus::ALLOCATION_CATEGORIES,
            'fund_types' => (clone $scopeQuery)->distinct()->orderBy('fund_type')->pluck('fund_type')->filter()->values()->all(),
            'sources' => (clone $scopeQuery)->distinct()->orderBy('source')->pluck('source')->filter()->values()->all(),
            'statuses' => array_values(array_unique(array_merge(
                FinancialDisclosureStatus::ALLOCATION_STATUSES,
                (clone $scopeQuery)->distinct()->orderBy('status')->pluck('status')->filter()->values()->all(),
            ))),
        ];

        return Inertia::render('Admin/FinancialDisclosure/Allocation', [
            'allocations' => $allocations,
            'summary' => $summary,
            'filterOptions' => $filterOptions,
            'filters' => [
                'search' => $search,
                'fiscal_year' => $fiscalYear,
                'category' => $category ?? '',
                'fund_type' => $fundType,
                'source' => $source,
                'status' => $status,
                'sort' => $sort,
            ],
            'categories' => FinancialDisclosureStatus::ALLOCATION_CATEGORIES,
            'fundSources' => FinancialDisclosureStatus::FUND_SOURCES,
            'mandatoryFunds' => collect(FinancialDisclosureStatus::MANDATORY_FUND_ALIASES)
                ->map(fn (array $aliases, string $label) => [
                    'label' => $label,
                    'total' => round((float) $yearAllocations
                        ->filter(fn (Allocation $allocation) => in_array(
                            strtolower(trim((string) $allocation->fund_type)),
                            $aliases,
                            true,
                        ))
                        ->sum(fn (Allocation $allocation) => (float) $allocation->amount_approved
                            + (float) ($allocation->amendments_total_sum ?? 0)), 2),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, string $allocation): JsonResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $record = FinancialDisclosureScope::allocation($barangayId, $allocation);

        $amendments = $record->budgetAmendments()
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($amendment) => [
                'id' => $amendment->id,
                'amendment_date' => $amendment->created_at?->format('Y-m-d'),
                'amount' => round((float) $amendment->amount, 2),
                'reference_no' => $amendment->reference_no,
                'reason' => $amendment->reason,
                'created_by' => $amendment->createdBy?->name ?? 'Unknown',
            ])
            ->all();

        $disbursements = $record->disbursements()
            ->orderByDesc('disbursement_date')
            ->get()
            ->map(fn ($disbursement) => [
                'id' => $disbursement->id,
                'disbursement_date' => $disbursement->disbursement_date?->format('Y-m-d'),
                'payee' => $disbursement->payee,
                'amount' => round((float) $disbursement->amount, 2),
                'status' => $disbursement->status,
            ])
            ->all();

        return response()->json([
            'allocation' => $this->transformAllocation($record),
            'balance' => AllocationBalance::totals($record),
            'amendments' => $amendments,
            'disbursements' => $disbursements,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());

        $validated = $request->validate($this->rules(true));
        $validated['created_by'] = $actorId;

        $allocation = Allocation::create($validated);

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'allocation_created',
            'allocation',
            $allocation->name,
            entityUuid: $allocation->id,
            newStatus: $allocation->status,
        );

        return back()->with('success', 'Allocation created successfully.');
    }

    public function update(Request $request, string $allocation): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::allocation($barangayId, $allocation);

        $previousStatus = $record->status;
        $record->update($request->validate($this->rules(false)));

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'allocation_updated',
            'allocation',
            $record->name,
            entityUuid: $record->id,
            previousStatus: $previousStatus,
            newStatus: $record->status,
        );

        return back()->with('success', 'Allocation updated successfully.');
    }

    public function storeAmendment(Request $request, string $allocation): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::allocation($barangayId, $allocation);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'not_in:0'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $validated['created_by'] = $actorId;

        $amendment = AllocationBalance::withLock($record, null, function (Allocation $lockedAllocation) use ($validated) {
            return $lockedAllocation->budgetAmendments()->create([
                ...$validated,
                'allocation_id' => $lockedAllocation->id,
            ]);
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'amendment_created',
            'budget_amendment',
            $amendment->reference_no ?? $record->name,
            entityUuid: $amendment->id,
            newStatus: 'recorded',
        );

        return back()->with('success', 'Budget amendment recorded successfully.');
    }

    private function rules(bool $creating): array
    {
        $rules = [
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:255'],
            'fund_type' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(FinancialDisclosureStatus::ALLOCATION_CATEGORIES)],
            'source' => ['required', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(FinancialDisclosureStatus::ALLOCATION_STATUSES)],
        ];

        if ($creating) {
            $rules['amount_approved'] = ['required', 'numeric', 'decimal:0,2', 'min:0.01'];
        }

        return $rules;
    }

    private function transformAllocation(Allocation $allocation): array
    {
        $approved = round((float) $allocation->amount_approved, 2);
        $amendments = round((float) ($allocation->amendments_total_sum ?? $allocation->budgetAmendments()->sum('amount')), 2);
        $used = round((float) ($allocation->used_total_sum ?? $allocation->disbursements()
            ->where('status', AllocationBalance::RELEASED_STATUS)
            ->sum('amount')), 2);
        $available = round($approved + $amendments, 2);
        $remaining = round($available - $used, 2);
        $percentageUsed = $available > 0
            ? round(($used / $available) * 100, 2)
            : 0;

        return [
            'id' => $allocation->id,
            'fiscal_year' => (int) $allocation->fiscal_year,
            'name' => $allocation->name,
            'fund_type' => $allocation->fund_type,
            'category' => $allocation->category,
            'source' => $allocation->source,
            'amount_approved' => $approved,
            'reference_no' => $allocation->reference_no,
            'status' => $allocation->status,
            'created_by' => $allocation->createdBy?->name ?? 'Unknown',
            'amount_used' => $used,
            'remaining_balance' => $remaining,
            'percentage_used' => $percentageUsed,
        ];
    }
}
