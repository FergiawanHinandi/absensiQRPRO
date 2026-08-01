<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ProductionMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Production Monitoring Controller
 * 
 * Provides endpoints for monitoring production health,
 * queue status, slow queries, and alerts.
 */
class MonitoringController extends Controller
{
    public function __construct(
        protected ProductionMonitoringService $monitoringService
    ) {}

    /**
     * Get comprehensive system health status
     * 
     * @return JsonResponse
     */
    public function systemHealth(): JsonResponse
    {
        $health = $this->monitoringService->getSystemHealth();
        
        $overallStatus = $this->determineOverallStatus($health);
        
        return response()->json([
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'version' => config('app.version', '1.0.0'),
            'health' => $health,
        ], $overallStatus === 'healthy' ? 200 : 503);
    }

    /**
     * Get queue monitoring metrics
     * 
     * @return JsonResponse
     */
    public function queueMetrics(): JsonResponse
    {
        $metrics = $this->monitoringService->getQueueMetrics();
        
        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'metrics' => $metrics,
        ]);
    }

    /**
     * Get slow query statistics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function slowQueries(Request $request): JsonResponse
    {
        $hours = $request->input('hours', 1);
        $hours = min(max($hours, 1), 24); // Limit to 1-24 hours
        
        $stats = $this->monitoringService->getSlowQueryStats($hours);
        
        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'period_hours' => $hours,
            'statistics' => $stats,
        ]);
    }

    /**
     * Get monitoring dashboard data (all metrics combined)
     * 
     * @return JsonResponse
     */
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'version' => config('app.version', '1.0.0'),
            'system_health' => $this->monitoringService->getSystemHealth(),
            'queue_metrics' => $this->monitoringService->getQueueMetrics(),
            'slow_queries' => $this->monitoringService->getSlowQueryStats(1),
            'deadlock_metrics' => \App\Http\Middleware\DeadlockRetryMiddleware::getMetrics(),
            'thresholds' => [
                'slow_query_warning_ms' => config('monitoring.slow_query.warning_threshold'),
                'slow_query_critical_ms' => config('monitoring.slow_query.critical_threshold'),
                'queue_backlog_alert' => config('monitoring.queue.backlog_alert_threshold'),
                'error_rate_alert' => config('monitoring.errors.alert_threshold_per_minute'),
            ],
        ]);
    }

    /**
     * Prometheus-compatible metrics endpoint
     * 
     * @return \Illuminate\Http\Response
     */
    public function prometheusMetrics(): \Illuminate\Http\Response
    {
        $health = $this->monitoringService->getSystemHealth();
        $queue = $this->monitoringService->getQueueMetrics();
        $slowQueries = $this->monitoringService->getSlowQueryStats(1);
        $deadlock = \App\Http\Middleware\DeadlockRetryMiddleware::getMetrics();
        
        $metrics = [];
        
        // System metrics
        $metrics[] = "# HELP absensi_database_healthy Database health status";
        $metrics[] = "# TYPE absensi_database_healthy gauge";
        $metrics[] = "absensi_database_healthy " . ($health['database']['status'] === 'healthy' ? 1 : 0);
        
        $metrics[] = "# HELP absensi_database_latency_ms Database latency in milliseconds";
        $metrics[] = "# TYPE absensi_database_latency_ms gauge";
        $metrics[] = "absensi_database_latency_ms " . ($health['database']['latency_ms'] ?? 0);
        
        $metrics[] = "# HELP absensi_redis_healthy Redis health status";
        $metrics[] = "# TYPE absensi_redis_healthy gauge";
        $metrics[] = "absensi_redis_healthy " . ($health['redis']['status'] === 'healthy' ? 1 : 0);
        
        $metrics[] = "# HELP absensi_redis_latency_ms Redis latency in milliseconds";
        $metrics[] = "# TYPE absensi_redis_latency_ms gauge";
        $metrics[] = "absensi_redis_latency_ms " . ($health['redis']['latency_ms'] ?? 0);
        
        // Memory metrics
        $metrics[] = "# HELP absensi_memory_usage_bytes Current memory usage in bytes";
        $metrics[] = "# TYPE absensi_memory_usage_bytes gauge";
        $metrics[] = "absensi_memory_usage_bytes " . ($health['memory']['usage_bytes'] ?? 0);
        
        $metrics[] = "# HELP absensi_memory_usage_percent Memory usage percentage";
        $metrics[] = "# TYPE absensi_memory_usage_percent gauge";
        $metrics[] = "absensi_memory_usage_percent " . ($health['memory']['usage_percent'] ?? 0);
        
        // Disk metrics
        $metrics[] = "# HELP absensi_disk_usage_percent Disk usage percentage";
        $metrics[] = "# TYPE absensi_disk_usage_percent gauge";
        $metrics[] = "absensi_disk_usage_percent " . ($health['disk']['usage_percent'] ?? 0);
        
        $metrics[] = "# HELP absensi_disk_free_bytes Free disk space in bytes";
        $metrics[] = "# TYPE absensi_disk_free_bytes gauge";
        $metrics[] = "absensi_disk_free_bytes " . (($health['disk']['free_gb'] ?? 0) * 1024 * 1024 * 1024);
        
        // Queue metrics
        $metrics[] = "# HELP absensi_queue_failed_jobs_total Total failed jobs";
        $metrics[] = "# TYPE absensi_queue_failed_jobs_total gauge";
        $metrics[] = "absensi_queue_failed_jobs_total " . ($queue['failed_jobs'] ?? 0);
        
        // Slow queries
        $metrics[] = "# HELP absensi_slow_queries_last_hour Slow queries in the last hour";
        $metrics[] = "# TYPE absensi_slow_queries_last_hour gauge";
        $metrics[] = "absensi_slow_queries_last_hour " . ($slowQueries['total_count'] ?? 0);
        
        $metrics[] = "# HELP absensi_slow_query_avg_ms Average slow query duration in ms";
        $metrics[] = "# TYPE absensi_slow_query_avg_ms gauge";
        $metrics[] = "absensi_slow_query_avg_ms " . ($slowQueries['avg_duration_ms'] ?? 0);
        
        // Deadlock metrics
        $metrics[] = "# HELP absensi_deadlock_detected_total Total deadlocks detected";
        $metrics[] = "# TYPE absensi_deadlock_detected_total counter";
        $metrics[] = "absensi_deadlock_detected_total " . ($deadlock['total_deadlocks'] ?? 0);
        
        $metrics[] = "# HELP absensi_deadlock_retries_total Total deadlock retry attempts";
        $metrics[] = "# TYPE absensi_deadlock_retries_total counter";
        $metrics[] = "absensi_deadlock_retries_total " . ($deadlock['total_retries'] ?? 0);
        
        $metrics[] = "# HELP absensi_deadlock_retry_success_rate Deadlock retry success rate percentage";
        $metrics[] = "# TYPE absensi_deadlock_retry_success_rate gauge";
        $metrics[] = "absensi_deadlock_retry_success_rate " . ($deadlock['success_rate'] ?? 100);
        
        return response(implode("\n", $metrics) . "\n", 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /**
     * Determine overall system status
     */
    protected function determineOverallStatus(array $health): string
    {
        $criticalServices = ['database', 'redis'];
        
        foreach ($criticalServices as $service) {
            if (isset($health[$service]['status']) && $health[$service]['status'] === 'unhealthy') {
                return 'unhealthy';
            }
        }
        
        foreach ($health as $component => $status) {
            if (isset($status['status']) && $status['status'] === 'degraded') {
                return 'degraded';
            }
        }
        
        return 'healthy';
    }
}
