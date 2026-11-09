<?php

namespace Tests\Feature;

use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class StaffAccessIntegrityTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_assignment_must_match_the_users_barangay_and_resident(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $other = $this->createBarangay('Two', 'TWO');
        $user = $this->createStaff($barangay, 'admin');
        $assignment = $user->staffAssignments()->firstOrFail();
        $this->assertTrue($user->hasActiveAssignment(['admin']));

        $assignment->update(['barangay_id' => $other->id]);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();

        $assignment->update(['barangay_id' => $barangay->id, 'resident_id' => $user->resident_id]);
        $otherUser = $this->createStaff($barangay, 'clerk');
        $assignment->update(['barangay_id' => $barangay->id, 'resident_id' => $otherUser->resident_id]);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_account_or_barangay_revocation_denies_existing_staff_sessions(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $user = $this->createStaff($barangay, 'admin');
        ResidentAccount::where('resident_id', $user->resident_id)->update(['is_active' => false]);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();

        ResidentAccount::where('resident_id', $user->resident_id)->update(['is_active' => true]);
        $barangay->update(['is_active' => false]);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bulk_user_deactivation_is_not_bypassed_by_a_cached_user(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $user = $this->createStaff($barangay, 'admin');
        $user->newQuery()->whereKey($user->id)->update(['status' => 'inactive']);
        $this->assertSame('active', $user->status);
        $this->actingAs($user)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
