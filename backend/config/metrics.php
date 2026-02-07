<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Metrics Collection Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for application metrics collection (Prometheus format).
    |
    */

    'enabled' => env('METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Metrics Endpoint
    |--------------------------------------------------------------------------
    */
    'endpoint' => env('METRICS_ENDPOINT', '/metrics'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    | Protect metrics endpoint with basic auth or token
    */
    'auth' => [
        'enabled' => env('METRICS_AUTH_ENABLED', true),
        'type' => env('METRICS_AUTH_TYPE', 'token'), // 'basic', 'token', 'ip'
        'token' => env('METRICS_AUTH_TOKEN'),
        'username' => env('METRICS_AUTH_USERNAME', 'prometheus'),
        'password' => env('METRICS_AUTH_PASSWORD'),
        'allowed_ips' => explode(',', env('METRICS_ALLOWED_IPS', '127.0.0.1,10.0.0.0/8')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metric Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env('METRICS_PREFIX', 'absensi'),

    /*
    |--------------------------------------------------------------------------
    | Collection Settings
    |--------------------------------------------------------------------------
    */
    'collect' => [
        'api' => true,
        'database' => true,
        'redis' => true,
        'queue' => true,
        'system' => true,
        'attendance' => true,  // Business-specific metrics
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Thresholds
    |--------------------------------------------------------------------------
    | Used for generating alert annotations
    */
    'thresholds' => [
        'api_latency_warning' => 0.5,      // 500ms
        'api_latency_critical' => 1.0,     // 1s
        'error_rate_warning' => 2,         // 2%
        'error_rate_critical' => 5,        // 5%
        'db_query_warning' => 0.3,         // 300ms
        'db_query_critical' => 1.0,        // 1s
        'db_connections_warning' => 80,    // 80%
        'db_connections_critical' => 90,   // 90%
        'redis_memory_warning' => 75,      // 75%
        'redis_memory_critical' => 90,     // 90%
        'queue_backlog_warning' => 500,    // 500 jobs
        'queue_backlog_critical' => 1000,  // 1000 jobs
    ],

    /*
    |--------------------------------------------------------------------------
    | Histogram Buckets
    |--------------------------------------------------------------------------
    */
    'buckets' => [
        'http_duration' => [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
        'db_duration' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0],
        'job_duration' => [0.1, 0.5, 1.0, 5.0, 10.0, 30.0, 60.0, 120.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cardinality Limits
    |--------------------------------------------------------------------------
    | Prevent metrics explosion
    */
    'limits' => [
        'max_endpoints' => 100,
        'max_labels' => 10,
    ],
];
