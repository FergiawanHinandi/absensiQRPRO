<?php

namespace Tests\Feature;

use App\Services\SafeRedisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedisHealthCheckTest extends TestCase
{
    /**
     * Test Redis health check returns 200 when Redis is healthy
     */
    public function test_redis_health_check_returns_healthy_when_redis_is_available(): void
    {
        $response = $this->getJson('/api/v1/health/redis');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'healthy',
                'redis' => 'connected',
            ])
            ->assertJsonStructure([
                'status',
                'redis',
                'circuit_breaker' => [
                    'state',
                    'failure_count',
                ],
                'message',
                'timestamp',
            ]);
    }

    /**
     * Test Redis health check returns 503 when circuit breaker is open
     */
    public function test_redis_health_check_returns_unhealthy_when_circuit_breaker_is_open(): void
    {
        // Mock SafeRedisService to simulate circuit breaker open state
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'open',
                'failure_count' => 5,
                'time_since_last_failure' => 10,
                'is_available' => false,
            ]);

        $response = $this->getJson('/api/v1/health/redis');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'redis' => 'disconnected',
                'circuit_breaker' => [
                    'state' => 'open',
                    'failure_count' => 5,
                ],
            ])
            ->assertJsonFragment([
                'error' => 'Circuit breaker triggered due to repeated failures',
            ]);
    }

    /**
     * Test Redis health check handles connection failures gracefully
     */
    public function test_redis_health_check_handles_connection_failure(): void
    {
        // Mock SafeRedisService to simulate connection failure
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'closed',
                'failure_count' => 0,
                'is_available' => true,
            ]);
        
        $mockRedis->shouldReceive('set')
            ->once()
            ->andThrow(new \Exception('Connection refused'));

        $response = $this->getJson('/api/v1/health/redis');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'redis' => 'disconnected',
            ])
            ->assertJsonStructure([
                'status',
                'redis',
                'error',
                'message',
                'timestamp',
            ]);
    }

    /**
     * Test Redis health check verifies read/write operations
     */
    public function test_redis_health_check_verifies_read_write_operations(): void
    {
        // Mock SafeRedisService to simulate successful operations
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'closed',
                'failure_count' => 0,
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
                'message' => 'Redis is operational',
            ]);
    }

    /**
     * Test Redis health check fails when read/write verification fails
     */
    public function test_redis_health_check_fails_when_verification_fails(): void
    {
        // Mock SafeRedisService to simulate verification failure
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'closed',
                'failure_count' => 0,
                'is_available' => true,
            ]);
        
        $mockRedis->shouldReceive('set')
            ->once()
            ->andReturn(true);
        
        // Return wrong value to simulate verification failure
        $mockRedis->shouldReceive('get')
            ->once()
            ->andReturn('wrong_value');

        $response = $this->getJson('/api/v1/health/redis');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'redis' => 'disconnected',
            ])
            ->assertJsonFragment([
                'error' => 'Redis read/write verification failed',
            ]);
    }

    /**
     * Test Redis health check returns proper structure for half-open circuit
     */
    public function test_redis_health_check_handles_half_open_circuit(): void
    {
        // Mock SafeRedisService for half-open state
        $mockRedis = $this->mock(SafeRedisService::class);
        
        $mockRedis->shouldReceive('getCircuitStatus')
            ->once()
            ->andReturn([
                'state' => 'half_open',
                'failure_count' => 3,
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
                    'failure_count' => 3,
                ],
            ]);
    }

    /**
     * Test health check endpoint is publicly accessible (no auth required)
     */
    public function test_redis_health_check_is_publicly_accessible(): void
    {
        // Don't authenticate - health checks should be public
        $response = $this->getJson('/api/v1/health/redis');

        // Should not return 401 Unauthorized
        $this->assertNotEquals(401, $response->status());
        
        // Should return either 200 or 503 depending on Redis state
        $this->assertContains($response->status(), [200, 503]);
    }
}
