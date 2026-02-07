<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController;
use App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController;

Route::middleware('role:teacher')->prefix('teacher')->group(function () {
    Route::get('/dashboard', [TeacherDashboardController::class, 'index'])
        ->middleware('ability:teacher:view_dashboard');

    Route::get('/teaching/dashboard', [TeacherDashboardController::class, 'getSubjectDashboard'])
        ->middleware('ability:teacher:view_dashboard');

    Route::get('/teaching/monitoring', [TeacherDashboardController::class, 'getSessionMonitoring'])
        ->middleware('ability:teacher:view_dashboard');
        
    // Schedules for teaching
    // Note: I saw getWeeklyScheduleByTeacher in Admin controller, check if Teacher has own schedule route
    // The previous output didn't show specific teacher schedule routes other than dashboard stats.
    // I will assume dashboard covers it or it was in the truncated part. 
    // Wait, I saw Route::get('/teaching/schedules/{id}/students', ...) in the truncated output.
    // I should add that.
    
    // Adding what I saw in truncated output for Teacher:
     Route::get('/teaching/schedules/{id}/students', [TeacherDashboardController::class, 'getScheduleStudents']);

    // Attendance (Self)
    Route::prefix('attendance')->group(function () {
        Route::post('/check-in', [TeacherAttendanceController::class, 'checkIn'])
            ->middleware('school.rate.limit:10,1');
        Route::post('/check-out', [TeacherAttendanceController::class, 'checkOut'])
            ->middleware('school.rate.limit:10,1');
        Route::get('/today', [TeacherAttendanceController::class, 'today']);
        Route::get('/history', [TeacherAttendanceController::class, 'history']);
        Route::get('/summary', [TeacherAttendanceController::class, 'summary']);
        Route::get('/devices', [TeacherAttendanceController::class, 'devices']);
    });
});
