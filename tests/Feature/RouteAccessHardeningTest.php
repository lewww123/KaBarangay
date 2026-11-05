<?php

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\SectorApplication;
use App\Models\SectorApplicationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class RouteAccessHardeningTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_guests_cannot_open_portals_exports_or_staff_endpoints(): void
    {
        foreach (['/admin', '/clerk/dashboard', '/senior/dashboard', '/pwd/dashboard', '/lupon/dashboard', '/admin/reports/export', '/admin/settings/export/residents', '/clerk/certificates/issuances/999/reprint', '/lupon/documents/999'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $this->get('/resident')->assertRedirect(route('resident.login'));
        $this->getJson('/api/qr/verify-token')->assertUnauthorized();
        $this->getJson('/admin')->assertUnauthorized();
    }

    public function test_resident_ajax_gets_401_but_browser_and_inertia_visits_keep_login_redirects(): void
    {
        $this->getJson('/resident/notifications')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/resident/notifications')->assertRedirect(route('resident.login'));
        $this->get('/resident/notifications', ['X-Inertia' => 'true', 'X-Inertia-Version' => \Inertia\Inertia::getVersion(), 'Accept' => 'application/json'])->assertRedirect(route('resident.login'));
        $this->withSession(['resident_id' => 999999, 'resident_logged_in' => true])
            ->getJson('/resident/notifications')->assertUnauthorized()->assertSessionMissing('resident_id');
    }

    public function test_active_resident_revisiting_login_keeps_session_and_original_identity(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $this->withSession(['resident_id' => $resident->id, 'resident_barangay_id' => $barangay->id, 'marker' => 'preserved']);
        $this->get('/resident/login')->assertRedirect(route('resident.dashboard'))->assertSessionHas('marker', 'preserved');
        $this->post('/resident/login', [])->assertRedirect(route('resident.dashboard'))->assertSessionHas('resident_id', $resident->id);
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_resident_login_rotates_the_session_identifier(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $this->withSession(['marker' => 'preserved']);
        $oldId = session()->getId();
        $this->post('/resident/login', [
            'barangay_id' => $barangay->id, 'identifier' => $resident->mobile, 'password' => 'password',
        ])->assertRedirect(route('resident.dashboard'))->assertSessionHas('resident_id', $resident->id);
        $this->assertNotSame($oldId, session()->getId());
    }

    public function test_staff_login_rotates_the_session_identifier(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $staff = $this->createStaff($barangay, 'clerk');
        $assignment = $staff->staffAssignments()->firstOrFail();
        $this->withSession(['marker' => 'preserved']);
        $oldId = session()->getId();
        $this->post('/login', [
            'barangay_id' => $barangay->id, 'department' => 'clerk',
            'position' => $assignment->position, 'full_name' => $staff->name, 'password' => 'password',
        ])->assertRedirect(route('clerk.dashboard'));
        $this->assertNotSame($oldId, session()->getId());
        $this->assertAuthenticatedAs($staff);
    }

    public function test_inactive_resident_can_see_login_but_cannot_get_protected_json(): void
    {
        $this->withoutVite();
        $barangay = $this->createBarangay('One', 'ONE');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $resident->account()->update(['is_active' => false]);
        $this->withSession(['resident_id' => $resident->id])->get('/resident/login')->assertOk();
        $this->getJson('/resident/notifications')->assertUnauthorized()->assertSessionMissing('resident_id');
    }

    public function test_staff_without_a_valid_assignment_gets_403_instead_of_a_login_loop(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $staff = $this->createStaff($barangay, 'admin');
        $staff->staffAssignments()->update(['is_active' => false]);
        $this->actingAs($staff)->withSession(['marker' => 'preserved'])
            ->get('https://staff.kabarangay.com/staff/login')->assertForbidden()->assertSessionHas('marker', 'preserved');
        $this->assertAuthenticatedAs($staff);
    }

    public function test_staff_redirect_skips_mismatched_assignment_and_uses_existing_valid_role(): void
    {
        $barangay = $this->createBarangay('One', 'ONE');
        $other = $this->createBarangay('Two', 'TWO');
        $staff = $this->createStaff($barangay, 'admin');
        $staff->staffAssignments()->update(['barangay_id' => $other->id]);
        $staff->staffAssignments()->create([
            'barangay_id' => $barangay->id, 'resident_id' => $staff->resident_id,
            'department' => 'clerk', 'position' => 'Clerk', 'is_active' => true,
        ]);
        $this->actingAs($staff)->get('https://staff.kabarangay.com/staff/login')->assertRedirect(route('clerk.dashboard'));
        $this->get('/admin')->assertForbidden();
    }

    public function test_revoked_resident_sessions_cannot_read_or_download_sector_documents(): void
    {
        Storage::fake('local');
        $barangay = $this->createBarangay('One', 'ONE');
        $purok = $this->createPurok($barangay);
        $sector = Sector::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior Citizens', 'requires_proof' => true]);
        foreach (['account', 'resident', 'approval'] as $index => $revocation) {
            $resident = $this->createResident($barangay, $purok, ['mobile' => '0917888000'.$index]);
            $application = SectorApplication::create([
                'barangay_id' => $barangay->id, 'resident_id' => $resident->id, 'sector_id' => $sector->id,
                'tracking_number' => 'SECURITY-'.$index, 'submission_type' => 'staff_assisted', 'status' => 'pending_staff_review',
            ]);
            Storage::disk('local')->put('security/document-'.$index.'.pdf', 'test document');
            $document = SectorApplicationDocument::create([
                'sector_application_id' => $application->id, 'document_type' => 'senior_proof', 'title' => 'Proof',
                'disk' => 'local', 'path' => 'security/document-'.$index.'.pdf', 'original_name' => 'proof.pdf',
                'mime_type' => 'application/pdf', 'file_size' => 13, 'verification_status' => 'pending',
            ]);
            $url = '/sector-applications/'.$application->id.'/documents/'.$document->id;
            $this->withSession(['resident_id' => $resident->id])->get($url.'/file')->assertOk();
            match ($revocation) {
                'account' => $resident->account()->update(['is_active' => false]),
                'resident' => $resident->update(['status' => 'inactive']),
                'approval' => $resident->registration()->update(['status' => 'pending']),
            };
            $this->get($url.'/file')->assertUnauthorized();
            $this->get($url.'/download')->assertUnauthorized();
        }
    }
}
