<?php

namespace App\Console\Commands;

use App\Services\RedisMonitoringService;
use Illuminate\Console\Command;

/**
 * Monitor Redis Health Command
 * 
 * Checks Redis health and triggers alerts if needed
 * Should be scheduled to run every minute
 */
class MonitorRedisHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:monitor
                          {--json : Output results as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Redis health and trigger alerts if needed';

    /**
     * Execute the console command.
     */
    public function handle(RedisMonitoringService $monitoring): int
    {
        $this->info('Checking Redis health...');
        
        $health = $monitoring->checkHealth();
        
        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        
        // Display connection status
        $connectionStatus = $health['connection']['is_connected'] ? 
            '<fg=green>✓ Connected</>' : 
            '<fg=red>✗ Disconnected</>';
        $this->line("Connection: {$connectionStatus}");
        
        if ($health['connection']['is_connected']) {
            $responseTime = $health['connection']['response_time_ms'];
            $this->line("  Response time: {$responseTime}ms");
        }
        
        // Display memory status
        if (isset($health['memory']['used_memory_mb'])) {
            $memoryStatus = $health['memory']['status'] === 'ok' ? 
                '<fg=green>OK</>' : 
                '<fg=yellow>WARNING</>';
            $this->line("Memory: {$memoryStatus}");
            $this->line("  Used: {$health['memory']['used_memory_mb']} MB");
            
            if (isset($health['memory']['usage_percent'])) {
                $this->line("  Usage: {$health['memory']['usage_percent']}%");
            }
        }
        
        // Display circuit breaker status
        $circuitState = $health['circuit_breaker']['state'];
        $circuitStatus = match($circuitState) {
            'closed' => '<fg=green>CLOSED (Healthy)</>',
            'half_open' => '<fg=yellow>HALF_OPEN (Testing)</>',
            'open' => '<fg=red>OPEN (Failing)</>',
            default => $circuitState,
        };
        $this->line("Circuit Breaker: {$circuitStatus}");
        
        if ($circuitState !== 'closed') {
            $failureCount = $health['circuit_breaker']['failure_count'];
            $this->line("  Failures: {$failureCount}");
        }
        
        // Display alerts
        if (!empty($health['alerts'])) {
            $this->newLine();
            $this->warn('Active Alerts:');
            foreach ($health['alerts'] as $alert) {
                $this->line("  • {$alert}");
            }
        }
        
        // Return appropriate exit code
        if (!empty($health['alerts'])) {
            return self::FAILURE;
        }
        
        $this->newLine();
        $this->info('✓ Redis health check completed');
        
        return self::SUCCESS;
    }
}
