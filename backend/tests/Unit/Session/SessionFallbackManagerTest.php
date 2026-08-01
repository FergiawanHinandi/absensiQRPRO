<?php

namespace Tests\Unit\Session;

use PHPUnit\Framework\Attributes\Test;

use App\Services\Session\SessionFallbackManager;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Session Fallback Manager Unit Tests
 * 
 * Tests the automatic fallback logic and health monitoring.
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class SessionFallbackManagerTest extends TestCase
{
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = new SessionFallbackManager('session', 30);
    }


    #[Test]
    public function it_reports_redis_as_healthy_when_ping_succeeds()
    {
        Redis::shouldReceive('connection')
            ->with('session')
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->andReturn('PONG');
        
        $isHealthy = $this->manager->isRedisHealthy();
        
        $this->assertTrue($isHealthy);
    }


    #[Test]
    public function it_reports_redis_as_unhealthy_when_ping_fails()
    {
        // Create a new manager to avoid cache from previous test
        $manager = new SessionFallbackManager('session', 0); // 0 interval = no caching
        
        Redis::shouldReceive('connection')
            ->with('session')
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->andThrow(new \Exception('Connection refused'));
        
        $isHealthy = $manager->isRedisHealthy();
        
        $this->assertFalse($isHealthy);
    }


    #[Test]
    public function it_caches_health_check_results()
    {
        // Create manager with 60 second cache interval
        $manager = new SessionFallbackManager('session', 60);
        
        Redis::shouldReceive('connection')
            ->with('session')
            ->twice() // Called twice but second call uses cache
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->once() // Only called once due to caching
            ->andReturn('PONG');
        
        // First call should check Redis
        $result1 = $manager->isRedisHealthy();
        
        // Second call should use cached result (no additional Redis call)
        $result2 = $manager->isRedisHealthy();
        
        $this->assertTrue($result1);
        $this->assertTrue($result2);
    }


    #[Test]
    public function it_provides_storage_status()
    {
        Redis::shouldReceive('connection')
            ->with('session')
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->andReturn('PONG');
        
        $status = $this->manager->getStorageStatus();
        
        $this->assertIsArray($status);
        $this->assertEquals('redis', $status['primary_backend']);
        $this->assertEquals('database', $status['fallback_backend']);
        $this->assertEquals('redis', $status['current_backend']);
        $this->assertTrue($status['redis_healthy']);
    }


    #[Test]
    public function it_indicates_fallback_when_redis_is_unhealthy()
    {
        // Create a new manager to avoid cache
        $manager = new SessionFallbackManager('session', 0);
        
        Redis::shouldReceive('connection')
            ->with('session')
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->andThrow(new \Exception('Connection failed'));
        
        $status = $manager->getStorageStatus();
        
        $this->assertEquals('database', $status['current_backend']);
        $this->assertFalse($status['redis_healthy']);
    }


    #[Test]
    public function it_provides_health_metrics()
    {
        Redis::shouldReceive('connection')
            ->with('session')
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->andReturn('PONG');
        
        $metrics = $this->manager->getHealthMetrics();
        
        $this->assertIsArray($metrics);
        $this->assertEquals(1, $metrics['redis_available']);
        $this->assertEquals(0, $metrics['using_fallback']);
        $this->assertArrayHasKey('last_check_timestamp', $metrics);
    }


    #[Test]
    public function it_forces_health_check_on_demand()
    {
        // Create manager with long cache interval
        $manager = new SessionFallbackManager('session', 3600);
        
        Redis::shouldReceive('connection')
            ->with('session')
            ->times(3) // Once for initial check, twice for forced checks
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->times(2) // Called twice - once initial, once forced
            ->andReturn('PONG');
        
        // Initial check
        $manager->isRedisHealthy();
        
        // Force another check (should not use cache)
        $result = $manager->forceHealthCheck();
        
        $this->assertTrue($result);
    }

    /**
     * Test fallback activation when Redis becomes unavailable
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_activates_fallback_when_redis_becomes_unavailable()
    {
        // Create manager with no caching for this test
        $manager = new SessionFallbackManager('session', 0);
        
        // Initially Redis is healthy
        Redis::shouldReceive('connection')
            ->with('session')
            ->twice() // Called for both health check and status
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->once()
            ->andReturn('PONG');
        
        $this->assertTrue($manager->isRedisHealthy());
        $status = $manager->getStorageStatus();
        $this->assertEquals('redis', $status['current_backend']);
        
        // Redis becomes unavailable
        Redis::shouldReceive('connection')
            ->with('session')
            ->twice() // Called for both health check and status
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->once()
            ->andThrow(new \Exception('Connection refused'));
        
        // Check health again to detect failure
        $this->assertFalse($manager->isRedisHealthy());
        
        // Verify fallback is activated
        $status = $manager->getStorageStatus();
        $this->assertEquals('database', $status['current_backend']);
        $this->assertFalse($status['redis_healthy']);
    }

    /**
     * Test automatic recovery when Redis becomes available again
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_recovers_automatically_when_redis_becomes_available()
    {
        // Create manager with no caching
        $manager = new SessionFallbackManager('session', 0);
        
        // Redis is initially unavailable
        Redis::shouldReceive('connection')
            ->with('session')
            ->twice() // Called for both health check and status
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->once()
            ->andThrow(new \Exception('Connection refused'));
        
        $this->assertFalse($manager->isRedisHealthy());
        $status = $manager->getStorageStatus();
        $this->assertEquals('database', $status['current_backend']);
        
        // Redis becomes available again
        Redis::shouldReceive('connection')
            ->with('session')
            ->twice() // Called for both health check and status
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->once()
            ->andReturn('PONG');
        
        // Check health again to detect recovery
        $this->assertTrue($manager->isRedisHealthy());
        
        // Verify Redis is used again
        $status = $manager->getStorageStatus();
        $this->assertEquals('redis', $status['current_backend']);
        $this->assertTrue($status['redis_healthy']);
    }

    /**
     * Test health check interval prevents excessive Redis calls
     * Validates: Requirements 4.3
     * 
*/
    public function it_respects_health_check_interval()
    {
        // Create manager with 60 second interval
        $manager = new SessionFallbackManager('session', 60);
        
        Redis::shouldReceive('connection')
            ->with('session')
            ->twice() // Called twice but second and third calls use cache
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->once() // Only called once due to caching
            ->andReturn('PONG');
        
        // Multiple checks within interval should use cached result
        $manager->isRedisHealthy();
        $manager->isRedisHealthy();
        $manager->isRedisHealthy();
        
        $this->assertTrue(true); // If we get here, caching worked
    }

    /**
     * Test metrics reflect fallback state accurately
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_provides_accurate_metrics_during_fallback()
    {
        // Create manager with no caching
        $manager = new SessionFallbackManager('session', 0);
        
        Redis::shouldReceive('connection')
            ->with('session')
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->andThrow(new \Exception('Redis unavailable'));
        
        $metrics = $manager->getHealthMetrics();
        
        $this->assertEquals(0, $metrics['redis_available']);
        $this->assertEquals(1, $metrics['using_fallback']);
        $this->assertIsInt($metrics['last_check_timestamp']);
    }

    /**
     * Test multiple consecutive failures maintain fallback state
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_maintains_fallback_state_across_multiple_failures()
    {
        Redis::shouldReceive('connection')
            ->with('session')
            ->times(3)
            ->andReturnSelf();
        
        Redis::shouldReceive('ping')
            ->times(3)
            ->andThrow(new \Exception('Connection failed'));
        
        // Multiple health checks should all report unhealthy
        $this->assertFalse($this->manager->forceHealthCheck());
        $this->assertFalse($this->manager->forceHealthCheck());
        $this->assertFalse($this->manager->forceHealthCheck());
        
        // Verify fallback is consistently used
        $status = $this->manager->getStorageStatus();
        $this->assertEquals('database', $status['current_backend']);
    }

    /**
     * Test different Redis error types trigger fallback
     * Validates: Requirements 2.2, 4.3
     * 
*/
    public function it_handles_various_redis_error_types()
    {
        $errorTypes = [
            new \Exception('Connection timeout'),
            new \Exception('Network unreachable'),
            new \RuntimeException('Redis server not responding'),
        ];
        
        foreach ($errorTypes as $error) {
            $manager = new SessionFallbackManager('session', 0); // No caching
            
            Redis::shouldReceive('connection')
                ->with('session')
                ->andReturnSelf();
            
            Redis::shouldReceive('ping')
                ->andThrow($error);
            
            $this->assertFalse($manager->isRedisHealthy());
            $status = $manager->getStorageStatus();
            $this->assertEquals('database', $status['current_backend']);
        }
    }
}
