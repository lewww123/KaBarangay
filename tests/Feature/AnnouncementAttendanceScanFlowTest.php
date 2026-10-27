<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\ResidentQrCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AnnouncementAttendanceScanFlowTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    public static function portalProvider(): array
    {
        return [
            'clerk' => ['clerk', 'clerk', null, 'all'],
            'pwd staff' => ['pwd', 'pwd_staff', 'pwd', 'pwd'],
            'senior staff' => ['senior', 'senior_staff', 'senior', 'senior_citizens'],
        ];
    }

    private function markUrl(string $portal, Announcement $announcement): string
    {
        return '/'.$portal.'/announcements/'.$announcement->id.'/attendance/mark';
    }

    private function createCredential(Resident $resident): ResidentQrCredential
    {
        return ResidentQrCredential::create([
            'resident_id' => $resident->id,
            'token' => 'KBQR-'.Str::random(64),
            'is_active' => true,
            'generated_at' => now(),
        ]);
    }

    private function resolveProof(User $staff, string $token, Announcement $announcement, Resident $resident): string
    {
        $response = $this->actingAs($staff)
            ->getJson('/api/qr/verify-token?token='.urlencode($token).'&announcement_id='.$announcement->id)
            ->assertOk()
            ->assertJsonPath('resident.id', $resident->id);

        $proof = $response->json('qr_proof');

        $this->assertIsString($proof);
        $this->assertNotSame('', $proof);

        return $proof;
    }

    #[DataProvider('portalProvider')]
    public function test_verified_qr_scan_records_attendance_for_portal(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        $staff = $this->createStaff($this->barangay, $role);
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170007001']);

        if ($sector !== null) {
            $this->assignSector($resident, $sector);
        }

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => $audience,
            'requires_attendance' => true,
        ]);
        $credential = $this->createCredential($resident);
        $proof = $this->resolveProof($staff, $credential->token, $announcement, $resident);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'qr_proof' => $proof,
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'qr_scanner',
            'verified_by' => $staff->id,
        ]);
    }

    #[DataProvider('portalProvider')]
    public function test_manual_attendance_still_records_for_portal(
        string $portal,
        string $role,
        ?string $sector,
        string $audience,
    ): void {
        $staff = $this->createStaff($this->barangay, $role);
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09170007002']);

        if ($sector !== null) {
            $this->assignSector($resident, $sector);
        }

        $announcement = $this->createAnnouncement($this->barangay, [
            'target_audience' => $audience,
            'requires_attendance' => true,
        ]);

        $this->actingAs($staff)->post($this->markUrl($portal, $announcement), [
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcement_attendances', [
            'announcement_id' => $announcement->id,
            'resident_id' => $resident->id,
            'status' => 'present',
            'method' => 'manual',
            'verified_by' => $staff->id,
        ]);
    }
}
