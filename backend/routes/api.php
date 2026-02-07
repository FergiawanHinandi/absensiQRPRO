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
        
        // Common / Public
        require __DIR__ . '/api/v1/common.php';
        
        // Auth
        require __DIR__ . '/api/v1/auth.php';
        
        // Authenticated Routes
        Route::middleware('auth:sanctum')->group(function () {
            
            // Attendance (Scan/Manual)
            require __DIR__ . '/api/v1/attendance.php';
            
            // Student
            require __DIR__ . '/api/v1/student.php';
            
            // Teacher
            require __DIR__ . '/api/v1/teacher.php';
            
            // Parent
            require __DIR__ . '/api/v1/parent.php';
            
            // School Admin
            require __DIR__ . '/api/v1/admin.php';
            
            // Principal
            require __DIR__ . '/api/v1/principal.php';
            
        });
    });
