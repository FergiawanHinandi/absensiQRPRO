<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any existing alerts
        Cache::flush();
    }

    /**
     * Test redis:monitor command exists and runs successfully
     */
    public function test_redis_monitor_command_exists(): void
    {
        $exitCode = Artisan::call('redis:monitor');

        // Command should complete (exit code 0 or -1 if Redis is down)
        $this->assertContains($exitCode, [0, -1]);
    }

    /**
     * Test memory monitoring logs metrics when Redis is available
     */
    public function test_memory_monitoring_logs_metrics_when_redis_available(): void
    {
        // Skip if Redis is not available
        try {
            Redis::connection()->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available');
        }

        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf()
            ->shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Redis memory monitored' &&
                    isset($context['used_memory_bytes']) &&
                    isset($context['memory_percent']);
            })
            ->once();

        Artisan::call('redis:monitor');
    }

    /**
     * Test connection health monitoring logs when Redis is available
     */
    public function test_connection_health_monitoring_logs_when_redis_available(): void
    {
        // Skip if Redis is not available
        try {
            Redis::connection()->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available');
        }

        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf()
            ->shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Redis connection healthy' &&
                    isset($context['response_time_ms']);
            })
            ->once();

        Artisan::call('redis:monitor');
    }

    /**
     * Test latency monitoring logs metrics when Redis is available
     */
    public function test_latency_monitoring_logs_metrics_when_redis_available(): void
    {
        // Skip if Redis is not available
        try {
            Redis::connection()->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available');
        }

        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf()
            ->shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'Redis latency monitored' &&
                    isset($context['avg_latency_ms']) &&
                    isset($context['min_latency_ms']) &&
                    isset($context['max_latency_ms']);
            })
            ->once();

        Artisan::call('redis:monitor');
    }

    /**
     * Test connection error is logged when Redis is unavailable
     */
    public function test_connection_error_logged_when_redis_unavailable(): void
    {
        // Mock Redis to throw exception
        Redis::shouldReceive('connection')
            ->andThrow(new \Exception('Connection refused'));

        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf()
            ->shouldReceive('error')
            ->withArgs(function ($message, $context) {
                return $message === 'Redis connection error' &&
                    isset($context['operation']) &&
                    isset($context['error']);
            })
            ->atLeast()
            ->once();

        Artisan::call('redis:monitor');
    }

    /**
     * Test alert cooldown prevents duplicate alerts
     */
    public function test_alert_cooldown_prevents_duplicate_alerts(): void
    {
        // Set alert as recently sent
        Cache::put('redis_memory_alert', [
            'sent_at' => now(),
        ], now()->addMinutes(120));

        // Mock Redis to return high memory usage
        $redisMock = \Mockery::mock();
        $redisMock->shouldReceive('info')
            ->with('memory')
            ->andReturn([
                'used_memory' => 850 * 1024 * 1024, // 850MB
                'maxmemory' => 1000 * 1024 * 1024,  // 1000MB (85%)
            ]);

        Redis::shouldReceive('connection')
            ->andReturn($redisMock);

        // Should not send alert due to cooldown
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf()
            ->shouldReceive('warning')
            ->never();

        Artisan::call('redis:monitor', ['--memory-threshold' => 80]);
    }
}
