<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;
use Monolog\LogRecord;

/**
 * Custom JSON Log Formatter for Centralized Logging
 * 
 * Produces structured JSON logs compatible with ELK/Loki/Datadog.
 * Includes request context: request_id, user_id, school_id, endpoint, response_time
 */
class JsonLogFormatter extends BaseJsonFormatter
{
    /**
     * Application name for log identification
     */
    protected string $appName;

    /**
     * Environment name
     */
    protected string $environment;

    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = true
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
        
        // Use env() directly to avoid dependency on Laravel container during early bootstrap
        $this->appName = env('APP_NAME', 'absensi');
        $this->environment = env('APP_ENV', 'production');
    }

    /**
     * Format the log record
     */
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);
        
        // Build structured log entry
        $log = [
            // Standard fields
            '@timestamp' => $record->datetime->format('c'),
            '@version' => '1',
            
            // Application identification
            'app' => $this->appName,
            'environment' => $this->environment,
            'service' => 'api',
            
            // Log level
            'level' => $record->level->name,
            'level_value' => $record->level->value,
            
            // Message
            'message' => $record->message,
            
            // Channel (log type)
            'channel' => $record->channel,
            
            // Request context (from LogContext)
            'request' => $this->getRequestContext(),
            
            // User context
            'user' => $this->getUserContext(),
            
            // Additional context from log call
            'context' => $normalized['context'] ?? [],
            
            // Extra data (from processors)
            'extra' => $normalized['extra'] ?? [],
            
            // Server info
            'server' => $this->getServerContext(),
        ];

        // Flatten nested arrays for better searchability in ELK
        $log = $this->flattenForSearch($log);

        return $this->toJson($log) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * Get request context from LogContext singleton
     */
    protected function getRequestContext(): array
    {
        return [
            'id' => LogContext::get('request_id'),
            'method' => LogContext::get('method'),
            'endpoint' => LogContext::get('endpoint'),
            'url' => LogContext::get('url'),
            'ip' => LogContext::get('ip'),
            'user_agent' => LogContext::get('user_agent'),
            'response_time_ms' => LogContext::get('response_time'),
            'status_code' => LogContext::get('status_code'),
            'trace_id' => LogContext::get('trace_id'),
        ];
    }

    /**
     * Get user context from LogContext singleton
     */
    protected function getUserContext(): array
    {
        return [
            'id' => LogContext::get('user_id'),
            'school_id' => LogContext::get('school_id'),
            'role' => LogContext::get('role'),
            'email' => LogContext::get('email'),
        ];
    }

    /**
     * Get server context
     */
    protected function getServerContext(): array
    {
        return [
            'hostname' => gethostname(),
            'instance_id' => env('INSTANCE_ID', gethostname()),
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Flatten nested arrays for better ELK field mapping
     */
    protected function flattenForSearch(array $log): array
    {
        // Add top-level searchable fields
        $log['request_id'] = $log['request']['id'] ?? null;
        $log['user_id'] = $log['user']['id'] ?? null;
        $log['school_id'] = $log['user']['school_id'] ?? null;
        $log['endpoint'] = $log['request']['endpoint'] ?? null;
        $log['response_time_ms'] = $log['request']['response_time_ms'] ?? null;
        $log['ip'] = $log['request']['ip'] ?? null;

        return $log;
    }

    /**
     * Normalize the record for JSON encoding
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalize($record->toArray());

        // Handle exceptions
        if (isset($normalized['context']['exception'])) {
            $exception = $normalized['context']['exception'];
            
            // Check if exception is an object or already normalized array
            if (is_object($record->context['exception'] ?? null)) {
                $exceptionObj = $record->context['exception'];
                $normalized['exception'] = [
                    'class' => get_class($exceptionObj),
                    'message' => $exceptionObj->getMessage(),
                    'code' => $exceptionObj->getCode(),
                    'file' => $exceptionObj->getFile(),
                    'line' => $exceptionObj->getLine(),
                    'trace' => $exception['trace'] ?? [],
                ];
            } elseif (is_array($exception)) {
                $normalized['exception'] = [
                    'class' => $exception['class'] ?? 'Unknown',
                    'message' => $exception['message'] ?? '',
                    'code' => $exception['code'] ?? 0,
                    'file' => $exception['file'] ?? '',
                    'line' => $exception['line'] ?? 0,
                    'trace' => $exception['trace'] ?? [],
                ];
            } else {
                // Exception is a string or other type
                $normalized['exception'] = [
                    'class' => 'Unknown',
                    'message' => is_string($exception) ? $exception : 'Unknown error',
                    'code' => 0,
                    'file' => '',
                    'line' => 0,
                    'trace' => [],
                ];
            }
            unset($normalized['context']['exception']);
        }

        return $normalized;
    }
}
