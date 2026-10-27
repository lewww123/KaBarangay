<?php

namespace App\Http\Requests\Admin;

use App\Support\ResidentRegistrationCorrectionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestResidentRegistrationCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'instructions' => ['required', 'string'],
            'field_targets' => ['sometimes', 'array'],
            'field_targets.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(ResidentRegistrationCorrectionPolicy::FIELD_TARGETS),
            ],
            'document_targets' => ['sometimes', 'array'],
            'document_targets.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(array_keys(ResidentRegistrationCorrectionPolicy::DOCUMENT_TARGETS)),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (trim((string) $this->input('instructions', '')) === '') {
                $validator->errors()->add('instructions', 'Enter correction instructions.');
            }

            if ($this->input('field_targets', []) === [] && $this->input('document_targets', []) === []) {
                $validator->errors()->add('field_targets', 'Select at least one field or document to correct.');
            }
        });
    }
}
