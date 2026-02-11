<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the production monitoring and observability layer.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enable Monitoring
    |--------------------------------------------------------------------------
    |
    | Master switch to enable/disable monitoring features.
    |
    */

    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Slow Query Thresholds (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Configure thresholds for slow query detection and alerting.
    |
    */

    'slow_query' => [
        // Log as warning when query exceeds this time
        'warning_threshold' => env('SLOW_QUERY_WARNING_MS', 500),
        
        // Log as critical when query exceeds this time
        'critical_threshold' => env('SLOW_QUERY_CRITICAL_MS', 2000),
        
        // Maximum number of slow queries per minute before alerting
        'alert_threshold_per_minute' => env('SLOW_QUERY_ALERT_THRESHOLD', 10),
        
        // Log all queries (ONLY for debugging, heavy performance impact)
        'log_all_queries' => env('LOG_ALL_QUERIES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure queue monitoring thresholds.
    |
    */

    'queue' => [
        // Alert when pending jobs exceed this count
        'backlog_alert_threshold' => env('QUEUE_BACKLOG_ALERT', 1000),
        
        // Alert when failed jobs exceed this count per hour
        'failed_jobs_alert_threshold' => env('QUEUE_FAILED_ALERT', 10),
        
        // Queues to monitor
        'monitored_queues' => [
            'default',
            'high',
            'low',
            'notifications',
            'attendance',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Rate Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure error rate thresholds.
    |
    */

    'errors' => [
        // Alert when errors per minute exceed this count
        'alert_threshold_per_minute' => env('ERROR_RATE_ALERT', 20),
        
        // Error types to track
        'tracked_types' => [
            'App\Exceptions',
            'Illuminate\Database',
            'Illuminate\Queue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Health Thresholds
    |--------------------------------------------------------------------------
    |
    | Configure system resource thresholds.
    |
    */

    'system' => [
        // Memory usage percentage warning threshold
        'memory_warning_percent' => env('MEMORY_WARNING_PERCENT', 80),
        
        // Disk usage percentage warning threshold  
        'disk_warning_percent' => env('DISK_WARNING_PERCENT', 85),
        
        // Response time P95 threshold in milliseconds
        'response_time_p95_ms' => env('RESPONSE_TIME_P95_THRESHOLD', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how alerts are delivered.
    |
    */

    'alerts' => [
        // Enable sending alerts
        'enabled' => env('ALERTS_ENABLED', true),
        
        // Webhook URL for critical alerts (Slack, Discord, etc.)
        'webhook_url' => env('MONITORING_ALERT_WEBHOOK_URL'),
        
        // Email recipients for critical alerts
        'email_recipients' => env('MONITORING_ALERT_EMAILS'),
        
        // Rate limit alerts (one alert per type per this many minutes)
        'rate_limit_minutes' => env('ALERT_RATE_LIMIT_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sentry Integration
    |--------------------------------------------------------------------------
    |
    | Sentry error tracking configuration.
    |
    */

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
        
        // Sample rate for error capturing (0.0 to 1.0)
        'sample_rate' => env('SENTRY_SAMPLE_RATE', 1.0),
        
        // Sample rate for performance tracing (0.0 to 1.0)
        'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.2),
        
        // Environment tag
        'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Retention
    |--------------------------------------------------------------------------
    |
    | How long to retain various metrics data.
    |
    */

    'retention' => [
        // Slow query data retention in hours
        'slow_queries_hours' => env('METRICS_RETENTION_SLOW_QUERIES', 24),
        
        // Error count data retention in hours
        'errors_hours' => env('METRICS_RETENTION_ERRORS', 24),
        
        // Alerts retention in days
        'alerts_days' => env('METRICS_RETENTION_ALERTS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the health check endpoint behavior.
    |
    */

    'health_check' => [
        // Services to check
        'services' => [
            'database' => true,
            'redis' => true,
            'cache' => true,
            'storage' => true,
            'queue' => true,
        ],
        
        // Cache the health check response for this many seconds
        'cache_ttl' => env('HEALTH_CHECK_CACHE_TTL', 10),
    ],

];
