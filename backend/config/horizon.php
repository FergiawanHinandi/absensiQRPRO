<?php

use Laravel\Horizon\Horizon;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | Horizon will use this Redis connection for storing job data.
    | When using Sentinel, this should point to a connection configured
    | with Sentinel support in config/database.php
    |
    */

    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('HORIZON_PREFIX', 'absensi-horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | Configure which middleware Horizon will use for its routes.
    | For production, add authorization middleware.
    |
    */

    'middleware' => [
        'web',
        \App\Http\Middleware\HorizonAuthMiddleware::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | Horizon can monitor wait times and alert when they exceed thresholds.
    | Configure thresholds in seconds per queue.
    |
    */

    'waits' => [
        'redis:default' => 60,          // Alert if waiting > 1 minute
        'redis:high' => 30,             // High priority - faster threshold
        'redis:low' => 300,             // Low priority - more relaxed
        'redis:notifications' => 60,
        'redis:attendance' => 30,       // Attendance processing should be fast
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Priority Configuration
    |--------------------------------------------------------------------------
    |
    | Configure job priority preservation during failover.
    | Jobs will maintain their priority when recovered after Redis failover.
    |
    */

    'priority' => [
        'preserve_on_failover' => env('HORIZON_PRESERVE_PRIORITY', true),
        'default_priority' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | How long to retain job history in minutes.
    |
    */

    'trim' => [
        'recent' => 60,                 // Recent jobs: 1 hour
        'pending' => 60,                // Pending jobs: 1 hour
        'completed' => 60,              // Completed jobs: 1 hour
        'recent_failed' => 10080,       // Failed jobs: 7 days
        'failed' => 10080,              // Failed jobs: 7 days
        'monitored' => 10080,           // Monitored tags: 7 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,                // Keep 24 hours of job metrics
            'queue' => 24,              // Keep 24 hours of queue metrics
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When using Supervisor, enable fast termination so workers can be
    | restarted quickly during deployments.
    |
    */

    'fast_termination' => true,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Supervisor process settings for different environments.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['high', 'default', 'attendance', 'notifications', 'low'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 10,
            'minProcesses' => 1,
            'maxTime' => 3600,          // Max job time: 1 hour
            'maxJobs' => 1000,          // Max jobs before restart
            'memory' => 256,            // Memory limit per worker
            'tries' => 3,               // Max attempts
            'timeout' => 300,           // Job timeout: 5 minutes
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['high', 'default', 'attendance', 'notifications', 'low'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,    // Always at least 2 workers
                'maxProcesses' => 10,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],

            // Dedicated high-priority supervisor
            'supervisor-high' => [
                'connection' => 'redis',
                'queue' => ['high', 'attendance'],
                'balance' => 'simple',
                'processes' => 3,
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,       // Faster timeout for high priority
                'nice' => -5,           // Higher OS priority
            ],
        ],

        'staging' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['high', 'default', 'attendance', 'notifications', 'low'],
                'balance' => 'simple',
                'processes' => 3,
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['high', 'default', 'attendance', 'notifications', 'low'],
                'balance' => 'simple',
                'processes' => 2,
                'maxTime' => 3600,
                'maxJobs' => 100,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 60,
                'nice' => 0,
            ],
        ],
    ],
];
