<?php

namespace App\Console\Commands;

use App\Services\DisasterRecoveryTestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DisasterRecoveryWorkflow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dr:test-workflow
                            {--disk=local : The backup disk to use}
                            {--create-backup : Create a fresh backup before testing}
                            {--notify : Send notification on completion}
                            {--slack-webhook= : Slack webhook URL for notifications}
                            {--json : Output results as JSON}
                            {--save-report : Save report to storage}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automated Disaster Recovery Test Workflow - restores backup to staging and verifies integrity';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting Disaster Recovery Test Workflow');
        $this->newLine();

        $service = new DisasterRecoveryTestService();

        // Run the full DR test
        $results = $service->runFullTest(
            diskName: $this->option('disk'),
            createFreshBackup: $this->option('create-backup')
        );

        // Output results
        if ($this->option('json')) {
            $this->line($service->generateJsonReport());
        } else {
            $this->displayResults($results);
        }

        // Save report if requested
        if ($this->option('save-report')) {
            $this->saveReport($results, $service);
        }

        // Send notifications if requested
        if ($this->option('notify')) {
            $this->sendNotifications($results, $service);
        }

        // Return appropriate exit code
        $exitCode = match ($results['overall_status']) {
            'PASS' => self::SUCCESS,
            'FAIL', 'ERROR' => self::FAILURE,
            default => self::FAILURE,
        };

        return $exitCode;
    }

    /**
     * Display results in a formatted table
     */
    private function displayResults(array $results): void
    {
        // Display steps
        $this->info('📋 Workflow Steps:');
        $stepRows = [];
        foreach ($results['steps'] ?? [] as $name => $step) {
            $statusIcon = $step['status'] === 'complete' ? '✅' : '⏳';
            $stepRows[] = [$name, $statusIcon, $step['message'] ?? ''];
        }
        $this->table(['Step', 'Status', 'Message'], $stepRows);
        $this->newLine();

        // Display integrity checks
        $this->info('🔍 Integrity Checks:');
        $checkRows = [];
        foreach ($results['integrity_checks'] ?? [] as $check) {
            $statusIcon = match ($check['status']) {
                'PASS' => '✅ PASS',
                'FAIL' => '❌ FAIL',
                'WARN' => '⚠️ WARN',
                'SKIP' => '⏭️ SKIP',
                default => $check['status'],
            };
            $message = $check['details']['message'] ?? $check['details']['error'] ?? 'N/A';
            $checkRows[] = [$check['name'], $statusIcon, $message];
        }
        $this->table(['Check', 'Status', 'Details'], $checkRows);
        $this->newLine();

        // Display summary
        $overallStatus = $results['overall_status'] ?? 'UNKNOWN';
        $duration = $results['duration_seconds'] ?? 0;

        if ($overallStatus === 'PASS') {
            $this->info("✅ DISASTER RECOVERY TEST PASSED");
        } elseif ($overallStatus === 'ERROR') {
            $this->error("❌ DISASTER RECOVERY TEST ERROR: " . ($results['error'] ?? 'Unknown error'));
        } else {
            $this->error("❌ DISASTER RECOVERY TEST FAILED");
        }

        $this->line("   Duration: {$duration}s");
        $this->line("   Test ID: " . ($results['test_id'] ?? 'N/A'));
        $this->line("   Staging DB: " . ($results['staging_db'] ?? 'N/A'));
    }

    /**
     * Save report to storage
     */
    private function saveReport(array $results, DisasterRecoveryTestService $service): void
    {
        $filename = 'dr-reports/dr-test-' . date('Y-m-d-His') . '.json';
        
        try {
            Storage::disk('local')->put($filename, $service->generateJsonReport());
            $this->info("📄 Report saved to: storage/app/{$filename}");
        } catch (\Exception $e) {
            $this->warn("Could not save report: " . $e->getMessage());
        }
    }

    /**
     * Send notifications about test results
     */
    private function sendNotifications(array $results, DisasterRecoveryTestService $service): void
    {
        $summary = $service->generateSummary();
        $status = $results['overall_status'] ?? 'UNKNOWN';

        // Log notification
        Log::info('DR Test Notification', [
            'status' => $status,
            'summary' => $summary,
        ]);

        // Slack notification
        $slackWebhook = $this->option('slack-webhook') ?? config('services.slack.dr_webhook');
        if ($slackWebhook) {
            $this->sendSlackNotification($slackWebhook, $results, $service);
        }

        // Discord notification (if configured)
        $discordWebhook = config('services.discord.dr_webhook');
        if ($discordWebhook) {
            $this->sendDiscordNotification($discordWebhook, $results, $service);
        }

        $this->info('📬 Notifications sent');
    }

    /**
     * Send Slack notification
     */
    private function sendSlackNotification(string $webhook, array $results, DisasterRecoveryTestService $service): void
    {
        $status = $results['overall_status'] ?? 'UNKNOWN';
        $color = match ($status) {
            'PASS' => 'good',
            'FAIL' => 'danger',
            default => 'warning',
        };

        $checksSummary = [];
        foreach ($results['integrity_checks'] ?? [] as $check) {
            $icon = match ($check['status']) {
                'PASS' => '✅',
                'FAIL' => '❌',
                'WARN' => '⚠️',
                default => '❓',
            };
            $checksSummary[] = "{$icon} {$check['name']}";
        }

        $payload = [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => '🔄 Disaster Recovery Test Results',
                    'fields' => [
                        [
                            'title' => 'Status',
                            'value' => $status,
                            'short' => true,
                        ],
                        [
                            'title' => 'Duration',
                            'value' => ($results['duration_seconds'] ?? 0) . 's',
                            'short' => true,
                        ],
                        [
                            'title' => 'Test ID',
                            'value' => $results['test_id'] ?? 'N/A',
                            'short' => true,
                        ],
                        [
                            'title' => 'Timestamp',
                            'value' => $results['completed_at'] ?? now()->toIso8601String(),
                            'short' => true,
                        ],
                        [
                            'title' => 'Integrity Checks',
                            'value' => implode("\n", $checksSummary),
                            'short' => false,
                        ],
                    ],
                    'footer' => 'AbsensiQRPro DR Test',
                    'ts' => time(),
                ],
            ],
        ];

        try {
            Http::post($webhook, $payload);
        } catch (\Exception $e) {
            Log::warning('Failed to send Slack notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send Discord notification
     */
    private function sendDiscordNotification(string $webhook, array $results, DisasterRecoveryTestService $service): void
    {
        $status = $results['overall_status'] ?? 'UNKNOWN';
        $color = match ($status) {
            'PASS' => 0x00FF00,
            'FAIL' => 0xFF0000,
            default => 0xFFFF00,
        };

        $checksSummary = [];
        foreach ($results['integrity_checks'] ?? [] as $check) {
            $icon = match ($check['status']) {
                'PASS' => '✅',
                'FAIL' => '❌',
                'WARN' => '⚠️',
                default => '❓',
            };
            $checksSummary[] = "{$icon} {$check['name']}";
        }

        $payload = [
            'embeds' => [
                [
                    'title' => '🔄 Disaster Recovery Test Results',
                    'color' => $color,
                    'fields' => [
                        ['name' => 'Status', 'value' => $status, 'inline' => true],
                        ['name' => 'Duration', 'value' => ($results['duration_seconds'] ?? 0) . 's', 'inline' => true],
                        ['name' => 'Test ID', 'value' => $results['test_id'] ?? 'N/A', 'inline' => true],
                        ['name' => 'Integrity Checks', 'value' => implode("\n", $checksSummary), 'inline' => false],
                    ],
                    'timestamp' => $results['completed_at'] ?? now()->toIso8601String(),
                    'footer' => ['text' => 'AbsensiQRPro DR Test'],
                ],
            ],
        ];

        try {
            Http::post($webhook, $payload);
        } catch (\Exception $e) {
            Log::warning('Failed to send Discord notification', ['error' => $e->getMessage()]);
        }
    }
}
