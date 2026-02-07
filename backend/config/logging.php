<?php

use App\Logging\CentralizedLoggerFactory;
use App\Logging\JsonLogFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    | For production with centralized logging: LOG_CHANNEL=centralized
    | For local development: LOG_CHANNEL=stack
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        /*
        |----------------------------------------------------------------------
        | Centralized Logging Channels
        |----------------------------------------------------------------------
        | These channels ship logs to ELK/Loki/Graylog for centralized logging.
        | Use LOG_CHANNEL=centralized in production.
        */

        'centralized' => [
            'driver' => 'stack',
            'channels' => ['elk', 'daily'],
            'ignore_exceptions' => false,
        ],

        'elk' => [
            'driver' => 'custom',
            'via' => CentralizedLoggerFactory::class,
            'driver_type' => env('CENTRALIZED_LOG_DRIVER', 'elk'), // elk, loki, graylog, fluentd
            'host' => env('LOGSTASH_HOST', 'localhost'),
            'port' => env('LOGSTASH_PORT', 5044),
            'protocol' => env('LOGSTASH_PROTOCOL', 'tcp'),
            'timeout' => 5,
            'level' => env('LOG_LEVEL', 'debug'),
            'local_fallback' => true,
            'memory_usage' => true,
            'web_processor' => true,
            'introspection' => true,
        ],

        'loki' => [
            'driver' => 'custom',
            'via' => CentralizedLoggerFactory::class,
            'driver_type' => 'loki',
            'host' => env('LOKI_HOST', 'localhost'),
            'port' => env('LOKI_PORT', 3100),
            'stream' => 'php://stdout', // Promtail collects from stdout
            'level' => env('LOG_LEVEL', 'debug'),
            'local_fallback' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Request Logging Channel
        |----------------------------------------------------------------------
        | Logs all HTTP requests with timing and context.
        */

        'request' => [
            'driver' => 'daily',
            'path' => storage_path('logs/requests.log'),
            'level' => 'debug',
            'days' => 7,
            'formatter' => JsonLogFormatter::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Auth Events Channel
        |----------------------------------------------------------------------
        | Logs authentication events: login, logout, password changes.
        */

        'auth' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auth.log'),
            'level' => 'debug',
            'days' => 30, // Keep auth logs longer for security audit
            'formatter' => JsonLogFormatter::class,
        ],

        /*
        |----------------------------------------------------------------------
        | System Events Channel
        |----------------------------------------------------------------------
        | Logs system events: errors, job failures, slow queries.
        */

        'system' => [
            'driver' => 'daily',
            'path' => storage_path('logs/system.log'),
            'level' => 'debug',
            'days' => 14,
            'formatter' => JsonLogFormatter::class,
        ],

        /*
        |----------------------------------------------------------------------
        | Default Channels (Laravel)
        |----------------------------------------------------------------------
        */

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'attendance' => [
            'driver' => 'daily',
            'path' => storage_path('logs/attendance.log'),
            'level' => 'debug',
            'days' => 30,
            'replace_placeholders' => true,
            // Note: For JSON formatting, use the 'attendance_json' channel
        ],

        // JSON-formatted attendance logs for structured logging/ELK stack
        'attendance_json' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/attendance-json.log'),
            ],
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
            'level' => 'debug',
        ],

        // Combined attendance channel (file + JSON)
        'attendance_combined' => [
            'driver' => 'stack',
            'channels' => ['attendance', 'attendance_json'],
            'ignore_exceptions' => false,
        ],

        'superadmin' => [
            'driver' => 'daily',
            'path' => storage_path('logs/superadmin.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'debug',
            'days' => 30, // Keep security logs longer
            'replace_placeholders' => true,
        ],

        // JSON-formatted security logs
        'security_json' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/security-json.log'),
            ],
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
            'level' => 'debug',
        ],

        'attendance_security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/attendance_security.log'),
            'level' => 'debug',
            'days' => 90, // Keep attendance security logs for 90 days
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        // Backup-specific logging channel
        'backup' => [
            'driver' => 'daily',
            'path' => storage_path('logs/backup.log'),
            'level' => 'debug',
            'days' => 90, // Keep backup logs for 90 days for audit compliance
            'replace_placeholders' => true,
        ],

        // Atomic rollback logging channel
        'rollback' => [
            'driver' => 'daily',
            'path' => storage_path('logs/rollback.log'),
            'level' => 'debug',
            'days' => 365, // Keep rollback logs for 1 year for compliance
            'replace_placeholders' => true,
        ],

        // JSON-formatted rollback logs for structured logging
        'rollback_json' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/rollback-json.log'),
            ],
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
            'level' => 'debug',
        ],

    ],

];
