<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use App\Services\PrometheusMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PrometheusMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PrometheusMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrometheusMetricsService();
    }


    #[Test]
    public function it_collects_metrics_in_prometheus_format()
    {
        Redis::shouldReceive('connection->info')->andReturn([
            'used_memory' => 1024 * 1024 * 100,
            'maxmemory' => 1024 * 1024 * 1024,
            'connected_clients' => 10,
            'total_commands_processed' => 1000,
            'evicted_keys' => 5,
            'keyspace_hits' => 900,
            'keyspace_misses' => 100,
        ]);
        Redis::shouldReceive('connection->dbsize')->andReturn(500);

        $output = $this->service->collect();

        $this->assertIsString($output);
        $this->assertStringContainsString('# HELP', $output);
        $this->assertStringContainsString('# TYPE', $output);
        $this->assertStringContainsString('absensi_', $output);
    }


    #[Test]
    public function it_includes_app_info_metric()
    {
        Config::set('app.version', '2.0.0');
        Config::set('app.env', 'testing');

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_app_info', $output);
        $this->assertStringContainsString('version="2.0.0"', $output);
        $this->assertStringContainsString('environment="testing"', $output);
    }


    #[Test]
    public function it_collects_api_metrics()
    {
        Cache::put('prometheus:requests', [
            'requests' => [
                'GET:200:/api/attendance' => 100,
                'POST:201:/api/attendance' => 50,
                'GET:404:/api/unknown' => 5,
            ],
            'errors' => 5,
            'active' => 3,
            'duration_buckets' => [
                '/api/attendance' => [
                    'buckets' => [0.1 => 80, 0.5 => 100],
                    'sum' => 15.5,
                    'total' => 100,
                ],
            ],
        ], now()->addHour());

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_http_requests_total', $output);
        $this->assertStringContainsString('absensi_http_errors_total', $output);
        $this->assertStringContainsString('absensi_http_error_rate_percent', $output);
        $this->assertStringContainsString('absensi_http_requests_active', $output);
    }


    #[Test]
    public function it_calculates_error_rate_correctly()
    {
        Cache::put('prometheus:requests', [
            'requests' => [
                'GET:200:/api/test' => 90,
                'GET:500:/api/test' => 10,
            ],
            'errors' => 10,
            'active' => 0,
            'duration_buckets' => [],
        ], now()->addHour());

        $output = $this->service->collect();

        // Error rate should be 10% (10 errors / 100 total)
        $this->assertStringContainsString('absensi_http_error_rate_percent', $output);
        
        // Extract the error rate value
        preg_match('/absensi_http_error_rate_percent\s+(\d+\.?\d*)/', $output, $matches);
        if (!empty($matches[1])) {
            $errorRate = (float) $matches[1];
            $this->assertEquals(10.0, $errorRate);
        }
    }


    #[Test]
    public function it_collects_database_metrics()
    {
        Cache::put('prometheus:db_queries', [
            'count' => 1000,
            'total_time' => 5000, // 5 seconds total
            'max_time' => 500,    // 500ms max
            'slow_count' => 10,
        ], now()->addHour());

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_db_query_duration_seconds', $output);
        $this->assertStringContainsString('absensi_db_queries_total', $output);
        $this->assertStringContainsString('absensi_db_slow_queries_total', $output);
        $this->assertStringContainsString('absensi_db_up', $output);
    }


    #[Test]
    public function it_collects_redis_metrics()
    {
        Redis::shouldReceive('connection->info')->andReturn([
            'used_memory' => 1024 * 1024 * 100, // 100MB
            'maxmemory' => 1024 * 1024 * 1024,  // 1GB
            'connected_clients' => 15,
            'total_commands_processed' => 50000,
            'evicted_keys' => 10,
            'keyspace_hits' => 9000,
            'keyspace_misses' => 1000,
        ]);
        Redis::shouldReceive('connection->dbsize')->andReturn(1500);

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_redis_up 1', $output);
        $this->assertStringContainsString('absensi_redis_memory_used_bytes', $output);
        $this->assertStringContainsString('absensi_redis_connected_clients', $output);
        $this->assertStringContainsString('absensi_redis_keys_total', $output);
        $this->assertStringContainsString('absensi_redis_hit_rate_percent', $output);
    }


    #[Test]
    public function it_calculates_redis_hit_rate_correctly()
    {
        Redis::shouldReceive('connection->info')->andReturn([
            'used_memory' => 1024 * 1024 * 100,
            'maxmemory' => 1024 * 1024 * 1024,
            'connected_clients' => 10,
            'total_commands_processed' => 1000,
            'evicted_keys' => 0,
            'keyspace_hits' => 800,
            'keyspace_misses' => 200,
        ]);
        Redis::shouldReceive('connection->dbsize')->andReturn(500);

        $output = $this->service->collect();

        // Hit rate should be 80% (800 hits / 1000 total)
        preg_match('/absensi_redis_hit_rate_percent\s+(\d+\.?\d*)/', $output, $matches);
        if (!empty($matches[1])) {
            $hitRate = (float) $matches[1];
            $this->assertEquals(80.0, $hitRate);
        }
    }


    #[Test]
    public function it_handles_redis_connection_failure()
    {
        Redis::shouldReceive('connection->info')
            ->andThrow(new \Exception('Connection refused'));

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_redis_up 0', $output);
    }


    #[Test]
    public function it_collects_queue_metrics()
    {
        Config::set('queue.default', 'database');

        // Create test jobs
        DB::table('jobs')->insert([
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

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_queue_jobs_pending', $output);
        $this->assertStringContainsString('absensi_queue_jobs_failed_total', $output);
    }


    #[Test]
    public function it_collects_system_metrics()
    {
        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_php_memory_usage_bytes', $output);
        $this->assertStringContainsString('absensi_php_memory_peak_bytes', $output);
        $this->assertStringContainsString('absensi_process_start_time_seconds', $output);
    }


    #[Test]
    public function it_collects_attendance_metrics()
    {
        $school = School::factory()->create(['is_active' => true]);
        
        Attendance::factory()->count(5)->create([
            'school_id' => $school->id,
            'status' => 'present',
            'created_at' => now(),
        ]);

        Attendance::factory()->count(2)->create([
            'school_id' => $school->id,
            'status' => 'late',
            'created_at' => now(),
        ]);

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_attendance_scans_today', $output);
        $this->assertStringContainsString('absensi_attendance_by_status', $output);
        $this->assertStringContainsString('status="present"', $output);
        $this->assertStringContainsString('status="late"', $output);
    }


    #[Test]
    public function it_records_request_metrics()
    {
        PrometheusMetricsService::recordRequest('GET', '/api/test', 200, 0.15);
        PrometheusMetricsService::recordRequest('POST', '/api/test', 201, 0.25);
        PrometheusMetricsService::recordRequest('GET', '/api/test', 500, 0.50);

        $metrics = Cache::get('prometheus:requests');

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('requests', $metrics);
        $this->assertArrayHasKey('errors', $metrics);
        $this->assertEquals(1, $metrics['errors']); // One 500 error
    }


    #[Test]
    public function it_records_query_metrics()
    {
        PrometheusMetricsService::recordQuery(50.5);
        PrometheusMetricsService::recordQuery(150.2);
        PrometheusMetricsService::recordQuery(350.8); // Slow query

        $metrics = Cache::get('prometheus:db_queries');

        $this->assertIsArray($metrics);
        $this->assertEquals(3, $metrics['count']);
        $this->assertEquals(1, $metrics['slow_count']); // One query > 300ms
        $this->assertEquals(350.8, $metrics['max_time']);
    }


    #[Test]
    public function it_records_job_metrics()
    {
        PrometheusMetricsService::recordJob('TestJob', 100.5, false);
        PrometheusMetricsService::recordJob('TestJob', 200.3, false);
        PrometheusMetricsService::recordJob('FailedJob', 50.0, true);

        $metrics = Cache::get('prometheus:job_metrics');

        $this->assertIsArray($metrics);
        $this->assertEquals(3, $metrics['processed']);
        $this->assertEquals(1, $metrics['failed']);
        $this->assertEquals(200.3, $metrics['max_duration']);
    }


    #[Test]
    public function it_handles_missing_cache_gracefully()
    {
        Cache::flush();

        $output = $this->service->collect();

        // Should still produce output without errors
        $this->assertIsString($output);
        $this->assertStringContainsString('absensi_', $output);
    }


    #[Test]
    public function it_formats_histogram_buckets_correctly()
    {
        Cache::put('prometheus:requests', [
            'requests' => ['GET:200:/api/test' => 100],
            'errors' => 0,
            'active' => 0,
            'duration_buckets' => [
                '/api/test' => [
                    'buckets' => [
                        0.01 => 10,
                        0.05 => 50,
                        0.1 => 80,
                        0.5 => 95,
                        1.0 => 100,
                    ],
                    'sum' => 25.5,
                    'total' => 100,
                ],
            ],
        ], now()->addHour());

        $output = $this->service->collect();

        $this->assertStringContainsString('le="0.01"', $output);
        $this->assertStringContainsString('le="0.5"', $output);
        $this->assertStringContainsString('le="+Inf"', $output);
        $this->assertStringContainsString('_sum', $output);
        $this->assertStringContainsString('_count', $output);
    }


    #[Test]
    public function it_collects_deadlock_metrics()
    {
        // Mock deadlock metrics
        $deadlockMetrics = [
            'total_deadlocks' => 5,
            'total_retries' => 12,
            'successful_retries' => 10,
            'exhausted_retries' => 2,
            'success_rate' => 83.33,
            'retry_attempts' => [
                1 => 5,
                2 => 4,
                3 => 3,
            ],
            'last_occurrence' => '2026-02-26 10:00:00',
        ];

        // Mock the middleware method
        $mock = \Mockery::mock('alias:App\Http\Middleware\DeadlockRetryMiddleware');
        $mock->shouldReceive('getMetrics')->andReturn($deadlockMetrics);

        $output = $this->service->collect();

        $this->assertStringContainsString('absensi_deadlock_detected_total', $output);
        $this->assertStringContainsString('absensi_deadlock_retries_total', $output);
        $this->assertStringContainsString('absensi_deadlock_retry_success_rate_percent', $output);
    }


    #[Test]
    public function it_uses_correct_metric_prefix()
    {
        $output = $this->service->collect();

        // All metrics should start with 'absensi_'
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (str_starts_with($line, 'absensi_')) {
                $this->assertStringStartsWith('absensi_', $line);
            }
        }
    }


    #[Test]
    public function it_includes_help_and_type_for_each_metric()
    {
        $output = $this->service->collect();

        // Count HELP and TYPE declarations
        $helpCount = substr_count($output, '# HELP absensi_');
        $typeCount = substr_count($output, '# TYPE absensi_');

        // Should have equal number of HELP and TYPE declarations
        $this->assertEquals($helpCount, $typeCount);
        $this->assertGreaterThan(0, $helpCount);
    }


    #[Test]
    public function it_handles_database_connection_failure_gracefully()
    {
        DB::shouldReceive('connection->select')
            ->andThrow(new \Exception('Connection failed'));

        $output = $this->service->collect();

        // Should still produce output
        $this->assertIsString($output);
        $this->assertStringContainsString('absensi_db_up 0', $output);
    }


    #[Test]
    public function it_calculates_average_query_duration()
    {
        Cache::put('prometheus:db_queries', [
            'count' => 100,
            'total_time' => 10000, // 10 seconds total
            'max_time' => 500,
            'slow_count' => 5,
        ], now()->addHour());

        $output = $this->service->collect();

        // Average should be 100ms (10000ms / 100 queries = 100ms = 0.1s)
        preg_match('/absensi_db_query_duration_seconds_avg\s+(\d+\.?\d*)/', $output, $matches);
        if (!empty($matches[1])) {
            $avgDuration = (float) $matches[1];
            $this->assertEquals(0.1, $avgDuration);
        }
    }
}
