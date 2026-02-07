<?php

namespace Tests\Feature\Queue;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create super admin for testing
        $this->superAdmin = User::factory()->create([
            'role_type' => 'super_admin',
            'school_id' => null,
        ]);
    }

    public function test_queue_health_endpoint_returns_metrics()
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/system/queue-status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'metrics' => [
                    'pending_jobs',
                    'failed_jobs_24h',
                    'total_failed_jobs',
                    'queue_lag_seconds',
                ],
                'health' => [
                    'is_healthy',
                    'alerts',
                ],
            ]);
    }

    public function test_queue_health_detects_failed_jobs()
    {
        // Insert a fake failed job from 1 hour ago
        DB::table('failed_jobs')->insert([
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['test' => 'data']),
            'exception' => 'Test exception',
            'failed_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/system/queue-status');

        $response->assertStatus(503) // Service Unavailable when unhealthy
            ->assertJson([
                'health' => [
                    'is_healthy' => false,
                ],
            ])
            ->assertJsonFragment([
                'severity' => 'warning',
            ]);
    }

    public function test_queue_health_shows_healthy_when_no_failures()
    {
        // Clear any existing failed jobs
        DB::table('failed_jobs')->truncate();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/system/queue-status');

        $response->assertStatus(200)
            ->assertJson([
                'health' => [
                    'is_healthy' => true,
                ],
                'metrics' => [
                    'failed_jobs_24h' => 0,
                    'total_failed_jobs' => 0,
                ],
            ]);
    }

    public function test_requires_authentication()
    {
        $response = $this->getJson('/api/v1/system/queue-status');

        $response->assertStatus(401);
    }
}
