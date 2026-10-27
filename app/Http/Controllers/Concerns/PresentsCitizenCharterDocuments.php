<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CitizenCharterService;
use App\Models\CitizenCharterVersion;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait PresentsCitizenCharterDocuments
{
    /**
     * @return array<string, mixed>
     */
    protected function servicePayload(
        CitizenCharterService $service,
        bool $isStaff
    ): array {
        $currentVersion = $service->currentVersion;

        $payload = [
            'id' => $service->id,
            'title' => $service->title,
            'category' => $service->category,
            'description' => $service->description,
            'display_order' => $service->display_order,
            'status' => $service->computeStatus(),
            'is_published' => $service->is_published,
            'published_at' => $service->published_at?->toIso8601String(),
            'archived_at' => $service->archived_at?->toIso8601String(),
            'created_at' => $service->created_at?->toIso8601String(),
            'current_version' => $currentVersion
                ? $this->versionPayload($currentVersion, $isStaff, $service->current_version_id)
                : null,
        ];

        if ($isStaff) {
            $payload['creator_name'] = $service->creator?->name;
            $payload['show_url'] = route('clerk.citizen-charter.show', $service);
            $payload['update_url'] = route('clerk.citizen-charter.update', $service);
            $payload['publish_url'] = route('clerk.citizen-charter.publish', $service);
            $payload['archive_url'] = route('clerk.citizen-charter.archive', $service);
            $payload['upload_version_url'] = route('clerk.citizen-charter.versions.store', $service);

            // Include all version history for staff review
            if ($service->relationLoaded('versions')) {
                $payload['versions'] = $service->versions->map(
                    fn (CitizenCharterVersion $version) => $this->versionPayload(
                        $version,
                        true,
                        $service->current_version_id
                    )
                )->values();
            }
        } else {
            $payload['detail_url'] = route('resident.citizen-charter.show', $service);
            if ($currentVersion) {
                $payload['file_url'] = route('resident.citizen-charter.file', $service);
                $payload['download_url'] = route('resident.citizen-charter.download', $service);
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function versionPayload(
        CitizenCharterVersion $version,
        bool $isStaff,
        ?int $currentVersionId = null
    ): array {
        $isCurrent = $currentVersionId !== null && (int) $version->id === (int) $currentVersionId;

        $payload = [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'original_name' => $version->original_name,
            'mime_type' => $version->mime_type,
            'file_size' => $version->file_size,
            'status' => $version->status,
            'is_current' => $isCurrent,
            'published_at' => $version->published_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
        ];

        if ($isStaff) {
            $payload['uploader_name'] = $version->uploader?->name;
            $payload['file_url'] = route('clerk.citizen-charter.versions.file', [
                'service' => $version->service_id,
                'version' => $version->id,
            ]);
            $payload['download_url'] = route('clerk.citizen-charter.versions.download', [
                'service' => $version->service_id,
                'version' => $version->id,
            ]);
        }

        return $payload;
    }

    protected function documentFileResponse(
        CitizenCharterVersion $version,
        bool $download
    ): StreamedResponse {
        $disk = $version->disk ?: 'local';

        abort_unless(
            Storage::disk($disk)->exists($version->path),
            404,
            'The requested document file could not be found.'
        );

        $filename = basename(
            $version->original_name ?: 'citizen-charter.pdf'
        );
        $filename = preg_replace(
            '/[^\pL\pN._ -]+/u',
            '_',
            $filename
        ) ?? 'citizen-charter.pdf';
        $filename = mb_substr($filename, 0, 180, 'UTF-8');
        $inline = ! $download;

        return Storage::disk($disk)->response(
            $version->path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $inline ? 'inline' : 'attachment'
        );
    }
}
