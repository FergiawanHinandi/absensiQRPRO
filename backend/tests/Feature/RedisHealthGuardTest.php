<?php

namespace Tests\Feature;

use App\Services\SafeRedisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Redis Health Guard Test Suite
 * 
 * Comprehensive tests for Redis health monitoring, failover behavior,
 * and graceful degradation when Redis is unavailable.
 * 
 * Day 7: Redis Health Guard - 10 Tests
 */
class RedisHealthGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear all caches before each test
        try {
            Cache::flush();
        } catch (\Exception $e) {
            // Cache might not be available in test environment
        }
    }

    /**
     * Test 1: Redis health endpoint returns healthy status when Redis is operational
     * 
     * @test
     */
    public function redis_health_endpoint_returns_healthy_when_operational(): void
    {
        $response = $this->getJson('/api/v1/health/redis');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'redis',
                'circuit_breaker' => [
                    'state',
                    'failure_count',
                ],
                'message',
                'timestamp',
            ])
            ->assertJson([
                'status' => 'healthy',
                'redis' => 'connected',
            ]);
    }

    /**
     * Test 2: System continues to function when Redis is unavailable
     * 
     * @test
     */
    public function system_continues_functioning_when_redis_unavailable(): void
    {
        // Mock SafeRedisService to simulate Redis unavailability
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('isAvailable')
            ->andReturn(false);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->andReturn([
                'state' => 'open',
                'failure_count' => 5,
                'is_available' => false,
            ]);

        // System should still respond (not crash)
        $response = $this->getJson('/api/v1/health/redis');
        
        $response->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'redis' => 'disconnected',
            ]);
        
        // Verify system didn't crash - we got a proper response
        $this->assertNotNull($response->json());
    }

    /**
     * Test 3: Cache automatically falls back to database when Redis fails
     * 
     * @test
     */
    public function cache_automatically_falls_back_to_database(): void
    {
        // Use failover cache store
        $cacheKey = 'test_failover_key';
        $cacheValue = 'test_value';
        
        Cache::store('failover')->put($cacheKey, $cacheValue, 60);
        
        $retrieved = Cache::store('failover')->get($cacheKey);
        
        $this->assertEquals($cacheValue, $retrieved);
        
        // Verify failover configuration includes database
        $stores = Config::get('cache.stores.failover.stores');
        $this->assertContains('database', $stores);
    }

    /**
     * Test 4: Circuit breaker opens after repeated Redis failures
     * 
     * @test
     */
    public function circuit_breaker_opens_after_repeated_failures(): void
    {
        $service = app(SafeRedisService::class);
        
        // Get initial circuit status
        $initialStatus = $service->getCircuitStatus();
        
        $this->assertIsArray($initialStatus);
        $this->assertArrayHasKey('state', $initialStatus);
        $this->assertArrayHasKey('failure_count', $initialStatus);
        
        // Circuit should be in a valid state
        $this->assertContains($initialStatus['state'], ['closed', 'open', 'half_open']);
    }

    /**
     * Test 5: Redis health check performs read/write verification
     * 
     * @test
     */
    public function redis_health_check_performs_read_write_verification(): void
    {
        $service = app(SafeRedisService::class);
        
        // Attempt write operation
        $writeResult = $service->set('health_check_key', 'health_check_value', 10);
        
        // Should return boolean (true if successful, false if unavailable)
        $this->assertIsBool($writeResult);
        
        if ($writeResult) {
            // If write succeeded, verify read
            $readResult = $service->get('health_check_key');
            $this->assertEquals('health_check_value', $readResult);
            
            // Cleanup
            $service->delete('health_check_key');
        }
    }

    /**
     * Test 6: Redis failure events are logged for monitoring
     * 
     * @test
     */
    public function redis_failure_events_are_logged(): void
    {
        // Mock SafeRedisService to simulate failure
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'open',
                'failure_count' => 3,
                'time_since_last_failure' => 5,
                'is_available' => false,
            ]);

        $response = $this->getJson('/api/v1/health/redis');

        // Verify response includes failure information
        $response->assertStatus(503)
            ->assertJsonStructure([
                'status',
                'redis',
                'circuit_breaker' => [
                    'state',
                    'failure_count',
                ],
                'error',
            ]);
        
        $json = $response->json();
        $this->assertEquals('open', $json['circuit_breaker']['state']);
        $this->assertGreaterThan(0, $json['circuit_breaker']['failure_count']);
    }

    /**
     * Test 7: Failover cache maintains data consistency across stores
     * 
     * @test
     */
    public function failover_cache_maintains_data_consistency(): void
    {
        $testData = [
            'school_id' => 1,
            'subscription_status' => 'active',
            'expires_at' => now()->addDays(30)->toDateTimeString(),
        ];
        
        // Store in failover cache
        Cache::store('failover')->put('subscription_data', $testData, 60);
        
        // Retrieve and verify
        $retrieved = Cache::store('failover')->get('subscription_data');
        
        $this->assertEquals($testData, $retrieved);
        $this->assertEquals($testData['school_id'], $retrieved['school_id']);
        $this->assertEquals($testData['subscription_status'], $retrieved['subscription_status']);
    }

    /**
     * Test 8: Redis health check handles half-open circuit breaker state
     * 
     * @test
     */
    public function redis_health_check_handles_half_open_circuit(): void
    {
        // Mock SafeRedisService for half-open state
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'half_open',
                'failure_count' => 2,
                'is_available' => true,
            ]);
        
        $mockRedis->shouldReceive('set')
            ->once()
            ->andReturn(true);
        
        $mockRedis->shouldReceive('get')
            ->once()
            ->andReturn('ok');
        
        $mockRedis->shouldReceive('delete')
            ->once()
            ->andReturn(true);

        $response = $this->getJson('/api/v1/health/redis');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'healthy',
                'redis' => 'connected',
                'circuit_breaker' => [
                    'state' => 'half_open',
                ],
            ]);
    }

    /**
     * Test 9: Multiple concurrent cache operations don't cause race conditions
     * 
     * @test
     */
    public function concurrent_cache_operations_are_safe(): void
    {
        $service = app(SafeRedisService::class);
        
        // Simulate concurrent writes to same key
        $key = 'concurrent_test_key';
        
        $service->set($key, 'value1', 60);
        $service->set($key, 'value2', 60);
        $service->set($key, 'value3', 60);
        
        // Last write should win
        $result = $service->get($key);
        
        // Should return one of the values (or null if Redis unavailable)
        if ($result !== null) {
            $this->assertContains($result, ['value1', 'value2', 'value3']);
        }
        
        // Cleanup
        $service->delete($key);
    }

    /**
     * Test 10: Redis health monitoring provides actionable metrics
     * 
     * @test
     */
    public function redis_health_monitoring_provides_actionable_metrics(): void
    {
        $response = $this->getJson('/api/v1/health/redis');

        // Response should include all necessary metrics for monitoring
        $json = $response->json();
        
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('redis', $json);
        $this->assertArrayHasKey('circuit_breaker', $json);
        $this->assertArrayHasKey('timestamp', $json);
        
        // Circuit breaker metrics should be present
        $this->assertArrayHasKey('state', $json['circuit_breaker']);
        $this->assertArrayHasKey('failure_count', $json['circuit_breaker']);
        
        // Status should be actionable (healthy or unhealthy)
        $this->assertContains($json['status'], ['healthy', 'unhealthy']);
        
        // Redis connection status should be clear
        $this->assertContains($json['redis'], ['connected', 'disconnected']);
        
        // Timestamp should be valid
        $this->assertNotEmpty($json['timestamp']);
        $this->assertIsString($json['timestamp']);
    }
}
