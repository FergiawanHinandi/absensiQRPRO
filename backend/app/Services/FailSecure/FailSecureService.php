<?php

namespace App\Services\FailSecure;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * FailSecureService
 *
 * Central service for fail-secure handling in the attendance system.
 * Ensures that security rules remain enforced even during system failures.
 *
 * CORE PRINCIPLE: When in doubt, DENY.
 * The system must fail securely, not allow bypass when dependencies break.
 */
class FailSecureService
{
    /**
     * System state constants
     */
    public const STATE_HEALTHY = 'healthy';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_CRITICAL = 'critical';

    public const STATE_UNKNOWN = 'unknown';

    /**
     * Component identifiers
     */
    public const COMPONENT_REDIS = 'redis';

    public const COMPONENT_DATABASE = 'database';

    public const COMPONENT_QUEUE = 'queue';

    public const COMPONENT_CACHE = 'cache';

    public const COMPONENT_POLICY = 'policy_service';

    public const COMPONENT_LOCATION = 'location_service';

    public const COMPONENT_DEVICE = 'device_service';

    /**
     * Fallback event types
     */
    public const EVENT_POLICY_FALLBACK = 'policy_fallback_used';

    public const EVENT_RATE_LIMIT_FALLBACK = 'rate_limit_fallback';

    public const EVENT_LOCATION_VALIDATION_FAILED = 'location_validation_failed';

    public const EVENT_DEVICE_VALIDATION_UNAVAILABLE = 'device_validation_unavailable';

    public const EVENT_SYSTEM_DEGRADED = 'system_degraded';

    public const EVENT_SECURITY_VALIDATION_FAILED = 'security_validation_failed';

    /**
     * Safe defaults - stricter than normal defaults for fail-secure mode
     */
    public const SAFE_DEFAULTS = [
        'attendance.geofence_radius_meters' => 30, // Tighter than normal 50
        'attendance.teacher_geofence_radius_meters' => 100, // Tighter than normal 200
        'attendance.max_scan_per_minute' => 10, // Tighter than normal 30
        'attendance.max_failed_scans_per_2min' => 3, // Tighter than normal 5
        'attendance.qr_expiry_minutes' => 5, // Tighter than normal 10
        'attendance.late_threshold_minutes' => 10, // Tighter than normal 15
        'rate_limit.login_attempts' => 3, // Tighter than normal 5
        'rate_limit.api_per_minute' => 30, // Tighter than normal 60
        'rate_limit.scan_per_minute' => 5, // Tighter than normal 10
        'security.require_device_approval' => true,
        'security.enable_geofence_check' => true,
    ];

    /**
     * Degraded mode rate limit reduction factor (50%)
     */
    public const DEGRADED_RATE_LIMIT_FACTOR = 0.5;

    /**
     * Database slow threshold in milliseconds
     */
    public const DB_SLOW_THRESHOLD_MS = 2000;

    /**
     * Queue backlog threshold for degraded state
     */
    public const QUEUE_BACKLOG_THRESHOLD = 1000;

    /**
     * Consecutive failures before marking component as degraded
     */
    public const FAILURE_THRESHOLD = 3;

    /**
     * Cache key prefix for system state
     */
    protected const STATE_CACHE_PREFIX = 'fail_secure:state:';

    /**
     * Currently active fallbacks (in-memory for current request)
     */
    protected array $activeFallbacks = [];

    /**
     * Current system state (cached for current request)
     */
    protected ?string $currentSystemState = null;

    /**
     * Get current system state
     */
    public function getSystemState(): string
    {
        if ($this->currentSystemState !== null) {
            return $this->currentSystemState;
        }

        try {
            // Try cache first
            $cachedState = Cache::get(self::STATE_CACHE_PREFIX.'overall');
            if ($cachedState) {
                $this->currentSystemState = $cachedState;

                return $cachedState;
            }

            // Check all components
            $componentStates = $this->checkAllComponents();

            // Determine overall state
            if (in_array(self::STATE_CRITICAL, $componentStates)) {
                $this->currentSystemState = self::STATE_CRITICAL;
            } elseif (in_array(self::STATE_DEGRADED, $componentStates)) {
                $this->currentSystemState = self::STATE_DEGRADED;
            } elseif (in_array(self::STATE_UNKNOWN, $componentStates)) {
                $this->currentSystemState = self::STATE_DEGRADED;
            } else {
                $this->currentSystemState = self::STATE_HEALTHY;
            }

            // Cache for 30 seconds
            Cache::put(self::STATE_CACHE_PREFIX.'overall', $this->currentSystemState, 30);

            return $this->currentSystemState;
        } catch (Throwable $e) {
            // If we can't even check state, assume degraded
            Log::error('FailSecureService: Cannot determine system state', [
                'error' => $e->getMessage(),
            ]);
            $this->currentSystemState = self::STATE_DEGRADED;

            return self::STATE_DEGRADED;
        }
    }

    /**
     * Check all infrastructure components
     */
    public function checkAllComponents(): array
    {
        return [
            self::COMPONENT_REDIS => $this->checkRedisHealth(),
            self::COMPONENT_DATABASE => $this->checkDatabaseHealth(),
            self::COMPONENT_QUEUE => $this->checkQueueHealth(),
            self::COMPONENT_CACHE => $this->checkCacheHealth(),
        ];
    }

    /**
     * Check if system is in degraded mode
     */
    public function isDegraded(): bool
    {
        $state = $this->getSystemState();

        return in_array($state, [self::STATE_DEGRADED, self::STATE_CRITICAL]);
    }

    /**
     * Check if system is in critical state
     */
    public function isCritical(): bool
    {
        return $this->getSystemState() === self::STATE_CRITICAL;
    }

    /**
     * Get effective rate limit (reduced if degraded)
     */
    public function getEffectiveRateLimit(int $normalLimit): int
    {
        if ($this->isDegraded()) {
            return (int) ceil($normalLimit * self::DEGRADED_RATE_LIMIT_FACTOR);
        }

        return $normalLimit;
    }

    /**
     * Get safe default for a policy key
     */
    public function getSafeDefault(string $key, $fallback = null)
    {
        return self::SAFE_DEFAULTS[$key] ?? $fallback;
    }

    /**
     * Record a fallback event
     */
    public function recordFallbackEvent(
        string $eventType,
        string $component,
        string $fallbackAction,
        ?string $originalError = null,
        array $context = [],
        string $severity = 'warning'
    ): void {
        // Add to active fallbacks for current request
        $this->activeFallbacks[] = [
            'type' => $eventType,
            'component' => $component,
        ];

        try {
            // Log to database
            DB::table('fallback_events')->insert([
                'event_type' => $eventType,
                'severity' => $severity,
                'component' => $component,
                'original_error' => $originalError ? substr($originalError, 0, 65535) : null,
                'fallback_action' => $fallbackAction,
                'context' => json_encode($context),
                'user_id' => auth()->id(),
                'school_id' => auth()->user()?->school_id,
                'ip_address' => request()->ip(),
                'request_path' => substr(request()->path(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // If we can't log to DB, at least log to file
            Log::channel('security')->error('FailSecure: Fallback event (DB unavailable)', [
                'event_type' => $eventType,
                'component' => $component,
                'fallback_action' => $fallbackAction,
                'original_error' => $originalError,
                'db_error' => $e->getMessage(),
            ]);
        }

        // Always log to security channel
        Log::channel('security')->warning("FailSecure: {$eventType}", [
            'component' => $component,
            'fallback_action' => $fallbackAction,
            'original_error' => $originalError,
            'context' => $context,
        ]);
    }

    /**
     * Get active fallbacks for current request
     */
    public function getActiveFallbacks(): array
    {
        return $this->activeFallbacks;
    }

    /**
     * Check if any fallback is active
     */
    public function hasFallbackActive(): bool
    {
        return ! empty($this->activeFallbacks);
    }

    /**
     * Update component health state
     */
    public function updateComponentState(
        string $component,
        string $state,
        ?int $responseMs = null,
        ?string $error = null
    ): void {
        try {
            $now = now();

            // Get current state
            $current = DB::table('system_health_state')
                ->where('component', $component)
                ->first();

            $data = [
                'component' => $component,
                'state' => $state,
                'last_response_ms' => $responseMs,
                'updated_at' => $now,
            ];

            if ($state === self::STATE_HEALTHY) {
                $data['last_healthy_at'] = $now;
                $data['consecutive_failures'] = 0;
                $data['last_error'] = null;
            } else {
                $data['last_failure_at'] = $now;
                $data['consecutive_failures'] = ($current->consecutive_failures ?? 0) + 1;
                $data['last_error'] = $error ? substr($error, 0, 65535) : null;
            }

            DB::table('system_health_state')->updateOrInsert(
                ['component' => $component],
                $data
            );

            // Clear cached system state
            Cache::forget(self::STATE_CACHE_PREFIX.'overall');
            Cache::forget(self::STATE_CACHE_PREFIX.$component);
        } catch (Throwable $e) {
            Log::error('FailSecure: Cannot update component state', [
                'component' => $component,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check Redis health
     */
    public function checkRedisHealth(): string
    {
        try {
            $cacheKey = self::STATE_CACHE_PREFIX.self::COMPONENT_REDIS;
            $cached = Cache::store('array')->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $start = microtime(true);
            Redis::ping();
            $responseMs = (int) ((microtime(true) - $start) * 1000);

            $state = $responseMs > 500 ? self::STATE_DEGRADED : self::STATE_HEALTHY;

            $this->updateComponentState(self::COMPONENT_REDIS, $state, $responseMs);

            Cache::store('array')->put($cacheKey, $state, 10);

            return $state;
        } catch (Throwable $e) {
            $this->updateComponentState(self::COMPONENT_REDIS, self::STATE_CRITICAL, null, $e->getMessage());

            return self::STATE_CRITICAL;
        }
    }

    /**
     * Check Database health
     */
    public function checkDatabaseHealth(): string
    {
        try {
            $cacheKey = self::STATE_CACHE_PREFIX.self::COMPONENT_DATABASE;
            $cached = Cache::store('array')->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $start = microtime(true);
            DB::select('SELECT 1');
            $responseMs = (int) ((microtime(true) - $start) * 1000);

            if ($responseMs > self::DB_SLOW_THRESHOLD_MS) {
                $state = self::STATE_DEGRADED;
            } elseif ($responseMs > 500) {
                $state = self::STATE_DEGRADED;
            } else {
                $state = self::STATE_HEALTHY;
            }

            $this->updateComponentState(self::COMPONENT_DATABASE, $state, $responseMs);

            Cache::store('array')->put($cacheKey, $state, 10);

            return $state;
        } catch (Throwable $e) {
            $this->updateComponentState(self::COMPONENT_DATABASE, self::STATE_CRITICAL, null, $e->getMessage());

            return self::STATE_CRITICAL;
        }
    }

    /**
     * Check Queue health
     */
    public function checkQueueHealth(): string
    {
        try {
            $cacheKey = self::STATE_CACHE_PREFIX.self::COMPONENT_QUEUE;
            $cached = Cache::store('array')->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            // Check queue size
            $queueSize = 0;
            try {
                $queueSize = DB::table('jobs')->count();
            } catch (Throwable $e) {
                // jobs table might not exist
            }

            // Check failed jobs
            $failedCount = 0;
            try {
                $failedCount = DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subHour())
                    ->count();
            } catch (Throwable $e) {
                // failed_jobs table might not exist
            }

            if ($queueSize > self::QUEUE_BACKLOG_THRESHOLD || $failedCount > 50) {
                $state = self::STATE_DEGRADED;
            } elseif ($queueSize > self::QUEUE_BACKLOG_THRESHOLD * 2 || $failedCount > 100) {
                $state = self::STATE_CRITICAL;
            } else {
                $state = self::STATE_HEALTHY;
            }

            $this->updateComponentState(self::COMPONENT_QUEUE, $state, null, null);

            Cache::store('array')->put($cacheKey, $state, 30);

            return $state;
        } catch (Throwable $e) {
            return self::STATE_UNKNOWN;
        }
    }

    /**
     * Check Cache health
     */
    public function checkCacheHealth(): string
    {
        try {
            $cacheKey = self::STATE_CACHE_PREFIX.self::COMPONENT_CACHE;

            $testKey = 'fail_secure_health_check_'.uniqid();
            $testValue = 'test_'.time();

            $start = microtime(true);
            Cache::put($testKey, $testValue, 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);
            $responseMs = (int) ((microtime(true) - $start) * 1000);

            if ($retrieved !== $testValue) {
                $this->updateComponentState(self::COMPONENT_CACHE, self::STATE_CRITICAL, $responseMs, 'Cache read/write mismatch');

                return self::STATE_CRITICAL;
            }

            $state = $responseMs > 200 ? self::STATE_DEGRADED : self::STATE_HEALTHY;
            $this->updateComponentState(self::COMPONENT_CACHE, $state, $responseMs);

            return $state;
        } catch (Throwable $e) {
            $this->updateComponentState(self::COMPONENT_CACHE, self::STATE_CRITICAL, null, $e->getMessage());

            return self::STATE_CRITICAL;
        }
    }

    /**
     * Execute with fail-secure handling
     *
     * @param  callable  $operation  The main operation
     * @param  callable  $fallback  The fallback if operation fails
     * @param  string  $component  Component identifier for logging
     * @param  string  $fallbackDescription  Description of fallback action
     * @return mixed Result of operation or fallback
     */
    public function executeWithFallback(
        callable $operation,
        callable $fallback,
        string $component,
        string $fallbackDescription
    ): mixed {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->recordFallbackEvent(
                self::EVENT_SECURITY_VALIDATION_FAILED,
                $component,
                $fallbackDescription,
                $e->getMessage()
            );

            return $fallback($e);
        }
    }

    /**
     * Get comprehensive health status for admin dashboard
     */
    public function getHealthStatus(): array
    {
        $components = [];
        $componentStates = $this->checkAllComponents();

        foreach ($componentStates as $component => $state) {
            $dbState = DB::table('system_health_state')
                ->where('component', $component)
                ->first();

            $components[$component] = [
                'state' => $state,
                'last_healthy_at' => $dbState?->last_healthy_at,
                'last_failure_at' => $dbState?->last_failure_at,
                'consecutive_failures' => $dbState?->consecutive_failures ?? 0,
                'last_response_ms' => $dbState?->last_response_ms,
                'last_error' => $dbState?->last_error,
            ];
        }

        // Get recent fallback events
        $recentFallbacks = DB::table('fallback_events')
            ->where('created_at', '>=', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        // Count fallbacks by type
        $fallbackCounts = $recentFallbacks->groupBy('event_type')
            ->map(fn ($items) => $items->count());

        return [
            'system_state' => $this->getSystemState(),
            'is_degraded' => $this->isDegraded(),
            'is_critical' => $this->isCritical(),
            'components' => $components,
            'fallbacks_active' => array_unique(array_column($this->activeFallbacks, 'type')),
            'fallback_counts_24h' => $fallbackCounts,
            'recent_fallbacks' => $recentFallbacks->take(10)->values(),
            'safe_defaults_in_use' => $this->isDegraded(),
            'rate_limit_factor' => $this->isDegraded() ? self::DEGRADED_RATE_LIMIT_FACTOR : 1.0,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Clear system state cache (for testing or manual reset)
     */
    public function clearStateCache(): void
    {
        $this->currentSystemState = null;
        Cache::forget(self::STATE_CACHE_PREFIX.'overall');

        foreach ([self::COMPONENT_REDIS, self::COMPONENT_DATABASE, self::COMPONENT_QUEUE, self::COMPONENT_CACHE] as $component) {
            Cache::forget(self::STATE_CACHE_PREFIX.$component);
        }
    }

    /**
     * Force system into degraded mode (for testing)
     */
    public function forceDegradedMode(bool $enabled = true): void
    {
        if ($enabled) {
            Cache::put(self::STATE_CACHE_PREFIX.'overall', self::STATE_DEGRADED, 3600);
            $this->currentSystemState = self::STATE_DEGRADED;
        } else {
            $this->clearStateCache();
        }
    }
}
