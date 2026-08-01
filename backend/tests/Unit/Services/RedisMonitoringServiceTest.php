<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;

use App\Infrastructure\CircuitBreaker\RedisCircuitBreaker;
use App\Services\RedisMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Mockery;

class RedisMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private RedisMonitoringService $service;
    private $circuitBreakerMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->circuitBreakerMock = Mockery::mock(RedisCircuitBreaker::class);
        $this->service = new RedisMonitoringService($this->circuitBreakerMock);
        
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }


    #[Test]
    public function it_checks_health_successfully_when_redis_is_available()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 10485760, // 10MB
                'maxmemory' => 104857600,  // 100MB
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $health = $this->service->checkHealth();

        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('connection', $health);
        $this->assertArrayHasKey('memory', $health);
        $this->assertArrayHasKey('circuit_breaker', $health);
        $this->assertArrayHasKey('alerts', $health);

        $this->assertTrue($health['connection']['is_connected']);
        $this->assertEquals('healthy', $health['connection']['status']);
        $this->assertEmpty($health['alerts']);
    }


    #[Test]
    public function it_detects_connection_failure()
    {
        Redis::shouldReceive('ping')
            ->once()
            ->andThrow(new \Exception('Connection refused'));

        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 10485760,
                'maxmemory' => 104857600,
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        Log::shouldReceive('error')
            ->once()
            ->with('redis_connection_failure_alert', Mockery::type('array'));

        $health = $this->service->checkHealth();

        $this->assertFalse($health['connection']['is_connected']);
        $this->assertEquals('unhealthy', $health['connection']['status']);
        $this->assertContains('redis_connection_failed', $health['alerts']);
    }


    #[Test]
    public function it_detects_high_memory_usage()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 85000000,  // 85MB
                'maxmemory' => 100000000,   // 100MB (85% usage)
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        Log::shouldReceive('warning')
            ->once()
            ->with('redis_high_memory_alert', Mockery::type('array'));

        $health = $this->service->checkHealth();

        $this->assertEquals('warning', $health['memory']['status']);
        $this->assertGreaterThan(80, $health['memory']['usage_percent']);
        $this->assertContains('redis_high_memory', $health['alerts']);
    }


    #[Test]
    public function it_detects_circuit_breaker_open()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 10485760,
                'maxmemory' => 104857600,
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'open', 'failures' => 5]);

        Log::shouldReceive('error')
            ->once()
            ->with('redis_circuit_breaker_open_alert', Mockery::type('array'));

        $health = $this->service->checkHealth();

        $this->assertEquals('open', $health['circuit_breaker']['state']);
        $this->assertContains('redis_circuit_breaker_open', $health['alerts']);
    }


    #[Test]
    public function it_handles_unlimited_memory_configuration()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 10485760,
                'maxmemory' => 0, // Unlimited
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $health = $this->service->checkHealth();

        $this->assertEquals('unlimited', $health['memory']['max_memory_mb']);
        $this->assertEquals(0, $health['memory']['usage_percent']);
        $this->assertEquals('ok', $health['memory']['status']);
    }


    #[Test]
    public function it_respects_alert_cooldown_period()
    {
        // First alert should be sent
        Redis::shouldReceive('ping')
            ->once()
            ->andThrow(new \Exception('Connection refused'));

        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn(['used_memory' => 10485760, 'maxmemory' => 104857600]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        Log::shouldReceive('error')
            ->once()
            ->with('redis_connection_failure_alert', Mockery::type('array'));

        $this->service->checkHealth();

        // Second check should not send alert (cooldown active)
        Redis::shouldReceive('ping')
            ->once()
            ->andThrow(new \Exception('Connection refused'));

        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn(['used_memory' => 10485760, 'maxmemory' => 104857600]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        // Log should NOT be called again
        Log::shouldReceive('error')->never();

        $health = $this->service->checkHealth();
        $this->assertContains('redis_connection_failed', $health['alerts']);
    }


    #[Test]
    public function it_measures_connection_response_time()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn(['used_memory' => 10485760, 'maxmemory' => 104857600]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $health = $this->service->checkHealth();

        $this->assertArrayHasKey('response_time_ms', $health['connection']);
        $this->assertIsNumeric($health['connection']['response_time_ms']);
        $this->assertGreaterThanOrEqual(0, $health['connection']['response_time_ms']);
    }


    #[Test]
    public function it_gets_metrics_with_uptime()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn(['used_memory' => 10485760, 'maxmemory' => 104857600]);

        Redis::shouldReceive('info')
            ->with('server')
            ->once()
            ->andReturn([
                'uptime_in_seconds' => 86400,
                'uptime_in_days' => 1,
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->twice()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $metrics = $this->service->getMetrics();

        $this->assertArrayHasKey('health', $metrics);
        $this->assertArrayHasKey('circuit_breaker', $metrics);
        $this->assertArrayHasKey('uptime', $metrics);
        $this->assertEquals(86400, $metrics['uptime']['uptime_seconds']);
        $this->assertEquals(1, $metrics['uptime']['uptime_days']);
    }


    #[Test]
    public function it_handles_memory_info_errors_gracefully()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andThrow(new \Exception('Memory info unavailable'));

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $health = $this->service->checkHealth();

        $this->assertEquals('unavailable', $health['memory']['status']);
        $this->assertArrayHasKey('error', $health['memory']);
    }


    #[Test]
    public function it_handles_uptime_query_errors_gracefully()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn(['used_memory' => 10485760, 'maxmemory' => 104857600]);

        Redis::shouldReceive('info')
            ->with('server')
            ->once()
            ->andThrow(new \Exception('Server info unavailable'));

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->twice()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $metrics = $this->service->getMetrics();

        $this->assertNull($metrics['uptime']);
    }


    #[Test]
    public function it_calculates_memory_usage_percentage_correctly()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 50000000,  // 50MB
                'maxmemory' => 100000000,   // 100MB
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $health = $this->service->checkHealth();

        $this->assertEquals(50.0, $health['memory']['usage_percent']);
        $this->assertEquals('ok', $health['memory']['status']);
    }


    #[Test]
    public function it_formats_memory_values_in_megabytes()
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('info')
            ->with('memory')
            ->once()
            ->andReturn([
                'used_memory' => 52428800,   // 50MB
                'maxmemory' => 104857600,    // 100MB
            ]);

        $this->circuitBreakerMock
            ->shouldReceive('getStatus')
            ->once()
            ->andReturn(['state' => 'closed', 'failures' => 0]);

        $health = $this->service->checkHealth();

        $this->assertEquals(50.0, $health['memory']['used_memory_mb']);
        $this->assertEquals(100.0, $health['memory']['max_memory_mb']);
    }
}
