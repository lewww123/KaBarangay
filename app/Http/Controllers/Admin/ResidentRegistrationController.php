<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Purok;
use App\Models\RegistrationDocument;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentQrCredential;
use App\Models\ResidentRegistration;
use App\Support\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidentRegistrationController extends Controller
{
    /**
     * Resident and login-account statuses an administrator may set.
     */
    private const RESIDENT_STATUSES = [
        'active',
        'inactive',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Purok Filter (server-side)
        |--------------------------------------------------------------------------
        |
        | The selected purok is validated against the authenticated admin's
        | barangay. An unknown, non-numeric, or foreign purok id resolves to
        | All Puroks (null) so it can never expose another barangay's records.
        |
        */

        $selectedPurokId = $this->resolvePurokFilter(
            $request,
            (int) $user->barangay_id
        );

        $puroks = Purok::query()
            ->where('barangay_id', $user->barangay_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Purok $purok) {
                return [
                    'id' => $purok->id,
                    'name' => $purok->name,
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Registration Search (server-side, shared by the review tabs)
        |--------------------------------------------------------------------------
        |
        | The Pending, Needs Correction, and Rejected tabs share one search box.
        | The keyword is applied on the server so those lists never load every
        | registration of the barangay into the browser. Each tab is paginated
        | with its own page parameter so their pagination states never conflict.
        |
        */

        $registrationSearch = trim(
            (string) $request->query('registration_search', '')
        );

        $pendingRegistrations = $this->registrationList(
            $user->barangay_id,
            $selectedPurokId,
            'pending',
            true,
            'pending_page',
            $registrationSearch
        );

        $needsCorrectionRegistrations = $this->registrationList(
            $user->barangay_id,
            $selectedPurokId,
            'needs_correction',
            true,
            'needs_correction_page',
            $registrationSearch
        );

        $rejectedRegistrations = $this->registrationList(
            $user->barangay_id,
            $selectedPurokId,
            'rejected',
            false,
            'rejected_page',
            $registrationSearch
        );

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

        /*
        |--------------------------------------------------------------------------
        | Approved Residents
        |--------------------------------------------------------------------------
        |
        | The approved roster is paginated and searched on the server so the
        | page never loads every resident of the barangay into the browser.
        | The current query string is preserved on the pagination links.
        |
        */

        $residentSearch = trim(
            (string) $request->query('resident_search', '')
        );

        $residents = Resident::query()
            ->with([
                'purok:id,name',
                'sectors:id,name,slug',
                'account:id,resident_id,is_active',
                'qrCredential:id,resident_id,is_active,revoked_at',
            ])
            ->where('barangay_id', $user->barangay_id)
            ->when($selectedPurokId !== null, function ($query) use ($selectedPurokId) {
                $query->where('purok_id', $selectedPurokId);
            })
            ->when($residentSearch !== '', function ($query) use ($residentSearch) {
                $like = '%'.$residentSearch.'%';

                $query->where(function ($query) use ($like) {
                    $query->where('resident_number', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('purok', function ($query) use ($like) {
                            $query->where('name', 'like', $like);
                        });
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Resident $resident) {
                return [
                    'id' => $resident->id,
                    'residentId' => $resident->resident_number,
                    'fullName' => $resident->full_name,
                    'sex' => $resident->sex,
                    'purok' => $resident->purok?->name ?? '—',
                    'sectors' => $resident->sectors
                        ->map(function ($sector) {
                            return [
                                'id' => $sector->id,
                                'name' => $sector->name,
                                'slug' => $sector->slug,
                            ];
                        })
                        ->values(),
                    'status' => $resident->status,
                    'accountActive' => (bool) (
                        $resident->account?->is_active ?? false
                    ),
                    'qrStatus' => $this->resolveQrStatus($resident),
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | All Residents (permanent residents + registration-only records)
        |--------------------------------------------------------------------------
        |
        | A single, barangay-scoped, server-side paginated view that combines
        | permanent residents with registrations that have not produced a
        | resident yet. Duplicates are prevented by excluding any registration
        | that already has a linked resident row.
        |
        */

        $allResidents = $this->allResidents(
            (int) $user->barangay_id,
            $selectedPurokId,
            $residentSearch
        );

        return Inertia::render('Admin/Residents', [
            'allResidents' => $allResidents,

            'pendingRegistrations' => $pendingRegistrations,

            'needsCorrectionRegistrations' => $needsCorrectionRegistrations,

            'rejectedRegistrations' => $rejectedRegistrations,

            'puroks' => $puroks,

            'residents' => $residents,

            'filters' => [
                'resident_search' => $residentSearch,
                'registration_search' => $registrationSearch,
                'purok_id' => $selectedPurokId,
            ],

            'stats' => [
                'totalResidents' => $totalResidents,
                'activeResidents' => $activeResidents,
                'pendingRegistrations' => $pendingCount,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Read-only Resident / Registration Details
    |--------------------------------------------------------------------------
    */

    /**
     * Read-only details for a single permanent resident.
     *
     * Scoped to the authenticated admin's barangay; cross-barangay records
     * return 404. Never exposes QR tokens, password hashes, or account
     * credentials.
     */
    public function showResidentDetails(
        Request $request,
        Resident $resident
    ): JsonResponse {
        $this->authorizeResident($request, $resident);

        $resident->load([
            'barangay:id,name',
            'purok:id,name',
            'sectors:id,name,slug',
            'account:id,resident_id,is_active',
        ]);

        return response()->json([
            'type' => 'resident',
            'data' => [
                'resident_number' => $resident->resident_number,
                'first_name' => $resident->first_name,
                'middle_name' => $resident->middle_name,
                'last_name' => $resident->last_name,
                'suffix' => $resident->suffix,
                'birthdate' => $resident->birthdate?->format('Y-m-d'),
                'sex' => $resident->sex,
                'civil_status' => $resident->civil_status,
                'address_line' => $resident->address_line,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'status' => $resident->status,
                'approved_at' => $resident->approved_at?->format('Y-m-d'),
                'barangay' => $resident->barangay?->name,
                'purok' => $resident->purok?->name,
                'account_active' => (bool) (
                    $resident->account?->is_active ?? false
                ),
                'sectors' => $resident->sectors
                    ->map(fn ($sector) => [
                        'id' => $sector->id,
                        'name' => $sector->name,
                        'slug' => $sector->slug,
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * Read-only details for a registration-only record (an application that
     * has not produced a permanent resident yet).
     *
     * Scoped to the authenticated admin's barangay; cross-barangay records
     * return 404. Never exposes the stored password hash.
     */
    public function showRegistrationDetails(
        Request $request,
        ResidentRegistration $registration
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
        );

        $registration->load([
            'barangay:id,name',
            'purok:id,name',
            'sectors.sector:id,name,slug',
            'documents',
        ]);

        return response()->json([
            'type' => 'registration',
            'data' => [
                'reference' => $registration->public_id,
                'first_name' => $registration->first_name,
                'middle_name' => $registration->middle_name,
                'last_name' => $registration->last_name,
                'suffix' => $registration->suffix,
                'birthdate' => $registration->birthdate?->format('Y-m-d'),
                'sex' => $registration->sex,
                'civil_status' => $registration->civil_status,
                'address_line' => $registration->address_line,
                'mobile' => $registration->mobile,
                'email' => $registration->email,
                'status' => $registration->status,
                'review_notes' => $registration->review_notes,
                'submitted_at' => $registration->submitted_at?->format('Y-m-d'),
                'reviewed_at' => $registration->reviewed_at?->format('Y-m-d'),
                'last_resubmitted_at' => $registration->last_resubmitted_at
                    ?->format('Y-m-d'),
                'barangay' => $registration->barangay?->name,
                'purok' => $registration->purok?->name,
                'sectors' => $registration->sectors
                    ->map(fn ($registrationSector) => [
                        'id' => $registrationSector->sector?->id,
                        'name' => $registrationSector->sector?->name,
                        'slug' => $registrationSector->sector?->slug,
                    ])
                    ->filter(fn ($sector) => $sector['id'])
                    ->values(),
                'documents' => $registration->documents
                    ->map(
                        fn (RegistrationDocument $document) => $this->documentPayload(
                            $document,
                            $registration->id
                        )
                    )
                    ->values(),
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
        // Return 404 (instead of 403) so cross-barangay records are not disclosed.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
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
        | Return 404 (instead of 403) so cross-barangay records are not disclosed.
        |
        */

        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
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

        $previousStatus = $registration->status;

        /*
        |--------------------------------------------------------------------------
        | Prevent Double Approval
        |--------------------------------------------------------------------------
        */

        if ($registration->resident()->exists()) {
            throw ValidationException::withMessages([
                'approval' => 'This registration already has a permanent resident record.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Existing Resident Contact Information
        |--------------------------------------------------------------------------
        |
        | Fast, friendly pre-check. The authoritative check runs again inside
        | the transaction (after the barangay row lock), so two concurrent
        | approvals cannot both pass the per-barangay mobile/email uniqueness.
        |
        */

        $this->assertResidentContactIsUnique($registration);

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
                    'approval' => 'This registration has already been approved.',
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
            | Re-check Contact Uniqueness Under Lock
            |--------------------------------------------------------------------------
            |
            | The barangay row is locked above, so approvals for the same
            | barangay are serialized. Re-running the contact check here makes
            | the per-barangay mobile/email uniqueness race-safe.
            |
            */

            $this->assertResidentContactIsUnique($lockedRegistration);

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

            try {
                $resident = Resident::create([
                    'public_id' => (string) Str::uuid(),

                    'resident_registration_id' => $lockedRegistration->id,

                    'barangay_id' => $lockedRegistration->barangay_id,

                    'purok_id' => $lockedRegistration->purok_id,

                    'resident_number' => $residentNumber,

                    'first_name' => $lockedRegistration->first_name,

                    'middle_name' => $lockedRegistration->middle_name,

                    'last_name' => $lockedRegistration->last_name,

                    'suffix' => $lockedRegistration->suffix,

                    'birthdate' => $lockedRegistration->birthdate,

                    'sex' => $lockedRegistration->sex,

                    'civil_status' => $lockedRegistration->civil_status,

                    'address_line' => $lockedRegistration->address_line,

                    'mobile' => $lockedRegistration->mobile,

                    'email' => $lockedRegistration->email,

                    'status' => 'active',

                    'approved_at' => now(),
                ]);
            } catch (QueryException $exception) {
                /*
                |--------------------------------------------------------------------------
                | Defensive Uniqueness Handling
                |--------------------------------------------------------------------------
                |
                | The per-barangay mobile/email uniqueness is re-checked under
                | the barangay row lock above. If a raw unique-constraint
                | violation still occurs (for example on a database without
                | row locking), surface a clear validation error instead of
                | leaking a database exception.
                |
                */

                if ($this->isResidentContactUniqueViolation($exception)) {
                    throw ValidationException::withMessages([
                        'approval' => $this->residentContactConflictMessage(
                            $exception
                        ),
                    ]);
                }

                throw $exception;
            }

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
                'token' => 'KBQR-'.Str::random(64),
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

        AuditLogger::record(
            'resident_registration.approved',
            $registration->barangay_id,
            $user->id,
            'resident_registration',
            $registration->id,
            [
                'previous_status' => $previousStatus,
                'new_status' => 'approved',
            ],
            $request->ip(),
        );

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
        // Return 404 (instead of 403) so cross-barangay records are not disclosed.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            404
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

        $previousStatus = $registration->status;

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

        /*
        |--------------------------------------------------------------------------
        | Audit: registration rejected
        |--------------------------------------------------------------------------
        |
        | The free-text rejection reason is deliberately not recorded; only the
        | bounded status transition is written.
        |
        */

        AuditLogger::record(
            'resident_registration.rejected',
            $registration->barangay_id,
            $user->id,
            'resident_registration',
            $registration->id,
            [
                'previous_status' => $previousStatus,
                'new_status' => 'rejected',
            ],
            $request->ip(),
        );

        return back()->with(
            'success',
            'Resident application rejected.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Resident Status
    |--------------------------------------------------------------------------
    */

    /**
     * Activate or deactivate a permanent resident.
     *
     * Deactivating a resident immediately blocks portal access because the
     * resident auth middleware requires an active resident status.
     */
    public function updateResidentStatus(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::RESIDENT_STATUSES),
            ],
        ]);

        $previousStatus = $resident->status;

        $resident->update([
            'status' => $validated['status'],
        ]);

        if ($resident->wasChanged('status')) {
            AuditLogger::record(
                'resident.status_changed',
                $resident->barangay_id,
                $request->user()->id,
                'resident',
                $resident->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $resident->status,
                ],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            $validated['status'] === 'active'
                ? 'Resident activated successfully.'
                : 'Resident deactivated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Resident Login Account Status
    |--------------------------------------------------------------------------
    */

    /**
     * Enable or disable a resident's portal login account.
     *
     * This is independent of the resident record status: either one being
     * inactive blocks login through the resident auth middleware.
     */
    public function updateAccountStatus(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $account = $resident->account;

        abort_unless($account, 404);

        $previousStatus = $account->is_active ? 'active' : 'inactive';

        $account->update([
            'is_active' => $validated['is_active'],
        ]);

        if ($account->wasChanged('is_active')) {
            AuditLogger::record(
                'resident.account_status_changed',
                $resident->barangay_id,
                $request->user()->id,
                'resident',
                $resident->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $account->is_active ? 'active' : 'inactive',
                ],
                $request->ip(),
            );
        }

        return back()->with(
            'success',
            $validated['is_active']
                ? 'Resident login account enabled.'
                : 'Resident login account disabled.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Revoke Resident QR Credential
    |--------------------------------------------------------------------------
    */

    /**
     * Revoke a resident's active QR credential.
     *
     * The credential row is flipped to inactive and stamped with a revocation
     * time. Every downstream consumer (public verification, staff token
     * resolution, and QR attendance-proof issuance) already treats an inactive
     * or revoked credential as invalid, so the raw token stops working
     * everywhere the moment this lands.
     */
    public function revokeQrCredential(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        DB::transaction(function () use ($resident) {
            // Serialize concurrent credential changes for this resident.
            Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->first();

            $credential = ResidentQrCredential::query()
                ->where('resident_id', $resident->id)
                ->lockForUpdate()
                ->first();

            if (! $credential || ! $this->qrCredentialIsActive($credential)) {
                throw ValidationException::withMessages([
                    'qr' => 'This resident has no active QR credential to revoke.',
                ]);
            }

            $credential->forceFill([
                'is_active' => false,
                'revoked_at' => now(),
            ])->save();
        });

        return back()->with(
            'success',
            'QR credential revoked successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reissue Resident QR Credential
    |--------------------------------------------------------------------------
    */

    /**
     * Issue a fresh QR credential for a resident, invalidating the previous one.
     *
     * The credential row is updated in place inside a transaction and behind a
     * row lock. Because `resident_qr_credentials.resident_id` is unique there is
     * only ever one credential row per resident, so the replacement and the
     * invalidation of the old token commit atomically: there is no window in
     * which both an old and a new credential are usable.
     */
    public function reissueQrCredential(
        Request $request,
        Resident $resident
    ): RedirectResponse {
        $this->authorizeResident($request, $resident);

        DB::transaction(function () use ($resident) {
            // Serialize concurrent credential changes for this resident.
            Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->first();

            $credential = ResidentQrCredential::query()
                ->where('resident_id', $resident->id)
                ->lockForUpdate()
                ->first();

            $token = 'KBQR-'.Str::random(64);

            if (! $credential) {
                try {
                    ResidentQrCredential::create([
                        'resident_id' => $resident->id,
                        'token' => $token,
                        'is_active' => true,
                        'generated_at' => now(),
                        'revoked_at' => null,
                    ]);
                } catch (QueryException $exception) {
                    /*
                    |--------------------------------------------------------------------------
                    | Defensive QR Credential Uniqueness Handling
                    |--------------------------------------------------------------------------
                    |
                    | A concurrent request may have created this resident's first
                    | credential between the locked read above and this insert.
                    | The UNIQUE(resident_id) constraint then rejects the insert,
                    | so surface a clear validation error instead of leaking a
                    | raw database exception. Any other query failure is
                    | rethrown untouched.
                    |
                    */

                    if ($this->isResidentQrCredentialUniqueViolation($exception)) {
                        throw ValidationException::withMessages([
                            'qr' => 'This resident already has a QR credential. Please try again.',
                        ]);
                    }

                    throw $exception;
                }

                return;
            }

            $credential->forceFill([
                'token' => $token,
                'is_active' => true,
                'generated_at' => now(),
                'revoked_at' => null,
            ])->save();
        });

        return back()->with(
            'success',
            'QR credential reissued successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure the resident belongs to the authenticated admin's barangay.
     *
     * Returns 404 (instead of 403) so cross-barangay records are not
     * disclosed, matching the other resident admin endpoints.
     */
    private function authorizeResident(
        Request $request,
        Resident $resident
    ): void {
        $user = $request->user();

        abort_unless(
            (int) $resident->barangay_id === (int) $user->barangay_id,
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | QR Credential Status
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a credential is currently usable: active and not revoked.
     */
    private function qrCredentialIsActive(
        ResidentQrCredential $credential
    ): bool {
        return $credential->is_active && $credential->revoked_at === null;
    }

    /**
     * Human-readable QR credential state for the admin list.
     *
     * Returns "active", "revoked", or "none". The raw token is never exposed.
     */
    private function resolveQrStatus(Resident $resident): string
    {
        $credential = $resident->qrCredential;

        if (! $credential) {
            return 'none';
        }

        return $this->qrCredentialIsActive($credential)
            ? 'active'
            : 'revoked';
    }

    /*
    |--------------------------------------------------------------------------
    | Purok Filtering
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the `purok` query parameter to a purok that belongs to the given
     * barangay.
     *
     * Unknown, non-numeric, or foreign purok ids resolve to null (All Puroks)
     * so a tampered query string can never expose another barangay's records.
     */
    private function resolvePurokFilter(
        Request $request,
        int $barangayId
    ): ?int {
        $purokId = $request->query('purok');

        if (! is_numeric($purokId)) {
            return null;
        }

        $purokId = (int) $purokId;

        $belongsToBarangay = Purok::query()
            ->where('barangay_id', $barangayId)
            ->whereKey($purokId)
            ->exists();

        return $belongsToBarangay ? $purokId : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Resident Contact Uniqueness
    |--------------------------------------------------------------------------
    */

    /**
     * Ensure no permanent resident in the same barangay already uses this
     * registration's mobile number or email address.
     *
     * @throws ValidationException
     */
    private function assertResidentContactIsUnique(
        ResidentRegistration $registration
    ): void {
        $mobileExists = Resident::query()
            ->where('barangay_id', $registration->barangay_id)
            ->where('mobile', $registration->mobile)
            ->exists();

        if ($mobileExists) {
            throw ValidationException::withMessages([
                'approval' => 'A permanent resident with this mobile number already exists in this barangay.',
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
                    'approval' => 'A permanent resident with this email address already exists in this barangay.',
                ]);
            }
        }
    }

    /**
     * Determine whether a query exception is a per-barangay mobile/email
     * unique-constraint violation on the `residents` table.
     */
    private function isResidentContactUniqueViolation(
        QueryException $exception
    ): bool {
        $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();

        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'residents_barangay_mobile_unique')
            || str_contains($message, 'residents_barangay_email_unique')
            || str_contains($message, 'residents.mobile')
            || str_contains($message, 'residents.email');
    }

    /**
     * Human-readable message for a resident contact unique violation.
     */
    private function residentContactConflictMessage(
        QueryException $exception
    ): string {
        if (str_contains(strtolower($exception->getMessage()), 'email')) {
            return 'A permanent resident with this email address already exists in this barangay.';
        }

        return 'A permanent resident with this mobile number already exists in this barangay.';
    }

    /*
    |--------------------------------------------------------------------------
    | QR Credential Uniqueness
    |--------------------------------------------------------------------------
    */

    /**
     * Determine whether a query exception is a UNIQUE(resident_id) violation on
     * the `resident_qr_credentials` table.
     *
     * This constraint fails when two requests race to create the same
     * resident's first credential; the losing request must surface a validation
     * error instead of a raw database exception. The check requires both the
     * table name and the `resident_id` column so an unrelated unique violation
     * (for example on `token`) is not swallowed.
     */
    private function isResidentQrCredentialUniqueViolation(
        QueryException $exception
    ): bool {
        $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();

        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'resident_qr_credentials')
            && str_contains($message, 'resident_id');
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
    | Registration List Payloads
    |--------------------------------------------------------------------------
    */

    /**
     * Build the review-list payload for registrations in a single status,
     * scoped to the admin's barangay and the selected purok.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function registrationList(
        int $barangayId,
        ?int $purokId,
        string $status,
        bool $includeDocuments,
        string $pageName,
        string $search = ''
    ): LengthAwarePaginator {
        $relations = [
            'barangay:id,name,code',
            'purok:id,name',
            'sectors.sector:id,name,slug',
        ];

        if ($includeDocuments) {
            $relations[] = 'documents:id,resident_registration_id,sector_id,document_type,mime_type,file_size,verification_status';
        }

        return ResidentRegistration::query()
            ->with($relations)
            ->where('barangay_id', $barangayId)
            ->when($purokId !== null, function ($query) use ($purokId) {
                $query->where('purok_id', $purokId);
            })
            ->where('status', $status)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like) {
                    $query->where('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('suffix', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('public_id', 'like', $like)
                        ->orWhereHas('purok', function ($query) use ($like) {
                            $query->where('name', 'like', $like);
                        })
                        ->orWhereHas('barangay', function ($query) use ($like) {
                            $query->where('name', 'like', $like);
                        });
                });
            })
            ->latest('submitted_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], $pageName)
            ->withQueryString()
            ->through(
                fn (ResidentRegistration $registration) => $this->registrationListPayload(
                    $registration,
                    $includeDocuments
                )
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationListPayload(
        ResidentRegistration $registration,
        bool $includeDocuments
    ): array {
        return [
            'id' => $registration->id,
            'reference' => $registration->public_id,

            'first_name' => $registration->first_name,
            'middle_name' => $registration->middle_name,
            'last_name' => $registration->last_name,
            'suffix' => $registration->suffix,

            'birthdate' => $registration->birthdate?->format('Y-m-d'),

            'sex' => $registration->sex,
            'civil_status' => $registration->civil_status,
            'address_line' => $registration->address_line,

            'mobile' => $registration->mobile,
            'email' => $registration->email,

            'barangay' => $registration->barangay?->name,
            'purok' => $registration->purok?->name,

            'sectors' => $registration->sectors
                ->map(fn ($registrationSector) => [
                    'id' => $registrationSector->sector?->id,
                    'name' => $registrationSector->sector?->name,
                    'slug' => $registrationSector->sector?->slug,
                ])
                ->filter(fn ($sector) => $sector['id'])
                ->values(),

            'documents' => $includeDocuments
                ? $registration->documents
                    ->map(
                        fn (RegistrationDocument $document) => $this->documentPayload(
                            $document,
                            $registration->id
                        )
                    )
                    ->values()
                : [],

            'status' => $registration->status,

            'submitted_at' => $registration->submitted_at?->format('Y-m-d'),

            'review_notes' => $registration->review_notes,

            'reviewed_at' => $registration->reviewed_at?->format('Y-m-d'),

            'last_resubmitted_at' => $registration->last_resubmitted_at
                ?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(
        RegistrationDocument $document,
        int $registrationId
    ): array {
        return [
            'id' => $document->id,
            'type' => $document->document_type,
            'label' => $this->documentLabel($document->document_type),
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'verification_status' => $document->verification_status,
            'url' => route(
                'admin.residents.documents.show',
                [
                    'registration' => $registrationId,
                    'document' => $document->id,
                ]
            ),
            'is_image' => str_starts_with(
                (string) $document->mime_type,
                'image/'
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | All Residents (unified, paginated)
    |--------------------------------------------------------------------------
    */

    /**
     * Barangay-scoped, server-side paginated union of permanent residents and
     * registration-only records (registrations that have not produced a
     * resident yet).
     *
     * Only lightweight columns are unioned so the database performs the
     * filtering, ordering and pagination; the page's rows are then hydrated
     * with their relationships. This never loads the full dataset.
     */
    private function allResidents(
        int $barangayId,
        ?int $purokId,
        string $search
    ): LengthAwarePaginator {
        $residentRows = Resident::query()
            ->where('residents.barangay_id', $barangayId)
            ->select([
                DB::raw("'resident' as record_type"),
                'residents.id as record_id',
                'residents.purok_id as purok_id',
                'residents.first_name as first_name',
                'residents.middle_name as middle_name',
                'residents.last_name as last_name',
                'residents.mobile as mobile',
                'residents.email as email',
                // CAST to a character type so this UNION column matches the
                // uuid `reference` of the registrations branch on PostgreSQL.
                DB::raw('CAST(residents.resident_number AS VARCHAR) as reference'),
            ]);

        $registrationRows = ResidentRegistration::query()
            ->where('resident_registrations.barangay_id', $barangayId)
            ->whereDoesntHave('resident')
            ->select([
                DB::raw("'registration' as record_type"),
                'resident_registrations.id as record_id',
                'resident_registrations.purok_id as purok_id',
                'resident_registrations.first_name as first_name',
                'resident_registrations.middle_name as middle_name',
                'resident_registrations.last_name as last_name',
                'resident_registrations.mobile as mobile',
                'resident_registrations.email as email',
                // `public_id` is a uuid column; cast it to text so the UNION
                // column aligns with the residents branch's varchar reference.
                DB::raw('CAST(resident_registrations.public_id AS VARCHAR) as reference'),
            ]);

        $paginator = DB::query()
            ->fromSub($residentRows->unionAll($registrationRows), 'all_residents')
            ->when($purokId !== null, fn ($query) => $query->where('purok_id', $purokId))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like) {
                    $query->where('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('reference', 'like', $like);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('record_type')
            ->orderBy('record_id')
            ->paginate(15, ['*'], 'all_page')
            ->withQueryString();

        $pageRows = $paginator->getCollection();

        $residentIds = $pageRows
            ->where('record_type', 'resident')
            ->pluck('record_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $registrationIds = $pageRows
            ->where('record_type', 'registration')
            ->pluck('record_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $residents = Resident::query()
            ->with([
                'purok:id,name',
                'sectors:id,name,slug',
                'account:id,resident_id,is_active',
                'qrCredential:id,resident_id,is_active,revoked_at',
            ])
            ->whereIn('id', $residentIds)
            ->get()
            ->keyBy('id');

        $registrations = ResidentRegistration::query()
            ->with([
                'purok:id,name',
                'sectors.sector:id,name,slug',
            ])
            ->whereIn('id', $registrationIds)
            ->get()
            ->keyBy('id');

        $paginator->setCollection(
            $pageRows->map(function ($row) use ($residents, $registrations) {
                $id = (int) $row->record_id;

                if ($row->record_type === 'resident') {
                    $resident = $residents->get($id);

                    if (! $resident) {
                        return null;
                    }

                    return [
                        'type' => 'resident',
                        'id' => $resident->id,
                        'reference' => $resident->resident_number,
                        'fullName' => $resident->full_name,
                        'sex' => $resident->sex,
                        'purok' => $resident->purok?->name ?? '—',
                        'sectors' => $resident->sectors
                            ->map(fn ($sector) => [
                                'id' => $sector->id,
                                'name' => $sector->name,
                                'slug' => $sector->slug,
                            ])
                            ->values(),
                        'status' => $resident->status,
                        'accountActive' => (bool) (
                            $resident->account?->is_active ?? false
                        ),
                        'qrStatus' => $this->resolveQrStatus($resident),
                    ];
                }

                $registration = $registrations->get($id);

                if (! $registration) {
                    return null;
                }

                return [
                    'type' => 'registration',
                    'id' => $registration->id,
                    'reference' => $registration->public_id,
                    'fullName' => collect([
                        $registration->first_name,
                        $registration->middle_name,
                        $registration->last_name,
                        $registration->suffix,
                    ])->filter()->implode(' '),
                    'sex' => $registration->sex,
                    'purok' => $registration->purok?->name ?? '—',
                    'sectors' => $registration->sectors
                        ->map(fn ($registrationSector) => [
                            'id' => $registrationSector->sector?->id,
                            'name' => $registrationSector->sector?->name,
                            'slug' => $registrationSector->sector?->slug,
                        ])
                        ->filter(fn ($sector) => $sector['id'])
                        ->values(),
                    'status' => $registration->status,
                    'accountActive' => null,
                    'qrStatus' => null,
                ];
            })->filter()->values()
        );

        return $paginator;
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
