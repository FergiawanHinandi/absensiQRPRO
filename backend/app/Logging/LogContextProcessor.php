<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog Processor that injects LogContext into all log entries.
 * 
 * This ensures every log message includes:
 * - request_id: Unique identifier for HTTP request correlation
 * - trace_id: End-to-end trace ID for distributed tracing
 * - user_id: Authenticated user ID (null for guests)
 * - school_id: Multi-tenant school context
 * - endpoint: Normalized API endpoint path
 * - span_id: Current operation span for call hierarchy
 * 
 * @version 1.0.0
 */
class LogContextProcessor implements ProcessorInterface
{
    /**
     * Context keys to include in every log entry
     */
    protected array $contextKeys = [
        'request_id',
        'trace_id',
        'span_id',
        'user_id',
        'school_id',
        'endpoint',
        'method',
        'ip',
        'role',
    ];

    /**
     * Process a log record and inject context
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Add correlation context
        foreach ($this->contextKeys as $key) {
            $value = LogContext::get($key);
            if ($value !== null) {
                $extra[$key] = $value;
            }
        }

        // Add performance metrics if available
        $responseTime = LogContext::get('response_time');
        if ($responseTime !== null) {
            $extra['response_time_ms'] = $responseTime;
        }

        $statusCode = LogContext::get('status_code');
        if ($statusCode !== null) {
            $extra['status_code'] = $statusCode;
        }

        // Add span context for distributed tracing
        $spanDuration = LogContext::getSpanDuration();
        if ($spanDuration > 0) {
            $extra['span_duration_ms'] = $spanDuration;
        }

        $operation = LogContext::get('current_operation');
        if ($operation) {
            $extra['operation'] = $operation;
        }

        // Add environment context
        $extra['environment'] = config('app.env', 'production');
        $extra['service'] = config('app.name', 'AbsensiQRPro');

        return $record->with(extra: $extra);
    }
}
