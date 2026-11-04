<?php

namespace App\Http\Controllers;

use App\Models\SectorApplication;
use App\Models\SectorApplicationDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SectorApplicationDocumentController extends Controller
{
    public function show(Request $request, SectorApplication $application, SectorApplicationDocument $document): StreamedResponse
    {
        $this->authorizeDocumentAccess($request, $application, $document);

        return $this->streamFile($document, false);
    }

    public function download(Request $request, SectorApplication $application, SectorApplicationDocument $document): StreamedResponse
    {
        $this->authorizeDocumentAccess($request, $application, $document);

        return $this->streamFile($document, true);
    }

    private function authorizeDocumentAccess(Request $request, SectorApplication $application, SectorApplicationDocument $document): void
    {
        if ($document->sector_application_id !== $application->id) {
            abort(404, 'Document does not belong to this application.');
        }

        $user = $request->user();
        $residentId = session('resident_id');

        if ($user) {
            if ($user->barangay_id !== $application->barangay_id) {
                abort(403, 'Cross-barangay document access is denied.');
            }

            if ($user->hasActiveAssignment(['admin'])) {
                return;
            }

            $sectorSlug = $application->sector->slug;
            if ($sectorSlug === 'senior' && $user->hasActiveAssignment(['senior'])) {
                return;
            }

            if ($sectorSlug === 'pwd' && $user->hasActiveAssignment(['pwd'])) {
                return;
            }

            abort(403, 'You are not authorized to view documents for this sector.');
        }

        if ($residentId) {
            if ($application->resident_id === (int) $residentId) {
                return;
            }
            abort(403, 'You are not authorized to view documents belonging to another resident.');
        }

        abort(401, 'Unauthenticated document access.');
    }

    private function streamFile(SectorApplicationDocument $document, bool $asDownload): StreamedResponse
    {
        if (! Storage::disk($document->disk)->exists($document->path)) {
            abort(404, 'File not found on storage.');
        }

        return Storage::disk($document->disk)->response(
            $document->path,
            $asDownload ? $document->original_name : null,
            [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => ($asDownload ? 'attachment' : 'inline').'; filename="'.addslashes($document->original_name).'"',
            ]
        );
    }
}
