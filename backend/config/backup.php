<?php

use Spatie\DbDumper\Compressors\GzipCompressor;

return [

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => config('app.name', 'AbsensiQRPro'),

        'source' => [
            'files' => [
                /*
                 * The list of directories and files that will be included in the backup.
                 * Only backup essential files - public uploads and security reports.
                 */
                'include' => [
                    storage_path('app/public'),
                    storage_path('app/security-reports'),
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 * Exclude temporary files, vendor, node_modules, and backup temp directory.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('app/backup-temp'),
                    storage_path('app/backups-temp'),
                    storage_path('logs'),
                    storage_path('framework/cache'),
                    storage_path('framework/sessions'),
                    storage_path('framework/views'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 */
                'follow_links' => false,

                /*
                 * Determines if it should avoid unreadable folders.
                 */
                'ignore_unreadable_directories' => true,

                /*
                 * This path is used to make directories in resulting zip-file relative
                 * Set to `null` to include complete absolute path
                 */
                'relative_path' => base_path(),
            ],

            /*
             * The names of the connections to the databases that should be backed up.
             * Uses the default database connection from .env (supports pgsql, mysql, sqlite).
             */
            'databases' => [
                env('DB_CONNECTION', 'pgsql'),
            ],
        ],

        /*
         * The database dump can be compressed to decrease disk space usage.
         * On Windows without gzip, set to null to disable compression.
         * On Linux/production servers, use GzipCompressor::class.
         */
        'database_dump_compressor' => env('BACKUP_USE_GZIP', false) ? GzipCompressor::class : null,

        /*
         * Database dump filename will contain a timestamp for easy identification.
         */
        'database_dump_file_timestamp_format' => 'Y-m-d-H-i-s',

        /*
         * Use database name in dump filename for clarity.
         */
        'database_dump_filename_base' => 'database',

        /*
         * The file extension used for the database dump files.
         */
        'database_dump_file_extension' => 'sql',

        'destination' => [
            /*
             * High compression for smaller backup files.
             */
            'compression_method' => ZipArchive::CM_DEFLATE,

            /*
             * Maximum compression level (9) for smallest file size.
             */
            'compression_level' => 9,

            /*
             * The filename prefix used for the backup zip file.
             */
            'filename_prefix' => 'absensi-backup-',

            /*
             * The disk names on which the backups will be stored.
             * Local always included, S3 only when BACKUP_AWS_BUCKET is configured.
             * This prevents errors when S3 is not set up in development.
             */
            'disks' => array_filter([
                'local',
                env('BACKUP_AWS_BUCKET') ? 'backups-s3' : null,
            ]),
        ],

        /*
         * The directory where the temporary files will be stored.
         */
        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * AES-256 encryption password from environment.
         * This uses a separate encryption key, not the app key.
         */
        'password' => env('BACKUP_ENCRYPTION_KEY'),

        /*
         * Use AES-256 encryption for maximum security.
         */
        'encryption' => 'default',

        /*
         * Retry backup 3 times on failure.
         */
        'tries' => 3,

        /*
         * Wait 30 seconds between retry attempts.
         */
        'retry_delay' => 30,
    ],

    /*
     * Custom encryption configuration.
     * Using a separate key from APP_KEY for defense in depth.
     */
    'encryption' => [
        'key' => env('BACKUP_ENCRYPTION_KEY'),
        'algorithm' => 'AES-256-CBC',
    ],

    /*
     * Notification configuration for backup events.
     */
    'notifications' => [
        'notifications' => [
            \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class => ['mail', 'slack'],
            \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class => ['mail', 'slack'],
            \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class => ['mail'],
            \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class => [],
            \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class => [],
            \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent.
         */
        'notifiable' => \Spatie\Backup\Notifications\Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL', 'admin@example.com'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'noreply@absensi.local'),
                'name' => env('MAIL_FROM_NAME', 'AbsensiQRPro Backup System'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('BACKUP_SLACK_WEBHOOK_URL', ''),
            'channel' => env('BACKUP_SLACK_CHANNEL', '#alerts'),
            'username' => 'AbsensiQRPro Backup',
            'icon' => ':floppy_disk:',
        ],

        'discord' => [
            'webhook_url' => env('BACKUP_DISCORD_WEBHOOK_URL', ''),
            'username' => 'AbsensiQRPro Backup',
            'avatar_url' => '',
        ],
    ],

    /*
     * Monitor backup health. Alert if backup is older than 26 hours.
     */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'AbsensiQRPro'),
            'disks' => array_filter([
                'local',
                env('BACKUP_AWS_BUCKET') ? 'backups-s3' : null,
            ]),
            'health_checks' => [
                // Alert if no backup in last 26 hours (allows for some delay in daily backups)
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                // Alert if backups exceed 10GB total
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 10240,
            ],
        ],
    ],

    /*
     * Cleanup strategy with retention policy:
     * - Daily backups: 14 days
     * - Weekly backups: 8 weeks
     * - Monthly backups: 6 months
     */
    'cleanup' => [
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

        'default_strategy' => [
            /*
             * Keep all backups for 7 days (since we run DB and files separately).
             */
            'keep_all_backups_for_days' => 7,

            /*
             * Keep daily backups for 30 days total (requirement: 30-day retention).
             */
            'keep_daily_backups_for_days' => 30,

            /*
             * Keep weekly backups for 8 weeks.
             */
            'keep_weekly_backups_for_weeks' => 8,

            /*
             * Keep monthly backups for 6 months.
             */
            'keep_monthly_backups_for_months' => 6,

            /*
             * Keep yearly backups for 2 years (for compliance).
             */
            'keep_yearly_backups_for_years' => 2,

            /*
             * Maximum storage limit: 20GB before oldest backups are deleted.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => 20480,
        ],

        /*
         * Retry cleanup 2 times on failure.
         */
        'tries' => 2,

        /*
         * Wait 15 seconds between retry attempts.
         */
        'retry_delay' => 15,
    ],

];
