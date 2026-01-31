<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function check(Request $request)
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'queue_jobs' => $this->checkQueueJobs(),
            'disk_space' => $this->checkDiskSpace(),
            'memory' => $this->checkMemory(),
        ];

        $allHealthy = collect($checks)->every(fn ($check) => in_array($check['status'], ['ok', 'warning']));

        $response = [
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toISOString(),
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env'),
            'app_name' => config('app.name'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'uptime' => $this->getUptime(),
            'checks' => $checks,
        ];

        return response()->json($response, $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);

            // Test basic connection
            DB::select('SELECT 1');

            // Test table access
            $userCount = DB::table('users')->count();
            $schoolCount = DB::table('schools')->count();

            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'ok',
                'response_time_ms' => $duration,
                'connection' => config('database.default'),
                'driver' => config('database.connections.'.config('database.default').'.driver'),
                'stats' => [
                    'users' => $userCount,
                    'schools' => $schoolCount,
                ],
                'message' => 'Database connection and queries successful',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'connection' => config('database.default'),
                'message' => 'Database connection failed: '.$e->getMessage(),
            ];
        }
    }

    private function checkRedis(): array
    {
        try {
            if (! extension_loaded('redis')) {
                return [
                    'status' => 'warning',
                    'message' => 'Redis extension not loaded',
                ];
            }

            // Check if Redis is configured and running
            $redisHost = config('database.redis.default.host');
            $redisPort = config('database.redis.default.port');
            
            // Quick connection test with timeout
            $timeout = 2; // 2 seconds timeout
            $socket = @fsockopen($redisHost, $redisPort, $errno, $errstr, $timeout);
            
            if (!$socket) {
                return [
                    'status' => 'warning',
                    'message' => "Redis server not running at {$redisHost}:{$redisPort}",
                    'connection' => "{$redisHost}:{$redisPort}",
                ];
            }
            fclose($socket);

            $start = microtime(true);

            // Test Redis connection
            $redis = Redis::connection();
            $redis->ping();

            // Test read/write
            $key = 'health_check_redis_'.time();
            $value = 'test_value_'.uniqid();

            $redis->set($key, $value, 'EX', 60);
            $retrieved = $redis->get($key);
            $redis->del($key);

            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($retrieved === $value) {
                return [
                    'status' => 'ok',
                    'response_time_ms' => $duration,
                    'connection' => config('database.redis.default.host').':'.config('database.redis.default.port'),
                    'message' => 'Redis connection and operations successful',
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Redis read/write test failed',
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'warning', // Changed from error to warning
                'message' => 'Redis not available: '.$e->getMessage(),
                'note' => 'Application will continue using database cache',
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $start = microtime(true);
            $key = 'health_check_cache_'.time();
            $value = 'test_value_'.uniqid();

            Cache::put($key, $value, 60);
            $retrieved = Cache::get($key);
            Cache::forget($key);

            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($retrieved === $value) {
                return [
                    'status' => 'ok',
                    'response_time_ms' => $duration,
                    'driver' => config('cache.default'),
                    'message' => 'Cache working properly',
                ];
            } else {
                return [
                    'status' => 'error',
                    'driver' => config('cache.default'),
                    'message' => 'Cache read/write failed',
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'driver' => config('cache.default'),
                'message' => 'Cache error: '.$e->getMessage(),
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $start = microtime(true);
            $path = storage_path('app/health_check_'.time().'.txt');
            $content = 'Health check at '.now()->toISOString();

            file_put_contents($path, $content);
            $read = file_get_contents($path);
            unlink($path);

            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'ok',
                'response_time_ms' => $duration,
                'path' => storage_path('app'),
                'writable' => is_writable(storage_path('app')),
                'message' => 'Storage read/write successful',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'path' => storage_path('app'),
                'writable' => is_writable(storage_path('app')),
                'message' => 'Storage error: '.$e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');
            $driver = config("queue.connections.{$connection}.driver");

            // Test queue connection
            $queueManager = Queue::connection($connection);

            return [
                'status' => 'ok',
                'connection' => $connection,
                'driver' => $driver,
                'message' => 'Queue connection available',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'connection' => config('queue.default'),
                'message' => 'Queue error: '.$e->getMessage(),
            ];
        }
    }

    private function checkQueueJobs(): array
    {
        try {
            $connection = config('queue.default');
            $pendingJobs = 0;
            $failedJobs = 0;

            // Check pending jobs count
            if ($connection === 'database') {
                $pendingJobs = DB::table('jobs')->count();
                $failedJobs = DB::table('failed_jobs')->count();
            } elseif ($connection === 'redis') {
                try {
                    $redis = Redis::connection('default');
                    $queueName = config('queue.connections.redis.queue', 'default');
                    $pendingJobs = $redis->llen("queues:{$queueName}");

                    // Check failed jobs if using Redis
                    $failedJobs = $redis->llen("queues:{$queueName}:failed") ?? 0;
                } catch (\Exception $e) {
                    // If Redis fails, still return info but with warning
                    return [
                        'status' => 'warning',
                        'connection' => $connection,
                        'message' => 'Could not check Redis queue stats: '.$e->getMessage(),
                    ];
                }
            }

            $status = 'ok';
            $message = 'Queue jobs checked successfully';

            // Warning if too many pending jobs
            if ($pendingJobs > 100) {
                $status = 'warning';
                $message = 'High number of pending jobs detected';
            }

            // Error if too many failed jobs
            if ($failedJobs > 50) {
                $status = 'error';
                $message = 'High number of failed jobs detected';
            }

            return [
                'status' => $status,
                'connection' => $connection,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'message' => $message,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'connection' => config('queue.default'),
                'message' => 'Queue jobs check error: '.$e->getMessage(),
            ];
        }
    }

    private function checkDiskSpace(): array
    {
        try {
            $path = storage_path();
            $totalBytes = disk_total_space($path);
            $freeBytes = disk_free_space($path);
            $usedBytes = $totalBytes - $freeBytes;
            $usedPercent = round(($usedBytes / $totalBytes) * 100, 2);

            $status = 'ok';
            $message = 'Disk space is adequate';

            if ($usedPercent > 90) {
                $status = 'error';
                $message = 'Disk space critically low';
            } elseif ($usedPercent > 80) {
                $status = 'warning';
                $message = 'Disk space running low';
            }

            return [
                'status' => $status,
                'path' => $path,
                'total_gb' => round($totalBytes / 1024 / 1024 / 1024, 2),
                'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
                'used_gb' => round($usedBytes / 1024 / 1024 / 1024, 2),
                'used_percent' => $usedPercent,
                'message' => $message,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Disk space check error: '.$e->getMessage(),
            ];
        }
    }

    private function checkMemory(): array
    {
        try {
            $memoryLimit = ini_get('memory_limit');
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);

            // Convert memory limit to bytes
            $memoryLimitBytes = $this->convertToBytes($memoryLimit);
            $usedPercent = $memoryLimitBytes > 0 ? round(($memoryUsage / $memoryLimitBytes) * 100, 2) : 0;

            $status = 'ok';
            $message = 'Memory usage is normal';

            if ($usedPercent > 90) {
                $status = 'error';
                $message = 'Memory usage critically high';
            } elseif ($usedPercent > 80) {
                $status = 'warning';
                $message = 'Memory usage is high';
            }

            return [
                'status' => $status,
                'limit' => $memoryLimit,
                'current_mb' => round($memoryUsage / 1024 / 1024, 2),
                'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
                'used_percent' => $usedPercent,
                'message' => $message,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Memory check error: '.$e->getMessage(),
            ];
        }
    }

    private function getUptime(): array
    {
        try {
            $uptimeFile = storage_path('app/uptime.txt');

            if (! file_exists($uptimeFile)) {
                file_put_contents($uptimeFile, now()->timestamp);
            }

            $startTime = (int) file_get_contents($uptimeFile);
            $uptime = now()->timestamp - $startTime;

            return [
                'seconds' => $uptime,
                'human' => $this->formatUptime($uptime),
                'started_at' => date('Y-m-d H:i:s', $startTime),
            ];
        } catch (\Exception $e) {
            return [
                'seconds' => 0,
                'human' => 'Unknown',
                'error' => $e->getMessage(),
            ];
        }
    }

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

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($seconds > 0 || empty($parts)) {
            $parts[] = "{$seconds}s";
        }

        return implode(' ', $parts);
    }
}
