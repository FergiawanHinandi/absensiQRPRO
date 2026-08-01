<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Redis\QueueRecoveryService;

/**
 * Monitor Queue Health Command
 * 
 * Displays real-time queue health status and statistics.
 * Useful for monitoring queue system during and after failover.
 * 
 * Requirements: 3.2, 6.1
 */
class MonitorQueueHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:health
                            {--watch : Continuously monitor queue health}
                            {--interval=5 : Refresh interval in seconds for watch mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor queue health and display statistics';

    /**
     * Execute the console command.
     */
    public function handle(QueueRecoveryService $recoveryService): int
    {
        if ($this->option('watch')) {
            return $this->watchMode($recoveryService);
        }
        
        return $this->singleCheck($recoveryService);
    }
    
    /**
     * Single health check
     */
    private function singleCheck(QueueRecoveryService $recoveryService): int
    {
        $health = $recoveryService->monitorQueueHealth();
        $this->displayHealth($health);
        
        return Command::SUCCESS;
    }
    
    /**
     * Continuous monitoring mode
     */
    private function watchMode(QueueRecoveryService $recoveryService): int
    {
        $interval = max(1, (int) $this->option('interval'));
        
        $this->info("Monitoring queue health (refreshing every {$interval}s)...");
        $this->info('Press Ctrl+C to stop');
        $this->newLine();
        
        while (true) {
            // Clear screen (works on most terminals)
            if (PHP_OS_FAMILY !== 'Windows') {
                system('clear');
            } else {
                system('cls');
            }
            
            $this->info("Queue Health Monitor - " . now()->format('Y-m-d H:i:s'));
            $this->newLine();
            
            $health = $recoveryService->monitorQueueHealth();
            $this->displayHealth($health);
            
            sleep($interval);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Display health information
     */
    private function displayHealth(array $health): void
    {
        // Status
        $statusColor = match($health['status']) {
            'healthy' => 'green',
            'degraded' => 'yellow',
            'unhealthy' => 'red',
            default => 'white',
        };
        
        $this->line("Overall Status: <fg={$statusColor};options=bold>" . strtoupper($health['status']) . '</fg>');
        $this->newLine();
        
        // Queue statistics
        if (!empty($health['queues'])) {
            $headers = ['Queue', 'Pending Jobs', 'Reserved Jobs', 'Status'];
            $rows = [];
            
            foreach ($health['queues'] as $queueName => $stats) {
                $status = $this->getQueueStatus($stats);
                $statusIcon = $this->getStatusIcon($status);
                
                $rows[] = [
                    $queueName,
                    $stats['pending'],
                    $stats['reserved'],
                    $statusIcon . ' ' . ucfirst($status),
                ];
            }
            
            $this->table($headers, $rows);
        }
        
        // Issues
        if (!empty($health['issues'])) {
            $this->newLine();
            $this->warn('⚠ Issues Detected:');
            
            foreach ($health['issues'] as $issue) {
                $this->line("  • <fg=yellow>{$issue['type']}</> in queue '<fg=cyan>{$issue['queue']}</>': <fg=red>{$issue['count']}</> jobs");
            }
            
            $this->newLine();
            $this->info('💡 Tip: Run "php artisan queue:recover" to recover stuck jobs');
        }
        
        // Error
        if (isset($health['error'])) {
            $this->newLine();
            $this->error('❌ Error: ' . $health['error']);
        }
        
        // Summary
        if (empty($health['issues']) && !isset($health['error'])) {
            $this->newLine();
            $this->info('✓ All queues are operating normally');
        }
    }
    
    /**
     * Get queue status based on statistics
     */
    private function getQueueStatus(array $stats): string
    {
        $pending = $stats['pending'];
        $reserved = $stats['reserved'];
        
        // High backlog
        if ($pending > 1000) {
            return 'overloaded';
        }
        
        // Many reserved jobs (might indicate stuck jobs)
        if ($reserved > 100) {
            return 'degraded';
        }
        
        // Normal operation
        if ($pending < 100 && $reserved < 50) {
            return 'healthy';
        }
        
        return 'busy';
    }
    
    /**
     * Get status icon
     */
    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'healthy' => '<fg=green>●</>',
            'busy' => '<fg=yellow>●</>',
            'degraded' => '<fg=yellow>⚠</>',
            'overloaded' => '<fg=red>⚠</>',
            default => '<fg=white>●</>',
        };
    }
}
