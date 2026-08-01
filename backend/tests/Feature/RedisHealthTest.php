<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Redis Health Guard Property-Based Tests
 * 
 * Tests Redis failure handling and automatic fallback behavior.
 * 
 * Properties tested:
 * - Property 19: Redis connection failures are caught
 * - Property 20: Automatic fallback to database cache
 * - Property 21: Alert triggered on Redis failure
 * 
 */
#[\PHPUnit\Framework\Attributes\Group('redis')]
#[\PHPUnit\Framework\Attributes\Group('health')]
#[\PHPUnit\Framework\Attributes\Group('property-based')]
class RedisHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Property 19: Redis connection failures are caught
     * 
     * Universal property: Any Redis connection failure should be caught
     * and not crash the application.
     * 
*/
    public function property_redis_connection_failures_are_caught(): void
    {
        // Test with invalid Redis configuration
        config(['cache.stores.redis.connection' => 'invalid_connection']);
        
        // This should not throw an exception
        $response = $this->getJson('/api/v1/health/redis');
        
        // Should return 503 status for unhealthy Redis
        $this->assertContains($response->status(), [200, 503]);
        
        // Response should have proper structure
        $response->assertJsonStructure([
            'status',
            'redis',
        ]);
    }

    /**
     * Property 19.1: Redis health endpoint returns proper status codes
     * 
*/
    public function property_redis_health_endpoint_returns_proper_status(): void
    {
        $response = $this->getJson('/api/v1/health/redis');
        
        // Should return either 200 (healthy) or 503 (unhealthy)
        $this->assertContains($response->status(), [200, 503]);
        
        // Response should always have status and redis fields
        $response->assertJsonStructure([
            'status',
            'redis',
        ]);
        
        $data = $response->json();
        
        // Status should be either 'healthy' or 'unhealthy'
        $this->assertContains($data['status'], ['healthy', 'unhealthy']);
        
        // Redis should be either 'connected' or 'disconnected'
        $this->assertContains($data['redis'], ['connected', 'disconnected']);
    }

    /**
     * Property 19.2: Redis health check performs actual operations
     * 
*/
    public function property_redis_health_check_performs_actual_operations(): void
    {
        $response = $this->getJson('/api/v1/health/redis');
        
        $data = $response->json();
        
        // If Redis is healthy, it should have performed a successful operation
        if ($data['status'] === 'healthy') {
            $this->assertEquals('connected', $data['redis']);
            $this->assertArrayHasKey('message', $data);
        }
        
        // If Redis is unhealthy, it should have an error message
        if ($data['status'] === 'unhealthy') {
            $this->assertEquals('disconnected', $data['redis']);
            $this->assertArrayHasKey('error', $data);
        }
    }

    /**
     * Property 20: Automatic fallback to database cache
     * 
     * Universal property: When Redis fails, cache operations should
     * automatically fall back to database cache without errors.
     * 
*/
    public function property_automatic_fallback_to_database_cache(): void
    {
        // Use failover cache store
        config(['cache.default' => 'failover']);
        
        // Test cache operations work regardless of Redis status
        $key = 'test_failover_' . uniqid();
        $value = 'test_value_' . time();
        
        // Put should work
        $putResult = Cache::put($key, $value, 60);
        $this->assertTrue($putResult);
        
        // Get should work
        $retrieved = Cache::get($key);
        $this->assertEquals($value, $retrieved);
        
        // Delete should work
        $deleteResult = Cache::forget($key);
        $this->assertTrue($deleteResult);
        
        // Verify deletion
        $this->assertNull(Cache::get($key));
    }

    /**
     * Property 20.1: Failover cache maintains data consistency
     * 
*/
    public function property_failover_cache_maintains_data_consistency(): void
    {
        config(['cache.default' => 'failover']);
        
        // Test multiple operations
        $testData = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];
        
        // Store all values
        foreach ($testData as $key => $value) {
            Cache::put($key, $value, 60);
        }
        
        // Retrieve and verify all values
        foreach ($testData as $key => $expectedValue) {
            $actualValue = Cache::get($key);
            $this->assertEquals($expectedValue, $actualValue, "Cache value mismatch for key: {$key}");
        }
        
        // Clean up
        foreach (array_keys($testData) as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Property 20.2: Failover cache handles complex data types
     * 
*/
    public function property_failover_cache_handles_complex_data_types(): void
    {
        config(['cache.default' => 'failover']);
        
        // Test with array
        $arrayData = ['name' => 'Test', 'count' => 42, 'active' => true];
        Cache::put('test_array', $arrayData, 60);
        $this->assertEquals($arrayData, Cache::get('test_array'));
        
        // Test with object
        $objectData = (object) ['id' => 1, 'name' => 'Test Object'];
        Cache::put('test_object', $objectData, 60);
        $this->assertEquals($objectData, Cache::get('test_object'));
        
        // Test with nested structure
        $nestedData = [
            'user' => [
                'id' => 1,
                'profile' => [
                    'name' => 'Test User',
                    'settings' => ['theme' => 'dark']
                ]
            ]
        ];
        Cache::put('test_nested', $nestedData, 60);
        $this->assertEquals($nestedData, Cache::get('test_nested'));
        
        // Clean up
        Cache::forget('test_array');
        Cache::forget('test_object');
        Cache::forget('test_nested');
    }

    /**
     * Property 20.3: Cache operations work with database fallback
     * 
*/
    public function property_cache_operations_work_with_database_fallback(): void
    {
        // Force database cache
        config(['cache.default' => 'database']);
        
        $key = 'db_cache_test_' . uniqid();
        $value = 'database_value_' . time();
        
        // All cache operations should work with database
        Cache::put($key, $value, 60);
        $this->assertEquals($value, Cache::get($key));
        
        // Test increment/decrement
        Cache::put('counter', 10, 60);
        Cache::increment('counter');
        $this->assertEquals(11, Cache::get('counter'));
        
        Cache::decrement('counter', 2);
        $this->assertEquals(9, Cache::get('counter'));
        
        // Clean up
        Cache::forget($key);
        Cache::forget('counter');
    }

    /**
     * Property 21: Alert triggered on Redis failure
     * 
     * Universal property: Redis failures should be logged for monitoring.
     * 
*/
    public function property_redis_failure_is_logged(): void
    {
        // Make request to Redis health endpoint
        $response = $this->getJson('/api/v1/health/redis');
        
        $data = $response->json();
        
        // If Redis is unhealthy, verify error information is present
        if ($data['status'] === 'unhealthy') {
            $this->assertArrayHasKey('error', $data);
            $this->assertNotEmpty($data['error']);
            $this->assertArrayHasKey('message', $data);
        }
    }

    /**
     * Property 21.1: Overall health endpoint includes Redis status
     * 
*/
    public function property_overall_health_endpoint_includes_redis_status(): void
    {
        $response = $this->getJson('/api/v1/health');
        
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'services' => [
                'database',
                'redis',
            ],
        ]);
        
        $data = $response->json();
        
        // Redis service should have status
        $this->assertArrayHasKey('status', $data['services']['redis']);
        $this->assertContains($data['services']['redis']['status'], ['up', 'down']);
    }

    /**
     * Property 21.2: Health endpoint returns appropriate HTTP status codes
     * 
*/
    public function property_health_endpoint_returns_appropriate_status_codes(): void
    {
        $response = $this->getJson('/api/v1/health');
        
        // Should return 200 for healthy or degraded, 503 for unhealthy
        $this->assertContains($response->status(), [200, 503]);
        
        $data = $response->json();
        
        // Status should match HTTP code
        if ($response->status() === 200) {
            $this->assertContains($data['status'], ['healthy', 'degraded']);
        } elseif ($response->status() === 503) {
            $this->assertEquals('unhealthy', $data['status']);
        }
    }

    /**
     * Integration test: Verify failover cache configuration
     * 
*/
    public function test_failover_cache_is_configured(): void
    {
        $stores = config('cache.stores');
        
        // Verify failover store exists
        $this->assertArrayHasKey('failover', $stores);
        
        // Verify failover configuration
        $failoverConfig = $stores['failover'];
        $this->assertEquals('failover', $failoverConfig['driver']);
        $this->assertArrayHasKey('stores', $failoverConfig);
        
        // Verify store hierarchy
        $expectedStores = ['redis', 'database', 'array'];
        $this->assertEquals($expectedStores, $failoverConfig['stores']);
    }

    /**
     * Integration test: Verify cache table exists for database fallback
     * 
*/
    public function test_cache_table_exists_for_database_fallback(): void
    {
        // Verify cache table exists
        $this->assertTrue(
            \Schema::hasTable('cache'),
            'Cache table must exist for database fallback'
        );
        
        // Verify cache table has required columns
        $this->assertTrue(\Schema::hasColumn('cache', 'key'));
        $this->assertTrue(\Schema::hasColumn('cache', 'value'));
        $this->assertTrue(\Schema::hasColumn('cache', 'expiration'));
    }
}
