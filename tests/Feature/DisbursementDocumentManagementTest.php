<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DisbursementDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class DisbursementDocumentManagementTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function pdfUpload(string $name = 'payout.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            $this->pdfBytes()
        );
    }

    public function test_index_lists_only_own_barangay_documents_and_hides_private_metadata(): void
    {
        $published = $this->createDisbursementDocument($this->barangay);
        $draft = $this->createDisbursementDocument($this->barangay, [
            'title' => 'Draft Payout',
            'is_published' => false,
            'published_at' => null,
        ]);
        $this->createDisbursementDocument($this->other);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/disbursements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Disbursements/Index')
                ->has('documents.data', 2)
                ->where('documents.data.0.id', $draft->id)
                ->where('documents.data.1.id', $published->id)
                ->where('stats.total', 2)
                ->where('stats.draft', 1)
                ->where('stats.published', 1)
                ->where('stats.archived', 0)
                ->missing('documents.data.0.path')
                ->missing('documents.data.0.disk')
                ->missing('documents.data.0.sha256'));
    }

    public function test_index_filters_documents_by_status_and_search(): void
    {
        $published = $this->createDisbursementDocument($this->barangay, [
            'title' => 'Educational Assistance Payout',
        ]);
        $this->createDisbursementDocument($this->barangay, [
            'title' => 'Draft Medical Payout',
            'is_published' => false,
            'published_at' => null,
        ]);

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/disbursements?status=published')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $published->id));

        $this->actingAs($clerk)
            ->get('/clerk/disbursements?search=Medical')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Draft Medical Payout'));
    }

    public function test_admin_cannot_access_clerk_document_management(): void
    {
        $admin = $this->createStaff($this->barangay, 'admin');
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($admin)
            ->get('/clerk/disbursements')
            ->assertForbidden();

        $this->actingAs($clerk)
            ->get('/clerk/disbursements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Disbursements/Index'));
    }

    public function test_guests_and_inactive_staff_cannot_access_management(): void
    {
        $this->get('/clerk/disbursements')->assertRedirect(route('login'));

        $inactive = $this->createStaff($this->barangay, 'clerk', [
            'status' => 'inactive',
        ]);

        $this->actingAs($inactive)
            ->get('/clerk/disbursements')
            ->assertForbidden();
    }

    public function test_pdf_upload_creates_private_draft_and_audit_event(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements', [
                'title' => 'September Educational Assistance',
                'description' => 'Payout record for September.',
                'document' => $this->pdfUpload('september-payout.pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $document = DisbursementDocument::query()->firstOrFail();

        $this->assertSame($this->barangay->id, $document->barangay_id);
        $this->assertSame($clerk->id, $document->uploaded_by);
        $this->assertSame('September Educational Assistance', $document->title);
        $this->assertSame('september-payout.pdf', $document->original_name);
        $this->assertSame('local', $document->disk);
        $this->assertFalse($document->is_published);
        $this->assertNull($document->published_at);
        $this->assertNull($document->archived_at);
        $this->assertSame(
            hash('sha256', $this->pdfBytes()),
            $document->sha256
        );
        $this->assertStringStartsWith(
            'disbursement-documents/'.$this->barangay->id.'/',
            $document->path
        );
        $this->assertStringEndsWith('.pdf', $document->path);
        $this->assertTrue(Storage::disk('local')->exists($document->path));

        $log = DB::table('audit_logs')
            ->where('event', 'disbursement_document.uploaded')
            ->where('entity_id', $document->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($clerk->id, (int) $log->actor_id);
        $this->assertSame($this->barangay->id, (int) $log->barangay_id);
        $this->assertSame('disbursement_document', $log->entity_type);
        $this->assertSame(
            ['new_status' => 'draft'],
            json_decode($log->context, true)
        );
    }

    public function test_csv_upload_is_stored_as_a_draft(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements', [
                'title' => 'Beneficiary Payout List',
                'document' => UploadedFile::fake()->createWithContent(
                    'beneficiaries.csv',
                    "name,amount\nJuan Dela Cruz,1500\n"
                ),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $document = DisbursementDocument::query()->firstOrFail();

        $this->assertSame('beneficiaries.csv', $document->original_name);
        $this->assertFalse($document->is_published);
        $this->assertStringEndsWith('.csv', $document->path);
        $this->assertTrue(Storage::disk('local')->exists($document->path));
    }

    public function test_upload_rejects_unsupported_invalid_and_oversized_files(): void
    {
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements', [
                'title' => 'Unsupported',
                'document' => UploadedFile::fake()->createWithContent(
                    'notes.txt',
                    'plain text'
                ),
            ])
            ->assertSessionHasErrors('document');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements', [
                'title' => 'Fake PDF',
                'document' => UploadedFile::fake()->create(
                    'fake.pdf',
                    10,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasErrors('document');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements', [
                'title' => 'Too Large',
                'document' => UploadedFile::fake()->create(
                    'large.pdf',
                    5121,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, DisbursementDocument::count());
    }

    public function test_cross_barangay_document_routes_return_404(): void
    {
        $foreign = $this->createDisbursementDocument($this->other, [
            'is_published' => false,
            'published_at' => null,
        ]);
        Storage::disk('local')->put($foreign->path, $this->pdfBytes());

        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$foreign->id)
            ->assertNotFound();

        $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$foreign->id.'/file')
            ->assertNotFound();

        $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$foreign->id.'/download')
            ->assertNotFound();

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$foreign->id.'/publish')
            ->assertNotFound();

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$foreign->id.'/archive')
            ->assertNotFound();
    }

    public function test_publish_sets_state_and_writes_one_audit_event(): void
    {
        $document = $this->createDisbursementDocument($this->barangay, [
            'is_published' => false,
            'published_at' => null,
        ]);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$document->id.'/publish')
            ->assertRedirect();

        $document->refresh();

        $this->assertTrue($document->is_published);
        $this->assertNotNull($document->published_at);

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$document->id.'/publish')
            ->assertRedirect();

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'disbursement_document.published')
                ->where('entity_id', $document->id)
                ->count()
        );
    }

    public function test_archived_document_cannot_be_published(): void
    {
        $document = $this->createDisbursementDocument($this->barangay, [
            'is_published' => false,
            'published_at' => null,
            'archived_at' => now(),
        ]);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$document->id.'/publish')
            ->assertSessionHasErrors('document');

        $document->refresh();

        $this->assertFalse($document->is_published);
        $this->assertNull($document->published_at);
        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->where('event', 'disbursement_document.published')
                ->count()
        );
    }

    public function test_archive_unpublishes_and_is_terminal(): void
    {
        $document = $this->createDisbursementDocument($this->barangay);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$document->id.'/archive')
            ->assertRedirect();

        $document->refresh();

        $this->assertFalse($document->is_published);
        $this->assertNotNull($document->archived_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'disbursement_document.archived',
            'actor_id' => $clerk->id,
            'entity_type' => 'disbursement_document',
            'entity_id' => $document->id,
        ]);

        $this->actingAs($clerk)
            ->post('/clerk/disbursements/'.$document->id.'/archive')
            ->assertRedirect();

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'disbursement_document.archived')
                ->where('entity_id', $document->id)
                ->count()
        );
    }

    public function test_staff_can_preview_download_and_open_the_original_file_safely(): void
    {
        $document = $this->createDisbursementDocument($this->barangay);
        Storage::disk('local')->put($document->path, $this->pdfBytes());
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$document->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Clerk/Disbursements/Show')
                ->where('document.id', $document->id)
                ->where('document.is_pdf', true)
                ->missing('document.path')
                ->missing('document.disk')
                ->missing('document.sha256'));

        $inline = $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$document->id.'/file')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = strtolower(
            (string) $inline->headers->get('cache-control')
        );

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $this->assertStringContainsString(
            'inline',
            strtolower($inline->headers->get('content-disposition'))
        );

        $download = $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$document->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString(
            'attachment',
            strtolower($download->headers->get('content-disposition'))
        );
    }

    public function test_missing_document_file_returns_404(): void
    {
        $document = $this->createDisbursementDocument($this->barangay);
        $clerk = $this->createStaff($this->barangay, 'clerk');

        $this->actingAs($clerk)
            ->get('/clerk/disbursements/'.$document->id.'/file')
            ->assertNotFound();
    }
}
