<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Redis Circuit Breaker Opened Event
 * 
 * Dispatched when the circuit breaker transitions to OPEN state
 */
class RedisCircuitBreakerOpened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct()
    {
        //
    }
}
