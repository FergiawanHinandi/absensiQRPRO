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
use App\Http\Controllers\Api\V1\SchoolAdmin\RiskOverviewController;
use App\Http\Controllers\Api\V1\SchoolAdmin\ReportController;
use App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController as SchoolAdminStudentCardController;
use App\Http\Controllers\Api\V1\Admin\SecurityMonitoringController;
use App\Http\Controllers\Api\V1\Admin\SecurityDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminSecurityAlertController;
use App\Http\Controllers\Api\V1\Admin\AttendanceReportControllerOptimized;
use App\Http\Controllers\Api\V1\Admin\AttendanceReportController;
use App\Http\Controllers\Api\V1\ExportProgressController;
use App\Http\Controllers\Api\V1\Admin\StudentCardController as AdminStudentCardController;
use App\Http\Controllers\Api\V1\Admin\StudentCardProgressController;
use App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController;
use App\Http\Controllers\Api\V1\Admin\TeacherHeatmapController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\NotificationController;

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

Route::middleware(['role:school_admin'])->prefix('admin')->group(function () {
    // A3-H4 FIX: Tambahkan GET /admin/dashboard (index) yang diagregasi dari semua sub-endpoint.
    // Sebelumnya tidak ada endpoint index, hanya ada sub-routes di bawah /dashboard/
    Route::get('dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('ability:admin:view_dashboard');

    // Dashboard sub-routes (classAttendance, teacherAbsent, lateAlpha, anomalies)
    Route::prefix('dashboard')->middleware('ability:admin:view_dashboard')->group(function () {
        Route::get('/class-attendance', [AdminDashboardController::class, 'classAttendance']);
        Route::get('/teacher-absent', [AdminDashboardController::class, 'teacherAbsent']);
        Route::get('/late-alpha', [AdminDashboardController::class, 'lateAlpha']);
        Route::get('/anomalies', [AdminDashboardController::class, 'anomalies']);
    });

    
    // School - view/update own school only
    Route::apiResource('school', SchoolController::class)->only(['show', 'update']);
    
    // Classes CRUD with status toggle
    Route::apiResource('classes', ClassController::class);
    Route::patch('classes/{id}/status', [ClassController::class, 'updateStatus']);
    
    // Students CRUD with import
    Route::apiResource('students', StudentController::class);
    Route::post('students/import', [StudentController::class, 'import'])
        ->middleware('ability:student:import');
    Route::get('students/placement', [StudentController::class, 'placements']);
    Route::get('students/mutations', [StudentController::class, 'mutations']);
    Route::patch('students/{studentId}/placement', [StudentController::class, 'updatePlacement']);
    Route::patch('students/{studentId}/mutation', [StudentController::class, 'updateMutation']);
    
    // Student Photos Review
    Route::get('students/photos/pending', [StudentPhotoReviewController::class, 'pending']);
    Route::post('students/{studentId}/photos/approve', [StudentPhotoReviewController::class, 'approve']);
    Route::post('students/{studentId}/photos/reject', [StudentPhotoReviewController::class, 'reject']);
    Route::post('students/{studentId}/photos/reupload', [StudentPhotoReviewController::class, 'reupload'])
        ->middleware('upload.validate:photo');
    
    // Teachers CRUD with import
    Route::apiResource('teachers', TeacherController::class);
    Route::post('teachers/import', [TeacherController::class, 'import'])
        ->middleware('ability:teacher:import');
    Route::patch('teachers/{id}/status', [TeacherController::class, 'updateStatus']);
    Route::get('teachers/assignments', [TeacherController::class, 'assignments']);
    Route::post('teachers/assignments', [TeacherController::class, 'storeAssignment']);
    Route::delete('teachers/assignments/{id}', [TeacherController::class, 'destroyAssignment']);
    Route::post('teachers/homeroom', [TeacherController::class, 'setHomeroom']);
    
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
        Route::post('/export-pdf', [AttendanceReportController::class, 'exportPdf'])
            ->middleware('ability:report:export');
        Route::get('/export/{jobId}/status', [AttendanceReportControllerOptimized::class, 'exportStatus']);
    });
    
    // ✅ NEW: Export Progress Tracking (Week 3 Day 14)
    Route::prefix('export-progress')->middleware('ability:report:view')->group(function () {
        Route::get('/', [ExportProgressController::class, 'index']);
        Route::get('/active', [ExportProgressController::class, 'active']);
        Route::get('/{id}', [ExportProgressController::class, 'show']);
        Route::delete('/{id}', [ExportProgressController::class, 'destroy'])
            ->middleware('ability:report:export');
    });
    
    // General Reports (index)
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('ability:report:view');
    
    // Parents listing
    Route::get('parents', [StudentController::class, 'parents']);
    
    // Student Cards
    Route::prefix('student-cards')->group(function () {
        Route::get('/progress', [StudentCardProgressController::class, 'progress']);
        Route::post('/{studentId}/generate', [AdminStudentCardController::class, 'generate']);
        Route::post('/bulk-generate', [AdminStudentCardController::class, 'bulkGenerate']);
        Route::get('/{studentId}/status', [SchoolAdminStudentCardController::class, 'getCardStatus']);
        Route::post('/{studentId}/deactivate', [SchoolAdminStudentCardController::class, 'deactivateCard']);
    });
    
    // Account Generator
    Route::post('accounts/generate', [\App\Http\Controllers\Api\V1\SchoolAdmin\AccountGeneratorController::class, 'generateBulk']);
    Route::post('accounts/{id}/reset-password', [\App\Http\Controllers\Api\V1\SchoolAdmin\AccountGeneratorController::class, 'resetPassword']);
    
    // Risk Overview
    Route::prefix('risk-overview')->group(function () {
        Route::get('/', [RiskOverviewController::class, 'index']);
        Route::get('/students', [RiskOverviewController::class, 'studentDetails']);
        Route::post('/export', [RiskOverviewController::class, 'export']);
    });
    
    // Risk changes
    Route::get('risk/changes', [RiskOverviewController::class, 'trendData']);
    
    // School Settings & Profile
    Route::get('settings/profile', [SchoolController::class, 'getSettings']);
    Route::get('settings/academic-year', [SchoolController::class, 'academicYears']);
    Route::get('school/profile', [SchoolController::class, 'profile']);
    
    // Notifications
    Route::get('notifications/logs', [NotificationController::class, 'index']);
    
    // Security Alerts (acknowledge/bulk)
    Route::prefix('security-alerts')->middleware('ability:security:manage')->group(function () {
        Route::patch('/{alertId}/ack', [AdminSecurityAlertController::class, 'resolve']);
        Route::post('/bulk-ack', [SecurityDashboardController::class, 'bulkResolve']);
    });
    
    // Security Dashboard - by school
    Route::get('security/by-school', [SecurityDashboardController::class, 'bySchool'])
        ->middleware('ability:security:view');
    
    // Teacher Heatmap
    Route::prefix('security-dashboard/teacher-heatmap')->middleware('ability:security:view')->group(function () {
        Route::get('/', [TeacherHeatmapController::class, 'index']);
        Route::get('/cluster-details', [TeacherHeatmapController::class, 'clusterDetails']);
        Route::get('/teachers', [TeacherHeatmapController::class, 'teacherSummary']);
        Route::get('/anomalies', [TeacherHeatmapController::class, 'anomalies']);
    });
});

// Query Profiling Dashboard — PROTECTED: requires auth:sanctum + role
Route::middleware(['auth:sanctum'])->prefix('query-profiling')->group(function () {
    Route::get('/dashboard', [App\Http\Controllers\Api\V1\Admin\QueryProfilingController::class, 'dashboard'])
        ->middleware('ability:admin:view_dashboard');
    Route::get('/realtime', [App\Http\Controllers\Api\V1\Admin\QueryProfilingController::class, 'realtime'])
        ->middleware('ability:admin:view_dashboard');
    Route::get('/suggestions', [App\Http\Controllers\Api\V1\Admin\QueryProfilingController::class, 'suggestions'])
        ->middleware('ability:admin:view_dashboard');
    Route::get('/{id}', [App\Http\Controllers\Api\V1\Admin\QueryProfilingController::class, 'show'])
        ->middleware('ability:admin:view_dashboard');
    Route::get('/export', [App\Http\Controllers\Api\V1\Admin\QueryProfilingController::class, 'export'])
        ->middleware('ability:admin:view_dashboard');
    Route::delete('/clear', [App\Http\Controllers\Api\V1\Admin\QueryProfilingController::class, 'clear'])
        ->middleware('ability:admin:manage_settings');
});
