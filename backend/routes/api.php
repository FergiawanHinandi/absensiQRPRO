<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Middleware Stack:
| - auth:sanctum (authentication)
| - role:xxx (authorization)
| - throttle:x,y (rate limiting)
|
*/

// Broadcasting
Broadcast::routes(['middleware' => ['auth:sanctum']]);
require base_path('routes/channels.php');

// V1 Routes
Route::prefix('v1')
    ->middleware('rate.limit:global')
    ->group(function () {
        
        // Public Attendance Trends (cached, lightweight)
        Route::get('/attendance/trends', [\App\Http\Controllers\Api\V1\AttendanceTrendController::class, 'index'])
            ->middleware(['auth:sanctum']);
        Route::get('/attendance/trends/comparison', [\App\Http\Controllers\Api\V1\AttendanceTrendController::class, 'comparison'])
            ->middleware(['auth:sanctum']);
        
        // Recent attendance for live feed
        Route::get('/attendance/recent', [\App\Http\Controllers\Api\V1\AttendanceRecentController::class, 'index'])
            ->middleware(['auth:sanctum']);
        
        // Export attendance trends (Excel/PDF)
        Route::post('/attendance/trends/export', [\App\Http\Controllers\Api\V1\ExportTrendController::class, 'export'])
            ->middleware(['auth:sanctum']);

        // Webhooks (Public - no auth required)
        require __DIR__ . '/api/v1/webhook.php';
        
        // Common / Public
        require __DIR__ . '/api/v1/common.php';
        
        // Auth
        require __DIR__ . '/api/v1/auth.php';
        
        // Authenticated Routes
        Route::middleware([
            'auth:sanctum',
            'school.rate.limit:60,1',
        ])->group(function () {
            
            // Attendance (Scan/Manual)
            require __DIR__ . '/api/v1/attendance.php';
            
            // ✅ SECURE ATTENDANCE (QR with HMAC signature)
            require __DIR__ . '/api/v1/secure-attendance.php';
            
            // Student
            require __DIR__ . '/api/v1/student.php';

            // Leaderboard / Gamification
            require __DIR__ . '/api/v1/leaderboard.php';
            
            // Teacher
            require __DIR__ . '/api/v1/teacher.php';
            
            // Parent
            require __DIR__ . '/api/v1/parent.php';
            
            // School Admin
            require __DIR__ . '/api/v1/admin.php';
            
            // Principal
            require __DIR__ . '/api/v1/principal.php';
            
            // Super Admin
            require __DIR__ . '/api/v1/super-admin.php';
            
        });
    });
