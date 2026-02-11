<?php

use App\Http\Controllers\Api\V1\Student\SecureAttendanceController;
use App\Http\Controllers\Api\V1\Teacher\QRGeneratorController;
use Illuminate\Support\Facades\Route;

/**
 * Secure Attendance Routes
 * 
 * SECURITY FEATURES:
 * - HMAC signature validation
 * - Rate limiting (30/min)
 * - Redis locking
 * - Idempotency checking
 * - Audit logging
 * 
 * @version 2.0.0 - Security Hardened
 */

// ============================================
// STUDENT ROUTES
// ============================================
Route::middleware(['auth:sanctum', 'role:student'])->prefix('student/attendance')->group(function () {
    
    // QR Scan (with security middleware)
    Route::post('/scan', [SecureAttendanceController::class, 'scan'])
        ->middleware(['throttle:scan', 'qr.validate'])
        ->name('student.attendance.scan');
    
    // Attendance History
    Route::get('/history', [SecureAttendanceController::class, 'history'])
        ->name('student.attendance.history');
    
    // Today's Summary
    Route::get('/today', [SecureAttendanceController::class, 'today'])
        ->name('student.attendance.today');
});

// ============================================
// TEACHER ROUTES
// ============================================
Route::middleware(['auth:sanctum', 'role:teacher|homeroom_teacher|school_admin|principal|vice_principal'])
    ->prefix('teacher/attendance')
    ->group(function () {
    
    // Generate QR Code
    Route::post('/generate-qr', [QRGeneratorController::class, 'generate'])
        ->middleware('throttle:60,1') // 60 per minute
        ->name('teacher.attendance.generate-qr');
    
    // Get Active Session
    Route::get('/active-session', [QRGeneratorController::class, 'activeSession'])
        ->name('teacher.attendance.active-session');
    
    // Live Attendance Updates
    Route::get('/session/{scheduleId}/live', [QRGeneratorController::class, 'liveAttendance'])
        ->name('teacher.attendance.live');
});
