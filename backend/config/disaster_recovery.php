<?php

/**
 * Disaster Recovery Configuration
 *
 * Spec: disaster-recovery-audit-improvements / requirements.md
 * Covers: RTO/RPO targets, scenario definitions, environment procedures
 */
return [

    /*
    |--------------------------------------------------------------------------
    | RTO / RPO Targets (US-1.1)
    |--------------------------------------------------------------------------
    |
    | RTO = Recovery Time Objective (max downtime allowed)
    | RPO = Recovery Point Objective (max data loss allowed)
    */
    'scenarios' => [

        'database_failure' => [
            'name'        => 'Database Server Failure',
            'rto_minutes' => 30,
            'rpo_minutes' => 5,
            'priority'    => 'critical',
            'auto_failover' => true,
            'procedures'  => ['failover_to_replica', 'restore_from_backup'],
        ],

        'redis_failure' => [
            'name'        => 'Redis / Cache Server Failure',
            'rto_minutes' => 5,
            'rpo_minutes' => 0,  // Acceptable to lose cache
            'priority'    => 'high',
            'auto_failover' => true,
            'procedures'  => ['fallback_to_database_cache', 'restart_redis'],
        ],

        'application_server_failure' => [
            'name'        => 'Application Server Failure',
            'rto_minutes' => 10,
            'rpo_minutes' => 0,
            'priority'    => 'high',
            'auto_failover' => true,
            'procedures'  => ['redirect_traffic', 'spin_up_replacement'],
        ],

        'storage_failure' => [
            'name'        => 'File Storage / S3 Failure',
            'rto_minutes' => 60,
            'rpo_minutes' => 60,
            'priority'    => 'medium',
            'auto_failover' => false,
            'procedures'  => ['switch_to_local_storage', 'restore_from_backup'],
        ],

        'complete_datacenter_failure' => [
            'name'        => 'Complete Datacenter Failure',
            'rto_minutes' => 120,
            'rpo_minutes' => 60,
            'priority'    => 'critical',
            'auto_failover' => false,
            'procedures'  => ['activate_dr_site', 'restore_full_backup', 'update_dns'],
        ],

        'data_corruption' => [
            'name'        => 'Data Corruption',
            'rto_minutes' => 60,
            'rpo_minutes' => 30,
            'priority'    => 'critical',
            'auto_failover' => false,
            'procedures'  => ['stop_writes', 'identify_corruption', 'restore_point_in_time'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-Specific Procedures (US-1.2)
    |--------------------------------------------------------------------------
    */
    'environments' => [

        'production' => [
            'requires_approval'    => true,
            'approved_roles'       => ['super_admin'],
            'min_backup_age_hours' => 1,    // Must have backup < 1 hour old
            'dry_run_required'     => false,
            'safety_checks'        => [
                'verify_backup_integrity',
                'check_active_users',
                'notify_school_admins',
            ],
        ],

        'staging' => [
            'requires_approval'    => false,
            'dry_run_required'     => false,
            'safety_checks'        => [
                'verify_backup_integrity',
            ],
        ],

        'development' => [
            'requires_approval'    => false,
            'dry_run_required'     => false,
            'safety_checks'        => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Configuration (US-5.1)
    |--------------------------------------------------------------------------
    */
    'backup' => [
        // Incremental backup settings
        'incremental' => [
            'enabled'           => env('DR_INCREMENTAL_BACKUP', true),
            'interval_minutes'  => 15,
            'max_chain_length'  => 96, // Max 24 hours of 15-min backups in a chain
        ],

        // Full backup settings
        'full' => [
            'schedule'      => env('DR_FULL_BACKUP_SCHEDULE', '0 2 * * *'), // 2 AM daily
            'retention_days' => 30,
        ],

        // Encryption (US: Security Requirements)
        'encryption' => [
            'enabled'   => env('DR_BACKUP_ENCRYPTION', true),
            'algorithm' => 'AES-256-GCM',
            'key_env'   => 'BACKUP_ENCRYPTION_KEY',
        ],

        // Storage locations
        'destinations' => [
            'primary' => [
                'disk'   => 'local',
                'path'   => 'backups/primary',
                'enabled' => true,
            ],
            's3' => [
                'disk'    => 'backups-s3',
                'path'    => 'backups',
                'enabled' => env('DR_S3_BACKUP_ENABLED', false),
                'region'  => env('DR_S3_REGION', env('AWS_DEFAULT_REGION', 'ap-southeast-1')),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Alerting (US-4.1, US-4.2)
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        // Real-time progress tracking
        'track_progress'       => true,
        'progress_update_secs' => 5,

        // Alert channels
        'alert_channels' => explode(',', env('DR_ALERT_CHANNELS', 'log,mail')),

        // Alert within 5 minutes for critical events
        'max_alert_delay_minutes' => 5,

        // Alert escalation
        'escalation' => [
            'level_1_minutes' => 5,   // First alert
            'level_2_minutes' => 15,  // Escalate to manager
            'level_3_minutes' => 30,  // Escalate to CTO
        ],

        // Alert email recipients
        'notify_emails' => explode(',', env('DR_ALERT_EMAILS', '')),

        // Alert Slack webhook
        'slack_webhook' => env('DR_SLACK_WEBHOOK', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | DR Drill Configuration (US-6.2)
    |--------------------------------------------------------------------------
    */
    'drill' => [
        // Run automated DR drills
        'enabled'         => env('DR_DRILL_ENABLED', false),

        // Schedule: monthly by default
        'schedule'        => env('DR_DRILL_SCHEDULE', '0 3 1 * *'), // 3 AM on 1st of month

        // Drill timeout in minutes
        'timeout_minutes' => 60,

        // Notify on drill completion
        'notify_on_completion' => true,

        // Minimum acceptable success rate
        'min_success_rate' => 95,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant Isolation (US-3.1, US-3.2)
    |--------------------------------------------------------------------------
    */
    'tenant' => [
        // Validate school_id on all backup operations
        'enforce_isolation'    => true,

        // Allow school-specific backup (partial restore)
        'school_backup_enabled' => env('DR_SCHOOL_BACKUP_ENABLED', false),

        // Maximum concurrent school backups
        'max_concurrent'       => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Trail (Security Requirements)
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'log_all_operations' => true,
        'log_channel'        => 'dr_audit',
        'retention_days'     => 365, // 1 year
        'immutable'          => true, // Log entries cannot be modified
    ],
];
