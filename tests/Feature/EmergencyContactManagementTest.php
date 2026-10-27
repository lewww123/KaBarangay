<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EmergencyContact;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\SharedEmergencyContact;
use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\EmergencyContactSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class EmergencyContactManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private User $admin;

    private User $clerk;

    private Resident $resident;

    private Purok $purok;

    /**
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay San Jose', 'BSJ');
        $this->otherBarangay = $this->createBarangay('Barangay Central', 'BCR');

        $this->admin = $this->createStaff($this->barangay, 'admin');
        $this->clerk = $this->createStaff($this->barangay, 'clerk');

        $this->purok = $this->createPurok($this->barangay);
        $this->resident = $this->createResident($this->barangay, $this->purok);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_guests_and_non_admin_roles_cannot_access_emergency_numbers_management(): void
    {
        $this->get(route('admin.emergency-numbers.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->clerk)
            ->get(route('admin.emergency-numbers.index'))
            ->assertForbidden();
    }

    public function test_admin_index_shows_only_own_hotlines_and_active_shared_contacts(): void
    {
        $this->makeHotline(['name' => 'San Jose Tanod Base']);
        $this->makeHotline(['barangay_id' => $this->otherBarangay->id, 'name' => 'Other Hotline']);
        $this->makeLegacyEmergency(['name' => 'Legacy Emergency']);
        $this->makeSharedContact(['name' => 'City 911']);
        $this->makeSharedContact(['name' => 'Inactive Shared', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.emergency-numbers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/EmergencyNumbers/Index')
                ->has('barangayHotlines', 1)
                ->has('sharedEmergencyContacts', 1)
                ->where('barangayHotlines.0.name', 'San Jose Tanod Base')
                ->where('sharedEmergencyContacts.0.name', 'City 911')
                ->where('sharedEmergencyContacts.0.category', EmergencyContact::CATEGORY_EMERGENCY_HOTLINE)
            );
    }

    public function test_admin_can_create_barangay_hotline(): void
    {
        $payload = [
            'sub_category' => 'Tanod',
            'name' => 'Cenon Mabutin Bocacao',
            'label' => 'Chief Tanod',
            'phone_number' => '09262235797',
            'can_call' => true,
            'can_sms' => true,
            'display_order' => 2,
            'is_active' => true,
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.emergency-numbers.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('emergency_contacts', [
            'barangay_id' => $this->barangay->id,
            'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
            'name' => 'Cenon Mabutin Bocacao',
            'phone_number' => '09262235797',
            'created_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'emergency_contact_created',
            'barangay_id' => $this->barangay->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_admin_cannot_create_shared_emergency_category_contacts(): void
    {
        $payload = [
            'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
            'sub_category' => 'Fire',
            'name' => 'PPC BFP',
            'phone_number' => '09649452971',
            'can_call' => true,
            'can_sms' => false,
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.emergency-numbers.store'), $payload)
            ->assertSessionHasErrors('category');

        $this->assertDatabaseCount('emergency_contacts', 0);
    }

    public function test_creation_validates_required_fields_and_phone_number(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emergency-numbers.store'), [
                'name' => '',
                'phone_number' => 'not-a-phone',
            ])
            ->assertSessionHasErrors(['name', 'phone_number']);

        $this->assertDatabaseCount('emergency_contacts', 0);
    }

    public function test_hotline_phone_numbers_must_be_unique_within_a_barangay(): void
    {
        $this->makeHotline(['phone_number' => '0939 459 6081']);

        $this->actingAs($this->admin)
            ->post(route('admin.emergency-numbers.store'), [
                'name' => 'Duplicate Number',
                'phone_number' => '09394596081',
            ])
            ->assertSessionHasErrors('phone_number');

        $this->assertSame(
            1,
            EmergencyContact::where('barangay_id', $this->barangay->id)->count()
        );
    }

    public function test_admin_can_update_own_barangay_hotline(): void
    {
        $contact = $this->makeHotline(['name' => 'Original Name']);

        $this->actingAs($this->admin)
            ->put(route('admin.emergency-numbers.update', $contact), [
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Official',
                'name' => 'Updated Name',
                'label' => 'Updated Label',
                'phone_number' => '09999999999',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 5,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $contact->refresh();
        $this->assertSame('Updated Name', $contact->name);
        $this->assertSame('09999999999', $contact->phone_number);
        $this->assertTrue($contact->can_sms);
        $this->assertSame($this->admin->id, $contact->updated_by);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'emergency_contact_updated',
            'barangay_id' => $this->barangay->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_admin_cannot_update_contact_of_another_barangay(): void
    {
        $otherContact = $this->makeHotline([
            'barangay_id' => $this->otherBarangay->id,
            'name' => 'Other Brgy Contact',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.emergency-numbers.update', $otherContact), [
                'name' => 'Tampered Name',
                'phone_number' => '09222222222',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('emergency_contacts', [
            'name' => 'Tampered Name',
        ]);
    }

    public function test_admin_cannot_update_legacy_emergency_contacts(): void
    {
        $contact = $this->makeLegacyEmergency(['name' => 'Legacy Contact']);

        $this->actingAs($this->admin)
            ->put(route('admin.emergency-numbers.update', $contact), [
                'name' => 'Tampered Legacy',
                'phone_number' => '09222222222',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('emergency_contacts', [
            'id' => $contact->id,
            'name' => 'Legacy Contact',
        ]);
    }

    public function test_admin_can_delete_own_barangay_hotline(): void
    {
        $contact = $this->makeHotline(['name' => 'Temporary Contact']);

        $this->actingAs($this->admin)
            ->delete(route('admin.emergency-numbers.destroy', $contact))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('emergency_contacts', [
            'id' => $contact->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'emergency_contact_deleted',
            'barangay_id' => $this->barangay->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_admin_cannot_delete_contact_of_another_barangay(): void
    {
        $otherContact = $this->makeHotline([
            'barangay_id' => $this->otherBarangay->id,
            'name' => 'Protected Contact',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.emergency-numbers.destroy', $otherContact))
            ->assertForbidden();

        $this->assertDatabaseHas('emergency_contacts', [
            'id' => $otherContact->id,
        ]);
    }

    public function test_admin_cannot_delete_legacy_emergency_contacts(): void
    {
        $contact = $this->makeLegacyEmergency(['name' => 'Protected Legacy']);

        $this->actingAs($this->admin)
            ->delete(route('admin.emergency-numbers.destroy', $contact))
            ->assertForbidden();

        $this->assertDatabaseHas('emergency_contacts', [
            'id' => $contact->id,
        ]);
    }

    public function test_resident_dashboard_combines_shared_and_own_hotlines_without_duplicates(): void
    {
        $this->makeSharedContact([
            'name' => 'City Emergency',
            'service_type' => 'City Emergency',
            'phone_number' => '0939 459 6081',
        ]);
        $this->makeHotline([
            'name' => 'Duplicate Barangay Copy',
            'phone_number' => '09394596081',
        ]);
        $this->makeHotline([
            'name' => 'Barangay Tanod',
            'phone_number' => '09170000002',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get(route('resident.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Dashboard')
                ->has('sharedEmergencyContacts', 1)
                ->has('barangayHotlines', 1)
                ->where('sharedEmergencyContacts.0.name', 'City Emergency')
                ->where('sharedEmergencyContacts.0.tel_uri', 'tel:09394596081')
                ->where('sharedEmergencyContacts.0.sms_uri', 'sms:09394596081')
                ->where('barangayHotlines.0.name', 'Barangay Tanod')
            );
    }

    public function test_resident_dashboard_returns_empty_contact_arrays_when_none_exist(): void
    {
        $this->withSession(['resident_id' => $this->resident->id])
            ->get(route('resident.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Dashboard')
                ->has('sharedEmergencyContacts', 0)
                ->has('barangayHotlines', 0)
            );
    }

    public function test_resident_dashboard_does_not_leak_other_barangay_hotlines(): void
    {
        $this->makeHotline([
            'barangay_id' => $this->otherBarangay->id,
            'name' => 'Other Barangay Hotline',
        ]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get(route('resident.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Resident/Dashboard')
                ->has('sharedEmergencyContacts', 0)
                ->has('barangayHotlines', 0)
            );
    }

    public function test_resident_dashboard_hides_inactive_shared_and_barangay_contacts(): void
    {
        $this->makeSharedContact(['is_active' => false]);
        $this->makeHotline(['is_active' => false]);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get(route('resident.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sharedEmergencyContacts', 0)
                ->has('barangayHotlines', 0)
            );
    }

    public function test_resident_dashboard_handles_missing_contact_tables(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get(route('resident.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sharedEmergencyContacts', 0)
                ->has('barangayHotlines', 0)
            );
    }

    public function test_resident_dashboard_still_loads_hotlines_when_shared_table_is_missing(): void
    {
        $this->makeHotline(['name' => 'Available Hotline']);

        Schema::shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table) => $table === 'emergency_contacts');

        $this->withSession(['resident_id' => $this->resident->id])
            ->get(route('resident.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sharedEmergencyContacts', 0)
                ->has('barangayHotlines', 1)
                ->where('barangayHotlines.0.name', 'Available Hotline')
            );
    }

    public function test_legacy_emergency_contact_seeder_is_retired(): void
    {
        try {
            $this->seed(EmergencyContactSeeder::class);
            $this->fail('The retired seeder should not run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retired', $exception->getMessage());
        }

        $this->assertDatabaseCount('emergency_contacts', 0);
    }

    public function test_phone_number_normalization_and_uri_generation(): void
    {
        $this->assertSame('09394596081', PhoneNumber::normalize('0939 459 6081'));
        $this->assertSame('+639171234567', PhoneNumber::normalize('+63 917-123-4567'));
        $this->assertSame('911', PhoneNumber::normalize(' 911 '));
        $this->assertSame('117', PhoneNumber::normalize('(117)'));

        $contact = $this->makeSharedContact([
            'phone_number' => '+63 917-123-4567',
            'can_sms' => true,
        ]);

        $this->assertSame('tel:+639171234567', $contact->telUri());
        $this->assertSame('sms:+639171234567', $contact->smsUri());
    }

    public function test_import_command_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        $file = $this->makeDirectoryFile([$this->importRow()]);

        $this->assertSame(0, $this->runArtisanCommand(
            'emergency-directory:import',
            ['file' => $file],
        ));

        $this->assertDatabaseCount('shared_emergency_contacts', 0);

        $this->assertSame(0, $this->runArtisanCommand('emergency-directory:import', [
            'file' => $file,
            '--apply' => true,
            '--verified-by' => $this->admin->id,
        ]));

        $contact = SharedEmergencyContact::firstOrFail();
        $this->assertSame('city-911', $contact->source_key);
        $this->assertSame('09394596081', $contact->normalized_phone);
        $this->assertSame($this->admin->id, $contact->verified_by);
        $this->assertSame($this->admin->id, $contact->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'shared_emergency_contact_imported',
            'actor_id' => $this->admin->id,
            'entity_type' => 'shared_emergency_contact',
        ]);

        $this->assertSame(0, $this->runArtisanCommand('emergency-directory:import', [
            'file' => $file,
            '--apply' => true,
            '--verified-by' => $this->admin->id,
        ]));

        $this->assertDatabaseCount('shared_emergency_contacts', 1);

        $contact->delete();

        $this->assertSame(0, $this->runArtisanCommand('emergency-directory:import', [
            'file' => $file,
            '--apply' => true,
            '--verified-by' => $this->admin->id,
        ]));

        $this->assertSoftDeleted('shared_emergency_contacts', [
            'id' => $contact->id,
        ]);
        $this->assertDatabaseCount('shared_emergency_contacts', 1);
    }

    public function test_import_command_rejects_invalid_rows(): void
    {
        $file = $this->makeDirectoryFile([
            $this->importRow([
                'phone_number' => 'invalid',
                'verification_source' => '',
            ]),
        ]);

        $this->assertSame(1, $this->runArtisanCommand('emergency-directory:import', [
            'file' => $file,
            '--apply' => true,
        ]));

        $this->assertDatabaseCount('shared_emergency_contacts', 0);
    }

    public function test_import_command_rejects_duplicate_source_keys(): void
    {
        $file = $this->makeDirectoryFile([
            $this->importRow(),
            $this->importRow(['name' => 'Duplicate City 911']),
        ]);

        $this->assertSame(1, $this->runArtisanCommand('emergency-directory:import', [
            'file' => $file,
            '--apply' => true,
        ]));

        $this->assertDatabaseCount('shared_emergency_contacts', 0);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runArtisanCommand(string $command, array $parameters = []): int
    {
        $result = $this->artisan($command, $parameters);

        return $result instanceof PendingCommand ? $result->run() : $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeHotline(array $attributes = []): EmergencyContact
    {
        return EmergencyContact::create([
            'barangay_id' => $this->barangay->id,
            'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
            'sub_category' => 'Tanod',
            'name' => 'Barangay Tanod',
            'label' => 'Official Hotline',
            'phone_number' => '09394596081',
            'can_call' => true,
            'can_sms' => true,
            'display_order' => 1,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeLegacyEmergency(array $attributes = []): EmergencyContact
    {
        return EmergencyContact::create([
            'barangay_id' => $this->barangay->id,
            'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
            'sub_category' => 'City Emergency',
            'name' => 'Legacy City 911',
            'label' => null,
            'phone_number' => '09277972009',
            'can_call' => true,
            'can_sms' => false,
            'display_order' => 10,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSharedContact(array $attributes = []): SharedEmergencyContact
    {
        $attributes = array_merge([
            'source_key' => 'shared-'.Str::uuid(),
            'service_type' => 'City Emergency',
            'name' => 'City 911',
            'label' => null,
            'phone_number' => '09394596081',
            'coverage_area' => 'Test City',
            'can_call' => true,
            'can_sms' => false,
            'display_order' => 1,
            'is_active' => true,
            'verified_at' => now(),
            'verification_source' => 'Test verification record',
        ], $attributes);

        $attributes['normalized_phone'] = PhoneNumber::normalize($attributes['phone_number']);

        return SharedEmergencyContact::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function importRow(array $attributes = []): array
    {
        return array_merge([
            'source_key' => 'city-911',
            'service_type' => 'City Emergency',
            'name' => 'Puerto Princesa City 911',
            'label' => 'Emergency Hotline 1',
            'phone_number' => '0939 459 6081',
            'coverage_area' => 'Puerto Princesa City',
            'can_call' => true,
            'can_sms' => true,
            'display_order' => 1,
            'is_active' => true,
            'verified_at' => '2026-10-25T00:00:00+08:00',
            'verification_source' => 'Reviewed city directory 2026',
        ], $attributes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function makeDirectoryFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emergency-directory-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary directory file.');
        }

        file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
