<?php

namespace App\Http\Controllers;

use App\Services\BackupRestoreMonitoringService;
use App\Models\BackupJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BackupMonitoringController extends Controller
{
    private $monitoringService;

    public function __construct(BackupRestoreMonitoringService $monitoringService)
    {
        $this->monitoringService = $monitoringService;
    }

    /**
     * Get monitoring dashboard data
     */
    public function dashboard(): JsonResponse
    {
        try {
            $data = $this->monitoringService->getDashboardData();

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get job list with filters
     */
    public function jobs(Request $request): JsonResponse
    {
        try {
            $query = BackupJob::with(['school', 'user']);

            // Apply filters
            if ($request->has('job_type')) {
                $query->where('job_type', $request->job_type);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('school_id')) {
                $query->where('school_id', $request->school_id);
            }

            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $jobs = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $jobs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load jobs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get job details
     */
    public function jobDetails(string $jobId): JsonResponse
    {
        try {
            $job = BackupJob::with(['school', 'user'])
                ->where('job_id', $jobId)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $job
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load job details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get job statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $dateRange = $request->get('date_range', 30); // days
            $since = now()->subDays($dateRange);

            $stats = [
                'total_jobs' => BackupJob::where('created_at', '>=', $since)->count(),
                'successful_jobs' => BackupJob::where('created_at', '>=', $since)->where('status', 'success')->count(),
                'failed_jobs' => BackupJob::where('created_at', '>=', $since)->where('status', 'failed')->count(),
                'running_jobs' => BackupJob::where('status', 'running')->count(),
                'success_rate' => $this->calculateSuccessRate($since),
                'average_duration' => BackupJob::where('status', 'success')
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('duration_seconds')
                    ->avg('duration_seconds'),
                'average_backup_size' => BackupJob::where('job_type', 'backup')
                    ->where('status', 'success')
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('backup_size_bytes')
                    ->avg('backup_size_bytes'),
                'job_types' => $this->getJobTypeStats($since),
                'daily_stats' => $this->getDailyStats($since),
                'hourly_stats' => $this->getHourlyStats($since)
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time job status
     */
    public function jobStatus(string $jobId): JsonResponse
    {
        try {
            $job = BackupJob::where('job_id', $jobId)->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'job_id' => $job->job_id,
                    'status' => $job->status,
                    'progress_percentage' => $job->progress_percentage,
                    'status_message' => $job->status_message,
                    'duration_seconds' => $job->duration_seconds,
                    'started_at' => $job->started_at,
                    'completed_at' => $job->completed_at
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get job status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel running job
     */
    public function cancelJob(string $jobId): JsonResponse
    {
        try {
            $job = BackupJob::where('job_id', $jobId)
                ->where('status', 'running')
                ->firstOrFail();

            $job->update([
                'status' => 'cancelled',
                'completed_at' => now(),
                'duration_seconds' => $job->started_at ? now()->diffInSeconds($job->started_at) : null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job cancelled successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Running job not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel job: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get monitoring configuration
     */
    public function configuration(): JsonResponse
    {
        try {
            $config = [
                'monitoring' => config('backup.monitoring'),
                'alerts' => [
                    'email_enabled' => !empty(config('backup.monitoring.alerts.email.recipients')),
                    'slack_enabled' => !empty(config('backup.monitoring.alerts.slack.webhook_url')),
                    'webhook_enabled' => !empty(config('backup.monitoring.alerts.webhook.url'))
                ],
                'thresholds' => [
                    'size_anomaly_threshold' => config('backup.monitoring.thresholds.size_anomaly', 30),
                    'duration_anomaly_threshold' => config('backup.monitoring.thresholds.duration_anomaly', 50)
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $config
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load configuration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate success rate
     */
    private function calculateSuccessRate(\Carbon\Carbon $since): float
    {
        $total = BackupJob::where('created_at', '>=', $since)->count();
        $successful = BackupJob::where('created_at', '>=', $since)->where('status', 'success')->count();

        return $total > 0 ? round(($successful / $total) * 100, 2) : 0;
    }

    /**
     * Get job type statistics
     */
    private function getJobTypeStats(\Carbon\Carbon $since): array
    {
        return BackupJob::where('created_at', '>=', $since)
            ->selectRaw('job_type, COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->groupBy('job_type')
            ->get()
            ->toArray();
    }

    /**
     * Get daily statistics
     */
    private function getDailyStats(\Carbon\Carbon $since): array
    {
        return BackupJob::where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Get hourly statistics (last 24 hours)
     */
    private function getHourlyStats(\Carbon\Carbon $since): array
    {
        $last24Hours = now()->subHours(24);

        return BackupJob::where('created_at', '>=', $last24Hours)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->toArray();
    }
}
