<?php

namespace App\Services\Session;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Session Fallback Manager
 * 
 * Manages automatic fallback between Redis and database session storage.
 * Monitors Redis health and switches storage backends transparently.
 * 
 * Features:
 * - Automatic Redis health monitoring
 * - Seamless fallback to database when Redis is unavailable
 * - Automatic recovery when Redis becomes available
 * - Health status tracking and reporting
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class SessionFallbackManager
{
    private $redisConnection;
    private $healthCheckInterval;
    private $lastHealthCheck;
    private $isRedisHealthy = true;

    /**
     * Create a new session fallback manager instance.
     *
     * @param string $redisConnection Redis connection name
     * @param int $healthCheckInterval Health check interval in seconds
     */
    public function __construct(string $redisConnection = 'session', int $healthCheckInterval = 30)
    {
        $this->redisConnection = $redisConnection;
        $this->healthCheckInterval = $healthCheckInterval;
        $this->lastHealthCheck = time();
    }

    /**
     * Check if Redis is healthy and available.
     *
     * @return bool
     */
    public function isRedisHealthy(): bool
    {
        // Only check health at configured intervals
        $currentTime = time();
        if ($currentTime - $this->lastHealthCheck < $this->healthCheckInterval) {
            return $this->isRedisHealthy;
        }

        $this->lastHealthCheck = $currentTime;

        try {
            // Perform a simple ping to check Redis availability
            $redis = Redis::connection($this->redisConnection);
            $result = $redis->ping();
            
            $isHealthy = ($result === true || $result === 'PONG');
            $this->isRedisHealthy = $isHealthy;
            
            if (!$isHealthy) {
                Log::warning('Redis health check failed', [
                    'connection' => $this->redisConnection,
                    'result' => $result,
                ]);
            }
            
            return $isHealthy;
        } catch (\Exception $e) {
            $this->isRedisHealthy = false;
            
            Log::error('Redis health check exception', [
                'connection' => $this->redisConnection,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get the current storage backend status.
     *
     * @return array
     */
    public function getStorageStatus(): array
    {
        $isHealthy = $this->isRedisHealthy();
        
        return [
            'primary_backend' => 'redis',
            'fallback_backend' => 'database',
            'current_backend' => $isHealthy ? 'redis' : 'database',
            'redis_healthy' => $isHealthy,
            'last_health_check' => $this->lastHealthCheck,
            'health_check_interval' => $this->healthCheckInterval,
        ];
    }

    /**
     * Force a health check and return the result.
     *
     * @return bool
     */
    public function forceHealthCheck(): bool
    {
        $this->lastHealthCheck = 0; // Reset to force check
        return $this->isRedisHealthy();
    }

    /**
     * Get health metrics for monitoring.
     *
     * @return array
     */
    public function getHealthMetrics(): array
    {
        $status = $this->getStorageStatus();
        
        return [
            'redis_available' => $status['redis_healthy'] ? 1 : 0,
            'using_fallback' => $status['current_backend'] === 'database' ? 1 : 0,
            'last_check_timestamp' => $status['last_health_check'],
        ];
    }
}
