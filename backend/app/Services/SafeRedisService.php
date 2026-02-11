<?php

namespace App\Services;

use App\Infrastructure\CircuitBreaker\CircuitBreakerOpenException;
use App\Infrastructure\CircuitBreaker\RedisCircuitBreaker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Safe Redis Service
 * 
 * Wraps Redis operations with circuit breaker protection.
 * Provides fallback strategies when Redis is unavailable.
 */
class SafeRedisService
{
    public function __construct(
        private RedisCircuitBreaker $circuitBreaker
    ) {}
    
    /**
     * Get a value from Redis with circuit breaker protection
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        try {
            return $this->circuitBreaker->execute(
                operation: fn() => Redis::get($key),
                fallback: fn() => $default
            );
        } catch (CircuitBreakerOpenException $e) {
            Log::debug('Redis GET failed (circuit open)', ['key' => $key]);
            return $default;
        }
    }
    
    /**
     * Set a value in Redis with circuit breaker protection
     * 
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl Time to live in seconds
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        try {
            return $this->circuitBreaker->execute(
                operation: function() use ($key, $value, $ttl) {
                    if ($ttl) {
                        return Redis::setex($key, $ttl, $value);
                    }
                    return Redis::set($key, $value);
                },
                fallback: fn() => false
            );
        } catch (CircuitBreakerOpenException $e) {
            Log::debug('Redis SET failed (circuit open)', ['key' => $key]);
            return false;
        }
    }
    
    /**
     * Delete a key from Redis with circuit breaker protection
     * 
     * @param string|array $keys
     * @return int Number of keys deleted
     */
    public function delete($keys): int
    {
        try {
            return $this->circuitBreaker->execute(
                operation: fn() => Redis::del($keys),
                fallback: fn() => 0
            );
        } catch (CircuitBreakerOpenException $e) {
            Log::debug('Redis DEL failed (circuit open)', ['keys' => $keys]);
            return 0;
        }
    }
    
    /**
     * Acquire a lock with circuit breaker protection
     * 
     * @param string $key
     * @param int $seconds
     * @return \Illuminate\Contracts\Cache\Lock|null
     */
    public function lock(string $key, int $seconds = 5)
    {
        try {
            return $this->circuitBreaker->execute(
                operation: fn() => Redis::lock($key, $seconds),
                fallback: fn() => null
            );
        } catch (CircuitBreakerOpenException $e) {
            Log::warning('Redis LOCK failed (circuit open)', ['key' => $key]);
            return null;
        }
    }
    
    /**
     * Increment a value with circuit breaker protection
     * 
     * @param string $key
     * @param int $value
     * @return int|false
     */
    public function increment(string $key, int $value = 1)
    {
        try {
            return $this->circuitBreaker->execute(
                operation: fn() => Redis::incrby($key, $value),
                fallback: fn() => false
            );
        } catch (CircuitBreakerOpenException $e) {
            Log::debug('Redis INCR failed (circuit open)', ['key' => $key]);
            return false;
        }
    }
    
    /**
     * Check if Redis is available
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->circuitBreaker->isAvailable();
    }
    
    /**
     * Get circuit breaker status
     * 
     * @return array
     */
    public function getCircuitStatus(): array
    {
        return $this->circuitBreaker->getStatus();
    }
    
    /**
     * Execute a custom Redis operation with circuit breaker
     * 
     * @param callable $operation
     * @param callable|null $fallback
     * @return mixed
     */
    public function execute(callable $operation, ?callable $fallback = null)
    {
        try {
            return $this->circuitBreaker->execute($operation, $fallback);
        } catch (CircuitBreakerOpenException $e) {
            if ($fallback) {
                return $fallback();
            }
            throw $e;
        }
    }
}
