<?php

namespace App\Http\Requests\Admin;

use App\Models\Resident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreResidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
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
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $barangayId = (int) $this->user()->barangay_id;

            if ($this->mobile) {
                $mobileExists = Resident::query()
                    ->where('barangay_id', $barangayId)
                    ->where('mobile', $this->mobile)
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
