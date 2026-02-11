<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configurations
    |--------------------------------------------------------------------------
    |
    | Define circuit breaker configurations for different dependencies.
    | Each circuit breaker has:
    | - failure_threshold: Number of failures before opening
    | - success_threshold: Number of successes to close from half-open
    | - timeout: Seconds to wait before attempting reset
    | - fallback: Fallback strategy when circuit is open
    |
    */

    'redis' => [
        'failure_threshold' => 3,
        'success_threshold' => 2,
        'timeout' => 10,  // seconds
        'fallback' => 'database_cache',
    ],

    'database' => [
        'failure_threshold' => 5,
        'success_threshold' => 3,
        'timeout' => 30,  // seconds
        'fallback' => 'read_replica',
    ],

    'external_api' => [
        'failure_threshold' => 3,
        'success_threshold' => 2,
        'timeout' => 60,  // seconds
        'fallback' => 'cached_response',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Remediation Rules
    |--------------------------------------------------------------------------
    |
    | Define thresholds and actions for auto-remediation
    |
    */

    'remediation' => [
        'queue' => [
            'lag_warning_threshold' => 500,
            'lag_critical_threshold' => 2000,
            'failed_jobs_threshold' => 50,
            'oldest_job_age_threshold' => 600,  // seconds
        ],

        'redis' => [
            'memory_warning_threshold' => 85,  // percent
            'memory_critical_threshold' => 95,  // percent
        ],

        'database' => [
            'connection_warning_threshold' => 80,  // percent
            'connection_critical_threshold' => 90,  // percent
            'latency_warning_threshold' => 500,  // ms
            'latency_critical_threshold' => 1000,  // ms
            'replication_lag_threshold' => 10,  // seconds
        ],

        'disk' => [
            'usage_warning_threshold' => 75,  // percent
            'usage_critical_threshold' => 85,  // percent
        ],

        'memory' => [
            'usage_warning_threshold' => 80,  // percent
            'usage_critical_threshold' => 90,  // percent
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Scaling Configuration
    |--------------------------------------------------------------------------
    */

    'scaling' => [
        'queue_workers' => [
            'min' => 2,
            'max' => 10,
            'cooldown' => 300,  // seconds
        ],

        'web_app' => [
            'min' => 3,
            'max' => 20,
            'cooldown' => 600,  // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Degraded Mode Configuration
    |--------------------------------------------------------------------------
    */

    'degraded_mode' => [
        'duration' => 600,  // seconds (10 minutes)
        'disable_features' => [
            'heavy_dashboard_aggregations',
            'admin_report_exports',
            'bulk_operations',
        ],
    ],
];
