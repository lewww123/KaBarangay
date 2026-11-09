<?php

namespace App\Support;

use App\Models\LuponCase;
use App\Models\LuponDocument;
use App\Models\LuponHearing;
use App\Models\OrgChartPosition;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use Throwable;

final class LuponDocuments
{
    public const KINDS = ['notice_of_hearing' => 'Notice of Hearing', 'summons' => 'Summons', 'amicable_settlement' => 'Amicable Settlement', 'cfa' => 'Certificate to File Action'];

    public static function queueNotices(LuponCase $case, ?LuponHearing $hearing, int $actor): void
    {
        foreach (['notice_of_hearing', 'summons'] as $kind) {
            $record = $case->documents()->where('kind', $kind)->where('hearing_id', $hearing?->id)->whereIn('status', ['pending', 'ready'])->first();
            if (! $record) {
                LuponDocument::create(['case_id' => $case->id, 'kind' => $kind, 'hearing_id' => $hearing?->id, 'status' => 'pending', 'created_by' => $actor]);
            }
            if ($hearing && self::signers($case)['punong_barangay']) {
                self::generate($case, $kind, $actor, $hearing->id);
            }
        }
    }

    public static function signers(LuponCase $case): array
    {
        $officials = CertificateOfficials::resolve($case->barangay_id, $case->acting_captain_id);
        if ($case->acting_captain_id && $officials['punong_barangay']) {
            $officials['punong_barangay']['title'] = 'Acting Punong Barangay';
        }

        return $officials;
    }

    public static function generate(LuponCase $case, string $kind, int $actor, ?int $hearingId = null): LuponDocument
    {
        if (! array_key_exists($kind, self::KINDS)) {
            self::fail('Choose a supported Lupon document.');
        }

        return DB::transaction(function () use ($case, $kind, $actor, $hearingId) {
            $case = LuponCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $case->load(['parties', 'barangay', 'settlement']);
            $officials = self::signers($case);
            if (! $officials['punong_barangay']) {
                self::fail('Publish the Punong Barangay or select an eligible acting captain before generating this document.');
            }
            $hearing = null;
            if (in_array($kind, ['notice_of_hearing', 'summons'], true)) {
                if (! in_array($case->status, ['filed', 'mediation_active', 'pangkat_formed', 'conciliation_active'], true)) {
                    self::fail('New hearing notices cannot be prepared for a closed case.');
                }
                $hearing = $case->hearings()->when($hearingId, fn ($q) => $q->whereKey($hearingId))->whereIn('status', ['Scheduled', 'Rescheduled'])->orderBy('hearing_date')->first();
                if (! $hearing || ! $hearing->hearing_time) {
                    self::fail('Schedule a hearing with a date, time and venue first.');
                }
                $hearingId = $hearing->id;
            }
            if ($kind === 'amicable_settlement' && (! $case->settlement || ! in_array($case->status, ['settled', 'repudiated', 'certified_to_file_action'], true))) {
                self::fail('Record a written settlement before generating the settlement document.');
            }
            if ($kind === 'cfa' && $case->status !== 'certified_to_file_action') {
                self::fail('Complete and verify the CFA transition before generating a certification.');
            }
            $existing = $case->documents()->where('kind', $kind)->where('hearing_id', $hearingId)->whereIn('status', ['ready', 'pending'])->latest('id')->first();
            if ($existing?->status === 'ready' && Storage::disk('local')->exists($existing->path)) {
                return $existing;
            }
            $record = $existing ?? LuponDocument::create(['case_id' => $case->id, 'kind' => $kind, 'hearing_id' => $hearingId, 'created_by' => $actor]);
            $values = self::values($case, $officials, $hearing, $kind);
            $path = 'lupon/documents/'.$case->barangay_id.'/'.Str::uuid().'.docx';
            Storage::disk('local')->makeDirectory(dirname($path));
            DB::afterRollBack(fn () => Storage::disk('local')->delete($path));
            $configured = config('lupon.templates.'.$kind);
            $template = is_string($configured) && $configured !== '' ? $configured : self::defaultTemplate($case, $kind);
            $temporaryTemplate = ! $configured;
            if (! is_file($template) || ! is_readable($template)) {
                self::fail('The approved document template is unavailable. Contact the template administrator.');
            }
            $escaping = Settings::isOutputEscapingEnabled();
            try {
                Settings::setOutputEscapingEnabled(true);
                StagedDocument::write(Storage::disk('local')->path($path), function ($temporary) use ($template, $values, $officials) {
                    $processor = DynamicDocumentEngine::processor($template, $officials);
                    $unknown = array_diff($processor->getVariables(), array_keys($values));
                    if ($unknown) {
                        self::fail('This Lupon template contains unsupported merge fields. Review its approved field contract.');
                    }
                    foreach ($values as $key => $value) {
                        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
                        if (str_contains($value, "\n")) {
                            $run = new TextRun;
                            foreach (explode("\n", $value) as $index => $line) {
                                if ($index > 0) {
                                    $run->addTextBreak();
                                }
                                $run->addText($line);
                            }
                            $processor->setComplexValue($key, $run);
                        } else {
                            $processor->setValue($key, $value);
                        }
                    }
                    $processor->saveAs($temporary);
                    CertificateOfficialDocument::apply($temporary, $officials, false);
                });
                $record->update(['status' => 'ready', 'path' => $path, 'file_name' => $case->case_number.'-'.$kind.'.docx', 'created_by' => $actor, 'generated_at' => now(), 'signatories' => $officials]);
                AuditLogger::record('lupon_document.generated', barangayId: $case->barangay_id, actorId: $actor, entityType: 'lupon_document', entityId: $record->id, context: ['case_number' => $case->case_number, 'kind' => $kind]);

                return $record->fresh();
            } catch (Throwable $error) {
                Storage::disk('local')->delete($path);
                if ($error instanceof ValidationException) {
                    throw $error;
                } report($error);
                self::fail('The document could not be prepared. Check the approved template and try again.');
            } finally {
                Settings::setOutputEscapingEnabled($escaping);
                if ($temporaryTemplate && is_file($template)) {
                    @unlink($template);
                }
            }
        });
    }

    private static function values(LuponCase $case, array $officials, ?LuponHearing $hearing, string $kind): array
    {
        $complainants = $case->parties->where('party_type', 'complainant');
        $respondents = $case->parties->where('party_type', 'respondent');
        $secretary = OrgChartPosition::where('barangay_id', $case->barangay_id)->where('status', 'published')
            ->whereRaw('LOWER(TRIM(title)) IN (?, ?, ?)', ['secretary', 'barangay secretary', 'lupon secretary'])->orderBy('id')->first();
        $fromPangkat = $case->pangkat_chair_name && $case->pangkat_secretary_name;
        if ($kind === 'cfa' && ! $fromPangkat && ! filled($secretary?->assigned_person)) {
            self::fail('Publish the Barangay/Lupon Secretary before generating the CFA.');
        }
        $values = [
            'province' => $case->barangay?->province ?? '', 'city' => $case->barangay?->city_municipality ?? '', 'barangay' => $case->barangay?->name ?? '',
            'case_number' => $case->case_number, 'case_title' => $case->title, 'case_type' => $case->case_type,
            'complainants' => $complainants->pluck('name')->implode(', '), 'respondents' => $respondents->pluck('name')->implode(', '),
            'complainant_addresses' => $complainants->pluck('address')->filter()->implode('; '), 'respondent_addresses' => $respondents->pluck('address')->filter()->implode('; '),
            'hearing_date' => $hearing?->hearing_date->format('F j, Y') ?? '', 'hearing_time' => $hearing?->hearing_time ?? '', 'venue' => $hearing?->venue ?? '',
            'issued_date' => ($kind === 'cfa' ? $case->cfa_issued_at : Carbon::parse(LuponCaseWorkflow::today()))?->format('F j, Y') ?? '',
            'settlement_date' => $case->settlement?->settlement_date->format('F j, Y') ?? '', 'settlement_terms' => $case->settlement?->terms ?? '',
            'mediation_date' => $case->mediation_started_at?->format('F j, Y') ?? '', 'conciliation_date' => $case->conciliation_started_at?->format('F j, Y') ?? '',
            'repudiation_date' => $case->repudiated_at?->format('F j, Y') ?? '',
            'cfa_basis' => $case->repudiated_at ? 'A settlement was recorded on '.$case->settled_at?->format('F j, Y').', and a sworn repudiation was filed on '.$case->repudiated_at->format('F j, Y').'. The grounds and filing have been verified in the case record.' : 'Personal confrontation and mediation before the Punong Barangay did not resolve the dispute. The duly constituted Pangkat conducted conciliation, which likewise did not result in a settlement. The prerequisites for certification have been verified in the case record.',
            'punong_barangay' => $officials['punong_barangay']['name'], 'punong_barangay_title' => $officials['punong_barangay']['title'],
            'kagawad_names' => implode(', ', array_column($officials['kagawads'], 'name')),
            'pangkat_chair_name' => $case->pangkat_chair_name ?? '', 'pangkat_secretary_name' => $case->pangkat_secretary_name ?? '',
            'certifying_secretary' => $fromPangkat ? $case->pangkat_secretary_name : ($secretary?->assigned_person ?? ''),
            'secretary_title' => $fromPangkat ? 'Pangkat Secretary' : 'Lupon Secretary',
            'attesting_chair' => $fromPangkat ? $case->pangkat_chair_name : $officials['punong_barangay']['name'],
            'attesting_title' => $fromPangkat ? 'Pangkat Chairman' : $officials['punong_barangay']['title'],
        ];
        foreach ($case->parties as $party) {
            $values['party_signature_'.$party->id] = $party->name;
        }

        return $values;
    }

    private static function defaultTemplate(LuponCase $case, string $kind): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(12);
        $section = $word->addSection(['pageSizeW' => 11906, 'pageSizeH' => 16838, 'marginLeft' => 1134, 'marginRight' => 1134, 'marginTop' => 850, 'marginBottom' => 850]);
        foreach (['Republic of the Philippines', 'Province of ${province}', 'City/Municipality of ${city}', 'Barangay ${barangay}', 'OFFICE OF THE LUPONG TAGAPAMAYAPA'] as $line) {
            $section->addText($line, [], ['alignment' => 'center', 'spaceAfter' => 0]);
        }
        $section->addTextBreak();
        $section->addText(strtoupper(self::KINDS[$kind]), ['bold' => true, 'size' => 16], ['alignment' => 'center']);
        $section->addText('Barangay Case No. ${case_number}');
        $section->addText('For: ${case_type}');
        $section->addText('Complainant(s): ${complainants}');
        $section->addText('Respondent(s): ${respondents}');
        $section->addTextBreak();
        if ($kind === 'notice_of_hearing' || $kind === 'summons') {
            $section->addText($kind === 'summons' ? 'To: ${respondents}' : 'To: ${complainants}');
            $section->addText($kind === 'summons' ? 'You are directed to appear personally in connection with the above dispute.' : 'Please appear personally for a hearing of the above dispute.');
            $section->addText('Date: ${hearing_date}     Time: ${hearing_time}');
            $section->addText('Venue: ${venue}');
            $section->addText('Bring your supporting records and witnesses, if any. Service and actual receipt must be recorded by the Lupon desk.');
            $section->addText('Prepared on ${issued_date}.');
            $section->addTextBreak(2);
            $section->addText('${punong_barangay}', ['bold' => true], ['alignment' => 'right']);
            $section->addText('${punong_barangay_title}', [], ['alignment' => 'right']);
            $section->addTextBreak();
            $section->addText('Received by: ____________________    Date/time: ____________________');
        } elseif ($kind === 'amicable_settlement') {
            $section->addText('The parties recorded the following written agreement on ${settlement_date}:');
            $section->addText('${settlement_terms}');
            $section->addTextBreak();
            foreach ($case->parties as $party) {
                $section->addText('____________________________');
                $section->addText('${party_signature_'.$party->id.'}');
            }
            $section->addText('Attested by:');
            $section->addText('${attesting_chair}', ['bold' => true]);
            $section->addText('${attesting_title}');
        } else {
            $section->addText('${cfa_basis}');
            $section->addText('Accordingly, the dispute may be filed with the appropriate court or government office, subject to its jurisdiction and applicable rules.');
            $section->addText('Prepared on ${issued_date}.');
            $section->addTextBreak(2);
            $section->addText('${certifying_secretary}', ['bold' => true]);
            $section->addText('${secretary_title}');
            $section->addText('Attested by:');
            $section->addText('${attesting_chair}', ['bold' => true]);
            $section->addText('${attesting_title}');
        }
        $section->addFooter()->addText('Configurable review template — not a prescribed official form. Legal review and original signatures are required before official use.', ['size' => 8], ['alignment' => 'center']);
        $template = tempnam(sys_get_temp_dir(), 'lupon_template_');
        IOFactory::createWriter($word, 'Word2007')->save($template);

        return $template;
    }

    public static function summary(LuponDocument $document, string $portal = 'lupon'): array
    {
        $route = $portal === 'resident' ? 'resident.lupon.documents.download' : 'lupon.documents.download';

        return ['id' => $document->id, 'kind' => $document->kind, 'label' => self::KINDS[$document->kind] ?? $document->kind, 'status' => $document->status, 'hearingId' => $document->hearing_id, 'generatedAt' => $document->generated_at?->toIso8601String(), 'issuedAt' => $document->issued_at?->toIso8601String(), 'servedAt' => $document->served_at?->toIso8601String(), 'url' => $document->status === 'ready' ? route($route, ['document' => $document->id]) : null];
    }

    public static function invalidateNotices(LuponCase $case): void
    {
        // Only prepared, unissued copies are superseded. Issued or served
        // notices stay untouched as legal history.
        $case->documents()
            ->whereIn('kind', ['notice_of_hearing', 'summons'])
            ->where('status', 'ready')
            ->whereNull('issued_at')
            ->whereNull('served_at')
            ->update(['status' => 'superseded']);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
