<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;


use Illuminate\Support\Facades\Schedule;
use App\Jobs\CalculateAttendanceRisk;

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

// Daily database backup at 02:00 AM
Schedule::command('backup:run --only-db')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::channel('security')
            ->info('Scheduled database backup completed successfully');
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('security')
            ->critical('Scheduled database backup FAILED');
        // Alert will be triggered by backup:monitor
    });

// Daily files backup at 02:30 AM (after security hash backup)
Schedule::command('backup:run --only-files')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::channel('security')
            ->info('Scheduled files backup completed successfully');
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('security')
            ->critical('Scheduled files backup FAILED');
    });

// Weekly backup cleanup on Sunday at 03:00 AM
Schedule::command('backup:clean')
    ->weeklyOn(0, '03:00') // Sunday
    ->withoutOverlapping()
    ->runInBackground();

// Backup health monitoring every 6 hours
Schedule::command('backup:health-check --silent')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::channel('security')
            ->critical('Backup health check detected issues');
    });