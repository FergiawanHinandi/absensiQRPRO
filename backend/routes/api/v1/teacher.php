<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Teacher Routes - API v1
|--------------------------------------------------------------------------
|
| Routes for teacher-specific functionality including:
| - Dashboard stats
| - Schedules
| - Reports export
| - Teacher attendance (self check-in/out)
|
*/

Route::middleware(['role:teacher,homeroom_teacher'])->group(function () {
    // ========================================
    // DASHBOARD
    // ========================================
    Route::get('/dashboard', [
        \App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
        'index',
    ])->middleware('ability:teacher:view_dashboard')
      ->name('teacher.dashboard');

    Route::get('/homeroom/summary', [
        \App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
        'homeroomSummary',
    ])->middleware('ability:homeroom:view_summary,teacher:view_dashboard')
      ->name('teacher.homeroom.summary');

    Route::get('/my-students', [
        \App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
        'myStudents',
    ])->middleware('ability:teacher:view_students')
      ->name('teacher.my-students');

    // ========================================
    // PROFILE
    // ========================================
    Route::get('/profile', [
        \App\Http\Controllers\Api\V1\TeacherProfileController::class,
        'show',
    ])->middleware('ability:teacher:view_profile')
      ->name('teacher.profile');

    // ========================================
    // SCHEDULES
    // ========================================
    Route::get('/schedules/today', [
        \App\Http\Controllers\Api\V1\ScheduleController::class,
        'today',
    ])->middleware('ability:teacher:view_schedules')
      ->name('teacher.schedules.today');

    // ========================================
    // REPORTS EXPORT
    // ========================================
    Route::prefix('reports')->middleware('school.rate.limit:20,1')->group(function () {
        Route::get('/export-excel', [
            \App\Http\Controllers\Api\V1\ReportExportController::class,
            'exportExcel',
        ])->middleware('ability:report:export')
          ->name('teacher.reports.export-excel');

        Route::get('/export-pdf', [
            \App\Http\Controllers\Api\V1\ReportExportController::class,
            'exportPdf',
        ])->middleware('ability:report:export')
          ->name('teacher.reports.export-pdf');

        Route::get('/monthly-summary', [
            \App\Http\Controllers\Api\V1\ReportExportController::class,
            'monthlySummary',
        ])->middleware('ability:report:export')
          ->name('teacher.reports.monthly-summary');
    });

    // ========================================
    // TEACHER SELF-ATTENDANCE
    // ========================================
    Route::prefix('attendance')->group(function () {
        Route::post('/check-in', [
            \App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
            'checkIn',
        ])->middleware('school.rate.limit:10,1')
          ->name('teacher.attendance.check-in');

        Route::post('/check-out', [
            \App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
            'checkOut',
        ])->middleware('school.rate.limit:10,1')
          ->name('teacher.attendance.check-out');

        Route::get('/today', [
            \App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
            'today',
        ])->name('teacher.attendance.today');

        Route::get('/history', [
            \App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
            'history',
        ])->name('teacher.attendance.history');

        Route::get('/summary', [
            \App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
            'summary',
        ])->name('teacher.attendance.summary');

        Route::get('/devices', [
            \App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
            'devices',
        ])->name('teacher.attendance.devices');
    });

    // ========================================
    // QR CODE GENERATION
    // ========================================
    Route::prefix('qr')->group(function () {
        Route::post('/generate', [
            \App\Http\Controllers\Api\V1\QrCodeController::class,
            'generate',
        ])->middleware(['ability:qr:generate', 'throttle:scan'])
          ->name('teacher.qr.generate');

        Route::post('/close', [
            \App\Http\Controllers\Api\V1\QrCodeController::class,
            'close',
        ])->middleware('ability:qr:close')
          ->name('teacher.qr.close');
    });

    // ========================================
    // PERMISSION MANAGEMENT (Digital Izin)
    // ========================================
    Route::prefix('permissions')->group(function () {
        Route::get('/', [
            \App\Http\Controllers\Api\V1\PermissionController::class,
            'index',
        ])->middleware('ability:permission:view')
          ->name('teacher.permissions.index');

        Route::post('/', [
            \App\Http\Controllers\Api\V1\PermissionController::class,
            'store',
        ])->middleware('ability:permission:view')
          ->name('teacher.permissions.store');

        Route::patch('/{id}/status', [
            \App\Http\Controllers\Api\V1\PermissionController::class,
            'updateStatus',
        ])->middleware('ability:permission:approve')
          ->name('teacher.permissions.update-status');
    });
});

Route::middleware(['auth:sanctum', 'role:teacher'])->prefix('teacher')->group(function () {
    Route::get('today-sessions', [
        \App\Http\Controllers\Api\V1\Teacher\AttendanceSessionController::class,
        'todaySessions',
    ])->name('teacher.today-sessions');
    Route::post('sessions/{session}/start-attendance', [
        \App\Http\Controllers\Api\V1\Teacher\AttendanceSessionController::class,
        'startAttendance',
    ])->name('teacher.sessions.start-attendance');
    Route::post('sessions/{session}/close-attendance', [
        \App\Http\Controllers\Api\V1\Teacher\AttendanceSessionController::class,
        'closeAttendance',
    ])->name('teacher.sessions.close-attendance');
    Route::get('sessions/{session}/attendance-status', [
        \App\Http\Controllers\Api\V1\Teacher\AttendanceSessionController::class,
        'attendanceStatus',
    ])->name('teacher.sessions.attendance-status');
    Route::post('sessions/{session}/manual-attendance', [
        \App\Http\Controllers\Api\V1\Teacher\AttendanceSessionController::class,
        'manualAttendance',
    ])->name('teacher.sessions.manual-attendance');
});

Route::middleware(['auth:sanctum', 'role:student'])->post('student/scan-attendance', [
    \App\Http\Controllers\Api\V1\Student\AttendanceScanController::class,
    'scanAttendance',
])->name('student.scan-attendance');
