<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasActiveAssignment(['admin']) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $householdCode = trim((string) $this->household_code);

        $this->merge([
            'household_code' => $householdCode === '' ? null : Str::upper($householdCode),
        ]);
    }

    public function rules(): array
    {
        $barangayId = (int) $this->user()->barangay_id;

        return [
            'purok_id' => [
                'required',
                'integer',
                Rule::exists('puroks', 'id')
                    ->where(fn ($query) => $query
                        ->where('barangay_id', $barangayId)
                        ->where('is_active', true)),
            ],
            'household_code' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('households', 'household_code')
                    ->where(fn ($query) => $query->where('barangay_id', $barangayId)),
            ],
            'head_resident_id' => [
                'nullable',
                'integer',
                Rule::exists('residents', 'id')
                    ->where(fn ($query) => $query->where('barangay_id', $barangayId)),
            ],
            'members' => ['nullable', 'array'],
            'members.*.resident_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('residents', 'id')
                    ->where(fn ($query) => $query->where('barangay_id', $barangayId)),
            ],
            'members.*.household_relationship' => ['required', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $members = $this->input('members', []);
            $headId = $this->input('head_resident_id');

            if ($headId) {
                if (empty($members)) {
                    $validator->errors()->add(
                        'head_resident_id',
                        'A household head cannot be designated without members in the household.'
                    );
                } else {
                    $memberIds = array_map(fn ($m) => (int) ($m['resident_id'] ?? 0), $members);
                    if (! in_array((int) $headId, $memberIds, true)) {
                        $validator->errors()->add(
                            'head_resident_id',
                            'The household head must be an actual member of this household.'
                        );
                    }
                }
            }
        });
    }
}
