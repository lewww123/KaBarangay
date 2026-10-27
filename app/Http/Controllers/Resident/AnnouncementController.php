<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Resident;
use App\Support\AnnouncementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    private const PER_PAGE = 8;

    public function index(Request $request, string $kind = 'announcements'): Response
    {
        $resident = $this->currentResident();
        $kind = $kind === 'updates' ? 'updates' : 'announcements';

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'importance' => ['nullable', Rule::in(Announcement::IMPORTANCE_LEVELS)],
            'period' => ['nullable', Rule::in(['current', 'past'])],
        ]);

        $period = $kind === 'updates'
            ? (string) ($validated['period'] ?? 'current')
            : 'current';

        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'category' => (string) ($validated['category'] ?? ''),
            'importance' => (string) ($validated['importance'] ?? ''),
        ];

        $baseQuery = $this->visibleAnnouncements($resident, $kind, $period);

        $categories = (clone $baseQuery)
            ->reorder()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->filter()
            ->values();

        $query = clone $baseQuery;

        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';

            $query->where(function (Builder $search) use ($term) {
                $search->where('title', 'like', $term)
                    ->orWhere('content', 'like', $term);
            });
        }

        if ($filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }

        if ($filters['importance'] !== '') {
            $query->where('importance', $filters['importance']);
        }

        $paginator = $query->latest()->paginate(self::PER_PAGE)->withQueryString();

        $paginator->through(fn (Announcement $announcement): array => [
            ...$announcement->toArray(),
            'type' => AnnouncementType::for($announcement),
        ]);

        return Inertia::render('Resident/Announcements', [
            'announcements' => $paginator->items(),
            'categories' => $categories,
            'filters' => $filters,
            'kind' => $kind,
            'period' => $period,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(
        Request $request,
        Announcement $announcement,
        string $kind = 'announcements',
    ): Response
    {
        $resident = $this->currentResident();
        $kind = $kind === 'updates' ? 'updates' : 'announcements';
        $period = $kind === 'updates' && $request->query('period') === 'past'
            ? 'past'
            : 'current';

        $announcement = $this->visibleAnnouncements($resident, $kind, $period)
            ->whereKey($announcement->getKey())
            ->firstOrFail();

        return Inertia::render('Resident/AnnouncementDetails', [
            'announcement' => [
                ...$announcement->toArray(),
                'type' => AnnouncementType::for($announcement),
            ],
            'kind' => $kind,
            'period' => $period,
        ]);
    }

    private function currentResident(): Resident
    {
        return Resident::query()
            ->with('sectors')
            ->whereKey((int) session('resident_id'))
            ->firstOrFail();
    }

    /**
     * @return Builder<Announcement>
     */
    private function visibleAnnouncements(
        Resident $resident,
        string $kind = 'announcements',
        string $period = 'current',
    ): Builder
    {
        $sectorSlugs = $resident->sectors->pluck('slug')->toArray();

        $query = Announcement::with(['author:id,name', 'sector:id,name'])
            ->where('approval_status', 'approved')
            ->where('is_published', true)
            ->where('barangay_id', $resident->barangay_id)
            ->where(function (Builder $query) use ($sectorSlugs) {
                $query->where('target_audience', 'all');

                if (in_array('senior', $sectorSlugs, true)) {
                    $query->orWhere('target_audience', 'senior_citizens');
                }

                if (in_array('pwd', $sectorSlugs, true)) {
                    $query->orWhere('target_audience', 'pwd');
                }
            });

        if ($kind === 'updates') {
            AnnouncementType::applyUpdateFilter($query);

            return $period === 'past'
                ? $query->whereNotNull('completed_at')
                : $query->whereNull('completed_at');
        }

        return $query->where(function (Builder $content) {
            AnnouncementType::applyProgramFilter($content);
            $content->orWhere(function (Builder $updates) {
                AnnouncementType::applyUpdateFilter($updates)
                    ->whereNull('completed_at');
            });
        });
    }
}
