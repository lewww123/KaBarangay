<?php

namespace App\Support;

use App\Models\Resident;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Contract, validation rules, and value formatting for the Marriage
 * Cohabitation certificate.
 *
 * The certificate is rendered from `storage/app/templates/marriage.docx`,
 * whose merge fields are fixed. The template states its own purpose ("for
 * Marriage Purposes") and carries its own Punong Barangay / Officer on Duty
 * signature blocks. The purpose is fixed by the template; the selected
 * published signatory is inserted into its literal marker and title label.
 *
 * The resident identity on the certificate (full name, age, purok) comes from
 * the linked resident profile only. A client-supplied name, age, or purok is
 * never trusted, so the certificate can only be issued against a registered
 * resident of the acting clerk's barangay.
 *
 * The partner details (full name, age, years living together) are transient
 * clerk input. They are written into the document but are never persisted to
 * the issuance history or to the audit log.
 */
class MarriageCohabitationCertificate
{
    /**
     * The certificate type label, as submitted by the Clerk form and as stored
     * on the issuance history record.
     */
    public const TYPE = 'Marriage Cohabitation';

    /**
     * The exact merge-field contract of the Marriage Cohabitation template.
     *
     * `resident’s_name`, `partner’s_full_name`, and `partner’s_age` use a
     * curly apostrophe (U+2019); they must match the DOCX exactly. The
     * current DOCX also contains a literal single-braced signatory marker,
     * which is filled in the generated copy after PHPWord processing.
     *
     * @var array<int, string>
     */
    public const TEMPLATE_KEYS = [
        'current_date',
        "partner\u{2019}s_age",
        "partner\u{2019}s_full_name",
        'purok',
        'resident_age',
        "resident\u{2019}s_name",
        'years_living_together',
    ];

    /**
     * The lowest accepted partner age.
     */
    public const PARTNER_AGE_MIN = 1;

    /**
     * The highest accepted partner age.
     */
    public const PARTNER_AGE_MAX = 120;

    /**
     * The lowest accepted number of years living together.
     */
    public const YEARS_LIVING_TOGETHER_MIN = 0;

    /**
     * The highest accepted number of years living together.
     */
    public const YEARS_LIVING_TOGETHER_MAX = 120;

    /**
     * Whether the given certificate type is the Marriage Cohabitation.
     */
    public static function isType(mixed $type): bool
    {
        return is_string($type) && $type === self::TYPE;
    }

    /**
     * The validation rules that apply only to the Marriage Cohabitation
     * certificate.
     *
     * The template prints its own purpose and carries its own signature
     * blocks, so the generic purpose, signatory, and control number inputs
     * are not collected for this certificate. The resident name and purok are
     * taken from the linked resident profile, so the client-supplied values
     * are not required either.
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

            // The partner details are transient clerk input.
            'partner_full_name' => ['required', 'string', 'max:255'],
            'partner_age' => [
                'required',
                'integer',
                'min:'.self::PARTNER_AGE_MIN,
                'max:'.self::PARTNER_AGE_MAX,
            ],
            'years_living_together' => [
                'required',
                'integer',
                'min:'.self::YEARS_LIVING_TOGETHER_MIN,
                'max:'.self::YEARS_LIVING_TOGETHER_MAX,
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
                'The Marriage Cohabitation template is not provisioned.'
            );
        }

        // PHPWord repairs the macros that the template splits across Word runs
        // (the proofing markers) while it reads the document, so the variable
        // list is the list the merge will actually write to.
        $variables = CertificatePlaceholders::templateVariables($templatePath);
        // These optional official fields are always supplied by the server.
        $variables = array_values(array_diff($variables, CertificateOfficials::TEMPLATE_KEYS));
        sort($variables);

        $expected = self::TEMPLATE_KEYS;
        sort($expected);

        if ($variables !== $expected) {
            throw new RuntimeException(
                'The Marriage Cohabitation template does not match the approved merge-field contract.'
            );
        }
    }

    /**
     * Resolve the final Marriage Cohabitation values.
     *
     * The resident name, age, and purok come from the linked resident
     * profile; a client-supplied value is never used for them. The partner
     * details are clerk-entered and transient.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string|int|null>
     */
    public static function resolveValues(array $validated, ?Resident $resident): array
    {
        return [
            'resident_name' => self::text($resident?->full_name),
            'resident_age' => self::ageFrom($resident?->birthdate),
            'purok' => self::text($resident?->purok?->name),
            'partner_full_name' => self::text($validated['partner_full_name'] ?? null),
            'partner_age' => self::wholeNumber(
                $validated['partner_age'] ?? null,
                self::PARTNER_AGE_MIN,
                self::PARTNER_AGE_MAX
            ),
            'years_living_together' => self::wholeNumber(
                $validated['years_living_together'] ?? null,
                self::YEARS_LIVING_TOGETHER_MIN,
                self::YEARS_LIVING_TOGETHER_MAX
            ),
        ];
    }

    /**
     * Ensure every required Marriage Cohabitation value was resolved.
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
                'A registered resident must be selected for a Marriage Cohabitation certificate.';
        }

        if (($values['resident_age'] ?? null) === null) {
            $errors['resident_id'] =
                'The selected resident has no birth date on record, so the age cannot be determined.';
        }

        if (($values['purok'] ?? '') === '') {
            $errors['purok'] = 'The purok of the selected resident is required.';
        }

        if (($values['partner_full_name'] ?? '') === '') {
            $errors['partner_full_name'] = 'The full name of the partner is required.';
        }

        if (($values['partner_age'] ?? null) === null) {
            $errors['partner_age'] = 'The age of the partner must be a whole number between '
                .self::PARTNER_AGE_MIN.' and '.self::PARTNER_AGE_MAX.'.';
        }

        if (($values['years_living_together'] ?? null) === null) {
            $errors['years_living_together'] = 'The number of years living together must be a whole number between '
                .self::YEARS_LIVING_TOGETHER_MIN.' and '.self::YEARS_LIVING_TOGETHER_MAX.'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Map the resolved values onto the template's merge fields.
     *
     * The template prints its institutional text in upper case, so the
     * resident and partner names and the purok are upper cased as well. The
     * template supplies no "PUROK" word of its own, so the merged purok
     * value carries that prefix.
     *
     * @param  array<string, string|int|null>  $values
     * @return array<string, string>
     */
    public static function templateValues(array $values): array
    {
        return [
            "resident\u{2019}s_name" => mb_strtoupper((string) ($values['resident_name'] ?? '')),
            'resident_age' => (string) ($values['resident_age'] ?? ''),
            "partner\u{2019}s_full_name" => mb_strtoupper(
                (string) ($values['partner_full_name'] ?? '')
            ),
            "partner\u{2019}s_age" => (string) ($values['partner_age'] ?? ''),
            'purok' => self::formatPurok((string) ($values['purok'] ?? '')),
            'years_living_together' => (string) ($values['years_living_together'] ?? ''),
            'current_date' => self::formatIssueDate(),
        ];
    }

    /**
     * Render the purok the way the template reads it.
     *
     * The template writes "both resident of ${purok}", so the merged value
     * supplies the "PUROK" word itself.
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
     * form the template's own "Issued on this ..." line uses.
     */
    public static function formatIssueDate(): string
    {
        return date('jS \d\a\y \o\f F Y');
    }

    /**
     * The age a resident has reached on the current date.
     *
     * The resident model hands the birth date over as a date instance, but the
     * value is also accepted as a plain date string so a missing or unusual
     * record can never be mistaken for a valid age.
     */
    private static function ageFrom(mixed $birthdate): ?int
    {
        if ($birthdate instanceof CarbonInterface) {
            return self::ageFromBirthdate($birthdate);
        }

        if (is_string($birthdate) && trim($birthdate) !== '') {
            try {
                return self::ageFromBirthdate(Carbon::parse($birthdate));
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The age a resident has reached on the current date.
     */
    public static function ageFromBirthdate(CarbonInterface $birthdate): int
    {
        $today = Carbon::now();

        $age = $today->year - $birthdate->year;

        if ($today->month < $birthdate->month
            || ($today->month === $birthdate->month && $today->day < $birthdate->day)) {
            $age--;
        }

        return max(0, $age);
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
