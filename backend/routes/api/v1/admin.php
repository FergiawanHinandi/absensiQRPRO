<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController;
use App\Http\Controllers\Api\V1\SchoolAdmin\ClassController;
use App\Http\Controllers\Api\V1\SchoolAdmin\StudentController;
use App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController;
use App\Http\Controllers\Api\V1\SchoolAdmin\SubjectController;
use App\Http\Controllers\Api\V1\SchoolAdmin\TeacherSubjectController;
use App\Http\Controllers\Api\V1\SchoolAdmin\ScheduleController as AdminScheduleController;
use App\Http\Controllers\Api\V1\SchoolAdmin\AttendanceSettingsController;
use App\Http\Controllers\Api\V1\Admin\SecurityMonitoringController;
use App\Http\Controllers\Api\V1\Admin\AttendanceReportControllerOptimized;
use App\Http\Controllers\Api\V1\AdminDashboardController;

/*
|--------------------------------------------------------------------------
| School Admin API Routes
|--------------------------------------------------------------------------
|
| SECURITY MIDDLEWARE STACK:
| 1. auth:sanctum - Authentication (inherited from api.php)
| 2. role:school_admin - Role-based access control
| 3. ability:xxx - Fine-grained permission checks (on sensitive operations)
|
| IDOR PROTECTION:
| - All models use BelongsToSchool trait (auto school_id scoping)
| - Controllers use validateSchoolOwnership() helper
| - Policies enforce tenant isolation
|
*/

Route::middleware(['role:school_admin'])->prefix('school-admin')->group(function () {
    // Dashboard
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('ability:admin:view_dashboard');
    
    // School - view/update own school only
    Route::apiResource('school', SchoolController::class)->only(['show', 'update']);
    
    // Classes CRUD
    Route::apiResource('classes', ClassController::class);
    
    // Students CRUD with import
    Route::apiResource('students', StudentController::class);
    Route::post('students/import', [StudentController::class, 'import'])
        ->middleware('ability:student:import');
    
    // Teachers CRUD with import
    Route::apiResource('teachers', TeacherController::class);
    Route::post('teachers/import', [TeacherController::class, 'import'])
        ->middleware('ability:teacher:import');
    
    // Subjects CRUD
    Route::apiResource('subjects', SubjectController::class);
    
    // Teacher-Subject assignments
    Route::get('teacher-subjects', [TeacherSubjectController::class, 'index']);
    Route::post('teacher-subjects', [TeacherSubjectController::class, 'store']);
    Route::delete('teacher-subjects/{id}', [TeacherSubjectController::class, 'destroy']);
    
    // Schedules CRUD with import
    Route::apiResource('schedules', AdminScheduleController::class);
    Route::post('schedules/import', [AdminScheduleController::class, 'import'])
        ->middleware('ability:schedule:import');
    Route::get('classes/{classId}/weekly-schedule', [AdminScheduleController::class, 'getWeeklyScheduleByClass']);
    Route::get('teachers/{teacherId}/weekly-schedule', [AdminScheduleController::class, 'getWeeklyScheduleByTeacher']);
    
    // Attendance Settings
    Route::get('attendance-settings', [AttendanceSettingsController::class, 'index']);
    Route::post('attendance-settings', [AttendanceSettingsController::class, 'update'])
        ->middleware('ability:settings:manage');
    
    Route::prefix('attendance-settings')->group(function () {
        Route::get('qr-mode', [AttendanceSettingsController::class, 'getQrMode']);
        Route::post('qr-mode', [AttendanceSettingsController::class, 'updateQrMode'])
            ->middleware('ability:settings:manage');
        Route::get('override', [AttendanceSettingsController::class, 'getOverride']);
        Route::post('override', [AttendanceSettingsController::class, 'updateOverride'])
            ->middleware('ability:settings:manage');
        Route::get('tolerance', [AttendanceSettingsController::class, 'getTolerance']);
        Route::post('tolerance', [AttendanceSettingsController::class, 'updateTolerance'])
            ->middleware('ability:settings:manage');
    });
    
    // Security Monitoring (requires elevated permission)
    Route::prefix('security')->middleware('ability:security:view')->group(function () {
        Route::get('events', [SecurityMonitoringController::class, 'events']);
        Route::get('summary', [SecurityMonitoringController::class, 'summary']);
        Route::get('suspicious-students', [SecurityMonitoringController::class, 'suspiciousStudents']);
        Route::get('suspicious-devices', [SecurityMonitoringController::class, 'suspiciousDevices']);
        Route::get('top-flagged-students', [SecurityMonitoringController::class, 'topFlaggedStudents']);
        Route::post('suspicious-students/{id}/review', [SecurityMonitoringController::class, 'reviewStudent'])
            ->middleware('ability:security:manage');
        Route::post('suspicious-devices/{id}/block', [SecurityMonitoringController::class, 'toggleDeviceBlock'])
            ->middleware('ability:security:manage');
        
        Route::get('trend', [SecurityMonitoringController::class, 'trend']);
        Route::get('by-type', [SecurityMonitoringController::class, 'byType']);
        Route::get('by-severity', [SecurityMonitoringController::class, 'bySeverity']);
        Route::get('critical-recent', [SecurityMonitoringController::class, 'criticalRecent']);
    });
    
    // ✅ OPTIMIZED: Attendance Reports with caching, pagination, async export, and archive support
    Route::prefix('reports')->middleware('ability:report:view')->group(function () {
        Route::get('/daily', [AttendanceReportControllerOptimized::class, 'daily']);
        Route::get('/monthly', [AttendanceReportControllerOptimized::class, 'monthly']);
        Route::get('/student/{id}/monthly', [AttendanceReportControllerOptimized::class, 'studentMonthly']);
        Route::get('/dashboard', [AttendanceReportControllerOptimized::class, 'dashboard']);
        
        // ✅ NEW: Historical reports with archive support (UNION query)
        Route::get('/historical', [AttendanceReportControllerOptimized::class, 'historical']);
        Route::get('/archives', [AttendanceReportControllerOptimized::class, 'archives']);
        
        // Async export
        Route::post('/export/excel', [AttendanceReportControllerOptimized::class, 'exportExcel'])
            ->middleware('ability:report:export');
        Route::get('/export/{jobId}/status', [AttendanceReportControllerOptimized::class, 'exportStatus']);
    });
});
