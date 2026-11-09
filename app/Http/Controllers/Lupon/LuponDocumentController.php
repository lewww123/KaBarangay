<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponDocument;
use App\Support\AuditLogger;
use App\Support\CertificateOfficials;
use App\Support\LuponAccess;
use App\Support\LuponCache;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponDocuments;
use App\Support\ResidentContext;
use App\Support\ResidentNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LuponDocumentController extends Controller
{
    public function issue(Request $request, LuponDocument $document)
    {
        $case = $document->case()->firstOrFail();
        LuponAccess::staff($request, $case);
        $data = $request->validate(['issued_at' => ['required', 'date', 'before_or_equal:now'], 'content_reviewed' => ['required', 'accepted']]);
        DB::transaction(function () use ($document, $case, $data, $request) {
            $locked = LuponDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->issued_at) { return; }
            if ($locked->status !== 'ready' || ! $locked->path || ! Storage::disk('local')->exists($locked->path) || $case->payment_status === 'unpaid') {
                throw ValidationException::withMessages(['document' => 'Prepare and review the document and verify actual payment before issuance.']);
            }
            if (Carbon::parse($data['issued_at'])->lt($locked->generated_at)) {
                throw ValidationException::withMessages(['issued_at' => 'Issuance cannot precede preparation.']);
            }
            $locked->update(['issued_at' => $data['issued_at'], 'issued_by' => $request->user()->id]);
            LuponCaseWorkflow::event($case, $case->status, $case->status, $request->user()->id, $data['issued_at'], 'Document issuance recorded.', ['document_id' => $locked->id, 'action' => 'document_issued']);
            AuditLogger::record('lupon_document.issued', barangayId: $case->barangay_id, actorId: $request->user()->id, entityType: 'lupon_document', entityId: $locked->id, context: ['action' => 'issued', 'entity_reference' => $case->case_number]);
        });
        LuponCache::forget((int) $case->barangay_id);

        return back()->with('success', 'Actual issuance recorded. This does not record service or close the case.');
    }

    public function generate(Request $request, LuponCase $case)
    {
        LuponAccess::staff($request, $case);
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(LuponDocuments::KINDS))], 'hearing_id' => ['nullable', 'integer'], 'acting_captain_id' => ['nullable', 'integer', 'min:1']]);
        DB::transaction(function () use ($case, $request, $data) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($request->has('acting_captain_id')) {
                $id = filled($data['acting_captain_id'] ?? null) ? (int) $data['acting_captain_id'] : null;
                CertificateOfficials::resolve($locked->barangay_id, $id);
                if ($locked->acting_captain_id !== $id) {
                    $locked->update(['acting_captain_id' => $id]);
                    $locked->documents()->where('kind', $data['kind'])->when(! empty($data['hearing_id']), fn ($query) => $query->where('hearing_id', $data['hearing_id']))->where('status', 'ready')->update(['status' => 'superseded']);
                }
            }
            LuponDocuments::generate($locked, $data['kind'], $request->user()->id, $data['hearing_id'] ?? null);
        });

        return back()->with('success', 'Document prepared with the recorded officials. Review before release.');
    }

    public function download(Request $request, LuponDocument $document)
    {
        $case = $document->case()->firstOrFail();
        if ($request->routeIs('lupon.*')) {
            LuponAccess::staff($request, $case);
        } else {
            abort_unless(LuponAccess::resident(ResidentContext::get(), $case), 404);
            if (in_array($document->kind, ['summons', 'notice_of_hearing'], true)) {
                abort_unless($document->served_at !== null, 404);
            }
        }
        abort_unless($document->status === 'ready' && $document->path && Storage::disk('local')->exists($document->path), 404);
        $headers = ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $request->routeIs('lupon.documents.preview') ? response()->file(Storage::disk('local')->path($document->path), $headers) : Storage::disk('local')->download($document->path, $document->file_name, $headers);
    }

    public function serve(Request $request, LuponDocument $document, ResidentNotificationService $notifications)
    {
        $case = $document->case()->firstOrFail();
        LuponAccess::staff($request, $case);
        $data = $request->validate(['served_at' => ['required', 'date', 'before_or_equal:now'], 'service_method' => ['required', 'in:personal,other'], 'service_note' => ['required', 'string', 'max:2000'], 'receipt_confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($document, $case, $data, $request, $notifications) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $document = LuponDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== 'ready' || ! $document->issued_at || ! in_array($document->kind, ['notice_of_hearing', 'summons'], true) || ! Storage::disk('local')->exists($document->path)) {
                throw ValidationException::withMessages(['document' => 'Prepare the notice before recording service.']);
            }
            if ($locked->payment_status === 'unpaid') {
                throw ValidationException::withMessages(['document' => 'Verify the filing fee or a waiver before releasing the notice.']);
            }
            if ($document->served_at) {
                return false;
            }
            if (Carbon::parse($data['served_at'])->lt($document->issued_at)) {
                throw ValidationException::withMessages(['served_at' => 'Service cannot precede document preparation.']);
            }
            $document->update(['served_at' => $data['served_at'], 'service_method' => $data['service_method'], 'service_note' => $data['service_note'], 'served_by' => $request->user()->id]);
            if ($document->kind === 'summons' && ! $locked->form_7_issued_at) {
                $locked->update(['form_7_issued_at' => $data['served_at']]);
            }
            LuponCaseWorkflow::event($locked, $locked->status, $locked->status, $request->user()->id, $data['served_at'], 'Service of '.$document->kind.' recorded.', ['document_id' => $document->id, 'service_method' => $data['service_method']]);
            if ($document->kind === 'summons') {
                foreach ($locked->respondents()->where('association_status', 'verified')->whereNotNull('resident_id')->with('resident')->get() as $party) {
                    if ($party->resident) {
                        $notification = $notifications->enqueueLuponSummons($party->resident, $locked);
                        DB::afterCommit(fn () => $notifications->deliver($notification));
                    }
                }
            }
        });

        return back()->with('success', 'Notice service recorded. Resident tracking has been updated.');
    }

    public function verifyFee(Request $request, LuponCase $case)
    {
        LuponAccess::secretary($request, $case);

        // Normalize OR number before validation: trim + uppercase.
        if (is_string($request->input('or_number'))) {
            $request->merge(['or_number' => strtoupper(trim($request->input('or_number')))]);
        }

        $rules = [
            'payment_status' => ['required', 'in:paid,waived'],
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'or_number' => ['required_if:payment_status,paid', 'nullable', 'string', 'max:100'],
        ];

        if ($request->input('payment_status') === 'paid') {
            $rules['or_number'][] = Rule::unique('lupon_cases', 'or_number')
                ->where('barangay_id', $case->barangay_id)
                ->ignore($case->id);
        }

        $data = $request->validate($rules);

        // Waived fees must not carry an OR number.
        $orInput = $request->input('or_number');
        if ($data['payment_status'] === 'waived') {
            if ($orInput !== null && $orInput !== '') {
                throw ValidationException::withMessages(['or_number' => 'A waived fee must not have an OR number.']);
            }
            $data['or_number'] = null;
        }

        // Paid fees require a non-empty OR after normalization.
        if ($data['payment_status'] === 'paid') {
            $paidOr = $data['or_number'] ?? null;
            if ($paidOr === null || $paidOr === '') {
                throw ValidationException::withMessages(['or_number' => 'An official receipt number is required for paid fees.']);
            }
        }

        DB::transaction(function () use ($case, $data, $request) {
            $locked = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== 'unpaid') {
                throw ValidationException::withMessages(['fee_amount' => 'The fee has already been verified.']);
            }

            // For paid cases, lock the barangay and check for duplicate OR numbers.
            if ($data['payment_status'] === 'paid') {
                Barangay::whereKey($locked->barangay_id)->lockForUpdate()->firstOrFail();
                if (LuponCase::where('barangay_id', $locked->barangay_id)->where('or_number', $data['or_number'])->where('id', '!=', $locked->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['or_number' => 'This OR number is already recorded in your barangay.']);
                }
            }

            $locked->update([...$data, 'paid_at' => now()]);
            AuditLogger::record('lupon_case.fee_verified', barangayId: $locked->barangay_id, actorId: $request->user()->id, entityType: 'lupon_case', entityId: $locked->id, context: ['payment_status' => $data['payment_status'], 'case_number' => $locked->case_number]);
        });
        LuponCache::forget((int) $case->barangay_id);

        return back()->with('success', 'Fee or waiver verified. Prepared notices may now be released and their service recorded.');
    }

    public function history(Request $request, LuponCase $case)
    {
        LuponAccess::staff($request, $case);
        $data = $request->validate(['mediation_started_at' => ['nullable', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()], 'pangkat_formed_at' => ['nullable', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()], 'conciliation_started_at' => ['nullable', 'date', 'before_or_equal:'.LuponCaseWorkflow::today()],
            'pangkat_members' => ['nullable', 'array', 'size:3'], 'pangkat_members.*' => ['string', 'max:255', 'distinct'], 'pangkat_chair_name' => ['nullable', 'string', 'max:255'], 'pangkat_secretary_name' => ['nullable', 'string', 'max:255'], 'notes' => ['required', 'string', 'max:3000']]);
        LuponCaseWorkflow::completeHistory($case, $request->user()->id, $data);

        return back()->with('success', 'Missing historical dates verified and recorded.');
    }

    public function extend(Request $request, LuponCase $case)
    {
        LuponAccess::staff($request, $case);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:15'], 'notes' => ['required', 'string', 'max:2000']]);
        LuponCaseWorkflow::extend($case, $request->user()->id, $data['days'], $data['notes']);

        return back()->with('success', 'Pangkat-approved conciliation extension recorded.');
    }
}
