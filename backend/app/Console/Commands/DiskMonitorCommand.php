<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DiskMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'disk:monitor
                            {--disk-threshold=90 : Disk space percentage threshold for alerts}
                            {--cleanup-days=7 : Days to keep temporary files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor disk space and clean up old files';

    /**
     * Alert cooldown in minutes
     */
    private const ALERT_COOLDOWN = 60;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting disk space monitoring...');

        // Monitor disk space
        $this->monitorDiskSpace();

        // Monitor storage directory usage
        $this->monitorStorageUsage();

        // Clean up old files
        $this->cleanupOldFiles();

        $this->info('Disk space monitoring completed.');

        return Command::SUCCESS;
    }

    /**
     * Monitor disk space usage
     */
    private function monitorDiskSpace(): void
    {
        $threshold = (int) $this->option('disk-threshold');

        try {
            $path = base_path();
            
            // Get disk space information
            $totalSpace = disk_total_space($path);
            $freeSpace = disk_free_space($path);
            $usedSpace = $totalSpace - $freeSpace;
            
            // Calculate percentage
            $usedPercent = round(($usedSpace / $totalSpace) * 100, 2);
            $freePercent = round(($freeSpace / $totalSpace) * 100, 2);
            
            // Format sizes
            $totalGB = round($totalSpace / 1024 / 1024 / 1024, 2);
            $usedGB = round($usedSpace / 1024 / 1024 / 1024, 2);
            $freeGB = round($freeSpace / 1024 / 1024 / 1024, 2);

            $this->line("Disk Space: {$usedGB}GB / {$totalGB}GB used ({$usedPercent}%), {$freeGB}GB free");

            // Log metrics
            Log::channel('system')->info('Disk space monitored', [
                'total_bytes' => $totalSpace,
                'used_bytes' => $usedSpace,
                'free_bytes' => $freeSpace,
                'used_percent' => $usedPercent,
                'free_percent' => $freePercent,
                'total_gb' => $totalGB,
                'used_gb' => $usedGB,
                'free_gb' => $freeGB,
            ]);

            // Check threshold and alert if needed
            if ($usedPercent > $threshold) {
                $this->warn("⚠️  Disk space is {$usedPercent}% full (threshold: {$threshold}%)");
                $this->sendDiskSpaceAlert($usedPercent, $threshold, $usedGB, $totalGB, $freeGB);
            }
        } catch (\Exception $e) {
            $this->error("Failed to monitor disk space: {$e->getMessage()}");
            Log::channel('system')->error('Disk space monitoring failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Monitor storage directory usage
     */
    private function monitorStorageUsage(): void
    {
        try {
            $storagePath = storage_path();
            $directories = [
                'app' => storage_path('app'),
                'logs' => storage_path('logs'),
                'framework' => storage_path('framework'),
                'exports' => storage_path('app/exports'),
                'temp' => storage_path('app/temp'),
            ];

            $this->line("\nStorage Directory Usage:");

            foreach ($directories as $name => $path) {
                if (!is_dir($path)) {
                    continue;
                }

                $size = $this->getDirectorySize($path);
                $sizeMB = round($size / 1024 / 1024, 2);

                $this->line("  {$name}: {$sizeMB}MB");

                // Log metrics
                Log::channel('system')->info('Storage directory monitored', [
                    'directory' => $name,
                    'path' => $path,
                    'size_bytes' => $size,
                    'size_mb' => $sizeMB,
                ]);
            }
        } catch (\Exception $e) {
            $this->error("Failed to monitor storage usage: {$e->getMessage()}");
            Log::channel('system')->error('Storage monitoring failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clean up old files
     */
    private function cleanupOldFiles(): void
    {
        $days = (int) $this->option('cleanup-days');
        $cutoffDate = now()->subDays($days);

        $this->line("\nCleaning up files older than {$days} days...");

        try {
            // Clean old export files
            $exportsCleaned = $this->cleanupDirectory(
                storage_path('app/exports'),
                $cutoffDate,
                ['xlsx', 'pdf', 'csv']
            );

            // Clean old temporary files
            $tempCleaned = $this->cleanupDirectory(
                storage_path('app/temp'),
                $cutoffDate,
                ['tmp', 'temp']
            );

            // Clean old log files (keep last 30 days)
            $logsCleaned = $this->cleanupDirectory(
                storage_path('logs'),
                now()->subDays(30),
                ['log']
            );

            $totalCleaned = $exportsCleaned + $tempCleaned + $logsCleaned;

            $this->info("Cleaned up {$totalCleaned} old files:");
            $this->line("  - Exports: {$exportsCleaned} files");
            $this->line("  - Temp: {$tempCleaned} files");
            $this->line("  - Logs: {$logsCleaned} files");

            // Log cleanup results
            Log::channel('system')->info('Old files cleaned up', [
                'exports_cleaned' => $exportsCleaned,
                'temp_cleaned' => $tempCleaned,
                'logs_cleaned' => $logsCleaned,
                'total_cleaned' => $totalCleaned,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'cleanup_days' => $days,
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to clean up old files: {$e->getMessage()}");
            Log::channel('system')->error('File cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;

        if (!is_dir($path)) {
            return 0;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            Log::channel('system')->warning('Failed to calculate directory size', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $size;
    }

    /**
     * Clean up old files in a directory
     */
    private function cleanupDirectory(string $path, \Carbon\Carbon $cutoffDate, array $extensions = []): int
    {
        $cleaned = 0;

        if (!is_dir($path)) {
            return 0;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                // Check extension if specified
                if (!empty($extensions)) {
                    $fileExtension = strtolower($file->getExtension());
                    if (!in_array($fileExtension, $extensions)) {
                        continue;
                    }
                }

                // Check file age
                $fileTime = \Carbon\Carbon::createFromTimestamp($file->getMTime());
                if ($fileTime->lessThan($cutoffDate)) {
                    try {
                        unlink($file->getPathname());
                        $cleaned++;
                    } catch (\Exception $e) {
                        Log::channel('system')->warning('Failed to delete old file', [
                            'file' => $file->getPathname(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('system')->error('Failed to cleanup directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $cleaned;
    }

    /**
     * Send disk space alert with cooldown
     */
    private function sendDiskSpaceAlert(float $usedPercent, int $threshold, float $usedGB, float $totalGB, float $freeGB): void
    {
        $cacheKey = 'disk_space_alert';

        // Check if we should send alert (cooldown)
        if (!$this->shouldSendAlert($cacheKey)) {
            return;
        }

        $message = "Disk space is {$usedPercent}% full ({$usedGB}GB / {$totalGB}GB used, {$freeGB}GB free) - threshold: {$threshold}%";

        // Log alert
        Log::channel('system')->warning('Disk space alert triggered', [
            'used_percent' => $usedPercent,
            'threshold' => $threshold,
            'used_gb' => $usedGB,
            'total_gb' => $totalGB,
            'free_gb' => $freeGB,
        ]);

        // Send notifications
        $this->sendAlert('Disk Space Alert', $message, [
            'used_percent' => $usedPercent,
            'threshold' => $threshold,
            'used_gb' => $usedGB,
            'total_gb' => $totalGB,
            'free_gb' => $freeGB,
            'type' => 'disk_space',
        ]);

        // Update alert cooldown
        $this->updateAlertCooldown($cacheKey);
    }

    /**
     * Check if alert should be sent (cooldown)
     */
    private function shouldSendAlert(string $cacheKey): bool
    {
        $lastAlert = Cache::get($cacheKey);

        if (!$lastAlert) {
            return true;
        }

        $cooldown = self::ALERT_COOLDOWN;
        $lastSentAt = $lastAlert['sent_at'] ?? now()->subMinutes($cooldown + 1);

        return now()->diffInMinutes($lastSentAt) >= $cooldown;
    }

    /**
     * Update alert cooldown
     */
    private function updateAlertCooldown(string $cacheKey): void
    {
        Cache::put($cacheKey, [
            'sent_at' => now(),
        ], now()->addMinutes(self::ALERT_COOLDOWN * 2));
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
            $fields[] = [
                'type' => 'mrkdwn',
                'text' => sprintf('*%s:* %s', ucfirst(str_replace('_', ' ', $key)), $this->formatValue($value)),
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
                \Illuminate\Support\Facades\Mail::raw(
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
            $body .= sprintf("%s: %s\n", ucfirst(str_replace('_', ' ', $key)), $this->formatValue($value));
        }

        $body .= "\n--------\n";
        $body .= sprintf("Time: %s\n", now()->toDateTimeString());
        $body .= sprintf("Environment: %s\n", config('app.env'));

        return $body;
    }
}
