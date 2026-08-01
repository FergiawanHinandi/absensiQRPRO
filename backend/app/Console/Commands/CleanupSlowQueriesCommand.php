<?php

namespace App\Console\Commands;

use App\Models\SlowQuery;
use Illuminate\Console\Command;

class CleanupSlowQueriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queries:cleanup {--days=30 : Number of days to retain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old slow query records based on retention policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = (int) $this->option('days');

        $this->info("Cleaning up slow queries older than {$retentionDays} days...");

        $deletedCount = SlowQuery::cleanup($retentionDays);

        $this->info("Deleted {$deletedCount} old slow query records.");

        return Command::SUCCESS;
    }
}
