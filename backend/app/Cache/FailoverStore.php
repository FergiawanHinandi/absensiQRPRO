<?php

namespace App\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Log;

/**
 * Failover Cache Store
 * 
 * Implements automatic failover between multiple cache stores.
 * Tries stores in order: Redis -> Database -> Array
 */
class FailoverStore implements Store
{
    /**
     * Array of cache stores to try in order
     * 
     * @var array<Repository>
     */
    private array $stores;
    
    /**
     * Currently active store
     * 
     * @var Repository|null
     */
    private ?Repository $activeStore = null;
    
    public function __construct(array $stores)
    {
        $this->stores = $stores;
    }
    
    /**
     * Get the active store (with automatic failover)
     */
    private function getActiveStore(): Repository
    {
        // Try each store in order
        foreach ($this->stores as $store) {
            try {
                // Test if store is available with a simple operation
                $store->get('_failover_test_key');
                $this->activeStore = $store;
                return $store;
            } catch (\Exception $e) {
                $storeClass = get_class($store->getStore());
                Log::debug("Cache store {$storeClass} unavailable, trying next", [
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        // If all stores fail, use the last one (array cache)
        $this->activeStore = end($this->stores);
        return $this->activeStore;
    }
    
    /**
     * Retrieve an item from the cache by key.
     */
    public function get($key)
    {
        try {
            return $this->getActiveStore()->get($key);
        } catch (\Exception $e) {
            Log::warning('Failover cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Retrieve multiple items from the cache by key.
     */
    public function many(array $keys)
    {
        try {
            return $this->getActiveStore()->many($keys);
        } catch (\Exception $e) {
            Log::warning('Failover cache many failed', ['error' => $e->getMessage()]);
            return array_fill_keys($keys, null);
        }
    }
    
    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put($key, $value, $seconds)
    {
        try {
            return $this->getActiveStore()->put($key, $value, $seconds);
        } catch (\Exception $e) {
            Log::warning('Failover cache put failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, $seconds)
    {
        try {
            return $this->getActiveStore()->putMany($values, $seconds);
        } catch (\Exception $e) {
            Log::warning('Failover cache putMany failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Increment the value of an item in the cache.
     */
    public function increment($key, $value = 1)
    {
        try {
            return $this->getActiveStore()->increment($key, $value);
        } catch (\Exception $e) {
            Log::warning('Failover cache increment failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement($key, $value = 1)
    {
        try {
            return $this->getActiveStore()->decrement($key, $value);
        } catch (\Exception $e) {
            Log::warning('Failover cache decrement failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Store an item in the cache indefinitely.
     */
    public function forever($key, $value)
    {
        try {
            return $this->getActiveStore()->forever($key, $value);
        } catch (\Exception $e) {
            Log::warning('Failover cache forever failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Remove an item from the cache.
     */
    public function forget($key)
    {
        try {
            return $this->getActiveStore()->forget($key);
        } catch (\Exception $e) {
            Log::warning('Failover cache forget failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Remove all items from the cache.
     */
    public function flush()
    {
        try {
            return $this->getActiveStore()->flush();
        } catch (\Exception $e) {
            Log::warning('Failover cache flush failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Get the cache key prefix.
     */
    public function getPrefix()
    {
        try {
            return $this->getActiveStore()->getStore()->getPrefix();
        } catch (\Exception $e) {
            return '';
        }
    }
}
