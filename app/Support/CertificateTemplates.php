<?php

namespace App\Support;

use RuntimeException;

class CertificateTemplates
{
    /**
     * Certificate types supported by the generation feature, keyed by the
     * `certificate_type` value submitted by the clerk form (and used as the
     * option value in the UI), mapped to the template file name inside
     * storage/app/templates.
     *
     * To enable a new certificate later, add the approved .docx template to
     * storage/app/templates and add an entry here. Types without a template are
     * surfaced as "Coming Soon" in the UI and rejected by the backend.
     *
     * @var array<string, string>
     */
    private const FILES = [
        'Residency' => 'residency',
        'Indigency' => 'indigency',
        'Delayed PSA Registration' => 'delayedpsa',
        'Marriage Cohabitation' => 'marriage',
        'Scholarship Certificate' => 'scholarship',
        'Tricycle' => 'tricycle',
        'Sari-sari Store' => 'sari_sari_store',
    ];

    /**
     * The exact merge-field contracts of the certificates that use the generic
     * renderer. The Delayed PSA, Marriage Cohabitation, and Residency
     * certificates own their contracts in their respective support classes.
     *
     * Scholarship family-name/occupation/income keys use a curly apostrophe
     * (U+2019) and must match the current DOCX exactly. The current template
     * has no mother's monthly-income field.
     *
     * @var array<string, array<int, string>>
     */
    private const GENERIC_TEMPLATE_KEYS = [
        'Indigency' => [
            'current_date',
            'purok',
            'purpose',
            "resident\u{2019}s_name",
            'signatory_name',
        ],
        'Scholarship Certificate' => [
            'current_date',
            "father\u{2019}s_monthly_income",
            "father\u{2019}s_name",
            "father\u{2019}s_occupation",
            "mother\u{2019}s_name",
            "mother\u{2019}s_occupation",
            'purok',
            'purpose',
            "resident\u{2019}s_name",
            'school/institution',
            'signatory_name',
        ],
        'Tricycle' => [
            'business_address',
            'business_name',
            'current_date',
            'franchise_no',
            'purok',
            'purpose',
            'resident_name',
            'signatory_name',
        ],
    ];

    /**
     * Every certificate type recognised by the generation feature.
     *
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_keys(self::FILES);
    }

    /**
     * Absolute path to the .docx template for a certificate type.
     */
    public static function path(string $type): string
    {
        return storage_path('app/templates/'.(self::FILES[$type] ?? $type).'.docx');
    }

    /**
     * Whether a template file is present in this environment for the type.
     */
    public static function isAvailable(string $type): bool
    {
        return isset(self::FILES[$type]) && is_file(self::path($type));
    }

    /**
     * Certificate types whose template file is present and can be generated.
     *
     * @return array<int, string>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::types(), [self::class, 'isAvailable']));
    }

    /**
     * The approved merge-field contract for a generic certificate type.
     *
     * @return array<int, string>|null
     */
    public static function genericTemplateKeys(string $type): ?array
    {
        return self::GENERIC_TEMPLATE_KEYS[$type] ?? null;
    }

    /**
     * Ensure a generic certificate template exposes exactly its approved merge
     * fields.
     *
     * A template that is missing a key would silently leave an unresolved
     * `${...}` placeholder in the issued document, and a template with an
     * extra key would leave a placeholder that this feature never writes. Both
     * are rejected before a document is rendered.
     *
     * @throws RuntimeException when the template does not match its contract.
     */
    public static function assertTemplateContract(string $type, string $templatePath): void
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException(
                "The {$type} template is not provisioned."
            );
        }

        $expected = self::genericTemplateKeys($type);

        if ($expected === null) {
            throw new RuntimeException(
                "The {$type} template has no approved merge-field contract."
            );
        }

        $variables = CertificatePlaceholders::templateVariables($templatePath);
        sort($variables);

        sort($expected);

        if ($variables !== $expected) {
            throw new RuntimeException(
                "The {$type} template does not match the approved merge-field contract."
            );
        }
    }

    /**
     * Availability flags consumed by the clerk certificate form.
     *
     * @return array<int, array{value: string, available: bool}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            static fn (string $type): array => [
                'value' => $type,
                'available' => self::isAvailable($type),
            ],
            self::types(),
        ));
    }
}
