<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\HealthCheckJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * System Health Check Controller
 * 
 * Used by Route 53 for multi-region failover decisions
 * 
 * @package App\Http\Controllers\Api
 */
class SystemHealthController extends Controller
{
    /**
     * Comprehensive system health check
     * 
     * Returns 200 if all systems healthy, 503 if any system fails
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $startTime = microtime(true);

        $checks = [
            'db' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'disk' => $this->checkDisk(),
        ];

        $responseTime = round((microtime(true) - $startTime) * 1000, 2);

        $allHealthy = collect($checks)->every(fn($status) => $status === 'ok');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'region' => config('app.region', 'unknown'),
            'checks' => $checks,
            'response_time_ms' => $responseTime,
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '1.0.0'),
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Database health check
     * 
     * Tests both read and write operations
     * 
     * @return string
     */
    private function checkDatabase(): string
    {
        try {
            $startTime = microtime(true);

            // Test connection
            DB::connection()->getPdo();

            // Test read
            $result = DB::table('health_checks')
                ->where('key', 'health_check')
                ->first();

            // Test write
            DB::table('health_checks')->updateOrInsert(
                ['key' => 'health_check'],
                [
                    'value' => now()->toDateTimeString(),
                    'updated_at' => now(),
                ]
            );

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Alert if slow
            if ($responseTime > 100) {
                Log::warning('Database health check slow', [
                    'response_time_ms' => $responseTime,
                ]);
            }

            return 'ok';
        } catch (\Exception $e) {
            Log::error('Database health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 'error';
        }
    }

    /**
     * Redis health check
     * 
     * Tests connection, write, and read operations
     * 
     * @return string
     */
    private function checkRedis(): string
    {
        try {
            $startTime = microtime(true);

            // Test connection
            Redis::ping();

            // Test write
            $testKey = 'health_check:' . now()->timestamp;
            $testValue = now()->toDateTimeString();
            Cache::put($testKey, $testValue, 60);

            // Test read
            $value = Cache::get($testKey);

            // Cleanup
            Cache::forget($testKey);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Alert if slow
            if ($responseTime > 50) {
                Log::warning('Redis health check slow', [
                    'response_time_ms' => $responseTime,
                ]);
            }

            return $value === $testValue ? 'ok' : 'error';
        } catch (\Exception $e) {
            Log::error('Redis health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 'error';
        }
    }

    /**
     * Queue health check
     * 
     * Tests queue push operation
     * 
     * @return string
     */
    private function checkQueue(): string
    {
        try {
            // Test queue push
            Queue::push(new HealthCheckJob());

            return 'ok';
        } catch (\Exception $e) {
            Log::error('Queue health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 'error';
        }
    }

    /**
     * Storage health check
     * 
     * Tests S3 write and read operations
     * 
     * @return string
     */
    private function checkStorage(): string
    {
        try {
            $startTime = microtime(true);

            $testFile = 'health_check/' . now()->timestamp . '.txt';
            $testContent = now()->toDateTimeString();

            // Test write
            Storage::put($testFile, $testContent);

            // Test read
            $content = Storage::get($testFile);

            // Cleanup
            Storage::delete($testFile);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Alert if slow
            if ($responseTime > 200) {
                Log::warning('Storage health check slow', [
                    'response_time_ms' => $responseTime,
                ]);
            }

            return $content === $testContent ? 'ok' : 'error';
        } catch (\Exception $e) {
            Log::error('Storage health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 'error';
        }
    }

    /**
     * Disk health check
     * 
     * Checks disk usage and alerts if critical
     * 
     * @return string
     */
    private function checkDisk(): string
    {
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');

            if ($diskFree === false || $diskTotal === false) {
                return 'error';
            }

            $diskUsagePercent = (1 - ($diskFree / $diskTotal)) * 100;

            // Critical threshold: 95%
            if ($diskUsagePercent > 95) {
                Log::critical('Disk usage critical', [
                    'usage_percent' => round($diskUsagePercent, 2),
                    'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                    'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                ]);

                return 'critical';
            }

            // Warning threshold: 90%
            if ($diskUsagePercent > 90) {
                Log::warning('Disk usage high', [
                    'usage_percent' => round($diskUsagePercent, 2),
                    'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                    'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                ]);

                return 'warning';
            }

            return 'ok';
        } catch (\Exception $e) {
            Log::error('Disk health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 'error';
        }
    }

    /**
     * Detailed health check for monitoring
     * 
     * Returns more detailed information for internal monitoring
     * 
     * @return JsonResponse
     */
    public function detailed(): JsonResponse
    {
        $startTime = microtime(true);

        $checks = [
            'database' => $this->detailedDatabaseCheck(),
            'redis' => $this->detailedRedisCheck(),
            'queue' => $this->detailedQueueCheck(),
            'storage' => $this->detailedStorageCheck(),
            'disk' => $this->detailedDiskCheck(),
        ];

        $responseTime = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'status' => 'ok',
            'region' => config('app.region', 'unknown'),
            'checks' => $checks,
            'response_time_ms' => $responseTime,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function detailedDatabaseCheck(): array
    {
        try {
            $startTime = microtime(true);

            // Connection info
            $pdo = DB::connection()->getPdo();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            // Get connection count
            $connections = DB::select("SHOW STATUS LIKE 'Threads_connected'");
            $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");

            return [
                'status' => 'ok',
                'response_time_ms' => $responseTime,
                'connections' => $connections[0]->Value ?? 'unknown',
                'max_connections' => $maxConnections[0]->Value ?? 'unknown',
                'driver' => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function detailedRedisCheck(): array
    {
        try {
            $startTime = microtime(true);

            // Get Redis info
            $info = Redis::info();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'ok',
                'response_time_ms' => $responseTime,
                'connected_clients' => $info['connected_clients'] ?? 'unknown',
                'used_memory_human' => $info['used_memory_human'] ?? 'unknown',
                'uptime_in_seconds' => $info['uptime_in_seconds'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function detailedQueueCheck(): array
    {
        try {
            // Get queue size
            $queueSize = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            return [
                'status' => 'ok',
                'queue_depth' => $queueSize,
                'failed_jobs' => $failedJobs,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function detailedStorageCheck(): array
    {
        try {
            $startTime = microtime(true);

            // Test S3 connection
            $files = Storage::files('health_check');
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'ok',
                'response_time_ms' => $responseTime,
                'driver' => config('filesystems.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function detailedDiskCheck(): array
    {
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');

            if ($diskFree === false || $diskTotal === false) {
                return [
                    'status' => 'error',
                    'error' => 'Unable to get disk space',
                ];
            }

            $diskUsagePercent = (1 - ($diskFree / $diskTotal)) * 100;

            return [
                'status' => 'ok',
                'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                'usage_percent' => round($diskUsagePercent, 2),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
}
