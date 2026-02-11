<?php

namespace App\Infrastructure\CircuitBreaker;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Circuit Breaker
 * 
 * Implements the Circuit Breaker pattern to prevent cascading failures
 * when Redis is unavailable.
 * 
 * States:
 * - CLOSED: Normal operation, all requests go through
 * - OPEN: Redis is down, all requests fail fast
 * - HALF_OPEN: Testing if Redis has recovered
 * 
 * @see https://martinfowler.com/bliki/CircuitBreaker.html
 */
class RedisCircuitBreaker
{
    // Circuit states
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';
    
    // Configuration
    private int $failureThreshold;
    private int $retryTimeoutSeconds;
    private int $successThreshold;
    
    // State storage keys (using file cache to avoid Redis dependency)
    private const STATE_KEY = 'circuit_breaker:redis:state';
    private const FAILURE_COUNT_KEY = 'circuit_breaker:redis:failure_count';
    private const LAST_FAILURE_KEY = 'circuit_breaker:redis:last_failure';
    private const HALF_OPEN_SUCCESS_KEY = 'circuit_breaker:redis:half_open_success';
    
    public function __construct(
        int $failureThreshold = 5,
        int $retryTimeoutSeconds = 30,
        int $successThreshold = 2
    ) {
        $this->failureThreshold = $failureThreshold;
        $this->retryTimeoutSeconds = $retryTimeoutSeconds;
        $this->successThreshold = $successThreshold;
    }
    
    /**
     * Execute a Redis operation with circuit breaker protection
     * 
     * @param callable $operation The Redis operation to execute
     * @param callable|null $fallback Fallback function if circuit is open
     * @return mixed
     * @throws CircuitBreakerOpenException
     */
    public function execute(callable $operation, ?callable $fallback = null)
    {
        $state = $this->getState();
        
        // If circuit is OPEN, fail fast
        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->transitionToHalfOpen();
                return $this->executeInHalfOpenState($operation, $fallback);
            }
            
            Log::warning('Redis circuit breaker is OPEN, failing fast', [
                'failure_count' => $this->getFailureCount(),
                'last_failure' => $this->getLastFailureTime(),
            ]);
            
            if ($fallback) {
                return $fallback();
            }
            
            throw new CircuitBreakerOpenException('Redis circuit breaker is OPEN');
        }
        
        // If circuit is HALF_OPEN, test carefully
        if ($state === self::STATE_HALF_OPEN) {
            return $this->executeInHalfOpenState($operation, $fallback);
        }
        
        // Circuit is CLOSED, execute normally
        return $this->executeInClosedState($operation);
    }
    
    /**
     * Execute operation in CLOSED state
     */
    private function executeInClosedState(callable $operation)
    {
        try {
            $result = $operation();
            
            // Reset failure count on success
            $this->resetFailureCount();
            
            return $result;
            
        } catch (\Exception $e) {
            $this->recordFailure($e);
            throw $e;
        }
    }
    
    /**
     * Execute operation in HALF_OPEN state
     */
    private function executeInHalfOpenState(callable $operation, ?callable $fallback = null)
    {
        try {
            $result = $operation();
            
            // Success in HALF_OPEN state
            $this->recordHalfOpenSuccess();
            
            // If we've had enough successes, close the circuit
            if ($this->getHalfOpenSuccessCount() >= $this->successThreshold) {
                $this->transitionToClosed();
            }
            
            return $result;
            
        } catch (\Exception $e) {
            // Failure in HALF_OPEN state, reopen circuit
            $this->transitionToOpen();
            
            Log::warning('Redis circuit breaker: HALF_OPEN test failed, reopening', [
                'error' => $e->getMessage(),
            ]);
            
            if ($fallback) {
                return $fallback();
            }
            
            throw new CircuitBreakerOpenException('Redis circuit breaker reopened after failed test');
        }
    }
    
    /**
     * Record a failure
     */
    private function recordFailure(\Exception $e): void
    {
        $failureCount = $this->incrementFailureCount();
        $this->setLastFailureTime(now()->timestamp);
        
        Log::warning('Redis operation failed', [
            'error' => $e->getMessage(),
            'failure_count' => $failureCount,
            'threshold' => $this->failureThreshold,
        ]);
        
        // If failures exceed threshold, open the circuit
        if ($failureCount >= $this->failureThreshold) {
            $this->transitionToOpen();
        }
    }
    
    /**
     * Check if we should attempt to reset the circuit
     */
    private function shouldAttemptReset(): bool
    {
        $lastFailure = $this->getLastFailureTime();
        
        if (!$lastFailure) {
            return true;
        }
        
        $timeSinceLastFailure = now()->timestamp - $lastFailure;
        
        return $timeSinceLastFailure >= $this->retryTimeoutSeconds;
    }
    
    /**
     * Transition to OPEN state
     */
    private function transitionToOpen(): void
    {
        $this->setState(self::STATE_OPEN);
        
        Log::error('redis_circuit_opened', [
            'failure_count' => $this->getFailureCount(),
            'threshold' => $this->failureThreshold,
            'retry_timeout' => $this->retryTimeoutSeconds,
        ]);
        
        // Dispatch event for monitoring
        event(new \App\Events\RedisCircuitBreakerOpened());
    }
    
    /**
     * Transition to HALF_OPEN state
     */
    private function transitionToHalfOpen(): void
    {
        $this->setState(self::STATE_HALF_OPEN);
        $this->resetHalfOpenSuccessCount();
        
        Log::info('Redis circuit breaker transitioned to HALF_OPEN', [
            'time_since_last_failure' => now()->timestamp - $this->getLastFailureTime(),
        ]);
        
        event(new \App\Events\RedisCircuitBreakerHalfOpen());
    }
    
    /**
     * Transition to CLOSED state
     */
    private function transitionToClosed(): void
    {
        $this->setState(self::STATE_CLOSED);
        $this->resetFailureCount();
        $this->resetHalfOpenSuccessCount();
        
        Log::info('redis_circuit_closed', [
            'previous_failures' => $this->getFailureCount(),
        ]);
        
        event(new \App\Events\RedisCircuitBreakerClosed());
    }
    
    // ─────────────────────────────────────────────────────────────────────
    // STATE MANAGEMENT (using file cache to avoid Redis dependency)
    // ─────────────────────────────────────────────────────────────────────
    
    private function getState(): string
    {
        return Cache::store('file')->get(self::STATE_KEY, self::STATE_CLOSED);
    }
    
    private function setState(string $state): void
    {
        Cache::store('file')->put(self::STATE_KEY, $state, 3600);
    }
    
    private function getFailureCount(): int
    {
        return (int) Cache::store('file')->get(self::FAILURE_COUNT_KEY, 0);
    }
    
    private function incrementFailureCount(): int
    {
        $count = $this->getFailureCount() + 1;
        Cache::store('file')->put(self::FAILURE_COUNT_KEY, $count, 3600);
        return $count;
    }
    
    private function resetFailureCount(): void
    {
        Cache::store('file')->forget(self::FAILURE_COUNT_KEY);
    }
    
    private function getLastFailureTime(): ?int
    {
        return Cache::store('file')->get(self::LAST_FAILURE_KEY);
    }
    
    private function setLastFailureTime(int $timestamp): void
    {
        Cache::store('file')->put(self::LAST_FAILURE_KEY, $timestamp, 3600);
    }
    
    private function getHalfOpenSuccessCount(): int
    {
        return (int) Cache::store('file')->get(self::HALF_OPEN_SUCCESS_KEY, 0);
    }
    
    private function recordHalfOpenSuccess(): void
    {
        $count = $this->getHalfOpenSuccessCount() + 1;
        Cache::store('file')->put(self::HALF_OPEN_SUCCESS_KEY, $count, 3600);
    }
    
    private function resetHalfOpenSuccessCount(): void
    {
        Cache::store('file')->forget(self::HALF_OPEN_SUCCESS_KEY);
    }
    
    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API FOR MONITORING
    // ─────────────────────────────────────────────────────────────────────
    
    /**
     * Get current circuit breaker status
     */
    public function getStatus(): array
    {
        $state = $this->getState();
        $failureCount = $this->getFailureCount();
        $lastFailure = $this->getLastFailureTime();
        
        return [
            'state' => $state,
            'failure_count' => $failureCount,
            'failure_threshold' => $this->failureThreshold,
            'last_failure_time' => $lastFailure ? date('Y-m-d H:i:s', $lastFailure) : null,
            'time_since_last_failure' => $lastFailure ? (now()->timestamp - $lastFailure) : null,
            'retry_timeout' => $this->retryTimeoutSeconds,
            'is_available' => $state === self::STATE_CLOSED,
        ];
    }
    
    /**
     * Manually reset the circuit breaker
     */
    public function reset(): void
    {
        $this->transitionToClosed();
        
        Log::info('Redis circuit breaker manually reset');
    }
    
    /**
     * Check if Redis is available (without throwing exception)
     */
    public function isAvailable(): bool
    {
        return $this->getState() === self::STATE_CLOSED;
    }
}
