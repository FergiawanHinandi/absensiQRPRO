<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monitor Migration Progress
 * 
 * This command provides real-time monitoring of data migration progress.
 * 
 * Usage:
 *   php artisan migration:monitor attendances state
 *   php artisan migration:monitor attendances state --watch
 */
class MonitorMigrationProgressCommand extends Command
{
    protected $signature = 'migration:monitor 
                            {table : The table being migrated}
                            {column : The column being backfilled}
                            {--watch : Watch mode (refresh every 5 seconds)}';

    protected $description = 'Monitor zero-downtime migration progress';

    public function handle(): int
    {
        $table = $this->argument('table');
        $column = $this->argument('column');
        $watch = $this->option('watch');

        if ($watch) {
            $this->info("Watching migration progress for {$table}.{$column} (Ctrl+C to stop)...\n");
            
            while (true) {
                $this->displayProgress($table, $column);
                sleep(5);
                
                // Clear screen for next iteration
                if (PHP_OS_FAMILY === 'Windows') {
                    system('cls');
                } else {
                    system('clear');
                }
            }
        } else {
            $this->displayProgress($table, $column);
        }

        return 0;
    }

    protected function displayProgress(string $table, string $column): void
    {
        $stats = DB::selectOne("
            SELECT 
                COUNT(*) as total,
                COUNT({$column}) as migrated,
                COUNT(*) - COUNT({$column}) as remaining,
                ROUND(COUNT({$column}) / COUNT(*) * 100, 2) as progress_pct
            FROM {$table}
        ");

        $this->info("Migration Progress Report");
        $this->info("Table: {$table}");
        $this->info("Column: {$column}");
        $this->info("Time: " . now()->toDateTimeString());
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Records', number_format($stats->total)],
                ['Migrated', number_format($stats->migrated)],
                ['Remaining', number_format($stats->remaining)],
                ['Progress', $stats->progress_pct . '%'],
            ]
        );

        // Progress bar
        $this->output->progressStart($stats->total);
        $this->output->progressAdvance($stats->migrated);
        $this->output->progressFinish();
        $this->newLine();

        // Estimate completion time
        if ($stats->remaining > 0) {
            $recentRate = $this->getRecentMigrationRate($table, $column);
            
            if ($recentRate > 0) {
                $estimatedMinutes = ceil($stats->remaining / $recentRate);
                $this->info("Estimated completion: ~{$estimatedMinutes} minutes");
            }
        } else {
            $this->info("✅ Migration complete!");
        }
    }

    protected function getRecentMigrationRate(string $table, string $column): float
    {
        // Calculate records migrated in last 5 minutes
        $recentCount = DB::selectOne("
            SELECT COUNT(*) as count
            FROM {$table}
            WHERE {$column} IS NOT NULL
            AND updated_at >= NOW() - INTERVAL 5 MINUTE
        ");

        return ($recentCount->count ?? 0) / 5; // Records per minute
    }
}
