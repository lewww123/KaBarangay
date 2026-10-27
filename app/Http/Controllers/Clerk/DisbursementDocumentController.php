<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Concerns\PresentsDisbursementDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clerk\StoreDisbursementDocumentRequest;
use App\Models\DisbursementDocument;
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

class DisbursementDocumentController extends Controller
{
    use PresentsDisbursementDocuments;

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

        $baseQuery = DisbursementDocument::query()
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

        $documents = (clone $baseQuery)
            ->with('uploader:id,name')
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
                        ->orWhere('original_name', 'like', $like);
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(
                fn (DisbursementDocument $document) => $this->documentPayload(
                    $document,
                    true
                )
            );

        return Inertia::render('Clerk/Disbursements/Index', [
            'documents' => $documents,
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
        StoreDisbursementDocumentRequest $request
    ): RedirectResponse {
        $validated = $request->validated();
        $user = $request->user();
        $file = $request->file('document');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors([
                'document' => 'The document must be a valid uploaded file.',
            ]);
        }

        $extension = strtolower(
            $file->getClientOriginalExtension()
        );
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
                'disbursement-documents/'.$user->barangay_id,
                (string) Str::uuid().'.'.$extension,
                'local'
            );

            if (! is_string($path)) {
                throw new RuntimeException(
                    'The disbursement document could not be stored.'
                );
            }

            $document = DisbursementDocument::create([
                'barangay_id' => $user->barangay_id,
                'uploaded_by' => $user->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $originalName !== ''
                    ? $originalName
                    : 'document.'.$extension,
                'mime_type' => $file->getMimeType()
                    ?: 'application/octet-stream',
                'file_size' => (int) $file->getSize(),
                'sha256' => is_string($sha256) ? $sha256 : null,
                'is_published' => false,
                'published_at' => null,
                'archived_at' => null,
            ]);
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
            'disbursement_document.uploaded',
            $document->barangay_id,
            $user->id,
            'disbursement_document',
            $document->id,
            [
                'new_status' => 'draft',
            ],
            $request->ip(),
        );

        return back()->with(
            'success',
            'Disbursement document uploaded as a draft.'
        );
    }

    public function show(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): Response {
        $this->authorizeBarangay($request, $disbursementDocument);
        $disbursementDocument->load('uploader:id,name');

        return Inertia::render('Clerk/Disbursements/Show', [
            'document' => $this->documentPayload(
                $disbursementDocument,
                true
            ),
            'csvPreview' => $this->csvPreview($disbursementDocument),
            'user' => [
                'name' => $request->user()->name,
                'staff_id' => $request->user()->staff_id,
                'department' => $request->user()->department ?: 'Clerk',
            ],
        ]);
    }

    public function publish(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): RedirectResponse {
        $this->authorizeBarangay($request, $disbursementDocument);

        $published = false;

        DB::transaction(function () use (
            $request,
            $disbursementDocument,
            &$published
        ) {
            $document = DisbursementDocument::query()
                ->where('barangay_id', $request->user()->barangay_id)
                ->whereKey($disbursementDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->archived_at !== null) {
                throw ValidationException::withMessages([
                    'document' => 'Archived documents cannot be published.',
                ]);
            }

            if ($document->is_published) {
                return;
            }

            $document->update([
                'is_published' => true,
                'published_at' => now(),
            ]);

            $published = true;
        });

        if ($published) {
            AuditLogger::record(
                'disbursement_document.published',
                $disbursementDocument->barangay_id,
                $request->user()->id,
                'disbursement_document',
                $disbursementDocument->id,
                [
                    'previous_status' => 'draft',
                    'new_status' => 'published',
                ],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            $published
                ? 'Disbursement document published.'
                : 'The document is already published.'
        );
    }

    public function archive(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): RedirectResponse {
        $this->authorizeBarangay($request, $disbursementDocument);

        $previousStatus = $disbursementDocument->status();
        $archived = false;

        DB::transaction(function () use (
            $request,
            $disbursementDocument,
            &$archived
        ) {
            $document = DisbursementDocument::query()
                ->where('barangay_id', $request->user()->barangay_id)
                ->whereKey($disbursementDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->archived_at !== null) {
                return;
            }

            $document->update([
                'is_published' => false,
                'archived_at' => now(),
            ]);

            $archived = true;
        });

        if ($archived) {
            AuditLogger::record(
                'disbursement_document.archived',
                $disbursementDocument->barangay_id,
                $request->user()->id,
                'disbursement_document',
                $disbursementDocument->id,
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
                ? 'Disbursement document archived.'
                : 'The document is already archived.'
        );
    }

    public function file(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): StreamedResponse {
        $this->authorizeBarangay($request, $disbursementDocument);

        return $this->documentFileResponse(
            $disbursementDocument,
            false
        );
    }

    public function download(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): StreamedResponse {
        $this->authorizeBarangay($request, $disbursementDocument);

        return $this->documentFileResponse(
            $disbursementDocument,
            true
        );
    }

    private function authorizeBarangay(
        Request $request,
        DisbursementDocument $document
    ): void {
        abort_unless(
            (int) $document->barangay_id ===
                (int) $request->user()->barangay_id,
            404
        );
    }
}
