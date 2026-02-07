<?php

namespace App\Logging;

/**
 * Log Context Singleton
 * 
 * Stores request-scoped context that is automatically included in all log entries.
 * This provides consistent context across all logs within a single request.
 */
class LogContext
{
    /**
     * Context data storage
     */
    protected static array $context = [];

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
     * Initialize default context from request
     */
    public static function initFromRequest(\Illuminate\Http\Request $request): void
    {
        static::setMany([
            'request_id' => $request->header('X-Request-ID') ?? static::generateRequestId(),
            'trace_id' => $request->header('X-Trace-ID') ?? static::get('request_id'),
            'method' => $request->method(),
            'endpoint' => static::normalizeEndpoint($request->path()),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('Referer'),
        ]);
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
     * Generate unique request ID
     */
    protected static function generateRequestId(): string
    {
        return sprintf(
            '%s-%s',
            substr(bin2hex(random_bytes(8)), 0, 16),
            substr(microtime(true) * 1000, -4)
        );
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
