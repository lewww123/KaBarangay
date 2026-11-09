<?php

namespace App\Http\Requests\Admin;

use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferHouseholdMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasActiveAssignment(['admin']) ?? false;
    }

    public function rules(): array
    {
        $barangayId = (int) $this->user()->barangay_id;
        $sourceHousehold = $this->route('household');
        $sourceHouseholdId = $sourceHousehold instanceof Household ? $sourceHousehold->id : (int) $sourceHousehold;

        return [
            'target_household_id' => [
                'required',
                'integer',
                'different:'.$sourceHouseholdId,
                Rule::exists('households', 'id')
                    ->where(fn ($query) => $query->where('barangay_id', $barangayId)),
            ],
            'household_relationship' => ['required', 'string', 'max:100'],
            'is_head' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_household_id.different' => 'The target household must be different from the current household.',
        ];
    }
}
