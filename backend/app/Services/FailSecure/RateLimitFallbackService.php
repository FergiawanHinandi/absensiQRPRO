<?php

namespace App\Services\FailSecure;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * RateLimitFallbackService
 *
 * Provides database-based rate limiting as a fallback when Redis is unavailable.
 * Ensures rate limiting continues to function even during cache/Redis failures.
 *
 * FAIL-SECURE PRINCIPLE:
 * If Redis fails → Use DB-based rate limiting
 * If DB fails → DENY the request (fail closed)
 */
class RateLimitFallbackService
{
    protected FailSecureService $failSecureService;

    /**
     * Whether we're currently using fallback mode
     */
    protected bool $usingFallback = false;

    /**
     * Limiter types and their default limits
     */
    public const LIMITER_LOGIN = 'login';
    public const LIMITER_SCAN = 'scan';
    public const LIMITER_API = 'api';
    public const LIMITER_EXPORT = 'export';

    /**
     * Default limits (requests per minute unless specified)
     */
    protected const DEFAULT_LIMITS = [
        self::LIMITER_LOGIN => 5,      // per 5 minutes
        self::LIMITER_SCAN => 10,      // per minute
        self::LIMITER_API => 60,       // per minute
        self::LIMITER_EXPORT => 5,     // per hour
    ];

    /**
     * Decay periods in minutes
     */
    protected const DECAY_PERIODS = [
        self::LIMITER_LOGIN => 5,
        self::LIMITER_SCAN => 1,
        self::LIMITER_API => 1,
        self::LIMITER_EXPORT => 60,
    ];

    /**
     * Safe limits for degraded mode (stricter)
     */
    protected const SAFE_LIMITS = [
        self::LIMITER_LOGIN => 3,
        self::LIMITER_SCAN => 5,
        self::LIMITER_API => 30,
        self::LIMITER_EXPORT => 2,
    ];

    public function __construct(FailSecureService $failSecureService)
    {
        $this->failSecureService = $failSecureService;
    }

    /**
     * Check if a request should be rate limited
     *
     * @param string $key Unique identifier (user_id, ip, etc.)
     * @param string $limiterType Type of rate limiter
     * @param int|null $maxAttempts Override default max attempts
     * @param int|null $decayMinutes Override default decay period
     * @return array ['limited' => bool, 'remaining' => int, 'retry_after' => int|null]
     */
    public function check(
        string $key,
        string $limiterType = self::LIMITER_API,
        ?int $maxAttempts = null,
        ?int $decayMinutes = null
    ): array {
        // Get effective limits (reduced if system is degraded)
        $maxAttempts = $this->getEffectiveLimit($limiterType, $maxAttempts);
        $decayMinutes = $decayMinutes ?? (self::DECAY_PERIODS[$limiterType] ?? 1);

        // Try Redis first
        try {
            return $this->checkWithRedis($key, $limiterType, $maxAttempts, $decayMinutes);
        } catch (Throwable $e) {
            Log::warning('RateLimitFallback: Redis unavailable, using DB fallback', [
                'key' => $key,
                'limiter_type' => $limiterType,
                'error' => $e->getMessage(),
            ]);

            // Record fallback event
            $this->failSecureService->recordFallbackEvent(
                FailSecureService::EVENT_RATE_LIMIT_FALLBACK,
                FailSecureService::COMPONENT_REDIS,
                'Using database-based rate limiting',
                $e->getMessage(),
                ['key' => $key, 'limiter_type' => $limiterType]
            );

            // Fall back to database
            return $this->checkWithDatabase($key, $limiterType, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * Record a hit (increment counter)
     *
     * @param string $key Unique identifier
     * @param string $limiterType Type of rate limiter
     * @param int|null $maxAttempts Override default max attempts
     * @param int|null $decayMinutes Override default decay period
     * @return array ['limited' => bool, 'remaining' => int, 'retry_after' => int|null]
     */
    public function hit(
        string $key,
        string $limiterType = self::LIMITER_API,
        ?int $maxAttempts = null,
        ?int $decayMinutes = null
    ): array {
        $maxAttempts = $this->getEffectiveLimit($limiterType, $maxAttempts);
        $decayMinutes = $decayMinutes ?? (self::DECAY_PERIODS[$limiterType] ?? 1);

        // Try Redis first
        try {
            return $this->hitWithRedis($key, $limiterType, $maxAttempts, $decayMinutes);
        } catch (Throwable $e) {
            Log::warning('RateLimitFallback: Redis unavailable for hit, using DB fallback', [
                'key' => $key,
                'limiter_type' => $limiterType,
                'error' => $e->getMessage(),
            ]);

            $this->failSecureService->recordFallbackEvent(
                FailSecureService::EVENT_RATE_LIMIT_FALLBACK,
                FailSecureService::COMPONENT_REDIS,
                'Recording rate limit hit in database',
                $e->getMessage(),
                ['key' => $key, 'limiter_type' => $limiterType]
            );

            return $this->hitWithDatabase($key, $limiterType, $maxAttempts, $decayMinutes);
        }
    }

    /**
     * Clear rate limit for a key
     */
    public function clear(string $key, string $limiterType = self::LIMITER_API): void
    {
        $cacheKey = $this->buildCacheKey($key, $limiterType);

        try {
            Cache::forget($cacheKey);
            Cache::forget($cacheKey . ':timer');
        } catch (Throwable $e) {
            // Ignore cache errors on clear
        }

        try {
            DB::table('rate_limit_fallback')
                ->where('key', $key)
                ->where('limiter_type', $limiterType)
                ->delete();
        } catch (Throwable $e) {
            Log::warning('RateLimitFallback: Failed to clear DB rate limit', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get remaining attempts for a key
     */
    public function remaining(
        string $key,
        string $limiterType = self::LIMITER_API,
        ?int $maxAttempts = null
    ): int {
        $maxAttempts = $this->getEffectiveLimit($limiterType, $maxAttempts);
        $result = $this->check($key, $limiterType, $maxAttempts);
        return $result['remaining'];
    }

    /**
     * Check if rate limited (simple boolean check)
     */
    public function isLimited(
        string $key,
        string $limiterType = self::LIMITER_API,
        ?int $maxAttempts = null
    ): bool {
        $maxAttempts = $this->getEffectiveLimit($limiterType, $maxAttempts);
        $result = $this->check($key, $limiterType, $maxAttempts);
        return $result['limited'];
    }

    /**
     * Check rate limit using Redis/Cache
     */
    protected function checkWithRedis(
        string $key,
        string $limiterType,
        int $maxAttempts,
        int $decayMinutes
    ): array {
        $cacheKey = $this->buildCacheKey($key, $limiterType);

        $hits = (int) Cache::get($cacheKey, 0);
        $remaining = max(0, $maxAttempts - $hits);

        $retryAfter = null;
        if ($hits >= $maxAttempts) {
            $timerKey = $cacheKey . ':timer';
            $timer = Cache::get($timerKey);
            if ($timer) {
                $retryAfter = max(0, $timer - time());
            }
        }

        $this->usingFallback = false;

        return [
            'limited' => $hits >= $maxAttempts,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'hits' => $hits,
            'max' => $maxAttempts,
            'using_fallback' => false,
        ];
    }

    /**
     * Record hit using Redis/Cache
     */
    protected function hitWithRedis(
        string $key,
        string $limiterType,
        int $maxAttempts,
        int $decayMinutes
    ): array {
        $cacheKey = $this->buildCacheKey($key, $limiterType);
        $decaySeconds = $decayMinutes * 60;

        // Increment or initialize
        if (Cache::has($cacheKey)) {
            $hits = Cache::increment($cacheKey);
        } else {
            Cache::put($cacheKey, 1, $decaySeconds);
            Cache::put($cacheKey . ':timer', time() + $decaySeconds, $decaySeconds);
            $hits = 1;
        }

        $remaining = max(0, $maxAttempts - $hits);

        $retryAfter = null;
        if ($hits >= $maxAttempts) {
            $timerKey = $cacheKey . ':timer';
            $timer = Cache::get($timerKey);
            if ($timer) {
                $retryAfter = max(0, $timer - time());
            }
        }

        $this->usingFallback = false;

        return [
            'limited' => $hits > $maxAttempts,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'hits' => $hits,
            'max' => $maxAttempts,
            'using_fallback' => false,
        ];
    }

    /**
     * Check rate limit using Database
     */
    protected function checkWithDatabase(
        string $key,
        string $limiterType,
        int $maxAttempts,
        int $decayMinutes
    ): array {
        $this->usingFallback = true;
        $minuteWindow = $this->getMinuteWindow($decayMinutes);

        try {
            // Clean up expired entries first (async would be better, but ensure cleanup)
            $this->cleanupExpired();

            $record = DB::table('rate_limit_fallback')
                ->where('key', $key)
                ->where('limiter_type', $limiterType)
                ->where('minute_window', $minuteWindow)
                ->first();

            $hits = $record ? (int) $record->hit_count : 0;
            $remaining = max(0, $maxAttempts - $hits);

            $retryAfter = null;
            if ($hits >= $maxAttempts && $record) {
                $expiresAt = strtotime($record->expires_at);
                $retryAfter = max(0, $expiresAt - time());
            }

            return [
                'limited' => $hits >= $maxAttempts,
                'remaining' => $remaining,
                'retry_after' => $retryAfter,
                'hits' => $hits,
                'max' => $maxAttempts,
                'using_fallback' => true,
            ];
        } catch (Throwable $e) {
            // FAIL-SECURE: If DB also fails, deny the request
            Log::error('RateLimitFallback: DB fallback also failed, denying request', [
                'key' => $key,
                'limiter_type' => $limiterType,
                'error' => $e->getMessage(),
            ]);

            return [
                'limited' => true, // DENY when uncertain
                'remaining' => 0,
                'retry_after' => 60,
                'hits' => $maxAttempts,
                'max' => $maxAttempts,
                'using_fallback' => true,
                'error' => 'Rate limit service unavailable',
            ];
        }
    }

    /**
     * Record hit using Database
     */
    protected function hitWithDatabase(
        string $key,
        string $limiterType,
        int $maxAttempts,
        int $decayMinutes
    ): array {
        $this->usingFallback = true;
        $minuteWindow = $this->getMinuteWindow($decayMinutes);
        $expiresAt = now()->addMinutes($decayMinutes);

        try {
            // Upsert the rate limit record
            $affected = DB::table('rate_limit_fallback')
                ->where('key', $key)
                ->where('limiter_type', $limiterType)
                ->where('minute_window', $minuteWindow)
                ->update([
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_hit_at' => now(),
                ]);

            if ($affected === 0) {
                // Insert new record
                DB::table('rate_limit_fallback')->insert([
                    'key' => $key,
                    'limiter_type' => $limiterType,
                    'minute_window' => $minuteWindow,
                    'hit_count' => 1,
                    'max_allowed' => $maxAttempts,
                    'ip_address' => request()->ip(),
                    'user_id' => auth()->id(),
                    'school_id' => auth()->user()?->school_id,
                    'first_hit_at' => now(),
                    'last_hit_at' => now(),
                    'expires_at' => $expiresAt,
                ]);
                $hits = 1;
            } else {
                // Get updated count
                $record = DB::table('rate_limit_fallback')
                    ->where('key', $key)
                    ->where('limiter_type', $limiterType)
                    ->where('minute_window', $minuteWindow)
                    ->first();
                $hits = $record ? (int) $record->hit_count : 1;
            }

            $remaining = max(0, $maxAttempts - $hits);

            $retryAfter = null;
            if ($hits > $maxAttempts) {
                $retryAfter = $decayMinutes * 60;
            }

            return [
                'limited' => $hits > $maxAttempts,
                'remaining' => $remaining,
                'retry_after' => $retryAfter,
                'hits' => $hits,
                'max' => $maxAttempts,
                'using_fallback' => true,
            ];
        } catch (Throwable $e) {
            // FAIL-SECURE: If DB fails, deny the request
            Log::error('RateLimitFallback: DB hit recording failed, denying request', [
                'key' => $key,
                'limiter_type' => $limiterType,
                'error' => $e->getMessage(),
            ]);

            return [
                'limited' => true, // DENY when uncertain
                'remaining' => 0,
                'retry_after' => 60,
                'hits' => $maxAttempts + 1,
                'max' => $maxAttempts,
                'using_fallback' => true,
                'error' => 'Rate limit service unavailable',
            ];
        }
    }

    /**
     * Get effective limit considering degraded mode
     */
    protected function getEffectiveLimit(string $limiterType, ?int $customLimit = null): int
    {
        // Use custom limit if provided
        if ($customLimit !== null) {
            $baseLimit = $customLimit;
        } else {
            $baseLimit = self::DEFAULT_LIMITS[$limiterType] ?? 60;
        }

        // If system is degraded, use stricter limits
        if ($this->failSecureService->isDegraded()) {
            $safeLimit = self::SAFE_LIMITS[$limiterType] ?? (int) ($baseLimit * 0.5);
            return min($baseLimit, $safeLimit);
        }

        return $baseLimit;
    }

    /**
     * Build cache key for Redis
     */
    protected function buildCacheKey(string $key, string $limiterType): string
    {
        return "rate_limit:{$limiterType}:{$key}";
    }

    /**
     * Get minute window for database grouping
     */
    protected function getMinuteWindow(int $decayMinutes): int
    {
        // Group by decay period windows
        $timestamp = time();
        $windowSize = $decayMinutes * 60;
        return (int) floor($timestamp / $windowSize);
    }

    /**
     * Cleanup expired rate limit records
     */
    protected function cleanupExpired(): void
    {
        try {
            // Only cleanup occasionally (1% chance per request)
            if (rand(1, 100) > 1) {
                return;
            }

            DB::table('rate_limit_fallback')
                ->where('expires_at', '<', now())
                ->delete();
        } catch (Throwable $e) {
            // Silently ignore cleanup failures
        }
    }

    /**
     * Check if currently using fallback mode
     */
    public function isUsingFallback(): bool
    {
        return $this->usingFallback;
    }

    /**
     * Get rate limit statistics for monitoring
     */
    public function getStatistics(): array
    {
        try {
            $dbRecords = DB::table('rate_limit_fallback')
                ->where('expires_at', '>=', now())
                ->count();

            $limitedUsers = DB::table('rate_limit_fallback')
                ->where('expires_at', '>=', now())
                ->whereRaw('hit_count >= max_allowed')
                ->count();

            $byType = DB::table('rate_limit_fallback')
                ->where('expires_at', '>=', now())
                ->selectRaw('limiter_type, COUNT(*) as count, SUM(hit_count) as total_hits')
                ->groupBy('limiter_type')
                ->get()
                ->keyBy('limiter_type');

            return [
                'active_records' => $dbRecords,
                'limited_users' => $limitedUsers,
                'by_type' => $byType,
                'using_fallback' => $this->usingFallback,
                'degraded_mode' => $this->failSecureService->isDegraded(),
            ];
        } catch (Throwable $e) {
            return [
                'error' => 'Cannot retrieve statistics',
                'using_fallback' => $this->usingFallback,
            ];
        }
    }

    /**
     * Purge all rate limit data (for testing/emergency)
     */
    public function purgeAll(): void
    {
        try {
            DB::table('rate_limit_fallback')->truncate();
        } catch (Throwable $e) {
            Log::error('RateLimitFallback: Failed to purge all records', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
