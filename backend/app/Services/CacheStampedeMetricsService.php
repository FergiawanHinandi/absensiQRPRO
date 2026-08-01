<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache Stampede Metrics Service
 * 
 * Tracks and reports cache stampede metrics:
 * - Cache hit/miss rates
 * - Regeneration queue length
 * - Stale cache serving rate
 * - Lock contention patterns
 */
class CacheStampedeMetricsService
{
    /**
     * Get cache hit/miss rates for a specific key
     * 
     * @param string $cacheKey
     * @return array
     */
    public function getHitMissRates(string $cacheKey): array
    {
        $metricsKey = "cache_metrics:*:{$cacheKey}";
        
        $hits = $this->getMetricCount('cache_hit', $cacheKey);
        $misses = $this->getMetricCount('cache_miss', $cacheKey);
        $total = $hits + $misses;
        
        return [
            'cache_key' => $cacheKey,
            'hits' => $hits,
            'misses' => $misses,
            'total_requests' => $total,
            'hit_rate' => $total > 0 ? ($hits / $total) * 100 : 0,
            'miss_rate' => $total > 0 ? ($misses / $total) * 100 : 0,
        ];
    }

    /**
     * Get regeneration queue length
     * 
     * @return int
     */
    public function getRegenerationQueueLength(): int
    {
        // Count active locks (locks indicate ongoing regenerations)
        $lockPattern = "lock:*";
        $activeLocks = 0;
        
        // This is a simplified implementation
        // In production, you might want to use Redis SCAN command
        // or maintain a separate counter
        
        return $activeLocks;
    }

    /**
     * Get stale cache serving rate
     * 
     * @param string $cacheKey
     * @return array
     */
    public function getStaleServingRate(string $cacheKey): array
    {
        $staleServed = $this->getMetricCount('stale_cache_served', $cacheKey);
        $totalMisses = $this->getMetricCount('cache_miss', $cacheKey);
        
        return [
            'cache_key' => $cacheKey,
            'stale_served' => $staleServed,
            'total_misses' => $totalMisses,
            'stale_serving_rate' => $totalMisses > 0 ? ($staleServed / $totalMisses) * 100 : 0,
        ];
    }

    /**
     * Get lock contention metrics
     * 
     * @param string $cacheKey
     * @return array
     */
    public function getLockContentionMetrics(string $cacheKey): array
    {
        $contentions = $this->getMetricCount('lock_contention', $cacheKey);
        $acquisitions = $this->getMetricCount('cache_regeneration', $cacheKey);
        
        return [
            'cache_key' => $cacheKey,
            'lock_contentions' => $contentions,
            'lock_acquisitions' => $acquisitions,
            'contention_rate' => $acquisitions > 0 ? ($contentions / $acquisitions) * 100 : 0,
        ];
    }

    /**
     * Get all metrics for a cache key
     * 
     * @param string $cacheKey
     * @return array
     */
    public function getAllMetrics(string $cacheKey): array
    {
        return [
            'hit_miss_rates' => $this->getHitMissRates($cacheKey),
            'stale_serving' => $this->getStaleServingRate($cacheKey),
            'lock_contention' => $this->getLockContentionMetrics($cacheKey),
            'regeneration_stats' => $this->getRegenerationStats($cacheKey),
            'stampede_frequency' => $this->getStampedeFrequency($cacheKey),
        ];
    }

    /**
     * Get regeneration statistics
     * 
     * @param string $cacheKey
     * @return array
     */
    public function getRegenerationStats(string $cacheKey): array
    {
        $statsKey = "cache_regeneration_stats:{$cacheKey}";
        return Cache::get($statsKey, [
            'count' => 0,
            'total_time' => 0,
            'min_time' => 0,
            'max_time' => 0,
            'avg_time' => 0,
        ]);
    }

    /**
     * Get stampede frequency
     * 
     * @param string $cacheKey
     * @return int
     */
    public function getStampedeFrequency(string $cacheKey): int
    {
        $stampedeKey = "cache_stampede_frequency:{$cacheKey}";
        return Cache::get($stampedeKey, 0);
    }

    /**
     * Get hot keys (keys with high contention)
     * 
     * @param int $limit
     * @return array
     */
    public function getHotKeys(int $limit = 10): array
    {
        // This would require maintaining a sorted set of keys by contention count
        // For now, return empty array
        // In production, implement using Redis sorted sets
        
        return [];
    }

    /**
     * Get metric count for a specific metric type and cache key
     * 
     * @param string $metricType
     * @param string $cacheKey
     * @return int
     */
    protected function getMetricCount(string $metricType, string $cacheKey): int
    {
        $metricsKey = "cache_metrics:{$metricType}:{$cacheKey}";
        $metrics = Cache::get($metricsKey, []);
        
        return count($metrics);
    }

    /**
     * Get aggregated metrics for all cache keys
     * 
     * @return array
     */
    public function getAggregatedMetrics(): array
    {
        // This would require maintaining a list of all tracked cache keys
        // For now, return basic structure
        
        return [
            'total_cache_operations' => 0,
            'total_regenerations' => 0,
            'total_stampedes_detected' => 0,
            'average_hit_rate' => 0,
            'hot_keys' => $this->getHotKeys(),
        ];
    }

    /**
     * Clear metrics for a specific cache key
     * 
     * @param string $cacheKey
     */
    public function clearMetrics(string $cacheKey): void
    {
        $metricTypes = [
            'cache_hit',
            'cache_miss',
            'lock_contention',
            'cache_regeneration',
            'cache_regeneration_stale',
            'stale_cache_served',
        ];
        
        foreach ($metricTypes as $type) {
            $metricsKey = "cache_metrics:{$type}:{$cacheKey}";
            Cache::forget($metricsKey);
        }
        
        // Clear other related keys
        Cache::forget("cache_regeneration_stats:{$cacheKey}");
        Cache::forget("cache_stampede_frequency:{$cacheKey}");
        Cache::forget("cache_contention_count:{$cacheKey}");
        Cache::forget("cache_regeneration_frequency:{$cacheKey}");
        
        Log::info('Cache metrics cleared', ['cache_key' => $cacheKey]);
    }

    /**
     * Export metrics to array for reporting
     * 
     * @param string $cacheKey
     * @return array
     */
    public function exportMetrics(string $cacheKey): array
    {
        $metrics = $this->getAllMetrics($cacheKey);
        
        return [
            'cache_key' => $cacheKey,
            'timestamp' => now()->toIso8601String(),
            'metrics' => $metrics,
        ];
    }

    /**
     * Log metrics summary
     * 
     * @param string $cacheKey
     */
    public function logMetricsSummary(string $cacheKey): void
    {
        $metrics = $this->getAllMetrics($cacheKey);
        
        Log::info('Cache metrics summary', [
            'cache_key' => $cacheKey,
            'hit_rate' => $metrics['hit_miss_rates']['hit_rate'],
            'stale_serving_rate' => $metrics['stale_serving']['stale_serving_rate'],
            'contention_rate' => $metrics['lock_contention']['contention_rate'],
            'avg_regeneration_time' => $metrics['regeneration_stats']['avg_time'] ?? 0,
            'stampede_frequency' => $metrics['stampede_frequency'],
        ]);
    }
}
