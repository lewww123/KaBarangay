<?php

namespace App\Http\Requests\Admin;

use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasActiveAssignment(['admin']) ?? false;
    }

    public function rules(): array
    {
        $barangayId = (int) $this->user()->barangay_id;
        $household = $this->route('household');
        $householdId = $household instanceof Household ? $household->id : (int) $household;

        // `household_code` is intentionally not validated or accepted here:
        // it is immutable once the household is created.
        return [
            'purok_id' => [
                'required',
                'integer',
                Rule::exists('puroks', 'id')
                    ->where(fn ($query) => $query
                        ->where('barangay_id', $barangayId)
                        ->where('is_active', true)),
            ],
            'head_resident_id' => [
                'nullable',
                'integer',
                Rule::exists('residents', 'id')
                    ->where(fn ($query) => $query
                        ->where('barangay_id', $barangayId)
                        ->where('household_id', $householdId)),
            ],
        ];
    }
}
