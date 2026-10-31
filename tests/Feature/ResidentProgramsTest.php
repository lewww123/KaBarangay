<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentProgramsTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_resident_sees_only_published_ongoing_programs_for_their_eligibility(): void
    {
        $barangay = $this->createBarangay('Programs Barangay', 'PROGRAMS');
        $purok = $this->createPurok($barangay);
        $resident = $this->createResident($barangay, $purok);
        $this->assignSector($resident, 'senior');
        $this->createProgram($barangay, ['title' => 'Community Cleanup', 'target_audience' => 'all', 'event_start_at' => now()->addDay()]);
        $this->createProgram($barangay, ['title' => 'Senior Wellness Day', 'target_audience' => 'senior_citizens', 'event_start_at' => now()->addDays(2)]);
        $this->createProgram($barangay, ['title' => 'PWD Skills Workshop', 'target_audience' => 'pwd']);
        $this->createProgram($barangay, ['title' => 'Draft Project', 'program_status' => 'draft', 'is_published' => false]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/programs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Programs')
                ->has('programs', 2)
                ->where('programs.0.title', 'Community Cleanup')
                ->where('programs.0.eligibility', 'All Residents')
                ->where('programs.1.title', 'Senior Wellness Day')
                ->where('programs.1.eligibility', 'Senior Citizens')
                ->where('programs.0.location', 'Barangay Hall')
                ->where('programs.0.schedule', now()->addDay()->toIso8601String()));
    }

    private function createProgram($barangay, array $attributes = []): Announcement
    {
        return Announcement::create([
            'barangay_id' => $barangay->id,
            'title' => 'Program',
            'content' => 'Community initiative details.',
            'category' => 'Event',
            'program_status' => 'ongoing',
            'importance' => 'normal',
            'target_audience' => 'all',
            'requires_attendance' => true,
            'event_start_at' => now()->addDay(),
            'location' => 'Barangay Hall',
            'approval_status' => 'approved',
            'is_published' => true,
            ...$attributes,
        ]);
    }
}
