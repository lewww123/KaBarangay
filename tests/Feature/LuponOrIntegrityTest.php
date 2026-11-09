<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LuponOrIntegrityTest extends TestCase
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

        $this->barangay = $this->createBarangay('Integrity Barangay', 'INTG');
        $purok = $this->createPurok($this->barangay);
        $this->resident = $this->createResident($this->barangay, $purok);
        $this->staff = $this->createStaff($this->barangay, 'lupon');
        $this->staff->staffAssignments()->update(['position' => 'Lupon Secretary']);
    }

    public function test_promote_trims_and_uppercases_or_number(): void
    {
        $complaint = $this->assessedComplaint();
        $receipt = $this->receipt(['or_number' => '  or-online-101  ']);

        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $receipt)
            ->assertSessionHasNoErrors();

        $case = $complaint->case()->firstOrFail();
        $this->assertSame('OR-ONLINE-101', $case->or_number);
        $this->assertSame('paid', $case->payment_status);
        $this->assertSame('OR-ONLINE-101', $complaint->fresh()->payment_verification['or_number']);
    }

    public function test_store_manual_case_trims_and_uppercases_or_number(): void
    {
        $this->actingAs($this->staff)
            ->post('/lupon/cases', $this->manualCasePayload(['or_number' => '  or-manual-202  ']))
            ->assertSessionHasNoErrors();

        $case = LuponCase::where('barangay_id', $this->barangay->id)->latest('id')->firstOrFail();
        $this->assertSame('OR-MANUAL-202', $case->or_number);
        $this->assertSame('paid', $case->payment_status);
    }

    public function test_verify_fee_trims_and_uppercases_or_number(): void
    {
        $case = $this->createUnpaidCase();

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 150.00,
                'or_number' => '  or-verify-303  ',
            ])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('OR-VERIFY-303', $case->or_number);
        $this->assertSame('paid', $case->payment_status);
        $this->assertNotNull($case->paid_at);
    }

    public function test_duplicate_or_rejected_within_same_barangay(): void
    {
        $first = $this->assessedComplaint();
        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$first->id.'/promote', $this->receipt(['or_number' => 'OR-DUPE-100']))
            ->assertSessionHasNoErrors();

        // 1. Promote duplicate in same barangay (with lowercase/surrounding spaces) -> rejected
        $second = $this->assessedComplaint();
        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$second->id.'/promote', $this->receipt(['or_number' => '  or-dupe-100  ']))
            ->assertSessionHasErrors('or_number');

        // 2. Manual case duplicate in same barangay -> rejected
        $this->actingAs($this->staff)
            ->post('/lupon/cases', $this->manualCasePayload(['or_number' => 'OR-DUPE-100']))
            ->assertSessionHasErrors('or_number');

        // 3. Verify fee duplicate in same barangay -> rejected
        $unpaidCase = $this->createUnpaidCase();
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$unpaidCase->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'or-dupe-100',
            ])
            ->assertSessionHasErrors('or_number');

        $this->assertSame('unpaid', $unpaidCase->fresh()->payment_status);
        $this->assertNull($unpaidCase->fresh()->or_number);
    }

    public function test_same_or_number_allowed_in_different_barangays(): void
    {
        // Case in Barangay 1
        $complaint1 = $this->assessedComplaint();
        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$complaint1->id.'/promote', $this->receipt(['or_number' => 'OR-SHARED-888']))
            ->assertSessionHasNoErrors();

        // Barangay 2 setup
        $barangay2 = $this->createBarangay('Second Barangay', 'SEC');
        $purok2 = $this->createPurok($barangay2);
        $resident2 = $this->createResident($barangay2, $purok2);
        $staff2 = $this->createStaff($barangay2, 'lupon');
        $staff2->staffAssignments()->update(['position' => 'Lupon Secretary']);

        // Case in Barangay 2 using exact same OR
        $this->withSession(['resident_id' => $resident2->id])
            ->post('/resident/lupon/complaints', [
                'submission_token' => (string) Str::uuid(),
                'respondentName' => 'Respondent Two',
                'respondentResidentStatus' => 'Unknown',
                'complaintType' => 'Debt / Money Dispute',
                'incidentDate' => '2026-11-01',
                'incidentLocation' => 'Purok 1',
                'narrative' => 'Loan dispute',
                'requestedAction' => 'Mediation',
            ])->assertSessionHasNoErrors();
        $complaint2 = LuponComplaint::where('barangay_id', $barangay2->id)->latest('id')->firstOrFail();

        $this->actingAs($staff2)
            ->post('/lupon/complaints/'.$complaint2->id.'/request-payment', ['assessed_fee' => '100.00'])
            ->assertSessionHasNoErrors();

        $this->actingAs($staff2)
            ->post('/lupon/complaints/'.$complaint2->id.'/promote', [
                'payment_verified' => true,
                'payment_date' => now()->toDateString(),
                'verification_note' => 'Verified in second barangay',
                'or_number' => 'OR-SHARED-888',
                'hearing_date' => '2026-11-06',
                'hearing_time' => '10:00',
                'venue' => 'Hall 2',
            ])
            ->assertSessionHasNoErrors();

        $case1 = $complaint1->case()->firstOrFail();
        $case2 = $complaint2->case()->firstOrFail();

        $this->assertSame('OR-SHARED-888', $case1->or_number);
        $this->assertSame('OR-SHARED-888', $case2->or_number);
        $this->assertNotEquals($case1->barangay_id, $case2->barangay_id);
    }

    public function test_different_normalized_or_numbers_in_same_barangay_are_accepted(): void
    {
        $case1 = $this->createUnpaidCase('KP-DIFF-1');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case1->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => '  or-alpha-1  ',
            ])
            ->assertSessionHasNoErrors();

        $case2 = $this->createUnpaidCase('KP-DIFF-2');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case2->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'OR-BETA-2',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('OR-ALPHA-1', $case1->fresh()->or_number);
        $this->assertSame('OR-BETA-2', $case2->fresh()->or_number);
    }

    public function test_paid_requires_non_empty_or_number(): void
    {
        $complaint = $this->assessedComplaint();

        // Promotion with empty OR fails
        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt(['or_number' => '   ']))
            ->assertSessionHasErrors('or_number');

        // Manual case with empty OR fails
        $this->actingAs($this->staff)
            ->post('/lupon/cases', $this->manualCasePayload(['or_number' => '']))
            ->assertSessionHasErrors('or_number');

        // Fee verification for paid case with empty OR fails
        $case = $this->createUnpaidCase();
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 50,
                'or_number' => '  ',
            ])
            ->assertSessionHasErrors('or_number');
    }

    public function test_waived_fee_rejects_non_empty_or_and_stores_null(): void
    {
        $case = $this->createUnpaidCase();

        // 1. Supplying an OR for waived fee must be rejected
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
                'or_number' => 'WAIVER-DUMMY',
            ])
            ->assertSessionHasErrors('or_number');

        $this->assertSame('unpaid', $case->fresh()->payment_status);
        $this->assertNull($case->fresh()->or_number);

        // 2. Waived fee with no OR succeeds and persists NULL
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
            ])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('waived', $case->payment_status);
        $this->assertNull($case->or_number);
    }

    public function test_paid_with_zero_or_number_is_treated_as_non_empty_and_valid(): void
    {
        $case = $this->createUnpaidCase('KP-ZERO-1');

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 50,
                'or_number' => '0',
            ])
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('paid', $case->payment_status);
        $this->assertSame('0', $case->or_number);

        // A second case in the same barangay using "0" must be rejected under uniqueness
        $case2 = $this->createUnpaidCase('KP-ZERO-2');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case2->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 50,
                'or_number' => '0',
            ])
            ->assertSessionHasErrors('or_number');

        $this->assertSame('unpaid', $case2->fresh()->payment_status);
    }

    public function test_waived_with_zero_or_number_is_rejected(): void
    {
        $case = $this->createUnpaidCase('KP-WAIVED-ZERO');

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
                'or_number' => '0',
            ])
            ->assertSessionHasErrors('or_number');

        $case->refresh();
        $this->assertSame('unpaid', $case->payment_status);
        $this->assertNull($case->or_number);
    }

    public function test_waived_with_null_or_empty_or_succeeds_and_persists_null(): void
    {
        // 1. Explicit null
        $caseNull = $this->createUnpaidCase('KP-W-NULL');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$caseNull->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
                'or_number' => null,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('waived', $caseNull->fresh()->payment_status);
        $this->assertNull($caseNull->fresh()->or_number);

        // 2. Empty string
        $caseEmpty = $this->createUnpaidCase('KP-W-EMPTY');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$caseEmpty->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
                'or_number' => '',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('waived', $caseEmpty->fresh()->payment_status);
        $this->assertNull($caseEmpty->fresh()->or_number);

        // 3. Whitespace-only string
        $caseSpaces = $this->createUnpaidCase('KP-W-SPACES');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$caseSpaces->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
                'or_number' => '   ',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('waived', $caseSpaces->fresh()->payment_status);
        $this->assertNull($caseSpaces->fresh()->or_number);

        // 4. Missing/omitted or_number key
        $caseOmitted = $this->createUnpaidCase('KP-W-OMITTED');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$caseOmitted->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('waived', $caseOmitted->fresh()->payment_status);
        $this->assertNull($caseOmitted->fresh()->or_number);
    }

    public function test_multiple_waived_and_unpaid_cases_allow_null_or_number_without_collision(): void
    {
        $case1 = $this->createUnpaidCase('CASE-001');
        $case2 = $this->createUnpaidCase('CASE-002');
        $case3 = $this->createUnpaidCase('CASE-003');

        $this->assertNull($case1->or_number);
        $this->assertNull($case2->or_number);
        $this->assertNull($case3->or_number);

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case1->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case2->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('waived', $case1->fresh()->payment_status);
        $this->assertNull($case1->fresh()->or_number);
        $this->assertSame('waived', $case2->fresh()->payment_status);
        $this->assertNull($case2->fresh()->or_number);
        $this->assertSame('unpaid', $case3->fresh()->payment_status);
        $this->assertNull($case3->fresh()->or_number);
    }

    public function test_database_unique_index_rejects_duplicate_non_null_or_number_in_same_barangay(): void
    {
        DB::table('lupon_cases')->insert([
            'barangay_id' => $this->barangay->id,
            'case_number' => 'KP-2026-TEST-1',
            'title' => 'Dispute 1',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'paid',
            'or_number' => 'DB-TEST-001',
            'filed_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('lupon_cases')->insert([
            'barangay_id' => $this->barangay->id,
            'case_number' => 'KP-2026-TEST-2',
            'title' => 'Dispute 2',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'paid',
            'or_number' => 'DB-TEST-001',
            'filed_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_unique_index_allows_duplicate_null_or_numbers_in_same_barangay(): void
    {
        DB::table('lupon_cases')->insert([
            'barangay_id' => $this->barangay->id,
            'case_number' => 'KP-2026-NULL-1',
            'title' => 'Null Dispute 1',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'unpaid',
            'or_number' => null,
            'filed_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lupon_cases')->insert([
            'barangay_id' => $this->barangay->id,
            'case_number' => 'KP-2026-NULL-2',
            'title' => 'Null Dispute 2',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'waived',
            'or_number' => null,
            'filed_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('lupon_cases')
            ->where('barangay_id', $this->barangay->id)
            ->whereNull('or_number')
            ->count();

        $this->assertSame(2, $count);
    }

    public function test_database_normalized_unique_index_rejects_case_and_whitespace_variants_in_same_barangay(): void
    {
        DB::table('lupon_cases')->insert([
            'barangay_id' => $this->barangay->id,
            'case_number' => 'KP-2026-NORM-1',
            'title' => 'Normalized Dispute 1',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'paid',
            'or_number' => 'OR-CANON-1',
            'filed_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('lupon_cases')->insert([
            'barangay_id' => $this->barangay->id,
            'case_number' => 'KP-2026-NORM-2',
            'title' => 'Normalized Dispute 2',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'paid',
            'or_number' => '  or-canon-1  ',
            'filed_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_updating_a_case_with_its_own_existing_or_number_remains_valid(): void
    {
        $case = $this->createUnpaidCase('KP-OWN-OR');

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => '  or-keep-me  ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('OR-KEEP-ME', $case->fresh()->or_number);

        // Re-verification is blocked once already verified; this proves the stored
        // normalized OR remains intact and the existing record was not corrupted.
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'OR-KEEP-ME',
            ])
            ->assertSessionHasErrors('fee_amount');

        $this->assertSame('OR-KEEP-ME', $case->fresh()->or_number);
    }

    public function test_verify_fee_does_not_partially_update_on_validation_failure(): void
    {
        $existing = $this->createUnpaidCase('KP-2026-EXISTING');
        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$existing->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'OR-EXISTING-999',
            ])->assertSessionHasNoErrors();

        $target = $this->createUnpaidCase('KP-2026-TARGET');

        $this->actingAs($this->staff)
            ->post('/lupon/cases/'.$target->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'OR-EXISTING-999',
            ])->assertSessionHasErrors('or_number');

        $target->refresh();
        $this->assertSame('unpaid', $target->payment_status);
        $this->assertNull($target->or_number);
        $this->assertNull($target->paid_at);
    }

    public function test_case_fee_verification_and_waiver_require_the_selected_secretary_assignment(): void
    {
        $member = $this->createStaff($this->barangay, 'lupon');
        $member->staffAssignments()->update(['position' => 'Lupon Member']);
        $case = $this->createUnpaidCase('KP-MEMBER-DENIED');

        $this->actingAs($member)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'OR-MEMBER-DENIED',
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'waived',
                'fee_amount' => 0,
            ])
            ->assertForbidden();

        $case->refresh();
        $this->assertSame('unpaid', $case->payment_status);
        $this->assertNull($case->or_number);
    }

    public function test_secondary_secretary_assignment_does_not_authorize_the_selected_member_assignment(): void
    {
        $member = $this->createStaff($this->barangay, 'lupon');
        $member->staffAssignments()->update(['position' => 'Lupon Member']);
        StaffAssignment::create([
            'user_id' => $member->id,
            'resident_id' => $member->resident_id,
            'barangay_id' => $this->barangay->id,
            'department' => 'lupon',
            'position' => 'Lupon Secretary',
            'is_active' => true,
        ]);

        $case = $this->createUnpaidCase('KP-SECONDARY-SECRETARY');
        $this->actingAs($member)
            ->post('/lupon/cases/'.$case->id.'/verify-fee', [
                'payment_status' => 'paid',
                'fee_amount' => 100,
                'or_number' => 'OR-SECONDARY-SECRETARY',
            ])
            ->assertForbidden();

        $this->assertSame('unpaid', $case->fresh()->payment_status);
    }

    public function test_promote_clears_the_v3_dashboard_and_case_count_caches(): void
    {
        $complaint = $this->assessedComplaint();

        Cache::put('lupon:complaints:stats:'.$this->barangay->id, ['stale' => true], 30);
        Cache::put('lupon:cases:counts:v3:'.$this->barangay->id, ['stale' => true], 30);
        Cache::put('lupon:dashboard:metrics:v3:'.$this->barangay->id, ['stale' => true], 30);

        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$complaint->id.'/promote', $this->receipt(['or_number' => 'OR-CACHE-101']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Cache::has('lupon:complaints:stats:'.$this->barangay->id));
        $this->assertFalse(Cache::has('lupon:cases:counts:v3:'.$this->barangay->id));
        $this->assertFalse(Cache::has('lupon:dashboard:metrics:v3:'.$this->barangay->id));
    }

    private function submitComplaint(): LuponComplaint
    {
        $this->withSession(['resident_id' => $this->resident->id])->post('/resident/lupon/complaints', [
            'submission_token' => (string) Str::uuid(),
            'respondentName' => 'Respondent One',
            'respondentResidentStatus' => 'Unknown',
            'complaintType' => 'Debt / Money Dispute',
            'incidentDate' => '2026-11-01',
            'incidentLocation' => 'Purok 1',
            'narrative' => 'Unpaid loan.',
            'requestedAction' => 'Request mediation.',
        ])->assertSessionHasNoErrors();

        return LuponComplaint::latest('id')->firstOrFail();
    }

    private function assessedComplaint(): LuponComplaint
    {
        $complaint = $this->submitComplaint();
        $this->actingAs($this->staff)
            ->post('/lupon/complaints/'.$complaint->id.'/request-payment', ['assessed_fee' => '125.50'])
            ->assertSessionHasNoErrors();

        return $complaint->fresh();
    }

    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'payment_verified' => true,
            'payment_date' => now()->timezone('Asia/Manila')->toDateString(),
            'verification_note' => 'Original receipt and actual payment checked.',
            'or_number' => 'OR-ONLINE-101',
            'hearing_date' => '2026-11-05',
            'hearing_time' => '14:30',
            'venue' => 'Barangay Session Hall',
        ], $overrides);
    }

    private function manualCasePayload(array $overrides = []): array
    {
        return array_merge([
            'payment_verified' => true,
            'payment_date' => now()->timezone('Asia/Manila')->toDateString(),
            'verification_note' => 'Original receipt checked.',
            'or_number' => 'WALKIN-'.Str::uuid(),
            'fee_amount' => 100,
            'case_type' => 'Property / Boundary Dispute',
            'incident_date' => now()->subDay()->toDateString(),
            'incident_location' => 'Purok 1',
            'narrative' => 'Boundary encroachment dispute.',
            'requestedAction' => 'Boundary relocation survey.',
            'requested_action' => 'Boundary relocation survey.',
            'complainant_name' => 'Walkin Complainant',
            'complainant_is_resident' => false,
            'respondent_name' => 'Walkin Respondent',
            'respondent_is_resident' => false,
        ], $overrides);
    }

    private function createUnpaidCase(?string $caseNumber = null): LuponCase
    {
        return LuponCase::create([
            'barangay_id' => $this->barangay->id,
            'case_number' => $caseNumber ?? ('KP-2026-'.Str::random(6)),
            'title' => 'Unpaid Case Dispute',
            'case_type' => 'Debt / Money Dispute',
            'phase' => 'Kapitan',
            'status' => 'filed',
            'payment_status' => 'unpaid',
            'fee_amount' => 100.00,
            'or_number' => null,
            'filed_date' => now()->toDateString(),
            'created_by' => $this->staff->id,
            'assigned_to' => $this->staff->id,
        ]);
    }
}
