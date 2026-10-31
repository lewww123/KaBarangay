<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Contract, validation rules, and value formatting for the Residency
 * certificate.
 *
 * The certificate is rendered from `storage/app/templates/residency.docx`,
 * whose merge fields are fixed. The template states its own purpose ("PROOF OF
 * RESIDENCY.") and includes a signatory field sourced from the published
 * barangay Organizational Chart.
 *
 * The resident identity on the certificate (full name, purok) comes from the
 * linked resident profile only. A client-supplied name or purok is never
 * trusted, so the certificate can only be issued against a registered,
 * active resident of the acting clerk's barangay.
 *
 * The template names the resident twice, once with a curly apostrophe and
 * once without one, so both merge fields carry the same trusted full name.
 *
 * The number of years of residency is transient clerk input. It is written
 * into the document but never persisted to the issuance history or to the
 * audit log.
 */
class ResidencyCertificate
{
    /**
     * The certificate type label, as submitted by the Clerk form and as stored
     * on the issuance history record.
     */
    public const TYPE = 'Residency';

    /**
     * The exact merge-field contract of the Residency template.
     *
     * `resident’s_name` uses a curly apostrophe (U+2019) and must match the
     * DOCX exactly. `residents_name` is the second, ASCII spelling of the same
     * name. The template also carries a signatory merge field populated from
     * the published barangay Organizational Chart.
     *
     * @var array<int, string>
     */
    public const TEMPLATE_KEYS = [
        'current_date',
        "resident\u{2019}s_name",
        'purok',
        'residents_name',
        'signatory_name',
        'years_of_residency',
    ];

    /**
     * The lowest accepted number of years of residency.
     */
    public const YEARS_OF_RESIDENCY_MIN = 0;

    /**
     * The highest accepted number of years of residency.
     */
    public const YEARS_OF_RESIDENCY_MAX = 120;

    /**
     * Whether the given certificate type is the Residency certificate.
     */
    public static function isType(mixed $type): bool
    {
        return is_string($type) && $type === self::TYPE;
    }

    /**
     * The validation rules that apply only to the Residency certificate.
     *
     * The template prints its own purpose. Its signatory comes from the
     * published barangay Organizational Chart. The resident name and purok are taken from the linked
     * resident profile, so the client-supplied values are not required either.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'purpose' => ['nullable', 'string', 'max:500'],
            'control_no' => ['nullable', 'string', 'max:100'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'signatory_name' => ['nullable', 'string', 'max:255'],
            'signatory_title' => ['nullable', 'string', 'max:255'],

            // The resident identity is read from the linked resident profile.
            'resident_name' => ['nullable', 'string', 'max:255'],
            'purok' => ['nullable', 'string', 'max:255'],

            // The number of years of residency is transient clerk input.
            'years_of_residency' => [
                'required',
                'integer',
                'min:'.self::YEARS_OF_RESIDENCY_MIN,
                'max:'.self::YEARS_OF_RESIDENCY_MAX,
            ],
        ];
    }

    /**
     * Ensure the provisioned template exposes exactly the approved merge
     * fields.
     *
     * A template that is missing a key would silently leave an unresolved
     * `${...}` placeholder in the issued document, and a template with an
     * extra key would leave a placeholder that this feature never writes. Both
     * are rejected before a document is rendered.
     *
     * @throws RuntimeException when the template does not match the contract.
     */
    public static function assertTemplateContract(string $templatePath): void
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException(
                'The Residency template is not provisioned.'
            );
        }

        // PHPWord repairs the macros that the template splits across Word runs
        // (the proofing markers) while it reads the document, so the variable
        // list is the list the merge will actually write to.
        $variables = CertificatePlaceholders::templateVariables($templatePath);
        sort($variables);

        $expected = self::TEMPLATE_KEYS;
        sort($expected);

        if ($variables !== $expected) {
            throw new RuntimeException(
                'The Residency template does not match the approved merge-field contract.'
            );
        }
    }

    /**
     * Resolve the final Residency values.
     *
     * The resident name and purok come from the linked resident profile; a
     * client-supplied value is never used for them. The number of years of
     * residency is clerk-entered and transient.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|int|null>
     */
    public static function resolveValues(array $validated, ?Resident $resident): array
    {
        return [
            'resident_name' => self::text($resident?->full_name),
            'purok' => self::text($resident?->purok?->name),
            'years_of_residency' => self::wholeNumber(
                $validated['years_of_residency'] ?? null,
                self::YEARS_OF_RESIDENCY_MIN,
                self::YEARS_OF_RESIDENCY_MAX
            ),
            'signatory_name' => self::text($validated['signatory_name'] ?? null),
        ];
    }

    /**
     * Ensure every required Residency value was resolved.
     *
     * A missing or malformed value is reported as a field-level validation
     * error so the Clerk is told exactly which field still has to be supplied.
     *
     * @param  array<string, string|int|null>  $values
     *
     * @throws ValidationException when a required value is missing or invalid.
     */
    public static function assertResolvedValuesAreComplete(array $values): void
    {
        $errors = [];

        if (($values['resident_name'] ?? '') === '') {
            $errors['resident_id'] =
                'A registered resident must be selected for a Residency certificate.';
        }

        if (($values['purok'] ?? '') === '') {
            $errors['purok'] = 'The purok of the selected resident is required.';
        }

        if (($values['years_of_residency'] ?? null) === null) {
            $errors['years_of_residency'] = 'The number of years of residency must be a whole number between '
                .self::YEARS_OF_RESIDENCY_MIN.' and '.self::YEARS_OF_RESIDENCY_MAX.'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Map the resolved values onto the template's merge fields.
     *
     * The template prints its institutional text in upper case, so the
     * resident name and the purok are upper cased as well. The template names
     * the resident twice, with a curly apostrophe and without one, so both
     * merge fields carry the same trusted name. The template supplies no
     * "PUROK" word of its own, so the merged purok value carries that prefix.
     *
     * @param  array<string, string|int|null>  $values
     * @return array<string, string>
     */
    public static function templateValues(array $values): array
    {
        $residentName = mb_strtoupper((string) ($values['resident_name'] ?? ''));

        return [
            "resident\u{2019}s_name" => $residentName,
            'residents_name' => $residentName,
            'purok' => self::formatPurok((string) ($values['purok'] ?? '')),
            'years_of_residency' => (string) ($values['years_of_residency'] ?? ''),
            'current_date' => self::formatIssueDate(),
            'signatory_name' => mb_strtoupper((string) ($values['signatory_name'] ?? '')),
        ];
    }

    /**
     * Render the purok the way the template reads it.
     *
     * The template writes "A bona fide resident of ${purok}", so the merged
     * value supplies the "PUROK" word itself.
     */
    public static function formatPurok(string $purok): string
    {
        $name = trim($purok);

        if ($name === '') {
            return '';
        }

        return preg_match('/^purok\b/i', $name) ? mb_strtoupper($name) : 'PUROK '.mb_strtoupper($name);
    }

    /**
     * Render the certificate's issue date in the formal long form, the same
     * form the other certificates use for their own issue date.
     */
    public static function formatIssueDate(): string
    {
        return date('jS \d\a\y \o\f F Y');
    }

    /**
     * Trim a submitted value to a plain string.
     */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Read a whole number that is inside the given range.
     *
     * A fractional, negative, out-of-range, or malformed value is rejected
     * (null) instead of being coerced into a valid one.
     */
    private static function wholeNumber(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $number = (int) trim($value);
        } else {
            return null;
        }

        if ($number < $min || $number > $max) {
            return null;
        }

        return $number;
    }
}
