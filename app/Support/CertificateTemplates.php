<?php

namespace App\Support;

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
