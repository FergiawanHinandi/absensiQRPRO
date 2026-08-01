<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MonitoringController;
use App\Http\Controllers\Api\V1\RegionController;

Route::get('/health', [HealthController::class, 'check']);

// Individual service health checks
Route::get('/health/database', [HealthController::class, 'database']);
Route::get('/health/redis', [HealthController::class, 'redis']);
Route::get('/health/storage', [HealthController::class, 'storage']);
Route::get('/health/queue', [HealthController::class, 'queue']);

// Deep health check for self-healing infrastructure (internal use only)
Route::get('/health/deep', [\App\Http\Controllers\HealthCheckController::class, 'deep'])
    ->middleware(['throttle:60,1']); // Rate limit to prevent abuse

// Observability metrics endpoint (requires authentication)
Route::get('/metrics', [HealthController::class, 'metrics'])
    ->middleware(['auth:sanctum', 'role:super_admin|admin']);

// Production monitoring endpoints (requires super_admin)
Route::prefix('monitoring')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::get('/system', [MonitoringController::class, 'systemHealth']);
    Route::get('/queue', [MonitoringController::class, 'queueMetrics']);
    Route::get('/slow-queries', [MonitoringController::class, 'slowQueries']);
    Route::get('/dashboard', [MonitoringController::class, 'dashboard']);
});

// Prometheus metrics endpoint (for scraping, IP restricted in production)
Route::get('/prometheus/metrics', [MonitoringController::class, 'prometheusMetrics'])
    ->middleware(['auth:sanctum', 'role:super_admin']);

// Region lookup (Indonesian administrative regions)
Route::prefix('region')->group(function () {
    Route::get('/provinces', [RegionController::class, 'provinces']);
    Route::get('/regencies/{provinceId}', [RegionController::class, 'regencies']);
    Route::get('/districts/{regencyId}', [RegionController::class, 'districts']);
    Route::get('/villages/{districtId}', [RegionController::class, 'villages']);
});

// Xendit payment callback handled by WebhookController (see webhook routes)

// FCM Device Token Registration (mobile app push notifications)
Route::post('/device-token', [\App\Http\Controllers\Api\V1\DeviceTokenController::class, 'update'])
    ->middleware(['auth:sanctum']);
Route::delete('/device-token', [\App\Http\Controllers\Api\V1\DeviceTokenController::class, 'destroy'])
    ->middleware(['auth:sanctum']);

// General Notifications (for all authenticated users - teachers, parents, students, admins)
Route::prefix('notifications')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index']);
    Route::post('/{id}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markRead']);
    Route::post('/read-all', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllRead']);
    Route::get('/unread-count', [\App\Http\Controllers\Api\V1\NotificationController::class, 'unreadCount']);
});
