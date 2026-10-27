<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CertificateRequest;
use App\Models\LuponCase;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentRegistration;
use App\Models\User;
use App\Support\AnnouncementType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $barangayId = (int) $user->barangay_id;

        $residents = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->count();
        $totalPrograms = AnnouncementType::applyProgramFilter(
            Announcement::query()->where('barangay_id', $barangayId),
        )->count();

        $totalAnnouncements = Announcement::query()
            ->where('barangay_id', $barangayId)
            ->count();

        $activeStaff = User::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->where('role', '!=', 'super_admin')
            ->count();

        $puroksCount = Purok::query()
            ->where('barangay_id', $barangayId)
            ->count();

        $puroks = Purok::query()
            ->where('barangay_id', $barangayId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Purok $purok) => ['id' => $purok->id, 'name' => $purok->name])
            ->values();

        $openCases = 0;
        try {
            if (Schema::hasTable('lupon_cases')) {
                $openCases = LuponCase::query()
                    ->where('barangay_id', $barangayId)
                    ->whereIn('status', ['Active', 'Under Mediation', 'Under Conciliation'])
                    ->count();
            }
        } catch (\Throwable) {
            $openCases = 0;
        }

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
            ->get()
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
            ->get()
            ->each(function (CertificateRequest $cr) use (&$notifications) {
                $notifications[] = [
                    'id' => 'cert-'.$cr->id,
                    'icon' => '!',
                    'title' => 'Certificate request awaiting processing',
                    'description' => $cr->certificate?->name ?? 'Certificate request',
                    'time' => $cr->submitted_at
                        ? Carbon::parse($cr->submitted_at)->diffForHumans()
                        : ($cr->created_at ? Carbon::parse($cr->created_at)->diffForHumans() : ''),
                    'href' => '/admin/sectors',
                ];
            });

        // Sort notifications by time desc (lexicographically not ideal, but timestamps differ)
        // Simple: keep original priority; but to bound, just take up to 5
        $notifications = array_slice($notifications, 0, 5);

        return Inertia::render('Dashboard', [
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
        ]);
    }
}
