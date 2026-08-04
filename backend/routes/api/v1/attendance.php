<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AttendanceController;

/*
|--------------------------------------------------------------------------
| Attendance API Routes (Secured)
|--------------------------------------------------------------------------
|
| ALL scan endpoints consolidated into AttendanceController.
| Security: attendance.security middleware on all scan routes.
|
| ENDPOINTS:
| - POST /attendance/scan              → Student QR scan (mobile app)
| - POST /attendance/scan-student      → Teacher scans student (token-based)
| - POST /attendance/secure/scan       → Teacher scan (HMAC signature verified)
| - POST /attendance/secure/scan-encoded → Teacher scan (base64 encoded QR)
| - POST /attendance/secure/generate-qr  → Generate signed QR for student
| - POST /attendance/manual            → Manual attendance entry
|
*/

// Student Scan - Most restricted
Route::middleware([
    'role:student',
    'ability:attendance:scan',
    'attendance.security',
    'idempotency:2',
    'attendance.rate.limit:qr-scan',
])->group(function () {
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
});

// Teacher & Admin Scan Actions
Route::middleware([
    'role:teacher,homeroom_teacher,admin,school_admin',
    'attendance.security',
])->group(function () {
    Route::post('/attendance/scan-student', [AttendanceController::class, 'teacherScan'])
        ->middleware([
            'teacher.device',
            'ability:attendance:scan',
            'idempotency:2',
            'attendance.rate.limit:qr-scan',
        ]);

    Route::post('/attendance/manual', [AttendanceController::class, 'manual'])
        ->middleware([
            'teacher.device',
            'ability:attendance:manual,*',
            'idempotency:2',
            'attendance.rate.limit:manual-entry',
        ]);
});

// Secure Attendance (Teacher scanning student QR with HMAC verification)
Route::middleware([
    'role:teacher,homeroom_teacher',
    'teacher.device',
    'attendance.security',
])->prefix('attendance/secure')->group(function () {
    Route::post('/scan', [AttendanceController::class, 'secureScan'])
        ->middleware([
            'ability:attendance:scan',
            'idempotency:2',
            'attendance.rate.limit:qr-scan',
        ]);

    Route::post('/scan-encoded', [AttendanceController::class, 'scanEncoded'])
        ->middleware([
            'ability:attendance:scan',
            'idempotency:2',
            'attendance.rate.limit:qr-scan',
        ]);

    Route::post('/generate-qr', [AttendanceController::class, 'generateQR'])
        ->middleware([
            'ability:qr:generate',
            'attendance.rate.limit:generate-qr',
        ]);
});
