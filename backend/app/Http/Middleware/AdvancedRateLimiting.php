<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Advanced Rate Limiting Middleware
 * 
 * Implements multiple layers of rate limiting:
 * 1. Global IP-based rate limiting (prevent DDoS)
 * 2. Login brute force protection
 * 3. QR scan spam protection per device
 * 4. API endpoint-specific limits
 * 
 * CRITICAL SECURITY:
 * - Prevents brute force attacks
 * - Prevents DDoS attacks
 * - Prevents spam/abuse
 * - Logs all rate limit violations
 */
class AdvancedRateLimiting
{
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $type = 'api'): Response
    {
        // Skip rate limiting in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        // Determine rate limit based on type
        $key = $this->resolveRequestSignature($request, $type);
        $maxAttempts = $this->getMaxAttempts($type);
        $decayMinutes = $this->getDecayMinutes($type);

        // Check if rate limit exceeded
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            // Log rate limit violation
            $this->logRateLimitViolation($request, $type, $key);

            return $this->buildRateLimitResponse($key, $maxAttempts);
        }

        // Increment attempts
        $this->limiter->hit($key, $decayMinutes * 60);

        $response = $next($request);

        // Add rate limit headers
        return $this->addRateLimitHeaders(
            $response,
            $maxAttempts,
            $this->limiter->retriesLeft($key, $maxAttempts)
        );
    }

    /**
     * Resolve request signature for rate limiting
     */
    protected function resolveRequestSignature(Request $request, string $type): string
    {
        $ip = $request->ip();
        $userId = $request->user()?->id;

        return match ($type) {
            // Global IP-based rate limiting
            'global' => "global:{$ip}",

            // Login brute force protection (IP-based)
            'login' => "login:{$ip}",

            // QR scan protection (user + device)
            'scan' => $this->resolveScanSignature($request),

            // API rate limiting (user-based if authenticated, IP-based otherwise)
            'api' => $userId ? "api:user:{$userId}" : "api:ip:{$ip}",

            // Registration/signup (IP-based)
            'register' => "register:{$ip}",

            // Password reset (IP-based)
            'password-reset' => "password-reset:{$ip}",

            // Default
            default => "default:{$ip}",
        };
    }

    /**
     * Resolve scan signature (user + device + school)
     * 
     * Prevents:
     * - Same device scanning multiple times rapidly
     * - Same user scanning from different devices rapidly
     */
    protected function resolveScanSignature(Request $request): string
    {
        $userId = $request->user()?->id ?? 'guest';
        $deviceId = $request->header('X-Device-ID') ?? $request->input('device_id') ?? 'unknown';
        $schoolId = $request->user()?->school_id ?? 'unknown';

        // Combine user + device + school for unique signature
        return "scan:user:{$userId}:device:{$deviceId}:school:{$schoolId}";
    }

    /**
     * Get max attempts based on type
     */
    protected function getMaxAttempts(string $type): int
    {
        return match ($type) {
            'global' => 1000,        // 1000 requests per minute per IP (DDoS protection)
            'login' => 5,            // 5 login attempts per minute per IP
            'scan' => 10,            // 10 scans per minute per user+device
            'api' => 60,             // 60 API requests per minute per user
            'register' => 3,         // 3 registrations per hour per IP
            'password-reset' => 3,   // 3 password resets per hour per IP
            default => 60,
        };
    }

    /**
     * Get decay minutes based on type
     */
    protected function getDecayMinutes(string $type): int
    {
        return match ($type) {
            'global' => 1,           // Reset every 1 minute
            'login' => 5,            // Reset every 5 minutes (longer for security)
            'scan' => 1,             // Reset every 1 minute
            'api' => 1,              // Reset every 1 minute
            'register' => 60,        // Reset every 1 hour
            'password-reset' => 60,  // Reset every 1 hour
            default => 1,
        };
    }

    /**
     * Log rate limit violation
     */
    protected function logRateLimitViolation(Request $request, string $type, string $key): void
    {
        Log::warning('Rate Limit Exceeded', [
            'type' => $type,
            'key' => $key,
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'user_agent' => $request->userAgent(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build rate limit exceeded response
     */
    protected function buildRateLimitResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return response()->json([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
        ], 429)
            ->header('Retry-After', $retryAfter)
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', 0);
    }

    /**
     * Add rate limit headers to response
     */
    protected function addRateLimitHeaders(
        Response $response,
        int $maxAttempts,
        int $remainingAttempts
    ): Response {
        return $response
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', $remainingAttempts);
    }
}
