<?php

namespace Tests\Feature;

use App\Models\Barangay;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use Throwable;

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

    private function staffSession(): array
    {
        return [
            'selected_barangay_id' => $this->barangay->id,
            'login_department' => 'admin',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Staff login events
    |--------------------------------------------------------------------------
    */

    public function test_failed_staff_login_records_one_login_failed_row_without_an_actor(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $response = $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('login.failed', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertNull($row->entity_type);
        $this->assertNull($row->entity_id);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertNotNull($row->created_at);
    }

    public function test_successful_staff_login_records_one_login_success_row_with_actor_and_barangay(): void
    {
        $staff = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $response = $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'password',
            ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($staff);

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('login.success', $row->event);
        $this->assertSame($staff->id, (int) $row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertNull($row->entity_type);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);
    }

    /*
    |--------------------------------------------------------------------------
    | Department login events
    |--------------------------------------------------------------------------
    */

    public function test_failed_department_login_records_one_safe_failure_row_without_secrets(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
            'email' => 'admin@example.test',
        ]);

        $response = $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'admin@example.test',
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('login.failed', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertNull($row->entity_type);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);

        $serialized = json_encode((array) $row);

        $this->assertStringNotContainsString('admin@example.test', $serialized);
        $this->assertStringNotContainsString('wrong-password', $serialized);
        $this->assertStringNotContainsString('"admin"', $serialized);
        $this->assertStringNotContainsString('"department"', $serialized);
    }

    public function test_department_selection_alone_does_not_record_a_successful_login(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
            'email' => 'admin@example.test',
        ]);

        $this->post('/select-barangay', ['barangay_id' => $this->barangay->id])
            ->assertRedirect(route('department.login'));

        $this->assertSame(0, DB::table('audit_logs')->count());

        $response = $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'admin@example.test',
                'password' => 'password',
            ]);

        $response->assertRedirect(route('staff.login'));
        $response->assertSessionHas('login_department');
        $this->assertGuest();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Resident login events
    |--------------------------------------------------------------------------
    */

    public function test_failed_resident_login_records_one_safe_failure_row(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok);

        $response = $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => $resident->resident_number,
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('login.failed', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($this->barangay->id, (int) $row->barangay_id);
        $this->assertSame('resident_login', $row->entity_type);
        $this->assertNull($row->entity_id);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);

        $serialized = json_encode((array) $row);

        $this->assertStringNotContainsString($resident->resident_number, $serialized);
        $this->assertStringNotContainsString('wrong-password', $serialized);
    }

    public function test_successful_resident_login_records_one_safe_success_row(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok);

        $response = $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => '09170000001',
                'password' => 'password',
                'remember' => false,
            ]);

        $response->assertRedirect(route('resident.dashboard'));
        $response->assertSessionHas('resident_logged_in', true);
        $response->assertSessionHas('resident_id', $resident->id);
        $response->assertSessionHas('resident_barangay_id', $resident->barangay_id);
        $this->assertGuest();

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();

        $this->assertSame('login.success', $row->event);
        $this->assertNull($row->actor_id);
        $this->assertSame($resident->barangay_id, (int) $row->barangay_id);
        $this->assertSame('resident_login', $row->entity_type);
        $this->assertNull($row->context);
        $this->assertSame('127.0.0.1', $row->ip_address);

        $serialized = json_encode((array) $row);

        $this->assertStringNotContainsString('09170000001', $serialized);
        $this->assertStringNotContainsString($resident->resident_number, $serialized);
    }

    /*
    |--------------------------------------------------------------------------
    | Privacy: identifiers and secrets never reach storage
    |--------------------------------------------------------------------------
    */

    public function test_attempted_login_identifiers_are_never_persisted_in_any_form(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-SECRET-9',
            'name' => 'Confidential Person',
            'email' => 'confidential@example.test',
        ]);

        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok, [
            'resident_number' => 'TEST-RES-999999',
        ]);

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-SECRET-9',
                'full_name' => 'Confidential Person',
                'password' => 'WrongPass-Staff!',
            ]);

        $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'confidential@example.test',
                'password' => 'WrongPass-Dept!',
            ]);

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => 'TEST-RES-999999',
                'password' => 'WrongPass-Res!',
            ]);

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(3, $rows);

        $secrets = [
            'ADM-SECRET',
            'Confidential Person',
            'confidential@example.test',
            $resident->resident_number,
            'TEST-RES-',
            '09170000001',
            'WrongPass-Staff!',
            'WrongPass-Dept!',
            'WrongPass-Res!',
        ];

        foreach ($rows as $row) {
            $this->assertNull($row->context);

            $serialized = json_encode((array) $row);

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }
    }

    public function test_login_events_persist_no_passwords_tokens_session_data_or_request_payloads(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok);

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'N0t-Real-Passw0rd!',
            ]);

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => $resident->resident_number,
                'password' => 'password',
            ]);

        $this->assertSame(
            [
                'id',
                'barangay_id',
                'actor_id',
                'event',
                'entity_type',
                'entity_id',
                'context',
                'ip_address',
                'created_at',
            ],
            Schema::getColumnListing('audit_logs')
        );

        $rows = DB::table('audit_logs')->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertNull($row->context);

            $serialized = json_encode((array) $row);

            $this->assertStringNotContainsString('N0t-Real-Passw0rd!', $serialized);
            $this->assertStringNotContainsString('"password"', $serialized);
            $this->assertStringNotContainsString('"token"', $serialized);
            $this->assertStringNotContainsString('"session_id"', $serialized);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Barangay attribution
    |--------------------------------------------------------------------------
    */

    public function test_login_events_attribute_the_validated_barangay_or_null_when_unvalidated(): void
    {
        $inactive = $this->createBarangay('Barangay Inactive', 'BRGY-INACT');
        Barangay::query()
            ->where('id', $inactive->id)
            ->update(['is_active' => false]);

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);

        $this
            ->withSession([
                'selected_barangay_id' => 987654,
                'login_department' => 'admin',
            ])
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);

        $this
            ->withSession([
                'selected_barangay_id' => 'not-a-number',
                'login_department' => 'admin',
            ])
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $inactive->id,
                'identifier' => 'anything',
                'password' => 'wrong-password',
            ]);

        $rows = DB::table('audit_logs')->orderBy('id')->get();

        $this->assertCount(4, $rows);

        $this->assertSame('login.failed', $rows[0]->event);
        $this->assertSame($this->barangay->id, (int) $rows[0]->barangay_id);

        $this->assertNull($rows[1]->barangay_id);

        $this->assertNull($rows[2]->barangay_id);

        $this->assertSame('login.failed', $rows[3]->event);
        $this->assertSame('resident_login', $rows[3]->entity_type);
        $this->assertSame($inactive->id, (int) $rows[3]->barangay_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Existing behaviour stays intact
    |--------------------------------------------------------------------------
    */

    public function test_staff_and_department_login_responses_and_session_contract_are_unchanged(): void
    {
        $staff = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
            'email' => 'admin@example.test',
        ]);

        $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'admin@example.test',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['login' => 'Invalid department credentials.']);

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['login' => 'Invalid staff credentials.']);

        $departmentResponse = $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'admin@example.test',
                'password' => 'password',
            ]);

        $departmentResponse->assertRedirect(route('staff.login'));
        $departmentResponse->assertSessionHas([
            'login_department' => 'admin',
            'department_username' => 'admin@example.test',
        ]);
        $this->assertGuest();

        $staffResponse = $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'password',
            ]);

        $staffResponse->assertRedirect(route('admin.dashboard'));
        $staffResponse->assertSessionMissing('login_department');
        $staffResponse->assertSessionMissing('department_username');
        $this->assertAuthenticatedAs($staff);
    }

    public function test_resident_login_responses_and_session_contract_are_unchanged(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok);

        $inactive = $this->createBarangay('Barangay Inactive', 'BRGY-INACT');
        Barangay::query()
            ->where('id', $inactive->id)
            ->update(['is_active' => false]);

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $inactive->id,
                'identifier' => 'anything',
                'password' => 'password',
            ])
            ->assertSessionHasErrors([
                'barangay_id' => 'The selected barangay is not available.',
            ]);

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => $resident->resident_number,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['login' => 'Invalid resident credentials.']);

        $response = $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => $resident->resident_number,
                'password' => 'password',
                'remember' => false,
            ]);

        $response->assertRedirect(route('resident.dashboard'));
        $response->assertSessionHas([
            'resident_logged_in' => true,
            'resident_id' => $resident->id,
            'resident_barangay_id' => $resident->barangay_id,
            'resident_remember' => false,
        ]);
        $this->assertGuest();
    }

    public function test_existing_authentication_throttling_is_unchanged(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('resident.register.store'), [])->assertStatus(302);
        }

        $this->post(route('resident.register.store'), [])->assertStatus(429);
    }

    public function test_writer_persistence_failure_does_not_change_the_login_outcome(): void
    {
        $staff = $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
        ]);

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')
            ->twice()
            ->with(Mockery::type(Throwable::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        Schema::drop('audit_logs');

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('login');

        $this->assertGuest();

        $response = $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'password',
            ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($staff);
    }

    /*
    |--------------------------------------------------------------------------
    | Exactly one row per outcome
    |--------------------------------------------------------------------------
    */

    public function test_each_staff_login_outcome_writes_exactly_one_audit_row(): void
    {
        $this->createStaff($this->barangay, 'admin', [
            'staff_id' => 'ADM-001',
            'name' => 'Admin User',
            'email' => 'admin@example.test',
        ]);

        $this->post('/select-barangay', ['barangay_id' => $this->barangay->id]);

        $this->assertSame(0, DB::table('audit_logs')->count());

        $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'admin@example.test',
                'password' => 'wrong-password',
            ]);

        $this->assertSame(1, DB::table('audit_logs')->count());

        $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->post('/department-login', [
                'department' => 'admin',
                'username' => 'admin@example.test',
                'password' => 'password',
            ]);

        $this->assertSame(1, DB::table('audit_logs')->count());

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);

        $this->assertSame(2, DB::table('audit_logs')->count());

        $this
            ->withSession($this->staffSession())
            ->post('/staff-login', [
                'staff_id' => 'ADM-001',
                'full_name' => 'Admin User',
                'password' => 'password',
            ]);

        $this->assertSame(3, DB::table('audit_logs')->count());
    }

    public function test_each_resident_login_outcome_writes_exactly_one_audit_row(): void
    {
        $purok = $this->createPurok($this->barangay);
        $resident = $this->createResident($this->barangay, $purok);

        $this->get(route('resident.login'))->assertOk();

        $this
            ->withSession(['selected_barangay_id' => $this->barangay->id])
            ->get(route('department.login'))
            ->assertOk();

        $this
            ->withSession($this->staffSession())
            ->get(route('staff.login'))
            ->assertOk();

        $this->assertSame(0, DB::table('audit_logs')->count());

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => $resident->resident_number,
                'password' => 'wrong-password',
            ]);

        $this->assertSame(1, DB::table('audit_logs')->count());

        $this
            ->post(route('resident.login.process'), [
                'barangay_id' => $this->barangay->id,
                'identifier' => $resident->resident_number,
                'password' => 'password',
            ]);

        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Scope boundaries
    |--------------------------------------------------------------------------
    */

    public function test_unrouted_authentication_methods_remain_out_of_scope(): void
    {
        $loginControllerActions = [];

        foreach (Route::getRoutes() as $route) {
            $uses = $route->getAction('uses') ?? null;

            if (is_string($uses) && str_contains($uses, 'Auth\\LoginController')) {
                $loginControllerActions[] = $uses;
            }
        }

        $this->assertTrue(Route::has('logout'));
        $this->assertSame(
            ['App\Http\Controllers\Auth\LoginController@destroy'],
            array_values(array_unique($loginControllerActions))
        );
    }
}
