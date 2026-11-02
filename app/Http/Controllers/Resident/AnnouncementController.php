<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Resident;
use App\Support\AnnouncementType;
use App\Support\ResidentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    private const PER_PAGE = 10;

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

        $paginator = null;

        $resolvePaginator = function () use (&$paginator, $query) {
            return $paginator ??= $query
                ->latest()
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(fn (Announcement $announcement): array => [
                    ...$announcement->toArray(),
                    'type' => AnnouncementType::for($announcement),
                ]);
        };

        return Inertia::render('Resident/Announcements', [
            'announcements' => $this->deferProp($resolvePaginator),
            'categories' => $this->deferProp(fn () => (clone $baseQuery)
                ->reorder()
                ->select('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->filter()
                ->values()),
            'filters' => $filters,
            'kind' => $kind,
            'period' => $period,
        ]);
    }

    public function show(
        Request $request,
        Announcement $announcement,
        string $kind = 'announcements',
    ): Response {
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

    public function programs(): Response
    {
        $resident = $this->currentResident();

        return Inertia::render('Resident/Programs', [
            'programs' => $this->deferProp(fn () => $this->visiblePrograms($resident)
                ->orderByRaw('event_start_at is null')
                ->orderBy('event_start_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Announcement $program): array => [
                    'id' => $program->id,
                    'title' => $program->title,
                    'description' => $program->content,
                    'category' => $program->category,
                    'schedule' => $program->event_start_at?->toIso8601String(),
                    'location' => $program->location,
                    'eligibility' => $this->eligibilityLabel($program),
                    'requires_attendance' => $program->requires_attendance,
                ])
                ->values()),
        ]);
    }

    private function currentResident(): Resident
    {
        return ResidentContext::get(['sectors']);
    }

    /**
     * @return Builder<Announcement>
     */
    private function visibleAnnouncements(
        Resident $resident,
        string $kind = 'announcements',
        string $period = 'current',
    ): Builder {
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

    /** @return Builder<Announcement> */
    private function visiblePrograms(Resident $resident): Builder
    {
        $query = $this->visibleAnnouncements($resident);

        return AnnouncementType::applyProgramFilter($query)
            ->where(fn (Builder $status) => $status
                ->where('program_status', 'ongoing')
                ->orWhereNull('program_status'));
    }

    private function eligibilityLabel(Announcement $program): string
    {
        return match ($program->target_audience) {
            'senior_citizens' => 'Senior Citizens',
            'pwd' => 'Persons with Disabilities',
            default => 'All Residents',
        };
    }
}
