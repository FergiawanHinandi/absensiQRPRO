<?php

return [
    /*
    |--------------------------------------------------------------------------
    | High Availability Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the HA monitoring system. These thresholds determine
    | when alerts are triggered and auto-failover is executed.
    |
    */

    'enabled' => env('HA_MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Monitoring Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        // Database
        'db_replication_lag_warning' => env('HA_DB_LAG_WARNING', 10),       // seconds
        'db_replication_lag_critical' => env('HA_DB_LAG_CRITICAL', 60),     // seconds
        'db_latency_warning' => env('HA_DB_LATENCY_WARNING', 100),          // ms
        'db_latency_critical' => env('HA_DB_LATENCY_CRITICAL', 500),        // ms

        // Redis
        'redis_memory_warning' => env('HA_REDIS_MEM_WARNING', 80),          // percentage
        'redis_memory_critical' => env('HA_REDIS_MEM_CRITICAL', 95),        // percentage

        // Queue
        'queue_backlog_warning' => env('HA_QUEUE_BACKLOG_WARNING', 100),    // jobs
        'queue_backlog_critical' => env('HA_QUEUE_BACKLOG_CRITICAL', 1000), // jobs
        'queue_failed_warning' => env('HA_QUEUE_FAILED_WARNING', 10),       // jobs per hour
        'queue_failed_critical' => env('HA_QUEUE_FAILED_CRITICAL', 50),     // jobs per hour

        // App Server
        'app_response_warning' => env('HA_APP_RESPONSE_WARNING', 500),      // ms
        'app_response_critical' => env('HA_APP_RESPONSE_CRITICAL', 2000),   // ms
        'disk_usage_warning' => env('HA_DISK_WARNING', 80),                 // percentage
        'disk_usage_critical' => env('HA_DISK_CRITICAL', 90),               // percentage
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Failover Configuration
    |--------------------------------------------------------------------------
    */
    'auto_failover' => [
        'enabled' => env('HA_AUTO_FAILOVER', false),
        'consecutive_failures_required' => env('HA_FAILOVER_THRESHOLD', 3),
        
        // Components that support auto-failover
        'components' => [
            'database' => [
                'enabled' => env('HA_DB_AUTO_FAILOVER', true),
                'action' => 'promote_replica',
            ],
            'redis' => [
                'enabled' => false, // Sentinel handles this
                'action' => 'sentinel_managed',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Configuration
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'enabled' => env('HA_ALERTS_ENABLED', true),
        
        // Cooldown period to prevent alert flooding (minutes)
        'cooldown_minutes' => env('HA_ALERT_COOLDOWN', 5),
        
        // Alert channels
        'channels' => [
            'slack' => [
                'enabled' => env('HA_ALERT_SLACK', true),
                'webhook' => env('SLACK_HA_WEBHOOK', env('SLACK_SECURITY_WEBHOOK_URL')),
            ],
            'discord' => [
                'enabled' => env('HA_ALERT_DISCORD', false),
                'webhook' => env('DISCORD_HA_WEBHOOK'),
            ],
            'pagerduty' => [
                'enabled' => env('HA_ALERT_PAGERDUTY', false),
                'integration_key' => env('PAGERDUTY_INTEGRATION_KEY'),
            ],
            'email' => [
                'enabled' => env('HA_ALERT_EMAIL', false),
                'recipients' => explode(',', env('HA_ALERT_EMAILS', '')),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Schedule
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'interval_seconds' => env('HA_CHECK_INTERVAL', 30),
        'daemon_enabled' => env('HA_DAEMON_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Component-Specific Configuration
    |--------------------------------------------------------------------------
    */
    'components' => [
        'database' => [
            'check_primary' => true,
            'check_replica' => env('DB_REPLICA_ENABLED', false),
            'check_replication_lag' => env('DB_REPLICA_ENABLED', false),
        ],
        
        'redis' => [
            'check_memory' => true,
            'check_role' => true,
            'check_sentinel' => env('REDIS_REPLICATION') === 'sentinel',
            'expected_role' => env('REDIS_EXPECTED_ROLE', 'master'),
        ],
        
        'queue' => [
            'check_backlog' => true,
            'check_failed_jobs' => true,
            'check_workers' => true,
            'queues_to_monitor' => ['default', 'high', 'low', 'notifications'],
        ],
        
        'storage' => [
            'check_local' => true,
            'check_cloud' => env('FILESYSTEM_CLOUD', 's3') !== null,
        ],
        
        'app_server' => [
            'check_disk' => true,
            'check_memory' => true,
            'check_opcache' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failover Strategy Matrix
    |--------------------------------------------------------------------------
    | Defines what happens when each component fails.
    */
    'failover_strategy' => [
        'app_server' => [
            'action' => 'lb_redirect',
            'description' => 'Load balancer redirects to healthy server',
            'impact' => 'No user impact',
            'recovery_time' => '< 1 second',
        ],
        'primary_db' => [
            'action' => 'promote_replica',
            'description' => 'Promote replica to primary',
            'impact' => 'Write pause',
            'recovery_time' => '< 1 minute',
        ],
        'redis' => [
            'action' => 'sentinel_switch',
            'description' => 'Sentinel promotes slave to master',
            'impact' => 'Session safe',
            'recovery_time' => '< 30 seconds',
        ],
        'storage' => [
            'action' => 'cloud_redundancy',
            'description' => 'Cloud storage redundancy',
            'impact' => 'No user impact',
            'recovery_time' => 'Automatic',
        ],
    ],
];
