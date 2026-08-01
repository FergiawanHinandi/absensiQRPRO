<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use App\Events\CacheStampedeDetected;
use App\Events\CacheRegenerationCompleted;

/**
 * Cache Lock Service
 * 
 * Implements cache stampede protection using lock pattern
 * 
 * PATTERN:
 * 1. Try to get cached value
 * 2. If miss, acquire lock
 * 3. Double-check cache (another process may have filled it)
 * 4. Calculate and cache value
 * 5. Release lock
 * 6. If lock acquisition fails, wait and retry
 * 
 * BENEFITS:
 * - Prevents cache stampede (multiple processes regenerating same cache)
 * - Reduces database load during cache expiration
 * - Ensures only one process regenerates cache at a time
 * 
 * MONITORING:
 * - Tracks lock contention for hot keys
 * - Monitors cache regeneration frequency
 * - Alerts on cache stampede patterns
 * 
 * @version 2.0.0
 */
class CacheLockService
{
    /**
     * Lock timeout in seconds
     */
    const LOCK_TIMEOUT = 10;

    /**
     * Maximum retry attempts
     */
    const MAX_RETRIES = 3;

    /**
     * Retry delay in milliseconds
     */
    const RETRY_DELAY_MS = 100;

    /**
     * Metrics tracking
     */
    protected array $metrics = [
        'cache_hits' => 0,
        'cache_misses' => 0,
        'lock_acquisitions' => 0,
        'lock_contentions' => 0,
        'regenerations' => 0,
        'stale_served' => 0,
    ];

    /**
     * Get cached value with lock protection
     * 
     * @param string $cacheKey Cache key
     * @param int $ttl Cache TTL in seconds
     * @param callable $callback Function to generate value if cache miss
     * @param int $lockTimeout Lock timeout in seconds (default: 10)
     * @return mixed Cached or generated value
     */
    public function remember(
        string $cacheKey,
        int $ttl,
        callable $callback,
        int $lockTimeout = self::LOCK_TIMEOUT
    ) {
        $startTime = microtime(true);
        
        // Step 1: Try cache first (fast path)
        $value = Cache::get($cacheKey);
        if ($value !== null) {
            $this->metrics['cache_hits']++;
            $this->trackMetric('cache_hit', $cacheKey, microtime(true) - $startTime);
            return $value;
        }

        $this->metrics['cache_misses']++;
        $this->trackMetric('cache_miss', $cacheKey, microtime(true) - $startTime);

        // Step 2: Cache miss - acquire lock
        $lockKey = "lock:{$cacheKey}";
        $lock = Cache::lock($lockKey, $lockTimeout);

        if ($lock->get()) {
            $this->metrics['lock_acquisitions']++;
            
            try {
                // Step 3: Double-check cache (another process may have filled it)
                $value = Cache::get($cacheKey);
                if ($value !== null) {
                    Log::debug('Cache lock: Value found after lock acquisition', [
                        'cache_key' => $cacheKey,
                    ]);
                    return $value;
                }

                // Step 4: Calculate and cache value
                $this->metrics['regenerations']++;
                
                Log::info('Cache lock: Regenerating cache', [
                    'cache_key' => $cacheKey,
                    'ttl' => $ttl,
                ]);

                $regenerationStart = microtime(true);
                $value = $callback();
                $regenerationTime = microtime(true) - $regenerationStart;
                
                Cache::put($cacheKey, $value, $ttl);

                $this->trackMetric('cache_regeneration', $cacheKey, $regenerationTime);
                
                Event::dispatch(new CacheRegenerationCompleted($cacheKey, $regenerationTime));

                return $value;
            } finally {
                // Step 5: Always release lock
                $lock->release();
            }
        }

        // Step 6: Lock acquisition failed - wait and retry
        $this->metrics['lock_contentions']++;
        $this->trackMetric('lock_contention', $cacheKey, microtime(true) - $startTime);
        
        // Detect potential stampede
        $this->detectStampede($cacheKey);
        
        return $this->retryWithBackoff($cacheKey, $ttl, $callback, $lockTimeout);
    }

    /**
     * Retry cache generation with exponential backoff
     * 
     * @param string $cacheKey
     * @param int $ttl
     * @param callable $callback
     * @param int $lockTimeout
     * @return mixed
     */
    protected function retryWithBackoff(
        string $cacheKey,
        int $ttl,
        callable $callback,
        int $lockTimeout
    ) {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;

            // Check if cache is now available (another process may have filled it)
            $value = Cache::get($cacheKey);
            if ($value !== null) {
                Log::debug('Cache lock: Value found during retry', [
                    'cache_key' => $cacheKey,
                    'attempt' => $attempt,
                ]);
                return $value;
            }

            // Exponential backoff: 100ms, 200ms, 400ms
            $delayMs = self::RETRY_DELAY_MS * pow(2, $attempt - 1);
            usleep($delayMs * 1000);

            Log::debug('Cache lock: Retrying after backoff', [
                'cache_key' => $cacheKey,
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ]);

            // Try to acquire lock again
            $lockKey = "lock:{$cacheKey}";
            $lock = Cache::lock($lockKey, $lockTimeout);

            if ($lock->get()) {
                try {
                    // Double-check cache again
                    $value = Cache::get($cacheKey);
                    if ($value !== null) {
                        return $value;
                    }

                    // Generate and cache value
                    Log::info('Cache lock: Regenerating cache after retry', [
                        'cache_key' => $cacheKey,
                        'attempt' => $attempt,
                    ]);

                    $value = $callback();
                    Cache::put($cacheKey, $value, $ttl);

                    return $value;
                } finally {
                    $lock->release();
                }
            }
        }

        // Max retries exceeded - generate without lock (fallback)
        Log::warning('Cache lock: Max retries exceeded, generating without lock', [
            'cache_key' => $cacheKey,
            'max_retries' => self::MAX_RETRIES,
        ]);

        $value = $callback();
        Cache::put($cacheKey, $value, $ttl);

        return $value;
    }

    /**
     * Get cached value with lock protection and stale-while-revalidate
     * 
     * This variant serves stale cache while regenerating in background
     * 
     * @param string $cacheKey Cache key
     * @param int $ttl Cache TTL in seconds
     * @param callable $callback Function to generate value
     * @param int $staleTtl How long to serve stale cache (default: 60s)
     * @return mixed Cached or generated value
     */
    public function rememberWithStale(
        string $cacheKey,
        int $ttl,
        callable $callback,
        int $staleTtl = 60
    ) {
        $staleKey = "{$cacheKey}:stale";

        // Try fresh cache first
        $value = Cache::get($cacheKey);
        if ($value !== null) {
            $this->metrics['cache_hits']++;
            return $value;
        }

        $this->metrics['cache_misses']++;

        // Try stale cache
        $staleValue = Cache::get($staleKey);

        // Acquire lock to regenerate
        $lockKey = "lock:{$cacheKey}";
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT);

        if ($lock->get()) {
            $this->metrics['lock_acquisitions']++;
            
            try {
                // Double-check fresh cache
                $value = Cache::get($cacheKey);
                if ($value !== null) {
                    return $value;
                }

                // Regenerate
                $this->metrics['regenerations']++;
                
                Log::info('Cache lock: Regenerating with stale support', [
                    'cache_key' => $cacheKey,
                    'has_stale' => $staleValue !== null,
                ]);

                $regenerationStart = microtime(true);
                $value = $callback();
                $regenerationTime = microtime(true) - $regenerationStart;

                // Store fresh cache
                Cache::put($cacheKey, $value, $ttl);

                // Store stale cache (for next expiration)
                Cache::put($staleKey, $value, $ttl + $staleTtl);

                $this->trackMetric('cache_regeneration_stale', $cacheKey, $regenerationTime);
                Event::dispatch(new CacheRegenerationCompleted($cacheKey, $regenerationTime));

                return $value;
            } finally {
                $lock->release();
            }
        }

        // Lock failed - serve stale if available
        $this->metrics['lock_contentions']++;
        
        if ($staleValue !== null) {
            $this->metrics['stale_served']++;
            
            Log::info('Cache lock: Serving stale cache', [
                'cache_key' => $cacheKey,
            ]);
            
            $this->trackMetric('stale_cache_served', $cacheKey, 0);
            
            return $staleValue;
        }

        // No stale cache - wait and retry
        return $this->retryWithBackoff($cacheKey, $ttl, $callback, self::LOCK_TIMEOUT);
    }

    /**
     * Track metric for monitoring
     * 
     * @param string $metricName
     * @param string $cacheKey
     * @param float $value
     */
    protected function trackMetric(string $metricName, string $cacheKey, float $value): void
    {
        // Store metric in cache for monitoring
        $metricsKey = "cache_metrics:{$metricName}:{$cacheKey}";
        $currentMetrics = Cache::get($metricsKey, []);
        
        $currentMetrics[] = [
            'timestamp' => now()->toIso8601String(),
            'value' => $value,
        ];
        
        // Keep only last 100 entries
        if (count($currentMetrics) > 100) {
            $currentMetrics = array_slice($currentMetrics, -100);
        }
        
        Cache::put($metricsKey, $currentMetrics, 3600); // 1 hour
    }

    /**
     * Detect cache stampede pattern
     * 
     * @param string $cacheKey
     */
    protected function detectStampede(string $cacheKey): void
    {
        $contentionKey = "cache_contention_count:{$cacheKey}";
        $contentionCount = Cache::get($contentionKey, 0);
        $contentionCount++;
        
        Cache::put($contentionKey, $contentionCount, 60); // Track for 1 minute
        
        // Alert if more than 5 contentions in 1 minute
        if ($contentionCount > 5) {
            Log::warning('Cache stampede detected', [
                'cache_key' => $cacheKey,
                'contention_count' => $contentionCount,
            ]);
            
            Event::dispatch(new CacheStampedeDetected($cacheKey, $contentionCount));
        }
    }

    /**
     * Get current metrics
     * 
     * @return array
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Reset metrics
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'cache_hits' => 0,
            'cache_misses' => 0,
            'lock_acquisitions' => 0,
            'lock_contentions' => 0,
            'regenerations' => 0,
            'stale_served' => 0,
        ];
    }

    /**
     * Get cache hit rate
     * 
     * @return float
     */
    public function getHitRate(): float
    {
        $total = $this->metrics['cache_hits'] + $this->metrics['cache_misses'];
        
        if ($total === 0) {
            return 0.0;
        }
        
        return ($this->metrics['cache_hits'] / $total) * 100;
    }
}
