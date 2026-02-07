<?php

namespace App\Services;

use App\Logging\LogContext;
use Illuminate\Support\Facades\Log;

/**
 * Structured Event Logger Service
 * 
 * Provides typed logging methods for different event categories:
 * - Attendance events (scan success/failure)
 * - Security events (invalid QR, suspicious activity)
 * - Auth events (login success/failure)
 * - System errors (exceptions)
 */
class EventLoggerService
{
    /*
    |--------------------------------------------------------------------------
    | Attendance Events
    |--------------------------------------------------------------------------
    */

    /**
     * Log successful attendance scan
     */
    public function attendanceSuccess(
        int $attendanceId,
        int $studentId,
        int $scheduleId,
        string $status,
        array $extra = []
    ): void {
        Log::channel('attendance')->info('Attendance scan successful', array_merge([
            'event_type' => 'attendance.scan.success',
            'attendance_id' => $attendanceId,
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'status' => $status, // present, late, excused
            'scanned_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log failed attendance scan
     */
    public function attendanceFailure(
        string $reason,
        ?int $studentId = null,
        ?int $scheduleId = null,
        array $extra = []
    ): void {
        Log::channel('attendance')->warning('Attendance scan failed', array_merge([
            'event_type' => 'attendance.scan.failure',
            'reason' => $reason,
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'attempted_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log QR code generation
     */
    public function qrGenerated(
        int $scheduleId,
        string $type,
        int $validitySeconds,
        array $extra = []
    ): void {
        Log::channel('attendance')->info('QR code generated', array_merge([
            'event_type' => 'attendance.qr.generated',
            'schedule_id' => $scheduleId,
            'type' => $type, // in, out
            'validity_seconds' => $validitySeconds,
            'generated_at' => now()->toISOString(),
        ], $extra));
    }

    /*
    |--------------------------------------------------------------------------
    | Security Events
    |--------------------------------------------------------------------------
    */

    /**
     * Log invalid QR code attempt
     */
    public function invalidQr(
        string $reason,
        ?string $qrToken = null,
        array $extra = []
    ): void {
        Log::channel('security')->warning('Invalid QR code attempt', array_merge([
            'event_type' => 'security.qr.invalid',
            'reason' => $reason, // expired, tampered, invalid_signature, reused
            'qr_token_hash' => $qrToken ? hash('sha256', $qrToken) : null,
            'ip' => LogContext::get('ip'),
            'user_agent' => LogContext::get('user_agent'),
            'attempted_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log suspicious activity
     */
    public function suspiciousActivity(
        string $type,
        string $description,
        string $severity = 'medium',
        array $extra = []
    ): void {
        $level = match ($severity) {
            'critical' => 'critical',
            'high' => 'error',
            'medium' => 'warning',
            default => 'notice',
        };

        Log::channel('security')->log($level, 'Suspicious activity detected', array_merge([
            'event_type' => 'security.suspicious',
            'activity_type' => $type,
            'description' => $description,
            'severity' => $severity,
            'user_id' => LogContext::get('user_id'),
            'school_id' => LogContext::get('school_id'),
            'ip' => LogContext::get('ip'),
            'detected_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log rate limit exceeded
     */
    public function rateLimitExceeded(
        string $limiter,
        int $maxAttempts,
        array $extra = []
    ): void {
        Log::channel('security')->warning('Rate limit exceeded', array_merge([
            'event_type' => 'security.rate_limit',
            'limiter' => $limiter,
            'max_attempts' => $maxAttempts,
            'ip' => LogContext::get('ip'),
            'user_id' => LogContext::get('user_id'),
            'endpoint' => LogContext::get('endpoint'),
            'exceeded_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log unauthorized access attempt
     */
    public function unauthorizedAccess(
        string $resource,
        string $action,
        array $extra = []
    ): void {
        Log::channel('security')->warning('Unauthorized access attempt', array_merge([
            'event_type' => 'security.unauthorized',
            'resource' => $resource,
            'action' => $action,
            'user_id' => LogContext::get('user_id'),
            'role' => LogContext::get('role'),
            'ip' => LogContext::get('ip'),
            'attempted_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log device binding event
     */
    public function deviceBinding(
        string $action,
        int $userId,
        string $deviceId,
        array $extra = []
    ): void {
        Log::channel('security')->info('Device binding event', array_merge([
            'event_type' => 'security.device_binding',
            'action' => $action, // bound, unbound, rejected
            'user_id' => $userId,
            'device_id_hash' => hash('sha256', $deviceId),
            'ip' => LogContext::get('ip'),
            'timestamp' => now()->toISOString(),
        ], $extra));
    }

    /*
    |--------------------------------------------------------------------------
    | Auth Events
    |--------------------------------------------------------------------------
    */

    /**
     * Log successful login
     */
    public function loginSuccess(
        int $userId,
        string $method = 'password',
        array $extra = []
    ): void {
        Log::channel('auth')->info('Login successful', array_merge([
            'event_type' => 'auth.login.success',
            'user_id' => $userId,
            'method' => $method, // password, token, oauth
            'ip' => LogContext::get('ip'),
            'user_agent' => LogContext::get('user_agent'),
            'logged_in_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log failed login
     */
    public function loginFailure(
        string $identifier,
        string $reason,
        array $extra = []
    ): void {
        Log::channel('auth')->warning('Login failed', array_merge([
            'event_type' => 'auth.login.failure',
            'identifier_hash' => hash('sha256', $identifier), // Don't log actual email/username
            'reason' => $reason, // invalid_credentials, account_locked, inactive
            'ip' => LogContext::get('ip'),
            'user_agent' => LogContext::get('user_agent'),
            'attempted_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log logout
     */
    public function logout(
        int $userId,
        string $reason = 'user_initiated',
        array $extra = []
    ): void {
        Log::channel('auth')->info('Logout', array_merge([
            'event_type' => 'auth.logout',
            'user_id' => $userId,
            'reason' => $reason, // user_initiated, token_expired, forced
            'logged_out_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log password change
     */
    public function passwordChanged(
        int $userId,
        bool $forced = false,
        array $extra = []
    ): void {
        Log::channel('auth')->info('Password changed', array_merge([
            'event_type' => 'auth.password.changed',
            'user_id' => $userId,
            'forced' => $forced,
            'ip' => LogContext::get('ip'),
            'changed_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log token refresh
     */
    public function tokenRefreshed(
        int $userId,
        array $extra = []
    ): void {
        Log::channel('auth')->debug('Token refreshed', array_merge([
            'event_type' => 'auth.token.refreshed',
            'user_id' => $userId,
            'ip' => LogContext::get('ip'),
            'refreshed_at' => now()->toISOString(),
        ], $extra));
    }

    /*
    |--------------------------------------------------------------------------
    | System Events
    |--------------------------------------------------------------------------
    */

    /**
     * Log exception/error
     */
    public function systemError(
        \Throwable $exception,
        string $context = 'general',
        array $extra = []
    ): void {
        Log::channel('system')->error('System error', array_merge([
            'event_type' => 'system.error',
            'context' => $context,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'trace' => array_slice($exception->getTrace(), 0, 10), // Limit trace
            'request_id' => LogContext::get('request_id'),
            'endpoint' => LogContext::get('endpoint'),
            'occurred_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log job failure
     */
    public function jobFailed(
        string $jobClass,
        string $queue,
        \Throwable $exception,
        array $extra = []
    ): void {
        Log::channel('system')->error('Job failed', array_merge([
            'event_type' => 'system.job.failed',
            'job_class' => $jobClass,
            'queue' => $queue,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'failed_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log database query slow
     */
    public function slowQuery(
        string $sql,
        float $timeMs,
        array $extra = []
    ): void {
        Log::channel('system')->warning('Slow database query', array_merge([
            'event_type' => 'system.db.slow_query',
            'query_hash' => hash('sha256', $sql), // Don't log full query
            'time_ms' => $timeMs,
            'endpoint' => LogContext::get('endpoint'),
            'detected_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log cache miss
     */
    public function cacheMiss(
        string $key,
        string $store = 'default',
        array $extra = []
    ): void {
        Log::channel('system')->debug('Cache miss', array_merge([
            'event_type' => 'system.cache.miss',
            'key_pattern' => $this->sanitizeCacheKey($key),
            'store' => $store,
            'endpoint' => LogContext::get('endpoint'),
            'occurred_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log external service call
     */
    public function externalServiceCall(
        string $service,
        string $endpoint,
        int $statusCode,
        float $responseTimeMs,
        array $extra = []
    ): void {
        $level = $statusCode >= 400 ? 'warning' : 'info';

        Log::channel('system')->log($level, 'External service call', array_merge([
            'event_type' => 'system.external.call',
            'service' => $service,
            'external_endpoint' => $endpoint,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'called_at' => now()->toISOString(),
        ], $extra));
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Events
    |--------------------------------------------------------------------------
    */

    /**
     * Log admin action
     */
    public function adminAction(
        string $action,
        string $resource,
        ?int $resourceId = null,
        array $changes = [],
        array $extra = []
    ): void {
        Log::channel('superadmin')->info('Admin action', array_merge([
            'event_type' => 'admin.action',
            'action' => $action, // create, update, delete, export
            'resource' => $resource,
            'resource_id' => $resourceId,
            'changes' => $this->sanitizeChanges($changes),
            'admin_id' => LogContext::get('user_id'),
            'admin_role' => LogContext::get('role'),
            'ip' => LogContext::get('ip'),
            'performed_at' => now()->toISOString(),
        ], $extra));
    }

    /**
     * Log data export
     */
    public function dataExport(
        string $exportType,
        int $recordCount,
        string $format,
        array $extra = []
    ): void {
        Log::channel('superadmin')->info('Data export', array_merge([
            'event_type' => 'admin.export',
            'export_type' => $exportType,
            'record_count' => $recordCount,
            'format' => $format,
            'exported_by' => LogContext::get('user_id'),
            'school_id' => LogContext::get('school_id'),
            'exported_at' => now()->toISOString(),
        ], $extra));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Sanitize cache key for logging (remove sensitive data)
     */
    protected function sanitizeCacheKey(string $key): string
    {
        // Replace IDs with placeholders
        return preg_replace('/:\d+/', ':{id}', $key);
    }

    /**
     * Sanitize changes array (remove sensitive fields)
     */
    protected function sanitizeChanges(array $changes): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'api_key'];

        foreach ($changes as $field => $value) {
            if (in_array(strtolower($field), $sensitiveFields)) {
                $changes[$field] = '[REDACTED]';
            }
        }

        return $changes;
    }
}
