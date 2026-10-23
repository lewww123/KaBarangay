<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CertificateRequest;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentRegistration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

        $pendingAnnouncements = Announcement::query()
            ->where('barangay_id', $barangayId)
            ->where('approval_status', 'pending')
            ->count();

        $activeStaff = User::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->where('role', '!=', 'super_admin')
            ->count();

        $puroksCount = Purok::query()
            ->where('barangay_id', $barangayId)
            ->count();

        $releasedCertificates = CertificateRequest::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'Released')
            ->count();

        $puroks = Purok::query()
            ->where('barangay_id', $barangayId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Purok $purok) => [
                'id' => $purok->id,
                'name' => $purok->name,
            ])
            ->values();

        $notifications = [];

        // Pending announcements (latest)
        Announcement::query()
            ->where('barangay_id', $barangayId)
            ->where('approval_status', 'pending')
            ->latest()
            ->limit(3)
            ->get()
            ->each(function (Announcement $announcement) use (&$notifications) {
                $notifications[] = [
                    'id' => 'ann-'.$announcement->id,
                    'icon' => '📢',
                    'title' => 'Announcement awaiting approval',
                    'description' => $announcement->title,
                    'time' => $announcement->created_at
                        ? Carbon::parse($announcement->created_at)->diffForHumans()
                        : '',
                    'href' => '/admin/announcements/approvals',
                ];
            });

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
                'pendingAnnouncements' => $pendingAnnouncements,
                'activeStaff' => $activeStaff,
                'puroks' => $puroksCount,
                'releasedCertificates' => $releasedCertificates,
            ],
            'puroks' => $puroks,
            'notifications' => $notifications,
            'activity' => [
                'pendingApprovals' => $pendingAnnouncements,
                'activeStaff' => $activeStaff,
            ],
        ]);
    }
}
