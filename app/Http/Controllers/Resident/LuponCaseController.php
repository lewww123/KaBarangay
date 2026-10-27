<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\Resident;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LuponCaseController extends Controller
{
    /**
     * Show case details to an authorized resident complainant or verified respondent.
     */
    public function show(Request $request, LuponCase $case): Response
    {
        $residentId = session('resident_id');

        $resident = Resident::query()
            ->where('id', $residentId)
            ->where('status', 'active')
            ->firstOrFail();

        // Cross-barangay check
        if ($case->barangay_id !== $resident->barangay_id) {
            abort(404, 'Case not found.');
        }

        // Check if viewer is a complainant
        $isComplainant = $case->complainants()
            ->where('resident_id', $resident->id)
            ->exists() || ($case->complaint && $case->complaint->resident_id === $resident->id);

        // Check if viewer is a verified respondent
        $isVerifiedRespondent = $case->respondents()
            ->where('resident_id', $resident->id)
            ->where('association_status', 'verified')
            ->exists();

        if (! $isComplainant && ! $isVerifiedRespondent) {
            abort(403, 'Unauthorized access to this case record.');
        }

        $case->load([
            'complaint',
            'complainants',
            'respondents',
            'hearings',
            'settlement',
        ]);

        $viewerRole = $isComplainant ? 'complainant' : 'respondent';

        // Privacy Policy: For respondents, narrative is sanitized or shown as official dispute summary
        return Inertia::render('Resident/LuponCaseShow', [
            'viewerRole' => $viewerRole,
            'caseData' => [
                'id' => $case->id,
                'caseNumber' => $case->case_number,
                'origin' => $case->origin,
                'title' => $case->title,
                'caseType' => $case->case_type,
                'phase' => $case->phase,
                'status' => $case->status,
                'filedDate' => $case->filed_date->format('F j, Y'),
                'complaintReference' => $case->complaint?->reference,
                'narrative' => $isComplainant ? $case->complaint?->narrative : 'Nature of Dispute: ' . $case->case_type,
                'requestedAction' => $isComplainant ? $case->complaint?->requested_action : null,
                'respondentName' => $case->respondents->first()?->name ?? 'Respondent',
                'complainantName' => $case->complainants->first()?->name ?? 'Complainant',
                'hearings' => $case->hearings->map(fn ($h) => [
                    'id' => $h->id,
                    'number' => $h->hearing_number,
                    'phase' => $h->phase,
                    'date' => $h->hearing_date->format('F j, Y'),
                    'time' => $h->hearing_time,
                    'venue' => $h->venue,
                    'status' => $h->status,
                    'outcome' => $h->outcome,
                ]),
                'settlement' => $case->settlement ? [
                    'type' => $case->settlement->settlement_type,
                    'date' => $case->settlement->settlement_date->format('F j, Y'),
                    'terms' => $case->settlement->terms,
                    'status' => $case->settlement->status,
                ] : null,
            ],
        ]);
    }
}
