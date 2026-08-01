<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AttendanceRateLimitMiddleware
 *
 * Specialized rate limiting for attendance endpoints to prevent abuse.
 *
 * FEATURES:
 * - Per-user rate limiting
 * - Per-device rate limiting
 * - Per-IP rate limiting (fallback)
 * - Configurable limits per endpoint
 * - Automatic security alerts on limit exceeded
 *
 * RATE LIMIT TIERS:
 * - QR Scan: 10 requests per minute per user
 * - Manual Entry: 30 requests per minute per user
 * - Report Export: 5 requests per minute per user
 *
 * USAGE:
 * Route::post('/attendance/scan')
 *     ->middleware('attendance.rate.limit:qr-scan');
 *
 * Route::post('/attendance/manual')
 *     ->middleware('attendance.rate.limit:manual-entry');
 *
 * @author Security Team
 * @version 1.0.0
 */
class AttendanceRateLimitMiddleware
{
    /**
     * Rate limit configurations per endpoint type.
     *
     * Format: [max_attempts, decay_minutes]
     */
    private const LIMITS = [
        'qr-scan' => [10, 1],           // 10 requests per minute
        'manual-entry' => [30, 1],      // 30 requests per minute
        'report-export' => [5, 1],      // 5 requests per minute
        'generate-qr' => [20, 1],       // 20 requests per minute
        'default' => [60, 1],           // 60 requests per minute (fallback)
    ];

    /**
     * Handle an incoming request.
     *
     * @param  string  $limitType  Type of rate limit (qr-scan, manual-entry, etc.)
     */
    public function handle(Request $request, Closure $next, string $limitType = 'default'): Response
    {
        $user = $request->user();

        if (!$user) {
            // No user, skip rate limiting (auth middleware should handle this)
            return $next($request);
        }

        // Get limit configuration
        [$maxAttempts, $decayMinutes] = self::LIMITS[$limitType] ?? self::LIMITS['default'];

        // Build rate limit keys (multiple layers)
        $keys = $this->buildRateLimitKeys($request, $user, $limitType);

        // Check each rate limit key
        foreach ($keys as $keyName => $key) {
            $executed = RateLimiter::attempt(
                $key,
                $maxAttempts,
                function () {
                    // Allow the request
                },
                $decayMinutes * 60
            );

            if (!$executed) {
                // Rate limit exceeded
                $this->logRateLimitExceeded($request, $user, $limitType, $keyName);

                return $this->rateLimitResponse($maxAttempts, $decayMinutes);
            }
        }

        // Add rate limit headers to response
        $response = $next($request);

        return $this->addRateLimitHeaders($response, $keys['user'], $maxAttempts, $decayMinutes);
    }

    /**
     * Build multiple rate limit keys for layered protection.
     *
     * @return array<string, string> Key name => Rate limit key
     */
    private function buildRateLimitKeys(Request $request, $user, string $limitType): array
    {
        $keys = [];

        // Layer 1: Per-user rate limit (primary)
        $keys['user'] = "attendance_rate:{$limitType}:user:{$user->id}";

        // Layer 2: Per-device rate limit (if device ID provided)
        $deviceId = $request->header('X-Device-ID');
        if ($deviceId) {
            $keys['device'] = "attendance_rate:{$limitType}:device:{$deviceId}";
        }

        // Layer 3: Per-IP rate limit (fallback for shared devices)
        $keys['ip'] = "attendance_rate:{$limitType}:ip:{$request->ip()}";

        return $keys;
    }

    /**
     * Log rate limit exceeded event.
     */
    private function logRateLimitExceeded(Request $request, $user, string $limitType, string $keyName): void
    {
        Log::channel('attendance_security')->warning('Attendance rate limit exceeded', [
            'user_id' => $user->id,
            'user_role' => $user->role_type,
            'limit_type' => $limitType,
            'limit_key' => $keyName,
            'endpoint' => $request->path(),
            'ip_address' => $request->ip(),
            'device_id' => $request->header('X-Device-ID'),
            'user_agent' => substr($request->userAgent() ?? '', 0, 100),
            'timestamp' => now()->toIso8601String(),
            'severity' => 'MEDIUM',
            'recommendation' => 'Monitor for potential abuse or bot activity',
        ]);

        // Dispatch security alert if threshold is critical
        if ($limitType === 'qr-scan') {
            event(new \App\Events\SecurityAlertTriggered(
                type: 'rate_limit_exceeded',
                severity: 'medium',
                userId: $user->id,
                context: [
                    'limit_type' => $limitType,
                    'endpoint' => $request->path(),
                ]
            ));
        }
    }

    /**
     * Return rate limit exceeded response.
     */
    private function rateLimitResponse(int $maxAttempts, int $decayMinutes): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
            'code' => 'RATE_LIMIT_EXCEEDED',
            'data' => [
                'max_attempts' => $maxAttempts,
                'retry_after_seconds' => $decayMinutes * 60,
            ],
        ], 429)->header('Retry-After', $decayMinutes * 60);
    }

    /**
     * Add rate limit headers to response.
     */
    private function addRateLimitHeaders(
        Response $response,
        string $key,
        int $maxAttempts,
        int $decayMinutes
    ): Response {
        $remaining = RateLimiter::remaining($key, $maxAttempts);
        $resetAt = now()->addMinutes($decayMinutes)->getTimestamp();

        return $response
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', $remaining)
            ->header('X-RateLimit-Reset', $resetAt);
    }
}
