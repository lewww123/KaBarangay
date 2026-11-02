<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Contract, validation rules, and value formatting for the Delayed PSA
 * Registration certificate.
 *
 * The certificate is rendered from `storage/app/templates/delayedpsa_dynamic.docx`,
 * whose merge fields are fixed. The template states its own purpose
 * ("DELAYED REGISTRATION OF BIRTH"). Its signatory is selected from the
 * published barangay Organizational Chart, and the generated document uses
 * that position's title in place of its fixed "Officer on duty" label.
 *
 * Values that belong to a registered resident (date of birth, sex, purok) are
 * taken from the linked resident profile; the clerk-entered value is only a
 * manual fallback used when no profile value is available.
 */
class DelayedPsaCertificate
{
    /**
     * The certificate type label, as submitted by the Clerk form and as stored
     * on the issuance history record.
     */
    public const TYPE = 'Delayed PSA Registration';

    /**
     * The exact merge-field contract of the Delayed PSA template.
     *
     * `father’s_name` and `resident’s_name` use a curly apostrophe
     * (U+2019); `mothers_maiden_name` deliberately has no apostrophe and is
     * singular in the key while the rendered label reads "MAIDEN NAME OF THE
     * MOTHER". Any change to this list is a template change and must be
     * reflected in the provisioned DOCX.
     *
     * @var array<int, string>
     */
    public const TEMPLATE_KEYS = [
        'control_number',
        'current_date',
        'date_of_birth',
        "father\u{2019}s_name",
        'gender',
        'mothers_maiden_name',
        'parents_marriage_details',
        'residency_period',
        'purok',
        "resident\u{2019}s_name",
        'signatory_name',
    ];

    /**
     * The sex values the template accepts, in the form the certificate
     * prints them.
     *
     * @var array<int, string>
     */
    public const SEX_VALUES = ['MALE', 'FEMALE'];

    /**
     * Default nationality offered to the Clerk. The value stays editable.
     */
    public const DEFAULT_NATIONALITY = 'FILIPINO';

    /**
     * Default birth place offered to the Clerk. The value stays editable.
     */
    public const DEFAULT_BIRTH_PLACE = 'PUERTO PRINCESA CITY';

    /**
     * The earliest year the Gregorian calendar can represent. It is the
     * absolute sanity floor for the residency year input; the effective lower
     * bound is the resident's own birth year (see earliestResidencyYear()).
     */
    public const EARLIEST_RESIDENCY_YEAR = 1583;

    /**
     * Whether the given certificate type is the Delayed PSA Registration.
     */
    public static function isType(mixed $type): bool
    {
        return is_string($type) && $type === self::TYPE;
    }

    /**
     * The validation rules that apply only to the Delayed PSA certificate.
     *
     * The date of birth, sex, and purok are nullable here because they may be
     * supplied by the linked resident profile; the resolved values are
     * checked for completeness once the profile has been merged in.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            // The template prints its own purpose, so the generic purpose
            // input is not collected for this certificate.
            'purpose' => ['nullable', 'string', 'max:500'],

            // The template carries its own signature block; the UI still lets
            // the Clerk select a published signatory and its title is applied
            // to the generated document copy.
            'signatory_name' => ['nullable', 'string', 'max:255'],
            'signatory_title' => ['nullable', 'string', 'max:255'],

            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::in(self::SEX_VALUES)],
            'purok' => ['nullable', 'string', 'max:255'],

            'nationality' => ['required', 'string', 'max:120'],
            'birth_place' => ['required', 'string', 'max:120'],
            'father_name' => ['required', 'string', 'max:255'],
            'mother_maiden_name' => ['required', 'string', 'max:255'],
            'parents_marriage_details' => ['required', 'string', 'max:255'],
            'residency_start_year' => [
                'nullable',
                'integer',
                'min:'.self::EARLIEST_RESIDENCY_YEAR,
                'max:'.(int) date('Y'),
            ],

            // The Clerk must confirm the reviewed resident details before the
            // certificate may be issued.
            'details_confirmed' => ['required', 'accepted'],
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
                'The Delayed PSA Registration template is not provisioned.'
            );
        }

        $variables = CertificatePlaceholders::templateVariables($templatePath);
        // These optional official fields are always supplied by the server.
        $variables = array_values(array_diff($variables, CertificateOfficials::TEMPLATE_KEYS));
        sort($variables);

        $expected = self::TEMPLATE_KEYS;
        sort($expected);

        if ($variables !== $expected) {
            throw new RuntimeException(
                'The Delayed PSA Registration template does not match the approved merge-field contract.'
            );
        }
    }

    /**
     * Resolve the final Delayed PSA values.
     *
     * The linked resident profile wins over the clerk-entered value for the
     * date of birth, sex, and purok; the clerk-entered value is only used
     * when the profile has none. Every other value is clerk-entered.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|int>
     */
    public static function resolveValues(array $validated, ?Resident $resident): array
    {
        $profileBirthdate = $resident?->birthdate?->format('Y-m-d');
        $profileSex = $resident?->sex;
        $profilePurok = $resident?->purok?->name;

        $birthdate = self::parseDate(
            self::firstFilled($profileBirthdate, self::text($validated['date_of_birth'] ?? null))
        );

        $sex = self::normalizeSex(
            self::firstFilled($profileSex, self::text($validated['sex'] ?? null))
        );

        $purok = self::text(
            self::firstFilled($profilePurok, self::text($validated['purok'] ?? null))
        );

        $residencyYear = self::year($validated['residency_start_year'] ?? null);

        return [
            'control_number' => self::text($validated['control_no'] ?? null),
            'resident_name' => self::text($resident?->full_name ?? $validated['resident_name'] ?? null),
            'purok' => $purok,
            'date_of_birth' => $birthdate?->format('Y-m-d') ?? '',
            'sex' => $sex ?? '',
            'nationality' => self::text($validated['nationality'] ?? null),
            'birth_place' => self::text($validated['birth_place'] ?? null),
            'father_name' => self::text($validated['father_name'] ?? null),
            'mother_maiden_name' => self::text($validated['mother_maiden_name'] ?? null),
            'parents_marriage_details' => self::text(
                $validated['parents_marriage_details'] ?? null
            ),
            'residency_start_year' => $residencyYear ?? 0,
            'signatory_name' => self::text($validated['signatory_name'] ?? null),
        ];
    }

    /**
     * Ensure every required Delayed PSA value was resolved.
     *
     * A missing value is reported as a field-level validation error so the
     * Clerk is told exactly which field still has to be supplied.
     *
     * @param  array<string, string|int>  $values
     *
     * @throws ValidationException when a required value is missing.
     */
    public static function assertResolvedValuesAreComplete(array $values): void
    {
        $errors = [];

        if (($values['date_of_birth'] ?? '') === '') {
            $errors['date_of_birth'] = 'The date of birth is required.';
        }

        if (! in_array($values['sex'] ?? null, self::SEX_VALUES, true)) {
            $errors['sex'] = 'The sex is required.';
        }

        if (($values['purok'] ?? '') === '') {
            $errors['purok'] = 'The purok is required.';
        }

        if (($values['nationality'] ?? '') === '') {
            $errors['nationality'] = 'The nationality is required.';
        }

        if (($values['birth_place'] ?? '') === '') {
            $errors['birth_place'] = 'The birth place is required.';
        }

        if (($values['father_name'] ?? '') === '') {
            $errors['father_name'] = 'The full name of the father is required.';
        }

        if (($values['mother_maiden_name'] ?? '') === '') {
            $errors['mother_maiden_name'] = 'The maiden name of the mother is required.';
        }

        if (($values['parents_marriage_details'] ?? '') === '') {
            $errors['parents_marriage_details'] =
                'The date and place of marriage of the parents is required.';
        }

        $residencyYear = (int) ($values['residency_start_year'] ?? 0);

        if ($residencyYear === 0) {
            $errors['residency_start_year'] =
                'The year the resident started residing in this barangay is required.';
        }

        if ($errors === [] && $residencyYear > 0) {
            $birthYear = (int) Carbon::parse((string) $values['date_of_birth'])->format('Y');

            if ($residencyYear < $birthYear) {
                $errors['residency_start_year'] =
                    'The residency start year cannot be earlier than the resident\'s birth year.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The earliest residency year that can be recorded for a resident.
     *
     * A resident cannot have been a resident before they were born, so the
     * effective lower bound is the resident's own birth year. The Gregorian
     * calendar floor is the absolute sanity limit for the numeric input.
     */
    public static function earliestResidencyYear(?Carbon $birthdate): int
    {
        if ($birthdate === null) {
            return self::EARLIEST_RESIDENCY_YEAR;
        }

        return max(self::EARLIEST_RESIDENCY_YEAR, (int) $birthdate->format('Y'));
    }

    /**
     * Map the resolved values onto the template's merge fields.
     *
     * The template prints its institutional text in upper case, so the
     * resident-supplied values are upper cased as well. The date of birth and
     * the issue date keep the formal long form already used by the template's
     * own "Issued on this ..." line.
     *
     * @param  array<string, string|int>  $values
     * @return array<string, string>
     */
    public static function templateValues(array $values): array
    {
        $birthdate = Carbon::parse((string) $values['date_of_birth']);

        return [
            'control_number' => (string) $values['control_number'],
            "resident\u{2019}s_name" => mb_strtoupper((string) $values['resident_name']),
            'purok' => mb_strtoupper((string) $values['purok']),
            'date_of_birth' => self::formatDateOfBirth($birthdate),
            'gender' => (string) $values['sex'],
            'nationality' => mb_strtoupper((string) $values['nationality']),
            'birth_place' => mb_strtoupper((string) $values['birth_place']),
            "father\u{2019}s_name" => mb_strtoupper((string) $values['father_name']),
            'mothers_maiden_name' => mb_strtoupper((string) $values['mother_maiden_name']),
            'parents_marriage_details' => mb_strtoupper(
                (string) $values['parents_marriage_details']
            ),
            'residency_period' => self::formatResidencyPeriod(
                (int) $values['residency_start_year']
            ),
            'current_date' => self::formatIssueDate(),
            'signatory_name' => mb_strtoupper((string) ($values['signatory_name'] ?? '')),
        ];
    }

    /**
     * Render a birth date in the formal long form the certificate uses, the
     * same form as the template's own issue date.
     */
    public static function formatDateOfBirth(Carbon $date): string
    {
        return $date->format('jS \d\a\y \o\f F Y');
    }

    /**
     * Render the certificate's issue date in the formal long form.
     */
    public static function formatIssueDate(): string
    {
        return date('jS \d\a\y \o\f F Y');
    }

    /**
     * Render the residency phrase written after the template's "has been a
     * resident of this Barangay from ".
     */
    public static function formatResidencyPeriod(int $year): string
    {
        return $year.' up to present';
    }

    /**
     * Normalize a profile or form sex value to the template's MALE/FEMALE
     * form, or null when the value is not a recognised sex.
     */
    public static function normalizeSex(mixed $value): ?string
    {
        $normalized = mb_strtoupper(trim((string) $value));

        return in_array($normalized, self::SEX_VALUES, true) ? $normalized : null;
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
     * The first non-empty value of the given candidates.
     */
    private static function firstFilled(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $text = self::text($candidate);

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Parse a Y-m-d date, returning null for empty or malformed input.
     */
    private static function parseDate(mixed $value): ?Carbon
    {
        $text = self::text($value);

        if ($text === '') {
            return null;
        }

        $parsed = Carbon::createFromFormat('Y-m-d', $text);

        if ($parsed === null || $parsed->format('Y-m-d') !== $text) {
            return null;
        }

        return $parsed->startOfDay();
    }

    /**
     * Parse a four-digit residency year, returning null for empty or
     * malformed input.
     */
    private static function year(mixed $value): ?int
    {
        $text = self::text($value);

        if ($text === '' || ! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }
}
