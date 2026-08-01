<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Redis\QueueRecoveryService;

/**
 * Recover Queue Jobs Command
 * 
 * Manual command to trigger queue job recovery after Redis failover.
 * Can be run by administrators to recover interrupted jobs.
 * 
 * Requirements: 3.1, 3.2, 3.3
 */
class RecoverQueueJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:recover
                            {--dry-run : Show what would be recovered without actually recovering}
                            {--force : Force recovery even if queue appears healthy}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover failed and stuck queue jobs after Redis failover';

    /**
     * Execute the console command.
     */
    public function handle(QueueRecoveryService $recoveryService): int
    {
        $this->info('Starting queue recovery process...');
        $this->newLine();
        
        // Check queue health first
        $this->info('Checking queue health...');
        $health = $recoveryService->monitorQueueHealth();
        
        $this->displayHealthStatus($health);
        $this->newLine();
        
        // Check if recovery is needed
        if ($health['status'] === 'healthy' && !$this->option('force')) {
            $this->info('Queue is healthy. No recovery needed.');
            $this->info('Use --force to recover anyway.');
            return Command::SUCCESS;
        }
        
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No jobs will be actually recovered');
            $this->newLine();
        }
        
        // Confirm before proceeding
        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Do you want to proceed with queue recovery?')) {
                $this->info('Recovery cancelled.');
                return Command::SUCCESS;
            }
        }
        
        // Perform recovery
        $this->info('Recovering failed jobs...');
        
        if (!$this->option('dry-run')) {
            $stats = $recoveryService->recoverFailedJobs();
            $this->displayRecoveryStats($stats);
        } else {
            $this->info('Dry run completed. No changes made.');
        }
        
        $this->newLine();
        $this->info('Queue recovery completed successfully!');
        
        return Command::SUCCESS;
    }
    
    /**
     * Display queue health status
     */
    private function displayHealthStatus(array $health): void
    {
        $statusColor = match($health['status']) {
            'healthy' => 'green',
            'degraded' => 'yellow',
            'unhealthy' => 'red',
            default => 'white',
        };
        
        $this->line("Status: <fg={$statusColor}>" . strtoupper($health['status']) . '</fg>');
        $this->newLine();
        
        // Display queue statistics
        if (!empty($health['queues'])) {
            $this->info('Queue Statistics:');
            
            $headers = ['Queue', 'Pending', 'Reserved'];
            $rows = [];
            
            foreach ($health['queues'] as $queueName => $stats) {
                $rows[] = [
                    $queueName,
                    $stats['pending'],
                    $stats['reserved'],
                ];
            }
            
            $this->table($headers, $rows);
        }
        
        // Display issues
        if (!empty($health['issues'])) {
            $this->newLine();
            $this->warn('Issues Detected:');
            
            foreach ($health['issues'] as $issue) {
                $this->line("  - {$issue['type']} in queue '{$issue['queue']}': {$issue['count']} jobs");
            }
        }
        
        // Display error if present
        if (isset($health['error'])) {
            $this->newLine();
            $this->error('Error: ' . $health['error']);
        }
    }
    
    /**
     * Display recovery statistics
     */
    private function displayRecoveryStats(array $stats): void
    {
        $this->newLine();
        $this->info('Recovery Statistics:');
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Found', $stats['total_found']],
                ['Recovered', "<fg=green>{$stats['recovered']}</>"],
                ['Skipped', "<fg=yellow>{$stats['skipped']}</>"],
                ['Failed', "<fg=red>{$stats['failed']}</>"],
            ]
        );
        
        if ($stats['recovered'] > 0) {
            $this->newLine();
            $this->info("✓ Successfully recovered {$stats['recovered']} jobs");
        }
        
        if ($stats['failed'] > 0) {
            $this->newLine();
            $this->warn("⚠ Failed to recover {$stats['failed']} jobs. Check logs for details.");
        }
    }
}
