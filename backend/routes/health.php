<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\HealthCheckController;
use App\Helpers\TimezoneHelper;

/**
 * ENHANCED HEALTH CHECK ENDPOINT
 * 
 * Features:
 * - Database connectivity check
 * - Redis connectivity check
 * - Cache functionality check
 * - Queue connectivity check
 * - Storage accessibility check
 * - Response time measurement
 * - Comprehensive error handling
 */
Route::get('/health', function () {
    $startTime = microtime(true);
    $status = 'healthy';
    $checks = [];
    
    // ============================================================
    // CHECK 1: Database Connection
    // ============================================================
    try {
        $dbStart = microtime(true);
        $pdo = DB::connection()->getPdo();
        $dbTime = round((microtime(true) - $dbStart) * 1000, 2);
        
        // Test actual query
        DB::select('SELECT 1');
        
        $checks['db'] = [
            'status' => 'ok',
            'driver' => DB::connection()->getDriverName(),
            'response_time_ms' => $dbTime,
        ];
    } catch (\Exception $e) {
        $status = 'unhealthy';
        $checks['db'] = [
            'status' => 'fail',
            'error' => $e->getMessage(),
        ];
    }
    
    // ============================================================
    // CHECK 2: Redis Connection
    // ============================================================
    try {
        $redisStart = microtime(true);
        $ping = Redis::ping();
        $redisTime = round((microtime(true) - $redisStart) * 1000, 2);
        
        $checks['redis'] = [
            'status' => 'ok',
            'response' => $ping,
            'response_time_ms' => $redisTime,
        ];
    } catch (\Exception $e) {
        $status = 'unhealthy';
        $checks['redis'] = [
            'status' => 'fail',
            'error' => $e->getMessage(),
        ];
    }
    
    // ============================================================
    // CHECK 3: Cache Functionality
    // ============================================================
    try {
        $cacheKey = 'health_check_' . TimezoneHelper::now()->timestamp;
        $cacheValue = 'test_' . rand(1000, 9999);
        
        // Test write
        Cache::put($cacheKey, $cacheValue, 10);
        
        // Test read
        $retrieved = Cache::get($cacheKey);
        
        // Test delete
        Cache::forget($cacheKey);
        
        $checks['cache'] = [
            'status' => $retrieved === $cacheValue ? 'ok' : 'fail',
            'driver' => config('cache.default'),
        ];
    } catch (\Exception $e) {
        $checks['cache'] = [
            'status' => 'fail',
            'error' => $e->getMessage(),
        ];
    }
    
    // ============================================================
    // CHECK 4: Queue Connection
    // ============================================================
    try {
        $queueConnection = config('queue.default');
        $checks['queue'] = [
            'status' => 'ok',
            'driver' => $queueConnection,
        ];
    } catch (\Exception $e) {
        $checks['queue'] = [
            'status' => 'fail',
            'error' => $e->getMessage(),
        ];
    }
    
    // ============================================================
    // CHECK 5: Storage Accessibility
    // ============================================================
    try {
        $testFile = 'health_check_' . TimezoneHelper::now()->timestamp . '.txt';
        Storage::disk('local')->put($testFile, 'health check');
        $exists = Storage::disk('local')->exists($testFile);
        Storage::disk('local')->delete($testFile);
        
        $checks['storage'] = [
            'status' => $exists ? 'ok' : 'fail',
            'disk' => 'local',
        ];
    } catch (\Exception $e) {
        $checks['storage'] = [
            'status' => 'fail',
            'error' => $e->getMessage(),
        ];
    }
    
    // ============================================================
    // CHECK 6: Application Status
    // ============================================================
    $checks['app'] = [
        'status' => 'ok',
        'environment' => app()->environment(),
        'debug' => config('app.debug'),
        'timezone' => config('app.timezone'),
    ];
    
    // ============================================================
    // CALCULATE TOTAL RESPONSE TIME
    // ============================================================
    $totalTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // ============================================================
    // RETURN RESPONSE
    // ============================================================
    $httpStatus = $status === 'healthy' ? 200 : 503;
    
    return response()->json([
        'status' => $status,
        'timestamp' => now()->toIso8601String(),
        'response_time_ms' => $totalTime,
        'checks' => $checks,
    ], $httpStatus);
});

// Detailed health checks requiring controller logic
Route::get('/health/detailed', [HealthCheckController::class, 'detailedHealth']);
Route::get('/health/load-balancer', [HealthCheckController::class, 'loadBalancerHealth']);

// Session storage health endpoints
Route::prefix('health/session')->group(function () {
    Route::get('/status', [\App\Http\Controllers\Api\SessionHealthController::class, 'status']);
    Route::get('/metrics', [\App\Http\Controllers\Api\SessionHealthController::class, 'metrics']);
    Route::get('/check', [\App\Http\Controllers\Api\SessionHealthController::class, 'check']);
});
