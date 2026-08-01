<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use App\Notifications\QueueAlertNotification;

class QueueMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:monitor
                            {--alert-threshold=1000 : Queue size threshold for alerts}
                            {--failed-threshold=10 : Failed jobs threshold for alerts}
                            {--check-failed : Check failed jobs (runs hourly)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor queue health and alert on issues';

    /**
     * Alert cooldown in minutes (exponential backoff)
     */
    private const ALERT_COOLDOWN_BASE = 5;
    private const ALERT_COOLDOWN_MAX = 60;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting queue monitoring...');

        // Monitor queue size (runs every minute)
        $this->monitorQueueSize();

        // Monitor failed jobs (runs hourly via --check-failed flag)
        if ($this->option('check-failed')) {
            $this->monitorFailedJobs();
        }

        $this->info('Queue monitoring completed.');

        return Command::SUCCESS;
    }

    /**
     * Monitor queue size for all connections
     */
    private function monitorQueueSize(): void
    {
        $threshold = (int) $this->option('alert-threshold');
        $connections = $this->getQueueConnections();

        foreach ($connections as $connection) {
            $queueSize = $this->getQueueSize($connection);

            $this->line("Queue '{$connection}': {$queueSize} jobs");

            // Log metrics
            Log::channel('system')->info('Queue size monitored', [
                'connection' => $connection,
                'size' => $queueSize,
                'threshold' => $threshold,
            ]);

            // Check threshold and alert if needed
            if ($queueSize > $threshold) {
                $this->warn("⚠️  Queue '{$connection}' has {$queueSize} jobs (threshold: {$threshold})");
                $this->sendQueueSizeAlert($connection, $queueSize, $threshold);
            }
        }
    }

    /**
     * Monitor failed jobs
     */
    private function monitorFailedJobs(): void
    {
        $threshold = (int) $this->option('failed-threshold');
        $failedCount = $this->getFailedJobsCount();

        $this->line("Failed jobs: {$failedCount}");

        // Log metrics
        Log::channel('system')->info('Failed jobs monitored', [
            'count' => $failedCount,
            'threshold' => $threshold,
        ]);

        // Check threshold and alert if needed
        if ($failedCount > $threshold) {
            $this->warn("⚠️  Failed jobs count: {$failedCount} (threshold: {$threshold})");
            $this->sendFailedJobsAlert($failedCount, $threshold);
        }
    }

    /**
     * Get all configured queue connections
     */
    private function getQueueConnections(): array
    {
        $connections = [];
        $default = config('queue.default');

        // Add default connection
        $connections[] = $default;

        // Add other common connections if configured
        $otherConnections = ['database', 'redis', 'sync'];
        foreach ($otherConnections as $conn) {
            if ($conn !== $default && config("queue.connections.{$conn}")) {
                $connections[] = $conn;
            }
        }

        return array_unique($connections);
    }

    /**
     * Get queue size for a connection
     */
    private function getQueueSize(string $connection): int
    {
        try {
            $driver = config("queue.connections.{$connection}.driver");

            return match ($driver) {
                'database' => $this->getDatabaseQueueSize($connection),
                'redis' => $this->getRedisQueueSize($connection),
                'sync' => 0, // Sync driver has no queue
                default => 0,
            };
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to get queue size', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Get database queue size
     */
    private function getDatabaseQueueSize(string $connection): int
    {
        $table = config("queue.connections.{$connection}.table", 'jobs');
        $dbConnection = config("queue.connections.{$connection}.connection");

        return DB::connection($dbConnection)->table($table)->count();
    }

    /**
     * Get Redis queue size
     */
    private function getRedisQueueSize(string $connection): int
    {
        $queue = config("queue.connections.{$connection}.queue", 'default');
        $redisConnection = config("queue.connections.{$connection}.connection", 'default');

        try {
            $redis = app('redis')->connection($redisConnection);
            return $redis->llen("queues:{$queue}");
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to get Redis queue size', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Get failed jobs count
     */
    private function getFailedJobsCount(): int
    {
        try {
            $table = config('queue.failed.table', 'failed_jobs');
            $dbConnection = config('queue.failed.database');

            return DB::connection($dbConnection)->table($table)->count();
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to get failed jobs count', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Send queue size alert with exponential backoff
     */
    private function sendQueueSizeAlert(string $connection, int $size, int $threshold): void
    {
        $cacheKey = "queue_alert:{$connection}";

        // Check if we should send alert (exponential backoff)
        if (!$this->shouldSendAlert($cacheKey)) {
            return;
        }

        $message = "Queue '{$connection}' has {$size} jobs (threshold: {$threshold})";

        // Log alert
        Log::channel('system')->warning('Queue size alert triggered', [
            'connection' => $connection,
            'size' => $size,
            'threshold' => $threshold,
        ]);

        // Send notifications
        $this->sendAlert('Queue Size Alert', $message, [
            'connection' => $connection,
            'size' => $size,
            'threshold' => $threshold,
            'type' => 'queue_size',
        ]);

        // Update alert cooldown
        $this->updateAlertCooldown($cacheKey);
    }

    /**
     * Send failed jobs alert with exponential backoff
     */
    private function sendFailedJobsAlert(int $count, int $threshold): void
    {
        $cacheKey = 'failed_jobs_alert';

        // Check if we should send alert (exponential backoff)
        if (!$this->shouldSendAlert($cacheKey)) {
            return;
        }

        // Get recent failed jobs details
        $recentFailures = $this->getRecentFailedJobs(5);

        $message = "Failed jobs count: {$count} (threshold: {$threshold})";

        // Log alert
        Log::channel('system')->warning('Failed jobs alert triggered', [
            'count' => $count,
            'threshold' => $threshold,
            'recent_failures' => $recentFailures,
        ]);

        // Send notifications
        $this->sendAlert('Failed Jobs Alert', $message, [
            'count' => $count,
            'threshold' => $threshold,
            'recent_failures' => $recentFailures,
            'type' => 'failed_jobs',
        ]);

        // Update alert cooldown
        $this->updateAlertCooldown($cacheKey);
    }

    /**
     * Get recent failed jobs details
     */
    private function getRecentFailedJobs(int $limit = 5): array
    {
        try {
            $table = config('queue.failed.table', 'failed_jobs');
            $dbConnection = config('queue.failed.database');

            $failures = DB::connection($dbConnection)
                ->table($table)
                ->orderBy('failed_at', 'desc')
                ->limit($limit)
                ->get(['uuid', 'connection', 'queue', 'exception', 'failed_at'])
                ->map(function ($job) {
                    // Extract exception message (first line only)
                    $exceptionLines = explode("\n", $job->exception);
                    $exceptionMessage = $exceptionLines[0] ?? 'Unknown error';

                    return [
                        'uuid' => $job->uuid,
                        'connection' => $job->connection,
                        'queue' => $job->queue,
                        'exception' => $exceptionMessage,
                        'failed_at' => $job->failed_at,
                    ];
                })
                ->toArray();

            return $failures;
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to get recent failed jobs', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if alert should be sent (exponential backoff)
     */
    private function shouldSendAlert(string $cacheKey): bool
    {
        $lastAlert = Cache::get($cacheKey);

        if (!$lastAlert) {
            return true;
        }

        $cooldown = $lastAlert['cooldown'] ?? self::ALERT_COOLDOWN_BASE;
        $lastSentAt = $lastAlert['sent_at'] ?? now()->subMinutes($cooldown + 1);

        return now()->diffInMinutes($lastSentAt) >= $cooldown;
    }

    /**
     * Update alert cooldown with exponential backoff
     */
    private function updateAlertCooldown(string $cacheKey): void
    {
        $lastAlert = Cache::get($cacheKey);
        $currentCooldown = $lastAlert['cooldown'] ?? self::ALERT_COOLDOWN_BASE;

        // Exponential backoff: 5, 10, 20, 40, 60 (max)
        $newCooldown = min($currentCooldown * 2, self::ALERT_COOLDOWN_MAX);

        Cache::put($cacheKey, [
            'sent_at' => now(),
            'cooldown' => $newCooldown,
        ], now()->addMinutes($newCooldown * 2));
    }

    /**
     * Send alert via configured channels
     */
    private function sendAlert(string $title, string $message, array $context = []): void
    {
        // Check if alerts are enabled
        if (!config('monitoring.alerts.enabled', true)) {
            return;
        }

        // Suppress alerts during maintenance mode
        if (app()->isDownForMaintenance() && config('monitoring.maintenance.suppress_alerts', true)) {
            Log::channel('system')->info('Alert suppressed during maintenance', [
                'title' => $title,
                'message' => $message,
            ]);
            return;
        }

        // Send to Slack if configured
        if ($webhookUrl = config('monitoring.alerts.slack_webhook_url') ?? config('logging.channels.slack.url')) {
            $this->sendSlackAlert($webhookUrl, $title, $message, $context);
        }

        // Send email if configured
        if ($emails = config('monitoring.alerts.alert_emails')) {
            $this->sendEmailAlert($emails, $title, $message, $context);
        }

        // Log if no channels configured
        if (!config('monitoring.alerts.slack_webhook_url') && !config('logging.channels.slack.url') && !config('monitoring.alerts.alert_emails')) {
            Log::channel('system')->warning('No alert channels configured', [
                'title' => $title,
                'message' => $message,
                'context' => $context,
            ]);
        }
    }

    /**
     * Send Slack alert
     */
    private function sendSlackAlert(string $webhookUrl, string $title, string $message, array $context): void
    {
        try {
            $payload = [
                'text' => "🚨 *{$title}*",
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => $title,
                        ],
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => $message,
                        ],
                    ],
                    [
                        'type' => 'section',
                        'fields' => $this->formatSlackFields($context),
                    ],
                    [
                        'type' => 'context',
                        'elements' => [
                            [
                                'type' => 'mrkdwn',
                                'text' => sprintf('*Time:* %s | *Environment:* %s', now()->toDateTimeString(), config('app.env')),
                            ],
                        ],
                    ],
                ],
            ];

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::channel('system')->error('Failed to send Slack alert', [
                    'http_code' => $httpCode,
                    'response' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('system')->error('Exception sending Slack alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format context for Slack fields
     */
    private function formatSlackFields(array $context): array
    {
        $fields = [];

        foreach ($context as $key => $value) {
            if ($key === 'recent_failures') {
                continue; // Handle separately
            }

            $fields[] = [
                'type' => 'mrkdwn',
                'text' => sprintf('*%s:* %s', ucfirst(str_replace('_', ' ', $key)), $this->formatValue($value)),
            ];
        }

        // Add recent failures if present
        if (isset($context['recent_failures']) && !empty($context['recent_failures'])) {
            $failureText = "*Recent Failures:*\n";
            foreach ($context['recent_failures'] as $failure) {
                $failureText .= sprintf(
                    "• `%s` - %s (%s)\n",
                    substr($failure['uuid'], 0, 8),
                    $failure['exception'],
                    $failure['failed_at']
                );
            }
            $fields[] = [
                'type' => 'mrkdwn',
                'text' => $failureText,
            ];
        }

        return $fields;
    }

    /**
     * Format value for display
     */
    private function formatValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Send email alert
     */
    private function sendEmailAlert(string $emails, string $title, string $message, array $context): void
    {
        try {
            $recipients = explode(',', $emails);

            foreach ($recipients as $email) {
                Mail::raw(
                    $this->formatEmailBody($title, $message, $context),
                    function ($mail) use ($email, $title) {
                        $mail->to(trim($email))
                            ->subject("[AbsensiQR] {$title}");
                    }
                );
            }
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to send email alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format email body
     */
    private function formatEmailBody(string $title, string $message, array $context): string
    {
        $body = "{$title}\n\n";
        $body .= "{$message}\n\n";
        $body .= "Details:\n";
        $body .= "--------\n";

        foreach ($context as $key => $value) {
            if ($key === 'recent_failures' && is_array($value)) {
                $body .= "\nRecent Failures:\n";
                foreach ($value as $failure) {
                    $body .= sprintf(
                        "  - %s: %s (%s)\n",
                        $failure['uuid'],
                        $failure['exception'],
                        $failure['failed_at']
                    );
                }
            } else {
                $body .= sprintf("%s: %s\n", ucfirst(str_replace('_', ' ', $key)), $this->formatValue($value));
            }
        }

        $body .= "\n--------\n";
        $body .= sprintf("Time: %s\n", now()->toDateTimeString());
        $body .= sprintf("Environment: %s\n", config('app.env'));

        return $body;
    }
}
