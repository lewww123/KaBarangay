<?php

namespace Tests\Concerns;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentRegistration;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CreatesBarangayFixtures
{
    private function createBarangay(string $name, string $code): Barangay
    {
        return Barangay::create([
            'name' => $name,
            'code' => $code,
            'city_municipality' => 'Test City',
            'province' => 'Test Province',
            'is_active' => true,
        ]);
    }

    private function createPurok(Barangay $barangay, string $name = 'Purok 1'): Purok
    {
        return Purok::create([
            'barangay_id' => $barangay->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createStaff(Barangay $barangay, string $role, array $attributes = []): User
    {
        return User::factory()->create([
            'barangay_id' => $barangay->id,
            'role' => $role,
            'department' => ucfirst($role),
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function createResident(Barangay $barangay, Purok $purok, array $attributes = []): Resident
    {
        $firstName = $attributes['first_name'] ?? 'Juan';
        $lastName = $attributes['last_name'] ?? 'Dela Cruz';
        $mobile = $attributes['mobile'] ?? '09170000001';

        $registration = ResidentRegistration::create([
            'public_id' => (string) Str::uuid(),
            'barangay_id' => $barangay->id,
            'purok_id' => $purok->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => '1990-01-01',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'address_line' => '123 Test Street',
            'mobile' => $mobile,
            'password' => Hash::make('password'),
            'status' => 'approved',
        ]);

        $resident = Resident::create([
            'public_id' => (string) Str::uuid(),
            'resident_registration_id' => $registration->id,
            'barangay_id' => $barangay->id,
            'purok_id' => $purok->id,
            'resident_number' => $attributes['resident_number'] ?? 'TEST-RES-'.Str::upper(Str::random(6)),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => '1990-01-01',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'address_line' => '123 Test Street',
            'mobile' => $mobile,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        ResidentAccount::create([
            'resident_id' => $resident->id,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        return $resident;
    }

    private function createPendingRegistration(Barangay $barangay, Purok $purok, array $attributes = []): ResidentRegistration
    {
        return ResidentRegistration::create([
            'public_id' => (string) Str::uuid(),
            'barangay_id' => $barangay->id,
            'purok_id' => $purok->id,
            'first_name' => $attributes['first_name'] ?? 'Pending',
            'last_name' => $attributes['last_name'] ?? 'Applicant',
            'birthdate' => '1995-05-05',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'address_line' => '456 Test Street',
            'mobile' => $attributes['mobile'] ?? '09980000001',
            'password' => Hash::make('password'),
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    private function createAnnouncement(Barangay $barangay, array $attributes = []): Announcement
    {
        return Announcement::create([
            'barangay_id' => $barangay->id,
            'title' => 'Test Announcement',
            'content' => 'Test content',
            'category' => 'General',
            'target_audience' => 'all',
            'approval_status' => 'approved',
            'is_published' => true,
            ...$attributes,
        ]);
    }

    private function assignSector(Resident $resident, string $slug): Sector
    {
        $sector = Sector::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug),
                'requires_proof' => true,
                'is_active' => true,
            ],
        );

        $resident->sectors()->syncWithoutDetaching([$sector->id]);

        return $sector;
    }
}
