<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--send-alerts : Send alerts if issues found}';
    protected $description = 'Check system health and optionally send alerts';

    public function handle()
    {
        $this->info('Running health check...');
        $this->newLine();

        $issues = [];
        $checks = [];

        // 1. Database Check
        $checks['database'] = $this->checkDatabase();

        // 2. Redis Check
        $checks['redis'] = $this->checkRedis();

        // 3. Queue Check
        $checks['queue'] = $this->checkQueue();

        // 4. Storage Check
        $checks['storage'] = $this->checkStorage();

        // 5. Application Check
        $checks['application'] = $this->checkApplication();

        // Display results
        foreach ($checks as $name => $result) {
            $status = $result['status'] ? '✅' : '❌';
            $this->line("{$status} {$name}: {$result['message']}");

            if (!$result['status']) {
                $issues[] = "{$name}: {$result['message']}";
            }
        }

        $this->newLine();

        // Summary
        $totalChecks = count($checks);
        $passedChecks = count(array_filter($checks, fn($c) => $c['status']));

        if ($passedChecks === $totalChecks) {
            $this->info("All {$totalChecks} checks passed!");
        } else {
            $this->error($totalChecks - $passedChecks . " check(s) failed!");
        }

        // Send alerts if requested
        if ($this->option('send-alerts') && !empty($issues)) {
            $this->sendAlerts($issues);
        }

        return empty($issues) ? 0 : 1;
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');
            return ['status' => true, 'message' => 'Connected'];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::connection()->ping();
            return ['status' => true, 'message' => 'Connected'];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->whereNull('resolved_at')->count();

            if ($failedJobs > 10) {
                return ['status' => false, 'message' => "High failed jobs: {$failedJobs}"];
            }

            if ($pendingJobs > 1000) {
                return ['status' => false, 'message' => "High queue backlog: {$pendingJobs}"];
            }

            return ['status' => true, 'message' => "Pending: {$pendingJobs}, Failed: {$failedJobs}"];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        $path = storage_path();
        $freeSpace = disk_free_space($path);
        $totalSpace = disk_total_space($path);
        $usedPercent = (($totalSpace - $freeSpace) / $totalSpace) * 100;

        if ($usedPercent > 95) {
            return ['status' => false, 'message' => "Disk usage critical: " . round($usedPercent, 1) . "%"];
        }

        if ($usedPercent > 85) {
            return ['status' => true, 'message' => "Disk usage warning: " . round($usedPercent, 1) . "%"];
        }

        return ['status' => true, 'message' => "Disk usage: " . round($usedPercent, 1) . "%"];
    }

    private function checkApplication(): array
    {
        try {
            // Check if app key is set
            if (!config('app.key')) {
                return ['status' => false, 'message' => 'APP_KEY not set'];
            }

            // Check if debug mode is on (should be off in production)
            if (config('app.debug') && config('app.env') === 'production') {
                return ['status' => false, 'message' => 'Debug mode is ON in production'];
            }

            return ['status' => true, 'message' => 'Application OK'];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => 'Check failed: ' . $e->getMessage()];
        }
    }

    private function sendAlerts(array $issues): void
    {
        $this->info('Sending alerts...');

        $message = "🚨 Health Check Alert\n\n";
        $message .= implode("\n", $issues);
        $message .= "\n\nTime: " . now()->toDateTimeString();
        $message .= "\nServer: " . gethostname();

        // Telegram
        if (config('alerting.telegram.enabled')) {
            $this->sendTelegram($message);
        }

        // Slack
        if (config('alerting.slack.enabled')) {
            $this->sendSlack($message);
        }

        $this->info('Alerts sent!');
    }

    private function sendTelegram(string $message): void
    {
        try {
            $botToken = config('alerting.telegram.bot_token');
            $chatId = config('alerting.telegram.chat_id');

            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to send Telegram alert: ' . $e->getMessage());
        }
    }

    private function sendSlack(string $message): void
    {
        try {
            $webhookUrl = config('alerting.slack.webhook_url');

            Http::post($webhookUrl, [
                'text' => $message,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to send Slack alert: ' . $e->getMessage());
        }
    }
}
