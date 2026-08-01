<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Principal\PrincipalDashboardController;
use App\Http\Controllers\Api\V1\Principal\PrincipalMonitoringController;
use App\Http\Controllers\Api\V1\Principal\PrincipalReportController;

/*
|--------------------------------------------------------------------------
| Principal API Routes
|--------------------------------------------------------------------------
|
| Routes for Principal and Vice Principal roles
|
| BE-09 FIX: Tambah endpoint untuk Monitoring, Reports, dan Approvals
| yang sebelumnya ada di frontend tapi tidak ada di backend.
|
*/

Route::middleware(['role:principal,vice_principal'])->prefix('principal')->group(function () {

    // Dashboard
    Route::get('/dashboard', [PrincipalDashboardController::class, 'index']);
    Route::get('/attendance-overview', [PrincipalDashboardController::class, 'attendanceOverview']);
    Route::get('/class-performance', [PrincipalDashboardController::class, 'classPerformance']);
    Route::get('/risk-students', [PrincipalDashboardController::class, 'riskStudents']);

    // BE-09 FIX: Monitoring routes (untuk PrincipalMonitoring frontend page)
    Route::prefix('monitoring')->group(function () {
        Route::get('/', [PrincipalMonitoringController::class, 'index']);
        Route::get('/realtime', [PrincipalMonitoringController::class, 'realtime']);
        Route::get('/alerts', [PrincipalMonitoringController::class, 'alerts']);
    });

    // BE-09 FIX: Reports routes (untuk PrincipalReports frontend page)
    Route::prefix('reports')->group(function () {
        Route::get('/', [PrincipalReportController::class, 'index']);
        Route::get('/attendance', [PrincipalReportController::class, 'attendance']);
        Route::get('/teacher-performance', [PrincipalReportController::class, 'teacherPerformance']);
        Route::get('/student-performance', [PrincipalReportController::class, 'studentPerformance']);
        Route::get('/summary', [PrincipalDashboardController::class, 'reportsSummary']);
        Route::post('/export', [PrincipalReportController::class, 'export']);
    });

    // BE-09 FIX: Approvals routes (untuk PrincipalApprovals frontend page)
    Route::prefix('approvals')->group(function () {
        Route::get('/', [PrincipalMonitoringController::class, 'pendingApprovals']);
        Route::post('/{id}/approve', [PrincipalMonitoringController::class, 'approve']);
        Route::post('/{id}/reject', [PrincipalMonitoringController::class, 'reject']);
    });
});
