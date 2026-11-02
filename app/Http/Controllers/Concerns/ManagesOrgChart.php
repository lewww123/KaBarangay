<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Clerk\StoreOrgChartPositionRequest;
use App\Http\Requests\Clerk\UpdateOrgChartPositionRequest;
use App\Models\OrgChartPosition;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait ManagesOrgChart
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $positions = null;
        $resolvePositions = function () use ($user, &$positions) {
            return $positions ??= OrgChartPosition::query()
                ->where('barangay_id', $user->barangay_id)
                ->orderBy('title')
                ->get();
        };

        return Inertia::render('OrgChart/Management', [
            'tree' => $this->deferProp(fn () => $this->chartTree($resolvePositions(), $this->photoRouteName())),
            'positions' => $this->deferProp(fn () => $this->chartFlat($resolvePositions(), $this->photoRouteName())),
            'stats' => $this->deferProp(function () use ($resolvePositions): array {
                $positions = $resolvePositions();

                return [
                    'total' => $positions->count(),
                    'draft' => $positions->where('status', 'draft')->count(),
                    'published' => $positions->where('status', 'published')->count(),
                    'archived' => $positions->where('status', 'archived')->count(),
                ];
            }),
            'portal' => $this->portal(),
            'user' => [
                'name' => $user->name,
                'department' => ucfirst($this->portal()),
            ],
        ]);
    }

    public function store(StoreOrgChartPositionRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $data['barangay_id'] = $user->barangay_id;
        $data['created_by'] = $user->id;
        $data['status'] = 'draft';

        if (array_key_exists('assigned_person', $data)) {
            $data['assigned_person'] = filled($data['assigned_person']) ? trim((string) $data['assigned_person']) : null;
        }

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store(
                'org-chart/'.$data['barangay_id'],
                'local'
            );
        }

        unset($data['photo']);

        $position = OrgChartPosition::create($data);

        AuditLogger::record(
            'org_chart_position_created',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'org_chart_position',
            entityId: $position->id,
            context: ['new_status' => $position->status],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Position saved as a draft.');
    }

    public function update(
        UpdateOrgChartPositionRequest $request,
        OrgChartPosition $position
    ): RedirectResponse {
        $this->authorizeBarangay($request, $position);

        $user = $request->user();
        $data = $request->validated();
        $previousStatus = $position->status;

        if (array_key_exists('parent_id', $data)
            && $data['parent_id'] !== null
            && $this->wouldCreateCycle($position, (int) $data['parent_id'])) {
            throw ValidationException::withMessages([
                'parent_id' => 'A position cannot be placed under itself or one of its descendants.',
            ]);
        }

        $data['updated_by'] = $user->id;
        $data['status'] = 'draft';

        if (array_key_exists('assigned_person', $data)) {
            $data['assigned_person'] = filled($data['assigned_person']) ? trim((string) $data['assigned_person']) : null;
        }

        if ($request->hasFile('photo')) {
            $this->deletePhoto($position);

            $data['photo_path'] = $request->file('photo')->store(
                'org-chart/'.$position->barangay_id,
                'local'
            );
        }

        unset($data['photo']);

        $position->update($data);

        AuditLogger::record(
            'org_chart_position_updated',
            barangayId: $position->barangay_id,
            actorId: $user->id,
            entityType: 'org_chart_position',
            entityId: $position->id,
            context: [
                'previous_status' => $previousStatus,
                'new_status' => $position->status,
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Position updated and returned to draft for review.');
    }

    public function publish(
        Request $request,
        OrgChartPosition $position
    ): RedirectResponse {
        $this->authorizeBarangay($request, $position);

        $previousStatus = $position->status;

        $position->update([
            'status' => 'published',
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::record(
            'org_chart_position_published',
            barangayId: $position->barangay_id,
            actorId: $request->user()->id,
            entityType: 'org_chart_position',
            entityId: $position->id,
            context: [
                'previous_status' => $previousStatus,
                'new_status' => 'published',
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Position published to the resident chart.');
    }

    public function archive(
        Request $request,
        OrgChartPosition $position
    ): RedirectResponse {
        $this->authorizeBarangay($request, $position);

        $previousStatus = $position->status;

        $position->update([
            'status' => 'archived',
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::record(
            'org_chart_position_archived',
            barangayId: $position->barangay_id,
            actorId: $request->user()->id,
            entityType: 'org_chart_position',
            entityId: $position->id,
            context: [
                'previous_status' => $previousStatus,
                'new_status' => 'archived',
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Position archived and hidden from residents.');
    }

    public function destroy(
        Request $request,
        OrgChartPosition $position
    ): RedirectResponse {
        $this->authorizeBarangay($request, $position);

        if ($position->children()->exists()) {
            return back()->with(
                'error',
                'This position cannot be deleted while it has child positions.'
            );
        }

        $this->deletePhoto($position);

        $user = $request->user();
        $positionId = $position->id;
        $barangayId = $position->barangay_id;

        $position->delete();

        AuditLogger::record(
            'org_chart_position_deleted',
            barangayId: $barangayId,
            actorId: $user->id,
            entityType: 'org_chart_position',
            entityId: $positionId,
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Position deleted.');
    }

    public function photo(
        Request $request,
        OrgChartPosition $position
    ): BinaryFileResponse {
        $this->authorizeBarangay($request, $position);

        return $this->photoResponse($position);
    }

    protected function authorizeBarangay(
        Request $request,
        OrgChartPosition $position
    ): void {
        abort_unless(
            (int) $position->barangay_id === (int) $request->user()->barangay_id,
            403,
            'Unauthorized barangay access.'
        );
    }

    protected function photoResponse(OrgChartPosition $position): BinaryFileResponse
    {
        abort_unless(
            $position->photo_path !== null
                && Storage::disk('local')->exists($position->photo_path),
            404
        );

        return response()->file(
            Storage::disk('local')->path($position->photo_path)
        );
    }

    protected function deletePhoto(OrgChartPosition $position): void
    {
        if ($position->photo_path !== null
            && Storage::disk('local')->exists($position->photo_path)) {
            Storage::disk('local')->delete($position->photo_path);
        }
    }

    /**
     * @param  Collection<int, OrgChartPosition>  $positions
     * @return array<int, array<string, mixed>>
     */
    protected function chartTree(Collection $positions, string $photoRoute): array
    {
        $nodes = [];
        $children = [];

        foreach ($positions as $position) {
            $nodes[$position->id] = $this->positionPayload($position, $photoRoute);
            $nodes[$position->id]['children'] = [];
        }

        $roots = [];

        foreach ($positions as $position) {
            $parentId = $position->parent_id;

            if ($parentId !== null && isset($nodes[$parentId])) {
                $children[$parentId][] = $position->id;
            } else {
                $roots[] = $position->id;
            }
        }

        $build = function (int $id, array $stack) use (&$build, &$nodes, &$children): array {
            if (in_array($id, $stack, true)) {
                return [];
            }

            $stack[] = $id;
            $node = $nodes[$id];
            $node['children'] = array_map(
                fn (int $childId) => $build($childId, $stack),
                $children[$id] ?? []
            );

            return $node;
        };

        return array_map(
            fn (int $id) => $build($id, []),
            $roots
        );
    }

    /**
     * @param  Collection<int, OrgChartPosition>  $positions
     * @return array<int, array<string, mixed>>
     */
    protected function chartFlat(Collection $positions, string $photoRoute): array
    {
        return $positions
            ->map(fn (OrgChartPosition $position) => $this->positionPayload(
                $position,
                $photoRoute
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function positionPayload(
        OrgChartPosition $position,
        string $photoRoute
    ): array {
        return [
            'id' => $position->id,
            'title' => $position->title,
            'assigned_person' => $position->assigned_person,
            'parent_id' => $position->parent_id,
            'status' => $position->status,
            'has_photo' => $position->photo_path !== null,
            'photo_url' => $position->photo_path !== null
                ? route($photoRoute, ['position' => $position->id, 'v' => substr(hash('sha256', $position->photo_path), 0, 12)])
                : null,
            'updated_at' => $position->updated_at?->toISOString(),
        ];
    }

    protected function wouldCreateCycle(
        OrgChartPosition $position,
        int $parentId
    ): bool {
        if ((int) $position->id === $parentId) {
            return true;
        }

        $seen = [];
        $parents = OrgChartPosition::query()
            ->where('barangay_id', $position->barangay_id)
            ->pluck('parent_id', 'id')
            ->all();

        $currentId = $parentId;

        while (array_key_exists($currentId, $parents)) {
            if ($currentId === (int) $position->id) {
                return true;
            }

            if (isset($seen[$currentId])) {
                return true;
            }

            $seen[$currentId] = true;

            if ($parents[$currentId] === null) {
                break;
            }

            $currentId = (int) $parents[$currentId];
        }

        return false;
    }

    abstract protected function portal(): string;

    abstract protected function photoRouteName(): string;
}
