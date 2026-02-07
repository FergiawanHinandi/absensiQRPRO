<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for real-time monitoring of backup and restore jobs
    |
    */

    'monitoring' => [
        'enabled' => env('BACKUP_MONITORING_ENABLED', true),
        
        // Job tracking settings
        'track_duration' => true,
        'track_size' => true,
        'track_progress' => true,
        
        // Retention settings
        'job_retention_days' => env('BACKUP_JOB_RETENTION_DAYS', 90),
        'log_retention_days' => env('BACKUP_LOG_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for various alert channels
    |
    */

    'alerts' => [
        'enabled' => env('BACKUP_ALERTS_ENABLED', true),

        // Email alerts
        'email' => [
            'enabled' => env('BACKUP_EMAIL_ALERTS_ENABLED', true),
            'recipients' => explode(',', env('BACKUP_ALERT_EMAILS', '')),
            'from_address' => env('BACKUP_ALERT_FROM', 'alerts@yourapp.com'),
            'from_name' => env('BACKUP_ALERT_FROM_NAME', 'Backup Monitoring'),
        ],

        // Slack alerts
        'slack' => [
            'enabled' => env('BACKUP_SLACK_ALERTS_ENABLED', false),
            'webhook_url' => env('BACKUP_SLACK_WEBHOOK_URL'),
            'channel' => env('BACKUP_SLACK_CHANNEL', '#alerts'),
            'username' => env('BACKUP_SLACK_USERNAME', 'BackupBot'),
        ],

        // Webhook alerts
        'webhook' => [
            'enabled' => env('BACKUP_WEBHOOK_ALERTS_ENABLED', false),
            'url' => env('BACKUP_WEBHOOK_URL'),
            'headers' => [
                'Authorization' => env('BACKUP_WEBHOOK_AUTH'),
                'Content-Type' => 'application/json',
            ],
            'timeout' => env('BACKUP_WEBHOOK_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Alert thresholds for anomaly detection
    |
    */

    'thresholds' => [
        // Size anomaly threshold (percentage drop from average)
        'size_anomaly' => env('BACKUP_SIZE_ANOMALY_THRESHOLD', 30),

        // Duration anomaly threshold (percentage increase from average)
        'duration_anomaly' => env('BACKUP_DURATION_ANOMALY_THRESHOLD', 50),

        // Maximum job duration in seconds before alert
        'max_duration' => [
            'backup' => env('BACKUP_MAX_DURATION_SECONDS', 3600), // 1 hour
            'restore' => env('RESTORE_MAX_DURATION_SECONDS', 7200), // 2 hours
            'rollback' => env('ROLLBACK_MAX_DURATION_SECONDS', 1800), // 30 minutes
        ],

        // Minimum backup size in bytes
        'min_backup_size' => env('BACKUP_MIN_SIZE_BYTES', 1024), // 1KB
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the monitoring dashboard
    |
    */

    'dashboard' => [
        'refresh_interval' => env('BACKUP_DASHBOARD_REFRESH_INTERVAL', 30), // seconds
        'max_displayed_jobs' => env('BACKUP_MAX_DISPLAYED_JOBS', 100),
        'chart_data_points' => env('BACKUP_CHART_DATA_POINTS', 30), // days
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Performance-related configuration
    |
    */

    'performance' => [
        'cleanup_interval_hours' => env('BACKUP_CLEANUP_INTERVAL_HOURS', 24),
        'batch_size' => env('BACKUP_BATCH_SIZE', 100),
        'cache_ttl_minutes' => env('BACKUP_CACHE_TTL_MINUTES', 5),
    ],
];
