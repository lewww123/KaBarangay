<?php

use App\Http\Controllers\Admin\AnnouncementApprovalController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CertificateRequestController as AdminCertificateRequestController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PopulationController;
use App\Http\Controllers\Admin\ResidentRegistrationController;
use App\Http\Controllers\Admin\SectorController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\StaffManagementController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\Clerk\AnnouncementController as ClerkAnnouncementController;
use App\Http\Controllers\Clerk\CertificateController as ClerkCertificateController;
use App\Http\Controllers\Clerk\CertificateRequestController as ClerkCertificateRequestController;
use App\Http\Controllers\ClerkController;
use App\Http\Controllers\Lupon\LuponComplaintController;
use App\Http\Controllers\LuponController;
use App\Http\Controllers\Pwd\AnnouncementController as PwdAnnouncementController;
use App\Http\Controllers\PwdController;
use App\Http\Controllers\Resident\AnnouncementController as ResidentAnnouncementController;
use App\Http\Controllers\Resident\CertificateRequestController;
use App\Http\Controllers\Resident\LuponComplaintController as ResidentLuponComplaintController;
use App\Http\Controllers\Resident\PasswordResetController;
use App\Http\Controllers\Resident\QrVerificationController;
use App\Http\Controllers\Resident\RegistrationController;
use App\Http\Controllers\Senior\AnnouncementController as SeniorAnnouncementController;
use App\Http\Controllers\SeniorController;
use App\Models\Announcement;
use App\Models\Resident;
use App\Support\QrAttendanceProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Barangay Selection
|--------------------------------------------------------------------------
*/

Route::get('/', [AuthController::class, 'selectBarangay'])->name('barangay.select');
Route::post('/select-barangay', [AuthController::class, 'storeBarangay'])->name('barangay.store');

/*
|--------------------------------------------------------------------------
| Department & Staff Login
|--------------------------------------------------------------------------
*/

Route::get('/department-login', [AuthController::class, 'departmentLogin'])->name('department.login');
Route::post('/department-login', [AuthController::class, 'processDepartmentLogin'])->middleware('throttle:login-department')->name('department.login.store');

Route::get('/staff-login', [AuthController::class, 'staffLogin'])->name('staff.login');
Route::post('/staff-login', [AuthController::class, 'processStaffLogin'])->middleware('throttle:login-staff')->name('staff.login.store');

/*
|--------------------------------------------------------------------------
| Laravel Auth Login Redirect
|--------------------------------------------------------------------------
*/

Route::redirect('/login', '/staff-login')->name('login');

/*
|--------------------------------------------------------------------------
| Public Resident QR Verification
|--------------------------------------------------------------------------
*/

Route::get('/verify/resident/{token}', [QrVerificationController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('resident.qr.verify');

/*
|--------------------------------------------------------------------------
| Resident Authentication & Public Pages
|--------------------------------------------------------------------------
*/

Route::prefix('resident')->name('resident.')->group(function () {

    Route::inertia('/login', 'Resident/Login')->name('login');
    Route::post('/login', [AuthController::class, 'processResidentLogin'])->middleware('throttle:login-resident')->name('login.process');
    Route::get('/login/barangays', [AuthController::class, 'residentLoginBarangays'])->name('login.barangays');

    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
    Route::get('/register/submitted/{publicId}', [RegistrationController::class, 'submitted'])->name('registration.submitted');

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
            $resident = Resident::query()
                ->with(['barangay', 'sectors', 'qrCredential'])
                ->where('id', session('resident_id'))
                ->where('status', 'active')
                ->firstOrFail();

            $qrUrl = $resident->qrCredential && $resident->qrCredential->is_active && ! $resident->qrCredential->revoked_at
                ? route('resident.qr.verify', ['token' => $resident->qrCredential->token])
                : null;

            $sectorSlugs = $resident->sectors->pluck('slug')->toArray();

            $latestAnnouncement = Announcement::query()
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

            return Inertia::render('Resident/Dashboard', [
                'resident' => [
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
                ],
                'qrUrl' => $qrUrl,
                'latestAnnouncement' => $latestAnnouncement ? [
                    'id' => $latestAnnouncement->id,
                    'title' => $latestAnnouncement->title,
                    'created_at' => $latestAnnouncement->created_at->format('F j, Y'),
                ] : null,
            ]);
        })->name('dashboard');

        Route::get('/certificates', [CertificateRequestController::class, 'index'])->name('certificates');
        Route::post('/certificates', [CertificateRequestController::class, 'store'])->middleware('throttle:10,1')->name('certificates.store');

        Route::get('/lupon', [ResidentLuponComplaintController::class, 'index'])->name('lupon');
        Route::post('/lupon/complaints', [ResidentLuponComplaintController::class, 'store'])->name('lupon.complaints.store');

        Route::get('/announcements', [ResidentAnnouncementController::class, 'index'])->name('announcements');
        Route::get('/announcements/{announcement}', [ResidentAnnouncementController::class, 'show'])->name('announcements.show');

        Route::inertia('/disbursement', 'Resident/Disbursement')->name('disbursement');
        Route::inertia('/citizen-charter', 'Resident/CitizenCharter')->name('citizen-charter');
        Route::inertia('/chatbot', 'Resident/Chatbot')->name('chatbot');

        /*
        |--------------------------------------------------------------------------
        | Resident Profile
        |--------------------------------------------------------------------------
        */
        Route::get('/profile', function () {
            $resident = Resident::query()
                ->with(['barangay', 'purok', 'account', 'sectors', 'qrCredential'])
                ->where('id', session('resident_id'))
                ->where('status', 'active')
                ->firstOrFail();

            $birthdate = $resident->birthdate;
            $age = $birthdate ? $birthdate->age : 0;
            $qrCredential = $resident->qrCredential;

            return Inertia::render('Resident/Profile', [
                'profile' => [
                    'fullName' => $resident->full_name,
                    'residentId' => $resident->resident_number,
                    'birthdate' => $birthdate ? $birthdate->format('F j, Y') : 'Not provided',
                    'age' => $age,
                    'sex' => $resident->sex ?? 'Not provided',
                    'civilStatus' => $resident->civil_status ?? 'Not provided',
                    'mobileNumber' => $resident->mobile ?? '',
                    'email' => $resident->email ?? '',
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
                ],
            ]);
        })->name('profile');

        /*
        |--------------------------------------------------------------------------
        | Resident Profile Contact Update
        |--------------------------------------------------------------------------
        */
        Route::post('/profile/contact', function (Request $request) {
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

            $resident->update([
                'mobile' => $validated['mobileNumber'],
                'email' => $validated['email'] ?? null,
            ]);

            return back()->with('success', 'Contact information updated successfully.');
        })->name('profile.contact.update');

        Route::inertia('/settings', 'Resident/Settings')->name('settings');
        Route::inertia('/about', 'Resident/About')->name('about');
    });
});

/*
|--------------------------------------------------------------------------
| Admin & Staff Authentication Group
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

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

        return response()->json([
            'resident' => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'resident_number' => $resident->resident_number,
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

        // Resident QR Scanner
        Route::inertia('/qr-scanner', 'Admin/QrScanner')->name('qr-scanner');

        // Resident Registrations
        Route::get('/residents', [ResidentRegistrationController::class, 'index'])->name('residents');
        Route::get('/residents/{resident}/details', [ResidentRegistrationController::class, 'showResidentDetails'])->name('residents.details');
        Route::get('/resident-registrations/{registration}/details', [ResidentRegistrationController::class, 'showRegistrationDetails'])->name('residents.registrations.details');
        Route::get('/residents/{registration}/documents/{document}', [ResidentRegistrationController::class, 'showDocument'])->name('residents.documents.show');
        Route::post('/residents/{registration}/approve', [ResidentRegistrationController::class, 'approve'])->name('residents.approve');
        Route::post('/residents/{registration}/reject', [ResidentRegistrationController::class, 'reject'])->name('residents.reject');
        Route::patch('/residents/{resident}/status', [ResidentRegistrationController::class, 'updateResidentStatus'])->name('residents.status');
        Route::patch('/residents/{resident}/account-status', [ResidentRegistrationController::class, 'updateAccountStatus'])->name('residents.account-status');
        Route::post('/residents/{resident}/qr/revoke', [ResidentRegistrationController::class, 'revokeQrCredential'])->name('residents.qr.revoke');
        Route::post('/residents/{resident}/qr/reissue', [ResidentRegistrationController::class, 'reissueQrCredential'])->name('residents.qr.reissue');

        // Certificate Requests (Admin Tracking & Review)
        Route::get('/certificates', [AdminCertificateRequestController::class, 'index'])->name('certificates');
        Route::put('/certificates/{certificateRequest}/status', [AdminCertificateRequestController::class, 'updateStatus'])->name('certificates.status');

        // Announcement Approvals (Admin Workflow)
        Route::get('/announcements/approvals', [AnnouncementApprovalController::class, 'index'])->name('announcements.approvals');
        Route::post('/announcements/{announcement}/approve', [AnnouncementApprovalController::class, 'approve'])->name('announcements.approve');
        Route::post('/announcements/{announcement}/reject', [AnnouncementApprovalController::class, 'reject'])->name('announcements.reject');

        Route::get('/sectors', [SectorController::class, 'index'])->name('sectors');
        Route::post('/sectors', [SectorController::class, 'store'])->name('sectors.store');
        Route::put('/sectors/{sector}', [SectorController::class, 'update'])->name('sectors.update');

        // Staff Management
        Route::get('/staff', [StaffManagementController::class, 'index'])->name('staff');
        Route::post('/staff', [StaffManagementController::class, 'store'])->name('staff.store');
        Route::put('/staff/{user}', [StaffManagementController::class, 'update'])->name('staff.update');
        Route::patch('/staff/{user}/status', [StaffManagementController::class, 'updateStatus'])->name('staff.status');

        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log');
        Route::inertia('/reports', 'Admin/Reports')->name('reports');
        Route::get('/population', [PopulationController::class, 'index'])->name('population');

        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
        Route::get('/settings/export/residents', [AdminSettingsController::class, 'exportResidents'])->name('settings.export.residents');
        Route::get('/settings/export/staff', [AdminSettingsController::class, 'exportStaff'])->name('settings.export.staff');
        Route::get('/settings/export/backup', [AdminSettingsController::class, 'exportData'])->name('settings.export.data');
    });

    /*
    |--------------------------------------------------------------------------
    | General Clerk Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:clerk,admin')->prefix('clerk')->name('clerk.')->group(function () {

        Route::get('/dashboard', [ClerkController::class, 'dashboard'])->name('dashboard');
        Route::get('/certificates', [ClerkCertificateRequestController::class, 'index'])->name('certificates.page');
        Route::post('/generate-certificate', [CertificateController::class, 'generate'])->name('certificates.generate');

        // Certificate form support data: the resident values the Clerk needs
        // to fill the Delayed PSA Registration form for a linked resident.
        Route::get('/certificates/resident-details/{resident}', [ClerkCertificateController::class, 'residentDetails'])->name('certificates.resident-details');

        // General Clerk Announcements & Attendance
        Route::get('/announcements', [ClerkAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [ClerkAnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements', [ClerkAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [ClerkAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [ClerkAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::get('/announcements/{announcement}/attendance', [ClerkAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [ClerkAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');
    });

    /*
    |--------------------------------------------------------------------------
    | Senior Citizens Desk Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:senior,senior_staff,admin')->prefix('senior')->name('senior.')->group(function () {

        Route::get('/dashboard', [SeniorController::class, 'dashboard'])->name('dashboard');

        // Senior Desk Announcements & Attendance
        Route::get('/announcements', [SeniorAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [SeniorAnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements', [SeniorAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [SeniorAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [SeniorAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::get('/announcements/{announcement}/attendance', [SeniorAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [SeniorAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');
    });

    /*
    |--------------------------------------------------------------------------
    | PWD Sector Desk Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:pwd,pwd_staff,admin')->prefix('pwd')->name('pwd.')->group(function () {

        Route::get('/dashboard', [PwdController::class, 'dashboard'])->name('dashboard');

        // PWD Desk Announcements & Attendance
        Route::get('/announcements', [PwdAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/{announcement}', [PwdAnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('/announcements', [PwdAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [PwdAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [PwdAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::get('/announcements/{announcement}/attendance', [PwdAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [PwdAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');
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
    });
});
