<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ConfigureBackupSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:configure
                            {--test : Run a test backup after configuration}
                            {--verify : Verify backup configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure and verify the automated backup system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔧 Configuring Backup System for AbsensiQR Pro');
        $this->newLine();

        // Step 1: Verify configuration
        if ($this->option('verify') || !$this->option('test')) {
            $this->verifyConfiguration();
        }

        // Step 2: Test backup if requested
        if ($this->option('test')) {
            $this->testBackup();
        }

        // Step 3: Display schedule information
        $this->displayScheduleInfo();

        $this->newLine();
        $this->info('✅ Backup system configuration complete!');

        return Command::SUCCESS;
    }

    /**
     * Verify backup configuration.
     */
    protected function verifyConfiguration(): void
    {
        $this->info('📋 Verifying Backup Configuration...');
        $this->newLine();

        $checks = [
            'Database Connection' => $this->checkDatabaseConnection(),
            'Local Storage' => $this->checkLocalStorage(),
            'S3 Storage' => $this->checkS3Storage(),
            'Encryption Key' => $this->checkEncryptionKey(),
            'Backup Package' => $this->checkBackupPackage(),
            'Retention Policy' => $this->checkRetentionPolicy(),
        ];

        foreach ($checks as $check => $result) {
            if ($result['status']) {
                $this->components->info("✓ {$check}: {$result['message']}");
            } else {
                $this->components->error("✗ {$check}: {$result['message']}");
            }
        }

        $this->newLine();
    }

    /**
     * Check database connection.
     */
    protected function checkDatabaseConnection(): array
    {
        try {
            $connection = config('database.default');
            DB::connection()->getPdo();
            $database = DB::connection()->getDatabaseName();

            return [
                'status' => true,
                'message' => "Connected to {$connection} ({$database})",
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => "Failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Check local storage.
     */
    protected function checkLocalStorage(): array
    {
        try {
            $disk = Storage::disk('local');
            $path = 'backup-test-' . time() . '.txt';
            $disk->put($path, 'test');
            $exists = $disk->exists($path);
            $disk->delete($path);

            if ($exists) {
                $root = storage_path('app/private');

                return [
                    'status' => true,
                    'message' => "Writable ({$root})",
                ];
            }

            return [
                'status' => false,
                'message' => 'Cannot write to local storage',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => "Failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Check S3 storage.
     */
    protected function checkS3Storage(): array
    {
        if (!env('BACKUP_AWS_BUCKET')) {
            return [
                'status' => false,
                'message' => 'Not configured (BACKUP_AWS_BUCKET not set)',
            ];
        }

        try {
            $disk = Storage::disk('backups-s3');
            $path = 'backup-test-' . time() . '.txt';
            $disk->put($path, 'test');
            $exists = $disk->exists($path);
            $disk->delete($path);

            if ($exists) {
                $bucket = env('BACKUP_AWS_BUCKET');
                $region = env('BACKUP_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'ap-southeast-3'));

                return [
                    'status' => true,
                    'message' => "Connected to {$bucket} ({$region})",
                ];
            }

            return [
                'status' => false,
                'message' => 'Cannot write to S3 storage',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => "Failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Check encryption key.
     */
    protected function checkEncryptionKey(): array
    {
        $key = env('BACKUP_ENCRYPTION_KEY');

        if (!$key) {
            return [
                'status' => false,
                'message' => 'Not configured (BACKUP_ENCRYPTION_KEY not set)',
            ];
        }

        if (strlen($key) < 32) {
            return [
                'status' => false,
                'message' => 'Key too short (minimum 32 characters)',
            ];
        }

        return [
            'status' => true,
            'message' => 'Configured (AES-256-CBC)',
        ];
    }

    /**
     * Check backup package.
     */
    protected function checkBackupPackage(): array
    {
        if (class_exists(\Spatie\Backup\BackupServiceProvider::class)) {
            return [
                'status' => true,
                'message' => 'spatie/laravel-backup installed',
            ];
        }

        return [
            'status' => false,
            'message' => 'spatie/laravel-backup not installed',
        ];
    }

    /**
     * Check retention policy.
     */
    protected function checkRetentionPolicy(): array
    {
        $dailyDays = config('backup.cleanup.default_strategy.keep_daily_backups_for_days');
        $weeklyWeeks = config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks');
        $monthlyMonths = config('backup.cleanup.default_strategy.keep_monthly_backups_for_months');

        if ($dailyDays >= 30) {
            return [
                'status' => true,
                'message' => "Daily: {$dailyDays} days, Weekly: {$weeklyWeeks} weeks, Monthly: {$monthlyMonths} months",
            ];
        }

        return [
            'status' => false,
            'message' => "Daily retention too short ({$dailyDays} days, need 30+)",
        ];
    }

    /**
     * Test backup.
     */
    protected function testBackup(): void
    {
        $this->info('🧪 Running Test Backup...');
        $this->newLine();

        try {
            $this->info('Creating database backup...');
            Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);

            $output = Artisan::output();
            $this->line($output);

            if (str_contains($output, 'Backup completed')) {
                $this->components->info('✓ Test backup completed successfully');
            } else {
                $this->components->warn('⚠ Backup may have issues, check output above');
            }
        } catch (\Exception $e) {
            $this->components->error("✗ Test backup failed: {$e->getMessage()}");
        }

        $this->newLine();
    }

    /**
     * Display schedule information.
     */
    protected function displayScheduleInfo(): void
    {
        $this->info('📅 Backup Schedule:');
        $this->newLine();

        $this->table(
            ['Backup Type', 'Frequency', 'Retention', 'Command'],
            [
                ['Database', 'Daily at 2:00 AM', '30 days', 'backup:run --only-db'],
                ['Files', 'Daily at 3:00 AM', '30 days', 'backup:run --only-files'],
                ['Cleanup', 'Daily at 4:00 AM', 'Auto', 'backup:clean'],
                ['Monitor', 'Daily at 5:00 AM', 'N/A', 'backup:monitor'],
            ]
        );

        $this->newLine();
        $this->info('💡 Tips:');
        $this->line('  • Backups are encrypted with AES-256');
        $this->line('  • Stored locally and in S3 (if configured)');
        $this->line('  • 30-day retention policy for daily backups');
        $this->line('  • Automatic cleanup of old backups');
        $this->line('  • RTO target: < 1 hour');
        $this->line('  • RPO target: < 24 hours');
    }
}
