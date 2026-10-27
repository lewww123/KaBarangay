<?php

namespace App\Services;

use App\Models\Resident;
use App\Models\Sector;
use App\Models\SectorApplication;
use App\Models\SectorApplicationDocument;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SectorApplicationService
{
    /**
     * Staff-assisted application submission.
     *
     * @param  array<string, mixed>  $details
     * @param  array<int, array{file: UploadedFile, type: string, title?: string}>  $documents
     */
    public function submitStaffAssisted(
        User $staff,
        int $residentId,
        string $sectorSlug,
        array $details = [],
        array $documents = []
    ): SectorApplication {
        // Enforce staff role authorization for the target sector
        if ($sectorSlug === 'senior' && ! in_array($staff->role, ['senior', 'senior_staff'], true)) {
            throw ValidationException::withMessages([
                'sector' => 'You are not authorized to submit applications for the Senior sector.',
            ]);
        }

        if ($sectorSlug === 'pwd' && ! in_array($staff->role, ['pwd', 'pwd_staff'], true)) {
            throw ValidationException::withMessages([
                'sector' => 'You are not authorized to submit applications for the PWD sector.',
            ]);
        }

        $resident = Resident::query()
            ->where('id', $residentId)
            ->where('barangay_id', $staff->barangay_id)
            ->first();

        if (! $resident || $resident->status !== 'active') {
            throw ValidationException::withMessages([
                'resident_id' => 'Resident not found, does not belong to your barangay, or is inactive.',
            ]);
        }

        $sector = Sector::query()->where('slug', $sectorSlug)->first();
        if (! $sector) {
            throw ValidationException::withMessages([
                'sector' => 'The requested sector does not exist.',
            ]);
        }

        return $this->createApplication(
            barangayId: $staff->barangay_id,
            resident: $resident,
            sector: $sector,
            submissionType: SectorApplication::SUBMISSION_STAFF_ASSISTED,
            submittingUserId: $staff->id,
            details: $details,
            documents: $documents
        );
    }

    /**
     * Resident self-service application submission.
     *
     * @param  array<string, mixed>  $details
     * @param  array<int, array{file: UploadedFile, type: string, title?: string}>  $documents
     */
    public function submitSelfService(
        Resident $resident,
        string $sectorSlug,
        array $details = [],
        array $documents = []
    ): SectorApplication {
        if ($resident->status !== 'active') {
            throw ValidationException::withMessages([
                'resident' => 'Only active residents may apply for sector membership.',
            ]);
        }

        $sector = Sector::query()->where('slug', $sectorSlug)->first();
        if (! $sector) {
            throw ValidationException::withMessages([
                'sector' => 'The requested sector does not exist.',
            ]);
        }

        return $this->createApplication(
            barangayId: $resident->barangay_id,
            resident: $resident,
            sector: $sector,
            submissionType: SectorApplication::SUBMISSION_SELF_SERVICE,
            submittingUserId: null,
            details: $details,
            documents: $documents
        );
    }

    /**
     * Core submission logic with concurrency locks, duplicate prevention, and file storage.
     *
     * @param  array<string, mixed>  $details
     * @param  array<int, array{file: UploadedFile, type: string, title?: string}>  $documents
     */
    private function createApplication(
        int $barangayId,
        Resident $resident,
        Sector $sector,
        string $submissionType,
        ?int $submittingUserId,
        array $details,
        array $documents
    ): SectorApplication {
        $storedFilePaths = [];

        try {
            return DB::transaction(function () use (
                $barangayId,
                $resident,
                $sector,
                $submissionType,
                $submittingUserId,
                $details,
                $documents,
                &$storedFilePaths
            ): SectorApplication {
                // Check if resident is already an active member of this sector
                $isAlreadyMember = DB::table('resident_sector')
                    ->where('resident_id', $resident->id)
                    ->where('sector_id', $sector->id)
                    ->exists();

                if ($isAlreadyMember) {
                    throw ValidationException::withMessages([
                        'sector' => 'This resident is already an active member of the '.$sector->name.' sector.',
                    ]);
                }

                // Check if resident already has an active or in-progress application for this sector
                $hasActiveApplication = SectorApplication::query()
                    ->where('resident_id', $resident->id)
                    ->where('sector_id', $sector->id)
                    ->whereIn('status', SectorApplication::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveApplication) {
                    throw ValidationException::withMessages([
                        'sector' => 'An active application for the '.$sector->name.' sector is already in progress for this resident.',
                    ]);
                }

                $prefix = $sector->slug === 'senior' ? 'SNR' : 'PWD';
                $trackingNumber = 'APP-'.$prefix.'-'.date('Y').'-'.Str::upper(Str::random(5));

                $application = SectorApplication::create([
                    'public_id' => (string) Str::uuid(),
                    'tracking_number' => $trackingNumber,
                    'barangay_id' => $barangayId,
                    'resident_id' => $resident->id,
                    'sector_id' => $sector->id,
                    'submission_type' => $submissionType,
                    'submitted_by_user_id' => $submittingUserId,
                    'status' => SectorApplication::STATUS_PENDING_STAFF_REVIEW,
                    'details' => $details,
                ]);

                // Store uploaded supporting documents
                foreach ($documents as $docItem) {
                    /** @var UploadedFile $file */
                    $file = $docItem['file'];
                    $type = $docItem['type'] ?? 'sector_proof';
                    $title = $docItem['title'] ?? $file->getClientOriginalName();

                    $extension = $file->getClientOriginalExtension();
                    $storedName = (string) Str::uuid().($extension ? '.'.$extension : '');
                    $relativeDir = 'sector-applications/'.$barangayId.'/'.$application->id;
                    $storedPath = $file->storeAs($relativeDir, $storedName, 'local');

                    if (! $storedPath) {
                        throw new RuntimeException('Failed to store supporting document file.');
                    }

                    $storedFilePaths[] = $storedPath;
                    $fullPath = Storage::disk('local')->path($storedPath);
                    $sha256 = file_exists($fullPath) ? hash_file('sha256', $fullPath) : null;

                    SectorApplicationDocument::create([
                        'sector_application_id' => $application->id,
                        'document_type' => $type,
                        'title' => $title,
                        'disk' => 'local',
                        'path' => $storedPath,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size' => $file->getSize(),
                        'sha256' => $sha256,
                        'verification_status' => 'pending',
                    ]);
                }

                // Record audit log
                AuditLogger::record(
                    event: 'sector_application.submitted',
                    barangayId: $barangayId,
                    actorId: $submittingUserId,
                    entityType: 'sector_application',
                    entityId: $application->id,
                    context: [
                        'action' => 'submitted',
                        'department' => $sector->slug,
                        'entity_reference' => $application->tracking_number,
                        'new_status' => SectorApplication::STATUS_PENDING_STAFF_REVIEW,
                    ],
                    ipAddress: request()->ip()
                );

                return $application;

            });
        } catch (Throwable $e) {
            // Clean up any files saved before the failure
            foreach ($storedFilePaths as $filePath) {
                Storage::disk('local')->delete($filePath);
            }
            throw $e;
        }
    }

    /**
     * Sector staff approves the application and activates membership.
     *
     * @param  array<string, bool>  $checklist
     */
    public function staffApprove(
        SectorApplication $application,
        User $staff,
        array $checklist = [],
        ?string $reviewNotes = null
    ): SectorApplication {
        $this->authorizeStaffForApplication($staff, $application);

        return DB::transaction(function () use ($application, $staff, $checklist, $reviewNotes): SectorApplication {
            /** @var SectorApplication $lockedApp */
            $lockedApp = SectorApplication::query()
                ->where('id', $application->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeStaffForApplication($staff, $lockedApp);
            $this->ensureStaffDecisionIsAllowed($lockedApp);

            $isAlreadyMember = DB::table('resident_sector')
                ->where('resident_id', $lockedApp->resident_id)
                ->where('sector_id', $lockedApp->sector_id)
                ->exists();

            if ($isAlreadyMember) {
                throw ValidationException::withMessages([
                    'sector' => 'Resident already holds active membership in this sector.',
                ]);
            }

            $lockedApp->resident->sectors()->syncWithoutDetaching([$lockedApp->sector_id]);

            $previousStatus = $lockedApp->status;

            $lockedApp->update([
                'status' => SectorApplication::STATUS_APPROVED,
                'staff_reviewed_by' => $staff->id,
                'staff_reviewed_at' => now(),
                'staff_review_notes' => $reviewNotes,
                'staff_checklist' => $checklist,
                'staff_checklist_completed' => true,
                'admin_decided_by' => null,
                'admin_decided_at' => null,
                'admin_decision_notes' => null,
            ]);

            AuditLogger::record(
                event: 'sector_application.approved',
                barangayId: $lockedApp->barangay_id,
                actorId: $staff->id,
                entityType: 'sector_application',
                entityId: $lockedApp->id,
                context: [
                    'action' => 'staff_approved',
                    'department' => $lockedApp->sector->slug,
                    'entity_reference' => $lockedApp->tracking_number,
                    'previous_status' => $previousStatus,
                    'new_status' => SectorApplication::STATUS_APPROVED,
                ],
                ipAddress: request()->ip()
            );

            return $lockedApp;
        });
    }

    /**
     * Resident resubmits a corrected application.
     *
     * @param  Resident  $resident  The authenticated resident.
     * @param  int  $applicationId  The ID of the application to update.
     * @param  array  $details  Updated application details (optional).
     * @param  array  $documents  New document uploads (optional).
     */
    public function residentResubmitCorrection(
        Resident $resident,
        int $applicationId,
        array $details = [],
        array $documents = []
    ): SectorApplication {
        // Verify ownership and correct status
        $application = SectorApplication::query()
            ->where('id', $applicationId)
            ->where('resident_id', $resident->id)
            ->firstOrFail();

        if ($application->status !== SectorApplication::STATUS_REQUIRES_CORRECTION) {
            throw ValidationException::withMessages([
                'status' => 'Application is not awaiting correction.',
            ]);
        }

        // Transaction with row lock to avoid race conditions with staff actions
        return DB::transaction(function () use ($application, $resident, $details, $documents) {
            // Lock the row
            $lockedApp = SectorApplication::query()
                ->where('id', $application->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Update details if provided
            if (! empty($details)) {
                $lockedApp->details = $details;
            }

            // Reset correction fields
            $lockedApp->status = SectorApplication::STATUS_PENDING_STAFF_REVIEW;
            $lockedApp->correction_notes = null;
            $lockedApp->staff_reviewed_by = null;
            $lockedApp->staff_reviewed_at = null;

            $lockedApp->save();

            // Handle new document uploads (preserve existing docs)
            $storedFilePaths = [];
            foreach ($documents as $docItem) {
                /** @var UploadedFile $file */
                $file = $docItem['file'];
                $type = $docItem['type'] ?? 'sector_proof';
                $title = $docItem['title'] ?? $file->getClientOriginalName();

                $extension = $file->getClientOriginalExtension();
                $storedName = (string) Str::uuid().($extension ? '.'.$extension : '');
                $relativeDir = 'sector-applications/'.$lockedApp->barangay_id.'/'.$lockedApp->id;
                $storedPath = $file->storeAs($relativeDir, $storedName, 'local');
                if (! $storedPath) {
                    throw new RuntimeException('Failed to store supporting document file.');
                }
                $storedFilePaths[] = $storedPath;
                $fullPath = Storage::disk('local')->path($storedPath);
                $sha256 = file_exists($fullPath) ? hash_file('sha256', $fullPath) : null;

                SectorApplicationDocument::create([
                    'sector_application_id' => $lockedApp->id,
                    'document_type' => $type,
                    'title' => $title,
                    'disk' => 'local',
                    'path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
                    'sha256' => $sha256,
                    'verification_status' => 'pending',
                ]);
            }

            // Record audit log for resubmission
            AuditLogger::record(
                event: 'sector_application.resubmitted',
                barangayId: $lockedApp->barangay_id,
                actorId: $resident->id,
                entityType: 'sector_application',
                entityId: $lockedApp->id,
                context: [
                    'action' => 'resident_resubmitted',
                    'department' => $lockedApp->sector->slug,
                    'entity_reference' => $lockedApp->tracking_number,
                    'previous_status' => SectorApplication::STATUS_REQUIRES_CORRECTION,
                    'new_status' => SectorApplication::STATUS_PENDING_STAFF_REVIEW,
                ],
                ipAddress: request()->ip()
            );

            return $lockedApp;
        });
    }

    /**
     * Staff requests corrections with a recorded reason.
     */
    public function requestCorrection(
        SectorApplication $application,
        User $staff,
        string $correctionNotes
    ): SectorApplication {
        $this->authorizeStaffForApplication($staff, $application);

        if ($application->status !== SectorApplication::STATUS_PENDING_STAFF_REVIEW) {
            throw ValidationException::withMessages([
                'status' => 'Corrections can only be requested while the application is pending staff review.',
            ]);
        }

        $notes = trim($correctionNotes);
        if ($notes === '') {
            throw ValidationException::withMessages([
                'correction_notes' => 'Please provide specific instructions or reasons for the requested correction.',
            ]);
        }

        $previousStatus = $application->status;

        $application->update([
            'status' => SectorApplication::STATUS_REQUIRES_CORRECTION,
            'staff_reviewed_by' => $staff->id,
            'staff_reviewed_at' => now(),
            'correction_notes' => $notes,
        ]);

        AuditLogger::record(
            event: 'sector_application.correction_requested',
            barangayId: $application->barangay_id,
            actorId: $staff->id,
            entityType: 'sector_application',
            entityId: $application->id,
            context: [
                'action' => 'requested_correction',
                'department' => $application->sector->slug,
                'entity_reference' => $application->tracking_number,
                'previous_status' => $previousStatus,
                'new_status' => SectorApplication::STATUS_REQUIRES_CORRECTION,
            ],
            ipAddress: request()->ip()
        );

        return $application;
    }

    /**
     * Sector staff rejects the application without activating membership.
     */
    public function staffReject(
        SectorApplication $application,
        User $staff,
        string $rejectionReason
    ): SectorApplication {
        $this->authorizeStaffForApplication($staff, $application);

        $reason = trim($rejectionReason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($application, $staff, $reason): SectorApplication {
            /** @var SectorApplication $lockedApp */
            $lockedApp = SectorApplication::query()
                ->where('id', $application->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeStaffForApplication($staff, $lockedApp);
            $this->ensureStaffDecisionIsAllowed($lockedApp);

            $previousStatus = $lockedApp->status;

            $lockedApp->update([
                'status' => SectorApplication::STATUS_REJECTED,
                'staff_reviewed_by' => $staff->id,
                'staff_reviewed_at' => now(),
                'staff_review_notes' => $reason,
                'staff_checklist_completed' => false,
                'admin_decided_by' => null,
                'admin_decided_at' => null,
                'admin_decision_notes' => null,
            ]);

            AuditLogger::record(
                event: 'sector_application.rejected',
                barangayId: $lockedApp->barangay_id,
                actorId: $staff->id,
                entityType: 'sector_application',
                entityId: $lockedApp->id,
                context: [
                    'action' => 'staff_rejected',
                    'department' => $lockedApp->sector->slug,
                    'entity_reference' => $lockedApp->tracking_number,
                    'previous_status' => $previousStatus,
                    'new_status' => SectorApplication::STATUS_REJECTED,
                ],
                ipAddress: request()->ip()
            );

            return $lockedApp;
        });
    }

    /**
     * Validate staff authorization and barangay isolation for a given application.
     */
    private function authorizeStaffForApplication(User $staff, SectorApplication $application): void
    {
        if ($staff->barangay_id !== $application->barangay_id) {
            abort(403, 'Unauthorized access across barangays.');
        }

        $sectorSlug = $application->sector->slug;

        if ($sectorSlug === 'senior' && ! in_array($staff->role, ['senior', 'senior_staff'], true)) {
            abort(403, 'Unauthorized access: Senior staff privileges required.');
        }

        if ($sectorSlug === 'pwd' && ! in_array($staff->role, ['pwd', 'pwd_staff'], true)) {
            abort(403, 'Unauthorized access: PWD staff privileges required.');
        }
    }

    /**
     * Legacy forwarded records remain decidable; correction records must be
     * resubmitted by the resident before staff may approve or reject them.
     */
    private function ensureStaffDecisionIsAllowed(SectorApplication $application): void
    {
        if (! in_array($application->status, [
            SectorApplication::STATUS_PENDING_STAFF_REVIEW,
            SectorApplication::STATUS_UNDER_ADMIN_REVIEW,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'This application is not awaiting a sector staff decision (currently: '.$application->status.').',
            ]);
        }
    }
}
