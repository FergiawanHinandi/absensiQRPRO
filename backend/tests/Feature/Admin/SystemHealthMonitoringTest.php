<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * System Health Monitoring API Tests
 * 
 * Tests for admin-only system health endpoints:
 * - GET /api/v1/admin/system/health
 * - GET /api/v1/admin/system/health/queue
 * - GET /api/v1/admin/system/health/security
 */
class SystemHealthMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school first (required for foreign key)
        \App\Models\School::factory()->create([
            'id' => 1,
            'name' => 'Test School',
            'is_active' => true,
        ]);

        // Create roles if they don't exist
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'school_admin', 'guard_name' => 'sanctum']);

        // Create system:monitor permission if it doesn't exist
        Permission::firstOrCreate(['name' => 'system:monitor', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => '*', 'guard_name' => 'sanctum']);

        // Create admin user with system:monitor permission
        $this->admin = User::factory()->create([
            'school_id' => 1,
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo('system:monitor');

        // Create teacher user without system:monitor permission
        $this->teacher = User::factory()->create([
            'school_id' => 1,
            'is_active' => true,
        ]);
        $this->teacher->assignRole('teacher');
    }

    /** @test */
    public function admin_can_access_system_health_endpoint()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'metrics' => [
                        'queue_failed_last_24h',
                        'queue_pending',
                        'rate_limit_blocks_last_hour',
                        'qr_anomalies_last_24h',
                    ],
                    'health_status',
                    'alerts',
                    'timestamp',
                ],
            ]);
    }

    /** @test */
    public function admin_without_system_monitor_permission_cannot_access()
    {
        // Create admin without system:monitor permission
        $adminNoPermission = User::factory()->create([
            'school_id' => 1,
            'is_active' => true,
        ]);
        $adminNoPermission->assignRole('admin');
        
        // Explicitly revoke system:monitor if it exists
        if ($adminNoPermission->hasPermissionTo('system:monitor')) {
            $adminNoPermission->revokePermissionTo('system:monitor');
        }

        $response = $this->actingAs($adminNoPermission, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(403);
    }

    /** @test */
    public function token_with_only_wildcard_but_no_explicit_system_monitor_is_denied()
    {
        // Create a user with wildcard permission but NOT system:monitor
        $wildcardUser = User::factory()->create([
            'school_id' => 1,
            'is_active' => true,
        ]);
        $wildcardUser->assignRole('admin');
        
        // Revoke system:monitor explicitly
        if ($wildcardUser->hasPermissionTo('system:monitor')) {
            $wildcardUser->revokePermissionTo('system:monitor');
        }
        
        // Create token with wildcard ability ONLY (simulating old behavior)
        $token = $wildcardUser->createToken('test', ['*'])->plainTextToken;
        
        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/system/health');

        // Should be DENIED because explicit system:monitor is required
        $response->assertStatus(403);
    }

    /** @test */
    public function teacher_cannot_access_system_health_endpoint()
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_system_health()
    {
        $response = $this->getJson('/api/v1/admin/system/health');

        $response->assertStatus(401);
    }

    /** @test */
    public function system_health_returns_correct_queue_metrics()
    {
        // Create some failed jobs
        DB::table('failed_jobs')->insert([
            [
                'uuid' => 'test-uuid-1',
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'exception' => 'Test exception',
                'failed_at' => now()->subHours(2),
            ],
            [
                'uuid' => 'test-uuid-2',
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'exception' => 'Test exception',
                'failed_at' => now()->subHours(30), // Outside 24h window
            ],
        ]);

        // Create some pending jobs
        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'metrics' => [
                        'queue_failed_last_24h' => 1, // Only 1 within 24h
                        'queue_pending' => 1,
                    ],
                ],
            ]);
    }

    /** @test */
    public function system_health_calculates_health_status_correctly()
    {
        // Test healthy status (no issues)
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'health_status' => 'healthy',
                ],
            ]);

        // Create warning-level failed jobs (11 jobs)
        for ($i = 0; $i < 11; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => "test-uuid-warning-{$i}",
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'exception' => 'Test exception',
                'failed_at' => now()->subHours(1),
            ]);
        }

        // Clear cache to get fresh data
        \Cache::flush();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'health_status' => 'degraded',
                ],
            ]);
    }

    /** @test */
    public function system_health_generates_alerts_for_high_failures()
    {
        // Create 15 failed jobs (should trigger warning alert)
        for ($i = 0; $i < 15; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => "test-uuid-{$i}",
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['test' => 'data']),
                'exception' => 'Test exception',
                'failed_at' => now()->subHours(1),
            ]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200)
            ->assertJsonPath('data.alerts.0.severity', 'warning')
            ->assertJsonPath('data.alerts.0.category', 'queue');
    }

    /** @test */
    public function queue_health_endpoint_returns_detailed_metrics()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health/queue');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'pending_jobs',
                    'failed_jobs_24h',
                    'failed_jobs_total',
                    'queue_lag_seconds',
                    'failed_jobs_by_queue',
                ],
            ]);
    }

    /** @test */
    public function security_health_endpoint_returns_security_metrics()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health/security');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'rate_limit_blocks_1h',
                    'rate_limit_blocks_24h',
                    'qr_anomalies_24h',
                    'failed_login_attempts_1h',
                    'suspicious_activities_24h',
                ],
            ]);
    }

    /** @test */
    public function system_health_handles_missing_activity_log_table_gracefully()
    {
        // This test ensures the endpoint doesn't crash if activity_log table doesn't exist
        // The controller should check table existence and return 0 for those metrics

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'metrics' => [
                        'rate_limit_blocks_last_hour' => 0,
                        'qr_anomalies_last_24h' => 0,
                    ],
                ],
            ]);
    }

    /** @test */
    public function system_health_caches_metrics_correctly()
    {
        // First request - should hit database
        $response1 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response1->assertStatus(200);

        // Add a failed job
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid-cache',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['test' => 'data']),
            'exception' => 'Test exception',
            'failed_at' => now(),
        ]);

        // Second request within cache window - should return cached value (0)
        $response2 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response2->assertStatus(200)
            ->assertJson([
                'data' => [
                    'metrics' => [
                        'queue_failed_last_24h' => 0, // Still cached
                    ],
                ],
            ]);

        // Clear cache and request again - should see new value
        \Cache::flush();

        $response3 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response3->assertStatus(200)
            ->assertJson([
                'data' => [
                    'metrics' => [
                        'queue_failed_last_24h' => 1, // Updated after cache clear
                    ],
                ],
            ]);
    }

    /** @test */
    public function queue_health_calculates_lag_correctly()
    {
        // Create an old pending job (10 minutes ago)
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['test' => 'data']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health/queue');

        $response->assertStatus(200);

        $queueLag = $response->json('data.queue_lag_seconds');
        
        // Should be approximately 600 seconds (10 minutes)
        $this->assertGreaterThan(590, $queueLag);
        $this->assertLessThan(610, $queueLag);
    }

    /** @test */
    public function super_admin_can_access_system_health()
    {
        $superAdmin = User::factory()->create([
            'school_id' => 1,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');
        
        // Super admin gets both wildcard AND explicit system:monitor
        $superAdmin->givePermissionTo('*');
        $superAdmin->givePermissionTo('system:monitor');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200);
        
        // Verify super admin has explicit permission
        $this->assertTrue($superAdmin->hasPermissionTo('system:monitor'));
    }

    /** @test */
    public function school_admin_can_access_system_health()
    {
        $schoolAdmin = User::factory()->create([
            'school_id' => 1,
            'is_active' => true,
        ]);
        $schoolAdmin->assignRole('school_admin');
        $schoolAdmin->givePermissionTo('system:monitor');

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200);
    }

    /** @test */
    public function system_health_returns_timestamp_in_iso8601_format()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/system/health');

        $response->assertStatus(200);

        $timestamp = $response->json('data.timestamp');
        
        // Verify ISO 8601 format (e.g., 2026-01-28T22:45:00+08:00)
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $timestamp
        );
    }
}
