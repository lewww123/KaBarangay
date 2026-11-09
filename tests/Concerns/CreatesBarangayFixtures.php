<?php

namespace Tests\Concerns;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\DisbursementDocument;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentRegistration;
use App\Models\Sector;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;

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
        return Purok::firstOrCreate(
            ['barangay_id' => $barangay->id, 'name' => $name],
            ['is_active' => true],
        );
    }

    private function createStaff(Barangay $barangay, string $role, array $attributes = []): User
    {
        if (isset($attributes['name']) && ! isset($attributes['first_name'], $attributes['last_name'])) {
            $nameParts = preg_split('/\s+/', trim($attributes['name']), 2);
            $attributes['first_name'] = $nameParts[0];
            $attributes['last_name'] = $nameParts[1] ?? 'Staff';
        }

        $resident = isset($attributes['resident_id'])
            ? Resident::findOrFail($attributes['resident_id'])
            : $this->createResident(
                $barangay,
                Purok::query()->where('barangay_id', $barangay->id)->first() ?? $this->createPurok($barangay),
                [
                    'first_name' => $attributes['first_name'] ?? 'Staff',
                    'last_name' => $attributes['last_name'] ?? 'Member',
                    'mobile' => '09'.random_int(100000000, 999999999),
                ],
            );

        unset($attributes['first_name'], $attributes['last_name'], $attributes['mobile'], $attributes['resident_number']);

        $user = User::factory()->create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'name' => $resident->full_name,
            'status' => 'active',
            ...$attributes,
        ]);
        $department = match ($role) {
            'senior_staff' => 'senior',
            'pwd_staff' => 'pwd',
            default => $role,
        };

        if (in_array($department, ['admin', 'clerk', 'lupon', 'pwd', 'senior'], true)) {
            StaffAssignment::create([
                'user_id' => $user->id,
                'resident_id' => $resident->id,
                'barangay_id' => $barangay->id,
                'department' => $department,
                'position' => ucfirst(str_replace('_', ' ', $role)),
                'is_active' => true,
            ]);
        }

        return $user->load('staffAssignments');
    }

    /** Log in a staff member with their session-scoped staff assignment. */
    private function actingAsStaff(User $user, ?StaffAssignment $assignment = null): static
    {
        $assignment ??= $user->staffAssignments()->where('is_active', true)->first();
        if ($assignment) {
            $this->withSession(['staff_assignment_id' => $assignment->id]);
        }

        return $this->actingAs($user);
    }

    /** Create a resident-linked staff assignment. */
    private function createAssignedStaff(
        array $attributes = [],
        string $department = 'admin',
        string $position = 'Barangay Admin',
    ): User {
        $barangay = $attributes['barangay'] ?? throw new \InvalidArgumentException('A barangay is required.');
        $purok = $attributes['purok'] ?? throw new \InvalidArgumentException('A purok is required.');
        unset($attributes['barangay'], $attributes['purok']);
        $attributes['mobile'] ??= '09'.random_int(100000000, 999999999);

        $resident = $this->createResident($barangay, $purok, $attributes);
        $user = User::factory()->create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'name' => $resident->full_name,
            'email' => 'assigned-staff-'.$resident->id.'@example.test',
            'status' => 'active',
        ]);
        StaffAssignment::create([
            'user_id' => $user->id,
            'resident_id' => $resident->id,
            'barangay_id' => $barangay->id,
            'department' => $department,
            'position' => $position,
            'is_active' => true,
        ]);

        return $user->load(['resident.account', 'staffAssignments.barangay']);
    }

    private function createResident(Barangay $barangay, Purok $purok, array $attributes = []): Resident
    {
        $firstName = $attributes['first_name'] ?? 'Juan';
        $middleName = $attributes['middle_name'] ?? null;
        $lastName = $attributes['last_name'] ?? 'Dela Cruz';
        $suffix = $attributes['suffix'] ?? null;
        $mobile = $attributes['mobile'] ?? '09170000001';
        $email = $attributes['email'] ?? null;

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
            'email' => $email,
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
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'suffix' => $suffix,
            'birthdate' => '1990-01-01',
            'sex' => 'Male',
            'civil_status' => 'Single',
            'address_line' => '123 Test Street',
            'mobile' => $mobile,
            'email' => $email,
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
            'email' => $attributes['email'] ?? null,
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

    private function createDisbursementDocument(Barangay $barangay, array $attributes = []): DisbursementDocument
    {
        return DisbursementDocument::create([
            'barangay_id' => $barangay->id,
            'title' => 'Test Disbursement Document',
            'description' => null,
            'disk' => 'local',
            'path' => 'disbursement-documents/'.$barangay->id.'/'.Str::uuid().'.pdf',
            'original_name' => 'payout.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('a', 64),
            'is_published' => true,
            'published_at' => now(),
            'archived_at' => null,
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
