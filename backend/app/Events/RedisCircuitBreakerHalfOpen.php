<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Redis Circuit Breaker Half Open Event
 * 
 * Dispatched when the circuit breaker transitions to HALF_OPEN state (testing recovery)
 */
class RedisCircuitBreakerHalfOpen
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct()
    {
        //
    }
}
