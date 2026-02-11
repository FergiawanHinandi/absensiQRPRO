<?php

namespace App\Services\SelfHealing;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Events\CircuitBreakerOpened;
use App\Events\CircuitBreakerClosed;

enum CircuitBreakerState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}

class CircuitBreaker
{
    private string $name;
    private int $failureThreshold;
    private int $successThreshold;
    private int $timeout;
    private ?string $fallback;
    
    public function __construct(
        string $name,
        int $failureThreshold = 3,
        int $successThreshold = 2,
        int $timeout = 10,
        ?string $fallback = null
    ) {
        $this->name = $name;
        $this->failureThreshold = $failureThreshold;
        $this->successThreshold = $successThreshold;
        $this->timeout = $timeout;
        $this->fallback = $fallback;
    }
    
    /**
     * Execute operation with circuit breaker protection
     */
    public function call(callable $operation, array $context = [])
    {
        $state = $this->getState();
        
        if ($state === CircuitBreakerState::OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->setState(CircuitBreakerState::HALF_OPEN);
                Log::info("Circuit breaker {$this->name} entering HALF_OPEN state");
            } else {
                $this->recordRejection($context);
                throw new CircuitBreakerOpenException(
                    "Circuit breaker '{$this->name}' is OPEN. Using fallback: {$this->fallback}"
                );
            }
        }
        
        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($e, $context);
            throw $e;
        }
    }
    
    /**
     * Get current circuit breaker state
     */
    public function getState(): CircuitBreakerState
    {
        $state = Cache::get("circuit_breaker:{$this->name}:state", CircuitBreakerState::CLOSED->value);
        return CircuitBreakerState::from($state);
    }
    
    /**
     * Set circuit breaker state
     */
    private function setState(CircuitBreakerState $state): void
    {
        Cache::put("circuit_breaker:{$this->name}:state", $state->value, 3600);
    }
    
    /**
     * Record successful operation
     */
    private function recordSuccess(): void
    {
        $state = $this->getState();
        
        if ($state === CircuitBreakerState::HALF_OPEN) {
            $successes = Cache::increment("circuit_breaker:{$this->name}:successes");
            
            if ($successes >= $this->successThreshold) {
                $this->setState(CircuitBreakerState::CLOSED);
                Cache::forget("circuit_breaker:{$this->name}:failures");
                Cache::forget("circuit_breaker:{$this->name}:successes");
                Cache::forget("circuit_breaker:{$this->name}:opened_at");
                
                Log::info("Circuit breaker {$this->name} CLOSED (recovered)", [
                    'successes' => $successes,
                ]);
                
                event(new CircuitBreakerClosed($this->name));
            }
        } else {
            // Reset failure count on success in CLOSED state
            Cache::forget("circuit_breaker:{$this->name}:failures");
        }
    }
    
    /**
     * Record failed operation
     */
    private function recordFailure(Throwable $e, array $context): void
    {
        $failures = Cache::increment("circuit_breaker:{$this->name}:failures");
        
        if ($failures >= $this->failureThreshold) {
            $this->setState(CircuitBreakerState::OPEN);
            Cache::put("circuit_breaker:{$this->name}:opened_at", now(), 3600);
            Cache::forget("circuit_breaker:{$this->name}:successes");
            
            Log::critical("Circuit breaker {$this->name} OPENED", [
                'failures' => $failures,
                'threshold' => $this->failureThreshold,
                'last_error' => $e->getMessage(),
                'context' => $context,
            ]);
            
            event(new CircuitBreakerOpened($this->name, $e, $context));
        } else {
            Log::warning("Circuit breaker {$this->name} failure recorded", [
                'failures' => $failures,
                'threshold' => $this->failureThreshold,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Record rejected operation (circuit is open)
     */
    private function recordRejection(array $context): void
    {
        $rejections = Cache::increment("circuit_breaker:{$this->name}:rejections");
        
        if ($rejections % 100 === 0) {
            Log::warning("Circuit breaker {$this->name} rejections", [
                'rejections' => $rejections,
                'context' => $context,
            ]);
        }
    }
    
    /**
     * Check if circuit breaker should attempt reset
     */
    private function shouldAttemptReset(): bool
    {
        $openedAt = Cache::get("circuit_breaker:{$this->name}:opened_at");
        
        if (!$openedAt) {
            return true;
        }
        
        return now()->diffInSeconds($openedAt) >= $this->timeout;
    }
    
    /**
     * Manually reset circuit breaker
     */
    public function reset(): void
    {
        $this->setState(CircuitBreakerState::CLOSED);
        Cache::forget("circuit_breaker:{$this->name}:failures");
        Cache::forget("circuit_breaker:{$this->name}:successes");
        Cache::forget("circuit_breaker:{$this->name}:rejections");
        Cache::forget("circuit_breaker:{$this->name}:opened_at");
        
        Log::info("Circuit breaker {$this->name} manually reset");
    }
    
    /**
     * Get circuit breaker metrics
     */
    public function getMetrics(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->getState()->value,
            'failures' => Cache::get("circuit_breaker:{$this->name}:failures", 0),
            'successes' => Cache::get("circuit_breaker:{$this->name}:successes", 0),
            'rejections' => Cache::get("circuit_breaker:{$this->name}:rejections", 0),
            'opened_at' => Cache::get("circuit_breaker:{$this->name}:opened_at"),
            'config' => [
                'failure_threshold' => $this->failureThreshold,
                'success_threshold' => $this->successThreshold,
                'timeout' => $this->timeout,
                'fallback' => $this->fallback,
            ],
        ];
    }
}

class CircuitBreakerOpenException extends \Exception
{
}
