<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController;
use App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController;
use App\Http\Controllers\Api\V1\Teacher\TeacherScheduleController;
use App\Http\Controllers\Api\V1\Teacher\TeacherStudentAttendanceController;
use App\Http\Controllers\Api\V1\Teacher\HomeroomController;
use App\Http\Controllers\Api\V1\TeacherProfileController;
use App\Http\Controllers\Api\V1\PermissionController;

// A3-C2 FIX: Tambahkan 'homeroom_teacher' agar wali kelas bisa akses semua endpoint teacher.
// Sebelumnya hanya 'role:teacher' — homeroom_teacher mendapat 403 di semua endpoint.
Route::middleware('role:teacher,homeroom_teacher')->prefix('teacher')->group(function () {
    Route::get('/dashboard', [TeacherDashboardController::class, 'index'])
        ->middleware('ability:teacher:view_dashboard');

    Route::get('/teaching/dashboard', [TeacherDashboardController::class, 'getSubjectDashboard'])
        ->middleware('ability:teacher:view_dashboard');

    Route::get('/teaching/monitoring', [TeacherDashboardController::class, 'getSessionMonitoring'])
        ->middleware('ability:teacher:view_dashboard');

    // Teacher Schedules (own schedules only)
    Route::prefix('schedules')->group(function () {
        Route::get('/', [TeacherScheduleController::class, 'index']);      // GET /api/v1/teacher/schedules?week=2026-W06
        Route::get('/today', [TeacherScheduleController::class, 'today']); // GET /api/v1/teacher/schedules/today
        Route::get('/{id}', [TeacherScheduleController::class, 'show']);   // GET /api/v1/teacher/schedules/{id}
        Route::get('/{id}/students', [TeacherDashboardController::class, 'getScheduleStudents']); // Legacy support
        Route::get('/{id}/attendance', [TeacherStudentAttendanceController::class, 'getScheduleAttendance']); // Students with attendance status
    });

    // Teacher Self Attendance (Check-in/Check-out)
    Route::prefix('attendance')->group(function () {
        Route::post('/check-in', [TeacherAttendanceController::class, 'checkIn'])
            ->middleware('school.rate.limit:10,1');
        Route::post('/check-out', [TeacherAttendanceController::class, 'checkOut'])
            ->middleware('school.rate.limit:10,1');
        Route::get('/today', [TeacherAttendanceController::class, 'today']);
        Route::get('/history', [TeacherAttendanceController::class, 'history']);
        Route::get('/summary', [TeacherAttendanceController::class, 'summary']);
        Route::get('/devices', [TeacherAttendanceController::class, 'devices']);
        Route::delete('/devices/{id}', [TeacherAttendanceController::class, 'removeDevice']);
        
        // Student Attendance Management (Manual Entry)
        Route::get('/today-sessions', [TeacherStudentAttendanceController::class, 'todaySessions']);
        Route::post('/manual', [TeacherStudentAttendanceController::class, 'manual'])
            ->middleware('school.rate.limit:30,1');
        Route::post('/manual/bulk', [TeacherStudentAttendanceController::class, 'bulkManual'])
            ->middleware('school.rate.limit:10,1');
    });
    
    // Teacher Profile
    Route::get('/profile', [TeacherProfileController::class, 'show']);
    
    // Teacher Classes (homeroom class summary)
    Route::get('/classes', [HomeroomController::class, 'classSummary']);
    
    // My Students (homeroom students)
    Route::get('/my-students', [HomeroomController::class, 'getStudentNotes']);
    
    // Permission Management (izin/dispensasi)
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::post('/', [PermissionController::class, 'store']);
        Route::patch('/{id}/status', [PermissionController::class, 'updateStatus']);
    });
});
