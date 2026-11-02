<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\OrgChartPosition;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\CertificateOfficialDocument;
use App\Support\CertificateOfficials;
use App\Support\CertificateControlNumbers;
use App\Support\CertificatePlaceholders;
use App\Support\CertificateRequestDetails;
use App\Support\CertificateRequestWorkflow;
use App\Support\CertificateTemplates;
use App\Support\DelayedPsaCertificate;
use App\Support\DynamicDocumentEngine;
use App\Support\MarriageCohabitationCertificate;
use App\Support\ResidencyCertificate;
use App\Support\StagedDocument;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpWord\Settings;
use Throwable;

class CertificateController extends Controller
{
    /**
     * Maximum attempts to mint a unique issuance reference before giving up.
     * The unique index on certificate_issuances.reference is the final
     * authority; this loop only avoids a hard failure on the rare collision.
     */
    private const REFERENCE_ATTEMPTS = 5;

    public function nextControlNumber(Request $request)
    {
        $type = $request->validate(['certificate_type' => ['required', 'string', 'max:100']])['certificate_type'];
        $type = CertificateRequestDetails::typeForCertificate(new Certificate(['name' => trim($type), 'code' => trim($type)])) ?? $type;
        abort_unless($request->user()->barangay_id !== null, 403);
        abort_unless(CertificateTemplates::isPdfAvailable($type), 422);
        return response()->json(['control_no' => CertificateControlNumbers::next($request->user()->barangay_id, $type)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function generate(Request $request)
    {
        return $this->renderCertificate($request, false);
    }

    public function preview(Request $request)
    {
        return $this->renderCertificate($request, true);
    }

    private function renderCertificate(Request $request, bool $preview)
    {
        // The validation rules are certificate-type dependent, so the
        // submitted type is read first to build the right rule set. The type
        // itself is still validated below, and an unknown type simply falls
        // back to the generic rules and fails the `Rule::in()` check.
        $submittedType = $request->input('certificate_type');
        $submittedType = is_string($submittedType) ? trim($submittedType) : '';
        $canonicalType = CertificateRequestDetails::typeForCertificate(new Certificate(['name' => $submittedType, 'code' => $submittedType]));
        if ($canonicalType !== null) {
            $submittedType = $canonicalType;
            $request->merge(['certificate_type' => $canonicalType]);
        }

        $pdfOutput = $request->input('output_format') === 'pdf';
        $rules = $this->rulesFor($submittedType);
        if ($pdfOutput) {
            $catalogTypes = Certificate::query()->where('barangay_id', $request->user()->barangay_id)
                ->where('is_active', true)->get()->map(fn ($certificate) => CertificateRequestDetails::typeForCertificate($certificate))
                ->filter()->values()->all();
            $rules['certificate_type'] = ['required', 'string', Rule::in(array_unique([...CertificateTemplates::types(), ...$catalogTypes]))];
            $rules['rendered_pdf'] = ['required', 'string', 'max:6000000'];
            $rules['control_no'] = ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{4,}$/', 'max:100'];
        }
        if ($preview) {
            unset($rules['details_confirmed']);
        }
        $validated = $request->validate($rules);
        $pdfBytes = null;
        if ($pdfOutput) {
            $pdfBytes = base64_decode($validated['rendered_pdf'], true);
            if ($pdfBytes === false || ! str_starts_with($pdfBytes, '%PDF-') || ! str_contains(substr($pdfBytes, -1024), '%%EOF')) {
                throw ValidationException::withMessages(['rendered_pdf' => 'The reviewed certificate PDF is invalid. Please refresh the preview.']);
            }
            unset($validated['rendered_pdf']);
        }

        // Barangay and issuer are derived exclusively from the authenticated
        // server-side user; client-supplied values are never trusted.
        $user = $request->user();
        $barangayId = $user->barangay_id;

        abort_unless($barangayId !== null, 403);

        $officials = CertificateOfficials::resolve($barangayId, filled($validated['acting_captain_id'] ?? null) ? (int) $validated['acting_captain_id'] : null);

        if (filled($validated['signatory_name'] ?? null)) {
            $signatory = OrgChartPosition::query()
                ->where('barangay_id', $barangayId)
                ->where('status', 'published')
                ->where('assigned_person', $validated['signatory_name'])
                ->where('title', $validated['signatory_title'] ?? '')
                ->first();

            if ($signatory === null) {
                throw ValidationException::withMessages([
                    'signatory_name' => 'Choose a currently published signatory from this barangay Organizational Chart.',
                ]);
            }

            $validated['signatory_name'] = $signatory->assigned_person;
            $validated['signatory_title'] = $signatory->title;
        }

        $certType = $validated['certificate_type'];

        // Locate the approved Word template for the requested certificate type.
        $templatePath = CertificateTemplates::path($certType);

        if (! $pdfOutput) {
        if (! is_file($templatePath)) {
            return $this->renderFailure(
                $preview,
                'certificate_type',
                'This certificate template is not available yet. Please choose another certificate type.',
            );
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

                return $this->renderFailure(
                    $preview,
                    'certificate_type',
                    'The Delayed PSA Registration template is not correctly provisioned. Please contact the administrator.',
                );
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

                return $this->renderFailure(
                    $preview,
                    'certificate_type',
                    'The Marriage Cohabitation template is not correctly provisioned. Please contact the administrator.',
                );
            }
        }

        // The Residency template has a fixed merge-field contract as well, so
        // a mis-provisioned template is rejected before anything is rendered.
        if (ResidencyCertificate::isType($certType)) {
            try {
                ResidencyCertificate::assertTemplateContract($templatePath);
            } catch (Throwable $exception) {
                report($exception);

                return $this->renderFailure(
                    $preview,
                    'certificate_type',
                    'The Residency template is not correctly provisioned. Please contact the administrator.',
                );
            }
        }

        // The remaining templates use the generic renderer, so their approved
        // merge-field contracts live in the template registry. Reject a
        // mis-provisioned template before anything is rendered.
        if (! DelayedPsaCertificate::isType($certType)
            && ! MarriageCohabitationCertificate::isType($certType)
            && ! ResidencyCertificate::isType($certType)) {
            try {
                CertificateTemplates::assertTemplateContract($certType, $templatePath);
            } catch (Throwable $exception) {
                report($exception);

                return $this->renderFailure(
                    $preview,
                    'certificate_type',
                    "The {$certType} template is not correctly provisioned. Please contact the administrator.",
                );
            }
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
                ->where('status', 'active')
                ->whereKey($validated['resident_id'])
                ->first();

            if ($resident === null) {
                throw ValidationException::withMessages([
                    'resident_id' => 'Choose an active resident from your barangay or use manual entry.',
                ]);
            }
        }

        // A selected Resident is authoritative for identity and address
        // fields. Manual entry remains available when no Resident is linked.
        if ($resident !== null) {
            $validated['resident_name'] = $resident->full_name;
            $validated['purok'] = $resident->purok?->name ?? $validated['purok'] ?? null;
        }

        // Resolve an explicitly linked request. Only a request that belongs to
        // the acting user's barangay, and whose catalog certificate name
        // matches the submitted template type, is accepted. No linkage is ever
        // inferred by matching resident names.
        $linkedRequest = null;
        if (! empty($validated['certificate_request_id'])) {
            $linkedRequest = CertificateRequest::query()
                ->with('certificate:id,name,code')
                ->where('barangay_id', $barangayId)
                ->whereKey($validated['certificate_request_id'])
                ->first();

            if (! $linkedRequest) {
                throw ValidationException::withMessages([
                    'certificate_request_id' => 'The selected certificate request was not found in your barangay.',
                ]);
            }

            $requestResident = Resident::query()
                ->with('purok:id,name')
                ->where('barangay_id', $barangayId)
                ->where('status', 'active')
                ->whereKey($linkedRequest->resident_id)
                ->first();

            if ($requestResident === null
                || ($resident !== null && $resident->id !== $requestResident->id)) {
                throw ValidationException::withMessages([
                    'resident_id' => 'The selected request is not linked to the selected active resident in your barangay.',
                ]);
            }

            $resident = $requestResident;

            if (! in_array($linkedRequest->status, ['Processing', 'Needs Action', 'Pending'], true)) {
                throw ValidationException::withMessages([
                    'certificate_request_id' => "This certificate request cannot be issued while in {$linkedRequest->status} status.",
                ]);
            }

            $linkedType = CertificateRequestDetails::typeForCertificate(
                $linkedRequest->certificate,
            );

            if ($linkedType !== $certType) {
                throw ValidationException::withMessages([
                    'certificate_request_id' => 'The selected request does not match the chosen certificate type.',
                ]);
            }
        }

        // Snapshot the trusted fee: only a validated linked request provides a
        // server-stored fee; manual generation stores null.
        $feeSnapshot = $linkedRequest !== null ? $linkedRequest->fee : null;

        // A request may supply the resident even when no resident_id was sent.
        // Use that resident's authoritative identity for both preview and issue.
        if ($resident !== null) {
            $validated['resident_name'] = $resident->full_name;
            $validated['purok'] = $resident->purok?->name ?? $validated['purok'] ?? null;
        }

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

        $validated['purok'] = $purokSnapshot;
        foreach ($validated as $key => $value) {
            if ($key !== 'purok' && is_string($value)) {
                $validated[$key] = CertificatePlaceholders::replaceText($value, $purokSnapshot ?? '');
            }
        }

        // Build the document first. This must complete before any issuance is
        // recorded, and the filename must be unique so concurrent generations
        // never overwrite one another.
        $outputDirectory = storage_path('app/certificates');
        File::ensureDirectoryExists($outputDirectory);
        $outputPath = $outputDirectory.'/Certificate_'.Str::uuid().($pdfOutput ? '.pdf' : '.docx');

        $originalEscaping = Settings::isOutputEscapingEnabled();
        try {
            if ($pdfOutput) {
                if (file_put_contents($outputPath, $pdfBytes, LOCK_EX) === false) {
                    throw new \RuntimeException('The reviewed certificate could not be saved.');
                }
            } else {
            StagedDocument::write($outputPath, function ($stagingPath) use ($templatePath, $certType, $validated, $delayedPsaDetails, $marriageDetails, $residencyDetails, $officials, $purokSnapshot) {
                $this->buildDocument($templatePath, $stagingPath, $certType, $validated, $delayedPsaDetails, $marriageDetails, $residencyDetails, $officials);
                CertificateOfficialDocument::apply($stagingPath, $officials);
                CertificatePlaceholders::fillDocx($stagingPath, mb_strtoupper($purokSnapshot ?? ''));
            });
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->cleanup($outputPath);

            return $this->renderFailure(
                $preview,
                'certificate_type',
                'The certificate could not be generated. Please try again.',
            );
        } finally {
            Settings::setOutputEscapingEnabled($originalEscaping);
        }

        if ($preview) {
            return response()->download($outputPath, 'Certificate_Preview'.($pdfOutput ? '.pdf' : '.docx'), [
                'Content-Type' => $pdfOutput ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control' => 'private, no-store, max-age=0',
            ])->deleteFileAfterSend(true);
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
                $this->issuanceSnapshot(
                    $validated,
                    $delayedPsaDetails,
                    $marriageDetails,
                    $residencyDetails,
                    $officials,
                    $purokSnapshot,
                ) + ($pdfOutput ? ['pdf_path' => basename($outputPath)] : []),
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

        return response()->download(
            $outputPath,
            'Certificate_'.$issuance->reference.($pdfOutput ? '.pdf' : '.docx'),
            [
                'Content-Type' => $pdfOutput ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Certificate-Issuance-Status' => $issuance->status,
            ],
        )->deleteFileAfterSend(! $pdfOutput);
    }

    /** Return fetch-friendly preview errors without changing issuance redirects. */
    private function renderFailure(bool $preview, string $field, string $message)
    {
        if ($preview) {
            return response()->json([
                'message' => $message,
                'errors' => [$field => [$message]],
            ], 422);
        }

        return back()->withErrors([$field => $message]);
    }

    /**
     * Rebuild the archival document for an already-issued certificate.
     *
     * This endpoint is strictly read-only: it never creates a new issuance,
     * changes a request status, or rewrites the stored snapshot. New issuances
     * carry an immutable snapshot of the exact values used to render the
     * document; historical rows without one are reconstructed from their
     * linked request when the required values are available.
     */
    public function reprint(Request $request, CertificateIssuance $issuance)
    {
        $barangayId = $request->user()->barangay_id;

        abort_unless(
            $barangayId !== null && $issuance->barangay_id === $barangayId,
            404,
        );

        $savedPdf = $issuance->details['pdf_path'] ?? null;
        if (is_string($savedPdf) && basename($savedPdf) === $savedPdf && str_ends_with($savedPdf, '.pdf')) {
            $path = storage_path('app/certificates/'.$savedPdf);
            if (is_file($path)) {
                return response()->download($path, 'Certificate_'.$issuance->reference.'.pdf', [
                    'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store, max-age=0',
                ]);
            }
        }

        $certType = $issuance->certificate_type;
        $templatePath = CertificateTemplates::path($certType);

        $templateError = $this->templateAvailabilityError($certType, $templatePath);

        if ($templateError !== null) {
            return response()->json([
                'message' => $templateError,
                'errors' => ['certificate_type' => [$templateError]],
            ], 422);
        }

        $issuance->loadMissing([
            'certificateRequest.certificate:id,name,code,form_schema',
            'certificateRequest.resident.purok:id,name',
            'resident.purok:id,name',
        ]);

        $snapshot = is_array($issuance->details) ? $issuance->details : null;

        if ($snapshot === null || ! is_array($snapshot['validated'] ?? null)) {
            $snapshot = $this->reconstructIssuanceSnapshot($issuance);
        }

        if ($snapshot === null) {
            $message = 'This historical issuance does not contain enough recorded data to reproduce the document.';

            return response()->json([
                'message' => $message,
                'errors' => ['issuance' => [$message]],
            ], 422);
        }

        $validated = $snapshot['validated'];
        $officials = is_array($snapshot['officials'] ?? null)
            ? $snapshot['officials']
            : CertificateOfficials::resolve($barangayId);
        $purokSnapshot = $snapshot['purok'] ?? $issuance->purok;
        $delayedPsaDetails = is_array($snapshot['delayed_psa_details'] ?? null)
            ? $snapshot['delayed_psa_details']
            : null;
        $marriageDetails = is_array($snapshot['marriage_details'] ?? null)
            ? $snapshot['marriage_details']
            : null;
        $residencyDetails = is_array($snapshot['residency_details'] ?? null)
            ? $snapshot['residency_details']
            : null;

        if (DelayedPsaCertificate::isType($certType) && $delayedPsaDetails === null) {
            return $this->reprintFailure('The Delayed PSA Registration details for this issuance are incomplete.');
        }

        if (MarriageCohabitationCertificate::isType($certType) && $marriageDetails === null) {
            return $this->reprintFailure('The Marriage Cohabitation details for this issuance are incomplete.');
        }

        if (ResidencyCertificate::isType($certType) && $residencyDetails === null) {
            return $this->reprintFailure('The Residency details for this issuance are incomplete.');
        }

        $outputDirectory = storage_path('app/certificates');
        File::ensureDirectoryExists($outputDirectory);
        $outputPath = $outputDirectory.'/Reprint_'.Str::uuid().'.docx';

        $originalEscaping = Settings::isOutputEscapingEnabled();
        try {
            StagedDocument::write($outputPath, function ($stagingPath) use ($templatePath, $certType, $validated, $delayedPsaDetails, $marriageDetails, $residencyDetails, $officials, $purokSnapshot) {
                $this->buildDocument($templatePath, $stagingPath, $certType, $validated, $delayedPsaDetails, $marriageDetails, $residencyDetails, $officials);
                CertificateOfficialDocument::apply($stagingPath, $officials);
                CertificatePlaceholders::fillDocx($stagingPath, mb_strtoupper($purokSnapshot ?? ''));
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->cleanup($outputPath);

            return $this->reprintFailure('The historical certificate could not be reproduced. Please contact the administrator.');
        } finally {
            Settings::setOutputEscapingEnabled($originalEscaping);
        }

        AuditLogger::record(
            'certificate_issuance.reprinted',
            $issuance->barangay_id,
            $request->user()->id,
            'certificate_issuance',
            $issuance->id,
            ['entity_reference' => $issuance->reference],
            $request->ip(),
        );

        return response()->download(
            $outputPath,
            'Reprint_'.$issuance->reference.'.docx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Certificate-Reprint' => $issuance->reference,
            ],
        )->deleteFileAfterSend(true);
    }

    /** Return a fetch-friendly reprint error without touching issuance state. */
    private function reprintFailure(string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => ['issuance' => [$message]],
        ], 422);
    }

    /**
     * Validate that the template for a historical issuance is usable.
     */
    private function templateAvailabilityError(string $certType, string $templatePath): ?string
    {
        if (! is_file($templatePath)) {
            return 'This certificate template is not available yet.';
        }

        try {
            if (DelayedPsaCertificate::isType($certType)) {
                DelayedPsaCertificate::assertTemplateContract($templatePath);
            } elseif (MarriageCohabitationCertificate::isType($certType)) {
                MarriageCohabitationCertificate::assertTemplateContract($templatePath);
            } elseif (ResidencyCertificate::isType($certType)) {
                ResidencyCertificate::assertTemplateContract($templatePath);
            } else {
                CertificateTemplates::assertTemplateContract($certType, $templatePath);
            }
        } catch (Throwable $exception) {
            report($exception);

            return "The {$certType} template is not correctly provisioned. Please contact the administrator.";
        }

        return null;
    }

    /**
     * Build the immutable rendering snapshot recorded with a new issuance.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, string|int|null>|null  $delayedPsaDetails
     * @param  array<string, string|int|null>|null  $marriageDetails
     * @param  array<string, string|int|null>|null  $residencyDetails
     * @return array<string, mixed>
     */
    private function issuanceSnapshot(
        array $validated,
        ?array $delayedPsaDetails,
        ?array $marriageDetails,
        ?array $residencyDetails,
        array $officials,
        ?string $purokSnapshot,
    ): array {
        unset($validated['details_confirmed']);

        return [
            'version' => 1,
            'validated' => $validated,
            'delayed_psa_details' => $delayedPsaDetails,
            'marriage_details' => $marriageDetails,
            'residency_details' => $residencyDetails,
            'officials' => $officials,
            'purok' => $purokSnapshot,
        ];
    }

    /**
     * Reconstruct the renderable values for a historical issuance that
     * predates stored snapshots. Returns null when a required value is absent.
     *
     * @return array<string, mixed>|null
     */
    private function reconstructIssuanceSnapshot(CertificateIssuance $issuance): ?array
    {
        $linkedRequest = $issuance->certificateRequest;
        $decoded = CertificateRequestDetails::decode($linkedRequest?->additional_details);
        $resident = $issuance->resident ?? $linkedRequest?->resident;
        $officials = CertificateOfficials::resolve($issuance->barangay_id);
        $purok = $issuance->purok ?? $resident?->purok?->name ?? '';

        $validated = array_merge(
            [
                'certificate_type' => $issuance->certificate_type,
                'resident_name' => $issuance->resident_name,
                'purok' => $purok,
                'purpose' => $linkedRequest?->purpose ?? '',
                'control_no' => $issuance->control_no ?? '',
                'fee' => (string) ($issuance->fee ?? '0'),
                'business_name' => $linkedRequest?->business_name ?? '',
                'business_address' => $linkedRequest?->business_address ?? '',
                'signatory_name' => $officials['punong_barangay']['name'] ?? '',
                'signatory_title' => $officials['punong_barangay']['title'] ?? '',
            ],
            $decoded['fields'],
        );

        $certType = $issuance->certificate_type;
        $delayedPsaDetails = null;
        $marriageDetails = null;
        $residencyDetails = null;

        try {
            if (DelayedPsaCertificate::isType($certType)) {
                $delayedPsaDetails = DelayedPsaCertificate::resolveValues($validated, $resident);
                DelayedPsaCertificate::assertResolvedValuesAreComplete($delayedPsaDetails);
            } elseif (MarriageCohabitationCertificate::isType($certType)) {
                $marriageDetails = MarriageCohabitationCertificate::resolveValues($validated, $resident);
                MarriageCohabitationCertificate::assertResolvedValuesAreComplete($marriageDetails);
            } elseif (ResidencyCertificate::isType($certType)) {
                $residencyDetails = ResidencyCertificate::resolveValues($validated, $resident);
                $residencyDetails['resident_name'] = $issuance->resident_name;
                $residencyDetails['purok'] = $purok;
                ResidencyCertificate::assertResolvedValuesAreComplete($residencyDetails);
            } else {
                if (trim((string) ($validated['resident_name'] ?? '')) === ''
                    || trim((string) ($validated['purpose'] ?? '')) === '') {
                    return null;
                }
            }
        } catch (Throwable $exception) {
            return null;
        }

        return [
            'version' => 1,
            'validated' => $validated,
            'delayed_psa_details' => $delayedPsaDetails,
            'marriage_details' => $marriageDetails,
            'residency_details' => $residencyDetails,
            'officials' => $officials,
            'purok' => $purok,
        ];
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
            'control_no' => ['required', 'string', 'regex:/^CTRL-\d{4}-\d{4}$/', 'max:100'],
            'fee' => ['required', 'numeric', 'min:0'],
            'resident_id' => ['nullable', 'integer'],
            'certificate_request_id' => ['nullable', 'integer'],
            'acting_captain_id' => ['nullable', 'integer', 'min:1'],
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

        if ($certType === 'Sari-sari Store') {
            $rules['business_type'] = ['required', 'string', 'max:255'];
            $rules['business_name'] = ['required', 'string', 'max:255'];
            $rules['business_address'] = ['required', 'string', 'max:255'];
            $rules['or_number'] = ['nullable', 'string', 'max:100'];
            $rules['receipt_date'] = ['nullable', 'date_format:Y-m-d'];
            $rules['amount_paid'] = ['nullable', 'numeric', 'min:0'];
        }

        if (DelayedPsaCertificate::isType($certType)) {
            $rules = array_merge($rules, DelayedPsaCertificate::validationRules());
        }

        if (MarriageCohabitationCertificate::isType($certType)) {
            $rules = array_merge($rules, MarriageCohabitationCertificate::validationRules());
        }

        if (ResidencyCertificate::isType($certType)) {
            $rules = array_merge($rules, ResidencyCertificate::validationRules());
        }

        // The control number is an issuance identifier and is always stored.
        // A selected signatory must match a published assignment in this
        // barangay, regardless of whether the template uses its name/title.
        $rules['control_no'] = [
            'required',
            'string',
            'regex:/^CTRL-\d{4}-\d{4}$/',
            'max:100',
        ];
        // Legacy submissions may omit a signatory; the current Clerk form
        // requires a selected published assignment for newly issued docs.
        $rules['signatory_name'] = ['nullable', 'string', 'max:255'];
        $rules['signatory_title'] = ['nullable', 'string', 'max:255'];

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
        array $officials = [],
    ): void {
        Settings::setOutputEscapingEnabled(true);
        $templateProcessor = DynamicDocumentEngine::processor($templatePath, $officials);
        foreach (['PUROK', 'RESIDENT_PUROK'] as $alias) {
            $templateProcessor->setValue($alias, mb_strtoupper($validated['purok'] ?? ''));
        }

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
            // contract: it carries no control number, purpose, or fee field.
            // Its literal signatory marker is filled in the generated copy.
            foreach (MarriageCohabitationCertificate::templateValues($marriageDetails ?? []) as $key => $value) {
                $templateProcessor->setValue($key, $value);
            }

            $templateProcessor->saveAs($outputPath);
            $this->replaceLiteralDocxPlaceholder(
                $outputPath,
                '{signatory_name}',
                mb_strtoupper((string) ($validated['signatory_name'] ?? '')),
            );


            return;
        }

        if (ResidencyCertificate::isType($certType)) {
            // The Residency template has its own merge-field contract as well:
            // it carries no control number, purpose, or fee field, so only the
            // approved merge keys are written.
            foreach (ResidencyCertificate::templateValues($residencyDetails ?? []) as $key => $value) {
                $templateProcessor->setValue($key, $value);
            }

            $templateProcessor->saveAs($outputPath);


            return;
        }

        // Generic templates only contain the keys in their approved merge-field
        // contracts. Write exactly those keys; control numbers, fees, and
        // signatory titles are form/issuance metadata, not DOCX placeholders
        // for these certificate types.
        $residentNameKey = $certType === 'Tricycle'
            ? 'resident_name'
            : "resident\u{2019}s_name";
        $templateProcessor->setValue($residentNameKey, mb_strtoupper($validated['resident_name']));
        $templateProcessor->setValue('purok', mb_strtoupper($validated['purok'] ?? ''));
        if ($certType !== 'Indigency' || in_array('purpose', CertificateTemplates::genericTemplateKeys($certType) ?? [], true)) {
            $templateProcessor->setValue('purpose', mb_strtoupper($validated['purpose']));
        }
        $templateProcessor->setValue('current_date', date('jS \d\a\y \o\f F Y'));
        $templateProcessor->setValue('signatory_name', mb_strtoupper($validated['signatory_name'] ?? ''));

        if ($certType === 'Tricycle' || $certType === 'Sari-sari Store') {
            $templateProcessor->setValue('franchise_no', mb_strtoupper($validated['franchise_no'] ?? 'N/A'));
            $templateProcessor->setValue('business_name', mb_strtoupper($validated['business_name'] ?? ''));
            $templateProcessor->setValue('business_address', mb_strtoupper($validated['business_address'] ?? ''));
        }

        if ($certType === 'Scholarship Certificate') {
            // The Scholarship template uses curly apostrophes (U+2019) in these
            // keys; they must match the DOCX exactly. Empty values are replaced
            // with an empty string so no unresolved placeholder is left behind.
            $templateProcessor->setValue('school/institution', mb_strtoupper($validated['school_institution'] ?? ''));
            $templateProcessor->setValue('father’s_name', mb_strtoupper($validated['father_name'] ?? ''));
            $templateProcessor->setValue('father’s_occupation', mb_strtoupper($validated['father_occupation'] ?? ''));
            $templateProcessor->setValue('father’s_monthly_income', (string) ($validated['father_monthly_income'] ?? ''));
            $templateProcessor->setValue('mother’s_name', mb_strtoupper($validated['mother_name'] ?? ''));
            $templateProcessor->setValue('mother’s_occupation', mb_strtoupper($validated['mother_occupation'] ?? ''));
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
        ?array $detailsSnapshot = null,
    ): CertificateIssuance {
        $attributes = [
            'barangay_id' => $barangayId,
            'certificate_request_id' => $linkedRequest?->id,
            'resident_id' => $resident?->id,
            'certificate_type' => $certType,
            'status' => CertificateIssuance::STATUS_ISSUED,
            'control_no' => $validated['control_no'],
            // The Marriage Cohabitation certificate names the resident from
            // the resident profile, so its snapshot is the trusted name and
            // never the client-supplied one.
            'resident_name' => $residentNameSnapshot ?? ($validated['resident_name'] ?? ''),
            'resident_number' => $resident?->resident_number,
            'purok' => $purokSnapshot ?? ($validated['purok'] ?? null),
            'fee' => $feeSnapshot,
            'details' => $detailsSnapshot,
            'issued_by' => $userId,
            'issued_at' => now(),
        ];

        for ($attempt = 1; $attempt <= self::REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $linkedRequest, $barangayId) {
                    // Serialize the control-number check within the issuing
                    // barangay. The unique index is the final concurrency guard.
                    DB::table('barangays')
                        ->where('id', $barangayId)
                        ->lockForUpdate()
                        ->first();

                    if (isset($attributes['details']['pdf_path'])) {
                        $next = CertificateControlNumbers::next($barangayId, $attributes['certificate_type']);
                        if ($attributes['control_no'] !== $next) {
                            throw ValidationException::withMessages([
                                'control_no' => 'The control number has advanced. Review the updated preview and try again.',
                                'next_control_no' => $next,
                            ]);
                        }
                    }
                    if (CertificateIssuance::query()
                        ->where('barangay_id', $barangayId)
                        ->where('certificate_type', $attributes['certificate_type'])
                        ->where('control_no', $attributes['control_no'])
                        ->exists()) {
                        throw ValidationException::withMessages([
                            'control_no' => 'This control number is already in use in your barangay. Generate a new number and try again.',
                        ]);
                    }

                    $issuance = CertificateIssuance::create([
                        ...$attributes,
                        'reference' => $this->makeReference(),
                    ]);

                    if ($linkedRequest !== null) {
                        $fee = (float) $linkedRequest->fee;
                        $nextStatus = $fee > 0 ? 'For Payment' : 'Ready for Claim';
                        $note = $fee > 0
                            ? 'Certificate generated. Payment required before claiming.'
                            : 'Certificate generated and ready for claiming.';

                        CertificateRequestWorkflow::apply($linkedRequest, $nextStatus, $note);
                    }

                    return $issuance;
                });
            } catch (QueryException $exception) {
                // A duplicate request link is a controlled, user-facing error:
                // one request may only be issued once.
                if ($this->isDuplicateRequestLink($exception)) {
                    throw ValidationException::withMessages([
                        'certificate_request_id' => 'This certificate request has already been issued.',
                    ]);
                }

                if ($this->isDuplicateControlNumber($exception)) {
                    throw ValidationException::withMessages([
                        'control_no' => 'This control number is already in use in your barangay. Generate a new number and try again.',
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

    private function isDuplicateControlNumber(QueryException $exception): bool
    {
        return $this->isUniqueViolation($exception)
            && (str_contains($exception->getMessage(), 'cert_issuances_type_control_unique')
                || str_contains($exception->getMessage(), 'control_no'));
    }

    private function cleanup(string $outputPath): void
    {
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }
    }

    /** Fill the single-braced signatory token in the current Marriage DOCX copy. */
    private function replaceLiteralDocxPlaceholder(string $path, string $placeholder, string $value): void
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open the generated certificate document.');
        }

        $xmlNames = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name)
                && preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                $xmlNames[] = $name;
            }
        }

        $found = false;
        $replacement = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        foreach ($xmlNames as $name) {

            $xml = $zip->getFromName($name);

            if (! is_string($xml) || ! str_contains($xml, $placeholder)) {
                continue;
            }

            $found = true;
            $updated = str_replace($placeholder, $replacement, $xml);
            $zip->deleteName($name);
            $zip->addFromString($name, $updated);
        }

        $closed = $zip->close();

        if (! $closed || ! $found) {
            throw new InvalidArgumentException('The Marriage template signatory marker could not be filled.');
        }
    }

    /** Replace the current fixed signatory label in a generated DOCX copy. */
    private function replaceDocxSignatoryTitle(string $path, mixed $title): void
    {
        if (! is_string($title) || trim($title) === '') {
            return;
        }

        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open the generated certificate document.');
        }

        $xmlNames = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name)
                && preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                $xmlNames[] = $name;
            }
        }

        foreach ($xmlNames as $name) {
            $xml = $zip->getFromName($name);

            if (! is_string($xml)) {
                continue;
            }

            $document = new \DOMDocument;
            $document->preserveWhiteSpace = true;

            if (! $document->loadXML($xml, LIBXML_NONET)) {
                continue;
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $changed = false;

            foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
                $textNodes = $xpath->query('.//w:t', $paragraph);
                $text = '';

                foreach ($textNodes ?: [] as $textNode) {
                    $text .= $textNode->textContent;
                }

                if (mb_strtolower(trim($text)) !== 'officer on duty' || $textNodes === false || $textNodes->length === 0) {
                    continue;
                }

                $firstText = $textNodes->item(0);
                while ($firstText->firstChild) {
                    $firstText->removeChild($firstText->firstChild);
                }
                $firstText->appendChild($document->createTextNode(trim($title)));

                for ($index = 1; $index < $textNodes->length; $index++) {
                    $textNodes->item($index)->nodeValue = '';
                }

                $changed = true;
            }

            if ($changed) {
                $zip->deleteName($name);
                $zip->addFromString($name, $document->saveXML() ?: $xml);
            }
        }

        $zip->close();
    }
}
