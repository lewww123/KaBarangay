<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\RegistrationDocument;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidentRegistrationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $pendingRegistrations = ResidentRegistration::query()
            ->with([
                'barangay:id,name,code',
                'purok:id,name',
                'sectors.sector:id,name,slug',
                'documents:id,resident_registration_id,sector_id,document_type,mime_type,file_size,verification_status',
            ])
            ->where('barangay_id', $user->barangay_id)
            ->whereIn('status', [
                'pending',
                'needs_correction',
            ])
            ->latest('submitted_at')
            ->get()
            ->map(function (ResidentRegistration $registration) {
                return [
                    'id' => $registration->id,
                    'reference' => $registration->public_id,

                    'first_name' => $registration->first_name,
                    'middle_name' => $registration->middle_name,
                    'last_name' => $registration->last_name,
                    'suffix' => $registration->suffix,

                    'birthdate' => $registration->birthdate
                        ?->format('Y-m-d'),

                    'sex' => $registration->sex,
                    'civil_status' => $registration->civil_status,
                    'address_line' => $registration->address_line,

                    'mobile' => $registration->mobile,
                    'email' => $registration->email,

                    'barangay' => $registration->barangay?->name,
                    'purok' => $registration->purok?->name,

                    'sectors' => $registration->sectors
                        ->map(function ($registrationSector) {
                            return [
                                'id' => $registrationSector->sector?->id,
                                'name' => $registrationSector->sector?->name,
                                'slug' => $registrationSector->sector?->slug,
                            ];
                        })
                        ->filter(fn ($sector) => $sector['id'])
                        ->values(),

                    'documents' => $registration->documents
                        ->map(function (RegistrationDocument $document) use ($registration) {
                            return [
                                'id' => $document->id,
                                'type' => $document->document_type,
                                'label' => $this->documentLabel(
                                    $document->document_type
                                ),
                                'mime_type' => $document->mime_type,
                                'file_size' => $document->file_size,
                                'verification_status' =>
                                    $document->verification_status,
                                'url' => route(
                                    'admin.residents.documents.show',
                                    [
                                        'registration' => $registration->id,
                                        'document' => $document->id,
                                    ]
                                ),
                                'is_image' => str_starts_with(
                                    (string) $document->mime_type,
                                    'image/'
                                ),
                            ];
                        })
                        ->values(),

                    'status' => $registration->status,

                    'submitted_at' => $registration->submitted_at
                        ?->format('Y-m-d'),
                ];
            });

        $pendingCount = ResidentRegistration::query()
            ->where('barangay_id', $user->barangay_id)
            ->whereIn('status', [
                'pending',
                'needs_correction',
            ])
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Permanent Resident Statistics
        |--------------------------------------------------------------------------
        */

        $totalResidents = Resident::query()
            ->where('barangay_id', $user->barangay_id)
            ->count();

        $activeResidents = Resident::query()
            ->where('barangay_id', $user->barangay_id)
            ->where('status', 'active')
            ->count();

        return Inertia::render('Admin/Residents', [
            'pendingRegistrations' => $pendingRegistrations,

            'stats' => [
                'totalResidents' => $totalResidents,
                'activeResidents' => $activeResidents,
                'pendingRegistrations' => $pendingCount,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Show Registration Document
    |--------------------------------------------------------------------------
    */

    public function showDocument(
        Request $request,
        ResidentRegistration $registration,
        RegistrationDocument $document
    ): StreamedResponse {
        $user = $request->user();

        // Admin can only view applications from their own barangay.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            403
        );

        // Prevent using a document ID belonging to another registration.
        abort_unless(
            (int) $document->resident_registration_id ===
                (int) $registration->id,
            404
        );

        $disk = $document->disk ?: 'local';

        abort_unless(
            Storage::disk($disk)->exists($document->path),
            404
        );

        return Storage::disk($disk)->response(
            $document->path,
            $document->original_name ?: basename($document->path),
            [
                'Content-Type' => $document->mime_type
                    ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            'inline'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Approve Resident Registration
    |--------------------------------------------------------------------------
    */

    public function approve(
        Request $request,
        ResidentRegistration $registration
    ): RedirectResponse {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Barangay Security
        |--------------------------------------------------------------------------
        |
        | An admin can only approve applications belonging to their barangay.
        |
        */

        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            403
        );

        /*
        |--------------------------------------------------------------------------
        | Application Status
        |--------------------------------------------------------------------------
        */

        abort_unless(
            in_array(
                $registration->status,
                [
                    'pending',
                    'needs_correction',
                ],
                true
            ),
            422
        );

        /*
        |--------------------------------------------------------------------------
        | Prevent Double Approval
        |--------------------------------------------------------------------------
        */

        if ($registration->resident()->exists()) {
            throw ValidationException::withMessages([
                'approval' =>
                    'This registration already has a permanent resident record.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Existing Resident Contact Information
        |--------------------------------------------------------------------------
        */

        $mobileExists = Resident::query()
            ->where('barangay_id', $registration->barangay_id)
            ->where('mobile', $registration->mobile)
            ->exists();

        if ($mobileExists) {
            throw ValidationException::withMessages([
                'approval' =>
                    'A permanent resident with this mobile number already exists in this barangay.',
            ]);
        }

        if ($registration->email) {
            $emailExists = Resident::query()
                ->where('barangay_id', $registration->barangay_id)
                ->whereRaw(
                    'LOWER(email) = ?',
                    [Str::lower($registration->email)]
                )
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'approval' =>
                        'A permanent resident with this email address already exists in this barangay.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create Permanent Resident
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use ($registration, $user) {

            /*
            |--------------------------------------------------------------------------
            | Lock Registration
            |--------------------------------------------------------------------------
            |
            | Prevent two approval requests from processing the same application
            | at the same time.
            |
            */

            $lockedRegistration = ResidentRegistration::query()
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRegistration->resident()->exists()) {
                throw ValidationException::withMessages([
                    'approval' =>
                        'This registration has already been approved.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Lock Barangay
            |--------------------------------------------------------------------------
            |
            | This also helps prevent two approvals from generating the same
            | resident number for the same barangay.
            |
            */

            $barangay = Barangay::query()
                ->whereKey($lockedRegistration->barangay_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Generate Resident Number
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | BSJ-RES-000001
            | BSJ-RES-000002
            |
            */

            $residentNumber = $this->generateResidentNumber(
                $barangay->id,
                $barangay->code
            );

            /*
            |--------------------------------------------------------------------------
            | Create Permanent Resident
            |--------------------------------------------------------------------------
            */

            $resident = Resident::create([
                'public_id' => (string) Str::uuid(),

                'resident_registration_id' =>
                    $lockedRegistration->id,

                'barangay_id' =>
                    $lockedRegistration->barangay_id,

                'purok_id' =>
                    $lockedRegistration->purok_id,

                'resident_number' =>
                    $residentNumber,

                'first_name' =>
                    $lockedRegistration->first_name,

                'middle_name' =>
                    $lockedRegistration->middle_name,

                'last_name' =>
                    $lockedRegistration->last_name,

                'suffix' =>
                    $lockedRegistration->suffix,

                'birthdate' =>
                    $lockedRegistration->birthdate,

                'sex' =>
                    $lockedRegistration->sex,

                'civil_status' =>
                    $lockedRegistration->civil_status,

                'address_line' =>
                    $lockedRegistration->address_line,

                'mobile' =>
                    $lockedRegistration->mobile,

                'email' =>
                    $lockedRegistration->email,

                'status' => 'active',

                'approved_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Create Resident Login Account
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | The registration password is already hashed.
            | Do NOT Hash::make() it again.
            |
            */

            ResidentAccount::create([
                'resident_id' => $resident->id,
                'password' => $lockedRegistration->password,
                'is_active' => true,
                'password_changed_at' => null,
                'last_login_at' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Copy Approved Sectors
            |--------------------------------------------------------------------------
            |
            | Regular Resident:
            | No sector rows.
            |
            | Senior/PWD:
            | Copy sector IDs from registration_sectors.
            |
            */

            $sectorIds = $lockedRegistration
                ->sectors()
                ->pluck('sector_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($sectorIds)) {
                $resident->sectors()->sync($sectorIds);
            }

            /*
            |--------------------------------------------------------------------------
            | Create QR Credential
            |--------------------------------------------------------------------------
            |
            | QR stores only this random token, not resident personal data.
            |
            */

            ResidentQrCredential::create([
                'resident_id' => $resident->id,
                'token' => 'KBQR-' . Str::random(64),
                'is_active' => true,
                'generated_at' => now(),
                'revoked_at' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Mark Application Approved
            |--------------------------------------------------------------------------
            */

            $lockedRegistration->update([
                'status' => 'approved',
                'review_notes' => null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        });

        return back()->with(
            'success',
            'Resident registration approved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reject Resident Registration
    |--------------------------------------------------------------------------
    */

    public function reject(
        Request $request,
        ResidentRegistration $registration
    ): RedirectResponse {
        $user = $request->user();

        // Admin can only review applicants from their barangay.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            403
        );

        // Only applications still under review can be rejected.
        abort_unless(
            in_array(
                $registration->status,
                [
                    'pending',
                    'needs_correction',
                ],
                true
            ),
            422
        );

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
        ]);

        $registration->update([
            'status' => 'rejected',
            'review_notes' => trim($validated['reason']),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return back()->with(
            'success',
            'Resident application rejected.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Resident Number
    |--------------------------------------------------------------------------
    */

    private function generateResidentNumber(
        int $barangayId,
        string $barangayCode
    ): string {
        $lastResidentNumber = Resident::query()
            ->where('barangay_id', $barangayId)
            ->orderByDesc('id')
            ->value('resident_number');

        $nextNumber = 1;

        if (
            $lastResidentNumber &&
            preg_match(
                '/(\d+)$/',
                $lastResidentNumber,
                $matches
            )
        ) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        $code = strtoupper(
            preg_replace(
                '/[^A-Za-z0-9]/',
                '',
                $barangayCode
            )
        );

        return sprintf(
            '%s-RES-%06d',
            $code,
            $nextNumber
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Document Label
    |--------------------------------------------------------------------------
    */

    private function documentLabel(string $type): string
    {
        return match ($type) {
            'valid_id' => 'Valid ID',
            'live_identity_photo' => 'Live Identity Photo',
            'senior_proof' => 'Senior Citizen Proof',
            'pwd_proof' => 'PWD Proof',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}