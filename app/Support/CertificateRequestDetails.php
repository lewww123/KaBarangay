<?php

namespace App\Support;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps a certificate catalog entry to the detail set a resident must provide,
 * and encodes those details inside the existing additional_details column.
 *
 * The envelope is deliberately versioned and whitelisted so legacy plain-text
 * notes remain readable while new submissions keep structured, renderable
 * certificate-specific values.
 */
class CertificateRequestDetails
{
    private const VERSION = 1;

    /** @var array<string, string> */
    private const TYPE_BY_CODE = [
        'residency' => 'Residency',
        'indigency' => 'Indigency',
        'delayed-psa-registration' => 'Delayed PSA Registration',
        'marriage-cohabitation' => 'Marriage Cohabitation',
        'scholarship' => 'Scholarship Certificate',
        'tricycle' => 'Tricycle',
        'sari-sari' => 'Sari-sari Store',
        'barangay-clearance' => 'Barangay Clearance',
        'retail' => 'Retail',
        'hardware' => 'Hardware Store',
        'pool-resort' => 'Pool / Resort',
    ];

    /** @var array<string, string> */
    private const TYPE_BY_NAME = [
        'certificate of residency' => 'Residency',
        'certificate of indigency' => 'Indigency',
        'delayed psa' => 'Delayed PSA Registration',
        'delayed psa registration' => 'Delayed PSA Registration',
        'marriage cohabitation' => 'Marriage Cohabitation',
        'scholarship' => 'Scholarship Certificate',
        'scholarship certificate' => 'Scholarship Certificate',
        'tricycle' => 'Tricycle',
        'sari-sari' => 'Sari-sari Store',
        'sari sari' => 'Sari-sari Store',
        'sari-sari store' => 'Sari-sari Store',
        'sari sari store' => 'Sari-sari Store',
        'barangay clearance' => 'Barangay Clearance',
        'retail' => 'Retail',
        'hardware store' => 'Hardware Store',
        'pool / resort' => 'Pool / Resort',
    ];

    /** @var array<string, string> */
    private const FIXED_PURPOSES = [
        'Residency' => 'Proof of Residency',
        'Delayed PSA Registration' => 'Delayed Registration of Birth',
        'Marriage Cohabitation' => 'Marriage Purposes',
    ];

    /**
     * Resolve the generation template type for a catalog certificate.
     */
    public static function typeForCertificate(?Model $certificate): ?string
    {
        if (! $certificate instanceof Certificate) {
            return null;
        }

        $name = self::normalize($certificate->name);
        $code = str_replace('_', '-', self::normalize($certificate->code));

        foreach (CertificateTemplates::types() as $type) {
            if ($name === self::normalize($type)) {
                return $type;
            }
        }

        return self::TYPE_BY_CODE[$code] ?? self::TYPE_BY_NAME[$name] ?? null;
    }

    public static function fixedPurpose(?string $type): ?string
    {
        return self::FIXED_PURPOSES[$type] ?? null;
    }

    public static function requiresBusinessDetails(?string $type, bool $certificateFlag): bool
    {
        return $type === 'Tricycle' || $certificateFlag;
    }

    /**
     * The certificate-specific fields collected from the resident.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     type: string,
     *     kind: string,
     *     required: bool,
     *     min?: int,
     *     max?: int,
     *     default?: string,
     *     placeholder?: string
     * }>
     */
    public static function fieldsForType(?string $type): array
    {
        return match ($type) {
            'Residency' => [
                self::numberField('years_of_residency', 'Years of Residency', 0, 120, true),
            ],
            'Delayed PSA Registration' => [
                self::textField('nationality', 'Nationality', true, 120, 'FILIPINO'),
                self::textField('birth_place', 'Birth Place', true, 120, 'PUERTO PRINCESA CITY'),
                self::textField('father_name', "Father's Name", true),
                self::textField('mother_maiden_name', "Mother's Maiden Name", true),
                self::textField('parents_marriage_details', "Parents' Marriage Details", true),
                self::numberField('residency_start_year', 'Residency Start Year', 1583, (int) date('Y'), true),
            ],
            'Marriage Cohabitation' => [
                self::textField('partner_full_name', "Partner's Full Name", true),
                self::numberField('partner_age', "Partner's Age", 1, 120, true),
                self::numberField('years_living_together', 'Years Living Together', 0, 120, true),
            ],
            'Scholarship Certificate' => [
                self::textField('school_institution', 'School / Institution', true),
                self::textField('father_name', "Father's Name"),
                self::textField('father_occupation', "Father's Occupation"),
                self::incomeField('father_monthly_income', "Father's Monthly Income"),
                self::textField('mother_name', "Mother's Name"),
                self::textField('mother_occupation', "Mother's Occupation"),
                self::incomeField('mother_monthly_income', "Mother's Monthly Income"),
            ],
            'Sari-sari Store' => [
                self::textField('business_type', 'Type of Business', true),
            ],
            'Tricycle' => [
                self::textField('franchise_no', 'Franchise No.', true),
            ],
            default => [],
        };
    }

    /**
     * Resolve a barangay certificate's configured form fields. A null schema
     * keeps the legacy type definition so existing catalog entries continue to
     * work while barangays can override their own physical form requirements.
     */
    public static function fieldsForCertificate(Certificate $certificate): array
    {
        $configuredFields = $certificate->form_schema['fields'] ?? null;

        if (is_array($configuredFields)) {
            return collect($configuredFields)
                ->filter(fn ($field) => is_array($field)
                    && isset($field['key'], $field['label'])
                    && preg_match('/^[a-z][a-z0-9_]*$/', (string) $field['key']) === 1)
                ->map(function (array $field): array {
                    $type = ($field['type'] ?? null) === 'number' ? 'number' : 'text';
                    $kind = in_array($field['kind'] ?? null, ['integer', 'numeric'], true)
                        ? $field['kind']
                        : ($type === 'number' ? 'integer' : 'text');

                    return array_filter([
                        'key' => (string) $field['key'],
                        'label' => trim((string) $field['label']),
                        'type' => $type,
                        'kind' => $kind,
                        'required' => (bool) ($field['required'] ?? false),
                        'min' => isset($field['min']) ? (int) $field['min'] : null,
                        'max' => isset($field['max']) ? (int) $field['max'] : null,
                        'default' => isset($field['default']) ? (string) $field['default'] : null,
                        'placeholder' => isset($field['placeholder']) ? (string) $field['placeholder'] : null,
                    ], static fn (mixed $value): bool => $value !== null);
                })
                ->values()
                ->all();
        }

        return self::fieldsForType(self::typeForCertificate($certificate));
    }

    /**
     * Validation rules for a resident request, derived from the certificate
     * type and whether business details are required.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(?string $type, bool $requiresBusinessDetails, ?array $fields = null): array
    {
        $fixedPurpose = self::fixedPurpose($type);

        $rules = [
            'purpose' => $fixedPurpose === null
                ? ['required', 'string', 'max:500']
                : ['nullable', 'string', 'max:500'],
            'business_name' => [
                $requiresBusinessDetails ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
            'business_address' => [
                $requiresBusinessDetails ? 'required' : 'nullable',
                'string',
                'max:1000',
            ],
            'additional_details' => ['nullable', 'string', 'max:2000'],
            'details' => ['nullable', 'array'],
        ];

        foreach ($fields ?? self::fieldsForType($type) as $field) {
            $key = 'details.'.$field['key'];

            if ($field['kind'] === 'numeric') {
                $rules[$key] = [
                    $field['required'] ? 'required' : 'nullable',
                    'numeric',
                    'min:'.($field['min'] ?? 0),
                ];

                if (isset($field['max'])) {
                    $rules[$key][] = 'max:'.$field['max'];
                }

                continue;
            }

            if ($field['kind'] === 'integer') {
                $rules[$key] = [
                    $field['required'] ? 'required' : 'nullable',
                    'integer',
                    'min:'.($field['min'] ?? 0),
                ];

                if (isset($field['max'])) {
                    $rules[$key][] = 'max:'.$field['max'];
                }

                continue;
            }

            $rules[$key] = [
                $field['required'] ? 'required' : 'nullable',
                'string',
                'max:'.($field['max'] ?? 255),
            ];
        }

        return $rules;
    }

    /**
     * Validation rules for the Clerk's review of the certificate-specific
     * values only. Purpose and business details are part of the original
     * submission and are not edited by the review endpoint.
     *
     * @return array<string, mixed>
     */
    public static function detailValidationRules(?string $type, ?array $fields = null): array
    {
        return array_filter(
            self::validationRules($type, false, $fields),
            static fn (string $key): bool => str_starts_with($key, 'details.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Keep only whitelisted fields for the certificate type.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    public static function normalizeDetails(?string $type, array $submitted, ?array $fields = null): array
    {
        $normalized = [];

        foreach ($fields ?? self::fieldsForType($type) as $field) {
            $value = $submitted[$field['key']] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $normalized[$field['key']] = $field['kind'] === 'integer'
                ? (string) (int) $value
                : $value;
        }

        return $normalized;
    }

    /**
     * Build the versioned additional_details payload.
     *
     * @param  array<string, string>  $details
     */
    public static function encode(?string $type, array $details, ?string $notes, ?array $fields = null): ?string
    {
        $details = self::normalizeDetails($type, $details, $fields);
        $notes = is_string($notes) ? trim($notes) : null;

        if ($type === null && $details === [] && ($notes === null || $notes === '')) {
            return null;
        }

        return self::payload($type, $details, $notes, $details, $notes);
    }

    /**
     * Store a Clerk's reviewed values while preserving what the Resident
     * originally submitted. The first revision fixes the original values; any
     * later revision updates the current values without replacing them.
     *
     * @param  array<string, mixed>  $details
     */
    public static function encodeRevision(
        ?string $raw,
        ?string $type,
        array $details,
        ?string $notes = null,
        ?array $fields = null,
    ): ?string {
        $current = self::decode($raw);
        $resolvedType = $current['type'] ?? $type;
        $originalFields = $current['original_fields'] !== []
            ? $current['original_fields']
            : $current['fields'];

        return self::payload(
            $resolvedType,
            self::normalizeDetails($resolvedType, $details, $fields),
            $notes ?? $current['notes'],
            $originalFields,
            $current['original_notes'] ?? $current['notes'],
        );
    }

    /**
     * Decode a stored payload. Legacy plain-text notes are preserved as notes.
     *
     * `fields` and `notes` are the current values. `original_fields` and
     * `original_notes` retain the Resident's submission once a Clerk revises
     * it; for an untouched request they equal the current values.
     *
     * @return array{
     *     type: string|null,
     *     fields: array<string, string>,
     *     notes: string|null,
     *     original_fields: array<string, string>,
     *     original_notes: string|null
     * }
     */
    public static function decode(?string $raw): array
    {
        $empty = [
            'type' => null,
            'fields' => [],
            'notes' => null,
            'original_fields' => [],
            'original_notes' => null,
        ];

        if ($raw === null || trim($raw) === '') {
            return $empty;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return [
                'type' => null,
                'fields' => [],
                'notes' => $raw,
                'original_fields' => [],
                'original_notes' => null,
            ];
        }

        $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : null;
        $fields = self::scalarFields($decoded['fields'] ?? null);
        $originalFields = self::scalarFields($decoded['original_fields'] ?? null);

        if ($originalFields === []) {
            $originalFields = $fields;
        }

        $notes = is_string($decoded['notes'] ?? null) ? $decoded['notes'] : null;
        $originalNotes = is_string($decoded['original_notes'] ?? null)
            ? $decoded['original_notes']
            : $notes;

        return [
            'type' => $type,
            'fields' => $fields,
            'notes' => $notes === '' ? null : $notes,
            'original_fields' => $originalFields,
            'original_notes' => $originalNotes === '' ? null : $originalNotes,
        ];
    }

    /**
     * Human-readable rows for request details views.
     *
     * @param  array<string, string>  $fields
     * @return array<int, array{label: string, value: string}>
     */
    public static function displayRows(?string $type, array $values, ?array $fields = null): array
    {
        $rows = [];

        foreach ($fields ?? self::fieldsForType($type) as $field) {
            $value = $values[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $rows[] = [
                'label' => $field['label'],
                'value' => $value,
            ];
        }

        return $rows;
    }

    /**
     * @return array{key: string, label: string, type: string, kind: string, required: bool, min?: int, max?: int, default?: string, placeholder?: string}
     */
    private static function textField(
        string $key,
        string $label,
        bool $required = false,
        int $max = 255,
        ?string $default = null,
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => 'text',
            'kind' => 'text',
            'required' => $required,
            'max' => $max,
            'default' => $default,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{key: string, label: string, type: string, kind: string, required: bool, min: int, max: int}
     */
    private static function numberField(
        string $key,
        string $label,
        int $min,
        int $max,
        bool $required = false,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'number',
            'kind' => 'integer',
            'required' => $required,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * @return array{key: string, label: string, type: string, kind: string, required: bool, min: int}
     */
    private static function incomeField(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'number',
            'kind' => 'numeric',
            'required' => false,
            'min' => 0,
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $originalFields
     */
    private static function payload(
        ?string $type,
        array $fields,
        ?string $notes,
        array $originalFields,
        ?string $originalNotes,
    ): ?string {
        if ($type === null
            && $fields === []
            && $originalFields === []
            && ($notes === null || $notes === '')
            && ($originalNotes === null || $originalNotes === '')) {
            return null;
        }

        return json_encode([
            'version' => self::VERSION,
            'type' => $type,
            'fields' => $fields,
            'notes' => $notes === '' ? null : $notes,
            'original_fields' => $originalFields,
            'original_notes' => $originalNotes === '' ? null : $originalNotes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * @return array<string, string>
     */
    private static function scalarFields(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fields = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_scalar($item)) {
                $fields[$key] = (string) $item;
            }
        }

        return $fields;
    }

    private static function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
