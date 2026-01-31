<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Queue Health Controller
 * 
 * Provides operational visibility into queue system health.
 * Critical for production monitoring and incident response.
 */
class QueueHealthController extends Controller
{
    /**
     * Get queue system health status
     * 
     * Returns metrics for:
     * - Pending jobs awaiting processing
     * - Failed jobs in last 24 hours
     * - Total failed jobs
     * 
     * Use this endpoint for:
     * - Monitoring dashboards
     * - Alerting systems (if failed_jobs_24h > threshold)
     * - Health checks before deployment
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function status()
    {
        try {
            // Count pending jobs in queue
            $pendingJobs = DB::table('jobs')->count();

            // Count failed jobs in last 24 hours (critical metric)
            $failedJobs24h = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();

            // Total failed jobs (historical)
            $totalFailedJobs = DB::table('failed_jobs')->count();

            // Oldest pending job (queue lag indicator)
            $oldestPendingJob = DB::table('jobs')
                ->orderBy('created_at', 'asc')
                ->first();

            $queueLagSeconds = $oldestPendingJob 
                ? now()->timestamp - $oldestPendingJob->created_at 
                : 0;

            // Determine health status
            $isHealthy = $failedJobs24h === 0 && $queueLagSeconds < 300; // 5 minutes lag threshold
            $alerts = $this->generateAlerts($failedJobs24h, $queueLagSeconds, $pendingJobs);

            return response()->json([
                'status' => $isHealthy ? 'healthy' : 'degraded',
                'timestamp' => now()->toIso8601String(),
                'metrics' => [
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs_24h' => $failedJobs24h,
                    'total_failed_jobs' => $totalFailedJobs,
                    'queue_lag_seconds' => $queueLagSeconds,
                ],
                'health' => [
                    'is_healthy' => $isHealthy,
                    'alerts' => $alerts,
                ],
                'alerts' => $alerts, // Keep for backward compatibility
            ], $isHealthy ? 200 : 503);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check queue health',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal error',
            ], 500);
        }
    }

    /**
     * Generate alert messages based on queue metrics
     * 
     * @param int $failedJobs24h
     * @param int $queueLagSeconds
     * @param int $pendingJobs
     * @return array
     */
    private function generateAlerts(int $failedJobs24h, int $queueLagSeconds, int $pendingJobs): array
    {
        $alerts = [];

        if ($failedJobs24h > 10) {
            $alerts[] = [
                'severity' => 'critical',
                'message' => "High failure rate: {$failedJobs24h} jobs failed in last 24 hours",
                'action' => 'Check logs/storage/logs/laravel.log and failed_jobs table',
            ];
        } elseif ($failedJobs24h > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'message' => "{$failedJobs24h} job(s) failed in last 24 hours",
                'action' => 'Review failed jobs and retry if applicable',
            ];
        }

        if ($queueLagSeconds > 300) {
            $alerts[] = [
                'severity' => 'warning',
                'message' => "Queue lag is {$queueLagSeconds} seconds (threshold: 300s)",
                'action' => 'Ensure queue worker is running: php artisan queue:work --tries=3',
            ];
        }

        if ($pendingJobs > 1000) {
            $alerts[] = [
                'severity' => 'warning',
                'message' => "High pending jobs count: {$pendingJobs}",
                'action' => 'Consider scaling queue workers or checking for stuck jobs',
            ];
        }

        if (empty($alerts)) {
            $alerts[] = [
                'severity' => 'info',
                'message' => 'Queue system is healthy',
            ];
        }

        return $alerts;
    }
}
