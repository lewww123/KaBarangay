<?php

namespace App\Support;

use App\Models\CertificateRequest;
use App\Models\LuponCase;
use App\Models\LuponComplaint;
use App\Models\Resident;
use App\Models\SectorApplication;

final class AssistantResidentRecords
{
    public function snapshot(Resident $resident, ?string $reference = null): array
    {
        $records = [];
        foreach (CertificateRequest::query()->with('certificate:id,name')->where('resident_id', $resident->id)->where('barangay_id', $resident->barangay_id)->when($reference, fn ($q) => $q->where('reference', $reference))->latest('updated_at')->limit(5)->get() as $item) {
            $records[] = ['category' => 'certificate', 'reference' => $item->reference, 'label' => $item->certificate?->name ?? 'Certificate request', 'status' => $item->status, 'detail' => 'Isinumite: '.($item->submitted_at?->format('M j, Y') ?? $item->created_at?->format('M j, Y')), 'url' => '/resident/certificates', 'updatedAt' => $item->updated_at?->toIso8601String()];
        }
        foreach (LuponComplaint::query()->where('resident_id', $resident->id)->where('barangay_id', $resident->barangay_id)->when($reference, fn ($q) => $q->where('reference', $reference))->latest('updated_at')->limit(5)->get() as $item) {
            $records[] = ['category' => 'complaint', 'reference' => $item->reference, 'label' => 'Lupon complaint', 'status' => $item->status, 'detail' => 'Isinumite: '.($item->submitted_at?->format('M j, Y') ?? $item->created_at?->format('M j, Y')), 'url' => '/resident/lupon', 'updatedAt' => $item->updated_at?->toIso8601String()];
        }
        $cases = LuponCase::query()->where('barangay_id', $resident->barangay_id)->when($reference, fn ($q) => $q->where('case_number', $reference))->where(function ($query) use ($resident) {
            $query->whereHas('complaint', fn ($q) => $q->where('resident_id', $resident->id))
                ->orWhereHas('parties', fn ($q) => $q->where('resident_id', $resident->id)->where(function ($q) {
                    $q->where('party_type', 'complainant')->orWhere(fn ($q) => $q->where('party_type', 'respondent')->where('association_status', 'verified'));
                }));
        })->with(['hearings' => fn ($q) => $q->whereIn('status', ['Scheduled', 'Rescheduled'])->orderBy('hearing_date')->orderBy('hearing_time')])->latest('updated_at')->limit(5)->get();
        foreach ($cases as $item) {
            $next = $item->hearings->first();
            $records[] = ['category' => 'case', 'reference' => $item->case_number, 'label' => 'Lupon case', 'status' => LuponCaseWorkflow::LABELS[$item->status] ?? $item->status, 'detail' => 'Phase: '.$item->phase.($next ? ' · Hearing: '.$next->hearing_date->format('M j, Y').' '.$next->hearing_time.' · '.$next->venue : ''), 'url' => '/resident/lupon/cases/'.$item->id, 'updatedAt' => $item->updated_at?->toIso8601String()];
        }
        foreach (SectorApplication::query()->with('sector:id,name')->where('resident_id', $resident->id)->where('barangay_id', $resident->barangay_id)->when($reference, fn ($q) => $q->where('tracking_number', $reference))->latest('updated_at')->limit(5)->get() as $item) {
            $records[] = ['category' => 'sector', 'reference' => $item->tracking_number, 'label' => ($item->sector?->name ?? 'Sector').' application', 'status' => ucwords(str_replace('_', ' ', $item->status)), 'detail' => 'Isinumite: '.$item->created_at?->format('M j, Y'), 'url' => '/resident/sector-applications', 'updatedAt' => $item->updated_at?->toIso8601String()];
        }
        usort($records, fn ($a, $b) => strcmp($b['updatedAt'] ?? '', $a['updatedAt'] ?? ''));

        return $records;
    }

    public function isTrackingQuestion(string $message): bool
    {
        if (preg_match('/\b(paano|how|proseso|process|steps)\b/iu', $message)) {
            return false;
        }

        return preg_match('/\b(track(?:ing)?|status|update|progress|reference|nasaan|kumusta|kamusta)\b|request ko|kaso ko|reklamo ko|application ko|approved na|ready na|naaprubahan/iu', $message) === 1;
    }

    public function trackingReply(string $message, array $records): array
    {
        if (preg_match('/certificate|indigen|clearance|sertipiko/iu', $message)) {
            $records = array_filter($records, fn ($r) => $r['category'] === 'certificate');
        } elseif (preg_match('/lupon|kaso|case|reklamo|complaint/iu', $message)) {
            $records = array_filter($records, fn ($r) => in_array($r['category'], ['case', 'complaint'], true));
        } elseif (preg_match('/sector|membership/iu', $message)) {
            $records = array_filter($records, fn ($r) => $r['category'] === 'sector');
        }
        if (preg_match('/\b[A-Z]{2,12}-[A-Z0-9-]*\d[A-Z0-9-]*\b/i', $message, $match)) {
            $records = array_filter($records, fn ($r) => strcasecmp((string) $r['reference'], $match[0]) === 0);
        }
        $records = array_values($records);

        return ['reply' => $records === [] ? 'Wala akong nakitang matching request sa pinakahuling records ng account mo. Tingnan ang My Requests o Lupon page para sa buong listahan. Hindi ako makakapagpakita ng records ng ibang residente.' : 'Ito ang kasalukuyang status mula sa records ng account mo:'."\n\n".implode("\n\n", array_map(fn ($r) => $r['label'].' · '.$r['reference']."\nStatus: ".$r['status']."\n".$r['detail'], $records)), 'records' => $records, 'source' => 'resident_records', 'checkedAt' => now(config('lupon.timezone', 'Asia/Manila'))->toIso8601String()];
    }
}
