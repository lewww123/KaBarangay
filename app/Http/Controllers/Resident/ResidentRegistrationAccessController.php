<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureResidentRegistrationAccess;
use App\Models\ResidentRegistration;
use App\Models\ResidentRegistrationCorrectionCycle;
use App\Support\ResidentRegistrationCorrectionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResidentRegistrationAccessController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$S4su.OH..2iC5npAkAcXSOgsdPhGeKICW25xFk9YBiIBwLMpXveem';

    private const CREDENTIALS_ERROR = 'The application reference or password is incorrect.';

    public function create(Request $request): Response
    {
        $reference = $request->query('reference');

        return $this->renderNoStore($request, 'Resident/RegistrationAccess', [
            'reference' => is_string($reference) && strlen($reference) <= 64
                ? $reference
                : '',
            'feedback' => $request->session()->get('access_feedback'),
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $reference = $request->input('reference');
        $rawPassword = $request->input('password');
        $validReference = is_string($reference)
            && strlen($reference) <= 64
            && Str::isUuid($reference);
        $registration = $validReference
            ? ResidentRegistration::query()
                ->where('public_id', $reference)
                ->first(['id', 'public_id', 'password'])
            : null;

        $password = is_string($rawPassword) && strlen($rawPassword) <= 1024
            ? $rawPassword
            : '';

        try {
            $passwordMatches = Hash::check(
                $password,
                $registration?->password ?? self::DUMMY_PASSWORD_HASH,
            );
        } catch (Throwable) {
            $passwordMatches = false;
        }

        if ($registration === null || ! $passwordMatches) {
            $safeReference = is_string($reference) && strlen($reference) <= 64
                ? $reference
                : '';

            return redirect()
                ->route('resident.registration.access', $safeReference !== ''
                    ? ['reference' => $safeReference]
                    : [])
                ->withErrors(['credentials' => self::CREDENTIALS_ERROR]);
        }

        $request->session()->regenerate();
        $request->session()->forget(EnsureResidentRegistrationAccess::SESSION_KEY);

        $now = now();
        $absoluteExpiry = $now->copy()->addSeconds(
            EnsureResidentRegistrationAccess::ABSOLUTE_TIMEOUT_SECONDS
        )->getTimestamp();

        $request->session()->put(EnsureResidentRegistrationAccess::SESSION_KEY, [
            'registration_id' => (int) $registration->id,
            'reference' => (string) $registration->public_id,
            'authenticated_at' => $now->getTimestamp(),
            'last_activity_at' => $now->getTimestamp(),
            'idle_expires_at' => min(
                $now->copy()->addSeconds(EnsureResidentRegistrationAccess::IDLE_TIMEOUT_SECONDS)->getTimestamp(),
                $absoluteExpiry,
            ),
            'absolute_expires_at' => $absoluteExpiry,
        ]);

        return redirect()->route('resident.registration.application.show', [
            'reference' => $registration->public_id,
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $access = $request->session()->get(EnsureResidentRegistrationAccess::SESSION_KEY);
        abort_unless(is_array($access), 404);

        $emailVerificationAvailable = Schema::hasTable('resident_registration_email_verification_tokens') &&
            Schema::hasColumn('resident_registrations', 'email_verified_at');
        $columns = [
            'id',
            'public_id',
            'first_name',
            'middle_name',
            'last_name',
            'suffix',
            'status',
            'submitted_at',
            'barangay_id',
            'purok_id',
        ];
        if ($emailVerificationAvailable) {
            $columns[] = 'email_verified_at';
        }

        $registration = ResidentRegistration::query()
            ->where('public_id', $reference)
            ->firstOrFail($columns);

        abort_unless((int) $access['registration_id'] === (int) $registration->id, 404);

        $registration->load([
            'barangay:id,name',
            'purok:id,name',
        ]);

        $correction = null;
        if ($registration->status === 'needs_correction') {
            $cycle = ResidentRegistrationCorrectionCycle::query()
                ->where('resident_registration_id', $registration->id)
                ->orderByDesc('cycle_number')
                ->first();

            if ($cycle !== null && $cycle->resubmitted_at === null) {
                $fieldTargets = array_intersect(
                    is_array($cycle->field_targets) ? $cycle->field_targets : [],
                    ResidentRegistrationCorrectionPolicy::FIELD_TARGETS,
                );
                $documentTargets = array_intersect(
                    is_array($cycle->document_targets) ? $cycle->document_targets : [],
                    array_keys(ResidentRegistrationCorrectionPolicy::DOCUMENT_TARGETS),
                );

                $correction = [
                    'cycle_number' => $cycle->cycle_number,
                    'instructions' => $cycle->instructions,
                    'field_targets' => array_values(array_map(
                        fn (string $field) => ResidentRegistrationCorrectionPolicy::fieldDescriptor($field, $registration),
                        $fieldTargets,
                    )),
                    'document_targets' => array_values(array_map(
                        fn (string $target) => ResidentRegistrationCorrectionPolicy::documentDescriptor($target),
                        $documentTargets,
                    )),
                ];
            }
        }

        return $this->renderNoStore($request, 'Resident/RegistrationApplication', [
            'application' => [
                'reference' => $registration->public_id,
                'applicant_name' => trim(implode(' ', array_filter([
                    $registration->first_name,
                    $registration->middle_name,
                    $registration->last_name,
                    $registration->suffix,
                ]))),
                'status' => $registration->status,
                'submitted_at' => $registration->submitted_at?->toIso8601String(),
                'barangay' => $registration->barangay?->name,
                'purok' => $registration->purok?->name,
                'email_verification_available' => $emailVerificationAvailable,
                'email_verified' => $emailVerificationAvailable && $registration->email_verified_at !== null,
                'email_verification_feedback' => $request->session()->get('email_verification_feedback'),
                'correction' => $correction,
            ],
        ]);
    }

    public function logout(Request $request, string $reference): RedirectResponse
    {
        $access = $request->session()->get(EnsureResidentRegistrationAccess::SESSION_KEY);
        abort_unless(
            is_array($access) && hash_equals((string) $access['reference'], $reference),
            404
        );

        $request->session()->forget(EnsureResidentRegistrationAccess::SESSION_KEY);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('resident.registration.access', [
            'reference' => $reference,
        ]);
    }

    /** @param array<string, mixed> $props */
    private function renderNoStore(Request $request, string $component, array $props): Response
    {
        $page = match ($component) {
            'Resident/RegistrationAccess' => Inertia::render('Resident/RegistrationAccess', $props),
            'Resident/RegistrationApplication' => Inertia::render('Resident/RegistrationApplication', $props),
        };
        $response = $page->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
