<?php

namespace App\Traits;

use App\Logging\CorrelatedLogger;
use App\Logging\LogContext;

/**
 * Trait for Services that need correlated logging
 * 
 * Provides automatic span management and typed logging methods.
 * All logs include correlation context: request_id, trace_id, span_id, user_id.
 * 
 * Usage:
 * ```php
 * class AttendanceService
 * {
 *     use LogsWithCorrelation;
 *     
 *     public function recordScan(array $data): Attendance
 *     {
 *         return $this->withServiceSpan('recordScan', function () use ($data) {
 *             $this->logInfo('Processing attendance scan', ['student_id' => $data['student_id']]);
 *             
 *             // ... business logic ...
 *             
 *             return $attendance;
 *         });
 *     }
 * }
 * ```
 */
trait LogsWithCorrelation
{
    /**
     * Get the log category for this service
     * Override in service class if needed
     */
    protected function getLogCategory(): string
    {
        // Auto-detect from class name
        $className = class_basename(static::class);
        
        return match (true) {
            str_contains($className, 'Attendance') => 'attendance',
            str_contains($className, 'Auth') => 'auth',
            str_contains($className, 'Security') => 'security',
            str_contains($className, 'Qr') || str_contains($className, 'QR') => 'attendance',
            default => 'system',
        };
    }

    /**
     * Get the correlated logger instance
     */
    protected function logger(): CorrelatedLogger
    {
        return app(CorrelatedLogger::class);
    }

    /**
     * Execute a method within a service span
     * 
     * Creates a new span for the operation and auto-logs start/completion/failure.
     * 
     * @template T
     * @param string $method Method name for span naming
     * @param callable(): T $callback
     * @return T
     */
    protected function withServiceSpan(string $method, callable $callback): mixed
    {
        $operation = class_basename(static::class) . '.' . $method;
        
        return $this->logger()->withSpan($operation, $callback, $this->getLogCategory());
    }

    /**
     * Log a debug message
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $this->logger()->debug($this->getLogCategory(), $message, $this->enrichContext($context));
    }

    /**
     * Log an info message
     */
    protected function logInfo(string $message, array $context = []): void
    {
        $this->logger()->info($this->getLogCategory(), $message, $this->enrichContext($context));
    }

    /**
     * Log a warning message
     */
    protected function logWarning(string $message, array $context = []): void
    {
        $this->logger()->warning($this->getLogCategory(), $message, $this->enrichContext($context));
    }

    /**
     * Log an error message
     */
    protected function logError(string $message, array $context = []): void
    {
        $this->logger()->error($this->getLogCategory(), $message, $this->enrichContext($context));
    }

    /**
     * Log a critical message
     */
    protected function logCritical(string $message, array $context = []): void
    {
        $this->logger()->critical($this->getLogCategory(), $message, $this->enrichContext($context));
    }

    /**
     * Log an exception with full context
     */
    protected function logException(\Throwable $exception, array $context = []): void
    {
        $this->logger()->exception($this->getLogCategory(), $exception, $this->enrichContext($context));
    }

    /**
     * Log a successful operation (convenience method)
     */
    protected function logSuccess(string $operation, array $context = []): void
    {
        $this->logInfo("{$operation} completed successfully", array_merge($context, [
            'status' => 'success',
        ]));
    }

    /**
     * Log a failed operation (convenience method)
     */
    protected function logFailure(string $operation, string $reason, array $context = []): void
    {
        $this->logWarning("{$operation} failed: {$reason}", array_merge($context, [
            'status' => 'failure',
            'failure_reason' => $reason,
        ]));
    }

    /**
     * Enrich context with service-specific data
     */
    protected function enrichContext(array $context): array
    {
        return array_merge([
            'service' => class_basename(static::class),
        ], $context);
    }

    /**
     * Start a manual span (when not using withServiceSpan)
     * 
     * Remember to call endSpan() when done!
     */
    protected function startSpan(string $operation): string
    {
        return LogContext::pushSpan(class_basename(static::class) . '.' . $operation);
    }

    /**
     * End the current span and log completion
     */
    protected function endSpan(): ?array
    {
        return LogContext::popSpan();
    }
}
