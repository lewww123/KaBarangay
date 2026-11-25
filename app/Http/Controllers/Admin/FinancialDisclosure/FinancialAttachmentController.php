<?php

namespace App\Http\Controllers\Admin\FinancialDisclosure;

use App\Http\Controllers\Controller;
use App\Models\FinancialAttachment;
use App\Support\FinancialDisclosureAudit;
use App\Support\FinancialDisclosureScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\ValidationException;

class FinancialAttachmentController extends Controller
{
    private const RECORD_TYPES = ['general', 'disclosure_period', 'allocation', 'collection', 'disbursement'];

    public function store(Request $request): RedirectResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());
        $actorId = FinancialDisclosureScope::actorId($request->user());

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'record_type' => ['required', Rule::in(self::RECORD_TYPES)],
            'record_id' => ['nullable', 'uuid'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $recordType = (string) $validated['record_type'];
        $recordId = $validated['record_id'] ?? null;
        $this->validateRecord($barangayId, $recordType, $recordId);

        $path = $request->file('file')->store('financial-disclosure/'.$barangayId, 'local');

        $attachment = FinancialAttachment::create([
            'record_type' => $recordType,
            'record_id' => $recordId,
            'title' => $validated['title'],
            'file_path' => $path,
            'uploaded_by' => $actorId,
            'uploaded_at' => now(),
        ]);

        FinancialDisclosureAudit::record(
            $request,
            $barangayId,
            $actorId,
            'attachment_uploaded',
            'attachment',
            $attachment->title,
            entityUuid: $attachment->id,
            newStatus: $recordType,
        );

        return back()->with('success', 'Attachment uploaded successfully.');
    }

    public function show(Request $request, string $attachment): StreamedResponse
    {
        return $this->fileResponse($request, $attachment, false);
    }

    public function download(Request $request, string $attachment): StreamedResponse
    {
        return $this->fileResponse($request, $attachment, true);
    }

    private function validateRecord(int $barangayId, string $recordType, ?string $recordId): void
    {
        if ($recordType === 'general') {
            if ($recordId !== null) {
                throw ValidationException::withMessages([
                    'record_id' => 'General attachments must not reference a record.',
                ]);
            }

            return;
        }

        if ($recordId === null) {
            throw ValidationException::withMessages([
                'record_id' => 'A linked record is required for this attachment type.',
            ]);
        }

        $exists = match ($recordType) {
            'disclosure_period' => FinancialDisclosureScope::disclosurePeriods($barangayId)->whereKey($recordId)->exists(),
            'allocation' => FinancialDisclosureScope::allocations($barangayId)->whereKey($recordId)->exists(),
            'collection' => FinancialDisclosureScope::collections($barangayId)->whereKey($recordId)->exists(),
            'disbursement' => FinancialDisclosureScope::disbursements($barangayId)->whereKey($recordId)->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'record_id' => 'The linked record was not found in this barangay.',
            ]);
        }
    }

    private function fileResponse(Request $request, string $id, bool $download): StreamedResponse
    {
        $barangayId = FinancialDisclosureScope::barangayId($request->user());

        $attachment = FinancialDisclosureScope::attachments($barangayId)
            ->whereKey($id)
            ->firstOrFail();

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        $extension = strtolower(pathinfo($attachment->file_path, PATHINFO_EXTENSION));
        $filename = Str::slug($attachment->title).'.'.$extension;
        $mime = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return $download
            ? Storage::disk('local')->download($attachment->file_path, $filename)
            : Storage::disk('local')->response($attachment->file_path, $filename, ['Content-Type' => $mime]);
    }
}
