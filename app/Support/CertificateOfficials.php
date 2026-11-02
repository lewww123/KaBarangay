<?php

namespace App\Support;

use App\Models\OrgChartPosition;
use Illuminate\Validation\ValidationException;

class CertificateOfficials
{
    public const TEMPLATE_KEYS = ['punong_barangay', 'punong_barangay_title', 'kagawad_names'];

    public static function resolve(int $barangayId, ?int $actingCaptainId = null): array
    {
        $positions = OrgChartPosition::query()->where('barangay_id', $barangayId)
            ->where('status', 'published')->whereNotNull('assigned_person')
            ->where('assigned_person', '<>', '')->orderBy('id')
            ->get(['id', 'title', 'assigned_person']);
        $kagawads = $positions->filter(fn ($position) => str_contains(mb_strtolower($position->title), 'kagawad'));
        $default = $positions->first(fn ($position) => in_array(mb_strtolower(trim($position->title)), ['punong barangay', 'barangay captain'], true));
        $captain = $default;
        if ($actingCaptainId !== null) {
            $captain = $kagawads->firstWhere('id', $actingCaptainId);
            if (! $captain) {
                throw ValidationException::withMessages(['acting_captain_id' => 'Choose a currently published Kagawad from your barangay.']);
            }
        }
        $key = fn (string $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
        $person = fn ($position, ?string $title = null) => $position ? [
            'id' => $position->id, 'name' => trim($position->assigned_person), 'title' => $title ?? $position->title,
        ] : null;

        return [
            'default_punong_barangay' => $person($default, 'Punong Barangay'),
            'punong_barangay' => $person($captain, $actingCaptainId !== null ? 'Barangay Kagawad, OIC' : 'Punong Barangay'),
            'active_kagawads' => $kagawads->sortByDesc(fn ($position) => $position->id === $actingCaptainId)->unique(fn ($position) => $key($position->assigned_person))->map(fn ($position) => $person($position))->values()->all(),
            'kagawads' => $kagawads->filter(fn ($position) => $position->id !== $actingCaptainId && (! $captain || $key($position->assigned_person) !== $key($captain->assigned_person)))
                ->unique(fn ($position) => $key($position->assigned_person))->map(fn ($position) => $person($position))->values()->all(),
        ];
    }
}
