<?php

namespace App\Http\Requests\Clerk;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreCitizenCharterServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'document' => [
                'required',
                'file',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validatePdfFile($value, $fail);
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document.max' => 'The document may not be larger than 2 MB due to server upload limits.',
        ];
    }

    protected function validatePdfFile(mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The document must be a valid uploaded file.');

            return;
        }

        $extension = strtolower(
            $value->getClientOriginalExtension()
        );
        $mimeType = strtolower(
            (string) $value->getMimeType()
        );

        if ($extension !== 'pdf') {
            $fail('The document must be a PDF file.');

            return;
        }

        if ($value->getSize() === 0) {
            $fail('The document file is empty.');

            return;
        }

        if (! in_array($mimeType, [
            'application/pdf',
            'application/x-pdf',
            'application/octet-stream',
        ], true)) {
            $fail('The PDF file type could not be verified.');

            return;
        }

        $handle = fopen($value->getRealPath(), 'rb');

        if ($handle === false) {
            $fail('The PDF file could not be read.');

            return;
        }

        $signature = fread($handle, 5);
        fclose($handle);

        if ($signature !== '%PDF-') {
            $fail('The file is not a valid PDF document.');
        }
    }
}
