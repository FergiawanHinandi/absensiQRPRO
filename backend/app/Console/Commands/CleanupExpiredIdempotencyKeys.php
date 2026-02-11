<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CleanupExpiredIdempotencyKeys Command
 *
 * Removes expired idempotency keys from the database to prevent table bloat.
 *
 * SCHEDULING:
 * Add to App\Console\Kernel::schedule():
 *
 * $schedule->command('idempotency:cleanup')
 *     ->hourly()
 *     ->withoutOverlapping()
 *     ->runInBackground();
 *
 * MANUAL EXECUTION:
 * php artisan idempotency:cleanup
 * php artisan idempotency:cleanup --force  (skip confirmation)
 * php artisan idempotency:cleanup --days=7 (custom retention)
 */
class CleanupExpiredIdempotencyKeys extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'idempotency:cleanup
                            {--force : Skip confirmation prompt}
                            {--days=1 : Delete keys older than X days}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up expired idempotency keys from database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $force = $this->option('force');

        $this->info("🧹 Cleaning up idempotency keys older than {$days} day(s)...");

        // Count expired keys
        $expiredCount = IdempotencyKey::where('expires_at', '<=', now()->subDays($days))->count();

        if ($expiredCount === 0) {
            $this->info('✅ No expired keys to clean up.');
            return self::SUCCESS;
        }

        $this->warn("Found {$expiredCount} expired key(s) to delete.");

        // Confirm deletion unless --force is used
        if (!$force && !$this->confirm('Do you want to proceed with deletion?', true)) {
            $this->info('Cleanup cancelled.');
            return self::SUCCESS;
        }

        // Delete in batches to avoid memory issues
        $batchSize = 1000;
        $totalDeleted = 0;

        $this->withProgressBar($expiredCount, function () use ($days, $batchSize, &$totalDeleted) {
            while (true) {
                $deleted = IdempotencyKey::where('expires_at', '<=', now()->subDays($days))
                    ->limit($batchSize)
                    ->delete();

                if ($deleted === 0) {
                    break;
                }

                $totalDeleted += $deleted;
            }
        });

        $this->newLine(2);
        $this->info("✅ Successfully deleted {$totalDeleted} expired idempotency key(s).");

        Log::channel('daily')->info('Idempotency keys cleanup completed', [
            'deleted_count' => $totalDeleted,
            'retention_days' => $days,
        ]);

        return self::SUCCESS;
    }
}
