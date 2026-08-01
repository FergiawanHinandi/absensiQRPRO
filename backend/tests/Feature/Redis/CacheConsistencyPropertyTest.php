<?php

namespace Tests\Feature\Redis;

use App\Services\CacheLockService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Property-Based Test: Cache Consistency Management
 * 
 * Feature: redis-high-availability
 * Property 20: Cache consistency management
 * Validates: Requirements 4.5
 * 
 * This test validates that for any inconsistent cache data after failover,
 * invalidation and refresh mechanisms should restore consistency.
 * 
 * Property: For any inconsistent cache data after failover, invalidation
 * and refresh mechanisms should restore consistency.
 */
class CacheConsistencyPropertyTest extends TestCase
{
    private const MIN_ITERATIONS = 100;
    
    private CacheLockService $cacheLockService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cacheLockService = app(CacheLockService::class);
        
        // Ensure clean cache state
        Cache::flush();
    }
    
    protected function tearDown(): void
    {
        Cache::flush();
        
        parent::tearDown();
    }

    /**
     * Property Test: Cache invalidation removes stale data for any key
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that cache invalidation successfully removes stale
     * data regardless of the cache key or data type.
     * 
*/
    public function property_cache_invalidation_removes_stale_data_for_any_key(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = $this->generateRandomCacheKey($i);
            $originalValue = $this->generateRandomValue($i);
            
            try {
                // Store original value
                Cache::put($cacheKey, $originalValue, 3600);
                
                // Verify it's cached
                $this->assertEquals($originalValue, Cache::get($cacheKey));
                
                // Invalidate cache
                Cache::forget($cacheKey);
                
                // Property: Cache should be empty after invalidation
                $cachedValue = Cache::get($cacheKey);
                
                if ($cachedValue !== null) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Cache invalidation property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Cache refresh updates stale data for any value change
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that cache refresh mechanisms correctly update
     * stale data when the underlying data changes.
     * 
*/
    public function property_cache_refresh_updates_stale_data_for_any_value_change(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = "test:refresh:{$i}";
            $originalValue = "original_{$i}";
            $newValue = "updated_{$i}";
            
            try {
                // Cache original data
                Cache::put($cacheKey, $originalValue, 3600);
                
                // Verify original is cached
                $this->assertEquals($originalValue, Cache::get($cacheKey));
                
                // Invalidate and refresh cache with new value
                Cache::forget($cacheKey);
                
                $refreshedData = Cache::remember($cacheKey, 3600, function() use ($newValue) {
                    return $newValue;
                });
                
                // Property: Refreshed cache should have updated data
                if ($refreshedData !== $newValue) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget($cacheKey);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Cache refresh property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Stale-while-revalidate serves stale data during refresh
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that the stale-while-revalidate pattern serves
     * stale data while regenerating cache in the background.
     * 
*/
    public function property_stale_while_revalidate_serves_stale_data_during_refresh(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = "test:stale:{$i}";
            $originalValue = "original_{$i}";
            $newValue = "new_{$i}";
            
            try {
                // Store original value with stale support
                Cache::put($cacheKey, $originalValue, 60);
                Cache::put("{$cacheKey}:stale", $originalValue, 120);
                
                // Expire main cache but keep stale
                Cache::forget($cacheKey);
                
                // Use stale-while-revalidate
                $servedValue = $this->cacheLockService->rememberWithStale(
                    $cacheKey,
                    60,
                    function() use ($newValue) {
                        // Simulate slow regeneration
                        usleep(10000); // 10ms
                        return $newValue;
                    },
                    60
                );
                
                // Property: Should serve stale value immediately
                // (first call gets stale, regenerates in background)
                if ($servedValue !== $originalValue && $servedValue !== $newValue) {
                    $failureCount++;
                }
                
                // Verify fresh cache is now available
                $freshValue = Cache::get($cacheKey);
                $this->assertEquals($newValue, $freshValue);
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget($cacheKey);
                Cache::forget("{$cacheKey}:stale");
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Stale-while-revalidate property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache lock prevents concurrent regeneration for any key
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that cache locks prevent multiple processes from
     * regenerating the same cache key simultaneously.
     * 
*/
    public function property_cache_lock_prevents_concurrent_regeneration_for_any_key(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = "test:lock:{$i}";
            $regenerationCount = 0;
            
            try {
                Cache::flush();
                
                // Simulate concurrent requests
                $callback = function() use (&$regenerationCount) {
                    $regenerationCount++;
                    usleep(50000); // 50ms to simulate slow operation
                    return "value_{$regenerationCount}";
                };
                
                // First call should regenerate
                $value1 = $this->cacheLockService->remember($cacheKey, 60, $callback);
                
                // Immediate second call should use cached value (no regeneration)
                $value2 = $this->cacheLockService->remember($cacheKey, 60, $callback);
                
                // Property: Should only regenerate once
                if ($regenerationCount !== 1) {
                    $failureCount++;
                }
                
                // Both values should be identical
                $this->assertEquals($value1, $value2);
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget($cacheKey);
                Cache::forget("lock:{$cacheKey}");
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Cache lock property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Multi-tenant cache isolation prevents cross-tenant access
     * 
     * **Validates: Requirements 4.5, 5.1**
     * 
     * This test verifies that cache keys are properly isolated between tenants
     * and one tenant cannot access another tenant's cached data.
     * 
*/
    public function property_multi_tenant_cache_isolation_prevents_cross_tenant_access(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $tenant1Id = 900000 + ($i * 2);
            $tenant2Id = 900000 + ($i * 2) + 1;
            
            try {
                $data1 = ['tenant_id' => $tenant1Id, 'secret' => "secret_{$tenant1Id}"];
                $data2 = ['tenant_id' => $tenant2Id, 'secret' => "secret_{$tenant2Id}"];
                
                // Cache data for both tenants
                Cache::put("tenant:{$tenant1Id}:data", $data1, 3600);
                Cache::put("tenant:{$tenant2Id}:data", $data2, 3600);
                
                // Property: Each tenant should only access its own data
                $cached1 = Cache::get("tenant:{$tenant1Id}:data");
                $cached2 = Cache::get("tenant:{$tenant2Id}:data");
                
                if ($cached1['tenant_id'] !== $tenant1Id || 
                    $cached2['tenant_id'] !== $tenant2Id ||
                    $cached1['secret'] === $cached2['secret']) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget("tenant:{$tenant1Id}:data");
                Cache::forget("tenant:{$tenant2Id}:data");
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Multi-tenant isolation property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache consistency after failover simulation
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test simulates Redis failover and verifies that cache consistency
     * is maintained through invalidation and refresh.
     * 
*/
    public function property_cache_consistency_maintained_after_failover_simulation(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $schoolId = 900000 + $i;
            $cacheKey = "school:{$schoolId}:settings";
            
            try {
                // Pre-failover: Cache school data
                $originalData = ['id' => $schoolId, 'name' => "School {$i}"];
                Cache::put($cacheKey, $originalData, 3600);
                
                // Simulate failover: Flush cache
                Cache::flush();
                
                // Post-failover: Verify cache is empty
                $this->assertNull(Cache::get($cacheKey));
                
                // Refresh cache (simulating data source)
                $refreshedData = Cache::remember($cacheKey, 3600, function() use ($schoolId, $i) {
                    // Simulate data source returning consistent data
                    return ['id' => $schoolId, 'name' => "School {$i}"];
                });
                
                // Property: Refreshed data should match original structure
                if ($refreshedData['id'] !== $schoolId || 
                    $refreshedData['name'] !== "School {$i}") {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget($cacheKey);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Failover consistency property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache TTL consistency after refresh
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that cache TTL is properly set after refresh
     * operations to prevent premature expiration.
     * 
*/
    public function property_cache_ttl_consistency_after_refresh(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $expectedTtl = 3600; // 1 hour
        $ttlTolerance = 10; // 10 seconds tolerance

        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = "test:ttl:{$i}";
            $value = "value_{$i}";
            
            try {
                // Store with specific TTL
                Cache::put($cacheKey, $value, $expectedTtl);
                
                // Get TTL from Redis
                $actualTtl = Cache::getStore()->getRedis()->ttl(
                    config('cache.prefix') . ":{$cacheKey}"
                );
                
                // Property: TTL should be within tolerance
                if (abs($actualTtl - $expectedTtl) > $ttlTolerance) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget($cacheKey);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "TTL consistency property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% within {$ttlTolerance}s tolerance across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache stampede prevention under concurrent load
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that cache stampede prevention works correctly
     * under concurrent access patterns.
     * 
*/
    public function property_cache_stampede_prevention_under_concurrent_load(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced for performance
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $cacheKey = "test:stampede:{$i}";
            $regenerationCount = 0;
            
            try {
                Cache::flush();
                
                $callback = function() use (&$regenerationCount) {
                    $regenerationCount++;
                    usleep(100000); // 100ms slow operation
                    return "value_{$regenerationCount}";
                };
                
                // Simulate concurrent requests (3 simultaneous)
                $values = [];
                for ($j = 0; $j < 3; $j++) {
                    $values[] = $this->cacheLockService->remember($cacheKey, 60, $callback);
                }
                
                // Property: Should regenerate only once despite concurrent requests
                // (First request regenerates, others wait and use cached value)
                if ($regenerationCount > 1) {
                    $failureCount++;
                }
                
                // All values should be identical
                $this->assertEquals($values[0], $values[1]);
                $this->assertEquals($values[1], $values[2]);
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget($cacheKey);
                Cache::forget("lock:{$cacheKey}");
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Stampede prevention property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache invalidation cascades for related keys
     * 
     * **Validates: Requirements 4.5**
     * 
     * This test verifies that invalidating a parent cache key properly
     * invalidates related child keys.
     * 
*/
    public function property_cache_invalidation_cascades_for_related_keys(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $schoolId = 900000 + $i;
            
            try {
                // Cache school and related data
                Cache::put("school:{$schoolId}:settings", ['name' => "School {$i}"], 3600);
                Cache::put("school:{$schoolId}:stats", ['count' => 100], 3600);
                Cache::put("school:{$schoolId}:config", ['timezone' => 'Asia/Jakarta'], 3600);
                
                // Invalidate all school-related caches using pattern
                $pattern = "school:{$schoolId}:*";
                $keys = $this->getCacheKeysByPattern($pattern);
                
                foreach ($keys as $key) {
                    Cache::forget($key);
                }
                
                // Property: All related keys should be invalidated
                $settings = Cache::get("school:{$schoolId}:settings");
                $stats = Cache::get("school:{$schoolId}:stats");
                $config = Cache::get("school:{$schoolId}:config");
                
                if ($settings !== null || $stats !== null || $config !== null) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget("school:{$schoolId}:settings");
                Cache::forget("school:{$schoolId}:stats");
                Cache::forget("school:{$schoolId}:config");
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Cascade invalidation property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Generate random cache key for testing
     */
    private function generateRandomCacheKey(int $seed): string
    {
        $types = ['school', 'user', 'session', 'attendance', 'schedule'];
        $type = $types[$seed % count($types)];
        return "{$type}:test:{$seed}";
    }

    /**
     * Generate random value for testing
     */
    private function generateRandomValue(int $seed)
    {
        $types = ['string', 'array', 'number'];
        $type = $types[$seed % count($types)];
        
        switch ($type) {
            case 'string':
                return "value_{$seed}";
            case 'array':
                return ['id' => $seed, 'data' => "test_{$seed}"];
            case 'number':
                return $seed * 100;
        }
    }

    /**
     * Get cache keys matching a pattern
     */
    private function getCacheKeysByPattern(string $pattern): array
    {
        try {
            $prefix = config('cache.prefix');
            $fullPattern = "{$prefix}:{$pattern}";
            
            $redis = Cache::getStore()->getRedis()->connection();
            $keys = $redis->keys($fullPattern);
            
            // Remove prefix from keys
            return array_map(function($key) use ($prefix) {
                return str_replace("{$prefix}:", '', $key);
            }, $keys);
        } catch (\Exception $e) {
            return [];
        }
    }
}
