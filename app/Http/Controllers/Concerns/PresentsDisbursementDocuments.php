<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DisbursementDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait PresentsDisbursementDocuments
{
    private const CSV_PREVIEW_MAX_ROWS = 200;

    private const CSV_PREVIEW_MAX_COLUMNS = 30;

    private const CSV_PREVIEW_MAX_CELL_LENGTH = 500;

    /**
     * @return array<string, mixed>
     */
    protected function documentPayload(
        DisbursementDocument $document,
        bool $staff
    ): array {
        $routeName = $staff
            ? 'clerk.disbursements'
            : 'resident.disbursement';

        $payload = [
            'id' => $document->id,
            'title' => $document->title,
            'description' => $document->description,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'is_pdf' => $document->isPdf(),
            'is_csv' => $document->isCsv(),
            'status' => $document->status(),
            'is_published' => $document->is_published,
            'published_at' => $document->published_at?->toIso8601String(),
            'archived_at' => $document->archived_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'preview_url' => route($routeName.'.show', $document),
            'file_url' => route($routeName.'.file', $document),
            'download_url' => route($routeName.'.download', $document),
        ];

        if ($staff) {
            $payload['uploader_name'] = $document->uploader?->name;
            $payload['publish_url'] = route(
                $routeName.'.publish',
                $document
            );
            $payload['archive_url'] = route(
                $routeName.'.archive',
                $document
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function csvPreview(
        DisbursementDocument $document
    ): ?array {
        if (! $document->isCsv()) {
            return null;
        }

        $disk = $document->disk ?: 'local';

        if (! Storage::disk($disk)->exists($document->path)) {
            return $this->emptyCsvPreview(
                'The document file is unavailable.'
            );
        }

        $stream = Storage::disk($disk)->readStream($document->path);

        if (! is_resource($stream)) {
            return $this->emptyCsvPreview(
                'The document file could not be read.'
            );
        }

        $columns = [];
        $rows = [];
        $hasHeader = false;
        $rowsTruncated = false;
        $columnsTruncated = false;
        $cellsTruncated = false;

        while (($record = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if (! $hasHeader) {
                $hasHeader = true;

                if (count($record) > self::CSV_PREVIEW_MAX_COLUMNS) {
                    $record = array_slice(
                        $record,
                        0,
                        self::CSV_PREVIEW_MAX_COLUMNS
                    );
                    $columnsTruncated = true;
                }

                foreach ($record as $index => $value) {
                    $header = $this->csvCell($value);

                    if ($index === 0) {
                        $header = preg_replace(
                            '/^\xEF\xBB\xBF/',
                            '',
                            $header
                        ) ?? $header;
                    }

                    $columns[] = $header === ''
                        ? 'Column '.($index + 1)
                        : $header;
                }

                continue;
            }

            if (count($rows) >= self::CSV_PREVIEW_MAX_ROWS) {
                $rowsTruncated = true;

                break;
            }

            if (count($record) > count($columns)) {
                $record = array_slice($record, 0, count($columns));
                $columnsTruncated = true;
            }

            $row = [];

            foreach ($columns as $index => $column) {
                $value = (string) ($record[$index] ?? '');

                if (
                    mb_strlen($value, 'UTF-8') >
                    self::CSV_PREVIEW_MAX_CELL_LENGTH
                ) {
                    $cellsTruncated = true;
                }

                $row[] = $this->csvCell($value);
            }

            $rows[] = $row;
        }

        fclose($stream);

        if (! $hasHeader) {
            return $this->emptyCsvPreview(
                'This CSV file does not contain any readable rows.'
            );
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'row_limit' => self::CSV_PREVIEW_MAX_ROWS,
            'column_limit' => self::CSV_PREVIEW_MAX_COLUMNS,
            'rows_truncated' => $rowsTruncated,
            'columns_truncated' => $columnsTruncated,
            'cells_truncated' => $cellsTruncated,
            'error' => null,
        ];
    }

    protected function documentFileResponse(
        DisbursementDocument $document,
        bool $download
    ): StreamedResponse {
        $disk = $document->disk ?: 'local';

        abort_unless(
            Storage::disk($disk)->exists($document->path),
            404
        );

        $filename = basename(
            $document->original_name ?: 'disbursement-document'
        );
        $filename = preg_replace(
            '/[^\pL\pN._ -]+/u',
            '_',
            $filename
        ) ?? 'disbursement-document';
        $filename = mb_substr($filename, 0, 180, 'UTF-8');
        $inline = $document->isPdf() && ! $download;

        return Storage::disk($disk)->response(
            $document->path,
            $filename,
            [
                'Content-Type' => $document->isPdf()
                    ? 'application/pdf'
                    : 'text/csv; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $inline ? 'inline' : 'attachment'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCsvPreview(string $message): array
    {
        return [
            'columns' => [],
            'rows' => [],
            'row_limit' => self::CSV_PREVIEW_MAX_ROWS,
            'column_limit' => self::CSV_PREVIEW_MAX_COLUMNS,
            'rows_truncated' => false,
            'columns_truncated' => false,
            'cells_truncated' => false,
            'error' => $message,
        ];
    }

    private function csvCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');
        $cell = mb_scrub($cell, 'UTF-8');

        if (
            mb_strlen($cell, 'UTF-8') >
            self::CSV_PREVIEW_MAX_CELL_LENGTH
        ) {
            return mb_substr(
                $cell,
                0,
                self::CSV_PREVIEW_MAX_CELL_LENGTH,
                'UTF-8'
            ).'…';
        }

        return $cell;
    }
}
