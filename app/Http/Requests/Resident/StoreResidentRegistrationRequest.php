<?php

namespace App\Http\Requests\Resident;

use App\Models\Purok;
use App\Support\ResidentRegistrationDuplicateChecker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreResidentRegistrationRequest extends FormRequest
{
    private ?string $registrationQrToken = null;

    public function authorize(): bool
    {
        // Public resident registration.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Keep the optional QR token in memory only. It is intentionally removed
        // from the request input before validation failures can flash it to the
        // session or include it in old-input data.
        $qrToken = $this->request->get('qr_token');
        $this->registrationQrToken = is_string($qrToken)
            ? trim($qrToken)
            : null;
        $this->request->remove('qr_token');
        $this->query->remove('qr_token');

        $mobile = preg_replace('/[^0-9]/', '', (string) $this->mobile);

        // Convert +639XXXXXXXXX / 639XXXXXXXXX to 09XXXXXXXXX.
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
            'email' => trim((string) $this->email) !== '' ? strtolower(trim((string) $this->email)) : null,
            'sectors' => array_values(
                array_unique((array) $this->input('sectors', []))
            ),
        ]);
    }

    public function registrationQrToken(): ?string
    {
        return $this->registrationQrToken;
    }

    public function rules(): array
    {
        $selectedSectors = (array) $this->input('sectors', []);

        return [
            /*
            |--------------------------------------------------------------------------
            | Barangay / Purok
            |--------------------------------------------------------------------------
            */

            'barangay_id' => [
                'required',
                'integer',
                Rule::exists('barangays', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],

            'purok_id' => [
                'required',
                'integer',
                Rule::exists('puroks', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],

            /*
            |--------------------------------------------------------------------------
            | Personal Information
            |--------------------------------------------------------------------------
            */

            'first_name' => [
                'required',
                'string',
                'max:100',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'last_name' => [
                'required',
                'string',
                'max:100',
            ],

            'suffix' => [
                'nullable',
                'string',
                'max:20',
            ],

            'birthdate' => [
                'required',
                'date',
                'before_or_equal:today',
            ],

            'sex' => [
                'required',
                Rule::in([
                    'Male',
                    'Female',
                ]),
            ],

            'civil_status' => [
                'required',
                Rule::in([
                    'Single',
                    'Married',
                    'Widowed',
                    'Separated',
                ]),
            ],

            'address_line' => [
                'required',
                'string',
                'max:255',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sector Membership
            |--------------------------------------------------------------------------
            |
            | No selected sector = regular resident.
            |
            */

            'sectors' => [
                'nullable',
                'array',
                'max:2',
            ],

            'sectors.*' => [
                'string',
                'distinct',
                Rule::in([
                    'senior',
                    'pwd',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Contact / Credentials
            |--------------------------------------------------------------------------
            */

            'mobile' => [
                'required',
                'regex:/^09\d{9}$/',
            ],

            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->numbers(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Identity Documents
            |--------------------------------------------------------------------------
            |
            | Valid ID is captured live from the device camera and must be an image.
            |
            */

            'valid_id' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'max:5120',
            ],

            /*
            | Live photo only accepts images.
            | The frontend will generate this from the live camera.
            */

            'live_identity_photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png',
                'max:5120',
            ],

            'senior_proof' => [
                Rule::requiredIf(
                    in_array('senior', $selectedSectors, true)
                ),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],

            'pwd_proof' => [
                Rule::requiredIf(
                    in_array('pwd', $selectedSectors, true)
                ),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            /*
            |--------------------------------------------------------------------------
            | Security: Purok must belong to selected Barangay
            |--------------------------------------------------------------------------
            */

            if ($this->barangay_id && $this->purok_id) {
                $validPurok = Purok::query()
                    ->whereKey($this->purok_id)
                    ->where('barangay_id', $this->barangay_id)
                    ->where('is_active', true)
                    ->exists();

                if (! $validPurok) {
                    $validator->errors()->add(
                        'purok_id',
                        'The selected purok does not belong to the selected barangay.'
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate active/pending applications
            |--------------------------------------------------------------------------
            */

            if (! $validator->errors()->hasAny(['email', 'mobile', 'first_name', 'last_name', 'birthdate'])) {
                $duplicate = app(ResidentRegistrationDuplicateChecker::class)->find($this->all());
                if ($duplicate !== null) {
                    $validator->errors()->add('registration', $duplicate);
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Enter a valid Philippine mobile number, for example 09171234567.',

            'password.confirmed' => 'The password confirmation does not match.',

            'senior_proof.required' => 'Senior Citizen proof is required because you selected Senior Citizen.',

            'pwd_proof.required' => 'PWD proof is required because you selected PWD.',

            'live_identity_photo.required' => 'A live identity photo is required.',

            'valid_id.required' => 'Please capture a clear photo of your valid government ID using your device camera.',
            'valid_id.image' => 'The valid government ID must be a captured image (JPG or PNG).',
            'valid_id.mimes' => 'The valid government ID must be a JPG or PNG image.',
        ];
    }
}
