<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Support\LoginIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class LoginRateLimitingTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
    }

    private function attempt(string $name = 'Admin User', string $password = 'wrong-password'): TestResponse
    {
        return $this->from('/')->post('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'admin',
            'position' => 'Admin',
            'full_name' => $name,
            'password' => $password,
        ]);
    }

    public function test_full_name_limit_rejects_the_sixth_attempt(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->attempt()->assertRedirect('/');
        }

        $this->attempt()->assertRedirect('/')
            ->assertSessionHasErrors(['login' => 'Too many login attempts. Please wait before trying again.']);

        $row = DB::table('login_attempts')->where('status', 'rate_limited')->sole();
        $this->assertSame('staff', $row->channel);
        $this->assertSame(LoginIdentifier::fingerprint('Admin User'), $row->identifier_hash);
    }

    public function test_successful_login_clears_the_full_name_limit(): void
    {
        $this->createStaff($this->barangay, 'admin', ['name' => 'Admin User']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->attempt();
        }

        $this->attempt(password: 'password')->assertRedirect(route('admin.dashboard'));
        $key = LoginIdentifier::middlewareKey('login-staff', LoginIdentifier::identifierKey('staff', 'Admin User'));
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_json_rate_limit_uses_the_same_message(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', [
                'barangay_id' => $this->barangay->id,
                'department' => 'admin',
                'position' => 'Admin',
                'full_name' => 'Admin User',
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/login', [
            'barangay_id' => $this->barangay->id,
            'department' => 'admin',
            'position' => 'Admin',
            'full_name' => 'Admin User',
            'password' => 'wrong-password',
        ])->assertStatus(429)->assertJson(['message' => 'Too many login attempts. Please wait before trying again.']);
    }
}
