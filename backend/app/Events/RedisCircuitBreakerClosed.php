<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Redis Circuit Breaker Closed Event
 * 
 * Dispatched when the circuit breaker transitions to CLOSED state (recovered)
 */
class RedisCircuitBreakerClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct()
    {
        //
    }
}
