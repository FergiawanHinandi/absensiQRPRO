<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:monitor
                            {--memory-threshold=80 : Memory usage percentage threshold for alerts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Redis health, memory usage, and connection errors';

    /**
     * Alert cooldown in minutes
     */
    private const ALERT_COOLDOWN = 60;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Redis monitoring...');

        // Monitor Redis memory usage
        $this->monitorMemoryUsage();

        // Monitor connection health
        $this->monitorConnectionHealth();

        // Monitor connection latency
        $this->monitorConnectionLatency();

        $this->info('Redis monitoring completed.');

        return Command::SUCCESS;
    }

    /**
     * Monitor Redis memory usage
     */
    private function monitorMemoryUsage(): void
    {
        $threshold = (int) $this->option('memory-threshold');

        try {
            $redis = Redis::connection();
            $info = $redis->info('memory');

            // Parse memory info
            $usedMemory = $info['used_memory'] ?? 0;
            $maxMemory = $info['maxmemory'] ?? 0;

            // Calculate percentage
            if ($maxMemory > 0) {
                $memoryPercent = round(($usedMemory / $maxMemory) * 100, 2);
            } else {
                // If maxmemory is 0, Redis has no memory limit
                $memoryPercent = 0;
                $this->warn('⚠️  Redis has no memory limit configured (maxmemory=0)');
            }

            // Format memory values
            $usedMemoryMB = round($usedMemory / 1024 / 1024, 2);
            $maxMemoryMB = $maxMemory > 0 ? round($maxMemory / 1024 / 1024, 2) : 'unlimited';

            $this->line("Redis Memory: {$usedMemoryMB}MB / {$maxMemoryMB}MB ({$memoryPercent}%)");

            // Log metrics
            Log::channel('system')->info('Redis memory monitored', [
                'used_memory_bytes' => $usedMemory,
                'max_memory_bytes' => $maxMemory,
                'memory_percent' => $memoryPercent,
                'used_memory_mb' => $usedMemoryMB,
                'max_memory_mb' => $maxMemoryMB,
            ]);

            // Check threshold and alert if needed
            if ($maxMemory > 0 && $memoryPercent > $threshold) {
                $this->warn("⚠️  Redis memory usage is {$memoryPercent}% (threshold: {$threshold}%)");
                $this->sendMemoryAlert($memoryPercent, $threshold, $usedMemoryMB, $maxMemoryMB);
            }
        } catch (\Exception $e) {
            $this->error("Failed to monitor Redis memory: {$e->getMessage()}");
            $this->logConnectionError('memory_monitoring', $e);
        }
    }

    /**
     * Monitor Redis connection health
     */
    private function monitorConnectionHealth(): void
    {
        try {
            $redis = Redis::connection();
            
            // Test connection with PING
            $startTime = microtime(true);
            $response = $redis->ping();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($response === 'PONG' || $response === true) {
                $this->line("Redis Connection: Healthy (response time: {$responseTime}ms)");
                
                Log::channel('system')->info('Redis connection healthy', [
                    'response_time_ms' => $responseTime,
                ]);
            } else {
                $this->warn("⚠️  Redis connection returned unexpected response: {$response}");
                $this->sendConnectionAlert('Unexpected PING response', $response);
            }
        } catch (\Exception $e) {
            $this->error("Redis connection failed: {$e->getMessage()}");
            $this->logConnectionError('connection_health', $e);
            $this->sendConnectionAlert('Connection failed', $e->getMessage());
        }
    }

    /**
     * Monitor Redis connection latency
     */
    private function monitorConnectionLatency(): void
    {
        try {
            $redis = Redis::connection();
            $latencies = [];

            // Perform multiple PING tests to get average latency
            for ($i = 0; $i < 5; $i++) {
                $startTime = microtime(true);
                $redis->ping();
                $latency = (microtime(true) - $startTime) * 1000;
                $latencies[] = $latency;
            }

            $avgLatency = round(array_sum($latencies) / count($latencies), 2);
            $minLatency = round(min($latencies), 2);
            $maxLatency = round(max($latencies), 2);

            $this->line("Redis Latency: avg={$avgLatency}ms, min={$minLatency}ms, max={$maxLatency}ms");

            // Log metrics
            Log::channel('system')->info('Redis latency monitored', [
                'avg_latency_ms' => $avgLatency,
                'min_latency_ms' => $minLatency,
                'max_latency_ms' => $maxLatency,
            ]);

            // Alert if latency is too high (> 100ms average)
            if ($avgLatency > 100) {
                $this->warn("⚠️  Redis latency is high: {$avgLatency}ms average");
                $this->sendLatencyAlert($avgLatency, $minLatency, $maxLatency);
            }
        } catch (\Exception $e) {
            $this->error("Failed to monitor Redis latency: {$e->getMessage()}");
            $this->logConnectionError('latency_monitoring', $e);
        }
    }

    /**
     * Log Redis connection error
     */
    private function logConnectionError(string $operation, \Exception $e): void
    {
        Log::channel('system')->error('Redis connection error', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Track failover events
        if (str_contains($e->getMessage(), 'failover') || str_contains($e->getMessage(), 'master')) {
            Log::channel('system')->critical('Redis failover event detected', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send memory usage alert with cooldown
     */
    private function sendMemoryAlert(float $percent, int $threshold, float $usedMB, $maxMB): void
    {
        $cacheKey = 'redis_memory_alert';

        // Check if we should send alert (cooldown)
        if (!$this->shouldSendAlert($cacheKey)) {
            return;
        }

        $message = "Redis memory usage is {$percent}% ({$usedMB}MB / {$maxMB}MB) - threshold: {$threshold}%";

        // Log alert
        Log::channel('system')->warning('Redis memory alert triggered', [
            'memory_percent' => $percent,
            'threshold' => $threshold,
            'used_memory_mb' => $usedMB,
            'max_memory_mb' => $maxMB,
        ]);

        // Send notifications
        $this->sendAlert('Redis Memory Alert', $message, [
            'memory_percent' => $percent,
            'threshold' => $threshold,
            'used_memory_mb' => $usedMB,
            'max_memory_mb' => $maxMB,
            'type' => 'redis_memory',
        ]);

        // Update alert cooldown
        $this->updateAlertCooldown($cacheKey);
    }

    /**
     * Send connection alert with cooldown
     */
    private function sendConnectionAlert(string $reason, string $details): void
    {
        $cacheKey = 'redis_connection_alert';

        // Check if we should send alert (cooldown)
        if (!$this->shouldSendAlert($cacheKey)) {
            return;
        }

        $message = "Redis connection issue: {$reason}";

        // Log alert
        Log::channel('system')->error('Redis connection alert triggered', [
            'reason' => $reason,
            'details' => $details,
        ]);

        // Send notifications
        $this->sendAlert('Redis Connection Alert', $message, [
            'reason' => $reason,
            'details' => $details,
            'type' => 'redis_connection',
        ]);

        // Update alert cooldown
        $this->updateAlertCooldown($cacheKey);
    }

    /**
     * Send latency alert with cooldown
     */
    private function sendLatencyAlert(float $avgLatency, float $minLatency, float $maxLatency): void
    {
        $cacheKey = 'redis_latency_alert';

        // Check if we should send alert (cooldown)
        if (!$this->shouldSendAlert($cacheKey)) {
            return;
        }

        $message = "Redis latency is high: avg={$avgLatency}ms, min={$minLatency}ms, max={$maxLatency}ms";

        // Log alert
        Log::channel('system')->warning('Redis latency alert triggered', [
            'avg_latency_ms' => $avgLatency,
            'min_latency_ms' => $minLatency,
            'max_latency_ms' => $maxLatency,
        ]);

        // Send notifications
        $this->sendAlert('Redis Latency Alert', $message, [
            'avg_latency_ms' => $avgLatency,
            'min_latency_ms' => $minLatency,
            'max_latency_ms' => $maxLatency,
            'type' => 'redis_latency',
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
