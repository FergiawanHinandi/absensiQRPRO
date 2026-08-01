<?php

namespace App\Listeners;

use App\Events\CacheStampedeDetected;
use App\Events\CacheRegenerationCompleted;
use App\Services\Alerting\SlackAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Monitor Cache Stampede Listener
 * 
 * Monitors cache regeneration patterns and alerts on stampede scenarios.
 */
class MonitorCacheStampede
{
    /**
     * Handle cache stampede detected event
     */
    public function handleStampedeDetected(CacheStampedeDetected $event): void
    {
        // Track stampede frequency
        $stampedeKey = "cache_stampede_frequency:{$event->cacheKey}";
        $frequency = Cache::get($stampedeKey, 0);
        $frequency++;
        
        Cache::put($stampedeKey, $frequency, 3600); // Track for 1 hour
        
        // Log stampede with context
        Log::warning('Cache stampede pattern detected', [
            'cache_key' => $event->cacheKey,
            'contention_count' => $event->contentionCount,
            'frequency_last_hour' => $frequency,
            'timestamp' => now()->toIso8601String(),
        ]);
        
        // Alert if stampede is frequent (more than 3 times in an hour)
        if ($frequency > 3) {
            Log::critical('High frequency cache stampede detected', [
                'cache_key' => $event->cacheKey,
                'frequency' => $frequency,
                'recommendation' => 'Consider increasing cache TTL or implementing rate limiting',
            ]);
            
            SlackAlert::warning("High frequency cache stampede detected for key: {$event->cacheKey}", [
                'frequency' => $frequency,
            ]);
        }
    }

    /**
     * Handle cache regeneration completed event
     */
    public function handleRegenerationCompleted(CacheRegenerationCompleted $event): void
    {
        // Track regeneration frequency
        $regenKey = "cache_regeneration_frequency:{$event->cacheKey}";
        $frequency = Cache::get($regenKey, 0);
        $frequency++;
        
        Cache::put($regenKey, $frequency, 3600); // Track for 1 hour
        
        // Track regeneration time statistics
        $statsKey = "cache_regeneration_stats:{$event->cacheKey}";
        $stats = Cache::get($statsKey, [
            'count' => 0,
            'total_time' => 0,
            'min_time' => PHP_FLOAT_MAX,
            'max_time' => 0,
        ]);
        
        $stats['count']++;
        $stats['total_time'] += $event->regenerationTime;
        $stats['min_time'] = min($stats['min_time'], $event->regenerationTime);
        $stats['max_time'] = max($stats['max_time'], $event->regenerationTime);
        $stats['avg_time'] = $stats['total_time'] / $stats['count'];
        
        Cache::put($statsKey, $stats, 3600);
        
        // Log slow regenerations (> 1 second)
        if ($event->regenerationTime > 1.0) {
            Log::warning('Slow cache regeneration detected', [
                'cache_key' => $event->cacheKey,
                'regeneration_time' => $event->regenerationTime,
                'avg_time' => $stats['avg_time'],
                'recommendation' => 'Consider optimizing the cache generation callback',
            ]);
        }
        
        // Log regeneration metrics
        Log::info('Cache regeneration completed', [
            'cache_key' => $event->cacheKey,
            'regeneration_time' => $event->regenerationTime,
            'frequency_last_hour' => $frequency,
            'stats' => $stats,
        ]);
    }

    /**
     * Get cache regeneration statistics
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
     * Get hot keys (keys with high contention)
     */
    public function getHotKeys(int $limit = 10): array
    {
        // This would require a more sophisticated implementation
        // For now, return empty array
        // In production, you might want to use Redis sorted sets or similar
        return [];
    }
}
