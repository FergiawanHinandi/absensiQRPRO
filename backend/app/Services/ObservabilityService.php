<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * ObservabilityService - Enterprise Monitoring & Metrics
 * 
 * Provides:
 * - Response time tracking and alerting
 * - Error rate monitoring with threshold alerts
 * - Slow query aggregation
 * - System health metrics collection
 * 
 * @version 1.0.0
 */
class ObservabilityService
{
    /**
     * Response time threshold for alerts (milliseconds)
     */
    private const SLOW_RESPONSE_THRESHOLD_MS = 2000;

    /**
     * Error rate threshold for alerts (percentage)
     */
    private const ERROR_RATE_THRESHOLD = 5.0;

    /**
     * Time window for error rate calculation (seconds)
     */
    private const ERROR_RATE_WINDOW = 300; // 5 minutes

    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'observability:';

    /**
     * Record a request's response time
     */
    public function recordResponseTime(string $endpoint, float $durationMs, int $statusCode): void
    {
        $key = self::CACHE_PREFIX . 'response_times:' . date('Y-m-d-H');
        
        $record = [
            'endpoint' => $endpoint,
            'duration_ms' => $durationMs,
            'status_code' => $statusCode,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            // Store in cache for aggregation
            $existing = Cache::get($key, []);
            $existing[] = $record;
            
            // Keep only last 1000 records per hour
            if (count($existing) > 1000) {
                $existing = array_slice($existing, -1000);
            }
            
            Cache::put($key, $existing, 7200); // 2 hours TTL

            // Alert if response time exceeds threshold
            if ($durationMs > self::SLOW_RESPONSE_THRESHOLD_MS) {
                $this->triggerSlowResponseAlert($endpoint, $durationMs, $statusCode);
            }
        } catch (\Exception $e) {
            Log::channel('system')->warning('Failed to record response time', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an error occurrence
     */
    public function recordError(string $endpoint, int $statusCode, ?string $errorMessage = null): void
    {
        $windowKey = self::CACHE_PREFIX . 'errors:' . floor(time() / 60); // Per-minute bucket
        $totalKey = self::CACHE_PREFIX . 'total_requests:' . floor(time() / 60);

        try {
            // Increment error count
            $errorCount = Cache::increment($windowKey);
            if ($errorCount === 1) {
                Cache::put($windowKey, 1, self::ERROR_RATE_WINDOW + 60);
            }

            // Log the error
            Log::channel('system')->error('Request Error Recorded', [
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'error' => $errorMessage,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Check error rate
            $this->checkErrorRateThreshold();
        } catch (\Exception $e) {
            Log::channel('system')->warning('Failed to record error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record total request count for error rate calculation
     */
    public function recordRequest(): void
    {
        $key = self::CACHE_PREFIX . 'total_requests:' . floor(time() / 60);
        
        try {
            $count = Cache::increment($key);
            if ($count === 1) {
                Cache::put($key, 1, self::ERROR_RATE_WINDOW + 60);
            }
        } catch (\Exception $e) {
            // Silently fail - don't break the request
        }
    }

    /**
     * Get current error rate percentage
     */
    public function getErrorRate(): float
    {
        $totalErrors = 0;
        $totalRequests = 0;
        $windowMinutes = self::ERROR_RATE_WINDOW / 60;
        $currentMinute = floor(time() / 60);

        try {
            for ($i = 0; $i < $windowMinutes; $i++) {
                $minute = $currentMinute - $i;
                $errorKey = self::CACHE_PREFIX . 'errors:' . $minute;
                $totalKey = self::CACHE_PREFIX . 'total_requests:' . $minute;
                
                $totalErrors += (int) Cache::get($errorKey, 0);
                $totalRequests += (int) Cache::get($totalKey, 0);
            }

            if ($totalRequests === 0) {
                return 0.0;
            }

            return round(($totalErrors / $totalRequests) * 100, 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Get response time statistics
     */
    public function getResponseTimeStats(): array
    {
        $key = self::CACHE_PREFIX . 'response_times:' . date('Y-m-d-H');
        $records = Cache::get($key, []);

        if (empty($records)) {
            return [
                'count' => 0,
                'avg_ms' => 0,
                'min_ms' => 0,
                'max_ms' => 0,
                'p95_ms' => 0,
                'p99_ms' => 0,
                'slow_count' => 0,
            ];
        }

        $durations = array_column($records, 'duration_ms');
        sort($durations);

        $count = count($durations);
        $slowCount = count(array_filter($durations, fn($d) => $d > self::SLOW_RESPONSE_THRESHOLD_MS));

        return [
            'count' => $count,
            'avg_ms' => round(array_sum($durations) / $count, 2),
            'min_ms' => round(min($durations), 2),
            'max_ms' => round(max($durations), 2),
            'p95_ms' => round($durations[(int) floor($count * 0.95)] ?? 0, 2),
            'p99_ms' => round($durations[(int) floor($count * 0.99)] ?? 0, 2),
            'slow_count' => $slowCount,
            'slow_threshold_ms' => self::SLOW_RESPONSE_THRESHOLD_MS,
        ];
    }

    /**
     * Get slow queries from the last hour
     */
    public function getSlowQueries(): array
    {
        $key = self::CACHE_PREFIX . 'slow_queries:' . date('Y-m-d-H');
        return Cache::get($key, []);
    }

    /**
     * Record a slow query
     */
    public function recordSlowQuery(string $sql, float $durationMs, string $connection): void
    {
        $key = self::CACHE_PREFIX . 'slow_queries:' . date('Y-m-d-H');
        
        try {
            $existing = Cache::get($key, []);
            $existing[] = [
                'sql' => substr($sql, 0, 500),
                'duration_ms' => $durationMs,
                'connection' => $connection,
                'timestamp' => now()->toIso8601String(),
            ];
            
            // Keep only last 100 slow queries per hour
            if (count($existing) > 100) {
                $existing = array_slice($existing, -100);
            }
            
            Cache::put($key, $existing, 7200);
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Get comprehensive observability metrics
     */
    public function getMetrics(): array
    {
        return [
            'error_rate' => [
                'current_percent' => $this->getErrorRate(),
                'threshold_percent' => self::ERROR_RATE_THRESHOLD,
                'window_seconds' => self::ERROR_RATE_WINDOW,
                'status' => $this->getErrorRate() > self::ERROR_RATE_THRESHOLD ? 'alert' : 'ok',
            ],
            'response_time' => $this->getResponseTimeStats(),
            'slow_queries' => [
                'count' => count($this->getSlowQueries()),
                'last_hour' => array_slice($this->getSlowQueries(), -10),
            ],
            'queue_health' => $this->getQueueHealth(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get queue health metrics
     */
    public function getQueueHealth(): array
    {
        $connection = config('queue.default');
        
        try {
            $pendingJobs = 0;
            $failedJobs = 0;
            $processedLastHour = 0;

            if ($connection === 'database') {
                $pendingJobs = DB::table('jobs')->count();
                $failedJobs = DB::table('failed_jobs')->count();
                $processedLastHour = DB::table('jobs')
                    ->where('created_at', '>=', now()->subHour())
                    ->count();
            } elseif ($connection === 'redis') {
                $redis = Redis::connection('default');
                $queueName = config('queue.connections.redis.queue', 'default');
                $pendingJobs = (int) $redis->llen("queues:{$queueName}");
            }

            // Check if workers are running
            $workersRunning = $this->checkQueueWorkers();

            return [
                'connection' => $connection,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'processed_last_hour' => $processedLastHour,
                'workers_running' => $workersRunning,
                'status' => $workersRunning && $failedJobs < 50 ? 'ok' : 'warning',
            ];
        } catch (\Exception $e) {
            return [
                'connection' => $connection,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if queue workers are running
     */
    public function checkQueueWorkers(): bool
    {
        // Check via heartbeat key
        $heartbeatKey = self::CACHE_PREFIX . 'queue_worker_heartbeat';
        $lastHeartbeat = Cache::get($heartbeatKey);
        
        if ($lastHeartbeat) {
            $lastTime = strtotime($lastHeartbeat);
            // Worker is considered running if heartbeat within last 2 minutes
            return (time() - $lastTime) < 120;
        }

        // Fallback: Check process on Linux
        if (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec('pgrep -f "artisan queue:work" 2>/dev/null');
            return !empty(trim($output ?? ''));
        }

        // Windows: Check for PHP processes running artisan
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" 2>nul');
            return strpos($output ?? '', 'php.exe') !== false;
        }

        return false;
    }

    /**
     * Record queue worker heartbeat (called from queue worker)
     */
    public function recordWorkerHeartbeat(): void
    {
        $key = self::CACHE_PREFIX . 'queue_worker_heartbeat';
        Cache::put($key, now()->toIso8601String(), 300);
    }

    /**
     * Trigger alert for slow response
     */
    private function triggerSlowResponseAlert(string $endpoint, float $durationMs, int $statusCode): void
    {
        Log::channel('system')->warning('Slow Response Detected', [
            'alert_type' => 'slow_response',
            'endpoint' => $endpoint,
            'duration_ms' => $durationMs,
            'threshold_ms' => self::SLOW_RESPONSE_THRESHOLD_MS,
            'status_code' => $statusCode,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Dispatch event for external monitoring integration
        event(new \App\Events\SlowResponseDetected($endpoint, $durationMs, $statusCode));
    }

    /**
     * Check if error rate exceeds threshold and trigger alert
     */
    private function checkErrorRateThreshold(): void
    {
        $errorRate = $this->getErrorRate();
        
        if ($errorRate > self::ERROR_RATE_THRESHOLD) {
            // Rate limit alerts to once per 5 minutes
            $alertKey = self::CACHE_PREFIX . 'error_rate_alert_sent';
            
            if (!Cache::has($alertKey)) {
                Log::channel('system')->critical('High Error Rate Alert', [
                    'alert_type' => 'high_error_rate',
                    'error_rate_percent' => $errorRate,
                    'threshold_percent' => self::ERROR_RATE_THRESHOLD,
                    'window_seconds' => self::ERROR_RATE_WINDOW,
                    'timestamp' => now()->toIso8601String(),
                ]);

                // Dispatch event for external monitoring
                event(new \App\Events\HighErrorRateDetected($errorRate, self::ERROR_RATE_THRESHOLD));
                
                Cache::put($alertKey, true, 300); // 5 minute cooldown
            }
        }
    }
}
