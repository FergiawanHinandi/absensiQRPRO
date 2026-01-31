<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRITICAL: Rate Limiting by School to prevent abuse
 *
 * FIXES:
 * - School-specific rate limiting
 * - QR scan abuse prevention
 * - API abuse protection
 */
class RateLimitBySchool
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id) {
            return $next($request);
        }

        // CRITICAL: Create school-specific rate limit key
        $key = $this->resolveRequestSignature($request, $user);
        $maxAttempts = $this->resolveMaxAttempts($request, $maxAttempts);
        $decaySeconds = $decayMinutes * 60;

        // ATOMIC OPERATION: Prevent race condition
        // Use increment() which is atomic in Redis/Memcached
        $attempts = Cache::add($key, 0, $decaySeconds)
            ? 1
            : Cache::increment($key);

        // Set expiration on first increment if using array/file cache
        if ($attempts === 1) {
            Cache::put($key, 1, now()->addSeconds($decaySeconds));
        }

        if ($attempts > $maxAttempts) {
            // CRITICAL: Log rate limit violation
            Log::warning('Rate limit exceeded', [
                'user_id' => $user->id,
                'school_id' => $user->school_id,
                'ip' => $request->ip(),
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
            ]);

            return response()->json([
                'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                'retry_after' => $decaySeconds,
            ], 429);
        }

        $response = $next($request);

        // CRITICAL: Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $attempts));
        $response->headers->set('X-RateLimit-Reset', now()->addSeconds($decaySeconds)->timestamp);

        return $response;
    }

    /**
     * Resolve request signature for rate limiting
     */
    protected function resolveRequestSignature(Request $request, $user): string
    {
        $route = $request->route()?->getName() ?? $request->path();

        // CRITICAL: Different limits for different endpoints
        if (str_contains($route, 'attendance.scan') || str_contains($route, 'attendance/scan')) {
            return "attendance_scan:{$user->school_id}:{$user->id}";
        }

        if (str_contains($route, 'qr.generate') || str_contains($route, 'qr/generate')) {
            return "qr_generate:{$user->school_id}:{$user->id}";
        }

        if (str_contains($route, 'report') || str_contains($route, 'export')) {
            return "report_generation:{$user->school_id}";
        }

        return "api_general:{$user->school_id}:{$request->ip()}";
    }

    /**
     * Resolve max attempts based on endpoint
     */
    protected function resolveMaxAttempts(Request $request, int $default): int
    {
        $route = $request->route()?->getName() ?? $request->path();

        // CRITICAL: Stricter limits for sensitive endpoints
        if (str_contains($route, 'attendance.scan') || str_contains($route, 'attendance/scan')) {
            return config('qr.scan_rate_limit.max_attempts', 60);
        }

        if (str_contains($route, 'qr.generate') || str_contains($route, 'qr/generate')) {
            return 10; // Max 10 QR generations per minute
        }

        if (str_contains($route, 'report') || str_contains($route, 'export')) {
            return 20; // Max 20 report generations per minute per school
        }

        if (str_contains($route, 'auth.login') || str_contains($route, 'auth/login')) {
            return 5; // Max 5 login attempts per minute
        }

        return $default;
    }
}
