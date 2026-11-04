<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Purok;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class RegistrationPurokCatalogTest extends TestCase
{
    use CreatesBarangayFixtures;

    public function test_registration_options_are_not_deferred_on_initial_load_or_validation_redirect(): void
    {
        $this->artisan('migrate:fresh');
        $this->withoutVite();
        config(['inertia.defer_heavy_props' => true]);
        $barangay = $this->createBarangay('San Jose', 'WIZARD-STABLE');
        $purok = $this->createPurok($barangay);
        $this->get('/resident/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('barangays.0.id', $barangay->id)->where('barangays.0.puroks.0.id', $purok->id)
            ->has('sectors'));
        $this->from('/resident/register')->post('/resident/register', [])->assertSessionHasErrors('first_name');
        $this->get('/resident/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('barangays.0.id', $barangay->id)->has('sectors'));
        $this->assertDatabaseCount('resident_registrations', 0);
    }

    public function test_puroks_remain_arrays_after_cache_serialization_and_on_other_browser_requests(): void
    {
        $this->artisan('migrate:fresh');
        $this->withoutVite();
        config(['inertia.defer_heavy_props' => false]);
        $barangay = $this->createBarangay('Registration Catalog', 'REG-CATALOG');
        $first = $this->createPurok($barangay, 'Purok Alpha');
        $second = $this->createPurok($barangay, 'Purok Beta');
        Purok::create(['barangay_id' => $barangay->id, 'name' => 'Inactive Purok', 'is_active' => false]);
        $this->get('/resident/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('barangays.0.puroks', 2)->where('barangays.0.puroks.0.id', $first->id));
        $cached = Cache::get('registration:barangay-purok-options:v2');
        $this->assertIsArray($cached[0]['puroks']);
        // Cache stores that disallow PHP object classes must preserve the public catalog.
        Cache::put('registration:barangay-purok-options:v2', unserialize(serialize($cached), ['allowed_classes' => false]), 300);
        $this->withCookie('registration_browser', 'second-browser')->get('/resident/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('barangays.0.puroks', 2)->where('barangays.0.puroks.1.id', $second->id));
        config(['inertia.defer_heavy_props' => true]);
        $this->get('/resident/register', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '', 'X-Inertia-Partial-Component' => 'Resident/Register', 'X-Inertia-Partial-Data' => 'barangays'])->assertOk()->assertJsonCount(2, 'props.barangays.0.puroks')->assertJsonPath('props.barangays.0.puroks.0.id', $first->id);
        $second->update(['is_active' => false]);
        $this->assertNull(Cache::get('registration:barangay-purok-options:v2'));
        config(['inertia.defer_heavy_props' => false]);
        $this->flushHeaders()->get('/resident/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('barangays.0.puroks', 1)->where('barangays.0.puroks.0.id', $first->id));
    }
}
