<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Resident;
use App\Support\AnnouncementType;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index()
    {
        $resident = $this->currentResident();

        $announcements = $this->visibleAnnouncements($resident)
            ->latest()
            ->get()
            ->map(fn (Announcement $announcement) => [
                ...$announcement->toArray(),
                'type' => AnnouncementType::for($announcement),
            ]);

        return Inertia::render('Resident/Announcements', [
            'announcements' => $announcements,
        ]);
    }

    public function show(Announcement $announcement)
    {
        $resident = $this->currentResident();

        $announcement = $this->visibleAnnouncements($resident)
            ->whereKey($announcement->getKey())
            ->firstOrFail();

        return Inertia::render('Resident/AnnouncementDetails', [
            'announcement' => [
                ...$announcement->toArray(),
                'type' => AnnouncementType::for($announcement),
            ],
        ]);
    }

    private function currentResident(): Resident
    {
        return Resident::with('sectors')->findOrFail(session('resident_id'));
    }

    private function visibleAnnouncements(Resident $resident): Builder
    {
        $sectorSlugs = $resident->sectors->pluck('slug')->toArray();

        return Announcement::with(['author:id,name', 'sector:id,name'])
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
    }
}
