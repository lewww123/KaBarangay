<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponComplaint;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentLuponComplaintTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->resident = $this->createResident($this->barangay, $this->purok);
    }

    public function test_guest_is_redirected_to_resident_login(): void
    {
        $this->get('/resident/lupon')
            ->assertRedirect(route('resident.login'));
    }

    public function test_inactive_resident_is_redirected_to_login(): void
    {
        $this->resident->update(['status' => 'inactive']);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertRedirect(route('resident.login'));
    }

    public function test_page_lists_only_the_logged_in_residents_own_complaints(): void
    {
        $own = $this->createComplaint($this->barangay, $this->resident, ['reference' => 'LUP-2026-00001']);

        $otherResident = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Other',
            'last_name' => 'Resident',
            'mobile' => '09170000099',
        ]);

        $this->createComplaint($this->barangay, $otherResident, ['reference' => 'LUP-2026-00002']);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Lupon')
                ->has('complaints.data', 1)
                ->where('complaints.data.0.id', $own->id)
                ->where('complaints.data.0.reference', 'LUP-2026-00001')
                ->where('resident.id', $this->resident->id));
    }

    public function test_complaints_from_another_barangay_are_not_visible(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000088',
        ]);

        $this->createComplaint($otherBarangay, $otherResident, ['reference' => 'LUP-2026-00009']);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Lupon')
                ->has('complaints.data', 0));
    }

    public function test_resident_can_submit_a_complaint(): void
    {
        $payload = $this->validPayload();

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $payload)
            ->assertRedirect(route('resident.lupon'));

        $complaint = LuponComplaint::query()->firstOrFail();

        $this->assertSame($this->barangay->id, $complaint->barangay_id);
        $this->assertSame($this->resident->id, $complaint->resident_id);
        $this->assertSame('Submitted', $complaint->status);
        $this->assertSame('Pedro Santos', $complaint->respondent_name);
        $this->assertSame('Yes', $complaint->respondent_resident_status);
        $this->assertSame('Noise / Disturbance', $complaint->complaint_type);
        $this->assertSame('19:30', $complaint->incident_time);
        $this->assertNotNull($complaint->submitted_at);
        $this->assertNull($complaint->reviewed_at);
        $this->assertNull($complaint->rejected_at);
        $this->assertMatchesRegularExpression('/^LUP-\d{4}-\d{5}$/', $complaint->reference);
    }

    public function test_references_are_sequential_within_the_year(): void
    {
        $year = now()->year;

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload())
            ->assertRedirect();

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload())
            ->assertRedirect();

        $this->assertSame(
            [
                "LUP-{$year}-00001",
                "LUP-{$year}-00002",
            ],
            LuponComplaint::query()->orderBy('id')->pluck('reference')->all(),
        );
    }

    public function test_reference_skips_existing_numbers_on_collision(): void
    {
        $year = now()->year;

        $this->createComplaint($this->barangay, $this->resident, [
            'reference' => "LUP-{$year}-00001",
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload())
            ->assertRedirect();

        $this->assertSame(
            "LUP-{$year}-00002",
            LuponComplaint::query()->latest('id')->first()->reference,
        );
    }

    public function test_required_fields_are_validated(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', [
                'respondentName' => '',
                'respondentResidentStatus' => '',
                'complaintType' => '',
                'incidentDate' => '',
                'incidentLocation' => '',
                'narrative' => '',
                'requestedAction' => '',
            ])
            ->assertSessionHasErrors([
                'respondentName',
                'respondentResidentStatus',
                'complaintType',
                'incidentDate',
                'incidentLocation',
                'narrative',
                'requestedAction',
            ]);

        $this->assertSame(0, LuponComplaint::query()->count());
    }

    public function test_future_incident_date_is_rejected(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload([
                'incidentDate' => now()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('incidentDate');
    }

    public function test_unsupported_complaint_type_is_rejected(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload([
                'complaintType' => 'Bogus Type',
            ]))
            ->assertSessionHasErrors('complaintType');
    }

    public function test_unsupported_respondent_resident_status_is_rejected(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload([
                'respondentResidentStatus' => 'Maybe',
            ]))
            ->assertSessionHasErrors('respondentResidentStatus');
    }

    public function test_incident_time_must_be_hh_mm(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload([
                'incidentTime' => '7:30 PM',
            ]))
            ->assertSessionHasErrors('incidentTime');
    }

    public function test_optional_fields_may_be_omitted(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->post('/resident/lupon/complaints', $this->validPayload([
                'respondentAddress' => null,
                'respondentContact' => null,
                'incidentTime' => null,
            ]))
            ->assertRedirect(route('resident.lupon'));

        $complaint = LuponComplaint::query()->firstOrFail();

        $this->assertNull($complaint->respondent_address);
        $this->assertNull($complaint->respondent_contact);
        $this->assertNull($complaint->incident_time);
    }

    public function test_payload_exposes_no_hearing_or_case_management_data(): void
    {
        $this->createComplaint($this->barangay, $this->resident, ['reference' => 'LUP-2026-00001']);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/lupon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Lupon')
                ->missing('complaints.data.0.hearing')
                ->missing('complaints.data.0.caseNumber')
                ->missing('complaints.data.0.pangkat')
                ->where('complaintTypes', [
                    'Property / Boundary Dispute',
                    'Noise / Disturbance',
                    'Debt / Money Dispute',
                    'Damage to Property',
                    'Threat / Harassment',
                    'Family / Neighbor Dispute',
                    'Other',
                ]));
    }

    public function test_page_links_live_case_tracking_and_drops_mock_data(): void
    {
        // React/Inertia renders client-side, so the HTML response only carries
        // the data-page payload. Assert on the page source instead.
        $source = file_get_contents(
            base_path('resources/js/pages/Resident/Lupon.tsx'),
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'usePoll(15000',
            $source,
        );

        foreach (['initialComplaints', 'Juan Dela Cruz', 'LUP-2026-00001', 'Maria Reyes', 'Purok 4'] as $mock) {
            $this->assertStringNotContainsString(
                $mock,
                $source,
                "Resident Lupon page still contains mock value [{$mock}].",
            );
        }
    }

    private function createComplaint(
        Barangay $barangay,
        Resident $resident,
        array $overrides = [],
    ): LuponComplaint {
        return LuponComplaint::create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'reference' => 'LUP-2026-99999',
            'respondent_name' => 'Maria Reyes',
            'respondent_resident_status' => 'Yes',
            'respondent_address' => 'Purok 2',
            'respondent_contact' => '09170000002',
            'complaint_type' => 'Debt / Money Dispute',
            'incident_date' => now()->subDays(3)->toDateString(),
            'incident_time' => '14:00',
            'incident_location' => 'Barangay One',
            'narrative' => 'An unsettled personal debt between the parties.',
            'requested_action' => 'Request mediation.',
            'status' => 'Submitted',
            'submitted_at' => now(),
            ...$overrides,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'respondentName' => 'Pedro Santos',
            'respondentResidentStatus' => 'Yes',
            'respondentAddress' => 'Purok 2',
            'respondentContact' => '09170000002',
            'complaintType' => 'Noise / Disturbance',
            'incidentDate' => now()->subDays(2)->toDateString(),
            'incidentTime' => '19:30',
            'incidentLocation' => 'Purok 3',
            'narrative' => 'Repeated disturbances late at night.',
            'requestedAction' => 'Request mediation from the Lupon.',
            ...$overrides,
        ];
    }
}
