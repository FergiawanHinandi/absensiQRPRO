<?php

namespace App\Infrastructure\CircuitBreaker;

/**
 * Circuit Breaker Open Exception
 * 
 * Thrown when a circuit breaker is in OPEN state and blocks an operation.
 */
class CircuitBreakerOpenException extends \RuntimeException
{
    public function __construct(string $message = 'Circuit breaker is open', int $code = 503)
    {
        parent::__construct($message, $code);
    }
}
