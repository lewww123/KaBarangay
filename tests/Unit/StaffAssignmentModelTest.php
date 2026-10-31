<?php

namespace Tests\Unit;

use App\Models\StaffAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class StaffAssignmentModelTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_staff_assignment_relates_the_linked_user_resident_and_barangay(): void
    {
        $barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $purok = $this->createPurok($barangay);
        $resident = $this->createResident($barangay, $purok);
        $user = $this->createStaff($barangay, 'lupon', ['resident_id' => $resident->id]);
        $assignment = StaffAssignment::create([
            'user_id' => $user->id,
            'resident_id' => $resident->id,
            'barangay_id' => $barangay->id,
            'department' => 'lupon',
            'position' => 'Lupon Secretary',
        ]);
        $assignment->refresh();

        $this->assertTrue($assignment->is_active);
        $this->assertTrue($assignment->user->is($user));
        $this->assertTrue($assignment->resident->is($resident));
        $this->assertTrue($assignment->barangay->is($barangay));
        $this->assertTrue($user->resident->is($resident));
        $this->assertTrue($resident->user->is($user));
        $this->assertTrue($user->staffAssignments->contains($assignment));
        $this->assertTrue($resident->staffAssignments->contains($assignment));
    }
}
