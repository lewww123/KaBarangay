<?php

namespace App\Http\Controllers\Admin\FinancialDisclosure;

use App\Http\Controllers\Controller;
use App\Models\Disbursement;
use App\Support\AllocationBalance;
use App\Support\FinancialDisclosureAudit;
use App\Support\FinancialDisclosureFilters;
use App\Support\FinancialDisclosureScope;
use App\Support\FinancialDisclosureStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DisbursementController extends Controller
{
    public function index(Request $request): Response
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());

        $search = FinancialDisclosureFilters::string($request->query('search'));
        $payee = FinancialDisclosureFilters::string($request->query('payee'));
        $purpose = FinancialDisclosureFilters::string($request->query('purpose'));
        $dvNumber = FinancialDisclosureFilters::string($request->query('dv_number'));
        $from = FinancialDisclosureFilters::parseDate($request->query('date_from'));
        $to = FinancialDisclosureFilters::parseDate($request->query('date_to'));
        $status = FinancialDisclosureFilters::allowed(
            $request->query('status'),
            FinancialDisclosureStatus::DISBURSEMENT_STATUSES,
        );
        $allocationId = FinancialDisclosureFilters::string($request->query('allocation_id'));

        if ($allocationId !== '' && ! FinancialDisclosureScope::allocations($barangayId)->whereKey($allocationId)->exists()) {
            $allocationId = '';
        }

        $query = FinancialDisclosureScope::disbursements($barangayId)
            ->with([
                'allocation:id,name,fiscal_year',
                'preparedBy:id,name',
                'approvedBy:id,name',
                'releasedBy:id,name',
            ]);

        FinancialDisclosureFilters::applySearch($query, $search, [
            'payee',
            'purpose',
            'dv_number',
        ]);

        if ($payee !== '') {
            $query->where('payee', 'like', '%'.$payee.'%');
        }

        if ($purpose !== '') {
            $query->where('purpose', 'like', '%'.$purpose.'%');
        }

        if ($dvNumber !== '') {
            $query->whereRaw('UPPER(TRIM(dv_number)) = ?', [mb_strtoupper(trim($dvNumber))]);
        }

        if ($from !== null) {
            $query->where('disbursement_date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('disbursement_date', '<=', $to);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($allocationId !== '') {
            $query->where('allocation_id', $allocationId);
        }

        $sort = FinancialDisclosureFilters::sort($query, $request->query('sort'), [
            'date_desc' => ['disbursement_date', 'desc'],
            'date_asc' => ['disbursement_date', 'asc'],
            'payee_asc' => ['payee', 'asc'],
            'payee_desc' => ['payee', 'desc'],
            'amount_desc' => ['amount', 'desc'],
            'amount_asc' => ['amount', 'asc'],
        ], 'date_desc');

        $disbursements = (clone $query)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Disbursement $disbursement) => $this->transformDisbursement($disbursement));

        $scopeQuery = FinancialDisclosureScope::disbursements($barangayId);
        $summary = [
            'released_amount' => round((float) (clone $scopeQuery)->where('status', 'Released')->sum('amount'), 2),
            'draft_count' => (int) (clone $scopeQuery)->where('status', 'Draft')->count(),
            'approved_count' => (int) (clone $scopeQuery)->where('status', 'Approved')->count(),
            'voided_count' => (int) (clone $scopeQuery)->where('status', 'Voided')->count(),
        ];

        $allocationOptions = FinancialDisclosureScope::allocations($barangayId)
            ->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->get(['id', 'name', 'fiscal_year'])
            ->map(fn ($allocation) => [
                'id' => $allocation->id,
                'label' => $allocation->fiscal_year.' — '.$allocation->name,
            ])
            ->all();

        return Inertia::render('Admin/FinancialDisclosure/Disbursement', [
            'disbursements' => $disbursements,
            'summary' => $summary,
            'allocationOptions' => $allocationOptions,
            'filters' => [
                'search' => $search,
                'payee' => $payee,
                'purpose' => $purpose,
                'dv_number' => $dvNumber,
                'date_from' => $from ?? '',
                'date_to' => $to ?? '',
                'status' => $status ?? '',
                'allocation_id' => $allocationId,
                'sort' => $sort,
            ],
            'statuses' => FinancialDisclosureStatus::DISBURSEMENT_STATUSES,
        ]);
    }

    public function show(Request $request, string $disbursement): JsonResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $record = FinancialDisclosureScope::disbursement($barangayId, $disbursement);

        return response()->json([
            'disbursement' => $this->transformDisbursement($record),
            'balance' => AllocationBalance::totals($record->allocation, $record->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());

        $validated = $request->validate($this->rules(true));
        $allocation = FinancialDisclosureScope::allocation($barangayId, $validated['allocation_id']);
        $amount = round((float) $validated['amount'], 2);

        $record = AllocationBalance::withLock($allocation, null, function ($lockedAllocation, $totals) use (
            $validated,
            $actorId,
            $amount,
        ) {
            AllocationBalance::assertWithinBalance($totals, $amount);

            return Disbursement::create([
                'disbursement_date' => $validated['disbursement_date'],
                'allocation_id' => $validated['allocation_id'],
                'payee' => $validated['payee'],
                'purpose' => $validated['purpose'],
                'amount' => $amount,
                'dv_number' => $validated['dv_number'] ?? null,
                'status' => 'Draft',
                'prepared_by' => $actorId,
            ]);
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'disbursement_created',
            'disbursement',
            $record->dv_number ?? $record->payee,
            entityUuid: $record->id,
            newStatus: $record->status,
        );

        return back()->with('success', 'Disbursement draft created successfully.');
    }

    public function update(Request $request, string $disbursement): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::disbursement($barangayId, $disbursement);

        $validated = $request->validate($this->rules(false));
        $amount = round((float) $validated['amount'], 2);

        AllocationBalance::withLock($record->allocation, $record->id, function ($allocation, $totals) use (
            $record,
            $validated,
            $amount,
        ) {
            $locked = Disbursement::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($locked, ['Draft']);
            AllocationBalance::assertWithinBalance($totals, $amount);

            $locked->update([
                'disbursement_date' => $validated['disbursement_date'],
                'payee' => $validated['payee'],
                'purpose' => $validated['purpose'],
                'amount' => $amount,
                'dv_number' => $validated['dv_number'] ?? null,
            ]);
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'disbursement_updated',
            'disbursement',
            $record->dv_number ?? $record->payee,
            entityUuid: $record->id,
            newStatus: $record->status,
        );

        return back()->with('success', 'Disbursement draft updated successfully.');
    }

    public function approve(Request $request, string $disbursement): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::disbursement($barangayId, $disbursement);

        AllocationBalance::withLock($record->allocation, $record->id, function ($allocation, $totals) use (
            $record,
            $actorId,
        ) {
            $locked = Disbursement::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($locked, ['Draft']);
            AllocationBalance::assertWithinBalance($totals, (float) $locked->amount);

            $locked->update([
                'status' => 'Approved',
                'approved_by' => $actorId,
            ]);

            $record->setRawAttributes($locked->getAttributes());
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'disbursement_approved',
            'disbursement',
            $record->dv_number ?? $record->payee,
            entityUuid: $record->id,
            previousStatus: 'Draft',
            newStatus: 'Approved',
        );

        return back()->with('success', 'Disbursement approved successfully.');
    }

    public function release(Request $request, string $disbursement): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::disbursement($barangayId, $disbursement);

        AllocationBalance::withLock($record->allocation, $record->id, function ($allocation, $totals) use (
            $record,
            $actorId,
        ) {
            $locked = Disbursement::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($locked, ['Approved']);
            AllocationBalance::assertWithinBalance($totals, (float) $locked->amount);

            $locked->update([
                'status' => 'Released',
                'released_by' => $actorId,
            ]);

            $record->setRawAttributes($locked->getAttributes());
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'disbursement_released',
            'disbursement',
            $record->dv_number ?? $record->payee,
            entityUuid: $record->id,
            previousStatus: 'Approved',
            newStatus: 'Released',
        );

        return back()->with('success', 'Disbursement released successfully.');
    }

    public function void(Request $request, string $disbursement): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());
        $record = FinancialDisclosureScope::disbursement($barangayId, $disbursement);

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        $previousStatus = $record->status;

        DB::transaction(function () use ($record, $validated, &$previousStatus) {
            $locked = Disbursement::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'Voided') {
                throw ValidationException::withMessages([
                    'void_reason' => 'This disbursement is already voided.',
                ]);
            }

            $previousStatus = $locked->status;
            $locked->update([
                'status' => 'Voided',
                'void_reason' => $validated['void_reason'],
            ]);

            $record->setRawAttributes($locked->getAttributes());
        });

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'disbursement_voided',
            'disbursement',
            $record->dv_number ?? $record->payee,
            entityUuid: $record->id,
            previousStatus: $previousStatus,
            newStatus: 'Voided',
            reasonCode: 'void_reason_supplied',
        );

        return back()->with('success', 'Disbursement voided successfully.');
    }

    private function rules(bool $creating): array
    {
        $rules = [
            'disbursement_date' => ['required', 'date', 'date_format:Y-m-d'],
            'payee' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'dv_number' => ['nullable', 'string', 'max:255'],
        ];

        if ($creating) {
            $rules['allocation_id'] = ['required', 'string', 'max:36'];
        }

        return $rules;
    }

    private function assertStatus(Disbursement $record, array $allowed): void
    {
        if (! in_array($record->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'This disbursement status does not allow that action.',
            ]);
        }
    }

    private function transformDisbursement(Disbursement $disbursement): array
    {
        return [
            'id' => $disbursement->id,
            'disbursement_date' => $disbursement->disbursement_date?->format('Y-m-d'),
            'allocation_id' => $disbursement->allocation_id,
            'allocation_name' => $disbursement->allocation?->name ?? 'Unknown allocation',
            'fiscal_year' => (int) ($disbursement->allocation?->fiscal_year ?? 0),
            'payee' => $disbursement->payee,
            'purpose' => $disbursement->purpose,
            'amount' => round((float) $disbursement->amount, 2),
            'dv_number' => $disbursement->dv_number,
            'status' => $disbursement->status,
            'prepared_by' => $disbursement->preparedBy?->name ?? 'Unknown',
            'approved_by' => $disbursement->approvedBy?->name,
            'released_by' => $disbursement->releasedBy?->name,
            'void_reason' => $disbursement->void_reason,
        ];
    }
}
