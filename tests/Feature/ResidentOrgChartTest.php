<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\OrgChartPosition;
use App\Models\Purok;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentOrgChartTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Barangay $barangay;

    private Barangay $otherBarangay;

    private Purok $purok;

    private Resident $resident;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Barangay Central', 'BRGY-CTR');
        $this->otherBarangay = $this->createBarangay('Barangay North', 'BRGY-NTH');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->resident = $this->createResident($this->barangay, $this->purok);
        $this->clerk = $this->createStaff($this->barangay, 'clerk');
    }

    private function createPosition(
        Barangay $barangay,
        array $attributes = []
    ): OrgChartPosition {
        return OrgChartPosition::create([
            'barangay_id' => $barangay->id,
            'title' => 'Punong Barangay',
            'parent_id' => null,
            'status' => 'draft',
            'photo_path' => null,
            'created_by' => $this->clerk->id,
            ...$attributes,
        ]);
    }

    private function residentSession()
    {
        return $this->withSession(['resident_id' => $this->resident->id]);
    }

    public function test_resident_sees_only_published_positions_from_their_barangay(): void
    {
        $root = $this->createPosition($this->barangay, [
            'title' => 'Punong Barangay',
            'assigned_person' => 'Hon. Roberto Cruz',
            'status' => 'published',
        ]);
        $child = $this->createPosition($this->barangay, [
            'title' => 'Barangay Secretary',
            'assigned_person' => null,
            'parent_id' => $root->id,
            'status' => 'published',
        ]);
        $this->createPosition($this->barangay, [
            'title' => 'Draft Role',
            'status' => 'draft',
        ]);
        $this->createPosition($this->barangay, [
            'title' => 'Archived Role',
            'status' => 'archived',
        ]);
        $this->createPosition($this->otherBarangay, [
            'title' => 'Other Barangay Role',
            'status' => 'published',
        ]);

        $this->residentSession()
            ->get('/resident/org-chart')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/OrgChart')
                ->where('resident.full_name', $this->resident->full_name)
                ->has('tree', 1)
                ->where('tree.0.id', $root->id)
                ->where('tree.0.assigned_person', 'Hon. Roberto Cruz')
                ->has('tree.0.children', 1)
                ->where('tree.0.children.0.id', $child->id)
                ->where('tree.0.children.0.assigned_person', null)
                ->missing('tree.0.photo_path')
                ->missing('tree.0.created_by'));
    }

    public function test_resident_photo_endpoint_serves_only_published_own_barangay_photos(): void
    {
        $publishedPath = 'org-chart/'.$this->barangay->id.'/published.png';
        Storage::disk('local')->put($publishedPath, base64_decode(self::PNG_1X1, true));

        $published = $this->createPosition($this->barangay, [
            'title' => 'Punong Barangay',
            'status' => 'published',
            'photo_path' => $publishedPath,
        ]);

        $draft = $this->createPosition($this->barangay, [
            'title' => 'Draft Role',
            'status' => 'draft',
            'photo_path' => $publishedPath,
        ]);

        $other = $this->createPosition($this->otherBarangay, [
            'title' => 'Other Barangay Role',
            'status' => 'published',
            'photo_path' => $publishedPath,
        ]);

        $this->residentSession()
            ->get("/resident/org-chart/{$published->id}/photo")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->residentSession()
            ->get("/resident/org-chart/{$draft->id}/photo")
            ->assertNotFound();

        $this->residentSession()
            ->get("/resident/org-chart/{$other->id}/photo")
            ->assertNotFound();

        $withoutPhoto = $this->createPosition($this->barangay, [
            'title' => 'No Photo Role',
            'status' => 'published',
        ]);

        $this->residentSession()
            ->get("/resident/org-chart/{$withoutPhoto->id}/photo")
            ->assertNotFound();
    }

    public function test_guests_and_inactive_resident_sessions_cannot_view_the_chart(): void
    {
        $this->get('/resident/org-chart')
            ->assertRedirect('/resident/login');

        $inactive = $this->createResident($this->barangay, $this->purok, [
            'first_name' => 'Inactive',
            'last_name' => 'Resident',
            'mobile' => '09170000999',
            'resident_number' => 'TEST-RES-INACTIVE',
        ]);
        $inactive->update(['status' => 'inactive']);

        $this->withSession(['resident_id' => $inactive->id])
            ->get('/resident/org-chart')
            ->assertRedirect('/resident/login');
    }
}
