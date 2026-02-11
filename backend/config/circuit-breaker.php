<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | Redis Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the circuit breaker behavior for Redis connections.
    |
    */
    
    'redis' => [
        
        /*
        | Failure Threshold
        |
        | Number of consecutive failures before opening the circuit.
        | Default: 5
        */
        'failure_threshold' => env('REDIS_CB_FAILURE_THRESHOLD', 5),
        
        /*
        | Retry Timeout (seconds)
        |
        | Time to wait before attempting to close the circuit after it opens.
        | Default: 30 seconds
        */
        'retry_timeout' => env('REDIS_CB_RETRY_TIMEOUT', 30),
        
        /*
        | Success Threshold
        |
        | Number of consecutive successes in HALF_OPEN state before closing circuit.
        | Default: 2
        */
        'success_threshold' => env('REDIS_CB_SUCCESS_THRESHOLD', 2),
        
    ],
    
];
