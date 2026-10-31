<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\OrgChartPosition;
use App\Models\Purok;
use App\Support\CertificateTemplates;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;
use ZipArchive;

class CertificateIssuanceGenerationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Purok $purok;

    private Certificate $certificate;

    private int $residentMobileSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');

        $this->certificate = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'TRICYCLE',
            'name' => 'Tricycle',
            'fee' => 300,
            'is_active' => true,
        ]);

        $creator = $this->createStaff($this->barangay, 'clerk');
        OrgChartPosition::create([
            'barangay_id' => $this->barangay->id,
            'title' => 'Punong Barangay',
            'assigned_person' => 'HON. TEST',
            'status' => 'published',
            'created_by' => $creator->id,
        ]);
    }

    public function test_successful_generation_persists_exactly_one_issuance_and_downloads(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $response = $this->actingAs($clerk)->post('/clerk/generate-certificate', $this->validPayload());

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);

        $this->assertSame(1, CertificateIssuance::query()->count());

        $issuance = CertificateIssuance::query()->first();
        $this->assertMatchesRegularExpression('/filename="?'.preg_quote('Certificate_'.$issuance->reference.'.docx', '/').'"?(?:;|$)/', $disposition);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
        $this->assertSame('Tricycle', $issuance->certificate_type);
        $this->assertSame('CTRL-2026-0001', $issuance->control_no);
        $this->assertSame('issued', $issuance->status);
        $response->assertHeader('X-Certificate-Issuance-Status', 'issued');

        $docxPath = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($docxPath);
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($docxPath));
        $this->assertNotFalse($archive->locateName('word/document.xml'));
        $archive->close();
    }

    public function test_reference_format_is_issue_year_eight_alnum_and_unique(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($clerk)->post('/clerk/generate-certificate', $this->validPayload([
                'control_no' => 'CTRL-2026-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
            ]))->assertOk();
        }

        $references = CertificateIssuance::query()->pluck('reference')->all();

        $this->assertCount(3, $references);
        $this->assertCount(3, array_unique($references));

        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^ISSUE-\d{4}-[A-Z0-9]{8}$/', $reference);
        }
    }

    public function test_control_numbers_are_unique_within_a_barangay_but_may_repeat_across_barangays(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $payload = $this->validPayload(['control_no' => 'CTRL-2026-0088']);

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $payload)
            ->assertOk();

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $payload)
            ->assertRedirect('/clerk/certificates')
            ->assertSessionHasErrors('control_no');

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherClerk = $this->createStaff($otherBarangay, 'clerk');
        OrgChartPosition::create([
            'barangay_id' => $otherBarangay->id,
            'title' => 'Punong Barangay',
            'assigned_person' => 'HON. TEST',
            'status' => 'published',
            'created_by' => $otherClerk->id,
        ]);

        $this->actingAs($otherClerk)
            ->post('/clerk/generate-certificate', $payload)
            ->assertOk();

        $this->assertSame(2, CertificateIssuance::query()->count());
        $this->assertSame(2, CertificateIssuance::query()
            ->where('control_no', 'CTRL-2026-0088')
            ->count());
    }

    public function test_barangay_and_issuer_ids_come_from_the_authenticated_user(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                // Client attempts to spoof these; they must be ignored.
                'barangay_id' => 999999,
                'issued_by' => 888888,
            ]))
            ->assertOk();

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame($this->barangay->id, $issuance->barangay_id);
        $this->assertSame($clerk->id, $issuance->issued_by);
    }

    public function test_unpublished_or_foreign_signatory_is_rejected(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'signatory_name' => 'Unapproved Person',
                'signatory_title' => 'Punong Barangay',
            ]))
            ->assertRedirect('/clerk/certificates')
            ->assertSessionHasErrors('signatory_name');

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_linked_request_snapshots_the_trusted_fee(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest(300);

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'certificate_request_id' => $request->id,
                // Spoofed client fee must never be the history value.
                'fee' => '5',
            ]))
            ->assertOk();

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame($request->id, $issuance->certificate_request_id);
        $this->assertSame('300.00', $issuance->fee);
    }

    public function test_manual_generation_stores_null_fee_and_snapshots(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'purok' => 'Purok 7',
                'fee' => '999',
            ]))
            ->assertOk();

        $issuance = CertificateIssuance::query()->first();

        $this->assertNull($issuance->certificate_request_id);
        $this->assertNull($issuance->resident_id);
        $this->assertNull($issuance->fee);
        $this->assertSame('Purok 7', $issuance->purok);
        $this->assertSame('Juan Dela Cruz', $issuance->resident_name);
    }

    public function test_registered_resident_snapshot_is_resolved_server_side(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $resident = $this->createResident($this->barangay, $this->purok, [
            'mobile' => '09170000099',
            'resident_number' => 'RES-SNAP-1',
        ]);

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'resident_id' => $resident->id,
            ]))
            ->assertOk();

        $issuance = CertificateIssuance::query()->first();

        $this->assertSame($resident->id, $issuance->resident_id);
        $this->assertSame('RES-SNAP-1', $issuance->resident_number);
    }

    public function test_cross_barangay_request_link_is_rejected_and_creates_no_issuance(): void
    {
        $this->requireTricycleTemplate();

        $otherBarangay = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $otherPurok = $this->createPurok($otherBarangay, 'Purok 2');
        $otherCertificate = Certificate::create([
            'barangay_id' => $otherBarangay->id,
            'code' => 'TRICYCLE',
            'name' => 'Tricycle',
            'fee' => 300,
            'is_active' => true,
        ]);
        $foreignRequest = $this->createRequestFor($otherBarangay, $otherPurok, $otherCertificate);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'certificate_request_id' => $foreignRequest->id,
            ]))
            ->assertRedirect('/clerk/certificates')
            ->assertSessionHasErrors('certificate_request_id');

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_request_type_mismatch_is_rejected_and_creates_no_issuance(): void
    {
        $this->requireTricycleTemplate();

        $indigency = Certificate::create([
            'barangay_id' => $this->barangay->id,
            'code' => 'INDIGENCY',
            'name' => 'Indigency',
            'fee' => 50,
            'is_active' => true,
        ]);
        $request = $this->createRequestFor($this->barangay, $this->purok, $indigency);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'certificate_request_id' => $request->id,
            ]))
            ->assertSessionHasErrors('certificate_request_id');

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_duplicate_request_issuance_is_rejected_without_a_second_record(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest(300);

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload([
                'certificate_request_id' => $request->id,
            ]))
            ->assertOk();

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'certificate_request_id' => $request->id,
            ]))
            ->assertRedirect('/clerk/certificates')
            ->assertSessionHasErrors('certificate_request_id');

        $this->assertSame(1, CertificateIssuance::query()->count());
    }

    public function test_unavailable_template_generation_creates_no_issuance(): void
    {
        $unavailable = array_values(
            array_diff(CertificateTemplates::types(), CertificateTemplates::available())
        );

        if ($unavailable === []) {
            $this->markTestSkipped('Every certificate template is present in this environment.');
        }

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload([
                'certificate_type' => $unavailable[0],
            ]))
            ->assertSessionHasErrors('certificate_type');

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_audit_event_is_recorded_with_allowlisted_context(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)->post('/clerk/generate-certificate', $this->validPayload())->assertOk();

        $issuance = CertificateIssuance::query()->first();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'certificate_issuance.created',
            'barangay_id' => $this->barangay->id,
            'actor_id' => $clerk->id,
            'entity_type' => 'certificate_issuance',
            'entity_id' => $issuance->id,
        ]);

        $log = AuditLog::query()->where('event', 'certificate_issuance.created')->first();
        $this->assertSame(['entity_reference' => $issuance->reference], $log->context);
        // No resident PII or fee leaks into the audit context.
        $this->assertArrayNotHasKey('resident_name', $log->context);
        $this->assertArrayNotHasKey('fee', $log->context);
    }

    public function test_unauthenticated_access_redirects(): void
    {
        $this->post('/clerk/generate-certificate', $this->validPayload())->assertRedirect(route('login'));
        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_unauthorized_role_is_rejected(): void
    {
        $senior = $this->createStaff($this->barangay, 'senior');

        $this->actingAs($senior)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertStatus(403);

        $this->assertSame(0, CertificateIssuance::query()->count());
    }

    public function test_generation_failure_records_no_issuance(): void
    {
        // Point a currently-missing template path at an invalid .docx so the
        // document builder throws after validation passes. The file is removed
        // again in teardown.
        $type = 'Residency';
        $path = CertificateTemplates::path($type);

        if (is_file($path)) {
            $this->markTestSkipped('The Residency template already exists in this environment.');
        }

        file_put_contents($path, 'not a real docx');

        try {
            $clerk = $this->createStaff($this->barangay, 'clerk');

            $this->actingAs($clerk)
                ->from('/clerk/certificates')
                ->post('/clerk/generate-certificate', $this->validPayload([
                    'certificate_type' => $type,
                ]))
                ->assertRedirect('/clerk/certificates')
                ->assertSessionHasErrors('certificate_type');

            $this->assertSame(0, CertificateIssuance::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_persistence_failure_returns_controlled_error_and_cleans_up(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        // Simulate a write failure at the Eloquent lifecycle boundary. The
        // controller must not report success and must not leave a record.
        CertificateIssuance::creating(function (): void {
            throw new RuntimeException('simulated persistence failure');
        });

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->atLeast()->once();

        $this->app->instance(ExceptionHandler::class, $handler);

        $before = glob(storage_path('app/public/Certificate_*.docx')) ?: [];

        $this->actingAs($clerk)
            ->from('/clerk/certificates')
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertRedirect('/clerk/certificates')
            ->assertSessionHasErrors('certificate_type');

        $this->assertSame(0, CertificateIssuance::query()->count());

        $after = glob(storage_path('app/public/Certificate_*.docx')) ?: [];
        $this->assertSame($before, $after, 'A failed issuance must not leave its generated document behind.');
    }

    public function test_audit_writer_failure_does_not_cancel_the_issuance(): void
    {
        $this->requireTricycleTemplate();

        $clerk = $this->createStaff($this->barangay, 'clerk');

        AuditLog::creating(function (): void {
            throw new RuntimeException('simulated audit failure');
        });

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->atLeast()->once();

        $this->app->instance(ExceptionHandler::class, $handler);

        $this->actingAs($clerk)
            ->post('/clerk/generate-certificate', $this->validPayload())
            ->assertOk();

        $this->assertSame(1, CertificateIssuance::query()->count());
    }

    public function test_preview_uses_the_real_template_without_issuing_or_advancing_a_request(): void
    {
        $this->requireTricycleTemplate();
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $request = $this->createRequest(300);
        $auditCount = AuditLog::query()->count();

        $response = $this->actingAs($clerk)->post('/clerk/certificates/preview', $this->validPayload([
            'certificate_request_id' => $request->id,
        ]))->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertSame(0, CertificateIssuance::query()->count());
        $this->assertSame('Pending', $request->fresh()->status);
        $this->assertSame($auditCount, AuditLog::query()->count());

        $original = new ZipArchive;
        $preview = new ZipArchive;
        $original->open(CertificateTemplates::path('Tricycle'));
        $path = $response->baseResponse->getFile()->getPathname();
        $preview->open($path);
        try {
            for ($index = 0; $index < $original->numFiles; $index++) {
                $name = $original->getNameIndex($index);
                if (str_starts_with($name, 'word/media/') || $name === 'word/styles.xml') {
                    $this->assertSame($original->getFromName($name), $preview->getFromName($name), 'Preview must retain original template artwork/styles.');
                }
            }
        } finally {
            $original->close();
            $preview->close();
            @unlink($path);
        }
    }

    public function test_manual_purok_and_dynamic_text_are_saved_and_immediately_listed_as_issued(): void
    {
        $this->requireTricycleTemplate();
        $clerk = $this->createStaff($this->barangay, 'clerk');
        $response = $this->actingAs($clerk)->post('/clerk/generate-certificate', $this->validPayload([
            'purok' => 'Purok A & B',
            'purpose' => 'Permit for {RESIDENT_PUROK}',
            'business_address' => '{PUROK}, San Jose',
        ]))->assertOk();
        $issuance = CertificateIssuance::firstOrFail();
        $this->assertDatabaseHas('certificate_issuances', ['id' => $issuance->id, 'status' => 'issued', 'purok' => 'Purok A & B']);
        $this->get('/clerk/certificates?tab=issued')->assertOk()->assertInertia(fn ($page) => $page
            ->where('issuances.data.0.id', $issuance->id)
            ->where('issuances.data.0.status', 'issued')
            ->where('issuances.data.0.purok', 'Purok A & B'));

        $archive = new ZipArchive;
        $path = $response->baseResponse->getFile()->getPathname();
        $archive->open($path);
        try {
            $xml = new \DOMDocument;
            $this->assertTrue($xml->loadXML($archive->getFromName('word/document.xml'), LIBXML_NONET));
            $this->assertStringContainsString('PERMIT FOR PUROK A & B', $xml->textContent);
            $this->assertStringNotContainsString('{PUROK}', $xml->textContent);
            $this->assertStringNotContainsString('{RESIDENT_PUROK}', $xml->textContent);
        } finally {
            $archive->close();
            @unlink($path);
        }
    }

    public function test_preview_and_generation_share_the_same_clerk_only_authorization(): void
    {
        $this->post('/clerk/certificates/preview', [])->assertRedirect(route('login'));
        $admin = $this->createStaff($this->barangay, 'admin');
        $this->actingAs($admin)->post('/clerk/certificates/preview', [])->assertForbidden();
        $foreignClerk = $this->createStaff($this->createBarangay('Other', 'OTHER'), 'clerk');
        $request = $this->createRequest(0);
        $this->actingAs($foreignClerk)->postJson('/clerk/certificates/preview', $this->validPayload([
            'certificate_request_id' => $request->id, 'signatory_name' => '', 'signatory_title' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors('certificate_request_id');
        $this->assertSame(0, CertificateIssuance::count());
    }

    private function requireTricycleTemplate(): void
    {
        if (! CertificateTemplates::isAvailable('Tricycle')) {
            $this->markTestSkipped('The Tricycle template is not present in this environment.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'certificate_type' => 'Tricycle',
            'resident_name' => 'Juan Dela Cruz',
            'purok' => 'Purok 1',
            'purpose' => 'Employment',
            'control_no' => 'CTRL-2026-0001',
            'fee' => '300',
            'franchise_no' => 'FR-123',
            'business_name' => 'JDC Trading',
            'business_address' => 'Purok 1',
            'signatory_name' => 'HON. TEST',
            'signatory_title' => 'Punong Barangay',
        ], $overrides);
    }

    private function createRequest(int $fee): CertificateRequest
    {
        return $this->createRequestFor($this->barangay, $this->purok, $this->certificate, $fee);
    }

    private function createRequestFor(
        Barangay $barangay,
        Purok $purok,
        Certificate $certificate,
        ?int $fee = null,
    ): CertificateRequest {
        $resident = $this->createResident($barangay, $purok, [
            'mobile' => '0917'.str_pad((string) $this->residentMobileSequence++, 7, '0', STR_PAD_LEFT),
        ]);

        return CertificateRequest::create([
            'barangay_id' => $barangay->id,
            'resident_id' => $resident->id,
            'certificate_id' => $certificate->id,
            'reference' => 'CERT-2026-'.strtoupper(Str::random(8)),
            'purpose' => 'Employment',
            'fee' => $fee ?? $certificate->fee,
            'status' => 'Pending',
            'submitted_at' => now(),
        ]);
    }
}
