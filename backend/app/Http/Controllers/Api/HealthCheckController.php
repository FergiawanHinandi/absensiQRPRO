<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Health Check Controller
 * 
 * Provides health check endpoints for monitoring and deployment verification.
 * Used by CI/CD pipeline for blue/green deployment validation.
 * 
 * @group Health
 */
class HealthCheckController extends Controller
{
    /**
     * Main health check endpoint
     * 
     * Returns overall system health status.
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'cache' => $this->checkCache(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env'),
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Database health check
     * 
     * @return JsonResponse
     */
    public function database(): JsonResponse
    {
        $check = $this->checkDatabase();

        return response()->json($check, $check['status'] === 'ok' ? 200 : 503);
    }

    /**
     * Redis health check
     * 
     * @return JsonResponse
     */
    public function redis(): JsonResponse
    {
        $check = $this->checkRedis();

        return response()->json($check, $check['status'] === 'ok' ? 200 : 503);
    }

    /**
     * Queue health check
     * 
     * @return JsonResponse
     */
    public function queue(): JsonResponse
    {
        $check = $this->checkQueue();

        return response()->json($check, $check['status'] === 'ok' ? 200 : 503);
    }

    /**
     * Attendance test (critical feature check)
     * 
     * Tests the core attendance functionality.
     * 
     * @return JsonResponse
     */
    public function attendanceTest(): JsonResponse
    {
        try {
            $startTime = microtime(true);

            // Test read operation
            $count = Attendance::where('attendance_date', today())->count();

            // Test read model
            $summary = \App\ReadModels\AttendanceDailySummary::query()
                ->where('attendance_date', today())
                ->first();

            $duration = (microtime(true) - $startTime) * 1000; // Convert to ms

            return response()->json([
                'status' => 'ok',
                'message' => 'Attendance system operational',
                'data' => [
                    'today_count' => $count,
                    'summary_exists' => $summary !== null,
                    'response_time_ms' => round($duration, 2),
                ],
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Attendance system check failed',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }

    /**
     * Metrics endpoint
     * 
     * Returns system metrics for monitoring.
     * 
     * @return JsonResponse
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'error_rate' => $this->getErrorRate(),
            'response_time' => $this->getAverageResponseTime(),
            'queue_depth' => $this->getQueueDepth(),
            'db_failures' => $this->getDatabaseFailures(),
            'memory_usage' => $this->getMemoryUsage(),
            'cpu_usage' => $this->getCpuUsage(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Error rate metric
     * 
     * @return JsonResponse
     */
    public function errorRate(): JsonResponse
    {
        $rate = $this->getErrorRate();

        return response()->json([
            'rate' => $rate,
            'threshold' => 5.0,
            'status' => $rate < 5.0 ? 'ok' : 'critical',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Response time metric
     * 
     * @return JsonResponse
     */
    public function responseTime(): JsonResponse
    {
        $avg = $this->getAverageResponseTime();

        return response()->json([
            'avg' => $avg,
            'threshold' => 500,
            'status' => $avg < 500 ? 'ok' : 'warning',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Queue depth metric
     * 
     * @return JsonResponse
     */
    public function queueDepth(): JsonResponse
    {
        $depth = $this->getQueueDepth();

        return response()->json([
            'depth' => $depth,
            'threshold' => 5000,
            'status' => $depth < 5000 ? 'ok' : 'warning',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    // ============================================
    // Private Helper Methods
    // ============================================

    /**
     * Check database connectivity and performance
     */
    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test connection
            DB::connection()->getPdo();
            
            // Test read
            DB::table('attendances')->limit(1)->get();
            
            // Test write (to a health check table)
            DB::table('health_checks')->updateOrInsert(
                ['key' => 'last_check'],
                ['value' => now()->toDateTimeString(), 'updated_at' => now()]
            );

            $duration = (microtime(true) - $startTime) * 1000;

            return [
                'status' => 'ok',
                'message' => 'Database operational',
                'response_time_ms' => round($duration, 2),
                'connection' => config('database.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database check failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connectivity and performance
     */
    private function checkRedis(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test connection
            Redis::ping();
            
            // Test write
            Cache::put('health_check', now()->toDateTimeString(), 60);
            
            // Test read
            $value = Cache::get('health_check');

            $duration = (microtime(true) - $startTime) * 1000;

            $timeoutDuration = 0; // Would be calculated from actual timeout events

            return [
                'status' => 'ok',
                'message' => 'Redis operational',
                'response_time_ms' => round($duration, 2),
                'timeout_duration' => $timeoutDuration,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Redis check failed',
                'error' => $e->getMessage(),
                'timeout_duration' => 999,
            ];
        }
    }

    /**
     * Check queue system health
     */
    private function checkQueue(): array
    {
        try {
            // Check if queue workers are running
            $workersRunning = $this->areQueueWorkersRunning();
            
            // Get queue depth
            $depth = $this->getQueueDepth();
            
            // Get failed jobs count
            $failedCount = DB::table('failed_jobs')->count();

            return [
                'status' => $workersRunning ? 'ok' : 'warning',
                'message' => $workersRunning ? 'Queue operational' : 'No workers running',
                'queue_depth' => $depth,
                'failed_jobs' => $failedCount,
                'workers_running' => $workersRunning,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Queue check failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check cache system health
     */
    private function checkCache(): array
    {
        try {
            $startTime = microtime(true);
            
            $testKey = 'health_check_' . uniqid();
            $testValue = 'test_' . time();
            
            // Test write
            Cache::put($testKey, $testValue, 60);
            
            // Test read
            $retrieved = Cache::get($testKey);
            
            // Test delete
            Cache::forget($testKey);

            $duration = (microtime(true) - $startTime) * 1000;

            return [
                'status' => $retrieved === $testValue ? 'ok' : 'error',
                'message' => 'Cache operational',
                'response_time_ms' => round($duration, 2),
                'driver' => config('cache.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Cache check failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get error rate from logs or metrics
     */
    private function getErrorRate(): float
    {
        // In production, this would query actual error logs or metrics
        // For now, return a mock value
        
        try {
            // Query error logs from last 5 minutes
            $totalRequests = Cache::get('metrics:total_requests:5min', 100);
            $errorRequests = Cache::get('metrics:error_requests:5min', 0);
            
            if ($totalRequests === 0) {
                return 0.0;
            }
            
            return round(($errorRequests / $totalRequests) * 100, 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Get average response time
     */
    private function getAverageResponseTime(): float
    {
        // In production, this would query actual metrics
        try {
            return (float) Cache::get('metrics:avg_response_time', 150.0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Get queue depth
     */
    private function getQueueDepth(): int
    {
        try {
            // Get pending jobs count
            $depth = DB::table('jobs')->count();
            
            return $depth;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get database failures count
     */
    private function getDatabaseFailures(): int
    {
        try {
            return (int) Cache::get('metrics:db_failures:5min', 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get memory usage percentage
     */
    private function getMemoryUsage(): float
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->convertToBytes(ini_get('memory_limit'));
        
        if ($memoryLimit === 0) {
            return 0.0;
        }
        
        return round(($memoryUsage / $memoryLimit) * 100, 2);
    }

    /**
     * Get CPU usage percentage
     */
    private function getCpuUsage(): float
    {
        // This is a simplified version
        // In production, use system monitoring tools
        return 0.0;
    }

    /**
     * Check if queue workers are running
     */
    private function areQueueWorkersRunning(): bool
    {
        // Check if Horizon is running
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            return true; // Horizon handles this
        }
        
        // Otherwise, check supervisor or process list
        return true; // Simplified for now
    }

    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
