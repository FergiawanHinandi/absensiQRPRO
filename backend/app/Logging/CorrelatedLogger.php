<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Correlated Logger Service
 * 
 * Provides structured logging with automatic correlation context injection.
 * All logs include: request_id, trace_id, span_id, user_id, school_id.
 * 
 * Log Levels:
 * - debug: Detailed debugging (dev only, not in production)
 * - info: Normal operations (successful scans, logins)
 * - warning: Recoverable issues (slow queries, rate limits)
 * - error: Failures that need attention (scan failures, auth errors)
 * - critical: System-level failures (DB down, service unavailable)
 * 
 * Usage:
 *   $logger = app(CorrelatedLogger::class);
 *   $logger->info('attendance', 'Scan completed', ['student_id' => 123]);
 *   $logger->withSpan('validate_qr', fn() => $this->validateQr($token));
 */
class CorrelatedLogger
{
    /**
     * Log channels for different categories
     */
    protected const CHANNELS = [
        'attendance' => 'attendance',
        'security' => 'security',
        'auth' => 'auth',
        'system' => 'system',
        'request' => 'request',
        'default' => 'daily',
    ];

    /**
     * Log a debug message (development only)
     */
    public function debug(string $category, string $message, array $context = []): void
    {
        $this->log('debug', $category, $message, $context);
    }

    /**
     * Log an info message (normal operations)
     */
    public function info(string $category, string $message, array $context = []): void
    {
        $this->log('info', $category, $message, $context);
    }

    /**
     * Log a warning message (recoverable issues)
     */
    public function warning(string $category, string $message, array $context = []): void
    {
        $this->log('warning', $category, $message, $context);
    }

    /**
     * Log an error message (failures needing attention)
     */
    public function error(string $category, string $message, array $context = []): void
    {
        $this->log('error', $category, $message, $context);
    }

    /**
     * Log a critical message (system-level failures)
     */
    public function critical(string $category, string $message, array $context = []): void
    {
        $this->log('critical', $category, $message, $context);
    }

    /**
     * Execute a callback within a new span context
     * 
     * Automatically logs span start and completion with timing.
     * 
     * @template T
     * @param string $operation Span name (e.g., 'AttendanceService.validate')
     * @param callable(): T $callback The operation to execute
     * @param string $category Log category for span events
     * @return T The result of the callback
     */
    public function withSpan(string $operation, callable $callback, string $category = 'system'): mixed
    {
        $spanId = LogContext::pushSpan($operation);
        
        $this->debug($category, "Span started: {$operation}", [
            'span_id' => $spanId,
        ]);

        try {
            $result = $callback();

            $spanInfo = LogContext::popSpan();
            $this->debug($category, "Span completed: {$operation}", [
                'duration_ms' => $spanInfo['duration_ms'],
                'span_id' => $spanInfo['span_id'],
            ]);

            return $result;
        } catch (\Throwable $e) {
            $spanInfo = LogContext::popSpan();
            $this->error($category, "Span failed: {$operation}", [
                'duration_ms' => $spanInfo['duration_ms'],
                'span_id' => $spanInfo['span_id'],
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            
            throw $e;
        }
    }

    /**
     * Log an exception with full context
     */
    public function exception(string $category, \Throwable $exception, array $context = []): void
    {
        $this->error($category, $exception->getMessage(), array_merge($context, [
            'exception_class' => get_class($exception),
            'exception_code' => $exception->getCode(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $this->formatTrace($exception),
        ]));
    }

    /**
     * Log attendance-specific events with standardized structure
     */
    public function attendanceEvent(
        string $eventType,
        string $level,
        string $message,
        array $context = []
    ): void {
        $this->log($level, 'attendance', $message, array_merge([
            'event_type' => "attendance.{$eventType}",
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    /**
     * Log security-specific events with standardized structure
     */
    public function securityEvent(
        string $eventType,
        string $severity,
        string $message,
        array $context = []
    ): void {
        $level = match ($severity) {
            'critical' => 'critical',
            'high' => 'error',
            'medium' => 'warning',
            'low' => 'info',
            default => 'warning',
        };

        $this->log($level, 'security', $message, array_merge([
            'event_type' => "security.{$eventType}",
            'severity' => $severity,
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    /**
     * Log authentication events
     */
    public function authEvent(
        string $eventType,
        bool $success,
        string $message,
        array $context = []
    ): void {
        $level = $success ? 'info' : 'warning';

        $this->log($level, 'auth', $message, array_merge([
            'event_type' => "auth.{$eventType}",
            'success' => $success,
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }

    /**
     * Internal log method with correlation context
     */
    protected function log(string $level, string $category, string $message, array $context = []): void
    {
        $channel = self::CHANNELS[$category] ?? self::CHANNELS['default'];

        // Merge correlation context
        $fullContext = array_merge(
            LogContext::getCorrelationContext(),
            [
                'category' => $category,
                'log_level' => $level,
            ],
            $context
        );

        // Add user trace fields for per-user filtering
        if (!isset($fullContext['user_id'])) {
            $fullContext['user_id'] = LogContext::get('user_id');
        }
        if (!isset($fullContext['request_date'])) {
            $fullContext['request_date'] = LogContext::get('request_date') ?? now()->toDateString();
        }

        Log::channel($channel)->log($level, $message, $fullContext);
    }

    /**
     * Format exception trace for logging
     */
    protected function formatTrace(\Throwable $exception): array
    {
        $trace = $exception->getTrace();
        
        // Only include first 5 frames to keep logs manageable
        return array_slice(array_map(function ($frame) {
            return sprintf(
                '%s%s%s() in %s:%d',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? 'unknown',
                basename($frame['file'] ?? 'unknown'),
                $frame['line'] ?? 0
            );
        }, $trace), 0, 5);
    }
}
