<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Advanced Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the advanced backup strategy including full backups,
    | incremental backups, and WAL/binlog archiving for PITR capability.
    |
    */

    'strategy' => [
        'full_backup' => [
            'schedule' => 'weekly',
            'day' => 'sunday',
            'time' => '02:00',
            'retention_weeks' => 12,
            'compression_level' => 9,
            'parallel_jobs' => 4,
        ],

        'incremental_backup' => [
            'schedule' => 'daily',
            'time' => '02:00',
            'retention_days' => 30,
            'compression_level' => 6,
            'exclude_full_backup_day' => true,
        ],

        'transaction_log_archive' => [
            'enabled' => true,
            'interval_seconds' => 300, // 5 minutes
            'retention_days' => 7,
            'compression' => true,
        ],
    ],

    'storage' => [
        'backup_path' => storage_path('backups/advanced'),
        'temp_path' => storage_path('temp/backup'),
        'archive_path' => storage_path('backups/archive'),
        
        'disk_space_threshold' => 85, // Percentage
        'cleanup_on_threshold' => true,
    ],

    'multi_tenant' => [
        'isolation_enabled' => true,
        'per_school_backup' => true,
        'global_data_backup' => true,
        'tenant_validation' => true,
    ],

    'postgresql' => [
        'wal_archive' => [
            'enabled' => true,
            'archive_command' => 'cp %p ' . storage_path('backups/advanced/wal_archive/%f'),
            'archive_timeout' => 300, // 5 minutes
        ],
        
        'backup_options' => [
            'format' => 'custom', // custom, plain, directory, tar
            'compression_level' => 9,
            'jobs' => 4,
            'verbose' => true,
        ],
        
        'pitr' => [
            'recovery_target_action' => 'promote',
            'recovery_target_timeline' => 'latest',
        ],
    ],

    'mysql' => [
        'binlog_archive' => [
            'enabled' => true,
            'expire_logs_days' => 7,
            'max_binlog_size' => '1G',
        ],
        
        'backup_options' => [
            'single_transaction' => true,
            'routines' => true,
            'triggers' => true,
            'events' => true,
            'flush_logs' => true,
            'master_data' => 2,
        ],
        
        'pitr' => [
            'binlog_format' => 'ROW',
            'sync_binlog' => 1,
        ],
    ],

    'monitoring' => [
        'enabled' => true,
        'metrics' => [
            'backup_duration',
            'backup_size',
            'compression_ratio',
            'success_rate',
            'storage_usage',
        ],
        
        'alerts' => [
            'backup_failure' => [
                'enabled' => true,
                'channels' => ['email', 'slack'],
                'severity' => 'critical',
            ],
            
            'storage_threshold' => [
                'enabled' => true,
                'threshold' => 80,
                'channels' => ['email'],
                'severity' => 'warning',
            ],
            
            'long_running_backup' => [
                'enabled' => true,
                'threshold_minutes' => 120,
                'channels' => ['slack'],
                'severity' => 'warning',
            ],
        ],
    ],

    'security' => [
        'encryption' => [
            'enabled' => true,
            'algorithm' => 'AES-256-GCM',
            'key_rotation_days' => 90,
        ],
        
        'access_control' => [
            'backup_user_only' => true,
            'file_permissions' => 0600,
            'directory_permissions' => 0700,
        ],
        
        'audit' => [
            'log_all_operations' => true,
            'retention_years' => 7,
            'include_metadata' => true,
        ],
    ],

    'performance' => [
        'parallel_processing' => [
            'enabled' => true,
            'max_workers' => 4,
            'chunk_size' => 1000,
        ],
        
        'compression' => [
            'algorithm' => 'gzip',
            'level' => 6,
            'parallel' => true,
        ],
        
        'network' => [
            'timeout_seconds' => 3600,
            'retry_attempts' => 3,
            'retry_delay_seconds' => 30,
        ],
    ],

    'validation' => [
        'integrity_checks' => [
            'enabled' => true,
            'checksum_algorithm' => 'sha256',
            'verify_after_backup' => true,
            'verify_before_restore' => true,
        ],
        
        'consistency_checks' => [
            'foreign_key_constraints' => true,
            'data_type_validation' => true,
            'referential_integrity' => true,
        ],
    ],

    'recovery' => [
        'rto_targets' => [
            'small_database' => 30,  // minutes (< 1GB)
            'medium_database' => 60, // minutes (1-10GB)
            'large_database' => 120, // minutes (> 10GB)
        ],
        
        'rpo_targets' => [
            'attendance_data' => 5,    // minutes
            'student_photos' => 60,    // minutes
            'configuration' => 1440,   // minutes (24 hours)
        ],
        
        'verification' => [
            'data_consistency' => true,
            'application_health' => true,
            'performance_baseline' => true,
        ],
    ],
];