<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

/**
 * Trait for using cache with tags
 *
 * Provides helper methods for caching with automatic tag support
 * Falls back to regular cache if tags are not supported
 */
trait UsesCacheTags
{
    /**
     * Remember data in cache with tags
     *
     * @param  string|array  $tags  Cache tags
     * @param  string  $key  Cache key
     * @param  int  $ttl  Time to live in seconds
     * @param  callable  $callback  Callback to get data if not cached
     * @return mixed
     */
    protected function cacheWithTags($tags, string $key, int $ttl, callable $callback)
    {
        // Ensure tags is an array
        $tags = is_array($tags) ? $tags : [$tags];

        if ($this->supportsCacheTags()) {
            // Use cache tags (Redis/Memcached)
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        } else {
            // Fallback to regular cache (File/Database)
            // Prefix key with tags for easier identification
            $prefixedKey = $this->getPrefixedKey($tags, $key);

            return Cache::remember($prefixedKey, $ttl, $callback);
        }
    }

    /**
     * Get data from cache with tags
     *
     * @param  string|array  $tags  Cache tags
     * @param  string  $key  Cache key
     * @param  mixed  $default  Default value if not found
     * @return mixed
     */
    protected function getCacheWithTags($tags, string $key, $default = null)
    {
        $tags = is_array($tags) ? $tags : [$tags];

        if ($this->supportsCacheTags()) {
            return Cache::tags($tags)->get($key, $default);
        } else {
            $prefixedKey = $this->getPrefixedKey($tags, $key);

            return Cache::get($prefixedKey, $default);
        }
    }

    /**
     * Put data in cache with tags
     *
     * @param  string|array  $tags  Cache tags
     * @param  string  $key  Cache key
     * @param  mixed  $value  Value to cache
     * @param  int  $ttl  Time to live in seconds
     */
    protected function putCacheWithTags($tags, string $key, $value, int $ttl): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];

        if ($this->supportsCacheTags()) {
            return Cache::tags($tags)->put($key, $value, $ttl);
        } else {
            $prefixedKey = $this->getPrefixedKey($tags, $key);

            return Cache::put($prefixedKey, $value, $ttl);
        }
    }

    /**
     * Flush cache by tags
     *
     * @param  string|array  $tags  Cache tags to flush
     */
    protected function flushCacheTags($tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];

        if ($this->supportsCacheTags()) {
            return Cache::tags($tags)->flush();
        } else {
            // For non-tag supporting drivers, we can't flush by tags
            // This is a limitation - consider using Redis in production
            \Illuminate\Support\Facades\Log::warning(
                'Cache flush by tags not supported with current driver',
                ['tags' => $tags, 'driver' => config('cache.default')]
            );

            return false;
        }
    }

    /**
     * Check if current cache driver supports tags
     */
    protected function supportsCacheTags(): bool
    {
        $driver = config('cache.default');

        // Redis and Memcached support tags
        return in_array($driver, ['redis', 'memcached']);
    }

    /**
     * Get prefixed cache key for non-tag supporting drivers
     */
    protected function getPrefixedKey(array $tags, string $key): string
    {
        $tagPrefix = implode('_', $tags);

        return "{$tagPrefix}:{$key}";
    }
}
