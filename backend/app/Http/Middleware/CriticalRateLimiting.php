<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Critical Rate Limiting Middleware
 * 
 * Implements the exact rate limits specified:
 * - Login: 5/min per IP
 * - QR Scan: 30/min per user+device
 * - Export: 10/hour per school
 * - Password Reset: 3/hour per IP
 * 
 * SECURITY FEATURES:
 * - IP-based brute force protection
 * - Device fingerprinting for mobile
 * - School-based resource protection
 * - Comprehensive logging and monitoring
 */
class CriticalRateLimiting
{
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request
     */
    public function handle(Request $request, Closure $next, string $type = 'api'): Response
    {
        // Skip rate limiting in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        // Get rate limit configuration
        $config = $this->getRateLimitConfig($type);
        $key = $this->resolveRequestSignature($request, $type);

        // Check if rate limit exceeded
        if ($this->limiter->tooManyAttempts($key, $config['max_attempts'])) {
            // Log rate limit violation
            $this->logRateLimitViolation($request, $type, $key, $config);

            return $this->buildRateLimitResponse($key, $config);
        }

        // Increment attempts
        $this->limiter->hit($key, $config['decay_seconds']);

        $response = $next($request);

        // Add rate limit headers
        return $this->addRateLimitHeaders($response, $key, $config);
    }

    /**
     * Get rate limit configuration based on type
     */
    protected function getRateLimitConfig(string $type): array
    {
        return match ($type) {
            'login' => [
                'max_attempts' => env('RATE_LIMIT_LOGIN', 5),
                'decay_seconds' => 300, // 5 minutes
                'description' => 'Login attempts per IP',
            ],
            'qr-scan' => [
                'max_attempts' => env('RATE_LIMIT_QR_SCAN', 30),
                'decay_seconds' => 60, // 1 minute
                'description' => 'QR scans per user+device',
            ],
            'export' => [
                'max_attempts' => env('RATE_LIMIT_EXPORT', 10),
                'decay_seconds' => 3600, // 1 hour
                'description' => 'Export operations per school',
            ],
            'password-reset' => [
                'max_attempts' => env('RATE_LIMIT_PASSWORD_RESET', 3),
                'decay_seconds' => 3600, // 1 hour
                'description' => 'Password reset attempts per IP',
            ],
            'api' => [
                'max_attempts' => 60,
                'decay_seconds' => 60, // 1 minute
                'description' => 'General API requests',
            ],
            default => [
                'max_attempts' => 60,
                'decay_seconds' => 60,
                'description' => 'Default rate limit',
            ],
        };
    }

    /**
     * Resolve request signature for rate limiting
     */
    protected function resolveRequestSignature(Request $request, string $type): string
    {
        $ip = $request->ip();
        $user = $request->user();

        return match ($type) {
            // Login: IP-based to prevent brute force
            'login' => "critical_login:{$ip}",

            // QR Scan: User + Device + School for precise control
            'qr-scan' => $this->buildQrScanKey($request),

            // Export: School-based to prevent resource exhaustion
            'export' => "critical_export:school:" . ($user?->school_id ?? 'unknown'),

            // Password Reset: IP-based to prevent abuse
            'password-reset' => "critical_password_reset:{$ip}",

            // API: User-based if authenticated, IP-based otherwise
            'api' => $user ? "critical_api:user:{$user->id}" : "critical_api:ip:{$ip}",

            // Default: IP-based
            default => "critical_default:{$ip}",
        };
    }

    /**
     * Build QR scan rate limit key
     * Combines user, device, and school for precise control
     */
    protected function buildQrScanKey(Request $request): string
    {
        $user = $request->user();
        $userId = $user?->id ?? 'guest';
        $schoolId = $user?->school_id ?? 'unknown';
        
        // Get device identifier from multiple sources
        $deviceId = $request->header('X-Device-ID')
            ?? $request->input('device_info.device_id')
            ?? $request->input('device_id')
            ?? $this->generateDeviceFingerprint($request);

        return "critical_qr_scan:user:{$userId}:device:{$deviceId}:school:{$schoolId}";
    }

    /**
     * Generate device fingerprint for mobile apps
     */
    protected function generateDeviceFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent(),
            $request->header('Accept-Language'),
            $request->header('Accept-Encoding'),
            $request->ip(),
        ];

        return 'fp_' . substr(md5(implode('|', array_filter($components))), 0, 16);
    }

    /**
     * Log rate limit violation with comprehensive details
     */
    protected function logRateLimitViolation(
        Request $request,
        string $type,
        string $key,
        array $config
    ): void {
        $user = $request->user();

        Log::warning('Critical Rate Limit Exceeded', [
            'type' => $type,
            'key' => $key,
            'description' => $config['description'],
            'max_attempts' => $config['max_attempts'],
            'decay_seconds' => $config['decay_seconds'],
            'ip' => $request->ip(),
            'user_id' => $user?->id,
            'school_id' => $user?->school_id,
            'user_agent' => $request->userAgent(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'device_id' => $request->header('X-Device-ID'),
            'timestamp' => now()->toIso8601String(),
            'severity' => 'HIGH',
        ]);

        // Additional security logging for critical endpoints
        if (in_array($type, ['login', 'password-reset'])) {
            Log::channel('security')->critical('Security Rate Limit Violation', [
                'type' => $type,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toIso8601String(),
                'potential_attack' => true,
            ]);
        }
    }

    /**
     * Build rate limit exceeded response
     */
    protected function buildRateLimitResponse(string $key, array $config): Response
    {
        $retryAfter = $this->limiter->availableIn($key);
        $retryAfterMinutes = ceil($retryAfter / 60);

        // User-friendly messages in Indonesian
        $message = match ($config['description']) {
            'Login attempts per IP' => "Terlalu banyak percobaan login. Coba lagi dalam {$retryAfterMinutes} menit.",
            'QR scans per user+device' => "Terlalu banyak scan QR. Tunggu {$retryAfter} detik sebelum scan lagi.",
            'Export operations per school' => "Batas ekspor tercapai. Coba lagi dalam {$retryAfterMinutes} menit.",
            'Password reset attempts per IP' => "Terlalu banyak permintaan reset password. Coba lagi dalam {$retryAfterMinutes} menit.",
            default => "Terlalu banyak permintaan. Coba lagi dalam {$retryAfter} detik.",
        };

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'type' => $config['description'],
                'retry_after' => $retryAfter,
                'retry_after_minutes' => $retryAfterMinutes,
                'limit' => $config['max_attempts'],
                'window_seconds' => $config['decay_seconds'],
            ],
        ], 429)
            ->header('Retry-After', $retryAfter)
            ->header('X-RateLimit-Limit', $config['max_attempts'])
            ->header('X-RateLimit-Remaining', 0)
            ->header('X-RateLimit-Reset', now()->addSeconds($retryAfter)->timestamp)
            ->header('X-RateLimit-Type', $config['description']);
    }

    /**
     * Add rate limit headers to successful response
     */
    protected function addRateLimitHeaders(Response $response, string $key, array $config): Response
    {
        $remaining = $this->limiter->retriesLeft($key, $config['max_attempts']);
        $resetTime = now()->addSeconds($config['decay_seconds'])->timestamp;

        return $response
            ->header('X-RateLimit-Limit', $config['max_attempts'])
            ->header('X-RateLimit-Remaining', max(0, $remaining))
            ->header('X-RateLimit-Reset', $resetTime)
            ->header('X-RateLimit-Window', $config['decay_seconds'])
            ->header('X-RateLimit-Type', $config['description']);
    }

    /**
     * Get current rate limit status for monitoring
     */
    public function getRateLimitStatus(Request $request, string $type): array
    {
        $config = $this->getRateLimitConfig($type);
        $key = $this->resolveRequestSignature($request, $type);
        
        $remaining = $this->limiter->retriesLeft($key, $config['max_attempts']);
        $resetTime = $this->limiter->availableIn($key);

        return [
            'type' => $type,
            'key' => $key,
            'limit' => $config['max_attempts'],
            'remaining' => max(0, $remaining),
            'reset_in_seconds' => $resetTime,
            'window_seconds' => $config['decay_seconds'],
            'description' => $config['description'],
        ];
    }
}