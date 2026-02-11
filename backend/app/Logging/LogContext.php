<?php

namespace App\Logging;

use Illuminate\Support\Str;

/**
 * Log Context Singleton
 * 
 * Stores request-scoped context that is automatically included in all log entries.
 * This provides consistent context across all logs within a single request.
 * 
 * Correlation Hierarchy:
 * - request_id: Unique per HTTP request (idempotency key)
 * - trace_id: End-to-end trace across services (W3C traceparent compatible)
 * - span_id: Current operation span (changes per service/layer)
 * - parent_span_id: Parent span for call hierarchy
 * 
 * Usage for correlation:
 * - API Layer: initFromRequest() sets request_id + trace_id + root span_id
 * - Service Layer: pushSpan('attendance_check') to start nested span
 * - DB Layer: Auto-correlated via ObservabilityServiceProvider
 * - popSpan() when service method completes
 */
class LogContext
{
    /**
     * Context data storage
     */
    protected static array $context = [];

    /**
     * Span stack for nested operations (Service → Repository → Query)
     */
    protected static array $spanStack = [];

    /**
     * Set a context value
     */
    public static function set(string $key, mixed $value): void
    {
        static::$context[$key] = $value;
    }

    /**
     * Get a context value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::$context[$key] ?? $default;
    }

    /**
     * Set multiple context values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::$context[$key] = $value;
        }
    }

    /**
     * Get all context
     */
    public static function all(): array
    {
        return static::$context;
    }

    /**
     * Clear all context (call at end of request)
     */
    public static function clear(): void
    {
        static::$context = [];
        static::$spanStack = [];
    }

    /**
     * Check if context has a key
     */
    public static function has(string $key): bool
    {
        return isset(static::$context[$key]);
    }

    /**
     * Remove a specific key
     */
    public static function forget(string $key): void
    {
        unset(static::$context[$key]);
    }

    /**
     * Push a new span onto the stack (entering a new service/layer)
     * 
     * @param string $operation Name of the operation (e.g., 'AttendanceService.recordScan')
     * @return string The new span_id
     */
    public static function pushSpan(string $operation): string
    {
        $currentSpanId = static::get('span_id');
        $newSpanId = static::generateSpanId();

        // Push current span to stack before replacing
        if ($currentSpanId) {
            static::$spanStack[] = [
                'span_id' => $currentSpanId,
                'operation' => static::get('current_operation'),
                'started_at' => static::get('span_started_at'),
            ];
        }

        static::setMany([
            'span_id' => $newSpanId,
            'parent_span_id' => $currentSpanId,
            'current_operation' => $operation,
            'span_started_at' => microtime(true),
            'span_depth' => count(static::$spanStack) + 1,
        ]);

        return $newSpanId;
    }

    /**
     * Pop current span and restore parent span
     * 
     * @return array|null Span info with duration, or null if stack empty
     */
    public static function popSpan(): ?array
    {
        $completedSpan = [
            'span_id' => static::get('span_id'),
            'operation' => static::get('current_operation'),
            'duration_ms' => static::getSpanDuration(),
            'parent_span_id' => static::get('parent_span_id'),
        ];

        // Restore parent span from stack
        if (!empty(static::$spanStack)) {
            $parent = array_pop(static::$spanStack);
            static::setMany([
                'span_id' => $parent['span_id'],
                'current_operation' => $parent['operation'],
                'span_started_at' => $parent['started_at'],
                'parent_span_id' => null, // Parent of parent would need deeper stack
                'span_depth' => count(static::$spanStack) + 1,
            ]);
        } else {
            // Root span - clear span context
            static::forget('span_id');
            static::forget('parent_span_id');
            static::forget('current_operation');
            static::forget('span_started_at');
            static::set('span_depth', 0);
        }

        return $completedSpan;
    }

    /**
     * Get current span duration in milliseconds
     */
    public static function getSpanDuration(): float
    {
        $startedAt = static::get('span_started_at');
        if (!$startedAt) {
            return 0.0;
        }
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    /**
     * Get correlation context for logging (optimized subset)
     */
    public static function getCorrelationContext(): array
    {
        return [
            'request_id' => static::get('request_id'),
            'trace_id' => static::get('trace_id'),
            'span_id' => static::get('span_id'),
            'parent_span_id' => static::get('parent_span_id'),
            'operation' => static::get('current_operation'),
            'user_id' => static::get('user_id'),
            'school_id' => static::get('school_id'),
        ];
    }

    /**
     * Initialize default context from request
     */
    public static function initFromRequest(\Illuminate\Http\Request $request): void
    {
        $requestId = $request->header('X-Request-ID') ?? static::generateRequestId();
        $traceId = $request->header('X-Trace-ID') 
            ?? static::parseTraceParent($request->header('traceparent'))
            ?? $requestId;

        static::setMany([
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'span_id' => static::generateSpanId(), // Root span for this request
            'parent_span_id' => null,
            'current_operation' => 'http.' . strtolower($request->method()),
            'span_started_at' => microtime(true),
            'span_depth' => 1,
            'method' => $request->method(),
            'endpoint' => static::normalizeEndpoint($request->path()),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('Referer'),
            'request_date' => now()->toDateString(), // For per-date filtering
        ]);
    }

    /**
     * Parse W3C traceparent header to extract trace_id
     */
    protected static function parseTraceParent(?string $header): ?string
    {
        if (!$header) {
            return null;
        }
        // Format: version-trace_id-parent_id-flags (e.g., 00-xxx-yyy-01)
        $parts = explode('-', $header);
        return $parts[1] ?? null;
    }

    /**
     * Set user context after authentication
     */
    public static function setUser(?object $user): void
    {
        if ($user) {
            static::setMany([
                'user_id' => $user->id ?? null,
                'school_id' => $user->school_id ?? null,
                'role' => $user->role_type ?? $user->getRoleNames()->first() ?? null,
                'email' => $user->email ?? null,
            ]);
        }
    }

    /**
     * Set response context
     */
    public static function setResponse(int $statusCode, float $responseTimeMs): void
    {
        static::setMany([
            'status_code' => $statusCode,
            'response_time' => round($responseTimeMs, 2),
        ]);
    }

    /**
     * Generate unique request ID (16 hex chars + 4 timestamp digits)
     */
    public static function generateRequestId(): string
    {
        return sprintf(
            '%s-%s',
            substr(bin2hex(random_bytes(8)), 0, 16),
            substr((string)(microtime(true) * 1000), -4)
        );
    }

    /**
     * Generate span ID (16 hex chars for W3C compatibility)
     */
    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Normalize endpoint to avoid cardinality explosion
     */
    protected static function normalizeEndpoint(string $path): string
    {
        // Replace UUIDs
        $path = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{uuid}',
            $path
        );

        // Replace numeric IDs
        $path = preg_replace('/\/\d+/', '/{id}', $path);

        return '/' . ltrim($path, '/');
    }
}
