<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponComplaint;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponComplaintReviewTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Resident $resident;

    private User $lupon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->resident = $this->createResident($this->barangay, $this->purok);
        $this->lupon = $this->createAssignedStaff([
            'barangay' => $this->barangay,
            'purok' => $this->purok,
        ], 'lupon', 'Lupon Secretary');
    }

    public function test_lupon_queue_lists_only_own_barangay_complaints(): void
    {
        $own = $this->createComplaint($this->barangay, $this->resident, ['reference' => 'LUP-2026-00001']);

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000077',
        ]);
        $this->createComplaint($otherBarangay, $otherResident, ['reference' => 'LUP-2026-00002']);

        $this->actingAs($this->lupon)
            ->get('/lupon/complaints')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Complaints')
                ->has('complaints.data', 1)
                ->where('complaints.data.0.id', $own->id)
                ->where('complaints.data.0.reference', 'LUP-2026-00001'));
    }

    public function test_queue_can_be_filtered_by_status(): void
    {
        $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00001',
            'status' => 'Submitted',
        ]);
        $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00002',
            'status' => 'Accepted',
        ]);

        $this->actingAs($this->lupon)
            ->get('/lupon/complaints?status=Accepted')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Complaints')
                ->has('complaints.data', 1)
                ->where('complaints.data.0.reference', 'LUP-2026-00002')
                ->where('activeStatusFilter', 'Accepted'));
    }

    public function test_unknown_status_filter_is_ignored(): void
    {
        $this->createComplaint($this->barangay, $this->resident, ['reference' => 'LUP-2026-00001']);

        $this->actingAs($this->lupon)
            ->get('/lupon/complaints?status=Bogus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Complaints')
                ->has('complaints.data', 1)
                ->where('activeStatusFilter', null));
    }

    public function test_aggregate_stats_contain_only_integer_counts(): void
    {
        $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00001',
            'status' => 'Submitted',
            'narrative' => 'Highly confidential mediation narrative.',
        ]);

        $this->createComplaint($this->barangay, $this->resident, [
            'reference' => 'LUP-2026-00002',
            'status' => 'Rejected',
            'lupon_note' => 'Rejected earlier.',
        ]);

        // Exact-array comparison proves no extra keys (no narrative, no
        // records) can ever leak into the aggregate payload.
        $this->actingAs($this->lupon)
            ->get('/lupon/complaints')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Lupon/Complaints')
                ->where('stats', [
                    'pendingCount' => 1,
                    'byStatus' => [
                        'Submitted' => 1,
                        'Under Review' => 0,
                        'For Payment' => 0,
                        'Accepted' => 0,
                        'Rejected' => 1,
                    ],
                ])
                ->has('complaints.data', 2));
    }

    public function test_lupon_can_move_complaint_from_submitted_to_under_review(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Under Review'])
            ->assertRedirect();

        $complaint->refresh();

        $this->assertSame('Under Review', $complaint->status);
        $this->assertNotNull($complaint->reviewed_at);
        $this->assertNull($complaint->rejected_at);
    }

    public function test_acceptance_cannot_be_set_manually_before_payment(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'status' => 'Under Review',
        ]);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Accepted'])
            ->assertSessionHasErrors('status');

        $complaint->refresh();

        $this->assertSame('Under Review', $complaint->status);
        $this->assertNull($complaint->case);
    }

    public function test_lupon_can_reject_a_complaint_with_a_note(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', [
                'status' => 'Rejected',
                'lupon_note' => 'Outside barangay jurisdiction.',
            ])
            ->assertRedirect();

        $complaint->refresh();

        $this->assertSame('Rejected', $complaint->status);
        $this->assertSame('Outside barangay jurisdiction.', $complaint->lupon_note);
        $this->assertNotNull($complaint->rejected_at);
        $this->assertNotNull($complaint->reviewed_at);
    }

    public function test_rejecting_requires_a_note(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Rejected'])
            ->assertSessionHasErrors('lupon_note');

        $this->assertSame('Submitted', $complaint->refresh()->status);
    }

    public function test_accepted_complaint_is_final(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'status' => 'Accepted',
        ]);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Under Review'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Accepted', $complaint->refresh()->status);
    }

    public function test_rejected_complaint_is_final(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'status' => 'Rejected',
            'lupon_note' => 'Rejected earlier.',
        ]);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Accepted'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Rejected', $complaint->refresh()->status);
    }

    public function test_under_review_cannot_move_back_to_submitted(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'status' => 'Under Review',
        ]);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Submitted'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Under Review', $complaint->refresh()->status);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Bogus'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Submitted', $complaint->refresh()->status);
    }

    public function test_lupon_cannot_update_a_complaint_from_another_barangay(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherResident = $this->createResident($otherBarangay, $otherPurok, [
            'mobile' => '09170000066',
        ]);
        $foreign = $this->createComplaint($otherBarangay, $otherResident);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$foreign->id.'/status', ['status' => 'Under Review'])
            ->assertStatus(403);

        $this->assertSame('Submitted', $foreign->refresh()->status);
    }

    public function test_non_lupon_roles_cannot_open_the_complaint_queue(): void
    {
        foreach (['admin', 'clerk', 'senior', 'senior_staff', 'pwd', 'pwd_staff'] as $role) {
            $staff = $this->createStaff($this->barangay, $role);

            $this->actingAs($staff)
                ->get('/lupon/complaints')
                ->assertStatus(403);
        }
    }

    public function test_non_lupon_roles_cannot_update_a_complaint_status(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident);

        foreach (['admin', 'clerk', 'senior', 'senior_staff', 'pwd', 'pwd_staff'] as $role) {
            $staff = $this->createStaff($this->barangay, $role);

            $this->actingAs($staff)
                ->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Under Review'])
                ->assertStatus(403);
        }

        $this->assertSame('Submitted', $complaint->refresh()->status);
    }

    public function test_super_admin_cannot_open_the_complaint_queue(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)
            ->get('/lupon/complaints')
            ->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login_from_the_complaint_queue(): void
    {
        $this->get('/lupon/complaints')
            ->assertRedirect(route('login'));
    }

    public function test_reapplying_the_current_status_updates_the_note_without_moving_it(): void
    {
        $complaint = $this->createComplaint($this->barangay, $this->resident, [
            'status' => 'Under Review',
            'lupon_note' => 'First note.',
        ]);

        $this->actingAs($this->lupon)
            ->patch('/lupon/complaints/'.$complaint->id.'/status', [
                'status' => 'Under Review',
                'lupon_note' => 'Updated note.',
            ])
            ->assertRedirect();

        $complaint->refresh();

        $this->assertSame('Under Review', $complaint->status);
        $this->assertSame('Updated note.', $complaint->lupon_note);
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
}
