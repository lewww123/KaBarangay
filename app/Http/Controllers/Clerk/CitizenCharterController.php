<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Concerns\PresentsCitizenCharterDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clerk\StoreCitizenCharterServiceRequest;
use App\Http\Requests\Clerk\StoreCitizenCharterVersionRequest;
use App\Http\Requests\Clerk\UpdateCitizenCharterServiceRequest;
use App\Models\CitizenCharterService;
use App\Models\CitizenCharterVersion;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CitizenCharterController extends Controller
{
    use PresentsCitizenCharterDocuments;

    private const STATUS_FILTERS = [
        'all',
        'draft',
        'published',
        'archived',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = (string) $request->query('status', 'all');

        if (! in_array($status, self::STATUS_FILTERS, true)) {
            $status = 'all';
        }

        $search = mb_substr(
            trim((string) $request->query('search', '')),
            0,
            100,
            'UTF-8'
        );

        $baseQuery = CitizenCharterService::query()
            ->where('barangay_id', $user->barangay_id);

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'draft' => (clone $baseQuery)
                ->where('is_published', false)
                ->whereNull('archived_at')
                ->count(),
            'published' => (clone $baseQuery)
                ->where('is_published', true)
                ->whereNull('archived_at')
                ->count(),
            'archived' => (clone $baseQuery)
                ->whereNotNull('archived_at')
                ->count(),
        ];

        $services = (clone $baseQuery)
            ->with(['currentVersion', 'creator:id,name'])
            ->when($status === 'draft', function ($query) {
                $query->where('is_published', false)
                    ->whereNull('archived_at');
            })
            ->when($status === 'published', function ($query) {
                $query->where('is_published', true)
                    ->whereNull('archived_at');
            })
            ->when($status === 'archived', function ($query) {
                $query->whereNotNull('archived_at');
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
            ->paginate(15)
            ->withQueryString()
            ->through(
                fn (CitizenCharterService $service) => $this->servicePayload(
                    $service,
                    true
                )
            );

        return Inertia::render('Clerk/CitizenCharter/Index', [
            'services' => $services,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'stats' => $stats,
            'user' => [
                'name' => $user->name,
                'staff_id' => $user->staff_id,
                'department' => $user->department ?: 'Clerk',
            ],
        ]);
    }

    public function store(
        StoreCitizenCharterServiceRequest $request
    ): RedirectResponse {
        $validated = $request->validated();
        $user = $request->user();
        $file = $request->file('document');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors([
                'document' => 'The document must be a valid uploaded file.',
            ]);
        }

        $originalName = mb_substr(
            basename($file->getClientOriginalName()),
            0,
            255,
            'UTF-8'
        );
        $path = null;

        try {
            $sha256 = hash_file('sha256', $file->getRealPath());
            $path = $file->storeAs(
                'citizen-charter/'.$user->barangay_id,
                (string) Str::uuid().'.pdf',
                'local'
            );

            if (! is_string($path)) {
                throw new RuntimeException(
                    'The Citizen\'s Charter document could not be stored.'
                );
            }

            $service = DB::transaction(function () use (
                $validated,
                $user,
                $path,
                $originalName,
                $file,
                $sha256
            ) {
                $service = CitizenCharterService::create([
                    'barangay_id' => $user->barangay_id,
                    'title' => $validated['title'],
                    'category' => $validated['category'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'display_order' => (int) ($validated['display_order'] ?? 0),
                    'status' => 'draft',
                    'is_published' => false,
                    'published_at' => null,
                    'archived_at' => null,
                    'created_by' => $user->id,
                ]);

                $version = CitizenCharterVersion::create([
                    'service_id' => $service->id,
                    'barangay_id' => $user->barangay_id,
                    'version_number' => 1,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $originalName !== '' ? $originalName : 'service.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => (int) $file->getSize(),
                    'sha256' => is_string($sha256) ? $sha256 : null,
                    'uploaded_by' => $user->id,
                    'status' => 'draft',
                ]);

                // Set version 1 as draft pointer
                $service->update(['current_version_id' => $version->id]);

                return $service;
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }

            report($exception);

            return back()
                ->withInput($request->except('document'))
                ->withErrors([
                    'document' => 'The document could not be uploaded. Please try again.',
                ]);
        }

        AuditLogger::record(
            'citizen_charter.service_created',
            $service->barangay_id,
            $user->id,
            'citizen_charter_service',
            $service->id,
            [
                'action' => 'created',
                'new_status' => 'draft',
            ],
            $request->ip(),
        );

        return redirect()
            ->route('clerk.citizen-charter.show', $service)
            ->with('success', 'Citizen\'s Charter service created as a draft.');
    }

    public function show(
        Request $request,
        CitizenCharterService $service
    ): Response {
        $this->authorizeBarangay($request, $service);

        $service->load([
            'currentVersion',
            'creator:id,name',
            'updater:id,name',
            'versions' => fn ($query) => $query->with('uploader:id,name')->orderByDesc('version_number'),
        ]);

        return Inertia::render('Clerk/CitizenCharter/Show', [
            'service' => $this->servicePayload($service, true),
            'user' => [
                'name' => $request->user()->name,
                'staff_id' => $request->user()->staff_id,
                'department' => $request->user()->department ?: 'Clerk',
            ],
        ]);
    }

    public function update(
        UpdateCitizenCharterServiceRequest $request,
        CitizenCharterService $service
    ): RedirectResponse {
        $this->authorizeBarangay($request, $service);

        if ($service->archived_at !== null) {
            throw ValidationException::withMessages([
                'service' => 'Archived services cannot be edited.',
            ]);
        }

        $validated = $request->validated();

        $service->update([
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'display_order' => (int) ($validated['display_order'] ?? 0),
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::record(
            'citizen_charter.service_updated',
            $service->barangay_id,
            $request->user()->id,
            'citizen_charter_service',
            $service->id,
            [
                'action' => 'updated',
            ],
            $request->ip(),
        );

        return back()->with('success', 'Service details updated.');
    }

    public function storeVersion(
        StoreCitizenCharterVersionRequest $request,
        CitizenCharterService $service
    ): RedirectResponse {
        $this->authorizeBarangay($request, $service);

        if ($service->archived_at !== null) {
            throw ValidationException::withMessages([
                'document' => 'Cannot add document revisions to an archived service.',
            ]);
        }

        $user = $request->user();
        $file = $request->file('document');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors([
                'document' => 'The document must be a valid uploaded file.',
            ]);
        }

        $originalName = mb_substr(
            basename($file->getClientOriginalName()),
            0,
            255,
            'UTF-8'
        );
        $path = null;

        try {
            $sha256 = hash_file('sha256', $file->getRealPath());
            $path = $file->storeAs(
                'citizen-charter/'.$user->barangay_id,
                (string) Str::uuid().'.pdf',
                'local'
            );

            if (! is_string($path)) {
                throw new RuntimeException(
                    'The Citizen\'s Charter document could not be stored.'
                );
            }

            $version = DB::transaction(function () use (
                $service,
                $user,
                $path,
                $originalName,
                $file,
                $sha256
            ) {
                $maxVersion = (int) $service->versions()->max('version_number');
                $nextVersion = $maxVersion + 1;

                return CitizenCharterVersion::create([
                    'service_id' => $service->id,
                    'barangay_id' => $user->barangay_id,
                    'version_number' => $nextVersion,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $originalName !== '' ? $originalName : 'service.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => (int) $file->getSize(),
                    'sha256' => is_string($sha256) ? $sha256 : null,
                    'uploaded_by' => $user->id,
                    'status' => 'draft',
                ]);
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }

            report($exception);

            return back()
                ->withErrors([
                    'document' => 'The document revision could not be uploaded. Please try again.',
                ]);
        }

        AuditLogger::record(
            'citizen_charter.version_uploaded',
            $service->barangay_id,
            $user->id,
            'citizen_charter_service',
            $service->id,
            [
                'action' => 'version_uploaded',
                'entity_reference' => 'v'.$version->version_number,
            ],
            $request->ip(),
        );

        return back()->with(
            'success',
            'New document version (v'.$version->version_number.') uploaded as a draft. You may preview and publish it when ready.'
        );
    }

    public function publish(
        Request $request,
        CitizenCharterService $service
    ): RedirectResponse {
        $this->authorizeBarangay($request, $service);

        if ($service->archived_at !== null) {
            throw ValidationException::withMessages([
                'service' => 'Archived services cannot be published.',
            ]);
        }

        $user = $request->user();
        $previousStatus = $service->computeStatus();
        $publishedVersionNumber = 1;

        DB::transaction(function () use (
            $service,
            &$publishedVersionNumber
        ) {
            $lockedService = CitizenCharterService::query()
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedService->archived_at !== null) {
                throw ValidationException::withMessages([
                    'service' => 'Archived services cannot be published.',
                ]);
            }

            // Find the latest draft version, or fallback to the current version
            $targetVersion = $lockedService->versions()
                ->where('status', 'draft')
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if (! $targetVersion && $lockedService->current_version_id) {
                $targetVersion = CitizenCharterVersion::query()
                    ->whereKey($lockedService->current_version_id)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $targetVersion) {
                throw ValidationException::withMessages([
                    'service' => 'Cannot publish a service without an uploaded document.',
                ]);
            }

            // If there was a previous current version that is not the target, mark it superseded
            if (
                $lockedService->current_version_id &&
                (int) $lockedService->current_version_id !== (int) $targetVersion->id
            ) {
                CitizenCharterVersion::query()
                    ->whereKey($lockedService->current_version_id)
                    ->update([
                        'status' => 'superseded',
                    ]);
            }

            $targetVersion->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            $lockedService->update([
                'current_version_id' => $targetVersion->id,
                'status' => 'published',
                'is_published' => true,
                'published_at' => $lockedService->published_at ?? now(),
            ]);

            $publishedVersionNumber = $targetVersion->version_number;
        });

        AuditLogger::record(
            'citizen_charter.published',
            $service->barangay_id,
            $user->id,
            'citizen_charter_service',
            $service->id,
            [
                'previous_status' => $previousStatus,
                'new_status' => 'published',
                'entity_reference' => 'v'.$publishedVersionNumber,
            ],
            $request->ip(),
        );

        return back()->with(
            'success',
            'Citizen\'s Charter service published (Version '.$publishedVersionNumber.' is now live for residents).'
        );
    }

    public function archive(
        Request $request,
        CitizenCharterService $service
    ): RedirectResponse {
        $this->authorizeBarangay($request, $service);

        $previousStatus = $service->computeStatus();
        $archived = false;

        DB::transaction(function () use (
            $request,
            $service,
            &$archived
        ) {
            $lockedService = CitizenCharterService::query()
                ->where('barangay_id', $request->user()->barangay_id)
                ->whereKey($service->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedService->archived_at !== null) {
                return;
            }

            $now = now();

            $lockedService->update([
                'status' => 'archived',
                'is_published' => false,
                'archived_at' => $now,
            ]);

            if ($lockedService->current_version_id) {
                CitizenCharterVersion::query()
                    ->whereKey($lockedService->current_version_id)
                    ->update([
                        'status' => 'archived',
                        'archived_at' => $now,
                    ]);
            }

            $archived = true;
        });

        if ($archived) {
            AuditLogger::record(
                'citizen_charter.archived',
                $service->barangay_id,
                $request->user()->id,
                'citizen_charter_service',
                $service->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => 'archived',
                ],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            $archived
                ? 'Citizen\'s Charter service archived and removed from resident visibility.'
                : 'The service is already archived.'
        );
    }

    public function file(
        Request $request,
        CitizenCharterService $service,
        CitizenCharterVersion $version
    ): StreamedResponse {
        $this->authorizeBarangay($request, $service);
        abort_unless((int) $version->service_id === (int) $service->id, 404);

        return $this->documentFileResponse($version, false);
    }

    public function download(
        Request $request,
        CitizenCharterService $service,
        CitizenCharterVersion $version
    ): StreamedResponse {
        $this->authorizeBarangay($request, $service);
        abort_unless((int) $version->service_id === (int) $service->id, 404);

        return $this->documentFileResponse($version, true);
    }

    private function authorizeBarangay(
        Request $request,
        CitizenCharterService $service
    ): void {
        abort_unless(
            (int) $service->barangay_id === (int) $request->user()->barangay_id,
            404
        );
    }
}
