<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\TeacherScanController;
use App\Http\Controllers\Api\V1\SecureAttendanceScanController;

/*
|--------------------------------------------------------------------------
| Attendance API Routes (Secured)
|--------------------------------------------------------------------------
|
| SECURITY MIDDLEWARE STACK:
| 1. attendance.security - Replay, GPS spoofing, timestamp, idempotency
| 2. critical.rate.limit - Per-endpoint rate limiting
| 3. teacher.device     - Device registration validation
| 4. ability            - Permission check
|
| REQUIRED HEADERS:
| - Authorization: Bearer {token}
| - X-Idempotency-Key: {uuid}  (recommended for retry safety)
| - X-Request-ID: {uuid}       (optional, auto-generated if missing)
|
| SECURITY FEATURES:
| ✓ Replay attack prevention (nonce validation)
| ✓ Timestamp manipulation prevention (server time authority)
| ✓ GPS spoofing detection (travel speed, mock location)
| ✓ Double submit prevention (idempotency key)
| ✓ Rate limiting per user+device
|
*/

// Student Scan - Most restricted
Route::middleware([
    'role:student',
    'ability:attendance:scan',
    'attendance.security',      // NEW: Full security checks
    'critical.rate.limit:qr-scan',
])->group(function () {
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
});

// Teacher & Admin Scan Actions
Route::middleware([
    'role:teacher,homeroom_teacher,admin,school_admin',
    'attendance.security',      // NEW: Full security checks
])->group(function () {
    Route::post('/attendance/scan-student', [
        TeacherScanController::class,
        'scan',
    ])->middleware([
        'teacher.device',
        'ability:attendance:scan',
        'critical.rate.limit:qr-scan',
    ]);
    
    Route::post('/attendance/manual', [
        AttendanceController::class,
        'manual',
    ])->middleware([
        'teacher.device',
        'ability:attendance:manual,*',
        'school.rate.limit:60,1',
    ]);
});

// Secure Attendance (Teacher scanning student QR)
Route::middleware([
    'role:teacher,homeroom_teacher',
    'teacher.device',
    'attendance.security',      // NEW: Full security checks
    'school.rate.limit:60,1',
])->prefix('attendance/secure')->group(function () {
    Route::post('/scan', [
        SecureAttendanceScanController::class,
        'scan',
    ])->middleware([
        'ability:attendance:scan',
        'critical.rate.limit:qr-scan',
    ]);

    Route::post('/scan-encoded', [
        SecureAttendanceScanController::class,
        'scanEncoded',
    ])->middleware([
        'ability:attendance:scan',
        'critical.rate.limit:qr-scan',
    ]);

    Route::post('/generate-qr', [
        SecureAttendanceScanController::class,
        'generateQR',
    ])->middleware('ability:qr:generate');
});
