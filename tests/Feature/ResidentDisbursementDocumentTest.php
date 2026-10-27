<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Purok;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentDisbursementDocumentTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    private Barangay $barangay;

    private Barangay $other;

    private Purok $purok;

    private Purok $otherPurok;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->barangay = $this->createBarangay('Barangay One', 'BRGY-ONE');
        $this->other = $this->createBarangay('Barangay Two', 'BRGY-TWO');
        $this->purok = $this->createPurok($this->barangay, 'Purok 1');
        $this->otherPurok = $this->createPurok($this->other, 'Purok 2');

        $this->resident = $this->createResident(
            $this->barangay,
            $this->purok,
            ['mobile' => '09190000001']
        );
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    public function test_index_lists_only_published_nonarchived_documents_from_own_barangay(): void
    {
        $published = $this->createDisbursementDocument($this->barangay);

        $this->createDisbursementDocument($this->barangay, [
            'title' => 'Draft Payout',
            'is_published' => false,
            'published_at' => null,
        ]);

        $this->createDisbursementDocument($this->barangay, [
            'title' => 'Archived Payout',
            'is_published' => false,
            'archived_at' => now(),
        ]);

        $this->createDisbursementDocument($this->other);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/Disbursement')
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $published->id)
                ->missing('documents.data.0.path')
                ->missing('documents.data.0.disk')
                ->missing('documents.data.0.sha256')
                ->missing('documents.data.0.publish_url')
                ->missing('documents.data.0.archive_url'));
    }

    public function test_resident_can_open_published_document_from_own_barangay(): void
    {
        $document = $this->createDisbursementDocument($this->barangay);
        Storage::disk('local')->put($document->path, $this->pdfBytes());

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$document->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/DisbursementShow')
                ->where('document.id', $document->id)
                ->where('document.is_pdf', true)
                ->missing('document.path')
                ->missing('document.disk')
                ->missing('document.sha256')
                ->missing('document.uploader_name')
                ->missing('document.publish_url')
                ->missing('document.archive_url'));
    }

    public function test_resident_cannot_open_draft_archived_or_foreign_documents(): void
    {
        $draft = $this->createDisbursementDocument($this->barangay, [
            'is_published' => false,
            'published_at' => null,
        ]);
        $archived = $this->createDisbursementDocument($this->barangay, [
            'is_published' => false,
            'archived_at' => now(),
        ]);
        $foreign = $this->createDisbursementDocument($this->other);

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$draft->id)
            ->assertNotFound();

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$archived->id)
            ->assertNotFound();

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_resident_file_access_is_limited_to_visible_documents(): void
    {
        $published = $this->createDisbursementDocument($this->barangay);
        Storage::disk('local')->put($published->path, $this->pdfBytes());

        $draft = $this->createDisbursementDocument($this->barangay, [
            'is_published' => false,
            'published_at' => null,
        ]);
        Storage::disk('local')->put($draft->path, $this->pdfBytes());

        $foreign = $this->createDisbursementDocument($this->other);
        Storage::disk('local')->put($foreign->path, $this->pdfBytes());

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$published->id.'/file')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $download = $this->withSession([
            'resident_id' => $this->resident->id,
        ])->get('/resident/disbursement/'.$published->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString(
            'attachment',
            strtolower($download->headers->get('content-disposition'))
        );

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$draft->id.'/file')
            ->assertNotFound();

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$foreign->id.'/download')
            ->assertNotFound();
    }

    public function test_csv_preview_is_bounded_and_reports_truncation(): void
    {
        $columns = array_map(
            fn (int $index) => 'Column '.$index,
            range(1, 31)
        );
        $rows = [];

        for ($index = 0; $index < 201; $index++) {
            $rows[] = implode(',', array_fill(0, 31, 'value'));
        }

        $document = $this->createDisbursementDocument($this->barangay, [
            'original_name' => 'beneficiaries.csv',
            'mime_type' => 'text/csv',
            'path' => 'disbursement-documents/'.$this->barangay->id.'/beneficiaries.csv',
        ]);

        Storage::disk('local')->put(
            $document->path,
            implode(',', $columns)."\n".implode("\n", $rows)."\n"
        );

        $this->withSession(['resident_id' => $this->resident->id])
            ->get('/resident/disbursement/'.$document->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Resident/DisbursementShow')
                ->where('document.is_csv', true)
                ->has('csvPreview.columns', 30)
                ->has('csvPreview.rows', 200)
                ->where('csvPreview.row_limit', 200)
                ->where('csvPreview.column_limit', 30)
                ->where('csvPreview.rows_truncated', true)
                ->where('csvPreview.columns_truncated', true)
                ->where('csvPreview.cells_truncated', false)
                ->where('csvPreview.error', null));
    }

    public function test_guest_is_redirected_to_resident_login(): void
    {
        $this->get('/resident/disbursement')
            ->assertRedirect(route('resident.login'));

        $document = $this->createDisbursementDocument($this->barangay);

        $this->get('/resident/disbursement/'.$document->id)
            ->assertRedirect(route('resident.login'));
    }
}
