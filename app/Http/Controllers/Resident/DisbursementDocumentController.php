<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Concerns\PresentsDisbursementDocuments;
use App\Http\Controllers\Controller;
use App\Models\DisbursementDocument;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisbursementDocumentController extends Controller
{
    use PresentsDisbursementDocuments;

    public function index(Request $request): Response
    {
        $resident = $this->resident($request);

        $documents = DisbursementDocument::query()
            ->where('barangay_id', $resident->barangay_id)
            ->visibleToResidents()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString()
            ->through(
                fn (DisbursementDocument $document) => $this->documentPayload(
                    $document,
                    false
                )
            );

        return Inertia::render('Resident/Disbursement', [
            'documents' => $documents,
            'resident' => [
                'full_name' => $resident->full_name,
            ],
        ]);
    }

    public function show(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): Response {
        $resident = $this->resident($request);
        $this->authorizeVisible($resident, $disbursementDocument);

        return Inertia::render('Resident/DisbursementShow', [
            'document' => $this->documentPayload(
                $disbursementDocument,
                false
            ),
            'csvPreview' => $this->csvPreview($disbursementDocument),
        ]);
    }

    public function file(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): StreamedResponse {
        $resident = $this->resident($request);
        $this->authorizeVisible($resident, $disbursementDocument);

        return $this->documentFileResponse(
            $disbursementDocument,
            false
        );
    }

    public function download(
        Request $request,
        DisbursementDocument $disbursementDocument
    ): StreamedResponse {
        $resident = $this->resident($request);
        $this->authorizeVisible($resident, $disbursementDocument);

        return $this->documentFileResponse(
            $disbursementDocument,
            true
        );
    }

    private function resident(Request $request): Resident
    {
        return Resident::query()
            ->whereKey($request->session()->get('resident_id'))
            ->where('status', 'active')
            ->firstOrFail();
    }

    private function authorizeVisible(
        Resident $resident,
        DisbursementDocument $document
    ): void {
        abort_unless(
            (int) $document->barangay_id ===
                (int) $resident->barangay_id
                && $document->is_published
                && $document->archived_at === null,
            404
        );
    }
}
