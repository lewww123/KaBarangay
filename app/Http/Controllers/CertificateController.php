<?php

namespace App\Http\Controllers;

use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\CertificateTemplates;
use App\Support\DelayedPsaCertificate;
use App\Support\MarriageCohabitationCertificate;
use App\Support\ResidencyCertificate;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpWord\TemplateProcessor;
use Throwable;

class CertificateController extends Controller
{
    /**
     * Maximum attempts to mint a unique issuance reference before giving up.
     * The unique index on certificate_issuances.reference is the final
     * authority; this loop only avoids a hard failure on the rare collision.
     */
    private const REFERENCE_ATTEMPTS = 5;

    public function generate(Request $request)
    {
        // The validation rules are certificate-type dependent, so the
        // submitted type is read first to build the right rule set. The type
        // itself is still validated below, and an unknown type simply falls
        // back to the generic rules and fails the `Rule::in()` check.
        $submittedType = $request->input('certificate_type');
        $submittedType = is_string($submittedType) ? $submittedType : '';

        $validated = $request->validate($this->rulesFor($submittedType));

        // Barangay and issuer are derived exclusively from the authenticated
        // server-side user; client-supplied values are never trusted.
        $user = $request->user();
        $barangayId = $user->barangay_id;

        $certType = $validated['certificate_type'];

        // Locate the approved Word template for the requested certificate type.
        $templatePath = CertificateTemplates::path($certType);

        if (! is_file($templatePath)) {
            return back()->withErrors([
                'certificate_type' => 'This certificate template is not available yet. Please choose another certificate type.',
            ]);
        }

        // The Delayed PSA template has a fixed merge-field contract. A
        // mis-provisioned template would silently leave unresolved
        // `${...}` placeholders in the issued document, so it is rejected
        // before anything is rendered.
        if (DelayedPsaCertificate::isType($certType)) {
            try {
                DelayedPsaCertificate::assertTemplateContract($templatePath);
            } catch (Throwable $exception) {
                report($exception);

                return back()->withErrors([
                    'certificate_type' => 'The Delayed PSA Registration template is not correctly provisioned. Please contact the administrator.',
                ]);
            }
        }

        // The Marriage Cohabitation template has a fixed merge-field contract
        // as well, so a mis-provisioned template is rejected before anything
        // is rendered.
        if (MarriageCohabitationCertificate::isType($certType)) {
            try {
                MarriageCohabitationCertificate::assertTemplateContract($templatePath);
            } catch (Throwable $exception) {
                report($exception);

                return back()->withErrors([
                    'certificate_type' => 'The Marriage Cohabitation template is not correctly provisioned. Please contact the administrator.',
                ]);
            }
        }

        // The Residency template has a fixed merge-field contract as well, so
        // a mis-provisioned template is rejected before anything is rendered.
        if (ResidencyCertificate::isType($certType)) {
            try {
                ResidencyCertificate::assertTemplateContract($templatePath);
            } catch (Throwable $exception) {
                report($exception);

                return back()->withErrors([
                    'certificate_type' => 'The Residency template is not correctly provisioned. Please contact the administrator.',
                ]);
            }
        }

        // Resolve an optional registered resident for the snapshot, scoped to
        // the acting user's barangay. An unknown or foreign resident id is
        // treated as no registered resident rather than trusted.
        $resident = null;
        if (! empty($validated['resident_id'])) {
            $resident = Resident::query()
                ->with('purok:id,name')
                ->where('barangay_id', $barangayId)
                ->whereKey($validated['resident_id'])
                ->first();
        }

        // Resolve an explicitly linked request. Only a request that belongs to
        // the acting user's barangay, and whose catalog certificate name
        // matches the submitted template type, is accepted. No linkage is ever
        // inferred by matching resident names.
        $linkedRequest = null;
        if (! empty($validated['certificate_request_id'])) {
            $linkedRequest = CertificateRequest::query()
                ->with('certificate:id,name')
                ->where('barangay_id', $barangayId)
                ->whereKey($validated['certificate_request_id'])
                ->first();

            if (! $linkedRequest) {
                throw ValidationException::withMessages([
                    'certificate_request_id' => 'The selected certificate request was not found in your barangay.',
                ]);
            }

            if ($linkedRequest->certificate?->name !== $certType) {
                throw ValidationException::withMessages([
                    'certificate_request_id' => 'The selected request does not match the chosen certificate type.',
                ]);
            }
        }

        // Snapshot the trusted fee: only a validated linked request provides a
        // server-stored fee; manual generation stores null.
        $feeSnapshot = $linkedRequest !== null ? $linkedRequest->fee : null;

        // The purok that is printed on the certificate and recorded on the
        // issuance history. For the Delayed PSA certificate it is resolved
        // from the linked resident profile first, with the clerk-entered
        // value only as a manual fallback.
        $purokSnapshot = $validated['purok'] ?? null;

        // The Delayed PSA certificate carries its own resident detail set.
        // Those values are merged with the linked resident profile and must
        // be complete before a document is rendered.
        $delayedPsaDetails = null;

        if (DelayedPsaCertificate::isType($certType)) {
            $delayedPsaDetails = DelayedPsaCertificate::resolveValues($validated, $resident);
            DelayedPsaCertificate::assertResolvedValuesAreComplete($delayedPsaDetails);

            $purokSnapshot = (string) $delayedPsaDetails['purok'];
        }

        // The Marriage Cohabitation certificate carries its own resident and
        // partner detail set. The resident identity is read from the linked
        // resident profile, so the certificate can only be issued against a
        // registered resident of the acting clerk's barangay. The partner
        // details are transient clerk input.
        $marriageDetails = null;

        if (MarriageCohabitationCertificate::isType($certType)) {
            if ($resident === null) {
                throw ValidationException::withMessages([
                    'resident_id' => 'A registered resident must be selected for a Marriage Cohabitation certificate.',
                ]);
            }

            $marriageDetails = MarriageCohabitationCertificate::resolveValues($validated, $resident);
            MarriageCohabitationCertificate::assertResolvedValuesAreComplete($marriageDetails);

            $purokSnapshot = (string) $marriageDetails['purok'];
        }

        // The Residency certificate names the resident from the linked resident
        // profile as well. The resident identity is read from the resident
        // record, so the certificate can only be issued against a registered,
        // active resident of the acting clerk's barangay. The number of years
        // of residency is transient clerk input.
        $residencyDetails = null;

        if (ResidencyCertificate::isType($certType)) {
            if ($resident === null) {
                throw ValidationException::withMessages([
                    'resident_id' => 'A registered resident must be selected for a Residency certificate.',
                ]);
            }

            if ($resident->status !== 'active') {
                throw ValidationException::withMessages([
                    'resident_id' => 'The selected resident is not an active resident of this barangay.',
                ]);
            }

            $residencyDetails = ResidencyCertificate::resolveValues($validated, $resident);
            ResidencyCertificate::assertResolvedValuesAreComplete($residencyDetails);

            $purokSnapshot = (string) $residencyDetails['purok'];
        }

        // Build the document first. This must complete before any issuance is
        // recorded, and the filename must be unique so concurrent generations
        // never overwrite one another.
        $outputPath = storage_path('app/public/Certificate_'.Str::uuid().'.docx');

        try {
            $this->buildDocument(
                $templatePath,
                $outputPath,
                $certType,
                $validated,
                $delayedPsaDetails,
                $marriageDetails,
                $residencyDetails,
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'certificate_type' => 'The certificate could not be generated. Please try again.',
            ]);
        }

        // Persist the issuance record before returning the download. A
        // persistence failure must not silently succeed.
        try {
            // The Marriage Cohabitation and the Residency certificates name the
            // resident from the resident profile, so their snapshot is the
            // trusted name and never the client-supplied one.
            $residentNameSnapshot = null;

            if ($marriageDetails !== null) {
                $residentNameSnapshot = (string) $marriageDetails['resident_name'];
            }

            if ($residencyDetails !== null) {
                $residentNameSnapshot = (string) $residencyDetails['resident_name'];
            }

            $issuance = $this->persistIssuance(
                $barangayId,
                $user->id,
                $linkedRequest,
                $resident,
                $certType,
                $validated,
                $feeSnapshot,
                $purokSnapshot,
                $residentNameSnapshot,
            );
        } catch (ValidationException $exception) {
            // Duplicate request issuance surfaces as a validation error.
            $this->cleanup($outputPath);

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->cleanup($outputPath);

            return back()->withErrors([
                'certificate_type' => 'The certificate was generated but could not be recorded. Nothing was issued.',
            ]);
        }

        // Audit is best-effort and must never change the outcome of an already
        // committed issuance (AuditLogger swallows its own failures).
        AuditLogger::record(
            'certificate_issuance.created',
            $issuance->barangay_id,
            $user->id,
            'certificate_issuance',
            $issuance->id,
            ['entity_reference' => $issuance->reference],
            $request->ip(),
        );

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }

    /**
     * The validation rules that apply to a certificate type.
     *
     * The Delayed PSA Registration certificate states its own purpose and
     * carries its own signature blocks, and it collects a resident detail set
     * the other templates do not use, so its rules differ.
     *
     * @return array<string, mixed>
     */
    private function rulesFor(string $certType): array
    {
        $rules = [
            'certificate_type' => [
                'required',
                'string',
                Rule::in(CertificateTemplates::types()),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $type = (string) $value;

                    if (in_array($type, CertificateTemplates::types(), true)
                        && ! CertificateTemplates::isAvailable($type)) {
                        $fail("The {$type} certificate is not available yet. Please choose another certificate type.");
                    }
                },
            ],
            'resident_name' => ['required', 'string', 'max:255'],
            'purok' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:500'],
            'control_no' => ['required', 'string', 'max:100'],
            'fee' => ['required', 'numeric', 'min:0'],
            'resident_id' => ['nullable', 'integer'],
            'certificate_request_id' => ['nullable', 'integer'],
            'signatory_name' => ['nullable', 'string', 'max:255'],
            'signatory_title' => ['nullable', 'string', 'max:255'],
            'franchise_no' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'school_institution' => ['nullable', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_occupation' => ['nullable', 'string', 'max:255'],
            'father_monthly_income' => ['nullable', 'numeric', 'min:0'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_occupation' => ['nullable', 'string', 'max:255'],
            'mother_monthly_income' => ['nullable', 'numeric', 'min:0'],
        ];

        if (DelayedPsaCertificate::isType($certType)) {
            $rules = array_merge($rules, DelayedPsaCertificate::validationRules());
        }

        if (MarriageCohabitationCertificate::isType($certType)) {
            $rules = array_merge($rules, MarriageCohabitationCertificate::validationRules());
        }

        if (ResidencyCertificate::isType($certType)) {
            $rules = array_merge($rules, ResidencyCertificate::validationRules());
        }

        return $rules;
    }

    /**
     * Render the certificate template into a unique output file.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, string|int|null>|null  $delayedPsaDetails
     * @param  array<string, string|int|null>|null  $marriageDetails
     * @param  array<string, string|int|null>|null  $residencyDetails
     */
    private function buildDocument(
        string $templatePath,
        string $outputPath,
        string $certType,
        array $validated,
        ?array $delayedPsaDetails = null,
        ?array $marriageDetails = null,
        ?array $residencyDetails = null,
    ): void {
        $templateProcessor = new TemplateProcessor($templatePath);

        if (DelayedPsaCertificate::isType($certType)) {
            // The Delayed PSA template has its own merge-field contract, so
            // only the approved keys are written. Every key is written on
            // every occurrence of that key in the document.
            foreach (DelayedPsaCertificate::templateValues($delayedPsaDetails ?? []) as $key => $value) {
                $templateProcessor->setValue($key, $value);
            }

            $templateProcessor->saveAs($outputPath);

            return;
        }

        if (MarriageCohabitationCertificate::isType($certType)) {
            // The Marriage Cohabitation template has its own merge-field
            // contract as well: it carries no control number, purpose, fee,
            // or signatory field, so only the approved keys are written.
            foreach (MarriageCohabitationCertificate::templateValues($marriageDetails ?? []) as $key => $value) {
                $templateProcessor->setValue($key, $value);
            }

            $templateProcessor->saveAs($outputPath);

            return;
        }

        if (ResidencyCertificate::isType($certType)) {
            // The Residency template has its own merge-field contract as well:
            // it carries no control number, purpose, fee, or signatory field,
            // so only the approved keys are written.
            foreach (ResidencyCertificate::templateValues($residencyDetails ?? []) as $key => $value) {
                $templateProcessor->setValue($key, $value);
            }

            $templateProcessor->saveAs($outputPath);

            return;
        }

        $templateProcessor->setValue('resident_name', mb_strtoupper($validated['resident_name']));
        $templateProcessor->setValue('purok', mb_strtoupper($validated['purok'] ?? ''));
        $templateProcessor->setValue('purpose', mb_strtoupper($validated['purpose']));
        $templateProcessor->setValue('control_no', $validated['control_no']);
        $templateProcessor->setValue('current_date', date('jS \d\a\y \o\f F Y'));
        $templateProcessor->setValue('fee', 'P '.number_format((float) $validated['fee'], 2));

        $templateProcessor->setValue('signatory_name', mb_strtoupper($validated['signatory_name'] ?? ''));
        $templateProcessor->setValue('signatory_title', $validated['signatory_title'] ?? '');

        if ($certType === 'Tricycle' || $certType === 'Sari-sari Store') {
            $templateProcessor->setValue('franchise_no', mb_strtoupper($validated['franchise_no'] ?? 'N/A'));
            $templateProcessor->setValue('business_name', mb_strtoupper($validated['business_name'] ?? ''));
            $templateProcessor->setValue('business_address', mb_strtoupper($validated['business_address'] ?? ''));
        }

        if ($certType === 'Scholarship Certificate') {
            // The Scholarship template uses curly apostrophes (U+2019) in these
            // keys; they must match the DOCX exactly. Empty values are replaced
            // with an empty string so no unresolved placeholder is left behind.
            $templateProcessor->setValue('school_institution', mb_strtoupper($validated['school_institution'] ?? ''));
            $templateProcessor->setValue('father’s_name', mb_strtoupper($validated['father_name'] ?? ''));
            $templateProcessor->setValue('father’s_occupation', mb_strtoupper($validated['father_occupation'] ?? ''));
            $templateProcessor->setValue('father’s_monthly_income', (string) ($validated['father_monthly_income'] ?? ''));
            $templateProcessor->setValue('mother’s_name', mb_strtoupper($validated['mother_name'] ?? ''));
            $templateProcessor->setValue('mother’s_occupation', mb_strtoupper($validated['mother_occupation'] ?? ''));
            $templateProcessor->setValue('mother’s_monthly_income', (string) ($validated['mother_monthly_income'] ?? ''));
        }

        $templateProcessor->saveAs($outputPath);
    }

    /**
     * Persist the issuance history record.
     *
     * @param  array<string, mixed>  $validated
     */
    private function persistIssuance(
        int $barangayId,
        int $userId,
        ?CertificateRequest $linkedRequest,
        ?Resident $resident,
        string $certType,
        array $validated,
        int|float|string|null $feeSnapshot,
        ?string $purokSnapshot = null,
        ?string $residentNameSnapshot = null,
    ): CertificateIssuance {
        $attributes = [
            'barangay_id' => $barangayId,
            'certificate_request_id' => $linkedRequest?->id,
            'resident_id' => $resident?->id,
            'certificate_type' => $certType,
            // The Marriage Cohabitation certificate names the resident from
            // the resident profile, so its snapshot is the trusted name and
            // never the client-supplied one.
            'resident_name' => $residentNameSnapshot ?? ($validated['resident_name'] ?? ''),
            'resident_number' => $resident?->resident_number,
            'purok' => $purokSnapshot ?? ($validated['purok'] ?? null),
            'fee' => $feeSnapshot,
            'issued_by' => $userId,
            'issued_at' => now(),
        ];

        for ($attempt = 1; $attempt <= self::REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes) {
                    return CertificateIssuance::create([
                        ...$attributes,
                        'reference' => $this->makeReference(),
                    ]);
                });
            } catch (QueryException $exception) {
                // A duplicate request link is a controlled, user-facing error:
                // one request may only be issued once.
                if ($this->isDuplicateRequestLink($exception)) {
                    throw ValidationException::withMessages([
                        'certificate_request_id' => 'This certificate request has already been issued.',
                    ]);
                }

                // Any other unique violation is a reference collision; retry.
                if ($this->isUniqueViolation($exception) && $attempt < self::REFERENCE_ATTEMPTS) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new InvalidArgumentException('Unable to generate a unique issuance reference.');
    }

    private function makeReference(): string
    {
        return 'ISSUE-'.now()->format('Y').'-'.Str::upper(Str::random(8));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || $exception->getCode() === '23505';
    }

    private function isDuplicateRequestLink(QueryException $exception): bool
    {
        if (! $this->isUniqueViolation($exception)) {
            return false;
        }

        $message = $exception->getMessage();

        // SQLite reports the constrained column, PostgreSQL reports the index
        // name; accept either so the controlled "already issued" response is
        // produced on both drivers.
        return str_contains($message, 'certificate_issuances_request_unique')
            || str_contains($message, 'certificate_request_id');
    }

    private function cleanup(string $outputPath): void
    {
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }
    }
}
