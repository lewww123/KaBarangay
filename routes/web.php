<?php

use App\Http\Controllers\Admin\AnnouncementApprovalController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CertificateRequestController as AdminCertificateRequestController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmergencyContactController as AdminEmergencyContactController;
use App\Http\Controllers\Admin\OrgChartController as AdminOrgChartController;
use App\Http\Controllers\Admin\PopulationController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\ResidentController as AdminResidentController;
use App\Http\Controllers\Admin\ResidentRegistrationController;
use App\Http\Controllers\Admin\SectorApplicationController as AdminSectorApplicationController;
use App\Http\Controllers\Admin\SectorController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\StaffManagementController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\Clerk\AnnouncementController as ClerkAnnouncementController;
use App\Http\Controllers\Clerk\CertificateController as ClerkCertificateController;
use App\Http\Controllers\Clerk\CertificateRequestController as ClerkCertificateRequestController;
use App\Http\Controllers\Clerk\CitizenCharterController as ClerkCitizenCharterController;
use App\Http\Controllers\Clerk\DisbursementDocumentController as ClerkDisbursementDocumentController;
use App\Http\Controllers\Clerk\OrgChartController as ClerkOrgChartController;
use App\Http\Controllers\ClerkController;
use App\Http\Controllers\Lupon\LuponCaseController;
use App\Http\Controllers\Lupon\LuponComplaintController;
use App\Http\Controllers\Lupon\LuponHearingController;
use App\Http\Controllers\Lupon\LuponSettlementController;
use App\Http\Controllers\LuponController;
use App\Http\Controllers\Pwd\AnnouncementController as PwdAnnouncementController;
use App\Http\Controllers\PwdController;
use App\Http\Controllers\Resident\AnnouncementController as ResidentAnnouncementController;
use App\Http\Controllers\Resident\CertificateRequestController;
use App\Http\Controllers\Resident\CitizenCharterController as ResidentCitizenCharterController;
use App\Http\Controllers\Resident\DisbursementDocumentController as ResidentDisbursementDocumentController;
use App\Http\Controllers\Resident\LuponCaseController as ResidentLuponCaseController;
use App\Http\Controllers\Resident\LuponComplaintController as ResidentLuponComplaintController;
use App\Http\Controllers\Resident\OrgChartController as ResidentOrgChartController;
use App\Http\Controllers\Resident\PasswordResetController;
use App\Http\Controllers\Resident\QrVerificationController;
use App\Http\Controllers\Resident\RegistrationController;
use App\Http\Controllers\Resident\RegistrationOtpController;
use App\Http\Controllers\Resident\RegistrationEmailVerificationController;
use App\Http\Controllers\Resident\ResidentEmailVerificationController;
use App\Http\Controllers\Resident\ResidentRegistrationAccessController;
use App\Http\Controllers\Resident\ResidentRegistrationCorrectionController;
use App\Http\Controllers\Resident\SectorApplicationController as ResidentSectorApplicationController;
use App\Http\Controllers\Resident\SettingsController as ResidentSettingsController;
use App\Http\Controllers\SectorApplicationDocumentController;
use App\Http\Controllers\Senior\AnnouncementController as SeniorAnnouncementController;
use App\Http\Controllers\SeniorController;
use App\Http\Middleware\EnsureResidentRegistrationAccess;
use App\Models\Announcement;
use App\Models\EmergencyContact;
use App\Models\Resident;
use App\Models\SharedEmergencyContact;
use App\Support\AuditLogger;
use App\Support\PhoneNumber;
use App\Support\QrAttendanceProof;
use App\Support\ResidentContext;
use App\Support\ResidentEmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

require __DIR__.'/attendance-offline.php';

/*
|--------------------------------------------------------------------------
| Barangay Selection
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => redirect('/resident/login'))->name('home');
Route::get('/offline/session', [\App\Http\Controllers\OfflineAccessController::class, 'session'])->name('offline.session');
// Accept legacy logout links from either portal, including expired sessions.
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
Route::view('/privacy-policy', 'legal.privacy')->name('privacy-policy');
Route::view('/terms-and-conditions', 'legal.terms')->name('terms-and-conditions');
Route::get('/terms-of-service', fn () => redirect('/terms-and-conditions', 301))->name('terms-of-service');
Route::post('/chatbot/send', [ChatbotController::class, 'sendMessage'])
    ->middleware(['resident.auth', 'throttle:20,1'])->name('chatbot.send');
Route::post('/register/send-otp', [RegistrationOtpController::class, 'send'])
    ->middleware('throttle:registration-otp-send')->name('register.send-otp');
Route::post('/register/verify-otp', [RegistrationOtpController::class, 'verify'])
    ->middleware('throttle:registration-otp-verify')->name('register.verify-otp');
/*
|--------------------------------------------------------------------------
| Public Resident QR Verification
|--------------------------------------------------------------------------
*/

Route::get('/verify/resident/{token}', [QrVerificationController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('resident.qr.verify');

Route::get('/sector-applications/{application}/documents/{document}/file', [SectorApplicationDocumentController::class, 'show'])
    ->name('sector-applications.documents.show');
Route::get('/sector-applications/{application}/documents/{document}/download', [SectorApplicationDocumentController::class, 'download'])
    ->name('sector-applications.documents.download');

/*
|--------------------------------------------------------------------------
| Resident Authentication & Public Pages
|--------------------------------------------------------------------------
*/

Route::prefix('resident')->name('resident.')->group(function () {

    Route::inertia('/login', 'Resident/Login')->name('login');
    Route::post('/logout', [\App\Http\Controllers\Resident\SessionController::class, 'destroy'])->name('logout');
    Route::post('/login', [AuthController::class, 'processResidentLogin'])->middleware('throttle:login-resident')->name('login.process');
    Route::get('/login/barangays', [AuthController::class, 'residentLoginBarangays'])->name('login.barangays');

    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
    Route::get('/register/submitted/{publicId}', [RegistrationController::class, 'submitted'])->name('registration.submitted');
    Route::get('/application/access', [ResidentRegistrationAccessController::class, 'create'])
        ->name('registration.access');
    Route::post('/application/access', [ResidentRegistrationAccessController::class, 'authenticate'])
        ->middleware('throttle:5,1')
        ->name('registration.access.authenticate');
    Route::middleware(EnsureResidentRegistrationAccess::class)->group(function () {
        Route::get('/application/{reference}', [ResidentRegistrationAccessController::class, 'show'])
            ->name('registration.application.show');
        Route::post('/application/{reference}/email-verification', [RegistrationEmailVerificationController::class, 'verifyOtp'])
            ->middleware('throttle:10,1')
            ->name('registration.application.email-verification');
        Route::post('/application/{reference}/correction', [ResidentRegistrationCorrectionController::class, 'resubmit'])
            ->name('registration.application.correction.resubmit');
        Route::post('/application/{reference}/logout', [ResidentRegistrationAccessController::class, 'logout'])
            ->name('registration.application.logout');
    });
    Route::get('/register/verification/resend', [RegistrationEmailVerificationController::class, 'showResend'])
        ->name('registration.verification.resend');
    Route::post('/register/verification/resend', [RegistrationEmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('registration.verification.resend.store');
    Route::get('/register/email/verify/{token}', [RegistrationEmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('registration.email.verify');

    Route::get('/email/verify/{token}', [ResidentEmailVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('email.verify');

    Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('forgot-password');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');

    Route::middleware('resident.auth')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Resident Dashboard
        |--------------------------------------------------------------------------
        */
        Route::get('/', function () {
            $resident = ResidentContext::get();

            $qrUrl = function () use ($resident) {
                $resident->loadMissing('qrCredential');

                return $resident->qrCredential && $resident->qrCredential->is_active && ! $resident->qrCredential->revoked_at
                ? route('resident.qr.verify', ['token' => $resident->qrCredential->token])
                : null;
            };

            $latestAnnouncement = function () use ($resident) {
                $sectorSlugs = $resident->loadMissing('sectors')->sectors->pluck('slug')->toArray();

                return Announcement::query()
                    ->where('approval_status', 'approved')
                    ->where('is_published', true)
                    ->where('barangay_id', $resident->barangay_id)
                    ->where(function ($query) use ($sectorSlugs) {
                        $query->where('target_audience', 'all');
                        if (in_array('senior', $sectorSlugs)) {
                            $query->orWhere('target_audience', 'senior_citizens');
                        }
                        if (in_array('pwd', $sectorSlugs)) {
                            $query->orWhere('target_audience', 'pwd');
                        }
                    })
                    ->latest()
                    ->first();
            };

            $contacts = null;
            $resolveContacts = function () use ($resident, &$contacts): array {
                if ($contacts !== null) {
                    return $contacts;
                }

                $sharedEmergencyContacts = [];
                $barangayHotlines = [];

                try {
                    if (Schema::hasTable('shared_emergency_contacts')) {
                        $sharedEmergencyContacts = SharedEmergencyContact::query()
                            ->active()
                            ->verified()
                            ->orderBy('display_order')
                            ->orderBy('id')
                            ->get()
                            ->map(fn (SharedEmergencyContact $contact) => [
                                'id' => $contact->id,
                                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                                'service_type' => $contact->service_type,
                                'sub_category' => $contact->service_type,
                                'name' => $contact->name,
                                'label' => $contact->label,
                                'phone_number' => $contact->phone_number,
                                'coverage_area' => $contact->coverage_area,
                                'can_call' => (bool) $contact->can_call,
                                'can_sms' => (bool) $contact->can_sms,
                                'tel_uri' => $contact->telUri(),
                                'sms_uri' => $contact->smsUri(),
                            ])
                            ->values()
                            ->all();
                    }
                } catch (Throwable) {
                    $sharedEmergencyContacts = [];
                }

                try {
                    if (Schema::hasTable('emergency_contacts')) {
                        $sharedPhones = [];

                        foreach ($sharedEmergencyContacts as $sharedContact) {
                            $normalized = PhoneNumber::normalize($sharedContact['phone_number']);

                            if ($normalized !== '') {
                                $sharedPhones[$normalized] = true;
                            }
                        }

                        $barangayHotlines = EmergencyContact::query()
                            ->where('barangay_id', $resident->barangay_id)
                            ->where('category', EmergencyContact::CATEGORY_BARANGAY_HOTLINE)
                            ->where('is_active', true)
                            ->orderBy('display_order')
                            ->orderBy('id')
                            ->get()
                            ->map(fn (EmergencyContact $contact) => [
                                'id' => $contact->id,
                                'category' => $contact->category,
                                'sub_category' => $contact->sub_category,
                                'name' => $contact->name,
                                'label' => $contact->label,
                                'phone_number' => $contact->phone_number,
                                'can_call' => (bool) $contact->can_call,
                                'can_sms' => (bool) $contact->can_sms,
                                'tel_uri' => 'tel:'.PhoneNumber::normalize($contact->phone_number),
                                'sms_uri' => 'sms:'.PhoneNumber::normalize($contact->phone_number),
                            ])
                            ->filter(function (array $contact) use ($sharedPhones) {
                                $normalized = PhoneNumber::normalize($contact['phone_number']);

                                return $normalized === '' || ! isset($sharedPhones[$normalized]);
                            })
                            ->values()
                            ->all();
                    }
                } catch (Throwable) {
                    $barangayHotlines = [];
                }

                return $contacts = compact('sharedEmergencyContacts', 'barangayHotlines');
            };

            $defer = fn (Closure $callback) => config('inertia.defer_heavy_props', true)
                ? Inertia::defer($callback)
                : $callback();

            return Inertia::render('Resident/Dashboard', [
                'resident' => $defer(function () use ($resident): array {
                    $resident->loadMissing(['barangay', 'sectors']);

                    return [
                        'id' => $resident->id,
                        'first_name' => $resident->first_name,
                        'middle_name' => $resident->middle_name,
                        'last_name' => $resident->last_name,
                        'suffix' => $resident->suffix,
                        'full_name' => $resident->full_name,
                        'resident_number' => $resident->resident_number,
                        'barangay' => [
                            'id' => $resident->barangay?->id,
                            'name' => $resident->barangay?->name,
                            'code' => $resident->barangay?->code,
                            'city_municipality' => $resident->barangay?->city_municipality,
                            'province' => $resident->barangay?->province,
                        ],
                        'sectors' => $resident->sectors->map(fn ($sector) => [
                            'id' => $sector->id,
                            'name' => $sector->name,
                            'slug' => $sector->slug,
                        ])->values(),
                    ];
                }),
                'qrUrl' => $defer($qrUrl),
                'latestAnnouncement' => $defer(function () use ($latestAnnouncement): ?array {
                    $announcement = $latestAnnouncement();

                    return $announcement ? [
                        'id' => $announcement->id,
                        'title' => $announcement->title,
                        'created_at' => $announcement->created_at->format('F j, Y'),
                    ] : null;
                }),
                'sharedEmergencyContacts' => $defer(fn () => $resolveContacts()['sharedEmergencyContacts']),
                'barangayHotlines' => $defer(fn () => $resolveContacts()['barangayHotlines']),
            ]);
        })->name('dashboard');

        Route::get('/certificates', [CertificateRequestController::class, 'index'])->name('certificates');
        Route::get('/certificates/offline-context', [\App\Http\Controllers\Resident\OfflineCertificateController::class, 'context'])->name('certificates.offline-context');
        Route::post('/certificates/sync', [\App\Http\Controllers\Resident\OfflineCertificateController::class, 'sync'])->middleware('throttle:10,1')->name('certificates.sync');
        Route::post('/certificates', [CertificateRequestController::class, 'store'])->middleware('throttle:10,1')->name('certificates.store');

        Route::get('/lupon', [ResidentLuponComplaintController::class, 'index'])->name('lupon');
        Route::post('/lupon/complaints', [ResidentLuponComplaintController::class, 'store'])->name('lupon.complaints.store');
        Route::get('/lupon/evidence/{evidence}', [\App\Http\Controllers\Lupon\LuponEvidenceController::class, 'download'])->name('lupon.evidence.download');
        Route::get('/lupon/documents/{document}', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'download'])->name('lupon.documents.download');
        Route::get('/lupon/cases/{case}', [ResidentLuponCaseController::class, 'show'])->name('lupon.cases.show');

        Route::get('/announcements', [ResidentAnnouncementController::class, 'index'])->name('announcements');
        Route::get('/announcements/{announcement}', [ResidentAnnouncementController::class, 'show'])->name('announcements.show');
        Route::get('/programs', [ResidentAnnouncementController::class, 'programs'])->name('programs');
        Route::get('/updates', [ResidentAnnouncementController::class, 'index'])->name('updates')->defaults('kind', 'updates');
        Route::get('/updates/{announcement}', [ResidentAnnouncementController::class, 'show'])->name('updates.show')->defaults('kind', 'updates');

        Route::get('/disbursement', [ResidentDisbursementDocumentController::class, 'index'])->name('disbursement');
        Route::get('/disbursement/{disbursementDocument}', [ResidentDisbursementDocumentController::class, 'show'])->name('disbursement.show');
        Route::get('/disbursement/{disbursementDocument}/file', [ResidentDisbursementDocumentController::class, 'file'])->name('disbursement.file');
        Route::get('/disbursement/{disbursementDocument}/download', [ResidentDisbursementDocumentController::class, 'download'])->name('disbursement.download');
        Route::get('/citizen-charter', [ResidentCitizenCharterController::class, 'index'])->name('citizen-charter');
        Route::get('/citizen-charter/{service}', [ResidentCitizenCharterController::class, 'show'])->name('citizen-charter.show');
        Route::get('/citizen-charter/{service}/file', [ResidentCitizenCharterController::class, 'file'])->name('citizen-charter.file');
        Route::get('/citizen-charter/{service}/download', [ResidentCitizenCharterController::class, 'download'])->name('citizen-charter.download');
        Route::get('/org-chart', [ResidentOrgChartController::class, 'index'])->name('org-chart');
        Route::get('/org-chart/{position}/photo', [ResidentOrgChartController::class, 'photo'])->name('org-chart.photo');
        Route::inertia('/chatbot', 'Resident/Chatbot')->name('chatbot');
        Route::get('/sector-applications', [ResidentSectorApplicationController::class, 'index'])->name('sector-applications');
        Route::post('/sector-applications', [ResidentSectorApplicationController::class, 'store'])->name('sector-applications.store');
        Route::get('/sector-applications/{id}/edit', [ResidentSectorApplicationController::class, 'edit'])->name('sector-applications.edit');
        Route::post('/sector-applications/{id}', [ResidentSectorApplicationController::class, 'update'])->name('sector-applications.update');

        /*
        |--------------------------------------------------------------------------
        | Resident Profile
        |--------------------------------------------------------------------------
        */
        Route::get('/profile', function () {
            $resident = ResidentContext::get();
            $loadProfile = function () use ($resident): array {
                $resident->loadMissing(['barangay', 'purok', 'account', 'sectors', 'qrCredential']);
                $birthdate = $resident->birthdate;
                $age = $birthdate ? $birthdate->age : 0;
                $qrCredential = $resident->qrCredential;

                return [
                    'fullName' => $resident->full_name,
                    'residentId' => $resident->resident_number,
                    'birthdate' => $birthdate ? $birthdate->format('F j, Y') : 'Not provided',
                    'age' => $age,
                    'sex' => $resident->sex ?? 'Not provided',
                    'civilStatus' => $resident->civil_status ?? 'Not provided',
                    'mobileNumber' => $resident->mobile ?? '',
                    'email' => $resident->email ?? '',
                    'emailVerified' => $resident->email_verified_at !== null,
                    'purok' => $resident->purok?->name ?? 'Not provided',
                    'barangay' => $resident->barangay?->name ?? 'Not provided',
                    'city' => $resident->barangay?->city_municipality ?? 'Not provided',
                    'province' => $resident->barangay?->province ?? 'Not provided',
                    'accountStatus' => ($resident->account?->is_active ?? false) ? 'Verified' : 'Inactive',
                    'registeredDate' => ($resident->approved_at ?? $resident->created_at)->format('F j, Y'),
                    'classification' => $resident->sectors->isEmpty()
                        ? 'Regular Resident'
                        : $resident->sectors->pluck('name')->implode(' • '),
                    'qrToken' => ($qrCredential?->is_active && ! $qrCredential?->revoked_at)
                        ? $qrCredential->token
                        : null,
                ];
            };

            return Inertia::render('Resident/Profile', [
                'profile' => config('inertia.defer_heavy_props', true) ? Inertia::defer($loadProfile) : $loadProfile(),
            ]);
        })->name('profile');

        /*
        |--------------------------------------------------------------------------
        | Resident Profile Contact Update
        |--------------------------------------------------------------------------
        */
        Route::post('/profile/contact', function (
            Request $request,
            ResidentEmailVerificationService $emailVerification
        ) {
            $resident = Resident::query()
                ->where('id', session('resident_id'))
                ->where('status', 'active')
                ->firstOrFail();

            $validated = $request->validate([
                // Mobile is NOT NULL in the residents table, so it is required.
                'mobileNumber' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('residents', 'mobile')
                        ->where(fn ($query) => $query->where('barangay_id', $resident->barangay_id))
                        ->ignore($resident->id),
                ],
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('residents', 'email')
                        ->where(fn ($query) => $query->where('barangay_id', $resident->barangay_id))
                        ->ignore($resident->id),
                ],
            ], [
                'mobileNumber.required' => 'A mobile number is required.',
                'mobileNumber.unique' => 'This mobile number is already registered in your barangay.',
                'email.unique' => 'This email address is already registered in your barangay.',
            ]);

            $newEmail = isset($validated['email'])
                ? strtolower(trim((string) $validated['email']))
                : null;

            $emailChanged = strtolower(trim((string) $resident->email)) !==
                strtolower(trim((string) $newEmail));

            $resident->update([
                'mobile' => $validated['mobileNumber'],
                'email' => $newEmail,
                'email_verified_at' => $emailChanged
                    ? null
                    : $resident->email_verified_at,
            ]);

            $sent = false;

            if ($emailChanged) {
                $emailVerification->invalidate($resident);
                AuditLogger::record(
                    'resident.email_changed',
                    $resident->barangay_id,
                    null,
                    'resident',
                    $resident->id,
                    [],
                    $request->ip(),
                );

                if ($newEmail !== null) {
                    $sent = $emailVerification->send($resident);
                }
            }

            $message = 'Contact information updated successfully.';

            if ($emailChanged && $newEmail !== null) {
                $message .= $sent
                    ? ' Verify the new email address using the link we sent.'
                    : ' The new email could not be sent a verification link yet.';
            }

            return back()->with('success', $message);
        })->name('profile.contact.update');

        Route::post('/email/verification/resend', [ResidentEmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1')
            ->name('email.verification.resend');

        Route::inertia('/settings', 'Resident/Settings')->name('settings');
        Route::post('/settings/password', [ResidentSettingsController::class, 'updatePassword'])
            ->middleware('throttle:6,1')
            ->name('settings.password.update');
        Route::inertia('/about', 'Resident/About')->name('about');
    });
});

/*
|--------------------------------------------------------------------------
| Admin & Staff Authentication Group
|--------------------------------------------------------------------------
*/

Route::domain(config('app.staff_domain') ?: 'staff.kabarangay.com')
    ->get('/', [StaffLoginController::class, 'showLoginForm'])->middleware('guest');

Route::group(['domain' => config('app.staff_domain')], function () {
    Route::get('/staff/login', [StaffLoginController::class, 'showLoginForm'])->middleware('guest')->name('login');
    Route::post('/login', [StaffLoginController::class, 'login'])
        ->middleware(['guest', 'throttle:login-staff'])
        ->name('login.store');

Route::middleware('auth')->group(function () {

    Route::get('/admin/residents/search', \App\Http\Controllers\ResidentSearchController::class)
        ->middleware(['role:admin,clerk', 'throttle:120,1'])
        ->name('admin.residents.search');

    /*
    |--------------------------------------------------------------------------
    | Staff QR Token Verification API (for Attendance & Claiming)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['role:admin,clerk,senior,senior_staff,pwd,pwd_staff', 'throttle:60,1'])->get('/api/qr/verify-token', function (Request $request) {
        $token = $request->query('token');

        if (! $token) {
            return response()->json([
                'message' => 'QR token is missing.',
            ], 400);
        }

        /*
        |----------------------------------------------------------------------
        | Resident QR payload normalization
        |----------------------------------------------------------------------
        |
        | A resident's QR code encodes the public verification URL
        | (/verify/resident/{token}) instead of the raw credential token, so a
        | scanner submits the whole URL. The credential token is taken from
        | that exact path shape only, so both payload forms resolve to the
        | same resident.
        |
        | The original host is deliberately not part of the contract: the
        | token itself is the credential and this endpoint already accepts it
        | directly, so a verification URL generated on another host must
        | still resolve. Every other input keeps its previous meaning (raw
        | token or resident number) and is never rewritten.
        |
        */

        if (is_string($token)) {
            $path = null;

            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $token) === 1) {
                $parsed = parse_url($token);
                $path = is_array($parsed) && isset($parsed['path']) ? $parsed['path'] : null;
            } else {
                $parsed = parse_url($token, PHP_URL_PATH);
                $path = is_string($parsed) ? $parsed : null;
            }

            // Only an exact "/verify/resident/{token}" path is accepted, and
            // the token segment is decoded exactly once.
            if (is_string($path) && preg_match('#^/verify/resident/([^/]+)$#', $path, $matches) === 1) {
                $token = urldecode($matches[1]);
            }
        }

        // Hanapin muna kung ito ay valid resident number o kaya ay token sa qr_credentials
        $resident = Resident::query()
            ->where('barangay_id', $request->user()->barangay_id)
            ->where(function ($query) use ($token) {
                $query->where('resident_number', strtoupper($token))
                    ->orWhereHas('qrCredential', function ($query) use ($token) {
                        $query->where('token', $token)
                            ->where('is_active', true)
                            ->whereNull('revoked_at');
                    });
            })
            ->first();

        if (! $resident || $resident->status !== 'active') {
            return response()->json([
                'message' => 'Resident not found or inactive.',
            ], 404);
        }

        // When the caller asks for a proof (announcement_id supplied), mint a
        // short-lived, single-use proof that later unlocks QR attendance for
        // this resident on this announcement. Only a real, active QR
        // credential may mint a proof: a resident number is a human-readable
        // identifier, not cryptographic proof.
        $qrProof = null;
        $announcementId = $request->query('announcement_id');

        if ($announcementId !== null && $announcementId !== '') {
            $announcement = Announcement::query()->whereKey($announcementId)->first();

            if (! $announcement || $announcement->approval_status !== 'approved' || ! $announcement->requires_attendance) {
                return response()->json([
                    'message' => 'Attendance cannot be recorded for this announcement.',
                ], 422);
            }

            $matchedCredential = $resident->qrCredential()
                ->where('token', $token)
                ->where('is_active', true)
                ->whereNull('revoked_at')
                ->exists();

            if (! $matchedCredential) {
                return response()->json([
                    'message' => 'Scan a valid KaBarangay QR code to record QR attendance.',
                ], 422);
            }

            $qrProof = QrAttendanceProof::issue($request->user(), $resident, $announcement);
        }

        $resident->loadMissing('sectors:id,name,slug');

        return response()->json([
            'resident' => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'resident_number' => $resident->resident_number,
                'sectors' => $resident->sectors->pluck('slug')->values()->toArray(),
            ],
            'qr_proof' => $qrProof,
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {

        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // Announcement review uses the controller's completed-state and revision guards.
        Route::get('/announcements/approvals', [AnnouncementApprovalController::class, 'index'])->name('announcements.approvals');
        Route::post('/announcements/{announcement}/approve', [AnnouncementApprovalController::class, 'approve'])->name('announcements.approve');
        Route::post('/announcements/{announcement}/reject', [AnnouncementApprovalController::class, 'reject'])->name('announcements.reject');

        // Resident QR Scanner
        Route::inertia('/qr-scanner', 'Admin/QrScanner')->name('qr-scanner');

        // Resident Masterlist / Claims
        Route::post('/residents', [AdminResidentController::class, 'store'])->name('residents.store');
        Route::get('/residents/households/search', [AdminResidentController::class, 'searchHouseholds'])->name('residents.households.search');
        Route::patch('/residents/{resident}/household', [AdminResidentController::class, 'updateHousehold'])->name('residents.household.update');
        Route::get('/residents/{resident}/qr/credential', [AdminResidentController::class, 'showQrCredential'])->name('residents.qr.credential');

        // Resident Registrations
        Route::get('/residents', [ResidentRegistrationController::class, 'index'])->name('residents');
        Route::get('/residents/{resident}/details', [ResidentRegistrationController::class, 'showResidentDetails'])->name('residents.details');
        Route::get('/resident-registrations/{registration}/details', [ResidentRegistrationController::class, 'showRegistrationDetails'])->name('residents.registrations.details');
        Route::get('/resident-registrations/{registration}/candidates', [ResidentRegistrationController::class, 'matchingCandidates'])->name('residents.registrations.candidates');
        Route::get('/residents/{registration}/documents/{document}', [ResidentRegistrationController::class, 'showDocument'])->name('residents.documents.show');
        Route::post('/residents/{registration}/correction', [ResidentRegistrationController::class, 'requestCorrection'])->name('residents.correction');
        Route::post('/residents/{registration}/approve', [ResidentRegistrationController::class, 'approve'])->name('residents.approve');
        Route::post('/residents/{registration}/reject', [ResidentRegistrationController::class, 'reject'])->name('residents.reject');
        Route::post('/resident-registration-notifications/{notification}/retry', [ResidentRegistrationController::class, 'retryNotification'])->name('resident-registration-notifications.retry');
        Route::patch('/residents/{resident}/status', [ResidentRegistrationController::class, 'updateResidentStatus'])->name('residents.status');
        Route::patch('/residents/{resident}/account-status', [ResidentRegistrationController::class, 'updateAccountStatus'])->name('residents.account-status');
        Route::post('/residents/{resident}/qr/revoke', [ResidentRegistrationController::class, 'revokeQrCredential'])->name('residents.qr.revoke');
        Route::post('/residents/{resident}/qr/reissue', [ResidentRegistrationController::class, 'reissueQrCredential'])->name('residents.qr.reissue');

        // Certificate Requests (Admin aggregate monitoring only)
        Route::get('/certificates', [AdminCertificateRequestController::class, 'index'])->name('certificates');

        Route::get('/sectors', [SectorController::class, 'index'])->name('sectors');

        // Sector Applications (Read-only oversight)
        Route::get('/sector-applications', [AdminSectorApplicationController::class, 'index'])->name('sector-applications.index');
        Route::get('/sector-applications/{application}', [AdminSectorApplicationController::class, 'show'])->name('sector-applications.show');

        // Staff Management
        Route::get('/staff', [StaffManagementController::class, 'index'])->name('staff');
        Route::get('/staff/residents', [StaffManagementController::class, 'searchResidents'])->name('staff.residents.search');
        Route::post('/staff', [StaffManagementController::class, 'store'])->name('staff.store');
        Route::put('/staff/{staff_assignment}', [StaffManagementController::class, 'update'])->name('staff.update');
        Route::patch('/staff/{staff_assignment}/deactivate', [StaffManagementController::class, 'deactivate'])->name('staff.deactivate');

        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log');
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::get('/population', [PopulationController::class, 'index'])->name('population');

        // Organizational Chart Management
        Route::get('/org-chart', [AdminOrgChartController::class, 'index'])->name('org-chart.index');
        Route::post('/org-chart', [AdminOrgChartController::class, 'store'])->name('org-chart.store');
        Route::put('/org-chart/{position}', [AdminOrgChartController::class, 'update'])->name('org-chart.update');
        Route::post('/org-chart/{position}/publish', [AdminOrgChartController::class, 'publish'])->name('org-chart.publish');
        Route::post('/org-chart/{position}/archive', [AdminOrgChartController::class, 'archive'])->name('org-chart.archive');
        Route::delete('/org-chart/{position}', [AdminOrgChartController::class, 'destroy'])->name('org-chart.destroy');
        Route::get('/org-chart/{position}/photo', [AdminOrgChartController::class, 'photo'])->name('org-chart.photo');

        // Emergency Numbers Management
        Route::get('/emergency-numbers', [AdminEmergencyContactController::class, 'index'])->name('emergency-numbers.index');
        Route::post('/emergency-numbers', [AdminEmergencyContactController::class, 'store'])->name('emergency-numbers.store');
        Route::put('/emergency-numbers/{emergencyContact}', [AdminEmergencyContactController::class, 'update'])->name('emergency-numbers.update');
        Route::delete('/emergency-numbers/{emergencyContact}', [AdminEmergencyContactController::class, 'destroy'])->name('emergency-numbers.destroy');

        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
        Route::get('/settings/export/residents', [AdminSettingsController::class, 'exportResidents'])->name('settings.export.residents');
        Route::get('/settings/export/staff', [AdminSettingsController::class, 'exportStaff'])->name('settings.export.staff');
        Route::get('/settings/export/audit-log', [AdminSettingsController::class, 'exportAuditLog'])->name('settings.export.audit-log');
        Route::get('/settings/export/backup', [AdminSettingsController::class, 'exportData'])->name('settings.export.data');
    });

    /*
    |--------------------------------------------------------------------------
    | General Clerk Routes
    |--------------------------------------------------------------------------
    |
    | Clerk-only. Administrators have their own aggregate monitoring routes
    | under /admin and must never reach a Clerk workspace, and Clerks are
    | denied every /admin route by the same role middleware.
    |
    */
    Route::middleware('role:clerk')->prefix('clerk')->name('clerk.')->group(function () {

        Route::get('/dashboard', [ClerkController::class, 'dashboard'])->name('dashboard');

        // Certificate request processing is Clerk work. Administrators have
        // aggregate monitoring only at /admin/certificates.
        Route::get('/certificates', [ClerkCertificateRequestController::class, 'index'])->name('certificates.page');
        Route::get('/certificates/requests/{certificateRequest}', [ClerkCertificateRequestController::class, 'show'])->name('certificates.requests.show');
        Route::get('/certificates/issuances/{issuance}', [ClerkCertificateRequestController::class, 'showIssuance'])->name('certificates.issuances.show');
        Route::get('/certificates/issuances/{issuance}/reprint', [CertificateController::class, 'reprint'])->name('certificates.issuances.reprint');
        Route::patch('/certificates/requests/{certificateRequest}', [ClerkCertificateRequestController::class, 'updateDetails'])->name('certificates.requests.update');
        Route::patch('/certificates/requests/{certificateRequest}/status', [ClerkCertificateRequestController::class, 'updateStatus'])->name('certificates.requests.status');
        Route::post('/certificates/requests/{certificateRequest}/confirm-payment', [ClerkCertificateRequestController::class, 'confirmPayment'])->name('certificates.requests.confirm-payment');
        Route::post('/certificates/requests/{certificateRequest}/release', [ClerkCertificateRequestController::class, 'confirmRelease'])->name('certificates.requests.release');
        Route::get('/certificates/control-number', [CertificateController::class, 'nextControlNumber'])->name('certificates.control-number');
        Route::post('/generate-certificate', [CertificateController::class, 'generate'])->name('certificates.generate');
        Route::post('/certificates/preview', [CertificateController::class, 'preview'])->name('certificates.preview');

        // Certificate form support data: the resident values the Clerk needs
        // to fill the Delayed PSA Registration form for a linked resident.
        Route::get('/certificates/resident-details/{resident}', [ClerkCertificateController::class, 'residentDetails'])->name('certificates.resident-details');

        // Disbursement Documents
        Route::get('/disbursements', [ClerkDisbursementDocumentController::class, 'index'])->name('disbursements.index');
        Route::post('/disbursements', [ClerkDisbursementDocumentController::class, 'store'])->name('disbursements.store');
        Route::get('/disbursements/{disbursementDocument}', [ClerkDisbursementDocumentController::class, 'show'])->name('disbursements.show');
        Route::get('/disbursements/{disbursementDocument}/file', [ClerkDisbursementDocumentController::class, 'file'])->name('disbursements.file');
        Route::get('/disbursements/{disbursementDocument}/download', [ClerkDisbursementDocumentController::class, 'download'])->name('disbursements.download');
        Route::post('/disbursements/{disbursementDocument}/publish', [ClerkDisbursementDocumentController::class, 'publish'])->name('disbursements.publish');
        Route::post('/disbursements/{disbursementDocument}/archive', [ClerkDisbursementDocumentController::class, 'archive'])->name('disbursements.archive');

        // Citizen's Charter Management
        Route::get('/citizen-charter', [ClerkCitizenCharterController::class, 'index'])->name('citizen-charter.index');
        Route::post('/citizen-charter', [ClerkCitizenCharterController::class, 'store'])->name('citizen-charter.store');
        Route::get('/citizen-charter/{service}', [ClerkCitizenCharterController::class, 'show'])->name('citizen-charter.show');
        Route::put('/citizen-charter/{service}', [ClerkCitizenCharterController::class, 'update'])->name('citizen-charter.update');
        Route::post('/citizen-charter/{service}/versions', [ClerkCitizenCharterController::class, 'storeVersion'])->name('citizen-charter.versions.store');
        Route::post('/citizen-charter/{service}/publish', [ClerkCitizenCharterController::class, 'publish'])->name('citizen-charter.publish');
        Route::post('/citizen-charter/{service}/archive', [ClerkCitizenCharterController::class, 'archive'])->name('citizen-charter.archive');
        Route::get('/citizen-charter/{service}/versions/{version}/file', [ClerkCitizenCharterController::class, 'file'])->name('citizen-charter.versions.file');
        Route::get('/citizen-charter/{service}/versions/{version}/download', [ClerkCitizenCharterController::class, 'download'])->name('citizen-charter.versions.download');

        // Organizational Chart Management
        Route::get('/org-chart', [ClerkOrgChartController::class, 'index'])->name('org-chart.index');
        Route::post('/org-chart', [ClerkOrgChartController::class, 'store'])->name('org-chart.store');
        Route::put('/org-chart/{position}', [ClerkOrgChartController::class, 'update'])->name('org-chart.update');
        Route::post('/org-chart/{position}/publish', [ClerkOrgChartController::class, 'publish'])->name('org-chart.publish');
        Route::post('/org-chart/{position}/archive', [ClerkOrgChartController::class, 'archive'])->name('org-chart.archive');
        Route::delete('/org-chart/{position}', [ClerkOrgChartController::class, 'destroy'])->name('org-chart.destroy');
        Route::get('/org-chart/{position}/photo', [ClerkOrgChartController::class, 'photo'])->name('org-chart.photo');

        // General Clerk Announcements & Attendance
        Route::get('/announcements', [ClerkAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [ClerkAnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements', [ClerkAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [ClerkAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [ClerkAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::post('/announcements/{announcement}/complete', [ClerkAnnouncementController::class, 'markDone'])->name('announcements.complete');
        Route::get('/announcements/{announcement}/attendance', [ClerkAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [ClerkAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');

        Route::get('/updates', [ClerkAnnouncementController::class, 'index'])->name('updates.index')->defaults('kind', 'update');
        Route::get('/updates/{announcement}', [ClerkAnnouncementController::class, 'show'])->name('updates.show')->defaults('kind', 'update');
        Route::post('/updates', [ClerkAnnouncementController::class, 'store'])->name('updates.store')->defaults('kind', 'update');
        Route::put('/updates/{announcement}', [ClerkAnnouncementController::class, 'update'])->name('updates.update')->defaults('kind', 'update');
        Route::delete('/updates/{announcement}', [ClerkAnnouncementController::class, 'destroy'])->name('updates.destroy')->defaults('kind', 'update');
        Route::post('/updates/{announcement}/complete', [ClerkAnnouncementController::class, 'markUpdateDone'])->name('updates.complete');

        Route::get('/programs', [ClerkAnnouncementController::class, 'index'])->name('programs.index')->defaults('kind', 'program');
        Route::get('/programs/{announcement}', [ClerkAnnouncementController::class, 'show'])->name('programs.show')->defaults('kind', 'program');
        Route::post('/programs', [ClerkAnnouncementController::class, 'store'])->name('programs.store')->defaults('kind', 'program');
        Route::put('/programs/{announcement}', [ClerkAnnouncementController::class, 'update'])->name('programs.update')->defaults('kind', 'program');
        Route::delete('/programs/{announcement}', [ClerkAnnouncementController::class, 'destroy'])->name('programs.destroy')->defaults('kind', 'program');
    });

    /*
    |--------------------------------------------------------------------------
    | Senior Citizens Desk Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:senior,senior_staff,admin')->prefix('senior')->name('senior.')->group(function () {

        Route::get('/dashboard', [SeniorController::class, 'dashboard'])->name('dashboard');

        // Senior Citizen Applications & Directory
        Route::get('/residents', [SeniorController::class, 'residents'])->name('residents.index');
        Route::get('/applications', [SeniorController::class, 'applications'])->name('applications.index');
        Route::get('/applications/create', [SeniorController::class, 'createApplication'])->name('applications.create');
        Route::post('/applications', [SeniorController::class, 'storeApplication'])->name('applications.store');
        Route::get('/applications/{application}', [SeniorController::class, 'showApplication'])->name('applications.show');
        Route::post('/applications/{application}/approve', [SeniorController::class, 'approveApplication'])->name('applications.approve');
        Route::post('/applications/{application}/reject', [SeniorController::class, 'rejectApplication'])->name('applications.reject');
        Route::post('/applications/{application}/correction', [SeniorController::class, 'requestCorrection'])->name('applications.correction');

        // Senior Desk Announcements & Attendance
        Route::get('/announcements', [SeniorAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [SeniorAnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements', [SeniorAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [SeniorAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [SeniorAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::post('/announcements/{announcement}/complete', [SeniorAnnouncementController::class, 'markDone'])->name('announcements.complete');
        Route::get('/announcements/{announcement}/attendance', [SeniorAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [SeniorAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');

        Route::get('/updates', [SeniorAnnouncementController::class, 'index'])->name('updates.index')->defaults('kind', 'update');
        Route::get('/updates/{announcement}', [SeniorAnnouncementController::class, 'show'])->name('updates.show')->defaults('kind', 'update');
        Route::post('/updates', [SeniorAnnouncementController::class, 'store'])->name('updates.store')->defaults('kind', 'update');
        Route::put('/updates/{announcement}', [SeniorAnnouncementController::class, 'update'])->name('updates.update')->defaults('kind', 'update');
        Route::delete('/updates/{announcement}', [SeniorAnnouncementController::class, 'destroy'])->name('updates.destroy')->defaults('kind', 'update');
        Route::post('/updates/{announcement}/complete', [SeniorAnnouncementController::class, 'markUpdateDone'])->name('updates.complete');

        Route::get('/programs', [SeniorAnnouncementController::class, 'index'])->name('programs.index')->defaults('kind', 'program');
        Route::get('/programs/{announcement}', [SeniorAnnouncementController::class, 'show'])->name('programs.show')->defaults('kind', 'program');
        Route::post('/programs', [SeniorAnnouncementController::class, 'store'])->name('programs.store')->defaults('kind', 'program');
        Route::put('/programs/{announcement}', [SeniorAnnouncementController::class, 'update'])->name('programs.update')->defaults('kind', 'program');
        Route::delete('/programs/{announcement}', [SeniorAnnouncementController::class, 'destroy'])->name('programs.destroy')->defaults('kind', 'program');
    });

    /*
    |--------------------------------------------------------------------------
    | PWD Sector Desk Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:pwd,pwd_staff,admin')->prefix('pwd')->name('pwd.')->group(function () {

        Route::get('/dashboard', [PwdController::class, 'dashboard'])->name('dashboard');

        // PWD Applications & Directory
        Route::get('/residents', [PwdController::class, 'residents'])->name('residents.index');
        Route::get('/applications', [PwdController::class, 'applications'])->name('applications.index');
        Route::get('/applications/create', [PwdController::class, 'createApplication'])->name('applications.create');
        Route::post('/applications', [PwdController::class, 'storeApplication'])->name('applications.store');
        Route::get('/applications/{application}', [PwdController::class, 'showApplication'])->name('applications.show');
        Route::post('/applications/{application}/approve', [PwdController::class, 'approveApplication'])->name('applications.approve');
        Route::post('/applications/{application}/reject', [PwdController::class, 'rejectApplication'])->name('applications.reject');
        Route::post('/applications/{application}/correction', [PwdController::class, 'requestCorrection'])->name('applications.correction');

        // PWD Desk Announcements & Attendance
        Route::get('/announcements', [PwdAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [PwdAnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements', [PwdAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [PwdAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [PwdAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::post('/announcements/{announcement}/complete', [PwdAnnouncementController::class, 'markDone'])->name('announcements.complete');
        Route::get('/announcements/{announcement}/attendance', [PwdAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [PwdAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');

        Route::get('/updates', [PwdAnnouncementController::class, 'index'])->name('updates.index')->defaults('kind', 'update');
        Route::get('/updates/{announcement}', [PwdAnnouncementController::class, 'show'])->name('updates.show')->defaults('kind', 'update');
        Route::post('/updates', [PwdAnnouncementController::class, 'store'])->name('updates.store')->defaults('kind', 'update');
        Route::put('/updates/{announcement}', [PwdAnnouncementController::class, 'update'])->name('updates.update')->defaults('kind', 'update');
        Route::delete('/updates/{announcement}', [PwdAnnouncementController::class, 'destroy'])->name('updates.destroy')->defaults('kind', 'update');
        Route::post('/updates/{announcement}/complete', [PwdAnnouncementController::class, 'markUpdateDone'])->name('updates.complete');

        Route::get('/programs', [PwdAnnouncementController::class, 'index'])->name('programs.index')->defaults('kind', 'program');
        Route::get('/programs/{announcement}', [PwdAnnouncementController::class, 'show'])->name('programs.show')->defaults('kind', 'program');
        Route::post('/programs', [PwdAnnouncementController::class, 'store'])->name('programs.store')->defaults('kind', 'program');
        Route::put('/programs/{announcement}', [PwdAnnouncementController::class, 'update'])->name('programs.update')->defaults('kind', 'program');
        Route::delete('/programs/{announcement}', [PwdAnnouncementController::class, 'destroy'])->name('programs.destroy')->defaults('kind', 'program');
    });

    /*
    |--------------------------------------------------------------------------
    | Lupon Desk Routes
    |--------------------------------------------------------------------------
    |
    | Lupon staff only. The `lupon` role is deliberately the sole entry:
    | no other staff role (including admin) may open this portal, and the
    | dormant `super_admin` role stays unauthorized.
    |
    */
    Route::middleware('role:lupon')->prefix('lupon')->name('lupon.')->group(function () {

        Route::get('/dashboard', [LuponController::class, 'dashboard'])->name('dashboard');

        // Complaint intake queue and review. Lupon staff only; no admin or
        // aggregate access is exposed in this batch.
        Route::get('/complaints', [LuponComplaintController::class, 'index'])->name('complaints.index');
        Route::patch('/complaints/{complaint}/status', [LuponComplaintController::class, 'updateStatus'])->name('complaints.status');
        Route::post('/complaints/{complaint}/verify-fee', [LuponComplaintController::class, 'verifyFee'])->name('complaints.verify-fee');
        Route::post('/complaints/{complaint}/request-payment', [LuponComplaintController::class, 'requestPayment'])->name('complaints.request-payment');
        Route::post('/complaints/{complaint}/promote', [LuponCaseController::class, 'promote'])->name('complaints.promote');

        Route::get('/residents/search', \App\Http\Controllers\ResidentSearchController::class)->middleware('throttle:120,1')->name('residents.search');
        Route::get('/residents/{resident}/lookup', [LuponCaseController::class, 'residentLookup'])->middleware('throttle:120,1')->name('residents.lookup');
        Route::get('/evidence/{evidence}', [\App\Http\Controllers\Lupon\LuponEvidenceController::class, 'download'])->name('evidence.download');
        Route::patch('/evidence/{evidence}/visibility', [\App\Http\Controllers\Lupon\LuponEvidenceController::class, 'share'])->name('evidence.visibility');
        Route::post('/cases/{case}/evidence', [\App\Http\Controllers\Lupon\LuponEvidenceController::class, 'store'])->name('evidence.store');
        Route::post('/cases/{case}/documents', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'generate'])->name('documents.generate');
        Route::get('/documents/{document}', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'download'])->name('documents.download');
        Route::get('/documents/{document}/preview', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'download'])->name('documents.preview');
        Route::post('/documents/{document}/serve', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'serve'])->name('documents.serve');
        Route::post('/cases/{case}/verify-fee', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'verifyFee'])->name('cases.verify-fee');
        Route::post('/cases/{case}/history', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'history'])->name('cases.history');
        Route::post('/cases/{case}/extend-conciliation', [\App\Http\Controllers\Lupon\LuponDocumentController::class, 'extend'])->name('cases.extend');
        // Lupon Case Management
        Route::get('/cases', [LuponCaseController::class, 'index'])->name('cases.index');
        Route::get('/cases/create', [LuponCaseController::class, 'create'])->name('cases.create');
        Route::get('/cases/qr-lookup', [LuponCaseController::class, 'qrLookup'])->middleware('throttle:120,1')->name('cases.qr-lookup');
        Route::post('/cases', [LuponCaseController::class, 'store'])->name('cases.store');
        Route::get('/hearings', [LuponHearingController::class, 'index'])->name('hearings.index');
        Route::get('/cases/{case}', [LuponCaseController::class, 'show'])->name('cases.show');
        Route::patch('/cases/{case}/status', [LuponCaseController::class, 'updateStatus'])->name('cases.status');
        Route::post('/cases/{case}/complainants', [LuponCaseController::class, 'addComplainant'])->name('cases.complainants.store');
        Route::post('/parties/{party}/associate-respondent', [LuponCaseController::class, 'associateRespondent'])->name('parties.associate-respondent');

        // Hearings
        Route::post('/cases/{case}/hearings', [LuponHearingController::class, 'store'])->name('cases.hearings.store');
        Route::patch('/hearings/{hearing}', [LuponHearingController::class, 'update'])->name('hearings.update');

        // Settlements
        Route::post('/cases/{case}/settlements', [LuponSettlementController::class, 'store'])->name('cases.settlements.store');
    });
});
});
