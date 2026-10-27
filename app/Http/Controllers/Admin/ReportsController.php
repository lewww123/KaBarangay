<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CertificateIssuance;
use App\Models\LuponCase;
use App\Models\Resident;
use App\Models\Sector;
use App\Support\AnnouncementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    private const REPORTING_TIMEZONE = 'Asia/Manila';

    private const MAX_RANGE_DAYS = 366;

    private const CATEGORIES = ['all', 'population', 'certificates', 'lupon', 'programs', 'financial'];

    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $report = $this->buildReport((int) $request->user()->barangay_id, $filters);

        return Inertia::render('Admin/Reports', [
            ...$report,
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $report = $this->buildReport((int) $request->user()->barangay_id, $filters);
        $filename = 'barangay-reports-'.$report['window']['from'].'-'.$report['window']['to'].'.csv';

        return response()->streamDownload(function () use ($report, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, ['Report', 'Metric', 'Period', 'Value'], ',', '"', '');

            if ($this->includes($filters, 'population')) {
                foreach ($report['population']['metrics'] as $label => $value) {
                    $this->writeCsvRow($output, ['Population', $label, 'Current snapshot', $value]);
                }
                foreach ($report['population']['sectors'] as $sector) {
                    $this->writeCsvRow($output, ['Population', $sector['name'], 'Current snapshot', $sector['count']]);
                }
            }

            if ($this->includes($filters, 'certificates')) {
                $this->writeCsvRow($output, ['Certificates', 'Generated issuances', $report['window']['label'], $report['certificates']['count']]);
                foreach ($report['certificates']['trend'] as $point) {
                    $this->writeCsvRow($output, ['Certificates', 'Issuances', $point['label'], $point['count']]);
                }
                foreach ($report['certificates']['by_type'] as $type) {
                    $this->writeCsvRow($output, ['Certificates', 'Issuance type: '.$type['label'], $report['window']['label'], $type['count']]);
                }
            }

            if ($this->includes($filters, 'lupon')) {
                $this->writeCsvRow($output, ['Lupon', 'Cases filed', $report['window']['label'], $report['lupon']['count']]);
                foreach ($report['lupon']['trend'] as $point) {
                    $this->writeCsvRow($output, ['Lupon', 'Cases filed', $point['label'], $point['count']]);
                }
                foreach ($report['lupon']['by_type'] as $type) {
                    $this->writeCsvRow($output, ['Lupon', 'Case type: '.$type['label'], $report['window']['label'], $type['count']]);
                }
            }

            if ($this->includes($filters, 'programs')) {
                $this->writeCsvRow($output, ['Programs & Attendance', 'Approved and published programs', $report['window']['label'], $report['programs']['count']]);
                foreach ($report['programs']['by_category'] as $category) {
                    $this->writeCsvRow($output, ['Programs & Attendance', 'Program category: '.$category['label'], $report['window']['label'], $category['count']]);
                }
                foreach ($report['programs']['attendance'] as $status) {
                    $this->writeCsvRow($output, ['Programs & Attendance', 'Attendance status: '.$status['label'], $report['window']['label'], $status['count']]);
                }
            }

            if ($this->includes($filters, 'financial')) {
                $this->writeCsvRow($output, ['Financial', 'Availability', $report['window']['label'], 'Unavailable: no authoritative financial ledger']);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{period: string, date: string, date_from: string, date_to: string, category: string} */
    private function validatedFilters(Request $request): array
    {
        $validated = Validator::make($request->query(), [
            'period' => ['sometimes', 'string', 'in:daily,weekly,monthly,yearly,range'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['required_if:period,range', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_if:period,range', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', self::CATEGORIES)],
        ])->validate();

        $period = $validated['period'] ?? 'monthly';
        $date = $validated['date'] ?? CarbonImmutable::now(self::REPORTING_TIMEZONE)->format('Y-m-d');
        $category = $validated['category'] ?? 'all';

        if ($period === 'range') {
            $from = $this->localDate($validated['date_from']);
            $to = $this->localDate($validated['date_to']);
            if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
                throw ValidationException::withMessages([
                    'date_to' => 'Choose a date range of 366 days or fewer.',
                ]);
            }
        }

        return [
            'period' => $period,
            'date' => $date,
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
            'category' => $category,
        ];
    }

    /** @param array{period: string, date: string, date_from: string, date_to: string, category: string} $filters
     *  @return array<string, mixed>
     */
    private function buildReport(int $barangayId, array $filters): array
    {
        $window = $this->window($filters);
        $residents = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active');

        $population = [
            'metrics' => [
                'Total active Residents' => (clone $residents)->count(),
                'Senior Citizens' => (clone $residents)->whereHas('sectors', fn ($query) => $query->where('slug', 'senior'))->count(),
                'PWD' => (clone $residents)->whereHas('sectors', fn ($query) => $query->where('slug', 'pwd'))->count(),
                'General Residents' => (clone $residents)->whereDoesntHave('sectors')->count(),
            ],
            'sectors' => $this->sectorBreakdown($barangayId),
            'distribution_note' => 'General follows the existing definition: active Residents with no sector membership. Senior and PWD memberships may overlap, so an exclusive distribution chart is omitted.',
        ];

        $certificateQuery = CertificateIssuance::query()
            ->where('barangay_id', $barangayId)
            ->where('issued_at', '>=', $window['start_utc'])
            ->where('issued_at', '<', $window['end_utc']);
        $caseQuery = LuponCase::query()
            ->where('barangay_id', $barangayId)
            ->where('filed_date', '>=', $window['date_from'])
            ->where('filed_date', '<', $window['date_to_exclusive']);
        $programQuery = $this->programQuery($barangayId, $window);

        return [
            'window' => [
                'from' => $window['date_from'],
                'to' => $window['date_to_inclusive'],
                'label' => $window['label'],
                'timezone' => self::REPORTING_TIMEZONE,
                'boundary' => $window['boundary'],
            ],
            'population' => $population,
            'certificates' => [
                'count' => (clone $certificateQuery)->count(),
                'trend' => $this->trend($certificateQuery, 'issued_at', true, $window),
                'by_type' => (clone $certificateQuery)
                    ->selectRaw('certificate_type as label, COUNT(*) as aggregate')
                    ->groupBy('certificate_type')
                    ->orderBy('certificate_type')
                    ->get()
                    ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->aggregate])
                    ->all(),
                'meaning' => 'Issuance rows represent generated and recorded certificates, not proof of physical handover. Fee snapshots are not revenue.',
            ],
            'lupon' => [
                'count' => (clone $caseQuery)->count(),
                'trend' => $this->trend($caseQuery, 'filed_date', false, $window),
                'by_type' => (clone $caseQuery)
                    ->selectRaw('case_type as label, COUNT(*) as aggregate')
                    ->groupBy('case_type')
                    ->orderBy('case_type')
                    ->get()
                    ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->aggregate])
                    ->all(),
                'meaning' => 'Counts Lupon case records by filed_date. Complaints are not counted separately.',
            ],
            'programs' => [
                'count' => (clone $programQuery)->count(),
                'trend' => $this->trend($programQuery, 'event_start_at', true, $window),
                'by_category' => (clone $programQuery)
                    ->selectRaw('category as label, COUNT(*) as aggregate')
                    ->groupBy('category')
                    ->orderBy('category')
                    ->get()
                    ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->aggregate])
                    ->all(),
                'attendance' => $this->attendanceBreakdown($barangayId, $window),
            ],
            'financial' => [
                'available' => false,
                'message' => 'Financial reports are unavailable because the current data sources do not provide an authoritative revenue and expense ledger.',
            ],
        ];
    }

    /** @return array<string, int|string> */
    private function sectorBreakdown(int $barangayId): array
    {
        return DB::table('sectors')
            ->leftJoin('resident_sector as rs', 'rs.sector_id', '=', 'sectors.id')
            ->leftJoin('residents as r', function ($join) use ($barangayId): void {
                $join->on('r.id', '=', 'rs.resident_id')
                    ->where('r.barangay_id', '=', $barangayId)
                    ->where('r.status', '=', 'active');
            })
            ->select('sectors.name')
            ->selectRaw('COUNT(DISTINCT r.id) as aggregate')
            ->groupBy('sectors.id', 'sectors.name')
            ->orderBy('sectors.name')
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'count' => (int) $row->aggregate])
            ->all();
    }

    private function programQuery(int $barangayId, array $window): EloquentBuilder
    {
        return Announcement::query()
            ->where('barangay_id', $barangayId)
            ->where('approval_status', 'approved')
            ->where('is_published', true)
            ->whereNotNull('event_start_at')
            ->where('event_start_at', '>=', $window['start_utc'])
            ->where('event_start_at', '<', $window['end_utc'])
            ->where(function ($query): void {
                AnnouncementType::applyProgramFilter($query);
            });
    }

    /** @return list<array{label: string, count: int}> */
    private function attendanceBreakdown(int $barangayId, array $window): array
    {
        return DB::table('announcement_attendances as aa')
            ->join('announcements as a', 'a.id', '=', 'aa.announcement_id')
            ->where('aa.barangay_id', $barangayId)
            ->where('a.barangay_id', $barangayId)
            ->where('a.approval_status', 'approved')
            ->where('a.is_published', true)
            ->whereNotNull('a.event_start_at')
            ->where('a.event_start_at', '>=', $window['start_utc'])
            ->where('a.event_start_at', '<', $window['end_utc'])
            ->where(function ($query): void {
                $query->where('a.category', 'Event')->orWhere('a.requires_attendance', true);
            })
            ->selectRaw('aa.status as label, COUNT(*) as aggregate')
            ->groupBy('aa.status')
            ->orderBy('aa.status')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->aggregate])
            ->all();
    }

    /** @param EloquentBuilder<CertificateIssuance>|EloquentBuilder<LuponCase>|EloquentBuilder<Announcement> $query
     *  @return list<array{label: string, count: int}>
     */
    private function trend(EloquentBuilder $query, string $column, bool $timestamp, array $window): array
    {
        $granularity = $window['granularity'];
        $expression = $this->bucketExpression($column, $timestamp, $granularity);
        $counts = (clone $query)
            ->selectRaw($expression.' as bucket, COUNT(*) as aggregate')
            ->groupByRaw($expression)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->bucket => (int) $row->aggregate]);

        $buckets = $window['buckets'];
        if (! $timestamp && $granularity === 'hour') {
            $buckets = [[
                'key' => $window['date_from'],
                'label' => CarbonImmutable::parse($window['date_from'], self::REPORTING_TIMEZONE)->format('M j'),
            ]];
        }

        return collect($buckets)
            ->map(fn (array $bucket) => [
                'label' => $bucket['label'],
                'count' => (int) ($counts[$bucket['key']] ?? 0),
            ])
            ->all();
    }

    private function bucketExpression(string $column, bool $timestamp, string $granularity): string
    {
        $driver = DB::connection()->getDriverName();
        if ($timestamp) {
            $local = match ($driver) {
                'pgsql' => "(($column AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Manila')",
                'mysql' => "CONVERT_TZ($column, '+00:00', '+08:00')",
                default => "datetime($column, '+8 hours')",
            };

            return match ([$driver, $granularity]) {
                ['pgsql', 'hour'] => "LPAD(CAST(EXTRACT(HOUR FROM $local) AS INTEGER)::text, 2, '0')",
                ['mysql', 'hour'] => "DATE_FORMAT($local, '%H')",
                ['sqlite', 'hour'] => "strftime('%H', $local)",
                ['pgsql', 'month'] => "TO_CHAR($local, 'YYYY-MM')",
                ['mysql', 'month'] => "DATE_FORMAT($local, '%Y-%m')",
                ['sqlite', 'month'] => "strftime('%Y-%m', $local)",
                ['pgsql', 'day'] => "TO_CHAR($local, 'YYYY-MM-DD')",
                ['mysql', 'day'] => "DATE_FORMAT($local, '%Y-%m-%d')",
                default => "date($local)",
            };
        }

        if ($granularity === 'month') {
            return match ($driver) {
                'pgsql' => "TO_CHAR($column, 'YYYY-MM')",
                'mysql' => "DATE_FORMAT($column, '%Y-%m')",
                default => "strftime('%Y-%m', $column)",
            };
        }

        return $column;
    }

    /** @param array{period: string, date: string, date_from: string, date_to: string, category: string} $filters
     *  @return array<string, mixed>
     */
    private function window(array $filters): array
    {
        if ($filters['period'] === 'range') {
            $start = $this->localDate($filters['date_from'])->startOfDay();
            $endExclusive = $this->localDate($filters['date_to'])->addDay()->startOfDay();
            $granularity = 'day';
            $label = $start->format('M j, Y').' – '.$endExclusive->subDay()->format('M j, Y');
        } else {
            $anchor = $this->localDate($filters['date']);
            [$start, $endExclusive, $granularity] = match ($filters['period']) {
                'daily' => [$anchor->startOfDay(), $anchor->startOfDay()->addDay(), 'hour'],
                'weekly' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->startOfWeek(CarbonImmutable::MONDAY)->addWeek(), 'day'],
                'yearly' => [$anchor->startOfYear(), $anchor->startOfYear()->addYear(), 'month'],
                default => [$anchor->startOfMonth(), $anchor->startOfMonth()->addMonth(), 'day'],
            };
            $label = match ($filters['period']) {
                'daily' => $start->format('M j, Y'),
                'weekly' => $start->format('M j').' – '.$endExclusive->subDay()->format('M j, Y'),
                'yearly' => $start->format('Y'),
                default => $start->format('F Y'),
            };
        }

        $buckets = [];
        $cursor = $start;
        while ($cursor < $endExclusive) {
            $key = match ($granularity) {
                'hour' => $cursor->format('H'),
                'month' => $cursor->format('Y-m'),
                default => $cursor->format('Y-m-d'),
            };
            $bucketLabel = match ($granularity) {
                'hour' => $cursor->format('g A'),
                'month' => $cursor->format('M Y'),
                default => $cursor->format('M j'),
            };
            $buckets[] = ['key' => $key, 'label' => $bucketLabel];
            $cursor = match ($granularity) {
                'hour' => $cursor->addHour(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return [
            'start_utc' => $start->utc()->format('Y-m-d H:i:s'),
            'end_utc' => $endExclusive->utc()->format('Y-m-d H:i:s'),
            'date_from' => $start->format('Y-m-d'),
            'date_to_inclusive' => $endExclusive->subDay()->format('Y-m-d'),
            'date_to_exclusive' => $endExclusive->format('Y-m-d'),
            'granularity' => $granularity,
            'buckets' => $buckets,
            'label' => $label,
            'boundary' => 'Calendar boundaries use Philippine time. Weeks run Monday through Sunday. Selected ranges include both dates; timestamp filters use local midnight as an inclusive start and the next local midnight as an exclusive end. Stored UTC timestamps are converted to Asia/Manila for chart buckets.',
        ];
    }

    private function localDate(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $value, self::REPORTING_TIMEZONE);
    }

    private function includes(array $filters, string $category): bool
    {
        return $filters['category'] === 'all' || $filters['category'] === $category;
    }

    /** @param resource $output
     *  @param list<int|string> $row
     */
    private function writeCsvRow($output, array $row): void
    {
        $safe = array_map(function (int|string $value): int|string {
            if (is_int($value)) {
                return $value;
            }

            $trimmed = ltrim($value, " \t\r\n\0\x0B\xEF\xBB\xBF");
            if ($trimmed !== '' && preg_match('/^[=+@-]/', $trimmed) === 1) {
                return "'".$value;
            }

            return $value;
        }, $row);

        fputcsv($output, $safe, ',', '"', '');
    }
}
