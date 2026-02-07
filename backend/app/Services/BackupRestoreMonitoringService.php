<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\BackupJob;

class BackupRestoreMonitoringService
{
    private $jobId;
    private $startTime;
    private $jobType;
    private $metrics = [];

    public function __construct()
    {
        $this->jobId = 'job_' . uniqid() . '_' . time();
    }

    /**
     * Start monitoring a backup/restore job
     */
    public function startJob(string $jobType, array $metadata = []): string
    {
        $this->jobType = $jobType;
        $this->startTime = now();

        // Create job record
        $job = BackupJob::create([
            'job_id' => $this->jobId,
            'job_type' => $jobType,
            'status' => 'running',
            'started_at' => $this->startTime,
            'metadata' => $metadata,
            'school_id' => $metadata['school_id'] ?? null
        ]);

        $this->logJobEvent('STARTED', "Job started: {$jobType}", $metadata);

        return $this->jobId;
    }

    /**
     * Complete job with success metrics
     */
    public function completeJob(array $metrics = []): void
    {
        $endTime = now();
        $duration = $this->startTime->diffInSeconds($endTime);

        $this->metrics = array_merge([
            'duration_seconds' => $duration,
            'ended_at' => $endTime,
            'status' => 'success'
        ], $metrics);

        // Update job record
        BackupJob::where('job_id', $this->jobId)->update([
            'status' => 'success',
            'completed_at' => $endTime,
            'duration_seconds' => $duration,
            'backup_size_bytes' => $metrics['backup_size_bytes'] ?? null,
            'metrics' => $this->metrics
        ]);

        $this->logJobEvent('COMPLETED', "Job completed successfully in {$duration}s", $this->metrics);

        // Perform post-completion checks
        $this->performPostCompletionChecks();
    }

    /**
     * Mark job as failed
     */
    public function failJob(string $errorMessage, array $metrics = []): void
    {
        $endTime = now();
        $duration = $this->startTime->diffInSeconds($endTime);

        $this->metrics = array_merge([
            'duration_seconds' => $duration,
            'ended_at' => $endTime,
            'status' => 'failed',
            'error_message' => $errorMessage
        ], $metrics);

        // Update job record
        BackupJob::where('job_id', $this->jobId)->update([
            'status' => 'failed',
            'completed_at' => $endTime,
            'duration_seconds' => $duration,
            'error_message' => $errorMessage,
            'metrics' => $this->metrics
        ]);

        $this->logJobEvent('FAILED', "Job failed: {$errorMessage}", $this->metrics);

        // Send failure alert
        $this->sendFailureAlert($errorMessage);
    }

    /**
     * Update job progress
     */
    public function updateProgress(int $percentage, string $message = null): void
    {
        BackupJob::where('job_id', $this->jobId)->update([
            'progress_percentage' => $percentage,
            'status_message' => $message
        ]);

        $this->logJobEvent('PROGRESS', "Progress: {$percentage}%", [
            'percentage' => $percentage,
            'message' => $message
        ]);
    }

    /**
     * Perform post-completion checks and alerts
     */
    private function performPostCompletionChecks(): void
    {
        // Check for size anomaly
        if (isset($this->metrics['backup_size_bytes'])) {
            $this->checkSizeAnomaly($this->metrics['backup_size_bytes']);
        }

        // Check for duration anomaly
        if (isset($this->metrics['duration_seconds'])) {
            $this->checkDurationAnomaly($this->metrics['duration_seconds']);
        }
    }

    /**
     * Check for backup size anomaly (>30% drop)
     */
    private function checkSizeAnomaly(int $currentSize): void
    {
        // Get average size of last 7 successful jobs of same type
        $averageSize = BackupJob::where('job_type', $this->jobType)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('backup_size_bytes')
            ->avg('backup_size_bytes');

        if ($averageSize && $currentSize > 0) {
            $sizeDrop = (($averageSize - $currentSize) / $averageSize) * 100;

            if ($sizeDrop > 30) {
                $this->sendSizeAnomalyAlert($currentSize, $averageSize, $sizeDrop);
            }
        }
    }

    /**
     * Check for duration anomaly
     */
    private function checkDurationAnomaly(int $currentDuration): void
    {
        // Get average duration of last 7 successful jobs of same type
        $averageDuration = BackupJob::where('job_type', $this->jobType)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('duration_seconds')
            ->avg('duration_seconds');

        if ($averageDuration && $currentDuration > 0) {
            $durationIncrease = (($currentDuration - $averageDuration) / $averageDuration) * 100;

            // Alert if duration is 50% longer than average
            if ($durationIncrease > 50) {
                $this->sendDurationAnomalyAlert($currentDuration, $averageDuration, $durationIncrease);
            }
        }
    }

    /**
     * Send failure alert
     */
    private function sendFailureAlert(string $errorMessage): void
    {
        $alertData = [
            'type' => 'failure',
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'error_message' => $errorMessage,
            'started_at' => $this->startTime->toISOString(),
            'school_id' => $this->metrics['school_id'] ?? null,
            'timestamp' => now()->toISOString()
        ];

        $this->sendEmailAlert($alertData);
        $this->sendSlackAlert($alertData);
        $this->sendWebhookAlert($alertData);

        Log::channel('backup')->error('Backup/Restore job failure alert sent', $alertData);
    }

    /**
     * Send size anomaly alert
     */
    private function sendSizeAnomalyAlert(int $currentSize, int $averageSize, float $dropPercentage): void
    {
        $alertData = [
            'type' => 'size_anomaly',
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'current_size_bytes' => $currentSize,
            'average_size_bytes' => $averageSize,
            'size_drop_percentage' => round($dropPercentage, 2),
            'school_id' => $this->metrics['school_id'] ?? null,
            'timestamp' => now()->toISOString()
        ];

        $this->sendEmailAlert($alertData);
        $this->sendSlackAlert($alertData);
        $this->sendWebhookAlert($alertData);

        Log::channel('backup')->warning('Backup size anomaly alert sent', $alertData);
    }

    /**
     * Send duration anomaly alert
     */
    private function sendDurationAnomalyAlert(int $currentDuration, int $averageDuration, float $increasePercentage): void
    {
        $alertData = [
            'type' => 'duration_anomaly',
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'current_duration_seconds' => $currentDuration,
            'average_duration_seconds' => $averageDuration,
            'duration_increase_percentage' => round($increasePercentage, 2),
            'school_id' => $this->metrics['school_id'] ?? null,
            'timestamp' => now()->toISOString()
        ];

        $this->sendEmailAlert($alertData);
        $this->sendSlackAlert($alertData);
        $this->sendWebhookAlert($alertData);

        Log::channel('backup')->warning('Backup duration anomaly alert sent', $alertData);
    }

    /**
     * Send email alert
     */
    private function sendEmailAlert(array $alertData): void
    {
        try {
            $recipients = config('backup.monitoring.alerts.email.recipients', []);
            
            if (empty($recipients)) {
                return;
            }

            $subject = $this->getEmailSubject($alertData);
            $message = $this->getEmailMessage($alertData);

            foreach ($recipients as $recipient) {
                \Mail::raw($message, function ($message) use ($recipient, $subject) {
                    $message->to($recipient)
                           ->subject($subject);
                });
            }

        } catch (\Exception $e) {
            Log::channel('backup')->error('Failed to send email alert', [
                'error' => $e->getMessage(),
                'alert_data' => $alertData
            ]);
        }
    }

    /**
     * Send Slack alert
     */
    private function sendSlackAlert(array $alertData): void
    {
        try {
            $webhookUrl = config('backup.monitoring.alerts.slack.webhook_url');
            
            if (!$webhookUrl) {
                return;
            }

            $payload = $this->buildSlackPayload($alertData);

            Http::post($webhookUrl, $payload);

        } catch (\Exception $e) {
            Log::channel('backup')->error('Failed to send Slack alert', [
                'error' => $e->getMessage(),
                'alert_data' => $alertData
            ]);
        }
    }

    /**
     * Send webhook alert
     */
    private function sendWebhookAlert(array $alertData): void
    {
        try {
            $webhookUrl = config('backup.monitoring.alerts.webhook.url');
            
            if (!$webhookUrl) {
                return;
            }

            $headers = config('backup.monitoring.alerts.webhook.headers', []);
            $payload = array_merge($alertData, [
                'service' => 'backup-restore-monitoring',
                'environment' => config('app.env')
            ]);

            Http::withHeaders($headers)->post($webhookUrl, $payload);

        } catch (\Exception $e) {
            Log::channel('backup')->error('Failed to send webhook alert', [
                'error' => $e->getMessage(),
                'alert_data' => $alertData
            ]);
        }
    }

    /**
     * Get email subject based on alert type
     */
    private function getEmailSubject(array $alertData): string
    {
        switch ($alertData['type']) {
            case 'failure':
                return "🚨 Backup/Restore Job Failed - {$alertData['job_type']}";
            case 'size_anomaly':
                return "⚠️ Backup Size Anomaly Detected - {$alertData['job_type']}";
            case 'duration_anomaly':
                return "⏱️ Backup Duration Anomaly Detected - {$alertData['job_type']}";
            default:
                return "Backup/Restore Monitoring Alert";
        }
    }

    /**
     * Get email message based on alert type
     */
    private function getEmailMessage(array $alertData): string
    {
        $message = "Backup/Restore Monitoring Alert\n\n";
        $message .= "Job ID: {$alertData['job_id']}\n";
        $message .= "Job Type: {$alertData['job_type']}\n";
        $message .= "Timestamp: {$alertData['timestamp']}\n";

        if (isset($alertData['school_id'])) {
            $message .= "School ID: {$alertData['school_id']}\n";
        }

        switch ($alertData['type']) {
            case 'failure':
                $message .= "\nError: {$alertData['error_message']}\n";
                $message .= "Status: FAILED\n";
                break;
            case 'size_anomaly':
                $message .= "\nSize Anomaly Detected:\n";
                $message .= "Current Size: " . $this->formatBytes($alertData['current_size_bytes']) . "\n";
                $message .= "Average Size: " . $this->formatBytes($alertData['average_size_bytes']) . "\n";
                $message .= "Size Drop: {$alertData['size_drop_percentage']}%\n";
                break;
            case 'duration_anomaly':
                $message .= "\nDuration Anomaly Detected:\n";
                $message .= "Current Duration: " . $this->formatDuration($alertData['current_duration_seconds']) . "\n";
                $message .= "Average Duration: " . $this->formatDuration($alertData['average_duration_seconds']) . "\n";
                $message .= "Duration Increase: {$alertData['duration_increase_percentage']}%\n";
                break;
        }

        $message .= "\nPlease investigate this issue immediately.\n";
        $message .= "Dashboard: " . config('app.url') . "/admin/backup-monitoring";

        return $message;
    }

    /**
     * Build Slack payload
     */
    private function buildSlackPayload(array $alertData): array
    {
        $color = $this->getSlackColor($alertData['type']);
        $title = $this->getSlackTitle($alertData);
        $text = $this->getSlackText($alertData);

        return [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $title,
                    'text' => $text,
                    'fields' => [
                        [
                            'title' => 'Job ID',
                            'value' => $alertData['job_id'],
                            'short' => true
                        ],
                        [
                            'title' => 'Job Type',
                            'value' => $alertData['job_type'],
                            'short' => true
                        ],
                        [
                            'title' => 'Timestamp',
                            'value' => $alertData['timestamp'],
                            'short' => true
                        ]
                    ],
                    'footer' => 'Backup Restore Monitoring',
                    'ts' => now()->timestamp
                ]
            ]
        ];
    }

    /**
     * Get Slack color based on alert type
     */
    private function getSlackColor(string $type): string
    {
        switch ($type) {
            case 'failure':
                return 'danger';
            case 'size_anomaly':
            case 'duration_anomaly':
                return 'warning';
            default:
                return 'good';
        }
    }

    /**
     * Get Slack title based on alert type
     */
    private function getSlackTitle(array $alertData): string
    {
        switch ($alertData['type']) {
            case 'failure':
                return "🚨 Backup/Restore Job Failed";
            case 'size_anomaly':
                return "⚠️ Backup Size Anomaly Detected";
            case 'duration_anomaly':
                return "⏱️ Backup Duration Anomaly Detected";
            default:
                return "Backup/Restore Monitoring Alert";
        }
    }

    /**
     * Get Slack text based on alert type
     */
    private function getSlackText(array $alertData): string
    {
        switch ($alertData['type']) {
            case 'failure':
                return "Error: {$alertData['error_message']}";
            case 'size_anomaly':
                return "Size dropped {$alertData['size_drop_percentage']}% from average";
            case 'duration_anomaly':
                return "Duration increased {$alertData['duration_increase_percentage']}% from average";
            default:
                return "Please check the monitoring dashboard for details.";
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format duration to human readable
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    /**
     * Log job event
     */
    private function logJobEvent(string $event, string $message, array $context = []): void
    {
        Log::channel('backup')->info("Backup Job [{$this->jobId}] {$event}: {$message}", array_merge([
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'event' => $event
        ], $context));
    }

    /**
     * Get dashboard data
     */
    public function getDashboardData(): array
    {
        $now = now();
        
        return [
            'summary' => [
                'total_jobs_today' => BackupJob::whereDate('created_at', $now)->count(),
                'successful_jobs_today' => BackupJob::whereDate('created_at', $now)->where('status', 'success')->count(),
                'failed_jobs_today' => BackupJob::whereDate('created_at', $now)->where('status', 'failed')->count(),
                'running_jobs' => BackupJob::where('status', 'running')->count()
            ],
            'recent_jobs' => BackupJob::with(['school'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
            'job_stats' => $this->getJobStatistics(),
            'alerts' => $this->getRecentAlerts()
        ];
    }

    /**
     * Get job statistics
     */
    private function getJobStatistics(): array
    {
        $last30Days = now()->subDays(30);

        return [
            'average_duration' => BackupJob::where('status', 'success')
                ->where('created_at', '>=', $last30Days)
                ->whereNotNull('duration_seconds')
                ->avg('duration_seconds'),
            'average_size' => BackupJob::where('job_type', 'backup')
                ->where('status', 'success')
                ->where('created_at', '>=', $last30Days)
                ->whereNotNull('backup_size_bytes')
                ->avg('backup_size_bytes'),
            'success_rate' => $this->calculateSuccessRate($last30Days),
            'daily_job_counts' => $this->getDailyJobCounts($last30Days)
        ];
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
     * Get daily job counts
     */
    private function getDailyJobCounts(\Carbon\Carbon $since): array
    {
        return BackupJob::where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total, SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Get recent alerts
     */
    private function getRecentAlerts(): array
    {
        // Get recent log entries that indicate alerts
        return Log::channel('backup')
            ->read()
            ->filter(function ($entry) {
                return str_contains($entry['message'], 'alert sent');
            })
            ->take(10)
            ->values()
            ->toArray();
    }
}
