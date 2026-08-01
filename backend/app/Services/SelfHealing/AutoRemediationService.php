<?php

namespace App\Services\SelfHealing;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Events\AutoRemediationTriggered;
use App\Events\SystemDegradedModeEnabled;
use App\Events\SystemDegradedModeDisabled;
use App\Services\Alerting\SlackAlert;

class AutoRemediationService
{
    private FailureClassifier $classifier;
    
    public function __construct(FailureClassifier $classifier)
    {
        $this->classifier = $classifier;
    }
    
    /**
     * Attempt to remediate a failure
     */
    public function remediate(Throwable $e, array $context = []): bool
    {
        $failureType = $this->classifier->classify($e, $context);
        $action = $this->classifier->getRecommendedAction($failureType);
        
        Log::info('Auto-remediation triggered', [
            'failure_type' => $failureType->value,
            'action' => $action,
            'error' => $e->getMessage(),
            'context' => $context,
        ]);
        
        event(new AutoRemediationTriggered($failureType, $action, $e, $context));
        
        return match($action) {
            'retry_with_backoff' => true, // Handled by caller
            'auto_scale' => $this->handleAutoScale($context),
            'circuit_breaker' => true, // Handled by circuit breaker
            'freeze_write' => $this->handleFreezeWrite($e, $context),
            'alert_manual' => $this->handleManualEscalation($e, $context),
            default => false,
        };
    }
    
    /**
     * Handle auto-scaling remediation
     */
    private function handleAutoScale(array $context): bool
    {
        // Check if we're in cooldown
        if (Cache::has('auto_scale:cooldown')) {
            Log::info('Auto-scale in cooldown, skipping');
            return false;
        }
        
        // Queue lag high
        if (($context['queue_lag'] ?? 0) > 500) {
            return $this->scaleQueueWorkers($context);
        }
        
        // Redis memory high
        if (($context['redis_memory_percent'] ?? 0) > 85) {
            return $this->handleRedisMemory($context);
        }
        
        // DB connection pool exhausted
        if (($context['db_connection_percent'] ?? 0) > 80) {
            return $this->enableReadReplicaRouting();
        }
        
        return false;
    }
    
    /**
     * Scale queue workers
     */
    private function scaleQueueWorkers(array $context): bool
    {
        try {
            $currentWorkers = Cache::get('metrics:active_workers', 2);
            $maxWorkers = config('queue.max_workers', 10);
            $queueLag = $context['queue_lag'] ?? 0;
            
            // Determine scale amount
            $scaleAmount = $queueLag > 2000 ? 2 : 1;
            $newWorkerCount = min($currentWorkers + $scaleAmount, $maxWorkers);
            
            if ($newWorkerCount <= $currentWorkers) {
                Log::warning('Queue workers already at maximum', [
                    'current' => $currentWorkers,
                    'max' => $maxWorkers,
                ]);
                return false;
            }
            
            Log::info('Scaling queue workers', [
                'from' => $currentWorkers,
                'to' => $newWorkerCount,
                'queue_lag' => $queueLag,
            ]);
            
            // In Kubernetes, this would trigger HPA
            // For now, log the intent
            Cache::put('auto_scale:queue_workers:target', $newWorkerCount, 300);
            Cache::put('auto_scale:cooldown', true, 300); // 5 min cooldown
            
            return true;
        } catch (Throwable $e) {
            Log::error('Failed to scale queue workers', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Handle Redis memory pressure
     */
    private function handleRedisMemory(array $context): bool
    {
        try {
            $memoryPercent = $context['redis_memory_percent'] ?? 0;
            
            if ($memoryPercent > 95) {
                Log::critical('Redis memory critical, performing emergency flush', [
                    'memory_percent' => $memoryPercent,
                ]);
                
                // Flush non-critical cache (preserve sessions and locks)
                $this->flushNonCriticalCache();
                
                return true;
            } elseif ($memoryPercent > 85) {
                Log::warning('Redis memory high, triggering scale', [
                    'memory_percent' => $memoryPercent,
                ]);
                
                // In cloud environment, trigger Redis scale
                Cache::put('auto_scale:redis:trigger', true, 600);
                Cache::put('auto_scale:cooldown', true, 600); // 10 min cooldown
                
                return true;
            }
            
            return false;
        } catch (Throwable $e) {
            Log::error('Failed to handle Redis memory', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Flush non-critical cache
     */
    private function flushNonCriticalCache(): void
    {
        $redis = Redis::connection();
        
        // Get all keys
        $keys = $redis->keys('*');
        
        $flushed = 0;
        foreach ($keys as $key) {
            // Preserve critical keys
            if (str_starts_with($key, 'session:') ||
                str_starts_with($key, 'lock:') ||
                str_starts_with($key, 'circuit_breaker:') ||
                str_starts_with($key, 'qr_nonce:')) {
                continue;
            }
            
            $redis->del($key);
            $flushed++;
        }
        
        Log::info('Flushed non-critical cache', [
            'keys_flushed' => $flushed,
        ]);
    }
    
    /**
     * Enable read replica routing
     */
    private function enableReadReplicaRouting(): bool
    {
        try {
            if (!config('database.connections.mysql_read')) {
                Log::warning('Read replica not configured, cannot enable routing');
                return false;
            }
            
            Log::info('Enabling read replica routing due to connection pressure');
            
            Cache::put('database:force_read_replica', true, 300); // 5 min
            Cache::put('auto_scale:cooldown', true, 180); // 3 min cooldown
            
            return true;
        } catch (Throwable $e) {
            Log::error('Failed to enable read replica routing', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Handle data corruption - freeze writes
     */
    private function handleFreezeWrite(Throwable $e, array $context): bool
    {
        try {
            Log::critical('DATA CORRUPTION DETECTED - FREEZING WRITES', [
                'error' => $e->getMessage(),
                'context' => $context,
            ]);
            
            // Enable write freeze
            Cache::put('system:write_freeze', true, 3600); // 1 hour
            
            // Send critical alert
            SlackAlert::critical('Write freeze activated - possible data corruption prevented', $context);
            
            return true;
        } catch (Throwable $e) {
            Log::error('Failed to freeze writes', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Handle manual escalation
     */
    private function handleManualEscalation(Throwable $e, array $context): bool
    {
        Log::critical('MANUAL ESCALATION REQUIRED', [
            'error' => $e->getMessage(),
            'context' => $context,
        ]);
        
        // Send alert
        SlackAlert::critical('MANUAL ESCALATION REQUIRED - ' . $e->getMessage(), $context);
        
        return false;
    }
    
    /**
     * Enable degraded mode
     */
    public function enableDegradedMode(string $reason, array $context = []): void
    {
        if (Cache::has('system:degraded_mode')) {
            return; // Already in degraded mode
        }
        
        Cache::put('system:degraded_mode', true, 600); // 10 min
        Cache::put('system:degraded_mode:reason', $reason, 600);
        
        Log::warning('System entered DEGRADED MODE', [
            'reason' => $reason,
            'context' => $context,
        ]);
        
        event(new SystemDegradedModeEnabled($reason, $context));
    }
    
    /**
     * Disable degraded mode
     */
    public function disableDegradedMode(): void
    {
        if (!Cache::has('system:degraded_mode')) {
            return; // Not in degraded mode
        }
        
        Cache::forget('system:degraded_mode');
        Cache::forget('system:degraded_mode:reason');
        
        Log::info('System exited DEGRADED MODE');
        
        event(new SystemDegradedModeDisabled());
    }
    
    /**
     * Check if system is in degraded mode
     */
    public function isInDegradedMode(): bool
    {
        return Cache::has('system:degraded_mode');
    }
    
    /**
     * Check if writes are frozen
     */
    public function isWriteFrozen(): bool
    {
        return Cache::has('system:write_freeze');
    }
}
