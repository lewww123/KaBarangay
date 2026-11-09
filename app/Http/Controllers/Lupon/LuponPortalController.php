<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\LuponDocument;
use App\Models\LuponEvidence;
use App\Support\LuponAccess;
use App\Support\LuponCaseWorkflow;
use App\Support\LuponDocuments;
use App\Support\LuponProceedings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LuponPortalController extends Controller
{
    public function index(Request $request)
    {
        LuponAccess::staff($request);
        $module = $request->route('module');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:60'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $barangay = $request->user()->barangay_id;
        $query = LuponCase::where('barangay_id', $barangay)->with(['parties', 'complaint']);
        if ($module === 'pangkat') {
            $query->whereNotNull('pangkat_formed_at');
        } elseif ($module === 'arbitration') {
            $query->whereNotNull('arbitration_record');
        } elseif ($module === 'records') {
            $query->whereIn('status', ['settled', 'repudiated', 'dismissed', 'certified_to_file_action', 'arbitration_awarded', 'executed', 'court_forwarded']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('case_number', 'like', $term)->orWhere('title', 'like', $term)->orWhereHas('complaint', fn ($c) => $c->where('reference', 'like', $term))->orWhereHas('parties', fn ($p) => $p->where('name', 'like', $term)));
        }
        $query->when($module !== 'documents' ? ($filters['status'] ?? null) : null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('filed_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('filed_date', '<=', $date));
        return Inertia::render('Lupon/Registry', [
            'module' => $module, 'filters' => $filters, 'statuses' => LuponCaseWorkflow::LABELS,
            'records' => $this->deferProp(function () use ($query, $module, $filters, $barangay) {
                if ($module === 'documents') {
                    return LuponDocument::whereHas('case', fn ($q) => $q->whereIn('id', (clone $query)->select('lupon_cases.id')))
                        ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                        ->with('case:id,case_number,title')->latest('id')->paginate(15)->withQueryString()
                        ->through(fn ($doc) => ['id' => $doc->id, 'caseId' => $doc->case_id, 'caseNumber' => $doc->case->case_number, 'title' => LuponDocuments::KINDS[$doc->kind] ?? $doc->kind, 'status' => $doc->served_at ? 'Served' : ($doc->issued_at ? 'Issued' : $doc->status), 'date' => $doc->generated_at?->toDateString(), 'details' => 'Prepared, issued and served are separate records.']);
                }
                return $query->latest('filed_date')->paginate(15)->withQueryString()->through(fn ($case) => [
                    'id' => $case->id, 'caseId' => $case->id, 'caseNumber' => $case->case_number, 'title' => $case->title,
                    'status' => $case->status, 'date' => $case->filed_date->toDateString(),
                    'details' => $module === 'pangkat' ? implode(', ', $case->pangkat_members ?? []).' · Chair: '.$case->pangkat_chair_name : ($case->complaint?->reference ?? 'Walk-in intake'),
                ]);
            }),
        ]);
    }

    public function reports(Request $request)
    {
        LuponAccess::staff($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', 'string', 'max:60']]);
        $filter = fn ($q) => $q->where('barangay_id', $request->user()->barangay_id)
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
        $complaints = $filter(LuponComplaint::query());
        $cases = $filter(LuponCase::query())->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        return Inertia::render('Lupon/Reports', ['filters' => $data, 'statuses' => LuponCaseWorkflow::LABELS,
            'report' => $this->deferProp(fn () => [
                'complaints' => (clone $complaints)->count(),
                'complaintsByStatus' => (clone $complaints)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'cases' => (clone $cases)->count(),
                'casesByStage' => (clone $cases)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'months' => (clone $cases)->get(['filed_date'])->groupBy(fn ($c) => $c->filed_date->format('Y-m'))->map->count(),
                'paymentVerified' => (clone $complaints)->whereNotNull('payment_verification')->count(),
            ]),
        ]);
    }

    public function profile(Request $request)
    {
        LuponAccess::staff($request);
        $user = $request->user();
        return Inertia::render('Lupon/Profile', ['profile' => ['name' => $user->name, 'email' => $user->email, 'barangay' => $user->barangay?->name, 'assignments' => $user->staffAssignments()->where('is_active', true)->where('barangay_id', $user->barangay_id)->get(['department', 'position']), 'canVerifyPayments' => LuponAccess::canVerify($request)]]);
    }

    public function proceeding(Request $request, LuponCase $case)
    {
        LuponAccess::staff($request, $case);
        $data = $request->validate([
            'action' => ['required', Rule::in(['arbitration_agreement', 'arbitration_session', 'arbitration_award', 'execution', 'court_forwarding'])],
            'event_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.LuponCaseWorkflow::today()],
            'notes' => ['required', 'string', 'max:5000'], 'parties_agreed' => ['nullable', 'boolean'],
            'attendance' => ['nullable', 'string', 'max:2000'], 'evidence_id' => ['nullable', 'integer'],
        ]);
        LuponProceedings::record($case, $request->user()->id, $data);
        return back()->with('success', 'Actual proceeding recorded in the case history.');
    }
}
