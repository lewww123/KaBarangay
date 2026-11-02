<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureResidentRegistrationAccess;
use App\Models\RegistrationDocument;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationCorrectionCycle;
use App\Support\AuditLogger;
use App\Support\ResidentRegistrationCorrectionPolicy;
use App\Support\ResidentRegistrationEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ResidentRegistrationCorrectionController extends Controller
{
    public function resubmit(
        Request $request,
        string $reference,
        ResidentRegistrationEmailVerificationService $emailVerification,
    ): RedirectResponse {
        $context = $request->session()->get(EnsureResidentRegistrationAccess::SESSION_KEY);
        abort_unless(is_array($context) && isset($context['registration_id']), 404);

        $registration = ResidentRegistration::query()
            ->whereKey($context['registration_id'])
            ->where('public_id', $reference)
            ->firstOrFail();
        abort_unless($registration->status === 'needs_correction', 422);

        $cycle = $this->latestCycle((int) $registration->id);
        abort_unless($cycle !== null && $cycle->resubmitted_at === null, 422);

        $fieldTargets = is_array($cycle->field_targets) ? $cycle->field_targets : [];
        $documentTargets = is_array($cycle->document_targets) ? $cycle->document_targets : [];
        abort_unless(
            $fieldTargets !== [] || $documentTargets !== [],
            422
        );
        abort_unless(
            array_diff($fieldTargets, ResidentRegistrationCorrectionPolicy::FIELD_TARGETS) === []
            && array_diff($documentTargets, array_keys(ResidentRegistrationCorrectionPolicy::DOCUMENT_TARGETS)) === [],
            422
        );

        $body = $request->except(['_token', '_method']);
        if (array_diff(array_keys($body), ['fields', 'documents']) !== []) {
            throw ValidationException::withMessages([
                'correction' => 'Only the fields and documents authorized for this correction may be submitted.',
            ]);
        }

        $fields = $request->input('fields', []);
        $documents = $request->file('documents', []);
        if (! is_array($fields) || ! is_array($documents)) {
            throw ValidationException::withMessages([
                'correction' => 'The correction submission is invalid.',
            ]);
        }

        if (
            array_diff(array_keys($fields), $fieldTargets) !== []
            || array_diff($fieldTargets, array_keys($fields)) !== []
        ) {
            throw ValidationException::withMessages([
                'fields' => 'Submit exactly the fields authorized for this correction.',
            ]);
        }

        if (
            array_diff(array_keys($documents), $documentTargets) !== []
            || array_diff($documentTargets, array_keys($documents)) !== []
        ) {
            throw ValidationException::withMessages([
                'documents' => 'Upload a replacement for each document authorized for this correction.',
            ]);
        }

        $fields = $this->normalizeFields($fields);
        $rules = [];
        foreach ($fieldTargets as $field) {
            $rules['fields.'.$field] = ResidentRegistrationCorrectionPolicy::validationRules(
                $field,
                (int) $registration->barangay_id,
            );
        }
        foreach ($documentTargets as $target) {
            $rules['documents.'.$target] = $target === 'live_identity_photo'
                ? ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120']
                : ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
        }

        Validator::make([
            'fields' => $fields,
            'documents' => $documents,
        ], $rules)->validate();

        $this->validateRegistrationUniqueness($registration, $fields, $fieldTargets);

        $storedPaths = [];
        $emailChanged = false;
        $cycleNumber = $cycle->cycle_number;

        try {
            $emailChanged = DB::transaction(function () use (
                $registration,
                $reference,
                $cycle,
                $fieldTargets,
                $documentTargets,
                $fields,
                $documents,
                &$storedPaths,
            ): bool {
                $locked = ResidentRegistration::query()
                    ->whereKey($registration->id)
                    ->where('public_id', $reference)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless($locked->status === 'needs_correction', 422);

                $latestCycle = ResidentRegistrationCorrectionCycle::query()
                    ->where('resident_registration_id', $locked->id)
                    ->orderByDesc('cycle_number')
                    ->lockForUpdate()
                    ->first();
                abort_unless(
                    $latestCycle !== null
                    && (int) $latestCycle->id === (int) $cycle->id
                    && $latestCycle->resubmitted_at === null,
                    422
                );

                if (
                    $latestCycle->field_targets !== $fieldTargets
                    || $latestCycle->document_targets !== $documentTargets
                ) {
                    throw ValidationException::withMessages([
                        'correction' => 'The correction request changed. Refresh the application and review the latest instructions.',
                    ]);
                }

                $this->validateRegistrationUniqueness($locked, $fields, $fieldTargets);

                $locked->load(['documents.sector:id,slug']);
                foreach ($documentTargets as $target) {
                    if (ResidentRegistrationCorrectionPolicy::currentDocumentForTarget($locked->documents, $target) === null) {
                        throw ValidationException::withMessages([
                            'documents.'.$target => 'The document version is no longer available for replacement.',
                        ]);
                    }
                }

                if (in_array('purok_id', $fieldTargets, true)) {
                    $purokIsValid = DB::table('puroks')
                        ->where('id', $fields['purok_id'])
                        ->where('barangay_id', $locked->barangay_id)
                        ->where('is_active', true)
                        ->exists();
                    if (! $purokIsValid) {
                        throw ValidationException::withMessages([
                            'fields.purok_id' => 'Select an active purok in the application’s barangay.',
                        ]);
                    }
                }

                $now = now();
                foreach ($documentTargets as $target) {
                    $previous = ResidentRegistrationCorrectionPolicy::currentDocumentForTarget(
                        $locked->documents,
                        $target
                    );
                    if ($previous === null) {
                        throw ValidationException::withMessages([
                            'documents.'.$target => 'The document version is no longer available for replacement.',
                        ]);
                    }

                    $file = $documents[$target];
                    $path = $file->store('resident-verifications/'.$locked->public_id, 'local');
                    if (! is_string($path) || $path === '') {
                        throw new \RuntimeException('Unable to store a registration correction document.');
                    }
                    $storedPaths[] = $path;

                    $newDocument = RegistrationDocument::create([
                        'resident_registration_id' => $locked->id,
                        'sector_id' => $previous->sector_id,
                        'document_type' => $previous->document_type,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => basename($file->getClientOriginalName()),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size' => (int) $file->getSize(),
                        'sha256' => hash_file('sha256', $file->getRealPath()),
                        'verification_status' => 'pending',
                        'supersedes_document_id' => $previous->id,
                    ]);

                    $previous->forceFill(['superseded_at' => $now])->save();
                    $locked->setRelation('documents', $locked->documents->push($newDocument));
                }

                $previousEmail = $locked->email;
                $updates = [];
                foreach ($fieldTargets as $field) {
                    $updates[$field] = $field === 'purok_id'
                        ? (int) $fields[$field]
                        : $fields[$field];
                }
                $emailChanged = in_array('email', $fieldTargets, true)
                    && $previousEmail !== $updates['email'];

                $locked->forceFill($updates + [
                    'status' => 'pending',
                    'last_resubmitted_at' => $now,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_notes' => null,
                ])->save();

                $latestCycle->forceFill(['resubmitted_at' => $now])->save();

                return $emailChanged;
            });
        } catch (ValidationException|HttpExceptionInterface $exception) {
            $this->deleteNewFiles($storedPaths);
            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteNewFiles($storedPaths);
            Log::warning('Unable to persist Resident registration correction.', [
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'correction' => 'We could not save your correction. Your application remains unchanged; please try again.',
            ]);
        }

        AuditLogger::record(
            'resident_registration.resubmitted',
            $registration->barangay_id,
            null,
            'resident_registration',
            $registration->id,
            ['cycle_number' => $cycleNumber],
            $request->ip(),
        );

        if ($emailChanged && config('registration.email_verification_required', false)) {
            $updatedRegistration = $registration->fresh();
            if ($updatedRegistration?->email !== null && trim($updatedRegistration->email) !== '') {
                try {
                    $emailVerification->send($updatedRegistration);
                } catch (Throwable $exception) {
                    Log::warning('Unable to send Resident registration email verification after correction.', [
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        return back()->with('success', 'Your correction was submitted for Admin review.');
    }

    /** @param list<string> $paths */
    private function deleteNewFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Unable to clean up a failed Resident registration correction upload.', [
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $fields
     *  @param list<string> $targets
     *  @return array<string, mixed>
     */
    private function normalizeFields(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (in_array($key, ['first_name', 'last_name', 'address_line'], true)) {
                $fields[$key] = trim($value);
            } elseif (in_array($key, ['middle_name', 'suffix'], true)) {
                $fields[$key] = trim($value) === '' ? null : trim($value);
            } elseif ($key === 'email') {
                $fields[$key] = trim($value) === '' ? null : mb_strtolower(trim($value));
            } elseif ($key === 'mobile') {
                $mobile = preg_replace('/[^0-9]/', '', $value) ?? '';
                if (strlen($mobile) === 12 && str_starts_with($mobile, '63')) {
                    $mobile = '0'.substr($mobile, 2);
                }
                $fields[$key] = $mobile;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $fields
     *  @param list<string> $targets
     */
    private function validateRegistrationUniqueness(
        ResidentRegistration $registration,
        array $fields,
        array $targets
    ): void {
        foreach (['mobile', 'email'] as $field) {
            if (! in_array($field, $targets, true) || ! is_string($fields[$field] ?? null) || $fields[$field] === '') {
                continue;
            }

            $duplicate = ResidentRegistration::query()
                ->where($field, $fields[$field])
                ->where('id', '<>', $registration->id)
                ->whereIn('status', ['pending', 'needs_correction', 'approved'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'fields.'.$field => $field === 'mobile'
                        ? 'A registration using this mobile number already exists.'
                        : 'A registration using this email address already exists.',
                ]);
            }
        }
    }

    private function latestCycle(int $registrationId): ?ResidentRegistrationCorrectionCycle
    {
        return ResidentRegistrationCorrectionCycle::query()
            ->where('resident_registration_id', $registrationId)
            ->orderByDesc('cycle_number')
            ->first();
    }
}
