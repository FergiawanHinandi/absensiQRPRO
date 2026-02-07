<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

/**
 * Prometheus Metrics Service
 * 
 * Collects and formats application metrics in Prometheus exposition format.
 * 
 * Metrics Categories:
 * - API: latency, error rate, request count
 * - Database: query duration, connection pool
 * - Redis: memory usage, connections
 * - Queue: pending jobs, failed jobs, processing time
 */
class PrometheusMetricsService
{
    /**
     * Metric name prefix
     */
    protected const PREFIX = 'absensi';

    /**
     * Cache key for metrics
     */
    protected const METRICS_CACHE_KEY = 'prometheus:metrics';
    protected const REQUEST_METRICS_KEY = 'prometheus:requests';

    /**
     * Collect all metrics and return in Prometheus format
     */
    public function collect(): string
    {
        $metrics = [];

        // Application info
        $metrics[] = $this->buildMetric(
            'app_info',
            'gauge',
            'Application information',
            1,
            [
                'version' => config('app.version', '1.0.0'),
                'environment' => config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ]
        );

        // API Metrics
        $metrics[] = $this->collectApiMetrics();

        // Database Metrics
        $metrics[] = $this->collectDatabaseMetrics();

        // Redis Metrics
        $metrics[] = $this->collectRedisMetrics();

        // Queue Metrics
        $metrics[] = $this->collectQueueMetrics();

        // System Metrics
        $metrics[] = $this->collectSystemMetrics();

        // Attendance-specific Metrics
        $metrics[] = $this->collectAttendanceMetrics();

        return implode("\n", array_filter($metrics));
    }

    /**
     * Collect API metrics
     */
    protected function collectApiMetrics(): string
    {
        $metrics = [];

        try {
            $requestMetrics = Cache::get(self::REQUEST_METRICS_KEY, []);

            // HTTP Request Duration (histogram)
            $metrics[] = "# HELP " . self::PREFIX . "_http_request_duration_seconds HTTP request latency in seconds";
            $metrics[] = "# TYPE " . self::PREFIX . "_http_request_duration_seconds histogram";

            $buckets = [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
            
            foreach ($requestMetrics['duration_buckets'] ?? [] as $endpoint => $data) {
                foreach ($buckets as $bucket) {
                    $count = $data['buckets'][$bucket] ?? 0;
                    $metrics[] = self::PREFIX . "_http_request_duration_seconds_bucket{endpoint=\"{$endpoint}\",le=\"{$bucket}\"} {$count}";
                }
                $metrics[] = self::PREFIX . "_http_request_duration_seconds_bucket{endpoint=\"{$endpoint}\",le=\"+Inf\"} " . ($data['total'] ?? 0);
                $metrics[] = self::PREFIX . "_http_request_duration_seconds_sum{endpoint=\"{$endpoint}\"} " . ($data['sum'] ?? 0);
                $metrics[] = self::PREFIX . "_http_request_duration_seconds_count{endpoint=\"{$endpoint}\"} " . ($data['total'] ?? 0);
            }

            // HTTP Request Total (counter)
            $metrics[] = "\n# HELP " . self::PREFIX . "_http_requests_total Total HTTP requests";
            $metrics[] = "# TYPE " . self::PREFIX . "_http_requests_total counter";

            foreach ($requestMetrics['requests'] ?? [] as $key => $count) {
                [$method, $status, $endpoint] = explode(':', $key) + ['', '', ''];
                $metrics[] = self::PREFIX . "_http_requests_total{method=\"{$method}\",status=\"{$status}\",endpoint=\"{$endpoint}\"} {$count}";
            }

            // HTTP Errors Total (counter)
            $metrics[] = "\n# HELP " . self::PREFIX . "_http_errors_total Total HTTP errors (4xx, 5xx)";
            $metrics[] = "# TYPE " . self::PREFIX . "_http_errors_total counter";

            $totalErrors = $requestMetrics['errors'] ?? 0;
            $metrics[] = self::PREFIX . "_http_errors_total {$totalErrors}";

            // Error Rate (gauge - calculated)
            $totalRequests = array_sum($requestMetrics['requests'] ?? [1]);
            $errorRate = $totalRequests > 0 ? round(($totalErrors / $totalRequests) * 100, 2) : 0;

            $metrics[] = "\n# HELP " . self::PREFIX . "_http_error_rate_percent HTTP error rate percentage";
            $metrics[] = "# TYPE " . self::PREFIX . "_http_error_rate_percent gauge";
            $metrics[] = self::PREFIX . "_http_error_rate_percent {$errorRate}";

            // Active Requests (gauge)
            $metrics[] = "\n# HELP " . self::PREFIX . "_http_requests_active Current active HTTP requests";
            $metrics[] = "# TYPE " . self::PREFIX . "_http_requests_active gauge";
            $metrics[] = self::PREFIX . "_http_requests_active " . ($requestMetrics['active'] ?? 0);

        } catch (\Exception $e) {
            $metrics[] = "# ERROR collecting API metrics: " . $e->getMessage();
        }

        return implode("\n", $metrics);
    }

    /**
     * Collect database metrics
     */
    protected function collectDatabaseMetrics(): string
    {
        $metrics = [];

        try {
            // Query Duration
            $metrics[] = "\n# HELP " . self::PREFIX . "_db_query_duration_seconds Database query duration";
            $metrics[] = "# TYPE " . self::PREFIX . "_db_query_duration_seconds gauge";

            $queryMetrics = Cache::get('prometheus:db_queries', []);
            $avgDuration = ($queryMetrics['total_time'] ?? 0) / max(1, $queryMetrics['count'] ?? 1);
            $metrics[] = self::PREFIX . "_db_query_duration_seconds_avg " . round($avgDuration / 1000, 6);
            $metrics[] = self::PREFIX . "_db_query_duration_seconds_max " . round(($queryMetrics['max_time'] ?? 0) / 1000, 6);

            // Query Count
            $metrics[] = "\n# HELP " . self::PREFIX . "_db_queries_total Total database queries";
            $metrics[] = "# TYPE " . self::PREFIX . "_db_queries_total counter";
            $metrics[] = self::PREFIX . "_db_queries_total " . ($queryMetrics['count'] ?? 0);

            // Slow Queries (> 300ms)
            $metrics[] = "\n# HELP " . self::PREFIX . "_db_slow_queries_total Slow queries (>300ms)";
            $metrics[] = "# TYPE " . self::PREFIX . "_db_slow_queries_total counter";
            $metrics[] = self::PREFIX . "_db_slow_queries_total " . ($queryMetrics['slow_count'] ?? 0);

            // Connection Pool
            $connection = DB::connection();
            $pdo = $connection->getPdo();

            // Get PostgreSQL connection stats
            if (config('database.default') === 'pgsql') {
                $stats = DB::select("SELECT 
                    (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()) as active_connections,
                    (SELECT setting::int FROM pg_settings WHERE name = 'max_connections') as max_connections
                ");

                if (!empty($stats)) {
                    $active = $stats[0]->active_connections ?? 0;
                    $max = $stats[0]->max_connections ?? 100;
                    $usage = round(($active / $max) * 100, 2);

                    $metrics[] = "\n# HELP " . self::PREFIX . "_db_connections_active Active database connections";
                    $metrics[] = "# TYPE " . self::PREFIX . "_db_connections_active gauge";
                    $metrics[] = self::PREFIX . "_db_connections_active " . $active;

                    $metrics[] = "\n# HELP " . self::PREFIX . "_db_connections_max Maximum database connections";
                    $metrics[] = "# TYPE " . self::PREFIX . "_db_connections_max gauge";
                    $metrics[] = self::PREFIX . "_db_connections_max " . $max;

                    $metrics[] = "\n# HELP " . self::PREFIX . "_db_connections_usage_percent Database connection pool usage";
                    $metrics[] = "# TYPE " . self::PREFIX . "_db_connections_usage_percent gauge";
                    $metrics[] = self::PREFIX . "_db_connections_usage_percent " . $usage;
                }
            }

            // DB Up
            $metrics[] = "\n# HELP " . self::PREFIX . "_db_up Database is reachable";
            $metrics[] = "# TYPE " . self::PREFIX . "_db_up gauge";
            $metrics[] = self::PREFIX . "_db_up 1";

        } catch (\Exception $e) {
            $metrics[] = "\n# HELP " . self::PREFIX . "_db_up Database is reachable";
            $metrics[] = "# TYPE " . self::PREFIX . "_db_up gauge";
            $metrics[] = self::PREFIX . "_db_up 0";
            $metrics[] = "# ERROR: " . $e->getMessage();
        }

        return implode("\n", $metrics);
    }

    /**
     * Collect Redis metrics
     */
    protected function collectRedisMetrics(): string
    {
        $metrics = [];

        try {
            $redis = Redis::connection();
            $info = $redis->info();

            // Redis Up
            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_up Redis is reachable";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_up gauge";
            $metrics[] = self::PREFIX . "_redis_up 1";

            // Memory Usage
            $usedMemory = $info['used_memory'] ?? 0;
            $maxMemory = $info['maxmemory'] ?? 0;
            $memoryUsagePercent = $maxMemory > 0 ? round(($usedMemory / $maxMemory) * 100, 2) : 0;

            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_memory_used_bytes Redis memory used";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_memory_used_bytes gauge";
            $metrics[] = self::PREFIX . "_redis_memory_used_bytes " . $usedMemory;

            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_memory_max_bytes Redis max memory";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_memory_max_bytes gauge";
            $metrics[] = self::PREFIX . "_redis_memory_max_bytes " . ($maxMemory ?: 'Inf');

            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_memory_usage_percent Redis memory usage percentage";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_memory_usage_percent gauge";
            $metrics[] = self::PREFIX . "_redis_memory_usage_percent " . $memoryUsagePercent;

            // Connected Clients
            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_connected_clients Connected Redis clients";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_connected_clients gauge";
            $metrics[] = self::PREFIX . "_redis_connected_clients " . ($info['connected_clients'] ?? 0);

            // Commands Processed
            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_commands_processed_total Total commands processed";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_commands_processed_total counter";
            $metrics[] = self::PREFIX . "_redis_commands_processed_total " . ($info['total_commands_processed'] ?? 0);

            // Keyspace
            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_keys_total Total keys in Redis";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_keys_total gauge";
            $dbSize = $redis->dbsize();
            $metrics[] = self::PREFIX . "_redis_keys_total " . $dbSize;

            // Evicted Keys
            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_evicted_keys_total Keys evicted due to maxmemory";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_evicted_keys_total counter";
            $metrics[] = self::PREFIX . "_redis_evicted_keys_total " . ($info['evicted_keys'] ?? 0);

            // Hit Rate
            $hits = $info['keyspace_hits'] ?? 0;
            $misses = $info['keyspace_misses'] ?? 0;
            $hitRate = ($hits + $misses) > 0 ? round(($hits / ($hits + $misses)) * 100, 2) : 0;

            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_hit_rate_percent Cache hit rate";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_hit_rate_percent gauge";
            $metrics[] = self::PREFIX . "_redis_hit_rate_percent " . $hitRate;

        } catch (\Exception $e) {
            $metrics[] = "\n# HELP " . self::PREFIX . "_redis_up Redis is reachable";
            $metrics[] = "# TYPE " . self::PREFIX . "_redis_up gauge";
            $metrics[] = self::PREFIX . "_redis_up 0";
        }

        return implode("\n", $metrics);
    }

    /**
     * Collect queue metrics
     */
    protected function collectQueueMetrics(): string
    {
        $metrics = [];

        try {
            $connection = config('queue.default');
            $queues = ['default', 'high', 'low', 'notifications'];
            $totalPending = 0;

            $metrics[] = "\n# HELP " . self::PREFIX . "_queue_jobs_pending Pending jobs in queue";
            $metrics[] = "# TYPE " . self::PREFIX . "_queue_jobs_pending gauge";

            foreach ($queues as $queue) {
                $pending = 0;

                if ($connection === 'redis') {
                    try {
                        $redis = Redis::connection();
                        $pending = (int) $redis->llen("queues:{$queue}");
                        $delayed = (int) $redis->zcard("queues:{$queue}:delayed");
                        
                        $metrics[] = self::PREFIX . "_queue_jobs_pending{queue=\"{$queue}\",status=\"ready\"} {$pending}";
                        $metrics[] = self::PREFIX . "_queue_jobs_pending{queue=\"{$queue}\",status=\"delayed\"} {$delayed}";
                        
                        $totalPending += $pending + $delayed;
                    } catch (\Exception $e) {
                        // Redis unavailable
                    }
                } elseif ($connection === 'database') {
                    $pending = DB::table('jobs')->where('queue', $queue)->count();
                    $metrics[] = self::PREFIX . "_queue_jobs_pending{queue=\"{$queue}\"} {$pending}";
                    $totalPending += $pending;
                }
            }

            // Total pending (for alerting)
            $metrics[] = "\n# HELP " . self::PREFIX . "_queue_jobs_pending_total Total pending jobs across all queues";
            $metrics[] = "# TYPE " . self::PREFIX . "_queue_jobs_pending_total gauge";
            $metrics[] = self::PREFIX . "_queue_jobs_pending_total " . $totalPending;

            // Failed Jobs
            $failedTotal = DB::table('failed_jobs')->count();
            $failedLastHour = DB::table('failed_jobs')
                ->where('failed_at', '>', now()->subHour())
                ->count();

            $metrics[] = "\n# HELP " . self::PREFIX . "_queue_jobs_failed_total Total failed jobs";
            $metrics[] = "# TYPE " . self::PREFIX . "_queue_jobs_failed_total counter";
            $metrics[] = self::PREFIX . "_queue_jobs_failed_total " . $failedTotal;

            $metrics[] = "\n# HELP " . self::PREFIX . "_queue_jobs_failed_last_hour Failed jobs in last hour";
            $metrics[] = "# TYPE " . self::PREFIX . "_queue_jobs_failed_last_hour gauge";
            $metrics[] = self::PREFIX . "_queue_jobs_failed_last_hour " . $failedLastHour;

            // Job Processing Time (from cache)
            $jobMetrics = Cache::get('prometheus:job_metrics', []);

            $metrics[] = "\n# HELP " . self::PREFIX . "_queue_job_duration_seconds Job processing duration";
            $metrics[] = "# TYPE " . self::PREFIX . "_queue_job_duration_seconds gauge";
            $metrics[] = self::PREFIX . "_queue_job_duration_seconds_avg " . round(($jobMetrics['avg_duration'] ?? 0) / 1000, 6);
            $metrics[] = self::PREFIX . "_queue_job_duration_seconds_max " . round(($jobMetrics['max_duration'] ?? 0) / 1000, 6);

            // Jobs Processed
            $metrics[] = "\n# HELP " . self::PREFIX . "_queue_jobs_processed_total Total jobs processed";
            $metrics[] = "# TYPE " . self::PREFIX . "_queue_jobs_processed_total counter";
            $metrics[] = self::PREFIX . "_queue_jobs_processed_total " . ($jobMetrics['processed'] ?? 0);

        } catch (\Exception $e) {
            $metrics[] = "# ERROR collecting queue metrics: " . $e->getMessage();
        }

        return implode("\n", $metrics);
    }

    /**
     * Collect system metrics
     */
    protected function collectSystemMetrics(): string
    {
        $metrics = [];

        // PHP Memory
        $metrics[] = "\n# HELP " . self::PREFIX . "_php_memory_usage_bytes PHP memory usage";
        $metrics[] = "# TYPE " . self::PREFIX . "_php_memory_usage_bytes gauge";
        $metrics[] = self::PREFIX . "_php_memory_usage_bytes " . memory_get_usage(true);

        $metrics[] = "\n# HELP " . self::PREFIX . "_php_memory_peak_bytes PHP peak memory usage";
        $metrics[] = "# TYPE " . self::PREFIX . "_php_memory_peak_bytes gauge";
        $metrics[] = self::PREFIX . "_php_memory_peak_bytes " . memory_get_peak_usage(true);

        // Uptime
        $metrics[] = "\n# HELP " . self::PREFIX . "_process_start_time_seconds Process start time";
        $metrics[] = "# TYPE " . self::PREFIX . "_process_start_time_seconds gauge";
        $metrics[] = self::PREFIX . "_process_start_time_seconds " . ($_SERVER['REQUEST_TIME'] ?? time());

        // Opcache (if available)
        if (function_exists('opcache_get_status')) {
            $opcache = @opcache_get_status(false);
            if ($opcache) {
                $metrics[] = "\n# HELP " . self::PREFIX . "_opcache_memory_used_bytes Opcache memory used";
                $metrics[] = "# TYPE " . self::PREFIX . "_opcache_memory_used_bytes gauge";
                $metrics[] = self::PREFIX . "_opcache_memory_used_bytes " . ($opcache['memory_usage']['used_memory'] ?? 0);

                $hitRate = $opcache['opcache_statistics']['opcache_hit_rate'] ?? 0;
                $metrics[] = "\n# HELP " . self::PREFIX . "_opcache_hit_rate_percent Opcache hit rate";
                $metrics[] = "# TYPE " . self::PREFIX . "_opcache_hit_rate_percent gauge";
                $metrics[] = self::PREFIX . "_opcache_hit_rate_percent " . round($hitRate, 2);
            }
        }

        return implode("\n", $metrics);
    }

    /**
     * Collect attendance-specific metrics
     */
    protected function collectAttendanceMetrics(): string
    {
        $metrics = [];

        try {
            // Today's attendance stats
            $today = now()->toDateString();

            $attendanceToday = DB::table('attendances')
                ->whereDate('created_at', $today)
                ->count();

            $metrics[] = "\n# HELP " . self::PREFIX . "_attendance_scans_today Total attendance scans today";
            $metrics[] = "# TYPE " . self::PREFIX . "_attendance_scans_today gauge";
            $metrics[] = self::PREFIX . "_attendance_scans_today " . $attendanceToday;

            // Scans by status
            $statusCounts = DB::table('attendances')
                ->whereDate('created_at', $today)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $metrics[] = "\n# HELP " . self::PREFIX . "_attendance_by_status Attendance by status today";
            $metrics[] = "# TYPE " . self::PREFIX . "_attendance_by_status gauge";
            
            foreach (['present', 'late', 'absent', 'excused'] as $status) {
                $count = $statusCounts[$status] ?? 0;
                $metrics[] = self::PREFIX . "_attendance_by_status{status=\"{$status}\"} {$count}";
            }

            // Active schools
            $activeSchools = DB::table('schools')
                ->where('is_active', true)
                ->count();

            $metrics[] = "\n# HELP " . self::PREFIX . "_schools_active Total active schools";
            $metrics[] = "# TYPE " . self::PREFIX . "_schools_active gauge";
            $metrics[] = self::PREFIX . "_schools_active " . $activeSchools;

            // Active users by role
            $usersByRole = DB::table('users')
                ->where('is_active', true)
                ->selectRaw('role_type, COUNT(*) as count')
                ->groupBy('role_type')
                ->pluck('count', 'role_type')
                ->toArray();

            $metrics[] = "\n# HELP " . self::PREFIX . "_users_active Active users by role";
            $metrics[] = "# TYPE " . self::PREFIX . "_users_active gauge";
            
            foreach ($usersByRole as $role => $count) {
                $metrics[] = self::PREFIX . "_users_active{role=\"{$role}\"} {$count}";
            }

            // QR scans rate (last 5 minutes)
            $recentScans = DB::table('attendances')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->count();
            $scanRate = round($recentScans / 5, 2); // per minute

            $metrics[] = "\n# HELP " . self::PREFIX . "_attendance_scan_rate Scans per minute (5min avg)";
            $metrics[] = "# TYPE " . self::PREFIX . "_attendance_scan_rate gauge";
            $metrics[] = self::PREFIX . "_attendance_scan_rate " . $scanRate;

        } catch (\Exception $e) {
            $metrics[] = "# ERROR collecting attendance metrics: " . $e->getMessage();
        }

        return implode("\n", $metrics);
    }

    /**
     * Build a single metric line
     */
    protected function buildMetric(
        string $name,
        string $type,
        string $help,
        $value,
        array $labels = []
    ): string {
        $fullName = self::PREFIX . '_' . $name;
        $lines = [];

        $lines[] = "# HELP {$fullName} {$help}";
        $lines[] = "# TYPE {$fullName} {$type}";

        $labelStr = '';
        if (!empty($labels)) {
            $labelParts = [];
            foreach ($labels as $k => $v) {
                $labelParts[] = "{$k}=\"{$v}\"";
            }
            $labelStr = '{' . implode(',', $labelParts) . '}';
        }

        $lines[] = "{$fullName}{$labelStr} {$value}";

        return implode("\n", $lines);
    }

    /**
     * Record request metrics (called from middleware)
     */
    public static function recordRequest(
        string $method,
        string $endpoint,
        int $status,
        float $duration
    ): void {
        try {
            $metrics = Cache::get(self::REQUEST_METRICS_KEY, [
                'requests' => [],
                'errors' => 0,
                'active' => 0,
                'duration_buckets' => [],
            ]);

            // Request count
            $key = "{$method}:{$status}:{$endpoint}";
            $metrics['requests'][$key] = ($metrics['requests'][$key] ?? 0) + 1;

            // Error count
            if ($status >= 400) {
                $metrics['errors']++;
            }

            // Duration histogram
            if (!isset($metrics['duration_buckets'][$endpoint])) {
                $metrics['duration_buckets'][$endpoint] = [
                    'buckets' => [],
                    'sum' => 0,
                    'total' => 0,
                ];
            }

            $metrics['duration_buckets'][$endpoint]['sum'] += $duration;
            $metrics['duration_buckets'][$endpoint]['total']++;

            $buckets = [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
            foreach ($buckets as $bucket) {
                if ($duration <= $bucket) {
                    $metrics['duration_buckets'][$endpoint]['buckets'][$bucket] = 
                        ($metrics['duration_buckets'][$endpoint]['buckets'][$bucket] ?? 0) + 1;
                }
            }

            Cache::put(self::REQUEST_METRICS_KEY, $metrics, now()->addHour());
        } catch (\Exception $e) {
            // Silently fail - metrics collection shouldn't break the app
        }
    }

    /**
     * Record database query (called from query listener)
     */
    public static function recordQuery(float $timeMs): void
    {
        try {
            $metrics = Cache::get('prometheus:db_queries', [
                'count' => 0,
                'total_time' => 0,
                'max_time' => 0,
                'slow_count' => 0,
            ]);

            $metrics['count']++;
            $metrics['total_time'] += $timeMs;
            $metrics['max_time'] = max($metrics['max_time'], $timeMs);

            if ($timeMs > 300) { // Slow query > 300ms
                $metrics['slow_count']++;
            }

            Cache::put('prometheus:db_queries', $metrics, now()->addHour());
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Record job metrics
     */
    public static function recordJob(string $job, float $durationMs, bool $failed = false): void
    {
        try {
            $metrics = Cache::get('prometheus:job_metrics', [
                'processed' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'max_duration' => 0,
            ]);

            $metrics['processed']++;
            if ($failed) {
                $metrics['failed']++;
            }
            $metrics['total_duration'] += $durationMs;
            $metrics['max_duration'] = max($metrics['max_duration'], $durationMs);
            $metrics['avg_duration'] = $metrics['total_duration'] / $metrics['processed'];

            Cache::put('prometheus:job_metrics', $metrics, now()->addHour());
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
