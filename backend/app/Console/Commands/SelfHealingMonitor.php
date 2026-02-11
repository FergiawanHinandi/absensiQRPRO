<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SelfHealing\AutoRemediationService;
use Illuminate\Support\Facades\Cache;

class SelfHealingMonitor extends Command
{
    protected $signature = 'self-healing:monitor';
    protected $description = 'Monitor self-healing system status';

    public function handle(AutoRemediationService $remediation)
    {
        $this->info('Self-Healing System Monitor');
        $this->line('');
        
        // System mode
        $this->showSystemMode($remediation);
        $this->line('');
        
        // Auto-scaling status
        $this->showAutoScaling();
        $this->line('');
        
        // Recent remediations
        $this->showRecentRemediations();
    }
    
    private function showSystemMode(AutoRemediationService $remediation): void
    {
        $this->line('<fg=cyan>System Mode</>');
        
        if ($remediation->isWriteFrozen()) {
            $this->line('Status: <fg=red>WRITE FROZEN</> ⚠️');
            $this->warn('System is in write freeze mode due to data corruption detection');
        } elseif ($remediation->isInDegradedMode()) {
            $reason = Cache::get('system:degraded_mode:reason', 'unknown');
            $this->line('Status: <fg=yellow>DEGRADED</> ⚠️');
            $this->line("Reason: {$reason}");
        } else {
            $this->line('Status: <fg=green>HEALTHY</> ✓');
        }
    }
    
    private function showAutoScaling(): void
    {
        $this->line('<fg=cyan>Auto-Scaling Status</>');
        
        // Queue workers
        $queueTarget = Cache::get('auto_scale:queue_workers:target');
        $queueCooldown = Cache::has('auto_scale:cooldown');
        
        if ($queueTarget) {
            $this->line("Queue Workers: Scaling to {$queueTarget}");
        } else {
            $this->line('Queue Workers: No scaling in progress');
        }
        
        // Redis
        $redisTrigger = Cache::get('auto_scale:redis:trigger');
        if ($redisTrigger) {
            $this->line('Redis: <fg=yellow>Scale triggered</>');
        } else {
            $this->line('Redis: No scaling needed');
        }
        
        // Cooldown
        if ($queueCooldown) {
            $this->line('Cooldown: <fg=yellow>Active</> (preventing rapid scaling)');
        } else {
            $this->line('Cooldown: Inactive');
        }
    }
    
    private function showRecentRemediations(): void
    {
        $this->line('<fg=cyan>Recent Remediations (Last 24h)</>');
        
        // This would ideally read from a log or database
        // For now, show placeholder
        $this->line('No recent remediations logged');
        $this->line('(Implement logging to track remediation history)');
    }
}
