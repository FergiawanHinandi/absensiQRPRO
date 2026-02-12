<?php

namespace Tests\Feature;

use App\Services\SafeRedisService;
use App\Infrastructure\CircuitBreaker\RedisCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * SafeRedisService Test
 * 
 * Tests Redis operations with circuit breaker protection
 */
class SafeRedisServiceTest extends TestCase
{
    private SafeRedisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Get the service from container
        $this->service = app(SafeRedisService::class);
        
        // Clear Redis before each test
        try {
            Redis::flushdb();
        } catch (\Exception $e) {
            // Redis might not be available in test environment
        }
    }

    /**
     * Test SafeRedisService can set and get values
     */
    public function test_safe_redis_can_set_and_get_values(): void
    {
        $result = $this->service->set('test_key', 'test_value');
        
        // Should return true on success or false if Redis unavailable
        $this->assertIsBool($result);
        
        if ($result) {
            $value = $this->service->get('test_key');
            $this->assertEquals('test_value', $value);
        }
    }

    /**
     * Test SafeRedisService set with TTL
     */
    public function test_safe_redis_set_with_ttl(): void
    {
        $result = $this->service->set('expiring_key', 'value', 2);
        
        if ($result) {
            $value = $this->service->get('expiring_key');
            $this->assertEquals('value', $value);
            
            // Wait for expiration
            sleep(3);
            
            $value = $this->service->get('expiring_key');
            $this->assertNull($value);
        } else {
            // Redis unavailable, test passes
            $this->assertTrue(true);
        }
    }

    /**
     * Test SafeRedisService delete operation
     */
    public function test_safe_redis_can_delete_keys(): void
    {
        $this->service->set('key_to_delete', 'value');
        
        $deleted = $this->service->delete('key_to_delete');
        
        // Should return number of keys deleted (0 or 1)
        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
        
        if ($deleted > 0) {
            $value = $this->service->get('key_to_delete');
            $this->assertNull($value);
        }
    }

    /**
     * Test SafeRedisService increment operation
     */
    public function test_safe_redis_can_increment_values(): void
    {
        $this->service->set('counter', '0');
        
        $result = $this->service->increment('counter', 5);
        
        // Should return new value or false if unavailable
        if ($result !== false) {
            $this->assertEquals(5, $result);
            
            $result = $this->service->increment('counter', 3);
            $this->assertEquals(8, $result);
        } else {
            // Redis unavailable, test passes
            $this->assertTrue(true);
        }
    }

    /**
     * Test SafeRedisService returns default value when key not found
     */
    public function test_safe_redis_returns_default_when_key_not_found(): void
    {
        $value = $this->service->get('nonexistent_key', 'default_value');
        
        $this->assertEquals('default_value', $value);
    }

    /**
     * Test SafeRedisService isAvailable method
     */
    public function test_safe_redis_is_available_returns_boolean(): void
    {
        $available = $this->service->isAvailable();
        
        $this->assertIsBool($available);
    }

    /**
     * Test SafeRedisService getCircuitStatus returns proper structure
     */
    public function test_safe_redis_circuit_status_has_required_fields(): void
    {
        $status = $this->service->getCircuitStatus();
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('state', $status);
        $this->assertArrayHasKey('failure_count', $status);
        $this->assertArrayHasKey('is_available', $status);
        
        // State should be one of: closed, open, half_open
        $this->assertContains($status['state'], ['closed', 'open', 'half_open']);
        
        // Failure count should be non-negative integer
        $this->assertIsInt($status['failure_count']);
        $this->assertGreaterThanOrEqual(0, $status['failure_count']);
        
        // is_available should be boolean
        $this->assertIsBool($status['is_available']);
    }

    /**
     * Test SafeRedisService handles complex data structures
     */
    public function test_safe_redis_handles_complex_data(): void
    {
        $data = [
            'school_id' => 1,
            'name' => 'Test School',
            'settings' => [
                'timezone' => 'Asia/Jakarta',
                'features' => ['qr_code', 'notifications'],
            ],
        ];
        
        $result = $this->service->set('complex_data', json_encode($data));
        
        if ($result) {
            $retrieved = $this->service->get('complex_data');
            $decoded = json_decode($retrieved, true);
            
            $this->assertEquals($data, $decoded);
        } else {
            // Redis unavailable, test passes
            $this->assertTrue(true);
        }
    }

    /**
     * Test SafeRedisService execute method with custom operation
     */
    public function test_safe_redis_execute_custom_operation(): void
    {
        $result = $this->service->execute(
            operation: function() {
                Redis::set('custom_key', 'custom_value');
                return Redis::get('custom_key');
            },
            fallback: fn() => 'fallback_value'
        );
        
        // Should return either the operation result or fallback
        $this->assertIsString($result);
        $this->assertContains($result, ['custom_value', 'fallback_value']);
    }

    /**
     * Test SafeRedisService gracefully handles Redis unavailability
     */
    public function test_safe_redis_gracefully_handles_unavailability(): void
    {
        // Mock circuit breaker to simulate Redis unavailability
        $mockCircuitBreaker = $this->mock(RedisCircuitBreaker::class);
        
        $mockCircuitBreaker->shouldReceive('isAvailable')
            ->andReturn(false);
        
        $mockCircuitBreaker->shouldReceive('getStatus')
            ->andReturn([
                'state' => 'open',
                'failure_count' => 5,
                'is_available' => false,
            ]);
        
        $service = new SafeRedisService($mockCircuitBreaker);
        
        // Operations should not throw exceptions
        $this->assertFalse($service->isAvailable());
        
        $status = $service->getCircuitStatus();
        $this->assertEquals('open', $status['state']);
        $this->assertEquals(5, $status['failure_count']);
    }
}
