<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LoginAuditEventsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    private function credentials(string $password = 'password'): array
    {
        return [
            'barangay_id' => $this->barangay->id,
            'department' => 'admin',
            'position' => 'Admin',
            'full_name' => 'Admin User',
            'password' => $password,
        ];
    }

    public function test_failed_unified_staff_login_records_a_safe_audit_event(): void
    {
        $this->createStaff($this->barangay, 'admin', ['name' => 'Admin User']);

        $this->from('/')->post('/login', $this->credentials('wrong-password'))
            ->assertSessionHasErrors('password');

        $row = DB::table('audit_logs')->sole();
        $this->assertSame('login.failed', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertNull($row->context);
        $this->assertStringNotContainsString('wrong-password', json_encode((array) $row));
        $this->assertStringNotContainsString('Admin User', json_encode((array) $row));
    }

    public function test_successful_unified_staff_login_records_a_safe_audit_event(): void
    {
        $staff = $this->createStaff($this->barangay, 'admin', ['name' => 'Admin User']);

        $this->post('/login', $this->credentials())->assertRedirect(route('admin.dashboard'));

        $row = DB::table('audit_logs')->sole();
        $this->assertSame('login.success', $row->event);
        $this->assertSame($staff->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertNull($row->context);
    }

    public function test_unified_login_writes_one_audit_row_for_each_outcome(): void
    {
        $this->createStaff($this->barangay, 'admin', ['name' => 'Admin User']);

        $this->from('/')->post('/login', $this->credentials('wrong-password'));
        $this->assertSame(1, DB::table('audit_logs')->count());

        $this->post('/login', $this->credentials())->assertRedirect(route('admin.dashboard'));
        $this->assertSame(2, DB::table('audit_logs')->count());
    }
}
