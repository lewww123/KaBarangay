<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Concerns\ManagesOrgChart;
use App\Http\Controllers\Controller;
use App\Models\OrgChartPosition;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrgChartController extends Controller
{
    use ManagesOrgChart;

    public function index(Request $request): Response
    {
        $resident = $this->resident($request);

        $positions = OrgChartPosition::query()
            ->where('barangay_id', $resident->barangay_id)
            ->where('status', 'published')
            ->orderBy('title')
            ->get();

        return Inertia::render('Resident/OrgChart', [
            'tree' => $this->chartTree($positions, $this->photoRouteName()),
            'resident' => [
                'full_name' => $resident->full_name,
            ],
        ]);
    }

    public function photo(
        Request $request,
        OrgChartPosition $position
    ): BinaryFileResponse {
        $resident = $this->resident($request);

        abort_unless(
            (int) $position->barangay_id === (int) $resident->barangay_id
                && $position->status === 'published',
            404
        );

        return $this->photoResponse($position);
    }

    private function resident(Request $request): Resident
    {
        return Resident::query()
            ->whereKey($request->session()->get('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();
    }

    protected function portal(): string
    {
        return 'resident';
    }

    protected function photoRouteName(): string
    {
        return 'resident.org-chart.photo';
    }
}
