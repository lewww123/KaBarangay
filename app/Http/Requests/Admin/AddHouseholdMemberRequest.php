<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddHouseholdMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasActiveAssignment(['admin']) ?? false;
    }

    public function rules(): array
    {
        $barangayId = (int) $this->user()->barangay_id;

        return [
            'resident_id' => [
                'required',
                'integer',
                Rule::exists('residents', 'id')
                    ->where(fn ($query) => $query->where('barangay_id', $barangayId)),
            ],
            'household_relationship' => ['required', 'string', 'max:100'],
            'is_head' => ['nullable', 'boolean'],
        ];
    }
}
