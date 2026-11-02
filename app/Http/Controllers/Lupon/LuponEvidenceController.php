<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\LuponEvidence;
use App\Support\AuditLogger;
use App\Support\LuponAccess;
use App\Support\LuponCatalog;
use App\Support\LuponEvidenceStore;
use App\Support\ResidentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LuponEvidenceController extends Controller
{
    public function download(Request $request, LuponEvidence $evidence)
    {
        if ($request->routeIs('lupon.*')) {
            LuponAccess::staff($request);
            abort_unless((int) $evidence->barangay_id === (int) $request->user()->barangay_id, 404);
        } else {
            $resident = ResidentContext::get();
            abort_unless((int) $evidence->barangay_id === (int) $resident->barangay_id, 404);
            $owner = $evidence->resident_id === $resident->id;
            $complaintOwner = $evidence->complaint_id && LuponComplaint::whereKey($evidence->complaint_id)->where('resident_id', $resident->id)->where('barangay_id', $resident->barangay_id)->exists();
            $shared = $evidence->visible_to_parties && $evidence->case_id && LuponAccess::resident($resident, LuponCase::findOrFail($evidence->case_id));
            abort_unless($owner || $complaintOwner || $shared, 404);
        }
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        return Storage::disk('local')->download($evidence->path, $evidence->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, LuponCase $case)
    {
        LuponAccess::staff($request, $case);
        $request->validate(['evidence' => ['required', 'array', 'min:1', 'max:5'], 'evidence.*' => LuponCatalog::FILE_RULES]);
        DB::transaction(fn () => LuponEvidenceStore::store($request->file('evidence', []), $case->complaint, $case, null, $request->user()->id));

        return back()->with('success', 'Supporting evidence saved privately.');
    }

    public function share(Request $request, LuponEvidence $evidence)
    {
        LuponAccess::staff($request);
        abort_unless((int) $evidence->barangay_id === (int) $request->user()->barangay_id, 404);
        $data = $request->validate(['visible_to_parties' => ['required', 'boolean']]);
        $evidence->update($data);
        AuditLogger::record('lupon_evidence.visibility_changed', barangayId: $evidence->barangay_id, actorId: $request->user()->id, entityType: 'lupon_evidence', entityId: $evidence->id, context: ['visible_to_parties' => $data['visible_to_parties']]);

        return back()->with('success', 'Evidence access updated.');
    }
}
