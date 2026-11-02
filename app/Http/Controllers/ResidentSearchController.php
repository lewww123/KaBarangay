<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResidentSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $barangayId = $request->user()->barangay_id;
        abort_unless($barangayId !== null, 403);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $term = preg_replace('/\s+/u', ' ', trim($validated['q'] ?? ''));
        $results = [];

        if (mb_strlen($term) >= 2) {
            // Literal prefix searches keep the indexed name columns usable.
            $prefix = fn (string $value) => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
            $postgres = DB::connection()->getDriverName() === 'pgsql';
            $term = $postgres ? mb_strtolower($term) : $term;
            $firstColumn = $postgres ? 'LOWER(r.first_name)' : 'r.first_name';
            $lastColumn = $postgres ? 'LOWER(r.last_name)' : 'r.last_name';
            $parts = explode(' ', $term, 2);
            $base = DB::table('residents as r')
                ->leftJoin('puroks as p', fn ($join) => $join->on('p.id', '=', 'r.purok_id')->on('p.barangay_id', '=', 'r.barangay_id'))
                ->where('r.barangay_id', $barangayId)
                ->where('r.status', 'active')
                ->select('r.id', 'r.first_name', 'r.middle_name', 'r.last_name', 'r.suffix', 'p.name as purok', 'r.birthdate', 'r.sex', 'r.civil_status', 'r.address_line as address_details');

            $first = (clone $base)->whereRaw("{$firstColumn} LIKE ? ESCAPE '!'", [$prefix($parts[0])]);
            if (isset($parts[1])) {
                $first->whereRaw("{$lastColumn} LIKE ? ESCAPE '!'", [$prefix($parts[1])]);
            }
            $last = (clone $base)->whereRaw("{$lastColumn} LIKE ? ESCAPE '!'", [$prefix($term)]);
            // Each indexed branch reads at most five rows. UNION deduplicates at most
            // ten candidates in one database round trip, then caps the JSON result at five.
            $matches = DB::query()
                ->fromSub($first->orderBy('r.first_name')->orderBy('r.id')->limit(5), 'first_matches')
                ->union(DB::query()->fromSub($last->orderBy('r.last_name')->orderBy('r.id')->limit(5), 'last_matches'))
                ->orderBy('first_name')->orderBy('id')
                ->limit(5)->get();
            foreach ($matches as $resident) {
                $birthdate = $resident->birthdate ? Carbon::parse($resident->birthdate) : null;
                $candidate = [
                    'id' => $resident->id,
                    'full_name' => implode(' ', array_filter([$resident->first_name, $resident->middle_name, $resident->last_name, $resident->suffix], fn ($part) => filled($part))),
                    'purok' => $resident->purok ?? '',
                    'birthdate' => $birthdate?->toDateString(),
                    'age' => $birthdate ? (int) $birthdate->age : null,
                    'sex' => $resident->sex,
                    'civil_status' => $resident->civil_status,
                    'address_details' => $resident->address_details ?? '',
                ];
                // Preserve complete names/addresses; fewer results are preferable to truncating identity data.
                if (strlen(json_encode(['residents' => [...$results, $candidate]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) < 2048) {
                    $results[] = $candidate;
                }
            }
        }

        return response()->json(['residents' => $results], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
