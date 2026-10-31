<?php

namespace Tests\Feature;

use App\Auth\CachedEloquentUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class CachedUserProviderTest extends TestCase
{
    use CreatesBarangayFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache invalidation must run after real commits.
        $this->artisan('migrate:fresh');
        $this->withoutVite();
        config(['auth.user_cache.store' => 'array', 'auth.user_cache.ttl_seconds' => 30]);
    }

    public function test_session_authentication_reuses_the_cached_user_across_requests_and_rechecks_after_expiry(): void
    {
        config(['inertia.defer_heavy_props' => true]);
        $user = $this->createStaff($this->createBarangay('Auth Cache', 'AUTH-CACHE'), 'admin');
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "users"')) {
                $queries++;
            }
        });
        $sessionKey = Auth::guard()->getName();

        $this->withSession([$sessionKey => $user->id])->get('/admin/staff')->assertOk();
        $this->assertSame(2, $queries);

        Auth::forgetGuards();
        $this->get('/admin/staff')->assertOk();
        $this->assertSame(3, $queries, 'Only the staff directory relation should query users on the second request.');

        $this->travel(31)->seconds();
        Auth::forgetGuards();
        $this->get('/admin/staff')->assertOk();
        $this->assertSame(5, $queries);
    }

    public function test_account_changes_and_deletion_invalidate_the_cached_identity(): void
    {
        $user = $this->createStaff($this->createBarangay('Auth Cache', 'AUTH-CACHE'), 'admin');
        $provider = Auth::createUserProvider('users');
        $this->assertInstanceOf(CachedEloquentUserProvider::class, $provider);
        $this->assertSame('active', $provider->retrieveById($user->id)->status);

        DB::transaction(function () use ($user): void {
            $user->update(['status' => 'inactive', 'password' => 'new-password']);
            $user->staffAssignments()->update(['department' => 'clerk']);
        });
        $fresh = Auth::createUserProvider('users')->retrieveById($user->id);
        $this->assertSame('inactive', $fresh->status);
        $this->assertSame('clerk', $fresh->staffAssignments()->sole()->department);
        $this->assertTrue($provider->validateCredentials($fresh, ['password' => 'new-password']));

        $user->delete();
        $this->assertNull($provider->retrieveById($user->id));
    }

    public function test_uncommitted_attributes_are_never_published_to_the_cache(): void
    {
        $user = $this->createStaff($this->createBarangay('Auth Cache', 'AUTH-CACHE'), 'admin');
        $provider = Auth::createUserProvider('users');
        DB::beginTransaction();
        try {
            $user->update(['status' => 'inactive']);
            $this->assertSame('inactive', $provider->retrieveById($user->id)->status);
        } finally {
            DB::rollBack();
        }

        $this->assertSame('active', $provider->retrieveById($user->id)->status);
    }

    public function test_zero_ttl_disables_the_cache(): void
    {
        $user = $this->createStaff($this->createBarangay('Auth Cache', 'AUTH-CACHE'), 'admin');
        $provider = Auth::createUserProvider('users');
        $provider->retrieveById($user->id);
        config(['auth.user_cache.ttl_seconds' => 0]);
        // Bulk updates intentionally bypass model observers.
        DB::table('users')->where('id', $user->id)->update(['status' => 'inactive']);
        $this->assertSame('inactive', $provider->retrieveById($user->id)->status);
    }
}
