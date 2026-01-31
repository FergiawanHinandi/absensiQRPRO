<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Route Aggregator
|--------------------------------------------------------------------------
|
| This file loads all v1 API route files and applies common middleware.
|
| ROUTE STRUCTURE:
| ├── auth.php       - Authentication routes
| ├── attendance.php - Attendance management
| ├── teacher.php    - Teacher-specific routes
| ├── admin.php      - School admin routes
| ├── security.php   - Security & monitoring (future)
| └── super-admin.php - Platform admin routes (future)
|
*/

// ========================================
// PUBLIC ROUTES
// ========================================
Route::prefix('auth')->group(
    base_path('routes/api/v1/auth.php')
);

// ========================================
// RATE LIMIT TEST ROUTES (Development Only)
// ========================================
if (app()->environment(['testing', 'local'])) {
    Route::middleware(['auth:sanctum', 'school.rate.limit:10,1'])->get(
        '/test/rate-limit/scan',
        fn () => response()->json(['ok' => true])
    );

    Route::middleware(['auth:sanctum', 'school.rate.limit:60,1'])->get(
        '/test/rate-limit/api',
        fn () => response()->json(['ok' => true])
    );

    Route::middleware(['auth:sanctum', 'school.rate.limit:5,1'])->post(
        '/test/rate-limit',
        fn () => response()->json(['success' => true, 'message' => 'Request processed'])
    );
}

// ========================================
// PROTECTED ROUTES
// ========================================
Route::middleware([
    'auth:sanctum',
    \App\Http\Middleware\CheckApiMaintenance::class,
    'rate.limit:api',
    'session.anomaly',
])->group(function () {
    // Health check
    Route::get('/health', [
        \App\Http\Controllers\Api\V1\HealthController::class,
        'check',
    ])->withoutMiddleware(['auth:sanctum']);

    // Broadcasts (announcements for all users)
    Route::get('/broadcasts', [
        \App\Http\Controllers\Api\V1\SuperAdmin\AnnouncementController::class,
        'getActive',
    ]);

    // Queue status
    Route::get('/system/queue-status', [
        \App\Http\Controllers\Api\V1\QueueHealthController::class,
        'status',
    ]);

    // ========================================
    // DOMAIN-SPECIFIC ROUTES
    // ========================================

    // Attendance routes
    Route::prefix('attendance')->group(
        base_path('routes/api/v1/attendance.php')
    );

    // Teacher routes
    Route::prefix('teacher')->group(
        base_path('routes/api/v1/teacher.php')
    );

    // School Admin routes
    Route::prefix('admin')->group(
        base_path('routes/api/v1/admin.php')
    );

    // ========================================
    // STUDENT ROUTES
    // ========================================
    Route::middleware('role:student')->prefix('student')->group(function () {
        Route::get('/my-qr-card', [
            \App\Http\Controllers\Api\V1\StudentQrController::class,
            'myQrCard',
        ])->middleware('ability:student:view_qr_card');

        Route::post('/permissions', [
            \App\Http\Controllers\Api\V1\PermissionController::class,
            'store',
        ])->middleware('ability:permission:create');
    });

    // ========================================
    // PARENT ROUTES
    // ========================================
    Route::middleware('role:parent')->prefix('parent')->group(function () {
        Route::get('/my-children', [
            \App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
            'index',
        ])->middleware('ability:parent:view_children');

        Route::get('/children/{id}/attendance', [
            \App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
            'childAttendance',
        ])->middleware('ability:parent:view_attendance');
    });

    // ========================================
    // MOBILE SECURITY EVENTS
    // ========================================
    Route::prefix('security')->group(function () {
        Route::post('/mobile-event', [
            \App\Http\Controllers\Api\V1\MobileSecurityController::class,
            'reportEvent',
        ]);

        Route::post('/mobile-events/batch', [
            \App\Http\Controllers\Api\V1\MobileSecurityController::class,
            'reportBatch',
        ]);

        Route::post('/device-integrity', [
            \App\Http\Controllers\Api\V1\MobileSecurityController::class,
            'reportDeviceIntegrity',
        ]);
    });
});
