<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MonitoringController;
// use App\Http\Controllers\Api\V1\RegionController; // TODO: Create controller if needed
// use App\Http\Controllers\Api\V1\CallbackController; // TODO: Create controller if needed

Route::get('/health', [HealthController::class, 'check']);

// Redis-specific health check endpoint
Route::get('/health/redis', [HealthController::class, 'redis']);

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

// TODO: Uncomment when RegionController is created
// Route::prefix('region')->group(function () {
//     Route::get('/provinces', [RegionController::class, 'provinces']);
//     Route::get('/regencies/{provinceId}', [RegionController::class, 'regencies']);
//     Route::get('/districts/{regencyId}', [RegionController::class, 'districts']);
//     Route::get('/villages/{districtId}', [RegionController::class, 'villages']);
// });

// TODO: Uncomment when CallbackController is created
// Route::post('/callback/xendit', [CallbackController::class, 'xendit']);
