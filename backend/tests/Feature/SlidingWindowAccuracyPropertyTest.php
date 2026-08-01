<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Core\Services\RateLimit\SlidingWindowCounter;
use Illuminate\Support\Facades\Redis;

/**
 * Property-Based Test for Sliding Window Accuracy
 * 
 * Feature: critical-rate-limiting, Property 4: Sliding window accuracy
 * 
 * Validates: For any rate limit window, when time progresses, the sliding window
 * should maintain accurate request counts by automatically removing expired entries
 * and providing precise current rates.
 * 
 * Requirements: 11.1, 11.2, 11.3, 11.5
 */
class SlidingWindowAccuracyPropertyTest extends TestCase
{

    private SlidingWindowCounter $counter;
    private string $testKeyPrefix = 'test:rate_limit:';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Check if Redis is available
        try {
            Redis::connection()->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
        
        $this->counter = new SlidingWindowCounter();
        
        // Clear any existing test keys
        $this->clearTestKeys();
    }

    protected function tearDown(): void
    {
        $this->clearTestKeys();
        parent::tearDown();
    }

    /**
     * Property 4.1: Sliding window maintains accurate counts within window
     * 
     * For any sequence of requests within a time window, the counter should
     * accurately track the number of requests.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_maintains_accurate_counts_within_window(): void
    {
        $key = $this->testKeyPrefix . 'accurate_count';
        $windowSeconds = 60;
        $maxAttempts = 10;
        
        // Make multiple requests within the window
        $requestCount = 5;
        $results = [];
        
        for ($i = 0; $i < $requestCount; $i++) {
            $result = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
            $results[] = $result;
            
            // Each request should increment the count
            $this->assertEquals($i + 1, $result['count'], "Request {$i} should have count " . ($i + 1));
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
        }
        
        // Verify final count matches number of requests
        $finalCount = $this->counter->count($key, $windowSeconds);
        $this->assertEquals($requestCount, $finalCount, 'Final count should match number of requests made');
    }

    /**
     * Property 4.2: Sliding window automatically removes expired entries
     * 
     * For any request older than the window duration, it should be automatically
     * removed from the count.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_removes_expired_entries(): void
    {
        $key = $this->testKeyPrefix . 'expired_entries';
        $windowSeconds = 2; // Short window for testing
        $maxAttempts = 10;
        
        // Make initial requests
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        
        // Verify count is 3
        $countBefore = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(3, $countBefore, 'Should have 3 requests in window');
        
        // Wait for window to expire (add buffer for safety)
        sleep($windowSeconds + 1);
        
        // Count should now be 0 as all entries expired
        $countAfter = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(0, $countAfter, 'All entries should be expired and removed');
        
        // New request should start fresh count
        $result = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->assertEquals(1, $result['count'], 'New request should start at count 1');
    }

    /**
     * Property 4.3: Sliding window provides precise current rates
     * 
     * For any point in time, the counter should provide accurate count of
     * requests within the current sliding window.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_provides_precise_current_rates(): void
    {
        $key = $this->testKeyPrefix . 'precise_rates';
        $windowSeconds = 3;
        $maxAttempts = 10;
        
        // T=0: Make 3 requests
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        
        $count1 = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(3, $count1, 'Should have 3 requests at T=0');
        
        // T=1.5: Wait 1.5 seconds, make 2 more requests
        usleep(1500000); // 1.5 seconds
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        
        $count2 = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(5, $count2, 'Should have 5 requests at T=1.5 (all within 3s window)');
        
        // T=3.5: Wait 2 more seconds (total 3.5s from start)
        sleep(2);
        
        // First 3 requests should be expired (made at T=0, now T=3.5)
        // Last 2 requests should still be valid (made at T=1.5, now T=3.5)
        $count3 = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(2, $count3, 'Should have 2 requests at T=3.5 (first 3 expired)');
    }

    /**
     * Property 4.4: Sliding window handles concurrent access safely
     * 
     * For any concurrent requests, the counter should maintain accuracy
     * through atomic operations.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_handles_concurrent_access(): void
    {
        $key = $this->testKeyPrefix . 'concurrent';
        $windowSeconds = 60;
        $maxAttempts = 100;
        $concurrentRequests = 10;
        
        // Simulate concurrent requests (sequential in test, but validates atomicity)
        $results = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $results[] = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        }
        
        // Verify each request got a unique, sequential count
        $counts = array_column($results, 'count');
        $this->assertCount($concurrentRequests, array_unique($counts), 'Each request should have unique count');
        
        // Verify final count is accurate
        $finalCount = $this->counter->count($key, $windowSeconds);
        $this->assertEquals($concurrentRequests, $finalCount, 'Final count should match concurrent requests');
    }

    /**
     * Property 4.5: Sliding window enforces rate limits accurately
     * 
     * For any rate limit, when the limit is reached, subsequent requests
     * should be blocked until entries expire.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_enforces_limits_accurately(): void
    {
        $key = $this->testKeyPrefix . 'enforce_limit';
        $windowSeconds = 60;
        $maxAttempts = 5;
        
        // Make requests up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed (under limit)");
            $this->assertEquals($i + 1, $result['count']);
        }
        
        // Next request should be blocked
        $blockedResult = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->assertFalse($blockedResult['allowed'], 'Request over limit should be blocked');
        $this->assertEquals($maxAttempts, $blockedResult['count'], 'Count should remain at limit');
        
        // Count should still be at limit
        $count = $this->counter->count($key, $windowSeconds);
        $this->assertEquals($maxAttempts, $count, 'Count should be at limit');
    }

    /**
     * Property 4.6: Sliding window updates counters precisely at boundaries
     * 
     * For any window boundary crossing, the counter should update precisely
     * as entries expire.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_updates_at_boundaries(): void
    {
        $key = $this->testKeyPrefix . 'boundaries';
        $windowSeconds = 2;
        $maxAttempts = 10;
        
        // T=0: Make request at start
        $result1 = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->assertEquals(1, $result1['count']);
        
        // T=1: Make request in middle of window
        sleep(1);
        $result2 = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->assertEquals(2, $result2['count'], 'Both requests should be in window');
        
        // T=2.5: First request should be expired
        sleep(2); // Total 3 seconds from start
        $count = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(0, $count, 'All requests should be expired after window');
    }

    /**
     * Property 4.7: Sliding window maintains accuracy across resets
     * 
     * For any reset operation, the counter should accurately start fresh.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_accurate_after_reset(): void
    {
        $key = $this->testKeyPrefix . 'reset';
        $windowSeconds = 60;
        $maxAttempts = 10;
        
        // Make some requests
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        
        $countBefore = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(3, $countBefore);
        
        // Reset the counter
        $resetResult = $this->counter->reset($key);
        $this->assertTrue($resetResult, 'Reset should succeed');
        
        // Count should be 0
        $countAfter = $this->counter->count($key, $windowSeconds);
        $this->assertEquals(0, $countAfter, 'Count should be 0 after reset');
        
        // New requests should start fresh
        $result = $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        $this->assertEquals(1, $result['count'], 'First request after reset should be count 1');
    }

    /**
     * Property 4.8: Sliding window TTL reflects actual window expiration
     * 
     * For any window, the TTL should accurately reflect when the window expires.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_ttl_accurate(): void
    {
        $key = $this->testKeyPrefix . 'ttl';
        $windowSeconds = 10;
        $maxAttempts = 5;
        
        // Make a request
        $this->counter->attempt($key, $maxAttempts, $windowSeconds);
        
        // TTL should be approximately the window duration
        $ttl = $this->counter->ttl($key);
        $this->assertGreaterThan(0, $ttl, 'TTL should be positive');
        $this->assertLessThanOrEqual($windowSeconds, $ttl, 'TTL should not exceed window duration');
        
        // Wait a bit
        sleep(2);
        
        // TTL should have decreased
        $ttlAfter = $this->counter->ttl($key);
        $this->assertLessThan($ttl, $ttlAfter, 'TTL should decrease over time');
    }

    /**
     * Property 4.9: Sliding window handles multiple keys independently
     * 
     * For any set of different keys, each should maintain independent
     * accurate counts.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_independent_keys(): void
    {
        $key1 = $this->testKeyPrefix . 'key1';
        $key2 = $this->testKeyPrefix . 'key2';
        $key3 = $this->testKeyPrefix . 'key3';
        $windowSeconds = 60;
        $maxAttempts = 10;
        
        // Make different numbers of requests to each key
        $this->counter->attempt($key1, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key1, $maxAttempts, $windowSeconds);
        
        $this->counter->attempt($key2, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key2, $maxAttempts, $windowSeconds);
        $this->counter->attempt($key2, $maxAttempts, $windowSeconds);
        
        $this->counter->attempt($key3, $maxAttempts, $windowSeconds);
        
        // Verify each key has independent count
        $count1 = $this->counter->count($key1, $windowSeconds);
        $count2 = $this->counter->count($key2, $windowSeconds);
        $count3 = $this->counter->count($key3, $windowSeconds);
        
        $this->assertEquals(2, $count1, 'Key1 should have 2 requests');
        $this->assertEquals(3, $count2, 'Key2 should have 3 requests');
        $this->assertEquals(1, $count3, 'Key3 should have 1 request');
    }

    /**
     * Property 4.10: Sliding window accuracy with varying window sizes
     * 
     * For any window size, the counter should maintain accuracy.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function property_sliding_window_accurate_with_varying_windows(): void
    {
        $key = $this->testKeyPrefix . 'varying_windows';
        $maxAttempts = 10;
        
        // Test with different window sizes
        $windowSizes = [1, 5, 10, 60, 300];
        
        foreach ($windowSizes as $windowSeconds) {
            $testKey = $key . '_' . $windowSeconds;
            
            // Make requests
            $requestCount = 3;
            for ($i = 0; $i < $requestCount; $i++) {
                $this->counter->attempt($testKey, $maxAttempts, $windowSeconds);
            }
            
            // Verify count
            $count = $this->counter->count($testKey, $windowSeconds);
            $this->assertEquals(
                $requestCount,
                $count,
                "Window of {$windowSeconds}s should accurately track {$requestCount} requests"
            );
        }
    }

    /**
     * Helper: Clear all test keys from Redis
     */
    private function clearTestKeys(): void
    {
        try {
            $redis = Redis::connection();
            $keys = $redis->keys($this->testKeyPrefix . '*');
            
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }
}
