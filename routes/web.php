<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Resident\RegistrationController;
use App\Http\Controllers\Admin\ResidentRegistrationController;
use Illuminate\Support\Facades\Route;

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
| Resident Authentication & Portal
|--------------------------------------------------------------------------
|
| These routes are temporarily public while we are building the Resident
| side. Later, the portal routes will be protected by Resident
| authentication.
|
*/

Route::prefix('resident')
    ->name('resident.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Resident Authentication
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/login',
            'Resident/Login'
        )->name('login');

        /*
        |--------------------------------------------------------------------------
        | Resident Login Barangay Dropdown
        |--------------------------------------------------------------------------
        |
        | Returns active barangays for the Resident Login dropdown.
        |
        */

        Route::get(
            '/login/barangays',
            [
                AuthController::class,
                'residentLoginBarangays',
            ]
        )->name('login.barangays');

        /*
        |--------------------------------------------------------------------------
        | Resident Registration
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Resident Dashboard
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/',
            'Resident/Dashboard'
        )->name('dashboard');

        /*
        |--------------------------------------------------------------------------
        | Certificates
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/certificates',
            'Resident/Certificates'
        )->name('certificates');

        /*
        |--------------------------------------------------------------------------
        | Lupon
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/lupon',
            'Resident/Lupon'
        )->name('lupon');

        /*
        |--------------------------------------------------------------------------
        | Announcements
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/announcements',
            'Resident/Announcements'
        )->name('announcements');

        /*
        |--------------------------------------------------------------------------
        | Disbursement
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/disbursement',
            'Resident/Disbursement'
        )->name('disbursement');

        /*
        |--------------------------------------------------------------------------
        | Citizen's Charter
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/citizen-charter',
            'Resident/CitizenCharter'
        )->name('citizen-charter');

        /*
        |--------------------------------------------------------------------------
        | Chatbot
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/chatbot',
            'Resident/Chatbot'
        )->name('chatbot');

        /*
        |--------------------------------------------------------------------------
        | Profile
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/profile',
            'Resident/Profile'
        )->name('profile');

        /*
        |--------------------------------------------------------------------------
        | Settings
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/settings',
            'Resident/Settings'
        )->name('settings');

        /*
        |--------------------------------------------------------------------------
        | About
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/about',
            'Resident/About'
        )->name('about');
    });

/*
|--------------------------------------------------------------------------
| Authenticated Area
|--------------------------------------------------------------------------
*/

Route::middleware('auth')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Logout
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/logout',
            [
                LoginController::class,
                'destroy',
            ]
        )->name('logout');

        /*
        |--------------------------------------------------------------------------
        | Admin Area
        |--------------------------------------------------------------------------
        */

        Route::prefix('admin')
            ->name('admin.')
            ->group(function () {

                /*
                |--------------------------------------------------------------------------
                | Dashboard
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/',
                    'Dashboard'
                )->name('dashboard');

                /*
                |--------------------------------------------------------------------------
                | Residents
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
                    '/residents/{registration}/reject',
                    [
                        ResidentRegistrationController::class,
                        'reject',
                    ]
                )->name(
                    'residents.reject'
                );

                /*
                |--------------------------------------------------------------------------
                | Sectors
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/sectors',
                    'Admin/Sectors'
                )->name('sectors');

                /*
                |--------------------------------------------------------------------------
                | Certificates
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/certificates',
                    'Admin/Certificates'
                )->name('certificates');

                /*
                |--------------------------------------------------------------------------
                | Staff
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/staff',
                    'Admin/Staff'
                )->name('staff');

                /*
                |--------------------------------------------------------------------------
                | Audit Log
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/audit-log',
                    'Admin/AuditLog'
                )->name('audit-log');

                /*
                |--------------------------------------------------------------------------
                | Reports
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/reports',
                    'Admin/Reports'
                )->name('reports');

                /*
                |--------------------------------------------------------------------------
                | Population
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/population',
                    'Admin/Population'
                )->name('population');

                /*
                |--------------------------------------------------------------------------
                | Settings
                |--------------------------------------------------------------------------
                */

                Route::inertia(
                    '/settings',
                    'Admin/Settings'
                )->name('settings');
            });
    });