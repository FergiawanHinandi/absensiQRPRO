<?php

namespace Tests\Feature;

use App\Services\CacheLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cache Stampede Protection Tests
 * 
 * Tests the cache lock pattern implementation to prevent cache stampede
 * 
 * SCENARIOS TESTED:
 * 1. Cache hit returns cached value immediately
 * 2. Cache miss acquires lock and generates value
 * 3. Concurrent requests wait for lock holder
 * 4. Double-check prevents duplicate generation
 * 5. Lock timeout and retry with backoff
 * 6. Stale-while-revalidate serves old cache
 */
class CacheStampedeTest extends TestCase
{
    use RefreshDatabase;

    protected CacheLockService $cacheLock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheLock = app(CacheLockService::class);
        Cache::flush();
    }

    /**
     * Test 1: Cache hit returns value immediately without lock
     */
    public function test_cache_hit_returns_immediately_without_lock(): void
    {
        // Arrange: Pre-populate cache
        $cacheKey = 'test_key_1';
        $expectedValue = ['data' => 'cached_value'];
        Cache::put($cacheKey, $expectedValue, 60);

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return ['data' => 'new_value'];
        };

        // Act: Get cached value
        $result = $this->cacheLock->remember($cacheKey, 60, $callback);

        // Assert: Returns cached value without executing callback
        $this->assertEquals($expectedValue, $result);
        $this->assertFalse($callbackExecuted, 'Callback should not execute on cache hit');
    }

    /**
     * Test 2: Cache miss acquires lock and generates value
     */
    public function test_cache_miss_acquires_lock_and_generates_value(): void
    {
        // Arrange: Empty cache
        $cacheKey = 'test_key_2';
        $expectedValue = ['data' => 'generated_value'];

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted, $expectedValue) {
            $callbackExecuted = true;
            return $expectedValue;
        };

        // Act: Get value (cache miss)
        $result = $this->cacheLock->remember($cacheKey, 60, $callback);

        // Assert: Callback executed and value cached
        $this->assertEquals($expectedValue, $result);
        $this->assertTrue($callbackExecuted, 'Callback should execute on cache miss');
        $this->assertEquals($expectedValue, Cache::get($cacheKey), 'Value should be cached');
    }

    /**
     * Test 3: Concurrent requests - only one generates, others wait
     */
    public function test_concurrent_requests_only_one_generates(): void
    {
        // Arrange: Simulate concurrent requests
        $cacheKey = 'test_key_3';
        $callbackCount = 0;
        $expectedValue = ['data' => 'generated_once'];

        $callback = function () use (&$callbackCount, $expectedValue) {
            $callbackCount++;
            // Simulate slow generation
            usleep(50000); // 50ms
            return $expectedValue;
        };

        // Act: Simulate 3 concurrent requests
        $results = [];
        
        // First request starts generation
        $results[] = $this->cacheLock->remember($cacheKey, 60, $callback);
        
        // Second request should find cached value (first request completed)
        $results[] = $this->cacheLock->remember($cacheKey, 60, $callback);
        
        // Third request should also find cached value
        $results[] = $this->cacheLock->remember($cacheKey, 60, $callback);

        // Assert: Callback executed only once
        $this->assertEquals(1, $callbackCount, 'Callback should execute only once');
        $this->assertEquals($expectedValue, $results[0]);
        $this->assertEquals($expectedValue, $results[1]);
        $this->assertEquals($expectedValue, $results[2]);
    }

    /**
     * Test 4: Double-check pattern prevents duplicate generation
     */
    public function test_double_check_prevents_duplicate_generation(): void
    {
        // Arrange: Simulate race condition where cache is filled between lock attempts
        $cacheKey = 'test_key_4';
        $callbackCount = 0;
        $expectedValue = ['data' => 'first_value'];

        $callback = function () use (&$callbackCount, $expectedValue, $cacheKey) {
            $callbackCount++;
            
            // Simulate another process filling cache during generation
            if ($callbackCount === 1) {
                Cache::put($cacheKey, $expectedValue, 60);
            }
            
            return ['data' => 'should_not_be_used'];
        };

        // Act: First request
        $result = $this->cacheLock->remember($cacheKey, 60, $callback);

        // Assert: Double-check found cached value
        $this->assertEquals($expectedValue, $result);
        $this->assertEquals(1, $callbackCount, 'Callback executed but result not used');
    }

    /**
     * Test 5: Lock timeout triggers retry with exponential backoff
     */
    public function test_lock_timeout_triggers_retry_with_backoff(): void
    {
        // Arrange: Acquire lock manually to simulate timeout
        $cacheKey = 'test_key_5';
        $lockKey = "lock:{$cacheKey}";
        $expectedValue = ['data' => 'retry_value'];

        // Hold lock to force retry
        $heldLock = Cache::lock($lockKey, 10);
        $heldLock->get();

        $callbackCount = 0;
        $callback = function () use (&$callbackCount, $expectedValue, $cacheKey, $heldLock) {
            $callbackCount++;
            
            // Release lock after first retry attempt
            if ($callbackCount === 1) {
                $heldLock->release();
                // Put value in cache so retry finds it
                Cache::put($cacheKey, $expectedValue, 60);
            }
            
            return ['data' => 'fallback_value'];
        };

        // Act: Try to get value (will retry)
        $result = $this->cacheLock->remember($cacheKey, 60, $callback);

        // Assert: Retry succeeded and found cached value
        $this->assertEquals($expectedValue, $result);
        $this->assertGreaterThanOrEqual(1, $callbackCount, 'Should attempt callback at least once');
    }

    /**
     * Test 6: Stale-while-revalidate serves old cache during regeneration
     */
    public function test_stale_while_revalidate_serves_old_cache(): void
    {
        // Arrange: Set up stale cache
        $cacheKey = 'test_key_6';
        $staleKey = "{$cacheKey}:stale";
        $staleValue = ['data' => 'stale_value'];
        $freshValue = ['data' => 'fresh_value'];

        // Pre-populate stale cache
        Cache::put($staleKey, $staleValue, 120);

        // Hold lock to simulate slow regeneration
        $lockKey = "lock:{$cacheKey}";
        $heldLock = Cache::lock($lockKey, 10);
        $heldLock->get();

        $callback = function () use ($freshValue) {
            return $freshValue;
        };

        // Act: Try to get value with stale support
        $result = $this->cacheLock->rememberWithStale($cacheKey, 60, $callback);

        // Assert: Returns stale value while regenerating
        $this->assertEquals($staleValue, $result, 'Should serve stale cache when lock held');

        // Cleanup
        $heldLock->release();
    }

    /**
     * Test 7: Max retries exceeded falls back to generating without lock
     */
    public function test_max_retries_exceeded_generates_without_lock(): void
    {
        // Arrange: Hold lock indefinitely
        $cacheKey = 'test_key_7';
        $lockKey = "lock:{$cacheKey}";
        $expectedValue = ['data' => 'fallback_value'];

        $heldLock = Cache::lock($lockKey, 30);
        $heldLock->get();

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted, $expectedValue) {
            $callbackExecuted = true;
            return $expectedValue;
        };

        // Act: Try to get value (will exhaust retries)
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->with('Cache lock: Max retries exceeded, generating without lock', \Mockery::any());

        $result = $this->cacheLock->remember($cacheKey, 60, $callback, 1); // Short timeout

        // Assert: Eventually generates without lock
        $this->assertEquals($expectedValue, $result);
        $this->assertTrue($callbackExecuted, 'Callback should execute as fallback');

        // Cleanup
        $heldLock->release();
    }

    /**
     * Test 8: Lock is always released even on exception
     */
    public function test_lock_released_on_exception(): void
    {
        // Arrange: Callback that throws exception
        $cacheKey = 'test_key_8';
        $lockKey = "lock:{$cacheKey}";

        $callback = function () {
            throw new \RuntimeException('Generation failed');
        };

        // Act & Assert: Exception propagates but lock is released
        try {
            $this->cacheLock->remember($cacheKey, 60, $callback);
            $this->fail('Should throw exception');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Generation failed', $e->getMessage());
        }

        // Verify lock is released by acquiring it
        $testLock = Cache::lock($lockKey, 5);
        $acquired = $testLock->get();
        
        $this->assertTrue($acquired, 'Lock should be released after exception');
        $testLock->release();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
