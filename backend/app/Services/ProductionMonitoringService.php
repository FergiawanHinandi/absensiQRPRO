<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Production Monitoring Service
 * 
 * Centralized monitoring for production observability:
 * - System health metrics
 * - Slow query aggregation
 * - Queue health monitoring
 * - Alert thresholds
 * - Early warning detection
 */
class ProductionMonitoringService
{
    /**
     * Cache keys for metrics storage
     */
    private const SLOW_QUERIES_KEY = 'monitoring:slow_queries';
    private const FAILED_JOBS_KEY = 'monitoring:failed_jobs';
    private const ERROR_COUNTS_KEY = 'monitoring:error_counts';
    private const METRICS_KEY = 'monitoring:metrics';
    private const ALERTS_KEY = 'monitoring:alerts';

    /**
     * Thresholds for alerting
     */
    private const THRESHOLDS = [
        'slow_query_count_per_minute' => 10,
        'failed_jobs_per_hour' => 5,
        'error_rate_per_minute' => 20,
        'memory_usage_percent' => 85,
        'disk_usage_percent' => 90,
        'response_time_p95_ms' => 500,
        'queue_backlog_size' => 1000,
    ];

    /**
     * Record a slow query for aggregation
     */
    public function recordSlowQuery(string $sql, float $durationMs, string $connection = 'default'): void
    {
        try {
            $key = self::SLOW_QUERIES_KEY . ':' . now()->format('Y-m-d-H-i');
            
            $queryData = [
                'sql' => $this->truncateSql($sql),
                'duration_ms' => round($durationMs, 2),
                'connection' => $connection,
                'timestamp' => now()->toIso8601String(),
            ];

            // Store in Redis for real-time metrics
            $existing = Cache::get($key, []);
            $existing[] = $queryData;
            Cache::put($key, $existing, now()->addHours(1));

            // Check threshold and alert
            if (count($existing) >= self::THRESHOLDS['slow_query_count_per_minute']) {
                $this->triggerAlert('slow_queries', [
                    'count' => count($existing),
                    'threshold' => self::THRESHOLDS['slow_query_count_per_minute'],
                    'sample_queries' => array_slice($existing, -3),
                ]);
            }
        } catch (\Exception $e) {
            // Silently fail - monitoring should not break the app
            Log::debug('Failed to record slow query metric', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a failed job
     */
    public function recordFailedJob(string $jobName, string $exception, array $context = []): void
    {
        try {
            $key = self::FAILED_JOBS_KEY . ':' . now()->format('Y-m-d-H');
            
            $jobData = [
                'job' => $jobName,
                'exception' => $exception,
                'context' => $context,
                'timestamp' => now()->toIso8601String(),
            ];

            $existing = Cache::get($key, []);
            $existing[] = $jobData;
            Cache::put($key, $existing, now()->addHours(24));

            // Check threshold and alert
            if (count($existing) >= self::THRESHOLDS['failed_jobs_per_hour']) {
                $this->triggerAlert('failed_jobs', [
                    'count' => count($existing),
                    'threshold' => self::THRESHOLDS['failed_jobs_per_hour'],
                    'recent_failures' => array_slice($existing, -3),
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to record failed job metric', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record an error occurrence
     */
    public function recordError(string $type, string $message, array $context = []): void
    {
        try {
            $key = self::ERROR_COUNTS_KEY . ':' . now()->format('Y-m-d-H-i');
            
            $errorData = [
                'type' => $type,
                'message' => $this->truncateMessage($message),
                'context' => array_slice($context, 0, 10), // Limit context size
                'timestamp' => now()->toIso8601String(),
            ];

            $existing = Cache::get($key, []);
            $existing[] = $errorData;
            Cache::put($key, $existing, now()->addHours(1));

            // Check threshold and alert
            if (count($existing) >= self::THRESHOLDS['error_rate_per_minute']) {
                $this->triggerAlert('high_error_rate', [
                    'count' => count($existing),
                    'threshold' => self::THRESHOLDS['error_rate_per_minute'],
                    'sample_errors' => array_slice($existing, -3),
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to record error metric', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record response time metric
     */
    public function recordResponseTime(string $endpoint, float $durationMs): void
    {
        try {
            $key = self::METRICS_KEY . ':response_times:' . now()->format('Y-m-d-H');
            
            $existing = Cache::get($key, []);
            $existing[] = [
                'endpoint' => $endpoint,
                'duration_ms' => round($durationMs, 2),
                'timestamp' => now()->toIso8601String(),
            ];
            Cache::put($key, $existing, now()->addHours(24));
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Get comprehensive system health
     */
    public function getSystemHealth(): array
    {
        return [
            'database' => $this->checkDatabaseHealth(),
            'redis' => $this->checkRedisHealth(),
            'queue' => $this->checkQueueHealth(),
            'memory' => $this->checkMemoryHealth(),
            'disk' => $this->checkDiskHealth(),
            'recent_alerts' => $this->getRecentAlerts(),
            'metrics_summary' => $this->getMetricsSummary(),
        ];
    }

    /**
     * Get queue monitoring data
     */
    public function getQueueMetrics(): array
    {
        try {
            $connection = config('queue.default');
            $metrics = [
                'connection' => $connection,
                'queues' => [],
                'failed_jobs' => $this->getFailedJobsCount(),
                'throughput' => $this->getQueueThroughput(),
                'memory_usage' => memory_get_usage(true),
            ];

            if ($connection === 'redis') {
                $metrics['queues'] = $this->getRedisQueueMetrics();
            } elseif ($connection === 'database') {
                $metrics['queues'] = $this->getDatabaseQueueMetrics();
            }

            return $metrics;
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'connection' => config('queue.default'),
            ];
        }
    }

    /**
     * Get slow query statistics
     */
    public function getSlowQueryStats(int $hours = 1): array
    {
        try {
            $queries = [];
            $now = now();

            for ($i = 0; $i < $hours * 60; $i++) {
                $key = self::SLOW_QUERIES_KEY . ':' . $now->copy()->subMinutes($i)->format('Y-m-d-H-i');
                $minuteData = Cache::get($key, []);
                $queries = array_merge($queries, $minuteData);
            }

            return [
                'total_count' => count($queries),
                'period_hours' => $hours,
                'avg_duration_ms' => count($queries) > 0 
                    ? round(array_sum(array_column($queries, 'duration_ms')) / count($queries), 2) 
                    : 0,
                'max_duration_ms' => count($queries) > 0 
                    ? max(array_column($queries, 'duration_ms')) 
                    : 0,
                'recent_queries' => array_slice($queries, -20),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Trigger an alert
     */
    protected function triggerAlert(string $type, array $data): void
    {
        $alertKey = self::ALERTS_KEY . ':' . $type . ':' . now()->format('Y-m-d-H');
        
        // Rate limit alerts (max 1 per type per hour)
        if (Cache::has($alertKey)) {
            return;
        }

        $alert = [
            'type' => $type,
            'severity' => $this->getAlertSeverity($type),
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ];

        // Log the alert
        Log::channel('system')->warning('Production Alert Triggered', $alert);

        // Store alert
        Cache::put($alertKey, $alert, now()->addHours(1));

        // Store in alerts list
        $allAlerts = Cache::get(self::ALERTS_KEY . ':recent', []);
        array_unshift($allAlerts, $alert);
        $allAlerts = array_slice($allAlerts, 0, 100); // Keep last 100
        Cache::put(self::ALERTS_KEY . ':recent', $allAlerts, now()->addDays(7));

        // Dispatch notification if critical
        if ($alert['severity'] === 'critical') {
            $this->sendCriticalAlert($alert);
        }
    }

    /**
     * Send critical alert (webhook, email, etc.)
     */
    protected function sendCriticalAlert(array $alert): void
    {
        // Log for now - can be extended to Slack/Discord/Email
        Log::channel('system')->critical('CRITICAL PRODUCTION ALERT', $alert);

        // If webhook URL is configured, send notification
        $webhookUrl = config('monitoring.alert_webhook_url');
        if ($webhookUrl) {
            try {
                \Illuminate\Support\Facades\Http::timeout(5)->post($webhookUrl, [
                    'text' => "🚨 Critical Alert: {$alert['type']}",
                    'alert' => $alert,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send alert webhook', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Check database health
     */
    protected function checkDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $latency < 100 ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
                'connection' => config('database.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis health
     */
    protected function checkRedisHealth(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $latency < 50 ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check queue health
     */
    protected function checkQueueHealth(): array
    {
        try {
            $failedCount = $this->getFailedJobsCount();
            $pendingCount = $this->getPendingJobsCount();

            $status = 'healthy';
            if ($failedCount > 10) {
                $status = 'degraded';
            }
            if ($pendingCount > self::THRESHOLDS['queue_backlog_size']) {
                $status = 'degraded';
            }

            return [
                'status' => $status,
                'failed_jobs' => $failedCount,
                'pending_jobs' => $pendingCount,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unknown',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check memory health
     */
    protected function checkMemoryHealth(): array
    {
        $memoryLimit = $this->getMemoryLimitBytes();
        $memoryUsage = memory_get_usage(true);
        $usagePercent = $memoryLimit > 0 ? round(($memoryUsage / $memoryLimit) * 100, 2) : 0;

        return [
            'status' => $usagePercent < self::THRESHOLDS['memory_usage_percent'] ? 'healthy' : 'warning',
            'usage_bytes' => $memoryUsage,
            'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            'usage_percent' => $usagePercent,
        ];
    }

    /**
     * Check disk health
     */
    protected function checkDiskHealth(): array
    {
        $path = storage_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        $usagePercent = $total > 0 ? round(($used / $total) * 100, 2) : 0;

        return [
            'status' => $usagePercent < self::THRESHOLDS['disk_usage_percent'] ? 'healthy' : 'warning',
            'total_gb' => round($total / 1024 / 1024 / 1024, 2),
            'used_gb' => round($used / 1024 / 1024 / 1024, 2),
            'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            'usage_percent' => $usagePercent,
        ];
    }

    /**
     * Get recent alerts
     */
    protected function getRecentAlerts(int $limit = 10): array
    {
        $alerts = Cache::get(self::ALERTS_KEY . ':recent', []);
        return array_slice($alerts, 0, $limit);
    }

    /**
     * Get metrics summary
     */
    protected function getMetricsSummary(): array
    {
        return [
            'slow_queries_last_hour' => $this->getSlowQueryStats(1)['total_count'] ?? 0,
            'failed_jobs_last_hour' => count(Cache::get(self::FAILED_JOBS_KEY . ':' . now()->format('Y-m-d-H'), [])),
            'errors_last_hour' => $this->getErrorCountLastHour(),
        ];
    }

    /**
     * Get Redis queue metrics
     */
    protected function getRedisQueueMetrics(): array
    {
        $queues = ['default', 'high', 'low', 'notifications', 'attendance'];
        $metrics = [];

        foreach ($queues as $queue) {
            try {
                $pending = Redis::llen("queues:{$queue}") ?? 0;
                $reserved = Redis::zcard("queues:{$queue}:reserved") ?? 0;
                $delayed = Redis::zcard("queues:{$queue}:delayed") ?? 0;

                $metrics[$queue] = [
                    'pending' => $pending,
                    'reserved' => $reserved,
                    'delayed' => $delayed,
                ];
            } catch (\Exception $e) {
                $metrics[$queue] = ['error' => $e->getMessage()];
            }
        }

        return $metrics;
    }

    /**
     * Get database queue metrics
     */
    protected function getDatabaseQueueMetrics(): array
    {
        try {
            return [
                'default' => [
                    'pending' => DB::table('jobs')->where('queue', 'default')->count(),
                ],
                'total_pending' => DB::table('jobs')->count(),
                'total_failed' => DB::table('failed_jobs')->count(),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get queue throughput (jobs processed per minute)
     */
    protected function getQueueThroughput(): array
    {
        // This would require tracking processed jobs
        // For now, return estimate based on recent activity
        return [
            'jobs_per_minute_estimate' => 'N/A - Requires Horizon or custom tracking',
        ];
    }

    /**
     * Get failed jobs count
     */
    protected function getFailedJobsCount(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get pending jobs count
     */
    protected function getPendingJobsCount(): int
    {
        try {
            $connection = config('queue.default');
            
            if ($connection === 'database') {
                return DB::table('jobs')->count();
            }
            
            if ($connection === 'redis') {
                return Redis::llen('queues:default') ?? 0;
            }

            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get error count for last hour
     */
    protected function getErrorCountLastHour(): int
    {
        $count = 0;
        $now = now();

        for ($i = 0; $i < 60; $i++) {
            $key = self::ERROR_COUNTS_KEY . ':' . $now->copy()->subMinutes($i)->format('Y-m-d-H-i');
            $count += count(Cache::get($key, []));
        }

        return $count;
    }

    /**
     * Get alert severity
     */
    protected function getAlertSeverity(string $type): string
    {
        $criticalTypes = ['high_error_rate', 'database_down', 'redis_down'];
        return in_array($type, $criticalTypes) ? 'critical' : 'warning';
    }

    /**
     * Get memory limit in bytes
     */
    protected function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    /**
     * Truncate SQL for storage
     */
    protected function truncateSql(string $sql): string
    {
        return strlen($sql) > 500 ? substr($sql, 0, 500) . '...' : $sql;
    }

    /**
     * Truncate message for storage
     */
    protected function truncateMessage(string $message): string
    {
        return strlen($message) > 300 ? substr($message, 0, 300) . '...' : $message;
    }
}
