<?php

namespace Tests\Unit\Services;

use App\Services\QueueWorkerScalerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Unit tests for QueueWorkerScalerService performance optimization
 * 
 * Tests scaling logic, metrics gathering, cooldown periods, and autoscaling decisions
 * for the Redis High Availability queue worker scaling system.
 */
class QueueWorkerScalerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QueueWorkerScalerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        Config::set('autoscaling.enabled', true);
        Config::set('autoscaling.provider', 'supervisor');
        Config::set('autoscaling.min_workers', 2);
        Config::set('autoscaling.max_workers', 10);
        Config::set('autoscaling.scale_up_threshold', 100);
        Config::set('autoscaling.scale_down_threshold', 10);
        Config::set('autoscaling.scale_up_cooldown', 300);
        Config::set('autoscaling.scale_down_cooldown', 600);
        Config::set('autoscaling.scale_down_delay', 300);
        
        $this->service = new QueueWorkerScalerService();
        
        // Clear any cached state
        Cache::forget('autoscaling:worker_count');
        Cache::forget('autoscaling:last_scale_up');
        Cache::forget('autoscaling:last_scale_down');
        Cache::forget('autoscaling:low_job_tracking_start');
    }

    /** @test */
    public function it_is_enabled_when_config_is_true()
    {
        Config::set('autoscaling.enabled', true);
        
        $this->assertTrue($this->service->isEnabled());
    }

    /** @test */
    public function it_is_disabled_when_config_is_false()
    {
        Config::set('autoscaling.enabled', false);
        
        $this->assertFalse($this->service->isEnabled());
    }

    /** @test */
    public function it_returns_configuration_array()
    {
        $config = $this->service->getConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('provider', $config);
        $this->assertArrayHasKey('min_workers', $config);
        $this->assertArrayHasKey('max_workers', $config);
        $this->assertArrayHasKey('scale_up_threshold', $config);
        $this->assertArrayHasKey('scale_down_threshold', $config);
    }

    /** @test */
    public function it_gathers_redis_queue_metrics()
    {
        Config::set('queue.default', 'redis');
        
        Redis::shouldReceive('connection')
            ->andReturnSelf();
        Redis::shouldReceive('llen')
            ->with('queues:default')
            ->andReturn(50);
        Redis::shouldReceive('llen')
            ->with('queues:high')
            ->andReturn(25);
        Redis::shouldReceive('zcard')
            ->with('queues:default:delayed')
            ->andReturn(10);
        Redis::shouldReceive('zcard')
            ->with('queues:default:reserved')
            ->andReturn(5);
        
        $metrics = $this->service->gatherMetrics();
        
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('pending_jobs', $metrics);
        $this->assertArrayHasKey('delayed_jobs', $metrics);
        $this->assertArrayHasKey('reserved_jobs', $metrics);
        $this->assertArrayHasKey('failed_jobs', $metrics);
        $this->assertGreaterThanOrEqual(75, $metrics['pending_jobs']); // 50 + 25
    }

    /** @test */
    public function it_gathers_database_queue_metrics()
    {
        Config::set('queue.default', 'database');
        
        // Create test jobs
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'high', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ]);
        
        DB::table('failed_jobs')->insert([
            [
                'uuid' => \Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Test',
                'failed_at' => now(),
            ],
        ]);
        
        $metrics = $this->service->gatherMetrics();
        
        $this->assertEquals(3, $metrics['pending_jobs']);
        $this->assertEquals(1, $metrics['failed_jobs']);
    }

    /** @test */
    public function it_recommends_scale_up_when_jobs_exceed_threshold()
    {
        Config::set('autoscaling.scale_up_threshold', 50);
        Config::set('queue.default', 'database');
        
        // Create jobs exceeding threshold
        for ($i = 0; $i < 100; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        Cache::put('autoscaling:worker_count', 2, now()->addHour());
        
        $result = $this->service->evaluate();
        
        $this->assertEquals('scale_up', $result['action']);
        $this->assertGreaterThan(2, $result['target_workers']);
    }

    /** @test */
    public function it_recommends_scale_down_when_jobs_below_threshold()
    {
        Config::set('autoscaling.scale_down_threshold', 10);
        Config::set('autoscaling.scale_down_delay', 0); // No delay for testing
        Config::set('queue.default', 'database');
        
        // Create minimal jobs
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        
        Cache::put('autoscaling:worker_count', 8, now()->addHour());
        Cache::put('autoscaling:low_job_tracking_start', now()->subMinutes(10)->timestamp, now()->addHour());
        
        $result = $this->service->evaluate();
        
        $this->assertEquals('scale_down', $result['action']);
        $this->assertLessThan(8, $result['target_workers']);
    }

    /** @test */
    public function it_recommends_no_action_when_within_thresholds()
    {
        Config::set('autoscaling.scale_up_threshold', 100);
        Config::set('autoscaling.scale_down_threshold', 10);
        Config::set('queue.default', 'database');
        
        // Create moderate number of jobs
        for ($i = 0; $i < 50; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        Cache::put('autoscaling:worker_count', 5, now()->addHour());
        
        $result = $this->service->evaluate();
        
        $this->assertEquals('none', $result['action']);
    }

    /** @test */
    public function it_respects_scale_up_cooldown_period()
    {
        Config::set('autoscaling.scale_up_cooldown', 300); // 5 minutes
        Config::set('autoscaling.scale_up_threshold', 10);
        Config::set('queue.default', 'database');
        
        // Create jobs exceeding threshold
        for ($i = 0; $i < 100; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        // Set recent scale up
        Cache::put('autoscaling:last_scale_up', now()->subMinutes(2)->timestamp, now()->addHour());
        Cache::put('autoscaling:worker_count', 2, now()->addHour());
        
        $result = $this->service->evaluate();
        
        $this->assertEquals('none', $result['action']);
        $this->assertStringContainsString('cooldown', strtolower($result['reason']));
    }

    /** @test */
    public function it_respects_scale_down_cooldown_period()
    {
        Config::set('autoscaling.scale_down_cooldown', 600); // 10 minutes
        Config::set('autoscaling.scale_down_threshold', 50);
        Config::set('queue.default', 'database');
        
        // Create minimal jobs
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        
        // Set recent scale down
        Cache::put('autoscaling:last_scale_down', now()->subMinutes(5)->timestamp, now()->addHour());
        Cache::put('autoscaling:worker_count', 8, now()->addHour());
        Cache::put('autoscaling:low_job_tracking_start', now()->subMinutes(10)->timestamp, now()->addHour());
        
        $result = $this->service->evaluate();
        
        $this->assertEquals('none', $result['action']);
        $this->assertStringContainsString('cooldown', strtolower($result['reason']));
    }

    /** @test */
    public function it_enforces_minimum_worker_count()
    {
        Config::set('autoscaling.min_workers', 2);
        Config::set('autoscaling.scale_down_threshold', 100);
        Config::set('queue.default', 'database');
        
        // No jobs
        Cache::put('autoscaling:worker_count', 2, now()->addHour());
        Cache::put('autoscaling:low_job_tracking_start', now()->subMinutes(10)->timestamp, now()->addHour());
        
        $result = $this->service->evaluate();
        
        if ($result['action'] === 'scale_down') {
            $this->assertGreaterThanOrEqual(2, $result['target_workers']);
        }
    }

    /** @test */
    public function it_enforces_maximum_worker_count()
    {
        Config::set('autoscaling.max_workers', 10);
        Config::set('autoscaling.scale_up_threshold', 1);
        Config::set('queue.default', 'database');
        
        // Create many jobs
        for ($i = 0; $i < 1000; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        Cache::put('autoscaling:worker_count', 10, now()->addHour());
        
        $result = $this->service->evaluate();
        
        if ($result['action'] === 'scale_up') {
            $this->assertLessThanOrEqual(10, $result['target_workers']);
        }
    }

    /** @test */
    public function it_tracks_low_job_period_for_scale_down()
    {
        Config::set('autoscaling.scale_down_delay', 300); // 5 minutes
        Config::set('autoscaling.scale_down_threshold', 50);
        Config::set('queue.default', 'database');
        
        // Create minimal jobs
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        
        Cache::put('autoscaling:worker_count', 8, now()->addHour());
        
        // First evaluation - should start tracking
        $result1 = $this->service->evaluate();
        $this->assertEquals('none', $result1['action']);
        
        // Set tracking start to past
        Cache::put('autoscaling:low_job_tracking_start', now()->subMinutes(6)->timestamp, now()->addHour());
        
        // Second evaluation - should scale down
        $result2 = $this->service->evaluate();
        $this->assertEquals('scale_down', $result2['action']);
    }

    /** @test */
    public function it_clears_low_job_tracking_when_jobs_increase()
    {
        Config::set('autoscaling.scale_down_threshold', 10);
        Config::set('queue.default', 'database');
        
        Cache::put('autoscaling:worker_count', 5, now()->addHour());
        Cache::put('autoscaling:low_job_tracking_start', now()->subMinutes(2)->timestamp, now()->addHour());
        
        // Create many jobs
        for ($i = 0; $i < 100; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        $this->service->evaluate();
        
        // Tracking should be cleared
        $this->assertNull(Cache::get('autoscaling:low_job_tracking_start'));
    }

    /** @test */
    public function it_gets_current_worker_count_from_cache()
    {
        Cache::put('autoscaling:worker_count', 5, now()->addHour());
        
        $count = $this->service->getCurrentWorkerCount();
        
        $this->assertEquals(5, $count);
    }

    /** @test */
    public function it_returns_default_worker_count_when_cache_empty()
    {
        Config::set('autoscaling.min_workers', 3);
        Cache::forget('autoscaling:worker_count');
        
        $count = $this->service->getCurrentWorkerCount();
        
        $this->assertEquals(3, $count);
    }

    /** @test */
    public function it_stores_metrics_history()
    {
        Config::set('queue.default', 'database');
        
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        
        $this->service->evaluate();
        
        $history = Cache::get('autoscaling:metrics_history', []);
        
        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        $this->assertArrayHasKey('timestamp', $history[0]);
        $this->assertArrayHasKey('pending_jobs', $history[0]);
    }

    /** @test */
    public function it_limits_metrics_history_size()
    {
        Config::set('queue.default', 'database');
        
        // Create history with 100 entries
        $history = [];
        for ($i = 0; $i < 100; $i++) {
            $history[] = [
                'timestamp' => now()->subMinutes($i)->timestamp,
                'pending_jobs' => 10,
            ];
        }
        Cache::put('autoscaling:metrics_history', $history, now()->addHour());
        
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        
        $this->service->evaluate();
        
        $newHistory = Cache::get('autoscaling:metrics_history', []);
        
        // Should be limited to 100 entries
        $this->assertLessThanOrEqual(100, count($newHistory));
    }

    /** @test */
    public function it_logs_scaling_events()
    {
        Config::set('autoscaling.scale_up_threshold', 10);
        Config::set('queue.default', 'database');
        
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Autoscaling evaluation') &&
                       isset($context['action']) &&
                       isset($context['metrics']);
            });
        
        for ($i = 0; $i < 50; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        Cache::put('autoscaling:worker_count', 2, now()->addHour());
        
        $this->service->evaluate();
    }

    /** @test */
    public function it_calculates_scale_up_cooldown_remaining()
    {
        Config::set('autoscaling.scale_up_cooldown', 300);
        
        Cache::put('autoscaling:last_scale_up', now()->subMinutes(2)->timestamp, now()->addHour());
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getScaleUpCooldownRemaining');
        $method->setAccessible(true);
        
        $remaining = $method->invoke($this->service);
        
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(300, $remaining);
    }

    /** @test */
    public function it_calculates_scale_down_cooldown_remaining()
    {
        Config::set('autoscaling.scale_down_cooldown', 600);
        
        Cache::put('autoscaling:last_scale_down', now()->subMinutes(5)->timestamp, now()->addHour());
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getScaleDownCooldownRemaining');
        $method->setAccessible(true);
        
        $remaining = $method->invoke($this->service);
        
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(600, $remaining);
    }

    /** @test */
    public function it_handles_redis_connection_failure_gracefully()
    {
        Config::set('queue.default', 'redis');
        
        Redis::shouldReceive('connection')
            ->andThrow(new \Exception('Connection failed'));
        
        $metrics = $this->service->gatherMetrics();
        
        // Should return default metrics
        $this->assertIsArray($metrics);
        $this->assertEquals(0, $metrics['pending_jobs']);
    }

    /** @test */
    public function it_handles_database_connection_failure_gracefully()
    {
        Config::set('queue.default', 'database');
        
        DB::shouldReceive('table')
            ->andThrow(new \Exception('Connection failed'));
        
        $metrics = $this->service->gatherMetrics();
        
        // Should return default metrics
        $this->assertIsArray($metrics);
        $this->assertEquals(0, $metrics['pending_jobs']);
    }

    /** @test */
    public function it_includes_all_queue_names_in_metrics()
    {
        Config::set('queue.default', 'database');
        Config::set('autoscaling.queues', ['default', 'high', 'low']);
        
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'high', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'low', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ]);
        
        $metrics = $this->service->gatherMetrics();
        
        $this->assertEquals(3, $metrics['pending_jobs']);
    }

    /** @test */
    public function it_calculates_jobs_per_worker_metric()
    {
        Config::set('queue.default', 'database');
        
        for ($i = 0; $i < 100; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }
        
        Cache::put('autoscaling:worker_count', 5, now()->addHour());
        
        $metrics = $this->service->gatherMetrics();
        
        $this->assertArrayHasKey('jobs_per_worker', $metrics);
        $this->assertEquals(20, $metrics['jobs_per_worker']); // 100 jobs / 5 workers
    }

    /** @test */
    public function it_handles_zero_workers_in_jobs_per_worker_calculation()
    {
        Config::set('queue.default', 'database');
        
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        
        Cache::forget('autoscaling:worker_count');
        
        $metrics = $this->service->gatherMetrics();
        
        $this->assertArrayHasKey('jobs_per_worker', $metrics);
        $this->assertGreaterThanOrEqual(0, $metrics['jobs_per_worker']);
    }
}
