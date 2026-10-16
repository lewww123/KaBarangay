<?php

use App\Http\Controllers\Admin\AnnouncementApprovalController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ResidentRegistrationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\Clerk\AnnouncementController as ClerkAnnouncementController;
use App\Http\Controllers\ClerkController;
use App\Http\Controllers\Pwd\AnnouncementController as PwdAnnouncementController;
use App\Http\Controllers\PwdController;
use App\Http\Controllers\Resident\AnnouncementController as ResidentAnnouncementController;
use App\Http\Controllers\Resident\CertificateRequestController;
use App\Http\Controllers\Resident\QrVerificationController;
use App\Http\Controllers\Resident\RegistrationController;
use App\Http\Controllers\Senior\AnnouncementController as SeniorAnnouncementController;
use App\Http\Controllers\SeniorController;
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
Route::post('/department-login', [AuthController::class, 'processDepartmentLogin'])->name('department.login.store');

Route::get('/staff-login', [AuthController::class, 'staffLogin'])->name('staff.login');
Route::post('/staff-login', [AuthController::class, 'processStaffLogin'])->name('staff.login.store');

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

Route::get('/verify/resident/{token}', [QrVerificationController::class, 'show'])->name('resident.qr.verify');

/*
|--------------------------------------------------------------------------
| Resident Authentication & Public Pages
|--------------------------------------------------------------------------
*/

Route::prefix('resident')->name('resident.')->group(function () {

    Route::inertia('/login', 'Resident/Login')->name('login');
    Route::post('/login', [AuthController::class, 'processResidentLogin'])->name('login.process');
    Route::get('/login/barangays', [AuthController::class, 'residentLoginBarangays'])->name('login.barangays');

    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
    Route::get('/register/submitted/{publicId}', [RegistrationController::class, 'submitted'])->name('registration.submitted');

    Route::inertia('/forgot-password', 'Resident/ForgotPassword')->name('forgot-password');

    Route::middleware('resident.auth')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Resident Dashboard
        |--------------------------------------------------------------------------
        */
        Route::get('/', function () {
            $resident = \App\Models\Resident::query()
                ->with(['barangay', 'sectors', 'qrCredential'])
                ->where('id', session('resident_id'))
                ->where('status', 'active')
                ->firstOrFail();

            $qrUrl = $resident->qrCredential && $resident->qrCredential->is_active && ! $resident->qrCredential->revoked_at
                ? route('resident.qr.verify', ['token' => $resident->qrCredential->token])
                : null;

            $sectorSlugs = $resident->sectors->pluck('slug')->toArray();

            $latestAnnouncement = \App\Models\Announcement::query()
                ->where('approval_status', 'approved')
                ->where('is_published', true)
                ->where(function ($query) use ($sectorSlugs, $resident) {
                    $query->where('target_audience', 'all');
                    if (in_array('senior-citizen', $sectorSlugs) || ($resident && $resident->is_senior)) {
                        $query->orWhere('target_audience', 'senior_citizens');
                    }
                    if (in_array('pwd', $sectorSlugs) || ($resident && $resident->is_pwd)) {
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
        Route::post('/certificates', [CertificateRequestController::class, 'store'])->name('certificates.store');

        Route::inertia('/lupon', 'Resident/Lupon')->name('lupon');

        Route::get('/announcements', [ResidentAnnouncementController::class, 'index'])->name('announcements');

        Route::inertia('/disbursement', 'Resident/Disbursement')->name('disbursement');
        Route::inertia('/citizen-charter', 'Resident/CitizenCharter')->name('citizen-charter');
        Route::inertia('/chatbot', 'Resident/Chatbot')->name('chatbot');

        /*
        |--------------------------------------------------------------------------
        | Resident Profile
        |--------------------------------------------------------------------------
        */
        Route::get('/profile', function () {
            $resident = \App\Models\Resident::query()
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
            $resident = \App\Models\Resident::query()
                ->where('id', session('resident_id'))
                ->where('status', 'active')
                ->firstOrFail();

            $validated = $request->validate([
                'mobileNumber' => [
                    'nullable',
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
            ]);

            $resident->update([
                'mobile' => $validated['mobileNumber'] ?? null,
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
    Route::get('/api/qr/verify-token', function (Request $request) {
            $token = $request->query('token');

            if (! $token) {
                return response()->json([
                    'message' => 'QR token is missing.',
                ], 400);
            }

            // Hanapin muna kung ito ay valid resident number o kaya ay token sa qr_credentials
            $resident = \App\Models\Resident::query()
                ->where('resident_number', strtoupper($token))
                ->orWhereHas('qrCredential', function ($query) use ($token) {
                    $query->where('token', $token)
                        ->where('is_active', true)
                        ->whereNull('revoked_at');
                })
                ->first();

            if (! $resident || $resident->status !== 'active') {
                return response()->json([
                    'message' => 'Resident not found or inactive.',
                ], 404);
            }

            return response()->json([
                'resident' => [
                    'id' => $resident->id,
                    'full_name' => $resident->full_name,
                    'resident_number' => $resident->resident_number,
                ],
            ]);
        });

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->name('admin.')->group(function () {

        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // Resident QR Scanner
        Route::inertia('/qr-scanner', 'Admin/QrScanner')->name('qr-scanner');

        // Resident Registrations
        Route::get('/residents', [ResidentRegistrationController::class, 'index'])->name('residents');
        Route::get('/residents/{registration}/documents/{document}', [ResidentRegistrationController::class, 'showDocument'])->name('residents.documents.show');
        Route::post('/residents/{registration}/approve', [ResidentRegistrationController::class, 'approve'])->name('residents.approve');
        Route::post('/residents/{registration}/reject', [ResidentRegistrationController::class, 'reject'])->name('residents.reject');

        // Announcement Approvals (Admin Workflow)
        Route::get('/announcements/approvals', [AnnouncementApprovalController::class, 'index'])->name('announcements.approvals');
        Route::post('/announcements/{announcement}/approve', [AnnouncementApprovalController::class, 'approve'])->name('announcements.approve');
        Route::post('/announcements/{announcement}/reject', [AnnouncementApprovalController::class, 'reject'])->name('announcements.reject');

        Route::inertia('/sectors', 'Admin/Sectors')->name('sectors');
        Route::inertia('/certificates', 'Admin/Certificates')->name('certificates');
        Route::inertia('/staff', 'Admin/Staff')->name('staff');
        Route::inertia('/audit-log', 'Admin/AuditLog')->name('audit-log');
        Route::inertia('/reports', 'Admin/Reports')->name('reports');
        Route::inertia('/population', 'Admin/Population')->name('population');
        Route::inertia('/settings', 'Admin/Settings')->name('settings');
    });

    /*
    |--------------------------------------------------------------------------
    | General Clerk Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('clerk')->name('clerk.')->group(function () {

        Route::get('/dashboard', [ClerkController::class, 'dashboard'])->name('dashboard');
        Route::inertia('/certificates', 'Clerk/Certificates')->name('certificates.page');
        Route::post('/generate-certificate', [CertificateController::class, 'generate'])->name('certificates.generate');

        // General Clerk Announcements & Attendance
        Route::get('/announcements', [ClerkAnnouncementController::class, 'index'])->name('announcements.index');
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
    Route::prefix('senior')->name('senior.')->group(function () {

        Route::get('/dashboard', [SeniorController::class, 'dashboard'])->name('dashboard');

        // Senior Desk Announcements & Attendance
        Route::get('/announcements', [SeniorAnnouncementController::class, 'index'])->name('announcements.index');
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
    Route::prefix('pwd')->name('pwd.')->group(function () {

        Route::get('/dashboard', [PwdController::class, 'dashboard'])->name('dashboard');

        // PWD Desk Announcements & Attendance
        Route::get('/announcements', [PwdAnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('/announcements', [PwdAnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [PwdAnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [PwdAnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::get('/announcements/{announcement}/attendance', [PwdAnnouncementController::class, 'getAttendanceSheet'])->name('announcements.attendance');
        Route::post('/announcements/{announcement}/attendance/mark', [PwdAnnouncementController::class, 'markAttendance'])->name('announcements.attendance.mark');
    });
});