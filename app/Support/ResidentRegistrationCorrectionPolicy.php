<?php

namespace App\Support;

use App\Models\RegistrationDocument;
use App\Models\Purok;
use App\Models\ResidentRegistration;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class ResidentRegistrationCorrectionPolicy
{
    /** @var list<string> */
    public const FIELD_TARGETS = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'sex',
        'civil_status',
        'address_line',
        'mobile',
        'email',
        'purok_id',
    ];

    /** @var array<string, array{document_type: string, sector_slug: ?string, label: string}> */
    public const DOCUMENT_TARGETS = [
        'valid_id' => [
            'document_type' => 'valid_id',
            'sector_slug' => null,
            'label' => 'Valid ID',
        ],
        'live_identity_photo' => [
            'document_type' => 'live_identity_photo',
            'sector_slug' => null,
            'label' => 'Live Identity Photo',
        ],
        'senior_proof' => [
            'document_type' => 'sector_proof',
            'sector_slug' => 'senior',
            'label' => 'Senior Citizen Proof',
        ],
        'pwd_proof' => [
            'document_type' => 'sector_proof',
            'sector_slug' => 'pwd',
            'label' => 'PWD Proof',
        ],
    ];

    /** @return array<string, mixed> */
    public static function fieldDescriptor(string $field, ResidentRegistration $registration): array
    {
        $value = $registration->getAttribute($field);
        if ($field === 'birthdate') {
            $value = $registration->birthdate?->format('Y-m-d');
        }

        return [
            'key' => $field,
            'label' => match ($field) {
                'first_name' => 'First name',
                'middle_name' => 'Middle name',
                'last_name' => 'Last name',
                'suffix' => 'Suffix',
                'birthdate' => 'Birthdate',
                'sex' => 'Sex',
                'civil_status' => 'Civil status',
                'address_line' => 'Address',
                'mobile' => 'Mobile number',
                'email' => 'Email address',
                'purok_id' => 'Purok',
                default => $field,
            },
            'type' => match ($field) {
                'birthdate' => 'date',
                'email' => 'email',
                'mobile' => 'tel',
                'sex', 'civil_status', 'purok_id' => 'select',
                default => 'text',
            },
            'value' => $field === 'purok_id' ? (string) $value : ($value === null ? '' : (string) $value),
            'options' => match ($field) {
                'sex' => ['Male', 'Female'],
                'civil_status' => ['Single', 'Married', 'Widowed', 'Separated'],
                'purok_id' => Purok::query()
                    ->where('barangay_id', $registration->barangay_id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($purok) => ['value' => (string) $purok->id, 'label' => $purok->name])
                    ->values()
                    ->all(),
                default => [],
            },
        ];
    }

    /** @return array<string, mixed> */
    public static function documentDescriptor(string $target): array
    {
        $definition = self::DOCUMENT_TARGETS[$target];

        return [
            'key' => $target,
            'label' => $definition['label'],
        ];
    }

    /** @return list<mixed> */
    public static function validationRules(string $field, int $barangayId): array
    {
        return match ($field) {
            'first_name', 'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['present', 'nullable', 'string', 'max:100'],
            'suffix' => ['present', 'nullable', 'string', 'max:20'],
            'birthdate' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'civil_status' => ['required', Rule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'address_line' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'regex:/^09\d{9}$/'],
            'email' => ['present', 'nullable', 'email:rfc', 'max:255'],
            'purok_id' => [
                'required',
                'integer',
                Rule::exists('puroks', 'id')->where(
                    fn ($query) => $query->where('barangay_id', $barangayId)->where('is_active', true)
                ),
            ],
            default => ['prohibited'],
        };
    }

    /** @param Collection<int, RegistrationDocument> $documents
     *  @return list<string>
     */
    public static function availableDocumentTargets(Collection $documents): array
    {
        $available = [];
        $documentIdsWithSuccessors = $documents->pluck('supersedes_document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (self::DOCUMENT_TARGETS as $target => $definition) {
            $matches = $documents->filter(function (RegistrationDocument $document) use ($definition): bool {
                return $document->superseded_at === null
                    && $document->document_type === $definition['document_type']
                    && ($definition['sector_slug'] === null
                        ? $document->sector_id === null
                        : $document->sector?->slug === $definition['sector_slug'])
                    && $document->id !== null;
            })->reject(fn (RegistrationDocument $document) => in_array(
                (int) $document->id,
                $documentIdsWithSuccessors,
                true,
            ));

            // A target must resolve to exactly one current document. Ambiguous
            // legacy data is left for Admin review instead of replacing a guess.
            if ($matches->count() === 1) {
                $available[] = $target;
            }
        }

        return $available;
    }

    /** @param Collection<int, RegistrationDocument> $documents */
    public static function currentDocumentForTarget(Collection $documents, string $target): ?RegistrationDocument
    {
        $definition = self::DOCUMENT_TARGETS[$target] ?? null;
        if ($definition === null) {
            return null;
        }

        $documentIdsWithSuccessors = $documents->pluck('supersedes_document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
        $matches = $documents->filter(function (RegistrationDocument $document) use ($definition): bool {
            return $document->superseded_at === null
                && $document->document_type === $definition['document_type']
                && ($definition['sector_slug'] === null
                    ? $document->sector_id === null
                    : $document->sector?->slug === $definition['sector_slug'])
                && $document->id !== null;
        })->reject(fn (RegistrationDocument $document) => in_array(
            (int) $document->id,
            $documentIdsWithSuccessors,
            true,
        ));

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
