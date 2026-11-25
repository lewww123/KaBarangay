<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Collection;
use App\Models\Disbursement;
use App\Models\DisclosurePeriod;
use App\Models\FinancialAttachment;
use App\Support\AllocationBalance;
use App\Support\FinancialDisclosureExport;
use App\Support\FinancialDisclosureFilters;
use App\Support\FinancialDisclosureScope;
use App\Support\ResidentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialDisclosureController extends Controller
{
    public function index(Request $request): Response
    {
        $barangayId = (int) ResidentContext::get()->barangay_id;
        $period = $this->publishedPeriod($request, $barangayId);

        $collections = $this->collectionQuery($barangayId, $period)
            ->get()
            ->map(fn (Collection $collection) => [
                'id' => $collection->id,
                'collection_date' => $collection->collection_date?->format('Y-m-d'),
                'collection_type' => $collection->collection_type,
                'amount' => round((float) $collection->amount, 2),
            ])
            ->all();

        $disbursements = $this->disbursementQuery($barangayId, $period)
            ->get()
            ->map(fn (Disbursement $disbursement) => [
                'id' => $disbursement->id,
                'disbursement_date' => $disbursement->disbursement_date?->format('Y-m-d'),
                'allocation_name' => $disbursement->allocation?->name ?? '',
                'purpose' => $disbursement->purpose,
                'amount' => round((float) $disbursement->amount, 2),
            ])
            ->all();

        $allocations = AllocationBalance::addTotalsToQuery(
            FinancialDisclosureScope::allocations($barangayId)->where('fiscal_year', $period->year),
        )
            ->get()
            ->map(fn (Allocation $allocation) => [
                'id' => $allocation->id,
                'name' => $allocation->name,
                'category' => $allocation->category,
                'source' => $allocation->source,
                'amount_approved' => round((float) $allocation->amount_approved, 2),
                'amount_used' => round((float) ($allocation->used_total_sum ?? 0), 2),
                'remaining_balance' => round(
                    (float) $allocation->amount_approved
                    + (float) ($allocation->amendments_total_sum ?? 0)
                    - (float) ($allocation->used_total_sum ?? 0),
                    2,
                ),
            ])
            ->all();

        $attachments = FinancialDisclosureScope::attachments($barangayId)
            ->where('record_type', 'disclosure_period')
            ->where('record_id', $period->id)
            ->orderByDesc('uploaded_at')
            ->get()
            ->map(fn (FinancialAttachment $attachment) => [
                'id' => $attachment->id,
                'title' => $attachment->title,
                'uploaded_at' => $attachment->uploaded_at?->format('Y-m-d H:i'),
                'url' => route('resident.financial-disclosure.attachment', $attachment->id),
            ])
            ->all();

        $periods = FinancialDisclosureScope::disclosurePeriods($barangayId)
            ->where('is_published', true)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (DisclosurePeriod $published) => [
                'year' => (int) $published->year,
                'month' => (int) $published->month,
                'label' => sprintf('%d-%02d', $published->year, $published->month),
            ])
            ->all();

        return Inertia::render('Resident/FinancialDisclosure', [
            'period' => [
                'year' => (int) $period->year,
                'month' => (int) $period->month,
                'label' => sprintf('%d-%02d', $period->year, $period->month),
                'published_at' => $period->published_at?->format('Y-m-d H:i'),
            ],
            'periods' => $periods,
            'summary' => [
                'collections' => round((float) collect($collections)->sum('amount'), 2),
                'disbursements' => round((float) collect($disbursements)->sum('amount'), 2),
                'available_balance' => round((float) collect($allocations)->sum('remaining_balance'), 2),
            ],
            'collections' => $collections,
            'disbursements' => $disbursements,
            'allocations' => $allocations,
            'attachments' => $attachments,
        ]);
    }

    public function download(Request $request, string $type): StreamedResponse
    {
        $barangayId = (int) ResidentContext::get()->barangay_id;
        $period = $this->publishedPeriod($request, $barangayId);
        $suffix = sprintf('-%d-%02d', $period->year, $period->month);

        return match ($type) {
            'collections' => FinancialDisclosureExport::csv('published-collections'.$suffix.'.csv', [
                'Date', 'Type', 'Amount',
            ], collect($this->collectionQuery($barangayId, $period)->get()->all())
                ->map(fn (Collection $collection) => [
                    $collection->collection_date?->format('Y-m-d'),
                    $collection->collection_type,
                    round((float) $collection->amount, 2),
                ])),
            'disbursements' => FinancialDisclosureExport::csv('published-disbursements'.$suffix.'.csv', [
                'Date', 'Allocation', 'Purpose', 'Amount',
            ], collect($this->disbursementQuery($barangayId, $period)->get()->all())
                ->map(fn (Disbursement $disbursement) => [
                    $disbursement->disbursement_date?->format('Y-m-d'),
                    $disbursement->allocation?->name ?? '',
                    $disbursement->purpose,
                    round((float) $disbursement->amount, 2),
                ])),
            default => abort(404),
        };
    }

    public function attachment(Request $request, string $attachment): StreamedResponse
    {
        $barangayId = (int) ResidentContext::get()->barangay_id;

        $record = FinancialDisclosureScope::attachments($barangayId)
            ->whereKey($attachment)
            ->where('record_type', 'disclosure_period')
            ->firstOrFail();

        abort_unless($record->record_id !== null, 404);

        $published = FinancialDisclosureScope::disclosurePeriods($barangayId)
            ->whereKey($record->record_id)
            ->where('is_published', true)
            ->exists();

        abort_unless($published, 404);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404);

        $extension = strtolower(pathinfo($record->file_path, PATHINFO_EXTENSION));
        $filename = Str::slug($record->title).'.'.$extension;
        $mime = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->response($record->file_path, $filename, ['Content-Type' => $mime]);
    }

    private function publishedPeriod(Request $request, int $barangayId): DisclosurePeriod
    {
        $year = FinancialDisclosureFilters::integer($request->query('year'));
        $month = $this->range($request->query('month'), 1, 12);

        if ($year !== null && $month !== null && $month >= 1 && $month <= 12) {
            return FinancialDisclosureScope::publishedDisclosurePeriod($barangayId, $year, $month);
        }

        $period = FinancialDisclosureScope::disclosurePeriods($barangayId)
            ->where('is_published', true)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        abort_unless($period !== null, 404, 'No disclosure period is published.');

        return $period;
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

    private function collectionQuery(int $barangayId, DisclosurePeriod $period): Builder
    {
        $query = FinancialDisclosureScope::collections($barangayId)
            ->where('status', 'Verified')
            ->where('is_waived', false)
            ->whereNull('void_reason');

        $this->applyPeriod($query, 'collection_date', $period);

        return $query->orderByDesc('collection_date');
    }

    private function disbursementQuery(int $barangayId, DisclosurePeriod $period): Builder
    {
        $query = FinancialDisclosureScope::disbursements($barangayId)
            ->with('allocation:id,name')
            ->where('status', 'Released');

        $this->applyPeriod($query, 'disbursement_date', $period);

        return $query->orderByDesc('disbursement_date');
    }

    private function applyPeriod(Builder $query, string $column, DisclosurePeriod $period): void
    {
        $query->whereYear($column, $period->year)
            ->whereMonth($column, $period->month);
    }
}
