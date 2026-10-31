<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     *   population of a barangay. Masterlist creation and online-account
     *   approval are distinct: registration approval links an existing
     *   resident rather than creating another population record.
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

        $groups = null;
        $resolveGroups = function () use ($barangayId, &$groups) {
            $flags = Resident::query()
                ->where('barangay_id', $barangayId)
                ->select(['purok_id', 'status'])
                ->withExists([
                    'sectors as is_senior' => fn ($query) => $query->where('slug', 'senior'),
                    'sectors as is_pwd' => fn ($query) => $query->where('slug', 'pwd'),
                    'sectors as has_sector',
                ]);

            return $groups ??= DB::query()->fromSub($flags->toBase(), 'population')
                ->selectRaw('purok_id, status, COUNT(*) as total,
                    COUNT(CASE WHEN is_senior = true THEN 1 END) as seniors,
                    COUNT(CASE WHEN is_pwd = true THEN 1 END) as pwd,
                    COUNT(CASE WHEN is_senior = true AND is_pwd = true THEN 1 END) as overlap,
                    COUNT(CASE WHEN has_sector = false THEN 1 END) as regular')
                ->groupBy('purok_id', 'status')->get();
        };

        $loadStats = function () use ($resolveGroups, $barangayId): array {
            $groups = $resolveGroups();
            $active = $groups->where('status', 'active');
            $pipeline = ResidentRegistration::query()->where('barangay_id', $barangayId)
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

            return [
                'totalResidents' => (int) $active->sum('total'),
                'seniorCitizens' => (int) $active->sum('seniors'),
                'pwdMembers' => (int) $active->sum('pwd'),
                'seniorAndPwd' => (int) $active->sum('overlap'),
                'regularResidents' => (int) $active->sum('regular'),
                'inactiveResidents' => (int) $groups->where('status', '!=', 'active')->sum('total'),
                'pendingRegistrations' => (int) ($pipeline['pending'] ?? 0) + (int) ($pipeline['needs_correction'] ?? 0),
                'rejectedRegistrations' => (int) ($pipeline['rejected'] ?? 0),
            ];
        };

        $loadPuroks = function () use ($barangayId, $resolveGroups): array {
            $counts = $resolveGroups()->where('status', 'active')->keyBy('purok_id');
            $options = $this->rememberReference("population:puroks:{$barangayId}", 300,
                fn () => Purok::query()->where('barangay_id', $barangayId)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->toArray());

            return array_map(fn (array $purok) => [
                ...$purok,
                'total' => (int) ($counts[$purok['id']]->total ?? 0),
                'seniors' => (int) ($counts[$purok['id']]->seniors ?? 0),
                'pwd' => (int) ($counts[$purok['id']]->pwd ?? 0),
            ], $options);
        };

        return Inertia::render('Admin/Population', [
            'stats' => $this->deferProp($loadStats),
            'puroks' => $this->deferProp($loadPuroks),
        ]);
    }
}
