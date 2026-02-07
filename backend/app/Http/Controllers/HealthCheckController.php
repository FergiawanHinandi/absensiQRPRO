<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class HealthCheckController extends Controller
{
    /**
     * Simple health check endpoint
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'service' => config('app.name'),
            'version' => config('app.version', '1.0.0')
        ]);
    }

    /**
     * Detailed health check with dependencies
     */
    public function detailedHealth(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'memory' => $this->checkMemory(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'healthy');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toISOString(),
            'service' => config('app.name'),
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Load balancer specific health check
     */
    public function loadBalancerHealth(): JsonResponse
    {
        // Quick check for load balancer
        try {
            // Test database connection
            DB::connection()->getPdo();
            
            // Test Redis connection
            Redis::ping();
            
            return response()->json([
                'status' => 'healthy',
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'unhealthy',
                'timestamp' => now()->toISOString(),
                'error' => $e->getMessage()
            ], 503);
        }
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $responseTime = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'response_time_ms' => $responseTime,
                'connection' => config('database.default')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check Redis connectivity
     */
    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $responseTime = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'response_time_ms' => $responseTime,
                'connection' => 'redis'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check cache functionality
     */
    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            $value = 'test_value';
            
            // Test write
            Cache::put($key, $value, 10);
            
            // Test read
            $retrieved = Cache::get($key);
            
            // Cleanup
            Cache::forget($key);

            if ($retrieved === $value) {
                return [
                    'status' => 'healthy',
                    'driver' => config('cache.default')
                ];
            } else {
                return [
                    'status' => 'unhealthy',
                    'error' => 'Cache read/write test failed'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check storage accessibility
     */
    private function checkStorage(): array
    {
        try {
            $testFile = 'health_check_' . time() . '.tmp';
            $testContent = 'test';
            
            // Test write
            $written = Storage::put($testFile, $testContent);
            
            if ($written) {
                // Test read
                $retrieved = Storage::get($testFile);
                
                // Cleanup
                Storage::delete($testFile);

                if ($retrieved === $testContent) {
                    return [
                        'status' => 'healthy',
                        'disk' => config('filesystems.default')
                    ];
                }
            }

            return [
                'status' => 'unhealthy',
                'error' => 'Storage read/write test failed'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check memory usage
     */
    private function checkMemory(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        
        // Convert memory limit to bytes
        $limitBytes = $this->parseMemoryLimit($memoryLimit);
        
        $usagePercentage = ($memoryUsage / $limitBytes) * 100;

        return [
            'status' => $usagePercentage < 90 ? 'healthy' : 'unhealthy',
            'usage_bytes' => $memoryUsage,
            'limit_bytes' => $limitBytes,
            'usage_percentage' => round($usagePercentage, 2),
            'limit' => $memoryLimit
        ];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = strtolower(trim($limit));
        $multiplier = 1;

        if (str_ends_with($limit, 'g')) {
            $multiplier = 1024 * 1024 * 1024;
        } elseif (str_ends_with($limit, 'm')) {
            $multiplier = 1024 * 1024;
        } elseif (str_ends_with($limit, 'k')) {
            $multiplier = 1024;
        }

        return (int) $limit * $multiplier;
    }
}
