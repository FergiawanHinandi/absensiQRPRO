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
use App\Http\Controllers\Api\V1\AdminDashboardController;

Route::middleware(['role:school_admin'])->prefix('school-admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);
    
    Route::apiResource('school', SchoolController::class)->only(['show', 'update']);
    Route::apiResource('classes', ClassController::class);
    
    Route::apiResource('students', StudentController::class);
    Route::post('students/import', [StudentController::class, 'import']);
    
    Route::apiResource('teachers', TeacherController::class);
    Route::post('teachers/import', [TeacherController::class, 'import']);
    
    Route::apiResource('subjects', SubjectController::class);
    
    Route::get('teacher-subjects', [TeacherSubjectController::class, 'index']);
    Route::post('teacher-subjects', [TeacherSubjectController::class, 'store']);
    Route::delete('teacher-subjects/{id}', [TeacherSubjectController::class, 'destroy']);
    
    Route::apiResource('schedules', AdminScheduleController::class);
    Route::post('schedules/import', [AdminScheduleController::class, 'import']);
    Route::get('classes/{classId}/weekly-schedule', [AdminScheduleController::class, 'getWeeklyScheduleByClass']);
    Route::get('teachers/{teacherId}/weekly-schedule', [AdminScheduleController::class, 'getWeeklyScheduleByTeacher']);
    
    Route::get('attendance-settings', [AttendanceSettingsController::class, 'index']);
    Route::post('attendance-settings', [AttendanceSettingsController::class, 'update']);
    
    Route::prefix('attendance-settings')->group(function () {
        Route::get('qr-mode', [AttendanceSettingsController::class, 'getQrMode']);
        Route::post('qr-mode', [AttendanceSettingsController::class, 'updateQrMode']);
        Route::get('override', [AttendanceSettingsController::class, 'getOverride']);
        Route::post('override', [AttendanceSettingsController::class, 'updateOverride']);
        Route::get('tolerance', [AttendanceSettingsController::class, 'getTolerance']);
        Route::post('tolerance', [AttendanceSettingsController::class, 'updateTolerance']);
    });
    
    Route::prefix('security')->group(function () {
        Route::get('events', [SecurityMonitoringController::class, 'events']);
        Route::get('summary', [SecurityMonitoringController::class, 'summary']);
        Route::get('suspicious-students', [SecurityMonitoringController::class, 'suspiciousStudents']);
        Route::get('suspicious-devices', [SecurityMonitoringController::class, 'suspiciousDevices']);
        Route::get('top-flagged-students', [SecurityMonitoringController::class, 'topFlaggedStudents']);
        Route::post('suspicious-students/{id}/review', [SecurityMonitoringController::class, 'reviewStudent']);
        Route::post('suspicious-devices/{id}/block', [SecurityMonitoringController::class, 'toggleDeviceBlock']);
        
        Route::get('trend', [SecurityMonitoringController::class, 'trend']);
        Route::get('by-type', [SecurityMonitoringController::class, 'byType']);
        Route::get('by-severity', [SecurityMonitoringController::class, 'bySeverity']);
        Route::get('critical-recent', [SecurityMonitoringController::class, 'criticalRecent']);
    });
});
