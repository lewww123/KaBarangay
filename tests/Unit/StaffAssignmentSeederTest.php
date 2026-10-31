<?php

namespace Tests\Unit;

use App\Models\Barangay;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\StaffAssignment;
use App\Models\User;
use Database\Seeders\StaffAssignmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAssignmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_provisions_idempotent_resident_linked_staff_assignments(): void
    {
        $this->seed(StaffAssignmentSeeder::class);
        $this->seed(StaffAssignmentSeeder::class);

        $barangay = Barangay::query()->where('code', 'STAFF-TEST')->firstOrFail();
        $residents = Resident::query()->where('barangay_id', $barangay->id)->where('status', 'active')->get();

        $this->assertGreaterThanOrEqual(3, $residents->count());
        $this->assertSame(5, StaffAssignment::query()->where('barangay_id', $barangay->id)->where('is_active', true)->count());

        foreach ($residents->take(3) as $resident) {
            $this->assertNotNull(ResidentAccount::query()->where('resident_id', $resident->id)->where('is_active', true)->first());
            $this->assertNotNull(User::query()->where('resident_id', $resident->id)->first());
        }
    }
}
