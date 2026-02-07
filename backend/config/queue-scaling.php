<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Worker Auto-Scaling Configuration
    |--------------------------------------------------------------------------
    |
    | Auto-scale queue workers based on queue length.
    | Rules:
    | - >500 pending jobs → add worker
    | - <100 jobs for 10 min → remove worker
    |
    */

    'enabled' => env('QUEUE_SCALING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Scaling Thresholds
    |--------------------------------------------------------------------------
    */
    'scale_up_threshold' => env('QUEUE_SCALE_UP_THRESHOLD', 500),      // Add worker when >500 jobs
    'scale_down_threshold' => env('QUEUE_SCALE_DOWN_THRESHOLD', 100),  // Remove worker when <100 jobs
    'scale_down_duration' => env('QUEUE_SCALE_DOWN_DURATION', 600),    // For 10 minutes (seconds)

    /*
    |--------------------------------------------------------------------------
    | Worker Limits
    |--------------------------------------------------------------------------
    */
    'min_workers' => env('QUEUE_MIN_WORKERS', 1),
    'max_workers' => env('QUEUE_MAX_WORKERS', 10),
    'workers_to_add' => env('QUEUE_WORKERS_TO_ADD', 1),
    'workers_to_remove' => env('QUEUE_WORKERS_TO_REMOVE', 1),

    /*
    |--------------------------------------------------------------------------
    | Cooldown Periods (seconds)
    |--------------------------------------------------------------------------
    */
    'cooldown_up' => env('QUEUE_COOLDOWN_UP', 60),      // 1 minute between scale ups
    'cooldown_down' => env('QUEUE_COOLDOWN_DOWN', 300), // 5 minutes between scale downs

    /*
    |--------------------------------------------------------------------------
    | Queues to Monitor
    |--------------------------------------------------------------------------
    */
    'queues' => ['default', 'high', 'low', 'notifications'],

    /*
    |--------------------------------------------------------------------------
    | Scaling Provider
    |--------------------------------------------------------------------------
    | Supported: 'supervisor', 'kubernetes', 'aws', 'horizon', 'generic'
    */
    'provider' => env('QUEUE_SCALING_PROVIDER', 'supervisor'),

    /*
    |--------------------------------------------------------------------------
    | Provider-Specific Configuration
    |--------------------------------------------------------------------------
    */

    // Supervisor
    'supervisor' => [
        'program' => env('SUPERVISOR_WORKER_PROGRAM', 'laravel-worker'),
        'config_path' => env('SUPERVISOR_CONFIG_PATH', '/etc/supervisor/conf.d/laravel-worker.conf'),
    ],

    // Kubernetes
    'kubernetes' => [
        'namespace' => env('K8S_NAMESPACE', 'production'),
        'deployment' => env('K8S_WORKER_DEPLOYMENT', 'queue-worker'),
    ],

    // AWS ECS
    'aws' => [
        'cluster' => env('AWS_ECS_CLUSTER'),
        'service' => env('AWS_ECS_WORKER_SERVICE'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled' => env('QUEUE_SCALING_NOTIFY', true),
        'channels' => ['slack', 'log'],
        'slack_webhook' => env('SLACK_QUEUE_SCALING_WEBHOOK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggressive Scaling (Emergency)
    |--------------------------------------------------------------------------
    | Bypass cooldown when thresholds are critical
    */
    'aggressive_scaling' => [
        'enabled' => true,
        'threshold' => 2000,  // Emergency scale at >2000 jobs
        'workers_to_add' => 3,
    ],
];
