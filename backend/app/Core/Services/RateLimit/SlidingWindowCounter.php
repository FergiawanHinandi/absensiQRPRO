<?php

namespace App\Core\Services\RateLimit;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Sliding Window Counter using Redis Sorted Sets
 *
 * Implements atomic sliding window algorithm for precise rate limiting.
 * Uses Lua scripts for atomicity — prevents race conditions.
 *
 * Spec: critical-rate-limiting / tasks.md Task 2
 */
class SlidingWindowCounter
{
    /**
     * Lua script for atomic sliding window increment + count.
     * Returns current count after increment.
     */
    private const LUA_SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local max = tonumber(ARGV[3])

        -- Remove entries older than the window
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)

        -- Count current entries
        local count = redis.call('ZCARD', key)

        -- If under limit, add this request
        if count < max then
            redis.call('ZADD', key, now, now .. ':' .. math.random(1, 1000000))
            redis.call('EXPIRE', key, window)
            return count + 1
        end

        -- Set expiry anyway (refresh window)
        redis.call('EXPIRE', key, window)
        return count
    LUA;

    /**
     * Lua script for count-only (no increment).
     */
    private const LUA_COUNT = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])

        -- Remove expired entries
        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)

        return redis.call('ZCARD', key)
    LUA;

    public function __construct(
        private readonly string $connectionName = 'default'
    ) {}

    /**
     * Attempt to increment counter.
     * Returns [count, allowed] tuple.
     *
     * @return array{count: int, allowed: bool}
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array
    {
        try {
            $redis = Redis::connection($this->connectionName);
            $now = microtime(true) * 1000; // Milliseconds for precision

            $count = $redis->eval(
                self::LUA_SCRIPT,
                1,
                $key,
                $now,
                $windowSeconds * 1000, // Convert to ms
                $maxAttempts
            );

            return [
                'count'   => (int) $count,
                'allowed' => (int) $count <= $maxAttempts,
            ];
        } catch (\Exception $e) {
            Log::warning('SlidingWindowCounter: Redis error', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);

            // Fail-open by default (configurable)
            return ['count' => 0, 'allowed' => true];
        }
    }

    /**
     * Get current count without incrementing.
     */
    public function count(string $key, int $windowSeconds): int
    {
        try {
            $redis = Redis::connection($this->connectionName);
            $now = microtime(true) * 1000;

            return (int) $redis->eval(
                self::LUA_COUNT,
                1,
                $key,
                $now,
                $windowSeconds * 1000
            );
        } catch (\Exception $e) {
            Log::warning('SlidingWindowCounter: Count failed', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Reset / clear the counter for a key.
     */
    public function reset(string $key): bool
    {
        try {
            Redis::connection($this->connectionName)->del($key);
            return true;
        } catch (\Exception $e) {
            Log::error('SlidingWindowCounter: Reset failed', ['key' => $key]);
            return false;
        }
    }

    /**
     * Get remaining time (seconds) until the window resets.
     */
    public function ttl(string $key): int
    {
        try {
            return max(0, (int) Redis::connection($this->connectionName)->ttl($key));
        } catch (\Exception $e) {
            return 0;
        }
    }
}
