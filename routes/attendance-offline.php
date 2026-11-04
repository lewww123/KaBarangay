<?php

use App\Http\Controllers\OfflineAttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:clerk,senior,pwd', 'throttle:120,1'])
    ->prefix('attendance/offline/{department}/{announcement}')
    ->where(['department' => 'clerk|senior|pwd', 'announcement' => '[0-9]+'])
    ->group(function (): void {
        Route::get('/context', [OfflineAttendanceController::class, 'context'])->name('attendance.offline.context');
        Route::post('/sync', [OfflineAttendanceController::class, 'sync'])->name('attendance.offline.sync');
    });
