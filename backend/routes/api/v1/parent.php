<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Parent\ParentDashboardController;
use App\Http\Controllers\Api\V1\PermissionController;

Route::middleware('role:parent')->prefix('parent')->group(function () {
    Route::get('/my-children', [ParentDashboardController::class, 'index'])
        ->middleware('ability:parent:view_children');
        
    Route::get('/children/{id}/dashboard', [ParentDashboardController::class, 'getDashboardStats'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/attendance', [ParentDashboardController::class, 'childAttendance'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/late-analysis', [ParentDashboardController::class, 'getLateAnalysis'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/alerts', [ParentDashboardController::class, 'getAbsenceAlerts'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/timeline', [ParentDashboardController::class, 'getTodayTimeline'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/reports/monthly', [ParentDashboardController::class, 'getMonthlyReport'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/notifications', [ParentDashboardController::class, 'getNotificationFeed'])
        ->middleware('ability:parent:view_attendance');
    Route::get('/children/{id}/profile', [ParentDashboardController::class, 'getStudentProfile'])
        ->middleware('ability:parent:view_attendance');

    // Permission / Izin (parent submits leave requests for children)
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::post('/', [PermissionController::class, 'store']);
    });
});
