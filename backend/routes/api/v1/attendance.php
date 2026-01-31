<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Attendance Routes - API v1
|--------------------------------------------------------------------------
|
| Routes for attendance management including:
| - Student QR scan
| - Teacher scan/manual input
| - Secure HMAC-signed scan
| - Attendance history
| - Reports
|
| All routes require auth:sanctum middleware.
| School-scoped rate limiting applied where appropriate.
|
*/

// ========================================
// STUDENT ATTENDANCE ROUTES
// ========================================
Route::middleware(['role:student'])->group(function () {
    // Student: Scan QR (RATE LIMITED - 5/10 seconds per device)
    Route::post('/scan', [
        \App\Http\Controllers\Api\V1\AttendanceController::class,
        'scan',
    ])->middleware([
        'ability:attendance:scan',
        'attendance.throttle', // 5 req/10s with IP blocking
    ])->name('attendance.scan');

    // Student: View attendance history
    Route::get('/history', [
        \App\Http\Controllers\Api\V1\AttendanceController::class,
        'history',
    ])->middleware('ability:attendance:view_own')
      ->name('attendance.history');

    // Student: View today's attendance status
    Route::get('/today', [
        \App\Http\Controllers\Api\V1\AttendanceController::class,
        'today',
    ])->middleware('ability:attendance:view_own')
      ->name('attendance.today');
});

// ========================================
// TEACHER ATTENDANCE ROUTES
// ========================================
Route::middleware(['role:teacher,homeroom_teacher,admin,school_admin'])->group(function () {
    // Teacher: Scan student QR (with device binding)
    Route::post('/scan', [
        \App\Http\Controllers\Api\V1\TeacherScanController::class,
        'scan',
    ])->middleware([
        'teacher.device',
        'ability:attendance:scan',
        'attendance.throttle', // 5 req/10s with IP blocking
    ])->name('attendance.teacher.scan');

    // Teacher: Manual attendance input
    Route::post('/manual', [
        \App\Http\Controllers\Api\V1\AttendanceController::class,
        'manual',
    ])->middleware([
        'teacher.device',
        'ability:attendance:manual,*',
        'school.rate.limit:60,1',
    ])->name('attendance.manual');

    // Teacher: Bulk attendance input
    Route::post('/bulk', [
        \App\Http\Controllers\Api\V1\AttendanceController::class,
        'bulkStore',
    ])->middleware([
        'teacher.device',
        'ability:attendance:manual,*',
        'school.rate.limit:30,1',
    ])->name('attendance.bulk');

    // Teacher: View class attendance
    Route::get('/schedule/{scheduleId}', [
        \App\Http\Controllers\Api\V1\AttendanceReportController::class,
        'bySchedule',
    ])->middleware('ability:attendance:view')
      ->name('attendance.by-schedule');

    // Teacher: Update attendance (manual only)
    Route::put('/{attendanceId}', [
        \App\Http\Controllers\Api\V1\AttendanceReportController::class,
        'update',
    ])->middleware('ability:attendance:manual,*')
      ->name('attendance.update');
});

// ========================================
// SECURE ATTENDANCE (HMAC Signature Validation)
// ========================================
Route::middleware([
    'role:teacher,homeroom_teacher',
    'teacher.device',
    'attendance.throttle', // 5 req/10s with IP blocking
])->prefix('secure')->group(function () {
    // Scan with JSON payload (signature verification)
    Route::post('/scan', [
        \App\Http\Controllers\Api\V1\SecureAttendanceScanController::class,
        'scan',
    ])->middleware('ability:attendance:scan')
      ->name('attendance.secure.scan');

    // Scan with encoded QR string (base64)
    Route::post('/scan-encoded', [
        \App\Http\Controllers\Api\V1\SecureAttendanceScanController::class,
        'scanEncoded',
    ])->middleware('ability:attendance:scan')
      ->name('attendance.secure.scan-encoded');

    // Generate signed QR for student
    Route::post('/generate-qr', [
        \App\Http\Controllers\Api\V1\SecureAttendanceScanController::class,
        'generateQR',
    ])->middleware('ability:qr:generate')
      ->name('attendance.secure.generate-qr');
});

// ========================================
// ATTENDANCE REPORTS (Admin)
// ========================================
Route::middleware(['role:admin,school_admin,principal,super_admin'])
    ->prefix('reports')
    ->group(function () {
        // Daily report
        Route::get('/daily', [
            \App\Http\Controllers\Api\V1\AttendanceReportController::class,
            'dailyReport',
        ])->middleware([
            'ability:report:daily,*',
            'school.rate.limit:20,1',
        ])->name('attendance.reports.daily');

        // Monthly summary
        Route::get('/monthly', [
            \App\Http\Controllers\Api\V1\AttendanceReportController::class,
            'monthlySummary',
        ])->middleware([
            'ability:report:monthly,*',
            'school.rate.limit:20,1',
        ])->name('attendance.reports.monthly');

        // Export attendance data
        Route::get('/export', [
            \App\Http\Controllers\Api\V1\AttendanceReportController::class,
            'export',
        ])->middleware([
            'ability:report:export,*',
            'school.rate.limit:10,1',
        ])->name('attendance.reports.export');
    });

// ========================================
// ATTENDANCE MANAGEMENT (Admin CRUD)
// ========================================
Route::middleware(['role:admin,school_admin,super_admin'])->group(function () {
    // List all attendances
    Route::get('/', [
        \App\Http\Controllers\Api\V1\AttendanceReportController::class,
        'index',
    ])->name('attendance.index');

    // View single attendance
    Route::get('/{attendanceId}', [
        \App\Http\Controllers\Api\V1\AttendanceReportController::class,
        'show',
    ])->name('attendance.show');

    // Delete attendance (soft delete)
    Route::delete('/{attendanceId}', [
        \App\Http\Controllers\Api\V1\AttendanceReportController::class,
        'destroy',
    ])->name('attendance.destroy');
});
