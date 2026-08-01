<?php

namespace App\Services\Redis;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Predis\Connection\ConnectionException;
use RuntimeException;

/**
 * Resilient Redis Connection Wrapper
 * 
 * Provides connection pooling with automatic retry logic, exponential backoff,
 * and health check mechanisms for Redis Sentinel high availability.
 * 
 * Features:
 * - Automatic retry with exponential backoff (100ms * attempts)
 * - Connection health checks
 * - Connection invalidation on failures
 * - Maximum retry attempts (default 3)
 * - Graceful error handling and logging
 */
class ResilientRedisConnection
{
    /**
     * Maximum number of retry attempts
     */
    private int $maxRetries;

    /**
     * Base delay for exponential backoff in microseconds (100ms)
     */
    private int $baseDelayMicroseconds = 100000;

    /**
     * Connection name (default, cache, session, queue)
     */
    private string $connectionName;

    /**
     * Last health check timestamp
     */
    private ?int $lastHealthCheck = null;

    /**
     * Health check interval in seconds
     */
    private int $healthCheckInterval = 30;

    /**
     * Is connection healthy
     */
    private bool $isHealthy = true;

    /**
     * Create a new resilient Redis connection instance
     *
     * @param string $connectionName Redis connection name (default, cache, session, queue)
     * @param int $maxRetries Maximum number of retry attempts
     */
    public function __construct(string $connectionName = 'default', int $maxRetries = 3)
    {
        $this->connectionName = $connectionName;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Execute a Redis command with automatic retry logic
     *
     * @param string $command Redis command name
     * @param array $args Command arguments
     * @return mixed Command result
     * @throws RedisUnavailableException When Redis is unavailable after all retries
     */
    public function execute(string $command, array $args = []): mixed
    {
        $attempts = 0;

        while ($attempts < $this->maxRetries) {
            try {
                $connection = $this->getHealthyConnection();
                
                // Execute the command
                return $connection->command($command, $args);
            } catch (ConnectionException $e) {
                $attempts++;
                $this->invalidateConnection();

                Log::warning('Redis connection failed', [
                    'connection' => $this->connectionName,
                    'attempt' => $attempts,
                    'max_retries' => $this->maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts >= $this->maxRetries) {
                    Log::error('Redis unavailable after all retries', [
                        'connection' => $this->connectionName,
                        'attempts' => $attempts,
                        'error' => $e->getMessage(),
                    ]);

                    throw new RedisUnavailableException(
                        "Redis unavailable after {$this->maxRetries} attempts: {$e->getMessage()}",
                        0,
                        $e
                    );
                }

                // Exponential backoff: 100ms, 200ms, 300ms, etc.
                $delayMicroseconds = $this->baseDelayMicroseconds * $attempts;
                usleep($delayMicroseconds);
            } catch (\Exception $e) {
                Log::error('Unexpected Redis error', [
                    'connection' => $this->connectionName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        }

        throw new RedisUnavailableException(
            "Redis unavailable after {$this->maxRetries} attempts"
        );
    }

    /**
     * Get a healthy Redis connection
     *
     * @return Connection
     * @throws ConnectionException When connection is unhealthy
     */
    private function getHealthyConnection(): Connection
    {
        $connection = Redis::connection($this->connectionName);

        // Perform health check if needed
        if ($this->shouldPerformHealthCheck()) {
            $this->performHealthCheck($connection);
        }

        if (!$this->isHealthy) {
            throw new ConnectionException('Connection is marked as unhealthy');
        }

        return $connection;
    }

    /**
     * Check if health check should be performed
     *
     * @return bool
     */
    private function shouldPerformHealthCheck(): bool
    {
        if ($this->lastHealthCheck === null) {
            return true;
        }

        return (time() - $this->lastHealthCheck) >= $this->healthCheckInterval;
    }

    /**
     * Perform health check on the connection
     *
     * @param Connection $connection
     * @return void
     */
    private function performHealthCheck(Connection $connection): void
    {
        try {
            // Simple PING command to check connection health
            $response = $connection->command('ping');
            
            $this->isHealthy = ($response === 'PONG' || $response === true);
            $this->lastHealthCheck = time();

            if (!$this->isHealthy) {
                Log::warning('Redis health check failed', [
                    'connection' => $this->connectionName,
                    'response' => $response,
                ]);
            }
        } catch (\Exception $e) {
            $this->isHealthy = false;
            $this->lastHealthCheck = time();

            Log::warning('Redis health check exception', [
                'connection' => $this->connectionName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate the current connection
     *
     * @return void
     */
    private function invalidateConnection(): void
    {
        $this->isHealthy = false;
        $this->lastHealthCheck = null;

        Log::info('Redis connection invalidated', [
            'connection' => $this->connectionName,
        ]);
    }

    /**
     * Check if the connection is healthy
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        try {
            $connection = Redis::connection($this->connectionName);
            $this->performHealthCheck($connection);
            return $this->isHealthy;
        } catch (\Exception $e) {
            Log::warning('Health check failed', [
                'connection' => $this->connectionName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get connection statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'connection_name' => $this->connectionName,
            'is_healthy' => $this->isHealthy,
            'last_health_check' => $this->lastHealthCheck,
            'max_retries' => $this->maxRetries,
            'health_check_interval' => $this->healthCheckInterval,
        ];
    }

    /**
     * Set maximum retry attempts
     *
     * @param int $maxRetries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * Set health check interval
     *
     * @param int $seconds
     * @return self
     */
    public function setHealthCheckInterval(int $seconds): self
    {
        $this->healthCheckInterval = $seconds;
        return $this;
    }

    /**
     * Force a health check
     *
     * @return bool
     */
    public function forceHealthCheck(): bool
    {
        $this->lastHealthCheck = null;
        return $this->isHealthy();
    }
}
