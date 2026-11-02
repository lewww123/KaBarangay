<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CertificateRequest;
use App\Models\LuponCase;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentRegistration;
use App\Models\StaffAssignment;
use App\Support\AnnouncementType;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $barangayId = (int) $user->barangay_id;
        $payload = null;

        $resolvePayload = function () use (&$payload, $barangayId): array {
            if ($payload !== null) {
                return $payload;
            }

            return $payload = $this->rememberAggregate(
                "admin:dashboard:v2:{$barangayId}",
                30,
                function () use ($barangayId): array {
                    $puroks = collect($this->rememberReference('admin:purok-options:v2:'.$barangayId, 300, fn () => Purok::query()
                        ->where('barangay_id', $barangayId)
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->get(['id', 'name'])->toArray()));

                    $puroksCount = $puroks->count();

                    try {
                        $counts = $this->countsQuery($barangayId, true)->first();
                    } catch (\Throwable) {
                        $counts = $this->countsQuery($barangayId, false)->first();
                    }

                    $residents = (int) ($counts->residents ?? 0);
                    $totalPrograms = (int) ($counts->total_programs ?? 0);
                    $totalAnnouncements = (int) ($counts->total_announcements ?? 0);
                    $activeStaff = (int) ($counts->active_staff ?? 0);
                    $openCases = (int) ($counts->open_cases ?? 0);

                    $announcements = Announcement::query()
                        ->where('barangay_id', $barangayId)
                        ->where('is_published', true)
                        ->where('approval_status', 'approved')
                        ->latest('event_start_at')
                        ->latest('created_at')
                        ->limit(5)
                        ->get(['id', 'title', 'content', 'category', 'event_start_at', 'location'])
                        ->map(fn (Announcement $announcement) => [
                            'id' => $announcement->id,
                            'title' => $announcement->title,
                            'content' => $announcement->content,
                            'category' => $announcement->category,
                            'eventStartAt' => $announcement->event_start_at?->format('M j, Y g:i A'),
                            'location' => $announcement->location,
                        ])
                        ->values();

                    $notifications = [];

                    // Pending resident registrations (latest)
                    ResidentRegistration::query()
                        ->where('barangay_id', $barangayId)
                        ->whereIn('status', ['pending', 'needs_correction'])
                        ->latest('submitted_at')
                        ->limit(3)
                        ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'submitted_at'])
                        ->each(function (ResidentRegistration $reg) use (&$notifications) {
                            $name = collect([
                                $reg->first_name,
                                $reg->middle_name,
                                $reg->last_name,
                                $reg->suffix,
                            ])->filter()->implode(' ');
                            $notifications[] = [
                                'id' => 'reg-'.$reg->id,
                                'icon' => '♙',
                                'title' => 'Resident registration to review',
                                'description' => $name ?: 'New application',
                                'time' => $reg->submitted_at
                                    ? Carbon::parse($reg->submitted_at)->diffForHumans()
                                    : '',
                                'href' => '/admin/residents',
                            ];
                        });

                    // Pending certificates (awaiting processing) - link to verified existing page
                    CertificateRequest::query()
                        ->where('barangay_id', $barangayId)
                        ->whereNotIn('status', ['Released', 'Rejected'])
                        ->latest('created_at')
                        ->limit(3)
                        ->select(['id', 'certificate_id', 'status', 'submitted_at', 'created_at'])
                        ->selectSub(
                            DB::table('certificates')
                                ->select('name')
                                ->whereColumn('certificates.id', 'certificate_requests.certificate_id')
                                ->limit(1),
                            'certificate_name',
                        )
                        ->get()
                        ->each(function (CertificateRequest $cr) use (&$notifications) {
                            $notifications[] = [
                                'id' => 'cert-'.$cr->id,
                                'icon' => '!',
                                'title' => 'Certificate request awaiting processing',
                                'description' => $cr->certificate_name ?? 'Certificate request',
                                'time' => $cr->submitted_at
                                    ? Carbon::parse($cr->submitted_at)->diffForHumans()
                                    : ($cr->created_at ? Carbon::parse($cr->created_at)->diffForHumans() : ''),
                                'href' => '/admin/sectors',
                            ];
                        });

                    // Sort notifications by time desc (lexicographically not ideal, but timestamps differ)
                    // Simple: keep original priority; but to bound, just take up to 5
                    $notifications = array_slice($notifications, 0, 5);

                    return [
                        'stats' => [
                            'residents' => $residents,
                            'totalPrograms' => $totalPrograms,
                            'totalAnnouncements' => $totalAnnouncements,
                            'puroks' => $puroksCount,
                            'cases' => $openCases,
                        ],
                        'puroks' => $puroks,
                        'announcements' => $announcements,
                        'notifications' => $notifications,
                        'activity' => [
                            'activeStaff' => $activeStaff,
                        ],
                    ];
                },
            );
        };

        return Inertia::render('Dashboard', [
            'stats' => $this->deferProp(fn () => $resolvePayload()['stats']),
            'puroks' => $this->deferProp(fn () => $resolvePayload()['puroks']),
            'announcements' => $this->deferProp(fn () => $resolvePayload()['announcements']),
            'notifications' => $this->deferProp(fn () => $resolvePayload()['notifications']),
            'activity' => $this->deferProp(fn () => $resolvePayload()['activity']),
        ]);
    }

    private function countsQuery(int $barangayId, bool $includeOpenCases): Builder
    {
        $query = DB::query()
            ->selectSub(
                Resident::query()
                    ->where('barangay_id', $barangayId)
                    ->approvedAccount()
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'residents',
            )
            ->selectSub(
                AnnouncementType::applyProgramFilter(
                    Announcement::query()->where('barangay_id', $barangayId),
                )->selectRaw('COUNT(*)')->toBase(),
                'total_programs',
            )
            ->selectSub(
                Announcement::query()
                    ->where('barangay_id', $barangayId)
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'total_announcements',
            )
            ->selectSub(
                StaffAssignment::query()
                    ->where('barangay_id', $barangayId)
                    ->where('is_active', true)
                    ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'active_staff',
            );

        if ($includeOpenCases) {
            $query->selectSub(
                LuponCase::query()
                    ->where('barangay_id', $barangayId)
                    ->whereIn('status', ['Active', 'Under Mediation', 'Under Conciliation'])
                    ->selectRaw('COUNT(*)')
                    ->toBase(),
                'open_cases',
            );
        }

        return $query;
    }
}
