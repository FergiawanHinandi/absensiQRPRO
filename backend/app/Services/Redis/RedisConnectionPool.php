<?php

namespace App\Services\Redis;

use Illuminate\Support\Facades\Log;

/**
 * Redis Connection Pool Manager
 * 
 * Manages a pool of resilient Redis connections for different purposes
 * (default, cache, session, queue) with automatic failover and health monitoring.
 */
class RedisConnectionPool
{
    /**
     * Pool of resilient connections
     *
     * @var array<string, ResilientRedisConnection>
     */
    private array $connections = [];

    /**
     * Get or create a resilient connection
     *
     * @param string $connectionName
     * @return ResilientRedisConnection
     */
    public function connection(string $connectionName = 'default'): ResilientRedisConnection
    {
        if (!isset($this->connections[$connectionName])) {
            $this->connections[$connectionName] = new ResilientRedisConnection(
                $connectionName,
                config('database.redis.max_retries', 3)
            );

            $healthCheckInterval = config('database.redis.health_check_interval', 30);
            $this->connections[$connectionName]->setHealthCheckInterval($healthCheckInterval);
        }

        return $this->connections[$connectionName];
    }

    /**
     * Get health status of all connections in the pool
     *
     * @return array
     */
    public function getPoolHealth(): array
    {
        $health = [];

        foreach ($this->connections as $name => $connection) {
            $health[$name] = [
                'is_healthy' => $connection->isHealthy(),
                'stats' => $connection->getStats(),
            ];
        }

        return $health;
    }

    /**
     * Force health check on all connections
     *
     * @return array
     */
    public function forceHealthCheckAll(): array
    {
        $results = [];

        foreach ($this->connections as $name => $connection) {
            $results[$name] = $connection->forceHealthCheck();
        }

        return $results;
    }

    /**
     * Get statistics for all connections
     *
     * @return array
     */
    public function getPoolStats(): array
    {
        $stats = [
            'total_connections' => count($this->connections),
            'connections' => [],
        ];

        foreach ($this->connections as $name => $connection) {
            $stats['connections'][$name] = $connection->getStats();
        }

        return $stats;
    }

    /**
     * Clear all connections from the pool
     *
     * @return void
     */
    public function clearPool(): void
    {
        Log::info('Clearing Redis connection pool', [
            'connection_count' => count($this->connections),
        ]);

        $this->connections = [];
    }
}
