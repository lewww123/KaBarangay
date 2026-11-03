<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponOnlinePaymentTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Resident $resident;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(1, 0));
        Storage::fake('local');
        Mail::fake();
        $this->barangay = $this->createBarangay('Payment Barangay', 'PAY');
        $purok = $this->createPurok($this->barangay);
        $this->resident = $this->createResident($this->barangay, $purok);
        $this->staff = $this->createStaff($this->barangay, 'lupon');
    }

    public function test_online_submission_review_payment_and_case_share_one_automatic_flow(): void
    {
        $complaint = $this->submitComplaint();
        $this->assertSame('Submitted', $complaint->status);
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->get('/resident/lupon')->assertInertia(fn ($page) => $page
            ->where('complaints.data.0.trackingStatus', 'For Review')
            ->where('complaints.data.0.case', null));

        $this->actingAs($this->staff)->post('/lupon/complaints/'.$complaint->id.'/request-payment', [
            'assessed_fee' => '125.50', 'lupon_note' => 'Pay at the barangay cashier and keep your OR.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('For Payment', $complaint->fresh()->status);
        $this->assertDatabaseCount('lupon_cases', 0);
        $this->get('/resident/lupon')->assertInertia(fn ($page) => $page
            ->where('complaints.data.0.trackingStatus', 'For Payment')
            ->where('complaints.data.0.assessedFee', '125.50')
            ->where('complaints.data.0.case', null));

        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())->assertSessionHasNoErrors();

        $case = $complaint->case()->firstOrFail();
        $this->assertSame('Accepted', $complaint->fresh()->status);
        $this->assertSame('paid', $case->payment_status);
        $this->assertEquals(125.50, $case->fee_amount);
        $this->assertSame('OR-ONLINE-101', $case->or_number);
        $this->assertNotNull($case->paid_at);
        $this->assertSame('Kapitan', $case->phase);
        $this->assertSame('2026-11-03', $case->filed_date->toDateString());
        $hearing = $case->hearings()->sole();
        $this->assertSame(1, $hearing->phase_number);
        $this->assertSame('2026-11-05', $hearing->hearing_date->toDateString());
        $this->assertStringStartsWith('14:30', $hearing->hearing_time);
        $this->assertSame('Barangay Session Hall', $hearing->venue);
        $this->get('/resident/lupon')->assertInertia(fn ($page) => $page
            ->where('complaints.data.0.case.orNumber', 'OR-ONLINE-101')
            ->where('complaints.data.0.case.paymentStatus', 'paid'));
        $this->assertDatabaseCount('lupon_cases', 1);
    }

    public function test_receipt_and_complete_schedule_are_required_before_any_case_or_hearing_exists(): void
    {
        $complaint = $this->assessedComplaint();
        foreach (['or_number', 'hearing_date', 'hearing_time', 'venue'] as $field) {
            $data = $this->receipt();
            unset($data[$field]);
            $this->post('/lupon/complaints/'.$complaint->id.'/promote', $data)->assertSessionHasErrors($field);
            $this->assertSame('For Payment', $complaint->fresh()->status);
            $this->assertDatabaseCount('lupon_cases', 0);
            $this->assertDatabaseCount('lupon_hearings', 0);
        }
        $this->post('/lupon/complaints/'.$complaint->id.'/verify-fee', [...$this->receipt(), 'or_number' => '  '])
            ->assertSessionHasErrors('or_number');
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    public function test_first_paid_hearing_counts_toward_the_three_hearing_and_fifteen_day_limits(): void
    {
        $complaint = $this->assessedComplaint();
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())->assertSessionHasNoErrors();
        $case = $complaint->case()->firstOrFail();
        $first = $case->hearings()->firstOrFail();

        $this->post('/lupon/cases/'.$case->id.'/hearings', [
            'hearing_date' => '2026-11-21', 'hearing_time' => '10:00', 'venue' => 'Barangay Hall',
            'previous_hearing_id' => $first->id,
        ])->assertSessionHasErrors('hearing_date');
        $this->assertSame('Scheduled', $first->fresh()->status);
        $this->assertSame(1, $case->hearings()->count());

        foreach (['2026-11-10', '2026-11-15'] as $date) {
            $previous = $case->hearings()->reorder('hearing_number', 'desc')->firstOrFail();
            $this->post('/lupon/cases/'.$case->id.'/hearings', [
                'hearing_date' => $date, 'hearing_time' => '10:00', 'venue' => 'Barangay Hall',
                'previous_hearing_id' => $previous->id, 'previous_hearing_notes' => 'Not settled.',
            ])->assertSessionHasNoErrors();
        }
        $last = $case->hearings()->reorder('hearing_number', 'desc')->firstOrFail();
        $this->post('/lupon/cases/'.$case->id.'/hearings', [
            'hearing_date' => '2026-11-16', 'hearing_time' => '10:00', 'venue' => 'Barangay Hall',
            'previous_hearing_id' => $last->id,
        ])->assertSessionHasErrors('hearing_date');
        $this->assertSame(3, $case->hearings()->where('phase', 'Kapitan')->count());
        $this->assertSame('Scheduled', $last->fresh()->status);
    }

    public function test_forged_acceptance_or_payment_fields_do_not_bypass_review_or_assessment(): void
    {
        $complaint = $this->submitComplaint();
        $this->actingAs($this->staff)->patch('/lupon/complaints/'.$complaint->id.'/status', ['status' => 'Accepted'])
            ->assertSessionHasErrors('status');
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', [...$this->receipt(), 'payment_status' => 'paid', 'fee_amount' => 1])
            ->assertSessionHasErrors('complaint');
        $this->assertDatabaseCount('lupon_cases', 0);

        $this->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => '125.50'])->assertSessionHasNoErrors();
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', [...$this->receipt(), 'payment_status' => 'unpaid', 'fee_amount' => 1])
            ->assertSessionHasNoErrors();
        $case = $complaint->case()->firstOrFail();
        $this->assertSame('paid', $case->payment_status);
        $this->assertEquals(125.50, $case->fee_amount);
    }

    public function test_double_submission_does_not_duplicate_the_paid_case_or_first_hearing(): void
    {
        $complaint = $this->assessedComplaint();
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())->assertSessionHasNoErrors();
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())->assertSessionHasErrors('complaint');
        $this->assertDatabaseCount('lupon_cases', 1);
        $this->assertDatabaseCount('lupon_hearings', 1);
        $this->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => 50])->assertSessionHasErrors('assessed_fee');
        $this->assertEquals(125.50, $complaint->fresh()->assessed_fee);
    }

    public function test_a_recorded_receipt_cannot_be_reused_for_another_online_case(): void
    {
        $first = $this->assessedComplaint();
        $this->post('/lupon/complaints/'.$first->id.'/promote', $this->receipt())->assertSessionHasNoErrors();
        $second = $this->assessedComplaint();
        $this->post('/lupon/complaints/'.$second->id.'/promote', $this->receipt())->assertSessionHasErrors('or_number');
        $this->assertSame('For Payment', $second->fresh()->status);
        $this->assertDatabaseCount('lupon_cases', 1);
    }

    public function test_fee_assessment_and_receipt_are_scoped_to_authorized_barangay_staff(): void
    {
        $complaint = $this->submitComplaint();
        $other = $this->createBarangay('Other Barangay', 'OTHER-PAY');
        $staff = $this->createStaff($other, 'lupon');
        $this->actingAs($staff)->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => 100])->assertForbidden();
        $this->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt())->assertForbidden();
        $this->assertNull($complaint->fresh()->assessed_fee);
        $this->assertDatabaseCount('lupon_cases', 0);
    }

    private function submitComplaint(): LuponComplaint
    {
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', [
            'respondentName' => 'Respondent One', 'respondentResidentStatus' => 'Unknown',
            'complaintType' => 'Debt / Money Dispute', 'incidentDate' => '2026-11-01',
            'incidentLocation' => 'Purok 1', 'narrative' => 'Unpaid loan.', 'requestedAction' => 'Request mediation.',
        ])->assertSessionHasNoErrors();

        return LuponComplaint::latest('id')->firstOrFail();
    }

    private function assessedComplaint(): LuponComplaint
    {
        $complaint = $this->submitComplaint();
        $this->actingAs($this->staff)->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => '125.50'])
            ->assertSessionHasNoErrors();

        return $complaint->fresh();
    }

    private function receipt(): array
    {
        return ['or_number' => 'OR-ONLINE-101', 'hearing_date' => '2026-11-05', 'hearing_time' => '14:30', 'venue' => 'Barangay Session Hall'];
    }
}
