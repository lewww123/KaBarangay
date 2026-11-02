<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentAnnouncementSearchTest extends TestCase
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

    public function test_resident_can_search_announcement_titles_and_content(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09181000001']);

        $this->createAnnouncement($this->barangay, [
            'title' => 'Relief Distribution Schedule',
            'content' => 'Rice and canned goods will be distributed.',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Barangay Assembly',
            'content' => 'Discuss the upcoming relief distribution plan.',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Health Advisory',
            'content' => 'Free checkup at the health center.',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements?q=relief')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Announcements')
                ->has('announcements.data', 2)
                ->where('filters.q', 'relief')
                ->where('announcements.total', 2));
    }

    public function test_search_and_filters_can_be_combined(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09181000002']);

        $this->createAnnouncement($this->barangay, [
            'title' => 'Relief Goods Schedule',
            'content' => 'Rice distribution details.',
            'category' => 'Relief Goods',
            'importance' => 'high',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Relief Update',
            'content' => 'General relief update.',
            'category' => 'General',
            'importance' => 'high',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Health Advisory',
            'content' => 'Free checkup.',
            'category' => 'General',
            'importance' => 'urgent',
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements?q=relief&category=Relief+Goods&importance=high')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 1)
                ->where('announcements.data.0.title', 'Relief Goods Schedule')
                ->where('filters.category', 'Relief Goods')
                ->where('filters.importance', 'high'));
    }

    public function test_search_does_not_bypass_visibility_rules(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09181000003']);

        $this->createAnnouncement($this->other, [
            'title' => 'Foreign Relief',
            'content' => 'Visible only in another barangay.',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'PWD Relief',
            'content' => 'Visible only to PWD residents.',
            'target_audience' => 'pwd',
        ]);
        $this->createAnnouncement($this->barangay, [
            'title' => 'Pending Relief',
            'content' => 'Not yet approved.',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements?q=relief')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 0)
                ->where('announcements.total', 0));
    }

    public function test_announcement_feed_is_paginated(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09181000004']);

        for ($index = 1; $index <= 11; $index++) {
            $this->createAnnouncement($this->barangay, [
                'title' => 'Announcement '.$index,
                'content' => 'Content '.$index,
            ]);
        }

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 10)
                ->where('announcements.current_page', 1)
                ->where('announcements.last_page', 2)
                ->where('announcements.total', 11)
                ->where('announcements.from', 1)
                ->where('announcements.to', 10));

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('announcements.data', 1)
                ->where('announcements.current_page', 2)
                ->where('announcements.from', 11)
                ->where('announcements.to', 11));
    }

    public function test_categories_only_include_visible_announcements(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09181000005']);

        $this->createAnnouncement($this->barangay, ['category' => 'General']);
        $this->createAnnouncement($this->barangay, ['category' => 'Event']);
        $this->createAnnouncement($this->other, ['category' => 'Foreign Category']);
        $this->createAnnouncement($this->barangay, [
            'category' => 'Pending Category',
            'approval_status' => 'pending',
            'is_published' => false,
        ]);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('categories', ['Event', 'General']));
    }

    public function test_invalid_importance_filter_is_rejected(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok, ['mobile' => '09181000006']);

        $this->withSession(['resident_id' => $resident->id])
            ->get('/resident/announcements?importance=critical')
            ->assertSessionHasErrors('importance');
    }
}
