<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SelfHealing\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

class CircuitBreakerStatus extends Command
{
    protected $signature = 'circuit-breaker:status {name?}';
    protected $description = 'Show circuit breaker status and metrics';

    public function handle()
    {
        $name = $this->argument('name');
        
        if ($name) {
            $this->showCircuitBreaker($name);
        } else {
            $this->showAllCircuitBreakers();
        }
    }
    
    private function showAllCircuitBreakers(): void
    {
        $this->info('Circuit Breaker Status');
        $this->line('');
        
        $breakers = ['redis', 'database', 'external_api'];
        
        foreach ($breakers as $name) {
            $config = config("self-healing.{$name}");
            
            if (!$config) {
                continue;
            }
            
            $breaker = new CircuitBreaker(
                $name,
                $config['failure_threshold'],
                $config['success_threshold'],
                $config['timeout'],
                $config['fallback'] ?? null
            );
            
            $metrics = $breaker->getMetrics();
            
            $this->displayMetrics($metrics);
            $this->line('');
        }
    }
    
    private function showCircuitBreaker(string $name): void
    {
        $config = config("self-healing.{$name}");
        
        if (!$config) {
            $this->error("Circuit breaker '{$name}' not configured");
            return;
        }
        
        $breaker = new CircuitBreaker(
            $name,
            $config['failure_threshold'],
            $config['success_threshold'],
            $config['timeout'],
            $config['fallback'] ?? null
        );
        
        $metrics = $breaker->getMetrics();
        
        $this->displayMetrics($metrics);
    }
    
    private function displayMetrics(array $metrics): void
    {
        $state = $metrics['state'];
        $stateColor = match($state) {
            'closed' => 'green',
            'half_open' => 'yellow',
            'open' => 'red',
            default => 'white',
        };
        
        $this->line("Circuit Breaker: <fg=cyan>{$metrics['name']}</>");
        $this->line("State: <fg={$stateColor}>" . strtoupper($state) . "</>");
        $this->line("Failures: {$metrics['failures']}");
        $this->line("Successes: {$metrics['successes']}");
        $this->line("Rejections: {$metrics['rejections']}");
        
        if ($metrics['opened_at']) {
            $this->line("Opened At: {$metrics['opened_at']}");
        }
        
        $this->line("Config:");
        $this->line("  - Failure Threshold: {$metrics['config']['failure_threshold']}");
        $this->line("  - Success Threshold: {$metrics['config']['success_threshold']}");
        $this->line("  - Timeout: {$metrics['config']['timeout']}s");
        $this->line("  - Fallback: {$metrics['config']['fallback']}");
    }
}
