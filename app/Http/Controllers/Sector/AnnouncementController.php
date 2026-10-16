<?php
namespace App\Http\Controllers\Sector;

use App\Http\Controllers\Controller;
use App\Models\Announcement;

class AnnouncementController extends Controller
{
    public function index()
    {
        $userSector = auth()->user()->sector_type; // e.g., 'senior_citizens' or 'pwd'

        // AUTOMATIC ROUTING: Makikita lang dito ang para sa sector nila at para sa lahat
        $announcements = Announcement::with('author:id,name')
            ->where(function ($q) use ($userSector) {
                $q->where('target_audience', 'all')
                  ->orWhere('target_audience', $userSector);
            })
            ->where('is_published', true)
            ->latest()
            ->get();

        return Inertia::render('Sector/Announcements', [
            'announcements' => $announcements,
            'sectorType' => $userSector,
        ]);
    }
}