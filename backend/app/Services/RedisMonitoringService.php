<?php

namespace App\Services;

use App\Infrastructure\CircuitBreaker\RedisCircuitBreaker;
use App\Services\Alerting\SlackAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Monitoring Service
 * 
 * Monitors Redis health, memory usage, and connection status
 * Triggers alerts when thresholds are exceeded
 */
class RedisMonitoringService
{
    // Memory usage threshold (80%)
    private const MEMORY_WARNING_THRESHOLD = 0.80;
    
    // Alert cooldown period (prevent alert spam)
    private const ALERT_COOLDOWN_MINUTES = 60;
    
    public function __construct(
        private RedisCircuitBreaker $circuitBreaker
    ) {}
    
    /**
     * Check Redis health and trigger alerts if needed
     * 
     * @return array Health status with metrics
     */
    public function checkHealth(): array
    {
        $health = [
            'timestamp' => now()->toIso8601String(),
            'connection' => $this->checkConnection(),
            'memory' => $this->checkMemoryUsage(),
            'circuit_breaker' => $this->circuitBreaker->getStatus(),
            'alerts' => [],
        ];
        
        // Check for alert conditions
        if (!$health['connection']['is_connected']) {
            $this->triggerConnectionFailureAlert($health);
            $health['alerts'][] = 'redis_connection_failed';
        }
        
        if ($health['memory']['usage_percent'] > self::MEMORY_WARNING_THRESHOLD * 100) {
            $this->triggerHighMemoryAlert($health);
            $health['alerts'][] = 'redis_high_memory';
        }
        
        if ($health['circuit_breaker']['state'] === 'open') {
            $this->triggerCircuitBreakerAlert($health);
            $health['alerts'][] = 'redis_circuit_breaker_open';
        }
        
        return $health;
    }
    
    /**
     * Check Redis connection status
     */
    private function checkConnection(): array
    {
        try {
            $startTime = microtime(true);
            Redis::ping();
            $responseTime = (microtime(true) - $startTime) * 1000; // Convert to ms
            
            return [
                'is_connected' => true,
                'response_time_ms' => round($responseTime, 2),
                'status' => 'healthy',
            ];
        } catch (\Exception $e) {
            return [
                'is_connected' => false,
                'error' => $e->getMessage(),
                'status' => 'unhealthy',
            ];
        }
    }
    
    /**
     * Check Redis memory usage
     */
    private function checkMemoryUsage(): array
    {
        try {
            $info = Redis::info('memory');
            
            $usedMemory = $info['used_memory'] ?? 0;
            $maxMemory = $info['maxmemory'] ?? 0;
            
            // If maxmemory is 0, Redis has no memory limit
            if ($maxMemory === 0) {
                return [
                    'used_memory_mb' => round($usedMemory / 1024 / 1024, 2),
                    'max_memory_mb' => 'unlimited',
                    'usage_percent' => 0,
                    'status' => 'ok',
                ];
            }
            
            $usagePercent = ($usedMemory / $maxMemory) * 100;
            
            return [
                'used_memory_mb' => round($usedMemory / 1024 / 1024, 2),
                'max_memory_mb' => round($maxMemory / 1024 / 1024, 2),
                'usage_percent' => round($usagePercent, 2),
                'status' => $usagePercent > self::MEMORY_WARNING_THRESHOLD * 100 ? 'warning' : 'ok',
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'status' => 'unavailable',
            ];
        }
    }
    
    /**
     * Trigger alert for Redis connection failure
     */
    private function triggerConnectionFailureAlert(array $health): void
    {
        if (!$this->shouldSendAlert('redis_connection_failure')) {
            return;
        }
        
        Log::error('redis_connection_failure_alert', [
            'severity' => 'critical',
            'message' => 'Redis connection failed',
            'health' => $health,
            'timestamp' => now()->toIso8601String(),
        ]);
        
        SlackAlert::critical('Redis connection failed', [
            'health' => $health,
        ]);
        
        $this->recordAlertSent('redis_connection_failure');
    }
    
    /**
     * Trigger alert for high Redis memory usage
     */
    private function triggerHighMemoryAlert(array $health): void
    {
        if (!$this->shouldSendAlert('redis_high_memory')) {
            return;
        }
        
        $memoryPercent = $health['memory']['usage_percent'] ?? 0;
        
        Log::warning('redis_high_memory_alert', [
            'severity' => 'high',
            'message' => "Redis memory usage is at {$memoryPercent}%",
            'threshold' => self::MEMORY_WARNING_THRESHOLD * 100 . '%',
            'health' => $health,
            'timestamp' => now()->toIso8601String(),
        ]);
        
        SlackAlert::warning("Redis memory usage is at {$memoryPercent}%", [
            'threshold' => self::MEMORY_WARNING_THRESHOLD * 100 . '%',
        ]);
        
        $this->recordAlertSent('redis_high_memory');
    }
    
    /**
     * Trigger alert for circuit breaker open
     */
    private function triggerCircuitBreakerAlert(array $health): void
    {
        if (!$this->shouldSendAlert('redis_circuit_breaker_open')) {
            return;
        }
        
        Log::error('redis_circuit_breaker_open_alert', [
            'severity' => 'critical',
            'message' => 'Redis circuit breaker is OPEN - system using fallback',
            'circuit_status' => $health['circuit_breaker'],
            'timestamp' => now()->toIso8601String(),
        ]);
        
        SlackAlert::critical('Redis circuit breaker is OPEN - system using fallback', [
            'circuit_status' => $health['circuit_breaker'] ?? 'unknown',
        ]);
        
        $this->recordAlertSent('redis_circuit_breaker_open');
    }
    
    /**
     * Check if we should send an alert (respects cooldown period)
     */
    private function shouldSendAlert(string $alertType): bool
    {
        $cacheKey = "redis_alert_cooldown:{$alertType}";
        
        // Check if we're in cooldown period
        if (cache()->has($cacheKey)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Record that an alert was sent (start cooldown period)
     */
    private function recordAlertSent(string $alertType): void
    {
        $cacheKey = "redis_alert_cooldown:{$alertType}";
        
        // Use file cache to avoid Redis dependency for alert tracking
        cache()->store('file')->put($cacheKey, true, now()->addMinutes(self::ALERT_COOLDOWN_MINUTES));
    }
    
    /**
     * Get monitoring metrics for dashboard
     */
    public function getMetrics(): array
    {
        return [
            'health' => $this->checkHealth(),
            'circuit_breaker' => $this->circuitBreaker->getStatus(),
            'uptime' => $this->getRedisUptime(),
        ];
    }
    
    /**
     * Get Redis uptime
     */
    private function getRedisUptime(): ?array
    {
        try {
            $info = Redis::info('server');
            
            return [
                'uptime_seconds' => $info['uptime_in_seconds'] ?? null,
                'uptime_days' => $info['uptime_in_days'] ?? null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get failover events from cache/logs
     * Returns recent failover events for monitoring
     */
    public function getFailoverEvents(): array
    {
        try {
            // Try to get from cache first
            $events = cache()->get('redis_failover_events', []);
            
            // If empty, check logs or return empty array
            if (empty($events)) {
                // Could parse logs here if needed
                return [];
            }
            
            return $events;
        } catch (\Exception $e) {
            Log::error('Failed to get failover events', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get tenant-specific metrics
     * Returns metrics for a specific school/tenant
     */
    public function getTenantMetrics(int $schoolId): array
    {
        try {
            $prefix = "school:{$schoolId}:";
            
            // Get tenant-specific cache stats
            $cacheKeys = Redis::keys($prefix . '*');
            $keyCount = count($cacheKeys);
            
            // Calculate approximate memory usage for tenant
            $memoryUsage = 0;
            foreach (array_slice($cacheKeys, 0, 100) as $key) {
                try {
                    $memoryUsage += Redis::strlen($key);
                } catch (\Exception $e) {
                    // Skip if key doesn't exist or error
                }
            }
            
            return [
                'school_id' => $schoolId,
                'cache_keys' => $keyCount,
                'estimated_memory_bytes' => $memoryUsage,
                'estimated_memory_mb' => round($memoryUsage / 1024 / 1024, 2),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get tenant metrics', [
                'school_id' => $schoolId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'school_id' => $schoolId,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }
}
