<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLegalPagesTest extends TestCase
{
    public function test_privacy_policy_is_available_without_a_resident_or_staff_login(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Groq API')
            ->assertSee('local storage')
            ->assertSee('administrator ng barangay na pinili mo')
            ->assertDontSee('resources/js/app.tsx');
    }

    public function test_terms_and_google_terms_of_service_url_are_public(): void
    {
        $this->get('/terms-and-conditions')
            ->assertOk()
            ->assertSee('Terms and Conditions')
            ->assertSee('hindi garantiya ng approval')
            ->assertSee('href="/privacy-policy"', false);

        $this->get('/terms-of-service')
            ->assertStatus(301)
            ->assertRedirect('/terms-and-conditions');
    }
}
