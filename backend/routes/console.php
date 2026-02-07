<?php

use App\Jobs\CalculateAttendanceRisk;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('exports:cleanup')->hourly();
Schedule::command('security:aggregate-alerts')->everyFiveMinutes();
Schedule::command('security:scan-anomalies')->everyFiveMinutes();
Schedule::command('retention:cleanup')->monthly();

// Token cleanup - runs daily at 3 AM to remove expired/revoked tokens
Schedule::command('tokens:cleanup')->dailyAt('03:00');

// Behavior analysis - runs at 1 AM every night
Schedule::command('behavior:analyze-daily')->dailyAt('01:00');

// Student Attendance Risk Calculation - runs daily at 01:00 AM
Schedule::job(new CalculateAttendanceRisk)->dailyAt('01:00');

// ============================================================
// IMMUTABLE AUDIT LOG INTEGRITY & BACKUP
// ============================================================

// Verify immutable log integrity every 6 hours with alerting
Schedule::command('security:verify-log-integrity --alert --silent')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('security')
            ->critical('Scheduled log integrity check failed');
    });

// Nightly backup of security log hashes at 2:30 AM
Schedule::command('security:backup-hashes --verify')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();
// ============================================================
// AUTOMATED ENCRYPTED BACKUP SYSTEM
// ============================================================

// Daily FULL database backup at 02:00 AM
// - Compressed with Gzip
// - Encrypted with AES-256 (BACKUP_ENCRYPTION_KEY)
// - Stored locally then synced to S3
// - Retained for 30 days
Schedule::command('backup:run --only-db')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::channel('backup')
            ->info('Daily database backup completed successfully', [
                'type' => 'database',
                'scheduled_time' => '02:00',
                'timestamp' => now()->toIso8601String(),
            ]);
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('backup')
            ->critical('Daily database backup FAILED', [
                'type' => 'database',
                'scheduled_time' => '02:00',
                'timestamp' => now()->toIso8601String(),
            ]);
    });

// Daily files backup at 02:45 AM (after security hash backup at 02:30)
Schedule::command('backup:run --only-files')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::channel('backup')
            ->info('Daily files backup completed successfully', [
                'type' => 'files',
                'scheduled_time' => '02:45',
                'timestamp' => now()->toIso8601String(),
            ]);
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('backup')
            ->critical('Daily files backup FAILED', [
                'type' => 'files',
                'scheduled_time' => '02:45',
                'timestamp' => now()->toIso8601String(),
            ]);
    });

// Backup cleanup - runs daily at 03:00 AM (removes backups older than 30 days)
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::channel('backup')
            ->info('Backup cleanup completed', [
                'retention_days' => 30,
                'timestamp' => now()->toIso8601String(),
            ]);
    });

// Backup health monitoring every 6 hours
Schedule::command('backup:health-check --silent --max-age=26')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('backup')
            ->critical('Backup health check detected issues - immediate attention required');
    });

// 🔄 Auto-Scale Queue Workers (Every Minute)
Schedule::command('queue:autoscale --os=linux')->everyMinute()->withoutOverlapping();

// 🚨 System Health Monitoring (Alerting)
Schedule::command('monitor:system')->everyMinute()->runInBackground();
