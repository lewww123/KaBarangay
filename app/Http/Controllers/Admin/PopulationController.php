<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PopulationController extends Controller
{
    /**
     * Population Monitoring.
     *
     * SCOPE / DEFINITIONS
     * -------------------
     * - Only records in the `residents` table represent the permanent
     *   population of a barangay. A `residents` row is created exclusively
     *   when a `resident_registrations` application is approved
     *   (see ResidentRegistrationController::approve()).
     * - A resident is part of the population only while `status = active`.
     *   Residents flagged `inactive` are reported separately and are NOT
     *   counted towards the population totals.
     * - Applications that are still `pending` / `needs_correction` or
     *   `rejected` live in `resident_registrations`, are not residents yet,
     *   and are reported separately as a registration pipeline.
     * - Sectors are global definitions; senior / PWD membership is derived
     *   from the `resident_sector` pivot, so a single resident can belong to
     *   both the senior and PWD sectors. Category counts are therefore
     *   distinct-but-overlapping and the overlap is surfaced explicitly.
     * - All figures are scoped to the authenticated admin's barangay.
     */
    public function index(Request $request): Response
    {
        $barangayId = (int) $request->user()->barangay_id;

        $totalResidents = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->count();

        $seniorCitizens = $this->countBySectorSlug($barangayId, 'senior');

        $pwdMembers = $this->countBySectorSlug($barangayId, 'pwd');

        $seniorAndPwd = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->whereHas('sectors', fn ($query) => $query->where('slug', 'senior'))
            ->whereHas('sectors', fn ($query) => $query->where('slug', 'pwd'))
            ->count();

        $regularResidents = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->whereDoesntHave('sectors')
            ->count();

        $inactiveResidents = Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', '!=', 'active')
            ->count();

        $pendingRegistrations = ResidentRegistration::query()
            ->where('barangay_id', $barangayId)
            ->whereIn('status', ['pending', 'needs_correction'])
            ->count();

        $rejectedRegistrations = ResidentRegistration::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'rejected')
            ->count();

        $totalsByPurok = $this->countByPurok($barangayId);
        $seniorsByPurok = $this->countByPurok($barangayId, 'senior');
        $pwdByPurok = $this->countByPurok($barangayId, 'pwd');

        $puroks = Purok::query()
            ->where('barangay_id', $barangayId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Purok $purok) => [
                'id' => $purok->id,
                'name' => $purok->name,
                'total' => (int) ($totalsByPurok[$purok->id] ?? 0),
                'seniors' => (int) ($seniorsByPurok[$purok->id] ?? 0),
                'pwd' => (int) ($pwdByPurok[$purok->id] ?? 0),
            ])
            ->values();

        return Inertia::render('Admin/Population', [
            'stats' => [
                'totalResidents' => $totalResidents,
                'seniorCitizens' => $seniorCitizens,
                'pwdMembers' => $pwdMembers,
                'seniorAndPwd' => $seniorAndPwd,
                'regularResidents' => $regularResidents,
                'inactiveResidents' => $inactiveResidents,
                'pendingRegistrations' => $pendingRegistrations,
                'rejectedRegistrations' => $rejectedRegistrations,
            ],
            'puroks' => $puroks,
        ]);
    }

    /**
     * Count active residents in the barangay tagged with the given sector slug.
     */
    private function countBySectorSlug(int $barangayId, string $slug): int
    {
        return Resident::query()
            ->where('barangay_id', $barangayId)
            ->where('status', 'active')
            ->whereHas('sectors', fn ($query) => $query->where('slug', $slug))
            ->count();
    }

    /**
     * Count active residents per purok, optionally limited to a sector slug.
     *
     * Returns a collection keyed by purok_id. Runs a single grouped query so
     * the purok breakdown stays constant regardless of the number of puroks.
     *
     * @return Collection<int, int>
     */
    private function countByPurok(int $barangayId, ?string $sectorSlug = null)
    {
        return Resident::query()
            ->where('residents.barangay_id', $barangayId)
            ->where('residents.status', 'active')
            ->when(
                $sectorSlug !== null,
                fn ($query) => $query->whereHas(
                    'sectors',
                    fn ($sectorQuery) => $sectorQuery->where('slug', $sectorSlug)
                )
            )
            ->selectRaw('residents.purok_id as purok_id, COUNT(*) as aggregate')
            ->groupBy('residents.purok_id')
            ->pluck('aggregate', 'purok_id');
    }
}
