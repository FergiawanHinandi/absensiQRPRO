<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker for Redis operations.
 *
 * States:
 *   CLOSED   → Normal operation. Failures are counted.
 *   OPEN     → Redis is down. All calls go to fallback immediately.
 *   HALF_OPEN → Testing recovery. Limited calls to Redis.
 *
 * State is stored in APCu (process-local) since Redis itself may be down.
 * Falls back to in-memory tracking if APCu is unavailable.
 */
class RedisCircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    private int $failureThreshold;

    private int $recoveryTimeoutSeconds;

    private int $halfOpenMaxCalls;

    // In-memory fallback when APCu is unavailable
    private static string $memoryState = self::STATE_CLOSED;

    private static int $memoryFailureCount = 0;

    private static int $memoryLastFailureTime = 0;

    private static int $memoryHalfOpenCalls = 0;

    public function __construct()
    {
        $this->failureThreshold = (int) config('circuit_breaker.failure_threshold', 5);
        $this->recoveryTimeoutSeconds = (int) config('circuit_breaker.recovery_timeout', 30);
        $this->halfOpenMaxCalls = (int) config('circuit_breaker.half_open_max_calls', 3);
    }

    /**
     * Execute a Redis operation with circuit breaker protection.
     *
     * @param  string   $operation  Name for logging
     * @param  Closure  $redisOp    The Redis operation
     * @param  Closure  $fallback   Fallback when circuit is open
     * @return mixed
     */
    public function execute(string $operation, Closure $redisOp, Closure $fallback): mixed
    {
        $state = $this->getState();

        if ($state === self::STATE_OPEN) {
            // Check if recovery timeout has elapsed
            if ($this->shouldAttemptRecovery()) {
                $this->setState(self::STATE_HALF_OPEN);
                $this->resetHalfOpenCalls();
            } else {
                Log::channel('attendance_json')->debug("Circuit breaker OPEN, using fallback for: {$operation}");

                return $fallback();
            }
        }

        if ($state === self::STATE_HALF_OPEN && $this->getHalfOpenCalls() >= $this->halfOpenMaxCalls) {
            return $fallback();
        }

        try {
            $result = $redisOp();

            // Success: reset failure count if we were testing
            if ($this->getState() === self::STATE_HALF_OPEN) {
                $this->incrementHalfOpenCalls();
                if ($this->getHalfOpenCalls() >= $this->halfOpenMaxCalls) {
                    // All test calls succeeded, close the circuit
                    $this->setState(self::STATE_CLOSED);
                    $this->resetFailureCount();
                    Log::channel('attendance_json')->info('Circuit breaker CLOSED (recovered)', [
                        'operation' => $operation,
                    ]);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($operation, $e);

            return $fallback();
        }
    }

    public function getState(): string
    {
        if (function_exists('apcu_fetch')) {
            $state = apcu_fetch('circuit_breaker:state');

            return $state !== false ? $state : self::STATE_CLOSED;
        }

        return self::$memoryState;
    }

    public function getMetrics(): array
    {
        return [
            'state' => $this->getState(),
            'failure_count' => $this->getFailureCount(),
            'failure_threshold' => $this->failureThreshold,
            'recovery_timeout_seconds' => $this->recoveryTimeoutSeconds,
            'last_failure_time' => $this->getLastFailureTime(),
        ];
    }

    /**
     * Manually trip the circuit breaker (for testing or emergency).
     */
    public function trip(): void
    {
        $this->setState(self::STATE_OPEN);
        $this->setLastFailureTime(time());
        Log::channel('attendance_json')->warning('Circuit breaker manually tripped');
    }

    /**
     * Manually reset the circuit breaker.
     */
    public function reset(): void
    {
        $this->setState(self::STATE_CLOSED);
        $this->resetFailureCount();
        Log::channel('attendance_json')->info('Circuit breaker manually reset');
    }

    private function recordFailure(string $operation, \Throwable $e): void
    {
        $failureCount = $this->incrementFailureCount();
        $this->setLastFailureTime(time());

        Log::channel('attendance_json')->warning("Redis operation failed: {$operation}", [
            'error' => $e->getMessage(),
            'failure_count' => $failureCount,
            'threshold' => $this->failureThreshold,
        ]);

        if ($failureCount >= $this->failureThreshold) {
            $this->setState(self::STATE_OPEN);
            Log::channel('attendance_json')->error('Circuit breaker OPEN (threshold reached)', [
                'operation' => $operation,
                'failure_count' => $failureCount,
                'recovery_timeout' => $this->recoveryTimeoutSeconds,
            ]);
        }
    }

    private function shouldAttemptRecovery(): bool
    {
        $lastFailure = $this->getLastFailureTime();

        return (time() - $lastFailure) >= $this->recoveryTimeoutSeconds;
    }

    // Storage accessors with APCu / in-memory fallback

    private function setState(string $state): void
    {
        if (function_exists('apcu_store')) {
            apcu_store('circuit_breaker:state', $state, 3600);
        } else {
            self::$memoryState = $state;
        }
    }

    private function getFailureCount(): int
    {
        if (function_exists('apcu_fetch')) {
            $count = apcu_fetch('circuit_breaker:failure_count');

            return $count !== false ? (int) $count : 0;
        }

        return self::$memoryFailureCount;
    }

    private function incrementFailureCount(): int
    {
        if (function_exists('apcu_inc')) {
            $result = apcu_inc('circuit_breaker:failure_count', 1, $success);
            if (! $success) {
                apcu_store('circuit_breaker:failure_count', 1, 3600);

                return 1;
            }

            return $result;
        }

        return ++self::$memoryFailureCount;
    }

    private function resetFailureCount(): void
    {
        if (function_exists('apcu_store')) {
            apcu_store('circuit_breaker:failure_count', 0, 3600);
        } else {
            self::$memoryFailureCount = 0;
        }
    }

    private function getLastFailureTime(): int
    {
        if (function_exists('apcu_fetch')) {
            $time = apcu_fetch('circuit_breaker:last_failure_time');

            return $time !== false ? (int) $time : 0;
        }

        return self::$memoryLastFailureTime;
    }

    private function setLastFailureTime(int $time): void
    {
        if (function_exists('apcu_store')) {
            apcu_store('circuit_breaker:last_failure_time', $time, 3600);
        } else {
            self::$memoryLastFailureTime = $time;
        }
    }

    private function getHalfOpenCalls(): int
    {
        if (function_exists('apcu_fetch')) {
            $calls = apcu_fetch('circuit_breaker:half_open_calls');

            return $calls !== false ? (int) $calls : 0;
        }

        return self::$memoryHalfOpenCalls;
    }

    private function incrementHalfOpenCalls(): void
    {
        if (function_exists('apcu_inc')) {
            $result = apcu_inc('circuit_breaker:half_open_calls', 1, $success);
            if (! $success) {
                apcu_store('circuit_breaker:half_open_calls', 1, 3600);
            }
        } else {
            self::$memoryHalfOpenCalls++;
        }
    }

    private function resetHalfOpenCalls(): void
    {
        if (function_exists('apcu_store')) {
            apcu_store('circuit_breaker:half_open_calls', 0, 3600);
        } else {
            self::$memoryHalfOpenCalls = 0;
        }
    }
}
