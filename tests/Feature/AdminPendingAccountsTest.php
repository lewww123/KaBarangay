<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminPendingAccountsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_new_applications_appear_without_a_full_page_reload_and_remain_barangay_scoped(): void
    {
        $barangay = $this->createBarangay('San Jose', 'LIVE-SJ');
        $purok = $this->createPurok($barangay);
        $admin = $this->createStaff($barangay, 'admin');
        $this->actingAs($admin)->getJson('/admin/resident-registrations/pending')
            ->assertOk()->assertJsonPath('totalPending', 0)->assertJsonCount(0, 'pendingRegistrations.data');

        $other = $this->createBarangay('Sicsican', 'LIVE-SC');
        $this->createPendingRegistration($other, $this->createPurok($other));
        $application = $this->createPendingRegistration($barangay, $purok);
        $this->getJson('/admin/resident-registrations/pending')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('totalPending', 1)
            ->assertJsonPath('pendingRegistrations.data.0.id', $application->id)
            ->assertJsonMissingPath('pendingRegistrations.data.0.password');

        $application->update(['status' => 'rejected']);
        $this->getJson('/admin/resident-registrations/pending')->assertOk()
            ->assertJsonPath('totalPending', 0)->assertJsonCount(0, 'pendingRegistrations.data');
    }

    public function test_filters_expose_global_count_and_pagination_recovers_when_last_page_disappears(): void
    {
        $barangay = $this->createBarangay('Sta Monica', 'LIVE-SM');
        $purok = $this->createPurok($barangay);
        $otherPurok = $this->createPurok($barangay, 'Purok 2');
        $admin = $this->createStaff($barangay, 'admin');
        $first = $this->createPendingRegistration($barangay, $purok, ['first_name' => 'Match']);
        $this->createPendingRegistration($barangay, $otherPurok, ['first_name' => 'Other']);

        $this->actingAs($admin)->getJson('/admin/resident-registrations/pending?purok='.$purok->id.'&registration_search=Match&pending_page=99')
            ->assertOk()->assertJsonPath('totalPending', 2)
            ->assertJsonPath('pendingRegistrations.total', 1)
            ->assertJsonPath('pendingRegistrations.current_page', 1)
            ->assertJsonPath('pendingRegistrations.data.0.id', $first->id)
            ->assertJsonPath('pendingRegistrations.path', route('admin.residents'));

        $this->getJson('/admin/resident-registrations/pending?registration_search=Missing')
            ->assertOk()->assertJsonPath('totalPending', 2)
            ->assertJsonCount(0, 'pendingRegistrations.data');
    }

    public function test_newest_applications_are_first_and_pages_are_bounded(): void
    {
        $barangay = $this->createBarangay('San Jose', 'LIVE-PAGES');
        $purok = $this->createPurok($barangay);
        $admin = $this->createStaff($barangay, 'admin');
        foreach (range(1, 16) as $number) {
            $latest = $this->createPendingRegistration($barangay, $purok);
        }
        $this->actingAs($admin)->getJson('/admin/resident-registrations/pending')
            ->assertOk()->assertJsonPath('totalPending', 16)
            ->assertJsonCount(15, 'pendingRegistrations.data')
            ->assertJsonPath('pendingRegistrations.data.0.id', $latest->id);
        $this->getJson('/admin/resident-registrations/pending?pending_page=2')
            ->assertOk()->assertJsonCount(1, 'pendingRegistrations.data');
    }

    public function test_non_admin_cannot_read_pending_accounts(): void
    {
        $barangay = $this->createBarangay('San Jose', 'LIVE-AUTH');
        $this->actingAs($this->createStaff($barangay, 'clerk'))
            ->getJson('/admin/resident-registrations/pending')->assertForbidden();
    }
}
