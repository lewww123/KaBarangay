<?php

namespace App\Http\Requests\Clerk;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreDisbursementDocumentRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
            'document' => [
                'required',
                'file',
                'max:5120',
                function (string $attribute, mixed $value, Closure $fail): void {
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

                    if (! in_array($extension, ['pdf', 'csv'], true)) {
                        $fail('The document must be a PDF or CSV file.');

                        return;
                    }

                    if ($value->getSize() === 0) {
                        $fail('The document file is empty.');

                        return;
                    }

                    if ($extension === 'pdf') {
                        $this->validatePdf($value, $mimeType, $fail);

                        return;
                    }

                    $this->validateCsv($value, $mimeType, $fail);
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document.max' => 'The document may not be larger than 5 MB.',
        ];
    }

    private function validatePdf(
        UploadedFile $file,
        string $mimeType,
        Closure $fail
    ): void {
        if (! in_array($mimeType, [
            'application/pdf',
            'application/x-pdf',
            'application/octet-stream',
        ], true)) {
            $fail('The PDF file type could not be verified.');

            return;
        }

        $handle = fopen($file->getRealPath(), 'rb');

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

    private function validateCsv(
        UploadedFile $file,
        string $mimeType,
        Closure $fail
    ): void {
        if (! in_array($mimeType, [
            'text/csv',
            'application/csv',
            'text/plain',
            'application/vnd.ms-excel',
        ], true)) {
            $fail('The CSV file type could not be verified.');

            return;
        }

        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            $fail('The CSV file could not be read.');

            return;
        }

        while (! feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                fclose($handle);
                $fail('The CSV file could not be read.');

                return;
            }

            if (str_contains($chunk, "\0")) {
                fclose($handle);
                $fail('The file is not a valid CSV document.');

                return;
            }
        }

        fclose($handle);
    }
}
