<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Concerns\PresentsCitizenCharterDocuments;
use App\Http\Controllers\Controller;
use App\Models\CitizenCharterService;
use App\Models\Resident;
use App\Support\ResidentContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CitizenCharterController extends Controller
{
    use PresentsCitizenCharterDocuments;

    public function index(Request $request): Response
    {
        $resident = $this->resident($request);
        $search = mb_substr(
            trim((string) $request->query('search', '')),
            0,
            100,
            'UTF-8'
        );
        $category = trim((string) $request->query('category', ''));

        $baseQuery = CitizenCharterService::query()
            ->where('barangay_id', $resident->barangay_id)
            ->visibleToResidents();

        return Inertia::render('Resident/CitizenCharter', [
            'services' => $this->deferProp(fn () => (clone $baseQuery)
                ->with('currentVersion')
                ->when($category !== '', function ($query) use ($category) {
                    $query->where('category', $category);
                })
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%'.$search.'%';

                    $query->where(function ($query) use ($like) {
                        $query->where('title', 'like', $like)
                            ->orWhere('category', 'like', $like)
                            ->orWhere('description', 'like', $like);
                    });
                })
                ->orderBy('display_order')
                ->latest('id')
                ->paginate(12)
                ->withQueryString()
                ->through(
                    fn (CitizenCharterService $service) => $this->servicePayload(
                        $service,
                        false
                    )
                )),
            // Get available categories for filtering
            'categories' => $this->deferProp(fn () => $this->rememberReference(
                "resident:citizen-charter:categories:{$resident->barangay_id}",
                300,
                fn () => (clone $baseQuery)
                    ->whereNotNull('category')
                    ->where('category', '!=', '')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category')
                    ->values()
                    ->all(),
            )),
            'filters' => [
                'search' => $search,
                'category' => $category,
            ],
            'resident' => [
                'full_name' => $resident->full_name,
            ],
        ]);
    }

    public function show(
        Request $request,
        CitizenCharterService $service
    ): Response {
        $resident = $this->resident($request);
        $this->authorizeVisible($resident, $service);

        $service->load('currentVersion');

        return Inertia::render('Resident/CitizenCharterShow', [
            'service' => $this->servicePayload($service, false),
            'resident' => [
                'full_name' => $resident->full_name,
            ],
        ]);
    }

    public function file(
        Request $request,
        CitizenCharterService $service
    ): StreamedResponse {
        $resident = $this->resident($request);
        $this->authorizeVisible($resident, $service);

        $version = $service->currentVersion;
        abort_unless($version !== null, 404);

        return $this->documentFileResponse($version, false);
    }

    public function download(
        Request $request,
        CitizenCharterService $service
    ): StreamedResponse {
        $resident = $this->resident($request);
        $this->authorizeVisible($resident, $service);

        $version = $service->currentVersion;
        abort_unless($version !== null, 404);

        return $this->documentFileResponse($version, true);
    }

    private function resident(Request $request): Resident
    {
        return ResidentContext::get();
    }

    private function authorizeVisible(
        Resident $resident,
        CitizenCharterService $service
    ): void {
        abort_unless(
            (int) $service->barangay_id === (int) $resident->barangay_id
                && $service->is_published
                && $service->archived_at === null
                && $service->current_version_id !== null,
            404
        );
    }
}
