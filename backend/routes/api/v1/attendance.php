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
    'attendance.security',      // Full security checks (replay, GPS, timestamp)
    'idempotency:2',            // REQUIRED: Idempotency with 2min TTL
    'attendance.rate.limit:qr-scan', // Specialized rate limiting
])->group(function () {
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
});

// Teacher & Admin Scan Actions
Route::middleware([
    'role:teacher,homeroom_teacher,admin,school_admin',
    'attendance.security',      // Full security checks
])->group(function () {
    Route::post('/attendance/scan-student', [
        TeacherScanController::class,
        'scan',
    ])->middleware([
        'teacher.device',
        'ability:attendance:scan',
        'idempotency:2',            // REQUIRED: Idempotency with 2min TTL
        'attendance.rate.limit:qr-scan', // Specialized rate limiting
    ]);
    
    Route::post('/attendance/manual', [
        AttendanceController::class,
        'manual',
    ])->middleware([
        'teacher.device',
        'ability:attendance:manual,*',
        'idempotency:2',            // REQUIRED: Idempotency with 2min TTL
        'attendance.rate.limit:manual-entry', // Specialized rate limiting
    ]);
});

// Secure Attendance (Teacher scanning student QR)
Route::middleware([
    'role:teacher,homeroom_teacher',
    'teacher.device',
    'attendance.security',      // Full security checks
])->prefix('attendance/secure')->group(function () {
    Route::post('/scan', [
        SecureAttendanceScanController::class,
        'scan',
    ])->middleware([
        'ability:attendance:scan',
        'idempotency:2',            // REQUIRED: Idempotency with 2min TTL
        'attendance.rate.limit:qr-scan', // Specialized rate limiting
    ]);

    Route::post('/scan-encoded', [
        SecureAttendanceScanController::class,
        'scanEncoded',
    ])->middleware([
        'ability:attendance:scan',
        'idempotency:2',            // REQUIRED: Idempotency with 2min TTL
        'attendance.rate.limit:qr-scan', // Specialized rate limiting
    ]);

    Route::post('/generate-qr', [
        SecureAttendanceScanController::class,
        'generateQR',
    ])->middleware([
        'ability:qr:generate',
        'attendance.rate.limit:generate-qr', // Specialized rate limiting
    ]);
});
