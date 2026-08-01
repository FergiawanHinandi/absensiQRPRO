<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();

        // Clear any existing jobs
        DB::table('jobs')->truncate();
        DB::table('failed_jobs')->truncate();
    }


    #[Test]
    public function it_monitors_queue_size_successfully()
    {
        // Arrange: Add jobs to the queue
        for ($i = 0; $i < 5; $i++) {
            Queue::push(function () {
                // Dummy job
            });
        }

        // Act: Run the monitor command
        $exitCode = Artisan::call('queue:monitor');

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Queue monitoring completed', $output);
    }


    #[Test]
    public function it_alerts_when_queue_size_exceeds_threshold()
    {
        // Arrange: Set low threshold and add jobs
        config(['monitoring.queue.backlog_alert_threshold' => 3]);

        for ($i = 0; $i < 5; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => json_encode(['job' => 'test']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        // Expect log warning
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->with('Queue size alert triggered', \Mockery::type('array'))
            ->once();

        // Act: Run the monitor command
        $exitCode = Artisan::call('queue:monitor', ['--alert-threshold' => 3]);

        // Assert
        $this->assertEquals(0, $exitCode);
    }


    #[Test]
    public function it_monitors_failed_jobs_count()
    {
        // Arrange: Add failed jobs
        for ($i = 0; $i < 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['job' => 'test']),
                'exception' => 'Test exception',
                'failed_at' => now(),
            ]);
        }

        // Act: Run the monitor command with failed jobs check
        $exitCode = Artisan::call('queue:monitor', ['--check-failed' => true]);

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Failed jobs: 3', $output);
    }


    #[Test]
    public function it_alerts_when_failed_jobs_exceed_threshold()
    {
        // Arrange: Set low threshold and add failed jobs
        config(['monitoring.queue.failed_jobs_alert_threshold' => 2]);

        for ($i = 0; $i < 5; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['job' => 'test']),
                'exception' => "Test exception {$i}",
                'failed_at' => now(),
            ]);
        }

        // Expect log warning
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->with('Failed jobs alert triggered', \Mockery::type('array'))
            ->once();

        // Act: Run the monitor command
        $exitCode = Artisan::call('queue:monitor', [
            '--check-failed' => true,
            '--failed-threshold' => 2,
        ]);

        // Assert
        $this->assertEquals(0, $exitCode);
    }


    #[Test]
    public function it_implements_exponential_backoff_for_alerts()
    {
        // Arrange: Set low threshold
        config(['monitoring.queue.backlog_alert_threshold' => 1]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'test']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        // First alert should be sent
        Artisan::call('queue:monitor', ['--alert-threshold' => 1]);

        // Check that cooldown was set
        $cacheKey = 'queue_alert:database';
        $this->assertTrue(Cache::has($cacheKey));

        $alertData = Cache::get($cacheKey);
        $this->assertArrayHasKey('sent_at', $alertData);
        $this->assertArrayHasKey('cooldown', $alertData);
        $this->assertEquals(5, $alertData['cooldown']); // Initial cooldown

        // Second alert should be suppressed (within cooldown)
        Artisan::call('queue:monitor', ['--alert-threshold' => 1]);

        // Cooldown should remain the same (alert not sent)
        $alertData = Cache::get($cacheKey);
        $this->assertEquals(5, $alertData['cooldown']);
    }


    #[Test]
    public function it_suppresses_alerts_during_maintenance_mode()
    {
        // Arrange: Enable maintenance mode
        config(['monitoring.maintenance.suppress_alerts' => true]);
        Artisan::call('down');

        // Add jobs to trigger alert
        config(['monitoring.queue.backlog_alert_threshold' => 1]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'test']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        // Expect log info about suppression
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('Alert suppressed during maintenance', \Mockery::type('array'))
            ->once();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();

        // Act: Run the monitor command
        Artisan::call('queue:monitor', ['--alert-threshold' => 1]);

        // Cleanup
        Artisan::call('up');
    }


    #[Test]
    public function it_handles_multiple_queue_connections()
    {
        // Arrange: Configure multiple connections
        config([
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'default',
            ],
            'queue.connections.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'default',
            ],
        ]);

        // Add jobs to database queue
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'test']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        // Act: Run the monitor command
        $exitCode = Artisan::call('queue:monitor');

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Queue monitoring completed', $output);
    }


    #[Test]
    public function it_includes_failed_job_details_in_alerts()
    {
        // Arrange: Add failed jobs with different exceptions
        $exceptions = [
            'Database connection timeout',
            'Memory limit exceeded',
            'Invalid argument exception',
        ];

        foreach ($exceptions as $exception) {
            DB::table('failed_jobs')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['job' => 'test']),
                'exception' => $exception,
                'failed_at' => now(),
            ]);
        }

        // Expect log with recent failures
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->with('Failed jobs alert triggered', \Mockery::on(function ($arg) {
                return isset($arg['recent_failures']) && count($arg['recent_failures']) === 3;
            }))
            ->once();

        // Act: Run the monitor command
        Artisan::call('queue:monitor', [
            '--check-failed' => true,
            '--failed-threshold' => 2,
        ]);
    }


    #[Test]
    public function it_handles_empty_queues_gracefully()
    {
        // Arrange: Ensure queues are empty
        DB::table('jobs')->truncate();
        DB::table('failed_jobs')->truncate();

        // Act: Run the monitor command
        $exitCode = Artisan::call('queue:monitor', ['--check-failed' => true]);

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Queue monitoring completed', $output);
        $this->assertStringContainsString('Failed jobs: 0', $output);
    }


    #[Test]
    public function it_respects_custom_thresholds_from_options()
    {
        // Arrange: Add jobs
        for ($i = 0; $i < 50; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => json_encode(['job' => 'test']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        // Act: Run with high threshold (should not alert)
        $exitCode = Artisan::call('queue:monitor', ['--alert-threshold' => 100]);

        // Assert: No alert should be triggered
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringNotContainsString('⚠️', $output);
    }


    #[Test]
    public function it_logs_queue_metrics_for_monitoring()
    {
        // Arrange: Add jobs
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['job' => 'test']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        // Expect log info with metrics
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('Queue size monitored', \Mockery::on(function ($arg) {
                return isset($arg['connection']) && isset($arg['size']) && isset($arg['threshold']);
            }))
            ->once();
        Log::shouldReceive('info')->andReturnSelf();

        // Act: Run the monitor command
        Artisan::call('queue:monitor');
    }


    #[Test]
    public function it_handles_database_connection_errors_gracefully()
    {
        // Arrange: Configure invalid database connection
        config(['queue.connections.database.connection' => 'invalid_connection']);

        // Expect error log
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('error')
            ->with('Failed to get queue size', \Mockery::type('array'))
            ->once();
        Log::shouldReceive('info')->andReturnSelf();

        // Act: Run the monitor command (should not crash)
        $exitCode = Artisan::call('queue:monitor');

        // Assert: Command should complete successfully despite error
        $this->assertEquals(0, $exitCode);
    }
}
