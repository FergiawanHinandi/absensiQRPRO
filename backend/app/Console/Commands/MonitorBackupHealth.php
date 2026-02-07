<?php

namespace App\Console\Commands;

use App\Services\SecurityAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Helpers\Format;

class MonitorBackupHealth extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'backup:health-check 
                            {--max-age=26 : Maximum backup age in hours before alerting}
                            {--silent : Suppress output except errors}';

    /**
     * The console command description.
     */
    protected $description = 'Monitor backup health and create security alerts on failures';

    protected SecurityAlertService $alertService;

    public function __construct(SecurityAlertService $alertService)
    {
        parent::__construct();
        $this->alertService = $alertService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $maxAgeHours = (int) $this->option('max-age');
        $silent = $this->option('silent');

        if (! $silent) {
            $this->info('🔍 Checking backup health...');
            $this->newLine();
        }

        $issues = [];
        $statuses = $this->getBackupStatuses();

        foreach ($statuses as $status) {
            $statusResult = $this->checkBackupStatus($status, $maxAgeHours);

            if (! $statusResult['healthy']) {
                $issues[] = $statusResult;
            }

            if (! $silent) {
                $this->displayStatus($statusResult);
            }
        }

        // If no backups found at all, that's an issue
        if (empty($statuses)) {
            $issues[] = [
                'healthy' => false,
                'name' => config('backup.backup.name', 'AbsensiQRPro'),
                'disk' => 'unknown',
                'message' => 'No backup destinations configured or accessible',
                'severity' => 'critical',
            ];
        }

        // Handle any issues found
        if (! empty($issues)) {
            $this->handleBackupIssues($issues);

            return Command::FAILURE;
        }

        if (! $silent) {
            $this->newLine();
            $this->info('✅ All backups are healthy');
        }

        return Command::SUCCESS;
    }

    /**
     * Get backup statuses from configured disks.
     * Uses direct BackupDestination instead of deprecated StatusFactory.
     */
    protected function getBackupStatuses(): array
    {
        $statuses = [];
        $monitorConfig = config('backup.monitor_backups', []);

        foreach ($monitorConfig as $config) {
            foreach ($config['disks'] ?? [] as $diskName) {
                try {
                    // Check if disk is configured before trying to create destination
                    $diskConfig = config("filesystems.disks.{$diskName}");
                    if (!$diskConfig) {
                        Log::channel('backup')->warning("Disk not configured, skipping: {$diskName}");
                        continue;
                    }

                    // Skip S3 disks if credentials are not configured
                    if (($diskConfig['driver'] ?? '') === 's3') {
                        if (empty($diskConfig['key']) || empty($diskConfig['bucket'])) {
                            Log::channel('backup')->info("S3 disk '{$diskName}' not configured, skipping");
                            continue;
                        }
                    }

                    $backupDestination = BackupDestination::create($diskName, $config['name']);
                    $statuses[] = [
                        'destination' => $backupDestination,
                        'health_checks' => $config['health_checks'] ?? [],
                    ];
                } catch (\Exception $e) {
                    Log::channel('backup')->warning("Cannot check backup status for disk: {$diskName}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $statuses;
    }

    /**
     * Check the health of a backup destination.
     */
    protected function checkBackupStatus(array $statusData, int $maxAgeHours): array
    {
        $destination = $statusData['destination'];
        $newestBackup = $destination->newestBackup();

        $result = [
            'healthy' => true,
            'name' => $destination->backupName(),
            'disk' => $destination->diskName(),
            'message' => 'Backup is healthy',
            'severity' => 'info',
            'backup_count' => $destination->backups()->count(),
            'newest_backup_at' => null,
            'backup_age_hours' => null,
            'total_size' => Format::humanReadableSize($destination->usedStorage()),
        ];

        // Check if any backup exists
        if (! $newestBackup) {
            $result['healthy'] = false;
            $result['message'] = 'No backups found on this disk';
            $result['severity'] = 'critical';

            return $result;
        }

        $result['newest_backup_at'] = $newestBackup->date()->toIso8601String();
        $ageHours = $newestBackup->date()->diffInHours(now());
        $result['backup_age_hours'] = $ageHours;

        // Check backup age
        if ($ageHours > $maxAgeHours) {
            $result['healthy'] = false;
            $result['message'] = "Backup is {$ageHours} hours old (max: {$maxAgeHours} hours)";
            $result['severity'] = $ageHours > ($maxAgeHours * 2) ? 'critical' : 'high';
        }

        // Run health checks from config
        $healthChecks = $statusData['health_checks'] ?? [];
        foreach ($healthChecks as $checkClass => $checkValue) {
            try {
                if (is_a($checkClass, \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class, true)) {
                    $maxAgeDays = $checkValue;
                    if ($newestBackup->date()->diffInDays(now()) > $maxAgeDays) {
                        $result['healthy'] = false;
                        $result['message'] = "Backup is older than {$maxAgeDays} days";
                        $result['severity'] = 'critical';
                    }
                }
                if (is_a($checkClass, \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class, true)) {
                    $maxStorageMb = $checkValue;
                    $usedStorageMb = $destination->usedStorage() / 1024 / 1024;
                    if ($usedStorageMb > $maxStorageMb) {
                        $result['healthy'] = false;
                        $result['message'] = "Backup storage ({$usedStorageMb}MB) exceeds limit ({$maxStorageMb}MB)";
                        $result['severity'] = 'high';
                    }
                }
            } catch (\Exception $e) {
                $result['healthy'] = false;
                $result['message'] = $e->getMessage();
                $result['severity'] = 'high';
            }
        }

        return $result;
    }

    /**
     * Display status in console.
     */
    protected function displayStatus(array $status): void
    {
        $icon = $status['healthy'] ? '✅' : '❌';
        $this->line("{$icon} {$status['name']} ({$status['disk']})");
        $this->line("   Message: {$status['message']}");

        if ($status['newest_backup_at']) {
            $this->line("   Last backup: {$status['newest_backup_at']}");
            $this->line("   Age: {$status['backup_age_hours']} hours");
        }

        if (isset($status['total_size'])) {
            $this->line("   Total size: {$status['total_size']}");
        }

        $this->newLine();
    }

    /**
     * Handle backup issues - create alerts.
     */
    protected function handleBackupIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->error("❌ Backup issue: {$issue['message']}");

            // Create security alert
            $this->alertService->createAlert(
                'backup_failure',
                $issue['severity'],
                "Backup health check failed: {$issue['message']}",
                [
                    'backup_name' => $issue['name'],
                    'disk' => $issue['disk'],
                    'backup_age_hours' => $issue['backup_age_hours'] ?? null,
                    'newest_backup_at' => $issue['newest_backup_at'] ?? null,
                ],
                null, // No specific user
                null, // System-wide
                request()->ip() ?? '127.0.0.1',
                null,
                true // Force notify - backup failures are critical
            );

            Log::channel('backup')->critical('Backup health check failed', $issue);
        }
    }
}
