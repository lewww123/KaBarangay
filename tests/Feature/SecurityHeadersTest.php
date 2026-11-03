<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_authenticated_pages_cannot_be_cached_and_have_browser_protections(): void
    {
        $this->withoutVite();
        $user = $this->createStaff($this->createBarangay('One', 'ONE'), 'admin');
        $response = $this->actingAs($user)->get('/admin/staff')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }
}
