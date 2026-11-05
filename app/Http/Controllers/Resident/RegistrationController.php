<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resident\StoreResidentRegistrationRequest;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\ResidentRegistration;
use App\Models\Sector;
use App\Support\AuditLogger;
use App\Support\ResidentRegistrationDuplicateChecker;
use App\Support\ResidentRegistrationQrMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegistrationController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Registration Page
    |--------------------------------------------------------------------------
    */

    public function create(Request $request): Response
    {
        $loadBarangays = function () {
            $barangays = Barangay::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                    'city_municipality',
                    'province',
                ]);

            $puroks = Purok::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get([
                    'id',
                    'barangay_id',
                    'name',
                    'code',
                ])
                ->groupBy('barangay_id');

            $barangays = $barangays
                ->map(function ($barangay) use ($puroks) {
                    return [
                        'id' => $barangay->id,
                        'name' => $barangay->name,
                        'code' => $barangay->code,
                        'city_municipality' => $barangay->city_municipality,
                        'province' => $barangay->province,

                        'puroks' => collect(
                            $puroks->get($barangay->id, [])
                        )->values()->map(function ($purok) {
                            return [
                                'id' => $purok->id,
                                'name' => $purok->name,
                                'code' => $purok->code,
                            ];
                        })->all(),
                    ];
                })
                ->values();

            return $barangays;
        };

        $sectors = fn () => Sector::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
                'requires_proof',
            ]);

        return Inertia::render('Resident/Register', [
            // Include these cached options on validation redirects as well.
            // Deferring them remounts the browser wizard and discards its files.
            'barangays' => fn () => $this->rememberReference('registration:barangay-purok-options:v2', 300, fn () => $loadBarangays()->toArray()),
            'sectors' => fn () => $this->rememberReference('registration:sector-options', 300, fn () => $sectors()->toArray()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Store Registration
    |--------------------------------------------------------------------------
    */

    public function store(
        StoreResidentRegistrationRequest $request,
        ResidentRegistrationQrMatcher $qrMatcher,
    ): RedirectResponse {
        $validated = $request->validated();
        $qrCredentialId = null;

        try {
            $qrCredentialId = $qrMatcher->resolveCredential(
                $request->registrationQrToken(),
                (int) $validated['barangay_id'],
            )?->getKey();
        } catch (Throwable $exception) {
            // QR is advisory: a lookup issue must not reject the application.
            // Do not include the submitted token or exception message in logs.
            Log::warning('Unable to resolve optional Resident registration QR reference.', [
                'exception' => $exception::class,
            ]);
        }

        $storedFiles = [];

        try {
            $createRegistration = function () use ($request, $validated, $qrCredentialId, &$storedFiles) {
                return DB::transaction(function () use (
                    $request,
                    $validated,
                    $qrCredentialId,
                    &$storedFiles
                ) {
                    /*
                    |--------------------------------------------------------------------------
                    | Create Pending Registration
                    |--------------------------------------------------------------------------
                    */

                    $registration = ResidentRegistration::create([
                        'public_id' => (string) Str::uuid(),

                        'barangay_id' => $validated['barangay_id'],
                        'purok_id' => $validated['purok_id'],

                        'first_name' => $validated['first_name'],
                        'middle_name' => $validated['middle_name'] ?? null,
                        'last_name' => $validated['last_name'],
                        'suffix' => $validated['suffix'] ?? null,

                        'birthdate' => $validated['birthdate'],
                        'sex' => $validated['sex'],
                        'civil_status' => $validated['civil_status'],

                        'address_line' => $validated['address_line'],

                        'mobile' => $validated['mobile'],
                        'email' => $validated['email'] ?? null,

                        /*
                        | NEVER save plain-text passwords.
                        */
                        'password' => Hash::make(
                            $validated['password']
                        ),

                        'status' => 'pending',
                        'submitted_at' => now(),
                    ]);

                    // The submitted QR value is only resolved to a credential
                    // reference. The raw token is never persisted.
                    if ($qrCredentialId !== null) {
                        $registration->forceFill([
                            'qr_credential_id' => $qrCredentialId,
                        ])->save();
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Selected Sectors
                    |--------------------------------------------------------------------------
                    */

                    $selectedSectorSlugs = $validated['sectors'] ?? [];

                    $selectedSectors = Sector::query()
                        ->where('is_active', true)
                        ->whereIn(
                            'slug',
                            $selectedSectorSlugs
                        )
                        ->get();

                    foreach ($selectedSectors as $sector) {
                        $registration->sectors()->create([
                            'sector_id' => $sector->id,
                            'verification_status' => 'pending',
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Valid ID
                    |--------------------------------------------------------------------------
                    */

                    $this->storeDocument(
                        registration: $registration,
                        file: $request->file('valid_id'),
                        documentType: 'valid_id',
                        sectorId: null,
                        storedFiles: $storedFiles,
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Live Identity Photo
                    |--------------------------------------------------------------------------
                    */

                    $this->storeDocument(
                        registration: $registration,
                        file: $request->file(
                            'live_identity_photo'
                        ),
                        documentType: 'live_identity_photo',
                        sectorId: null,
                        storedFiles: $storedFiles,
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Senior Citizen Proof
                    |--------------------------------------------------------------------------
                    */

                    $seniorSector = $selectedSectors
                        ->firstWhere('slug', 'senior');

                    if (
                        $seniorSector &&
                        $request->hasFile('senior_proof')
                    ) {
                        $this->storeDocument(
                            registration: $registration,
                            file: $request->file('senior_proof'),
                            documentType: 'sector_proof',
                            sectorId: $seniorSector->id,
                            storedFiles: $storedFiles,
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | PWD Proof
                    |--------------------------------------------------------------------------
                    */

                    $pwdSector = $selectedSectors
                        ->firstWhere('slug', 'pwd');

                    if (
                        $pwdSector &&
                        $request->hasFile('pwd_proof')
                    ) {
                        $this->storeDocument(
                            registration: $registration,
                            file: $request->file('pwd_proof'),
                            documentType: 'sector_proof',
                            sectorId: $pwdSector->id,
                            storedFiles: $storedFiles,
                        );
                    }

                    return $registration;
                });
            };
            // Serialize submissions so simultaneous requests cannot both pass
            // the duplicate check before either application has been saved.
            $registration = Cache::lock('resident-registration:submit', 120)->block(10, function () use ($request, $validated, $createRegistration) {
                $duplicate = app(ResidentRegistrationDuplicateChecker::class)->find($validated);
                if ($duplicate !== null) {
                    throw ValidationException::withMessages(['registration' => $duplicate]);
                }

                return $createRegistration();
            });
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Clean Up Files If Database Transaction Fails
            |--------------------------------------------------------------------------
            */

            foreach ($storedFiles as $path) {
                Storage::disk('local')->delete($path);
            }

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);

            return back()
                ->withInput(
                    $request->except([
                        'password',
                        'password_confirmation',
                        'valid_id',
                        'live_identity_photo',
                        'senior_proof',
                        'pwd_proof',
                        'qr_token',
                    ])
                )
                ->withErrors([
                    'registration' => 'We could not complete your registration. Please try again.',
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Audit: registration submitted
        |--------------------------------------------------------------------------
        |
        | Public registration has no staff/User identity, so actor_id stays null.
        | The barangay is taken from the persisted registration record, never
        | from client input, and no personal data is recorded.
        |
        */

        AuditLogger::record(
            'resident_registration.submitted',
            $registration->barangay_id,
            null,
            'resident_registration',
            $registration->id,
            [],
            $request->ip(),
        );

        // Admin review is still required; an unverified address is not marked verified.

        return redirect()->route('resident.registration.submitted');
    }

    /*
    |--------------------------------------------------------------------------
    | Registration Submitted Page
    |--------------------------------------------------------------------------
    */

    public function submitted(): Response
    {
        return Inertia::render('Resident/RegistrationPending');
    }

    /*
    |--------------------------------------------------------------------------
    | Private Document Storage
    |--------------------------------------------------------------------------
    */

    private function storeDocument(
        ResidentRegistration $registration,
        UploadedFile $file,
        string $documentType,
        ?int $sectorId,
        array &$storedFiles,
    ): RegistrationDocument {
        /*
        | Hash temporary upload before moving it.
        */
        $sha256 = hash_file(
            'sha256',
            $file->getRealPath()
        );

        /*
        |--------------------------------------------------------------------------
        | Storage::disk('local')
        |--------------------------------------------------------------------------
        |
        | On modern Laravel applications, the local disk is private storage.
        | These files are NOT intended to be directly accessible by URL.
        |
        */

        $path = $file->store(
            'resident-verifications/'.
                $registration->public_id,
            'local'
        );

        $storedFiles[] = $path;

        return RegistrationDocument::create([
            'resident_registration_id' => $registration->id,

            'sector_id' => $sectorId,

            'document_type' => $documentType,

            'disk' => 'local',
            'path' => $path,

            /*
            | basename prevents directory information
            | from being stored from a supplied filename.
            */
            'original_name' => basename(
                $file->getClientOriginalName()
            ),

            'mime_type' => $file->getMimeType()
                ?: 'application/octet-stream',

            'file_size' => $file->getSize(),

            'sha256' => $sha256,

            'verification_status' => 'pending',
        ]);
    }
}
