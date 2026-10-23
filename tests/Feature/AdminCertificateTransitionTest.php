<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateRequest;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class AdminCertificateTransitionTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);
    }

    public function test_valid_lifecycle_sets_each_timestamp_once(): void
    {
        $request = $this->createRequest('LIFE-001');
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->transition($admin, $request, 'For Payment');
        $this->transition($admin, $request, 'Processing');
        $processingAt = $request->refresh()->processing_at;
        $this->assertNotNull($processingAt);

        $this->transition($admin, $request, 'Ready for Claim');
        $this->transition($admin, $request, 'Released');

        $request->refresh();

        $this->assertSame('Released', $request->status);
        $this->assertNotNull($request->ready_for_claim_at);
        $this->assertNotNull($request->released_at);
        $this->assertEquals($processingAt, $request->processing_at);
    }

    public function test_invalid_jump_forward_is_rejected(): void
    {
        $request = $this->createRequest('JUMP-001');
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Released'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Pending', $request->refresh()->status);
    }

    public function test_released_status_is_terminal(): void
    {
        $request = $this->createRequest('TERM-001', ['status' => 'Released', 'released_at' => now()]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Processing'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Released', $request->refresh()->status);
    }

    public function test_rejected_status_is_terminal(): void
    {
        $request = $this->createRequest('TERM-002', ['status' => 'Rejected', 'admin_note' => 'Incomplete', 'rejected_at' => now()]);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Processing'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Rejected', $request->refresh()->status);
    }

    public function test_reapplying_current_status_updates_the_note(): void
    {
        $request = $this->createRequest('NOTE-001');
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Pending', 'admin_note' => 'Waiting for ID'])
            ->assertRedirect();

        $this->assertSame('Waiting for ID', $request->refresh()->admin_note);
    }

    public function test_omitted_note_is_cleared_on_the_next_transition(): void
    {
        $request = $this->createRequest('NOTE-002', ['admin_note' => 'Stale note']);
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->transition($admin, $request, 'Processing');

        $this->assertNull($request->refresh()->admin_note);
    }

    public function test_correction_path_through_needs_action_is_allowed(): void
    {
        $request = $this->createRequest('CORR-001');
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Needs Action', 'admin_note' => 'Missing signature'])
            ->assertRedirect();

        $this->transition($admin, $request, 'Processing');

        $request->refresh();

        $this->assertSame('Processing', $request->status);
        $this->assertNotNull($request->reviewed_at);
    }

    public function test_rejection_records_note_and_timestamp(): void
    {
        $request = $this->createRequest('REJ-002');
        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Rejected', 'admin_note' => 'Duplicate request'])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame('Rejected', $request->status);
        $this->assertSame('Duplicate request', $request->admin_note);
        $this->assertNotNull($request->rejected_at);
    }

    public function test_released_rejects_every_other_status(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        foreach ($this->otherStatusesThan('Released') as $index => $target) {
            $request = $this->createRequest('REL-'.$index, [
                'status' => 'Released',
                'admin_note' => 'Released note',
                'reviewed_at' => now()->subDay(),
                'released_at' => now()->subDay(),
            ]);

            $before = $this->snapshot($request);

            $this->actingAs($admin)
                ->put($this->statusUrl($request), $this->attemptPayload($target))
                ->assertSessionHasErrors('status');

            $this->assertUnchanged($request, $before, 'Released');
        }
    }

    public function test_rejected_rejects_every_other_status(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        foreach ($this->otherStatusesThan('Rejected') as $index => $target) {
            $request = $this->createRequest('REJ-'.$index, [
                'status' => 'Rejected',
                'admin_note' => 'Rejected note',
                'reviewed_at' => now()->subDay(),
                'rejected_at' => now()->subDay(),
            ]);

            $before = $this->snapshot($request);

            $this->actingAs($admin)
                ->put($this->statusUrl($request), $this->attemptPayload($target))
                ->assertSessionHasErrors('status');

            $this->assertUnchanged($request, $before, 'Rejected');
        }
    }

    public function test_failed_terminal_transition_does_not_write_note_or_timestamps(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $request = $this->createRequest('REL-MUT', [
            'status' => 'Released',
            'admin_note' => 'Original note',
            'reviewed_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
        ]);

        $before = $this->snapshot($request);

        $this->actingAs($admin)
            ->put($this->statusUrl($request), [
                'status' => 'Processing',
                'admin_note' => 'Tampered note',
            ])
            ->assertSessionHasErrors('status');

        $this->assertUnchanged($request, $before, 'Released');
        $this->assertNull($request->refresh()->processing_at);
    }

    public function test_foreign_admin_cannot_transition_a_terminal_request(): void
    {
        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'BRGY-CLEARANCE',
            'name' => 'Barangay Clearance',
            'fee' => 50,
            'is_active' => true,
        ]);

        $resident = $this->createResident($otherBarangay, $otherPurok);
        $request = CertificateRequest::create([
            'barangay_id' => $otherBarangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $otherCertificate->id,
            'reference' => 'FOREIGN-TERM-001',
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Released',
            'submitted_at' => now(),
            'released_at' => now(),
        ]);

        $admin = $this->createStaff($this->barangay, 'admin');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Processing'])
            ->assertStatus(403);

        $this->assertSame('Released', $request->refresh()->status);
    }

    public function test_for_payment_remains_optional(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');

        // Free certificates may skip payment entirely.
        $skipped = $this->createRequest('OPT-001');
        $this->transition($admin, $skipped, 'Processing');
        $this->assertSame('Processing', $skipped->refresh()->status);

        // When payment does apply, the explicit step still works.
        $explicit = $this->createRequest('OPT-002');
        $this->transition($admin, $explicit, 'For Payment');
        $this->transition($admin, $explicit, 'Processing');
        $this->assertSame('Processing', $explicit->refresh()->status);
    }

    public function test_needs_action_cannot_return_to_pending(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $request = $this->createRequest('CORR-002');

        $this->transition($admin, $request, 'Needs Action');

        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => 'Pending'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Needs Action', $request->refresh()->status);
    }

    public function test_resident_submission_cannot_set_a_terminal_status(): void
    {
        $resident = $this->createResident($this->barangay, $this->purok);

        $this->withSession(['resident_id' => $resident->id])
            ->post('/resident/certificates', [
                'certificate_id' => $this->certificate->id,
                'purpose' => 'Employment',
                'status' => 'Released',
            ])
            ->assertRedirect();

        $request = CertificateRequest::where('resident_id', $resident->id)->firstOrFail();

        $this->assertSame('Pending', $request->status);
    }

    private function otherStatusesThan(string $status): array
    {
        return array_values(array_filter(
            ['Pending', 'Needs Action', 'For Payment', 'Processing', 'Ready for Claim', 'Released', 'Rejected'],
            fn (string $candidate) => $candidate !== $status,
        ));
    }

    private function attemptPayload(string $status): array
    {
        return [
            'status' => $status,
            'admin_note' => 'Attempted change',
        ];
    }

    private function snapshot(CertificateRequest $request): array
    {
        return [
            'status' => $request->status,
            'admin_note' => $request->admin_note,
            'reviewed_at' => $request->reviewed_at?->format('Y-m-d H:i:s'),
            'processing_at' => $request->processing_at?->format('Y-m-d H:i:s'),
            'ready_for_claim_at' => $request->ready_for_claim_at?->format('Y-m-d H:i:s'),
            'released_at' => $request->released_at?->format('Y-m-d H:i:s'),
            'rejected_at' => $request->rejected_at?->format('Y-m-d H:i:s'),
            'updated_at' => $request->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function assertUnchanged(CertificateRequest $request, array $before, string $status): void
    {
        $request->refresh();

        $this->assertSame($before, $this->snapshot($request));
        $this->assertSame($status, $request->status);
    }

    private function transition($admin, CertificateRequest $request, string $status): void
    {
        $this->actingAs($admin)
            ->put($this->statusUrl($request), ['status' => $status])
            ->assertRedirect();
    }

    private function statusUrl(CertificateRequest $request): string
    {
        return '/admin/certificates/'.$request->id.'/status';
    }

    private function createRequest(string $reference, array $attributes = []): CertificateRequest
    {
        // Derive a unique mobile per reference so multiple requests can share a
        // barangay without tripping the (barangay_id, mobile) unique index.
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '0917'.str_pad(
                (string) (abs(crc32($reference)) % 10000000),
                7,
                '0',
                STR_PAD_LEFT
            ),
        ]);

        return CertificateRequest::create([
            'barangay_id' => $this->barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $this->certificate->id,
            'purpose' => 'Employment',
            'fee' => 50,
            'status' => 'Pending',
            'submitted_at' => now(),
            'reference' => $reference,
            ...$attributes,
        ]);
    }
}
