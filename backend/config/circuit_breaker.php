<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Controls how the circuit breaker handles Redis failures. When Redis
    | fails repeatedly, the circuit opens and all operations fall back to
    | database-based alternatives until Redis recovers.
    |
    */

    // Number of consecutive failures before opening the circuit
    'failure_threshold' => (int) env('CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),

    // Seconds to wait before attempting recovery (HALF_OPEN)
    'recovery_timeout' => (int) env('CIRCUIT_BREAKER_RECOVERY_TIMEOUT', 30),

    // Number of test calls in HALF_OPEN state before closing the circuit
    'half_open_max_calls' => (int) env('CIRCUIT_BREAKER_HALF_OPEN_MAX_CALLS', 3),

];
