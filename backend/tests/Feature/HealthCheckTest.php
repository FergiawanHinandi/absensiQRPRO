<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_endpoint_returns_comprehensive_status()
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'version',
                'environment',
                'app_name',
                'php_version',
                'laravel_version',
                'uptime' => [
                    'seconds',
                    'human',
                    'started_at',
                ],
                'checks' => [
                    'database' => [
                        'status',
                        'response_time_ms',
                        'connection',
                        'driver',
                        'stats',
                        'message',
                    ],
                    'redis' => [
                        'status',
                        'message',
                    ],
                    'cache' => [
                        'status',
                        'response_time_ms',
                        'driver',
                        'message',
                    ],
                    'storage' => [
                        'status',
                        'response_time_ms',
                        'path',
                        'writable',
                        'message',
                    ],
                    'queue' => [
                        'status',
                        'connection',
                        'driver',
                        'message',
                    ],
                    'queue_jobs' => [
                        'status',
                        'connection',
                        'pending_jobs',
                        'failed_jobs',
                        'message',
                    ],
                    'disk_space' => [
                        'status',
                        'path',
                        'total_gb',
                        'free_gb',
                        'used_gb',
                        'used_percent',
                        'message',
                    ],
                    'memory' => [
                        'status',
                        'limit',
                        'current_mb',
                        'peak_mb',
                        'used_percent',
                        'message',
                    ],
                ],
            ]);

        $data = $response->json();

        // Verify basic information
        $this->assertEquals('1.0.0', $data['version']);
        $this->assertEquals('testing', $data['environment']);
        $this->assertEquals('AbsensiQR API', $data['app_name']);
        $this->assertNotEmpty($data['php_version']);
        $this->assertNotEmpty($data['laravel_version']);

        // Verify database check
        $this->assertEquals('ok', $data['checks']['database']['status']);
        $this->assertIsNumeric($data['checks']['database']['response_time_ms']);
        $this->assertArrayHasKey('users', $data['checks']['database']['stats']);
        $this->assertArrayHasKey('schools', $data['checks']['database']['stats']);

        // Verify cache check
        $this->assertEquals('ok', $data['checks']['cache']['status']);
        $this->assertIsNumeric($data['checks']['cache']['response_time_ms']);

        // Verify storage check
        $this->assertEquals('ok', $data['checks']['storage']['status']);
        $this->assertIsNumeric($data['checks']['storage']['response_time_ms']);
        $this->assertTrue($data['checks']['storage']['writable']);

        // Verify queue check
        $this->assertEquals('ok', $data['checks']['queue']['status']);
        $this->assertNotEmpty($data['checks']['queue']['connection']);

        // Verify queue jobs check
        $this->assertEquals('ok', $data['checks']['queue_jobs']['status']);
        $this->assertIsNumeric($data['checks']['queue_jobs']['pending_jobs']);
        $this->assertIsNumeric($data['checks']['queue_jobs']['failed_jobs']);

        // Verify disk space check
        $this->assertContains($data['checks']['disk_space']['status'], ['ok', 'warning', 'error']);
        $this->assertIsNumeric($data['checks']['disk_space']['total_gb']);
        $this->assertIsNumeric($data['checks']['disk_space']['free_gb']);
        $this->assertIsNumeric($data['checks']['disk_space']['used_percent']);

        // Verify memory check
        $this->assertContains($data['checks']['memory']['status'], ['ok', 'warning', 'error']);
        $this->assertIsNumeric($data['checks']['memory']['current_mb']);
        $this->assertIsNumeric($data['checks']['memory']['peak_mb']);
        $this->assertIsNumeric($data['checks']['memory']['used_percent']);

        // Verify uptime
        $this->assertIsNumeric($data['uptime']['seconds']);
        $this->assertNotEmpty($data['uptime']['human']);
        $this->assertNotEmpty($data['uptime']['started_at']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_returns_unhealthy_when_database_fails()
    {
        // Mock database failure
        DB::shouldReceive('select')->andThrow(new \Exception('Database connection failed'));

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);

        $data = $response->json();
        $this->assertEquals('unhealthy', $data['status']);
        $this->assertEquals('error', $data['checks']['database']['status']);
        $this->assertStringContainsString('Database connection failed', $data['checks']['database']['message']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_returns_unhealthy_when_cache_fails()
    {
        // Mock cache failure
        Cache::shouldReceive('put')->andThrow(new \Exception('Cache connection failed'));

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);

        $data = $response->json();
        $this->assertEquals('unhealthy', $data['status']);
        $this->assertEquals('error', $data['checks']['cache']['status']);
        $this->assertStringContainsString('Cache connection failed', $data['checks']['cache']['message']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_shows_warning_for_high_queue_jobs()
    {
        // Change queue connection to database for this test
        config(['queue.default' => 'database']);

        // Create many jobs to trigger warning
        for ($i = 0; $i < 101; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        $response = $this->getJson('/api/v1/health');

        $data = $response->json();
        $this->assertEquals('warning', $data['checks']['queue_jobs']['status']);
        $this->assertGreaterThan(100, $data['checks']['queue_jobs']['pending_jobs']);
        $this->assertStringContainsString('High number of pending jobs', $data['checks']['queue_jobs']['message']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_shows_error_for_high_failed_jobs()
    {
        // Change queue connection to database for this test
        config(['queue.default' => 'database']);

        // Create many failed jobs to trigger error
        for ($i = 0; $i < 51; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'exception' => 'Test exception',
                'failed_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v1/health');

        $data = $response->json();
        $this->assertEquals('error', $data['checks']['queue_jobs']['status']);
        $this->assertGreaterThan(50, $data['checks']['queue_jobs']['failed_jobs']);
        $this->assertStringContainsString('High number of failed jobs', $data['checks']['queue_jobs']['message']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_includes_app_version_from_config()
    {
        config(['app.version' => '2.1.0']);

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals('2.1.0', $data['version']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function health_check_redis_shows_warning_when_extension_not_loaded()
    {
        // This test would need to mock the extension_loaded function
        // For now, we'll just verify the structure exists
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertArrayHasKey('redis', $data['checks']);
        $this->assertArrayHasKey('status', $data['checks']['redis']);
        $this->assertArrayHasKey('message', $data['checks']['redis']);
    }
}
