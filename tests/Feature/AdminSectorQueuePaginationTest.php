<?php

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\SectorApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminSectorQueuePaginationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_queue_is_bounded_scoped_and_searches_beyond_the_first_page(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('One', 'ONE');
        $other = $this->createBarangay('Two', 'TWO');
        $admin = $this->createStaff($barangay, 'admin');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $sector = Sector::create(['name' => 'PWD', 'slug' => 'pwd', 'requires_proof' => true, 'is_active' => true]);
        foreach (range(1, 31) as $index) {
            SectorApplication::create([
                'barangay_id' => $index === 31 ? $other->id : $barangay->id,
                'resident_id' => $resident->id, 'sector_id' => $sector->id,
                'tracking_number' => 'QUEUE-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'submission_type' => 'staff_assisted', 'status' => 'requires_correction',
            ]);
        }
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/admin/sector-applications')
            ->assertOk()->assertInertia(fn ($page) => $page
            ->has('applications', 25)->where('pagination.total', 30)
            ->where('pagination.last_page', 2)->where('stats.returned', 30));
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'from "sector_application_documents"')));
        DB::disableQueryLog();
        $this->get('/admin/sector-applications?page=2')->assertInertia(fn ($page) => $page->has('applications', 5));
        $this->get('/admin/sector-applications?search=queue-001&sector=pwd&status=requires_correction')
            ->assertInertia(fn ($page) => $page->has('applications', 1)->where('applications.0.tracking_number', 'QUEUE-001'));
        $this->get('/admin/sector-applications?search=QUEUE-031')->assertInertia(fn ($page) => $page->has('applications', 0));
    }
}
