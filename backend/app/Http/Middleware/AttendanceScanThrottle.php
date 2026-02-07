<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attendance Scan Rate Limiter
 *
 * Specialized rate limiting for attendance scan endpoint with:
 * - Max 5 requests per 10 seconds per device/user
 * - Temporary IP blocking after repeated violations
 * - Device-based tracking for mobile apps
 * - School-scoped limits to prevent abuse
 *
 * SECURITY LAYERS:
 * 1. Per-user/device limit (5/10s)
 * 2. Per-IP violation tracking
 * 3. IP blocking after 3 violations
 *
 * USAGE:
 * Route::post('/scan', ...)->middleware('attendance.throttle');
 */
class AttendanceScanThrottle
{
    protected RateLimiter $limiter;

    /**
     * Maximum requests per window
     */
    protected const MAX_REQUESTS = 5;

    /**
     * Time window in seconds
     */
    protected const WINDOW_SECONDS = 10;

    /**
     * Number of violations before IP block
     */
    protected const VIOLATIONS_BEFORE_BLOCK = 3;

    /**
     * IP block duration in minutes
     */
    protected const BLOCK_DURATION_MINUTES = 15;

    /**
     * Violation counter reset time in minutes
     */
    protected const VIOLATION_RESET_MINUTES = 30;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip in testing
        if (app()->environment('testing')) {
            return $next($request);
        }

        $ip = $request->ip();

        // Step 1: Check if IP is blocked
        if ($this->isIpBlocked($ip)) {
            return $this->buildBlockedResponse($ip);
        }

        // Step 2: Build rate limit key
        $key = $this->buildRateLimitKey($request);

        // Step 3: Check rate limit
        if ($this->limiter->tooManyAttempts($key, self::MAX_REQUESTS)) {
            // Record violation
            $this->recordViolation($request);

            // Log the violation
            $this->logRateLimitExceeded($request, $key);

            return $this->buildRateLimitResponse($key);
        }

        // Step 4: Increment counter
        $this->limiter->hit($key, self::WINDOW_SECONDS);

        // Step 5: Process request
        $response = $next($request);

        // Step 6: Add rate limit headers
        return $this->addRateLimitHeaders($response, $key);
    }

    /**
     * Build unique rate limit key based on user + device
     */
    protected function buildRateLimitKey(Request $request): string
    {
        $userId = $request->user()?->id ?? 'guest';
        $deviceId = $this->resolveDeviceId($request);
        $ip = $request->ip();

        // Primary key: user + device
        // Secondary: include IP to catch device spoofing
        return "attn_scan:{$userId}:{$deviceId}:{$ip}";
    }

    /**
     * Resolve device ID from request
     */
    protected function resolveDeviceId(Request $request): string
    {
        // Priority: Header > Body > Fingerprint
        return $request->header('X-Device-ID')
            ?? $request->input('device_info.device_id')
            ?? $request->input('device_id')
            ?? $this->generateFingerprint($request);
    }

    /**
     * Generate device fingerprint from request characteristics
     */
    protected function generateFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent(),
            $request->header('Accept-Language'),
            $request->header('Accept-Encoding'),
        ];

        return 'fp_'.substr(md5(implode('|', $components)), 0, 16);
    }

    /**
     * Check if IP is currently blocked
     */
    protected function isIpBlocked(string $ip): bool
    {
        return Cache::has($this->getBlockKey($ip));
    }

    /**
     * Get block cache key for IP
     */
    protected function getBlockKey(string $ip): string
    {
        return "attn_scan_blocked:{$ip}";
    }

    /**
     * Get violation counter key for IP
     */
    protected function getViolationKey(string $ip): string
    {
        return "attn_scan_violations:{$ip}";
    }

    /**
     * Record a rate limit violation
     */
    protected function recordViolation(Request $request): void
    {
        $ip = $request->ip();
        $violationKey = $this->getViolationKey($ip);

        // Get current violation count
        $violations = Cache::get($violationKey, 0) + 1;

        // Store updated count
        Cache::put(
            $violationKey,
            $violations,
            now()->addMinutes(self::VIOLATION_RESET_MINUTES)
        );

        // Check if should block IP
        if ($violations >= self::VIOLATIONS_BEFORE_BLOCK) {
            $this->blockIp($ip, $request);
        }
    }

    /**
     * Block an IP address temporarily
     */
    protected function blockIp(string $ip, Request $request): void
    {
        Cache::put(
            $this->getBlockKey($ip),
            [
                'blocked_at' => now()->toIso8601String(),
                'user_id' => $request->user()?->id,
                'reason' => 'Repeated rate limit violations on attendance scan',
            ],
            now()->addMinutes(self::BLOCK_DURATION_MINUTES)
        );

        // Log IP block
        Log::warning('IP Blocked for Attendance Scan Abuse', [
            'ip' => $ip,
            'user_id' => $request->user()?->id,
            'school_id' => $request->user()?->school_id,
            'device_id' => $this->resolveDeviceId($request),
            'user_agent' => $request->userAgent(),
            'duration_minutes' => self::BLOCK_DURATION_MINUTES,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Clear violation counter (will reset after block expires)
        Cache::forget($this->getViolationKey($ip));
    }

    /**
     * Log rate limit exceeded event
     */
    protected function logRateLimitExceeded(Request $request, string $key): void
    {
        $ip = $request->ip();
        $violations = Cache::get($this->getViolationKey($ip), 0);

        Log::warning('Attendance Scan Rate Limit Exceeded', [
            'key' => $key,
            'ip' => $ip,
            'user_id' => $request->user()?->id,
            'school_id' => $request->user()?->school_id,
            'device_id' => $this->resolveDeviceId($request),
            'user_agent' => $request->userAgent(),
            'violation_count' => $violations,
            'remaining_before_block' => self::VIOLATIONS_BEFORE_BLOCK - $violations,
            'endpoint' => $request->path(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build rate limit exceeded response
     */
    protected function buildRateLimitResponse(string $key): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return response()->json([
            'success' => false,
            'message' => 'Terlalu banyak permintaan scan. Tunggu beberapa detik.',
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'type' => 'attendance_scan',
                'retry_after' => $retryAfter,
                'limit' => self::MAX_REQUESTS,
                'window_seconds' => self::WINDOW_SECONDS,
            ],
        ], 429)
            ->header('Retry-After', $retryAfter)
            ->header('X-RateLimit-Limit', self::MAX_REQUESTS)
            ->header('X-RateLimit-Remaining', 0)
            ->header('X-RateLimit-Reset', now()->addSeconds($retryAfter)->timestamp);
    }

    /**
     * Build blocked IP response
     */
    protected function buildBlockedResponse(string $ip): Response
    {
        $blockData = Cache::get($this->getBlockKey($ip));
        $blockedAt = $blockData['blocked_at'] ?? now()->toIso8601String();
        $blockedTime = now()->parse($blockedAt);
        $unblockTime = $blockedTime->addMinutes(self::BLOCK_DURATION_MINUTES);
        $retryAfter = max(0, now()->diffInSeconds($unblockTime));

        return response()->json([
            'success' => false,
            'message' => 'IP Anda diblokir sementara karena aktivitas mencurigakan.',
            'error' => [
                'code' => 'IP_BLOCKED',
                'type' => 'temporary_block',
                'retry_after' => $retryAfter,
                'blocked_until' => $unblockTime->toIso8601String(),
            ],
        ], 429)
            ->header('Retry-After', $retryAfter)
            ->header('X-Block-Reason', 'rate_limit_violations');
    }

    /**
     * Add rate limit headers to successful response
     */
    protected function addRateLimitHeaders(Response $response, string $key): Response
    {
        $remaining = $this->limiter->retriesLeft($key, self::MAX_REQUESTS);
        $resetTime = now()->addSeconds(self::WINDOW_SECONDS)->timestamp;

        return $response
            ->header('X-RateLimit-Limit', self::MAX_REQUESTS)
            ->header('X-RateLimit-Remaining', max(0, $remaining))
            ->header('X-RateLimit-Reset', $resetTime)
            ->header('X-RateLimit-Window', self::WINDOW_SECONDS);
    }

    /**
     * Get current status for an IP (for admin monitoring)
     */
    public static function getIpStatus(string $ip): array
    {
        $blockKey = "attn_scan_blocked:{$ip}";
        $violationKey = "attn_scan_violations:{$ip}";

        return [
            'ip' => $ip,
            'is_blocked' => Cache::has($blockKey),
            'block_data' => Cache::get($blockKey),
            'violation_count' => Cache::get($violationKey, 0),
            'remaining_before_block' => self::VIOLATIONS_BEFORE_BLOCK - Cache::get($violationKey, 0),
        ];
    }

    /**
     * Manually unblock an IP (for admin use)
     */
    public static function unblockIp(string $ip): bool
    {
        $blockKey = "attn_scan_blocked:{$ip}";
        $violationKey = "attn_scan_violations:{$ip}";

        Cache::forget($blockKey);
        Cache::forget($violationKey);

        Log::info('IP Manually Unblocked', ['ip' => $ip]);

        return true;
    }
}
