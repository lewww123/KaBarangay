<?php

namespace App\Http\Requests\Admin;

use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateResidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasActiveAssignment(['admin']) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $mobile = preg_replace('/[^0-9]/', '', (string) $this->mobile);
        $email = trim((string) $this->email);

        if (strlen($mobile) === 12 && str_starts_with($mobile, '63')) {
            $mobile = '0'.substr($mobile, 2);
        }

        $this->merge([
            'first_name' => trim((string) $this->first_name),
            'middle_name' => $this->middle_name
                ? trim((string) $this->middle_name)
                : null,
            'last_name' => trim((string) $this->last_name),
            'suffix' => $this->suffix
                ? trim((string) $this->suffix)
                : null,
            'address_line' => trim((string) $this->address_line),
            'mobile' => $mobile,
            'email' => $email === '' ? null : Str::lower($email),
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
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:30'],
            'birthdate' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'civil_status' => [
                'required',
                Rule::in(['Single', 'Married', 'Widowed', 'Separated']),
            ],
            'address_line' => ['required', 'string', 'max:500'],
            'mobile' => ['required', 'regex:/^09\d{9}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'household_mode' => ['nullable', Rule::in(['none', 'new', 'existing', 'preserve'])],
            'household_id' => [
                Rule::requiredIf($this->household_mode === 'existing'),
                'nullable',
                'integer',
                Rule::exists('households', 'id')->where('barangay_id', $barangayId),
            ],
            'household_relationship' => [
                Rule::requiredIf(in_array($this->household_mode, ['new', 'existing'], true)),
                'nullable', 'string', 'max:100',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $barangayId = (int) $this->user()->barangay_id;
            $resident = $this->route('resident');
            $residentId = $resident instanceof Resident ? $resident->id : (int) $resident;

            if ($this->mobile) {
                $mobileExists = Resident::query()
                    ->where('barangay_id', $barangayId)
                    ->where('mobile', $this->mobile)
                    ->where('id', '!=', $residentId)
                    ->exists();

                if ($mobileExists) {
                    $validator->errors()->add(
                        'mobile',
                        'A resident with this mobile number already exists in your barangay.'
                    );
                }
            }

            if ($this->email) {
                $emailExists = Resident::query()
                    ->where('barangay_id', $barangayId)
                    ->whereRaw('LOWER(email) = ?', [$this->email])
                    ->where('id', '!=', $residentId)
                    ->exists();

                if ($emailExists) {
                    $validator->errors()->add(
                        'email',
                        'A resident with this email address already exists in your barangay.'
                    );
                }
            }
        });
    }
}
