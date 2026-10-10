<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Resident\RegistrationController;
use App\Http\Controllers\Resident\QrVerificationController;
use App\Http\Controllers\Admin\ResidentRegistrationController;
use App\Http\Controllers\Resident\CertificateRequestController;
use App\Http\Controllers\ClerkController;
use App\Http\Controllers\CertificateController; // <-- IDINAGDAG NATIN ITO
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Barangay Selection
|--------------------------------------------------------------------------
*/

Route::get(
    '/',
    [AuthController::class, 'selectBarangay']
)->name('barangay.select');

Route::post(
    '/select-barangay',
    [AuthController::class, 'storeBarangay']
)->name('barangay.store');

/*
|--------------------------------------------------------------------------
| Department Login
|--------------------------------------------------------------------------
*/

Route::get(
    '/department-login',
    [AuthController::class, 'departmentLogin']
)->name('department.login');

Route::post(
    '/department-login',
    [AuthController::class, 'processDepartmentLogin']
)->name('department.login.store');

/*
|--------------------------------------------------------------------------
| Staff Login
|--------------------------------------------------------------------------
*/

Route::get(
    '/staff-login',
    [AuthController::class, 'staffLogin']
)->name('staff.login');

Route::post(
    '/staff-login',
    [AuthController::class, 'processStaffLogin']
)->name('staff.login.store');

/*
|--------------------------------------------------------------------------
| Laravel Auth Login Redirect
|--------------------------------------------------------------------------
|
| Laravel's built-in "auth" middleware expects a route named "login".
| Our actual staff login page is /staff-login.
|
*/

Route::redirect(
    '/login',
    '/staff-login'
)->name('login');

/*
|--------------------------------------------------------------------------
| Public Resident QR Verification
|--------------------------------------------------------------------------
|
| This route is intentionally outside the resident.auth middleware.
| Anyone who scans a resident QR should be able to verify it.
|
*/

Route::get(
    '/verify/resident/{token}',
    [
        QrVerificationController::class,
        'show',
    ]
)->name('resident.qr.verify');

/*
|--------------------------------------------------------------------------
| Resident Authentication & Public Pages
|--------------------------------------------------------------------------
*/

Route::prefix('resident')
    ->name('resident.')
    ->group(function () {

        Route::inertia(
            '/login',
            'Resident/Login'
        )->name('login');

        Route::post(
            '/login',
            [
                AuthController::class,
                'processResidentLogin',
            ]
        )->name('login.process');

        Route::get(
            '/login/barangays',
            [
                AuthController::class,
                'residentLoginBarangays',
            ]
        )->name('login.barangays');

        Route::get(
            '/register',
            [
                RegistrationController::class,
                'create',
            ]
        )->name('register');

        Route::post(
            '/register',
            [
                RegistrationController::class,
                'store',
            ]
        )
            ->middleware('throttle:5,1')
            ->name('register.store');

        Route::get(
            '/register/submitted/{publicId}',
            [
                RegistrationController::class,
                'submitted',
            ]
        )->name('registration.submitted');

        Route::inertia(
            '/forgot-password',
            'Resident/ForgotPassword'
        )->name('forgot-password');

        Route::middleware('resident.auth')
            ->group(function () {

                /*
                |--------------------------------------------------------------------------
                | Resident Dashboard
                |--------------------------------------------------------------------------
                */

                Route::get(
                    '/',
                    function () {

                        $resident = \App\Models\Resident::query()
                            ->with([
                                'barangay',
                                'sectors',
                                'qrCredential',
                            ])
                            ->where(
                                'id',
                                session('resident_id')
                            )
                            ->where(
                                'status',
                                'active'
                            )
                            ->firstOrFail();

                        $qrUrl =
                            $resident->qrCredential &&
                            $resident->qrCredential->is_active &&
                            ! $resident->qrCredential->revoked_at
                                ? route(
                                    'resident.qr.verify',
                                    [
                                        'token' =>
                                            $resident
                                                ->qrCredential
                                                ->token,
                                    ]
                                )
                                : null;

                        return Inertia::render(
                            'Resident/Dashboard',
                            [
                                'resident' => [
                                    'id' =>
                                        $resident->id,

                                    'first_name' =>
                                        $resident->first_name,

                                    'middle_name' =>
                                        $resident->middle_name,

                                    'last_name' =>
                                        $resident->last_name,

                                    'suffix' =>
                                        $resident->suffix,

                                    'full_name' =>
                                        $resident->full_name,

                                    'resident_number' =>
                                        $resident->resident_number,

                                    'barangay' => [
                                        'id' =>
                                            $resident->barangay?->id,

                                        'name' =>
                                            $resident->barangay?->name,

                                        'code' =>
                                            $resident->barangay?->code,

                                        'city_municipality' =>
                                            $resident->barangay?->city_municipality,

                                        'province' =>
                                            $resident->barangay?->province,
                                    ],

                                    'sectors' =>
                                        $resident->sectors
                                            ->map(function ($sector) {
                                                return [
                                                    'id' =>
                                                        $sector->id,

                                                    'name' =>
                                                        $sector->name,

                                                    'slug' =>
                                                        $sector->slug,
                                                ];
                                            })
                                            ->values(),
                                ],

                                'qrUrl' =>
                                    $qrUrl,
                            ]
                        );
                    }
                )->name('dashboard');

                // Dito pinalitan ang route para sa Certificates (GET at POST)
                Route::get(
                    '/certificates',
                    [CertificateRequestController::class, 'index']
                )->name('certificates');

                Route::post(
                    '/certificates',
                    [CertificateRequestController::class, 'store']
                )->name('certificates.store');

                Route::inertia(
                    '/lupon',
                    'Resident/Lupon'
                )->name('lupon');

                Route::inertia(
                    '/announcements',
                    'Resident/Announcements'
                )->name('announcements');

                Route::inertia(
                    '/disbursement',
                    'Resident/Disbursement'
                )->name('disbursement');

                Route::inertia(
                    '/citizen-charter',
                    'Resident/CitizenCharter'
                )->name('citizen-charter');

                Route::inertia(
                    '/chatbot',
                    'Resident/Chatbot'
                )->name('chatbot');

                /*
                |--------------------------------------------------------------------------
                | Resident Profile
                |--------------------------------------------------------------------------
                */

                Route::get(
                    '/profile',
                    function () {

                        $resident = \App\Models\Resident::query()
                            ->with([
                                'barangay',
                                'purok',
                                'account',
                                'sectors',
                                'qrCredential',
                            ])
                            ->where(
                                'id',
                                session('resident_id')
                            )
                            ->where(
                                'status',
                                'active'
                            )
                            ->firstOrFail();

                        $birthdate =
                            $resident->birthdate;

                        $age = $birthdate
                            ? $birthdate->age
                            : 0;

                        $qrCredential =
                            $resident->qrCredential;

                        return Inertia::render(
                            'Resident/Profile',
                            [
                                'profile' => [

                                    'fullName' =>
                                        $resident->full_name,

                                    'residentId' =>
                                        $resident->resident_number,

                                    'birthdate' =>
                                        $birthdate
                                            ? $birthdate->format(
                                                'F j, Y'
                                            )
                                            : 'Not provided',

                                    'age' =>
                                        $age,

                                    'sex' =>
                                        $resident->sex
                                            ?? 'Not provided',

                                    'civilStatus' =>
                                        $resident->civil_status
                                            ?? 'Not provided',

                                    'mobileNumber' =>
                                        $resident->mobile
                                            ?? '',

                                    'email' =>
                                        $resident->email
                                            ?? '',

                                    'purok' =>
                                        $resident->purok?->name
                                            ?? 'Not provided',

                                    'barangay' =>
                                        $resident->barangay?->name
                                            ?? 'Not provided',

                                    'city' =>
                                        $resident->barangay?->city_municipality
                                            ?? 'Not provided',

                                    'province' =>
                                        $resident->barangay?->province
                                            ?? 'Not provided',

                                    'accountStatus' =>
                                        (
                                            $resident
                                                ->account
                                                ?->is_active
                                            ?? false
                                        )
                                            ? 'Verified'
                                            : 'Inactive',

                                    'registeredDate' =>
                                        (
                                            $resident->approved_at
                                            ?? $resident->created_at
                                        )->format(
                                            'F j, Y'
                                        ),

                                    'classification' =>
                                        $resident->sectors->isEmpty()
                                            ? 'Regular Resident'
                                            : $resident->sectors
                                                ->pluck('name')
                                                ->implode(
                                                    ' • '
                                                ),

                                    'qrToken' =>
                                        (
                                            $qrCredential?->is_active
                                            && ! $qrCredential?->revoked_at
                                        )
                                            ? $qrCredential->token
                                            : null,
                                ],
                            ]
                        );
                    }
                )->name('profile');

                /*
                |--------------------------------------------------------------------------
                | Resident Profile Contact Update
                |--------------------------------------------------------------------------
                */

                Route::post(
                    '/profile/contact',
                    function (Request $request) {

                        $resident =
                            \App\Models\Resident::query()
                                ->where(
                                    'id',
                                    session('resident_id')
                                )
                                ->where(
                                    'status',
                                    'active'
                                )
                                ->firstOrFail();

                        $validated =
                            $request->validate([
                                'mobileNumber' => [
                                    'nullable',
                                    'string',
                                    'max:20',

                                    Rule::unique(
                                        'residents',
                                        'mobile'
                                    )
                                        ->where(
                                            function ($query) use (
                                                $resident
                                            ) {
                                                return $query->where(
                                                    'barangay_id',
                                                    $resident->barangay_id
                                                );
                                            }
                                        )
                                        ->ignore(
                                            $resident->id
                                        ),
                                ],

                                'email' => [
                                    'nullable',
                                    'email',
                                    'max:255',

                                    Rule::unique(
                                        'residents',
                                        'email'
                                    )
                                        ->where(
                                            function ($query) use (
                                                $resident
                                            ) {
                                                return $query->where(
                                                    'barangay_id',
                                                    $resident->barangay_id
                                                );
                                            }
                                        )
                                        ->ignore(
                                            $resident->id
                                        ),
                                ],
                            ]);

                        $resident->update([
                            'mobile' =>
                                $validated['mobileNumber']
                                ?? null,

                            'email' =>
                                $validated['email']
                                ?? null,
                        ]);

                        return back()->with(
                            'success',
                            'Contact information updated successfully.'
                        );
                    }
                )->name(
                    'profile.contact.update'
                );

                Route::inertia(
                    '/settings',
                    'Resident/Settings'
                )->name('settings');

                Route::inertia(
                    '/about',
                    'Resident/About'
                )->name('about');
            });
    });

/*
|--------------------------------------------------------------------------
| Admin / Staff
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->group(function () {

        Route::post(
            '/logout',
            [
                LoginController::class,
                'destroy',
            ]
        )->name('logout');

        Route::prefix('admin')
            ->name('admin.')
            ->group(function () {

                Route::inertia(
                    '/',
                    'Dashboard'
                )->name('dashboard');

                /*
                |--------------------------------------------------------------------------
                | Resident QR Scanner
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/qr-scanner',
                    'Admin/QrScanner'
                )->name('qr-scanner');

                /*
                |--------------------------------------------------------------------------
                | Resident Registrations
                |--------------------------------------------------------------------------
                */

                Route::get(
                    '/residents',
                    [
                        ResidentRegistrationController::class,
                        'index',
                    ]
                )->name('residents');

                Route::get(
                    '/residents/{registration}/documents/{document}',
                    [
                        ResidentRegistrationController::class,
                        'showDocument',
                    ]
                )->name(
                    'residents.documents.show'
                );

                Route::post(
                    '/residents/{registration}/approve',
                    [
                        ResidentRegistrationController::class,
                        'approve',
                    ]
                )->name(
                    'residents.approve'
                );

                Route::post(
                    '/residents/{registration}/reject',
                    [
                        ResidentRegistrationController::class,
                        'reject',
                    ]
                )->name(
                    'residents.reject'
                );

                Route::inertia(
                    '/sectors',
                    'Admin/Sectors'
                )->name('sectors');

                Route::inertia(
                    '/certificates',
                    'Admin/Certificates'
                )->name('certificates');

                Route::inertia(
                    '/staff',
                    'Admin/Staff'
                )->name('staff');

                Route::inertia(
                    '/audit-log',
                    'Admin/AuditLog'
                )->name('audit-log');

                Route::inertia(
                    '/reports',
                    'Admin/Reports'
                )->name('reports');

                Route::inertia(
                    '/population',
                    'Admin/Population'
                )->name('population');

                Route::inertia(
                    '/settings',
                    'Admin/Settings'
                )->name('settings');
            });
    });

/*
|--------------------------------------------------------------------------
| Clerk Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->prefix('clerk')->group(function () {
    Route::get('/dashboard', [ClerkController::class, 'dashboard'])->name('clerk.dashboard');
    
    // 1. ROUTE PARA MAKITA ANG FORM PAGE (GET) - Ito baka ang kulang kaya 404 kapag binibisita mo
    Route::inertia('/certificates', 'Clerk/Certificates')->name('clerk.certificates.page');
    
    // 2. ROUTE PARA MAG-GENERATE NG FILE (POST)
    Route::post('/generate-certificate', [CertificateController::class, 'generate'])->name('clerk.certificates.generate');
});