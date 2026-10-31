<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\Resident;
use App\Support\ResidentContext;
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
        $resident = ResidentContext::get();

        // Cross-barangay check
        if ($case->barangay_id !== $resident->barangay_id) {
            abort(404, 'Case not found.');
        }

        // Resolve both party roles in one query, then reuse the loaded complaint.
        $case->loadMissing(['parties', 'complaint']);
        $isComplainant = $case->parties->contains(fn ($party) => $party->party_type === 'complainant' && $party->resident_id === $resident->id
        ) || $case->complaint?->resident_id === $resident->id;

        $isVerifiedRespondent = $case->parties->contains(fn ($party) => $party->party_type === 'respondent'
            && $party->resident_id === $resident->id
            && $party->association_status === 'verified'
        );

        if (! $isComplainant && ! $isVerifiedRespondent) {
            abort(403, 'Unauthorized access to this case record.');
        }

        $case->setRelation('complainants', $case->parties->where('party_type', 'complainant')->values());
        $case->setRelation('respondents', $case->parties->where('party_type', 'respondent')->values());

        $viewerRole = $isComplainant ? 'complainant' : 'respondent';

        // Privacy Policy: For respondents, narrative is sanitized or shown as official dispute summary
        return Inertia::render('Resident/LuponCaseShow', [
            'viewerRole' => $viewerRole,
            'caseData' => $this->deferProp(function () use ($case, $isComplainant): array {
                $case->loadMissing(['hearings', 'settlement']);

                return [
                    'id' => $case->id,
                    'caseNumber' => $case->case_number,
                    'origin' => $case->origin,
                    'title' => $case->title,
                    'caseType' => $case->case_type,
                    'phase' => $case->phase,
                    'status' => $case->status,
                    'filedDate' => $case->filed_date->format('F j, Y'),
                    'complaintReference' => $case->complaint?->reference,
                    'narrative' => $isComplainant ? $case->complaint?->narrative : 'Nature of Dispute: '.$case->case_type,
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
                ];
            }),
        ]);
    }
}
