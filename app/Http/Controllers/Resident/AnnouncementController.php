<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Resident;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index()
    {
        $residentId = session('resident_id');
        $resident = Resident::with('sectors')->find($residentId);

        // Kunin ang sectors o status ng residente
        $sectorSlugs = $resident ? $resident->sectors->pluck('slug')->toArray() : [];

        // Kukunin LANG ang APPROVED at PUBLISHED announcements para sa barangay ng residente
        $announcements = Announcement::with(['author:id,name', 'sector:id,name'])
            ->where('approval_status', 'approved')
            ->where('is_published', true)
            ->where(function ($query) use ($sectorSlugs, $resident) {
                // Always show 'All Residents' announcements
                $query->where('target_audience', 'all');

                // Kung Senior Citizen ang residente, ipakita rin ang para sa kanila
                if (in_array('senior-citizen', $sectorSlugs) || ($resident && $resident->is_senior)) {
                    $query->orWhere('target_audience', 'senior_citizens');
                }

                // Kung PWD ang residente, ipakita rin ang para sa kanila
                if (in_array('pwd', $sectorSlugs) || ($resident && $resident->is_pwd)) {
                    $query->orWhere('target_audience', 'pwd');
                }
            })
            ->latest()
            ->get();

        return Inertia::render('Resident/Announcements', [
            'announcements' => $announcements,
        ]);
    }
}