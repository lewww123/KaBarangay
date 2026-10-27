<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Support\CertificateRequestDetails;
use Tests\TestCase;

class CertificateRequestDetailsTest extends TestCase
{
    public function test_catalog_names_and_codes_map_to_template_types(): void
    {
        $this->assertSame(
            'Residency',
            CertificateRequestDetails::typeForCertificate(
                new Certificate(['code' => 'residency', 'name' => 'Certificate of Residency']),
            ),
        );

        $this->assertSame(
            'Indigency',
            CertificateRequestDetails::typeForCertificate(
                new Certificate(['code' => 'BRGY-INDIGENCY', 'name' => 'Certificate of Indigency']),
            ),
        );

        $this->assertSame(
            'Sari-sari Store',
            CertificateRequestDetails::typeForCertificate(
                new Certificate(['code' => 'STORE', 'name' => 'Sari-Sari Store']),
            ),
        );

        $this->assertNull(
            CertificateRequestDetails::typeForCertificate(
                new Certificate(['code' => 'BRGY-CLEARANCE', 'name' => 'Barangay Clearance']),
            ),
        );
    }

    public function test_fixed_purposes_match_the_generation_templates(): void
    {
        $this->assertSame(
            'Proof of Residency',
            CertificateRequestDetails::fixedPurpose('Residency'),
        );
        $this->assertSame(
            'Delayed Registration of Birth',
            CertificateRequestDetails::fixedPurpose('Delayed PSA Registration'),
        );
        $this->assertSame(
            'Marriage Purposes',
            CertificateRequestDetails::fixedPurpose('Marriage Cohabitation'),
        );
        $this->assertNull(CertificateRequestDetails::fixedPurpose('Tricycle'));
    }

    public function test_detail_payload_round_trips_and_whitelists_fields(): void
    {
        $encoded = CertificateRequestDetails::encode(
            'Residency',
            ['years_of_residency' => '5', 'unexpected' => 'ignored'],
            '  Note  ',
        );

        $this->assertNotNull($encoded);

        $decoded = CertificateRequestDetails::decode($encoded);

        $this->assertSame('Residency', $decoded['type']);
        $this->assertSame(['years_of_residency' => '5'], $decoded['fields']);
        $this->assertSame('Note', $decoded['notes']);
        $this->assertSame($decoded['fields'], $decoded['original_fields']);
        $this->assertSame('Note', $decoded['original_notes']);
        $this->assertSame(
            [['label' => 'Years of Residency', 'value' => '5']],
            CertificateRequestDetails::displayRows($decoded['type'], $decoded['fields']),
        );
    }

    public function test_revision_preserves_the_original_resident_values(): void
    {
        $submitted = CertificateRequestDetails::encode(
            'Residency',
            ['years_of_residency' => '5'],
            'Resident note',
        );

        $revised = CertificateRequestDetails::encodeRevision(
            $submitted,
            'Residency',
            ['years_of_residency' => '7'],
        );

        $decoded = CertificateRequestDetails::decode($revised);

        $this->assertSame(['years_of_residency' => '7'], $decoded['fields']);
        $this->assertSame(['years_of_residency' => '5'], $decoded['original_fields']);
        $this->assertSame('Resident note', $decoded['notes']);
        $this->assertSame('Resident note', $decoded['original_notes']);

        $revisedAgain = CertificateRequestDetails::decode(
            CertificateRequestDetails::encodeRevision(
                $revised,
                'Residency',
                ['years_of_residency' => '10'],
            ),
        );

        $this->assertSame(['years_of_residency' => '10'], $revisedAgain['fields']);
        $this->assertSame(['years_of_residency' => '5'], $revisedAgain['original_fields']);
    }

    public function test_normalization_keeps_only_defined_fields_and_integer_strings(): void
    {
        $normalized = CertificateRequestDetails::normalizeDetails(
            'Tricycle',
            [
                'franchise_no' => '  FR-001  ',
                'unexpected' => 'ignored',
            ],
        );

        $this->assertSame(['franchise_no' => 'FR-001'], $normalized);

        $residency = CertificateRequestDetails::normalizeDetails(
            'Residency',
            ['years_of_residency' => '007'],
        );

        $this->assertSame(['years_of_residency' => '7'], $residency);
    }

    public function test_legacy_plain_text_is_returned_as_notes(): void
    {
        $decoded = CertificateRequestDetails::decode('Legacy note');

        $this->assertSame([
            'type' => null,
            'fields' => [],
            'notes' => 'Legacy note',
            'original_fields' => [],
            'original_notes' => null,
        ], $decoded);
    }
}
