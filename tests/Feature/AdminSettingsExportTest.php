<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminSettingsExportTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
    }

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/settings')->assertRedirect(route('login'));
        $this->get('/admin/settings/export/residents')->assertRedirect(route('login'));
        $this->get('/admin/settings/export/staff')->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_cannot_access_settings_or_exports(): void
    {
        foreach (['clerk', 'senior', 'pwd', 'lupon'] as $role) {
            $user = $this->createStaff($this->barangay, $role);

            $this->actingAs($user)->get('/admin/settings')->assertStatus(403);
            $this->actingAs($user)->get('/admin/settings/export/residents')->assertStatus(403);
            $this->actingAs($user)->get('/admin/settings/export/staff')->assertStatus(403);
        }
    }

    public function test_super_admin_cannot_access_settings_or_exports(): void
    {
        $superAdmin = $this->createStaff($this->barangay, 'super_admin');

        $this->actingAs($superAdmin)->get('/admin/settings')->assertStatus(403);
        $this->actingAs($superAdmin)->get('/admin/settings/export/residents')->assertStatus(403);
        $this->actingAs($superAdmin)->get('/admin/settings/export/staff')->assertStatus(403);
    }

    public function test_inactive_admin_cannot_access_settings_or_exports(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['status' => 'inactive']);

        $this->actingAs($admin)->get('/admin/settings')->assertStatus(403);
        $this->actingAs($admin)->get('/admin/settings/export/residents')->assertStatus(403);
        $this->actingAs($admin)->get('/admin/settings/export/staff')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Settings page: real barangay name
    |--------------------------------------------------------------------------
    */

    public function test_settings_page_displays_the_authenticated_admins_barangay_name(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings')
                ->where('barangay.name', 'Barangay One'));
    }

    /*
    |--------------------------------------------------------------------------
    | Residents CSV export
    |--------------------------------------------------------------------------
    */

    public function test_residents_export_returns_csv_download_headers(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'A-1']);

        $response = $this->actingAs($admin)->get('/admin/settings/export/residents');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename="residents-',
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_residents_export_includes_only_own_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, [
            'resident_number' => 'OWN-1',
            'first_name' => 'Own',
            'last_name' => 'Resident',
        ]);

        $otherPurok = $this->createPurok($this->other, 'Purok 1');
        $this->createResident($this->other, $otherPurok, [
            'resident_number' => 'OTHER-1',
            'first_name' => 'Other',
            'last_name' => 'Resident',
        ]);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/residents')
            ->getContent();

        $this->assertStringContainsString('OWN-1', $body);
        $this->assertStringNotContainsString('OTHER-1', $body);
    }

    public function test_residents_export_excludes_sensitive_columns(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'A-1']);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/residents')
            ->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('public_id', $body);
        $this->assertStringNotContainsString('token', $body);
    }

    public function test_residents_export_contains_expected_header_columns(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'A-1']);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/residents')
            ->getContent();

        $header = strtok($body, "\n");

        foreach (['Resident Number', 'First Name', 'Last Name', 'Purok', 'Status'] as $column) {
            $this->assertStringContainsString($column, $header);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Staff CSV export
    |--------------------------------------------------------------------------
    */

    public function test_staff_export_returns_csv_download_headers(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)->get('/admin/settings/export/staff');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename="staff-',
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_staff_export_includes_only_own_barangay_and_excludes_super_admin(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', [
            'name' => 'Own Admin',
            'email' => 'own@example.test',
        ]);

        $this->createStaff($this->barangay, 'clerk', [
            'name' => 'Own Clerk',
            'email' => 'clerk@example.test',
        ]);

        $this->createStaff($this->barangay, 'super_admin', [
            'name' => 'Dormant Super',
            'email' => 'super@example.test',
        ]);

        $this->createStaff($this->other, 'admin', [
            'name' => 'Other Admin',
            'email' => 'other@example.test',
        ]);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/staff')
            ->getContent();

        $this->assertStringContainsString('clerk@example.test', $body);
        $this->assertStringContainsString('own@example.test', $body);
        $this->assertStringNotContainsString('super@example.test', $body);
        $this->assertStringNotContainsString('other@example.test', $body);
    }

    public function test_staff_export_excludes_authentication_secrets(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/staff')
            ->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Formula injection protection
    |--------------------------------------------------------------------------
    */

    public function test_residents_export_neutralises_formula_injection(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->createResident($this->barangay, $this->purok, [
            'resident_number' => 'A-1',
            'first_name' => '=SUM(A1:A2)',
            'last_name' => 'Payload',
        ]);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/residents')
            ->getContent();

        $this->assertStringContainsString("'=SUM(A1:A2)", $body);
        $this->assertStringNotContainsString(',=SUM(A1:A2)', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Null barangay assignment
    |--------------------------------------------------------------------------
    */

    public function test_null_barangay_admin_exports_no_records(): void
    {
        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'A-1']);

        $admin = User::factory()->create([
            'barangay_id' => null,
            'role' => 'admin',
            'department' => 'Admin',
            'status' => 'active',
        ]);

        $residents = $this->actingAs($admin)
            ->get('/admin/settings/export/residents')
            ->getContent();

        $staff = $this->actingAs($admin)
            ->get('/admin/settings/export/staff')
            ->getContent();

        $this->assertStringNotContainsString('A-1', $residents);
        $this->assertStringNotContainsString('@example.test', $staff);

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('barangay.name', null));
    }

    public function test_resident_is_not_exported_with_a_client_supplied_barangay_id(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $otherPurok = $this->createPurok($this->other, 'Purok 1');
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'OTHER-1']);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/residents?barangay_id='.$this->other->id)
            ->getContent();

        $this->assertStringNotContainsString('OTHER-1', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | JSON backup export
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected_from_backup_export(): void
    {
        $this->get('/admin/settings/export/backup')->assertRedirect(route('login'));
    }

    public function test_backup_export_returns_json_download_headers(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $response = $this->actingAs($admin)->get('/admin/settings/export/backup');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename="barangay-data-',
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_backup_includes_metadata_and_allowlisted_tables(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'OWN-1']);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/backup')
            ->getContent();

        $data = json_decode($body, true);

        $this->assertSame('kabarangay.barangay-data-export', $data['meta']['format']);
        $this->assertSame(1, $data['meta']['format_version']);
        $this->assertSame('barangay', $data['meta']['scope']);
        $this->assertSame($this->barangay->id, $data['meta']['barangay_id']);
        $this->assertSame('BRGY-ONE', $data['meta']['barangay_code']);
        $this->assertArrayHasKey('generated_at', $data['meta']);

        foreach (['residents', 'puroks', 'certificate_requests', 'lupon_complaints'] as $table) {
            $this->assertArrayHasKey($table, $data['tables']);
            $this->assertArrayHasKey('columns', $data['tables'][$table]);
        }
    }

    public function test_backup_metadata_documents_scope_global_tables_and_value_encoding(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $meta = $data['meta'];

        $this->assertSame(['sectors'], $meta['global_tables']);
        $this->assertSame('raw-database', $meta['value_encoding']);
        $this->assertIsString($meta['note']);
        $this->assertStringContainsString('not a restore-verified backup', $meta['note']);
        $this->assertStringContainsString('users table is not exported', $meta['note']);
    }

    public function test_backup_contains_only_own_barangay_records(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'OWN-1']);

        $otherPurok = $this->createPurok($this->other, 'Purok 1');
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'OTHER-1']);

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $numbers = array_column($data['tables']['residents']['rows'], 'resident_number');

        $this->assertContains('OWN-1', $numbers);
        $this->assertNotContains('OTHER-1', $numbers);

        foreach ($data['tables']['residents']['rows'] as $row) {
            $this->assertSame($this->barangay->id, $row['barangay_id']);
        }
    }

    public function test_backup_excludes_authentication_secrets(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'OWN-1']);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/backup')
            ->getContent();

        $data = json_decode($body, true);

        $this->assertStringNotContainsString('"password"', $body);
        $this->assertStringNotContainsString('"remember_token"', $body);
        $this->assertStringNotContainsString('"token"', $body);
        $this->assertStringNotContainsString('"sha256"', $body);
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString('"disk"', $body);

        $residentColumns = $data['tables']['residents']['columns'];
        $this->assertNotContains('password', $residentColumns);
        $this->assertNotContains('remember_token', $residentColumns);

        $registrationColumns = $data['tables']['resident_registrations']['columns'];
        $this->assertNotContains('password', $registrationColumns);
    }

    public function test_backup_excludes_lupon_third_party_and_free_text_fields(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $columns = $data['tables']['lupon_complaints']['columns'];

        $this->assertNotContains('respondent_contact', $columns);
        $this->assertNotContains('respondent_address', $columns);
        $this->assertNotContains('narrative', $columns);
        $this->assertNotContains('lupon_note', $columns);

        $this->assertContains('respondent_name', $columns);
        $this->assertContains('reference', $columns);
    }

    public function test_backup_excludes_certificate_request_admin_note(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $this->assertNotContains('admin_note', $data['tables']['certificate_requests']['columns']);
    }

    public function test_backup_excludes_document_file_metadata_but_keeps_relation_keys(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $columns = $data['tables']['registration_documents']['columns'];

        foreach (['original_name', 'mime_type', 'file_size', 'disk', 'path', 'sha256'] as $excluded) {
            $this->assertNotContains($excluded, $columns);
        }

        foreach (['id', 'resident_registration_id', 'sector_id', 'document_type', 'verification_status', 'verified_at'] as $kept) {
            $this->assertContains($kept, $columns);
        }
    }

    public function test_backup_does_not_include_security_or_audit_tables(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/backup')
            ->getContent();

        $data = json_decode($body, true);

        $this->assertStringNotContainsString('audit_logs', $body);
        $this->assertStringNotContainsString('login_attempts', $body);
        $this->assertStringNotContainsString('suspicious_login_alerts', $body);

        foreach ([
            'users',
            'resident_accounts',
            'resident_qr_credentials',
            'resident_password_reset_tokens',
            'password_reset_tokens',
            'sessions',
            'cache',
            'jobs',
        ] as $excludedTable) {
            $this->assertArrayNotHasKey($excludedTable, $data['tables']);
        }
    }

    public function test_null_barangay_admin_gets_empty_object_tables(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin', ['barangay_id' => null]);
        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'OWN-1']);

        $body = $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent();
        $data = json_decode($body, true);

        $this->assertNull($data['meta']['barangay_id']);
        $this->assertNull($data['meta']['barangay_code']);
        $this->assertSame([], $data['tables']);
        $this->assertStringNotContainsString('OWN-1', json_encode($data));

        $this->assertStringContainsString('"tables": {}', $body);
        $this->assertStringNotContainsString('"tables": []', $body);
    }

    public function test_backup_cannot_be_broadened_by_client_supplied_barangay_id(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $otherPurok = $this->createPurok($this->other, 'Purok 1');
        $this->createResident($this->other, $otherPurok, ['resident_number' => 'OTHER-1']);

        $body = $this->actingAs($admin)
            ->get('/admin/settings/export/backup?barangay_id='.$this->other->id)
            ->getContent();

        $this->assertStringNotContainsString('OTHER-1', $body);
    }

    public function test_backup_indirect_tables_are_scoped_through_own_barangay(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $resident = $this->createResident($this->barangay, $this->purok, ['resident_number' => 'OWN-1']);
        $sector = $this->assignSector($resident, 'senior');

        $registration = $this->createPendingRegistration($this->barangay, $this->purok);

        $otherPurok = $this->createPurok($this->other, 'Purok 1');
        $otherResident = $this->createResident($this->other, $otherPurok, ['resident_number' => 'OTHER-1']);
        $this->assignSector($otherResident, 'pwd');

        $otherRegistration = $this->createPendingRegistration($this->other, $otherPurok, [
            'first_name' => 'Other',
            'last_name' => 'Applicant',
        ]);

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $residentIds = array_column($data['tables']['resident_sector']['rows'], 'resident_id');

        $this->assertContains($resident->id, $residentIds);
        $this->assertNotContains($otherResident->id, $residentIds);
        $this->assertContains($sector->id, array_column($data['tables']['resident_sector']['rows'], 'sector_id'));

        $registrationIds = array_column(
            $data['tables']['registration_sectors']['rows'],
            'resident_registration_id'
        );

        $this->assertNotContains($otherRegistration->id, $registrationIds);
        foreach ($registrationIds as $id) {
            $this->assertSame($registration->id, $id);
        }
    }

    public function test_backup_indirect_tables_return_no_rows_when_parent_has_no_barangay_records(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        $otherPurok = $this->createPurok($this->other, 'Purok 1');
        $otherResident = $this->createResident($this->other, $otherPurok, ['resident_number' => 'OTHER-1']);
        $this->assignSector($otherResident, 'pwd');
        $this->createPendingRegistration($this->other, $otherPurok);

        $data = json_decode(
            $this->actingAs($admin)->get('/admin/settings/export/backup')->getContent(),
            true
        );

        $this->assertSame([], $data['tables']['resident_sector']['rows']);
        $this->assertSame([], $data['tables']['registration_sectors']['rows']);
        $this->assertSame([], $data['tables']['registration_documents']['rows']);
    }

    public function test_csv_exports_include_nosniff_header(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->createResident($this->barangay, $this->purok, ['resident_number' => 'OWN-1']);

        $this->actingAs($admin)
            ->get('/admin/settings/export/residents')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($admin)
            ->get('/admin/settings/export/staff')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
