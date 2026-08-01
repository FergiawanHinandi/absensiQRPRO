<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitoring Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable monitoring features globally.
    |
    */

    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for queue monitoring and alerting.
    |
    */

    'queue' => [
        'enabled' => env('QUEUE_MONITORING_ENABLED', true),
        'backlog_alert_threshold' => env('QUEUE_BACKLOG_ALERT', 1000),
        'failed_jobs_alert_threshold' => env('QUEUE_FAILED_ALERT', 10),
        'alert_cooldown_minutes' => env('QUEUE_ALERT_COOLDOWN_MINUTES', 5),
        'alert_cooldown_max_minutes' => env('QUEUE_ALERT_COOLDOWN_MAX_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Channels
    |--------------------------------------------------------------------------
    |
    | Configure alert notification channels.
    |
    */

    'alerts' => [
        'enabled' => env('ALERTS_ENABLED', true),
        
        // Slack webhook URL for alerts
        'slack_webhook_url' => env('MONITORING_ALERT_WEBHOOK_URL'),
        
        // Comma-separated list of email addresses for alerts
        'alert_emails' => env('MONITORING_ALERT_EMAILS'),
        
        // Rate limit for alerts (minutes)
        'rate_limit_minutes' => env('ALERT_RATE_LIMIT_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode
    |--------------------------------------------------------------------------
    |
    | Suppress alerts during maintenance windows.
    |
    */

    'maintenance' => [
        'suppress_alerts' => env('MAINTENANCE_SUPPRESS_ALERTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for Redis health monitoring.
    |
    */

    'redis' => [
        'enabled' => env('REDIS_MONITORING_ENABLED', true),
        'memory_warning_threshold' => env('REDIS_MEMORY_WARNING_THRESHOLD', 80),
        'alert_cooldown_minutes' => env('REDIS_ALERT_COOLDOWN_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Query Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for slow query detection and alerting.
    |
    */

    'slow_queries' => [
        'enabled' => env('SLOW_QUERY_MONITORING_ENABLED', true),
        'warning_threshold_ms' => env('SLOW_QUERY_WARNING_MS', 500),
        'critical_threshold_ms' => env('SLOW_QUERY_CRITICAL_MS', 2000),
        'alert_threshold' => env('SLOW_QUERY_ALERT_THRESHOLD', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Rate Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for error rate monitoring.
    |
    */

    'error_rate' => [
        'enabled' => env('ERROR_RATE_MONITORING_ENABLED', true),
        'alert_threshold' => env('ERROR_RATE_ALERT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Resource Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for system resource monitoring.
    |
    */

    'resources' => [
        'memory_warning_percent' => env('MEMORY_WARNING_PERCENT', 80),
        'disk_warning_percent' => env('DISK_WARNING_PERCENT', 85),
        'response_time_p95_threshold' => env('RESPONSE_TIME_P95_THRESHOLD', 500),
    ],

];
