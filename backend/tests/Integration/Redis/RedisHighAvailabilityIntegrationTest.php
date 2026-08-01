<?php

namespace Tests\Integration\Redis;

use App\Models\School;
use App\Models\User;
use App\Services\Redis\CacheWarmingService;
use App\Services\Redis\QueueRecoveryService;
use App\Services\Redis\ResilientRedisConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Integration tests for Redis High Availability system components
 * 
 * Tests the interaction between multiple components:
 * - Session management + Redis failover
 * - Cache warming + Queue recovery
 * - Multi-tenant isolation + Monitoring
 * - Backup + Recovery + Alerting
 */
class RedisHighAvailabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school1;
    protected School $school2;
    protected User $user1;
    protected User $user2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test schools and users
        $this->school1 = School::factory()->create(['name' => 'School 1']);
        $this->school2 = School::factory()->create(['name' => 'School 2']);
        
        $this->user1 = User::factory()->create(['school_id' => $this->school1->id]);
        $this->user2 = User::factory()->create(['school_id' => $this->school2->id]);
    }

    /**
     * Test: Session persistence + Cache warming integration
     * Validates: Requirements 2.1, 4.1
     */
    public function test_session_persistence_with_cache_warming_after_failover()
    {
        // Create authenticated sessions for both users
        $this->actingAs($this->user1);
        session(['test_data_1' => 'user1_session_data']);
        
        $this->actingAs($this->user2);
        session(['test_data_2' => 'user2_session_data']);

        // Cache some school-specific data
        Cache::put("school:{$this->school1->id}:config", ['setting' => 'value1'], 3600);
        Cache::put("school:{$this->school2->id}:config", ['setting' => 'value2'], 3600);

        // Simulate Redis failover
        $this->simulateRedisFailover();

        // Warm cache after failover
        $cacheWarmer = app(CacheWarmingService::class);
        $cacheWarmer->warmCache();

        // Verify sessions are preserved
        $this->actingAs($this->user1);
        $this->assertEquals('user1_session_data', session('test_data_1'));
        
        $this->actingAs($this->user2);
        $this->assertEquals('user2_session_data', session('test_data_2'));

        // Verify cache is warmed
        $this->assertNotNull(Cache::get("school:{$this->school1->id}:config"));
        $this->assertNotNull(Cache::get("school:{$this->school2->id}:config"));
    }

    /**
     * Test: Queue recovery + Session continuity integration
     * Validates: Requirements 2.2, 3.1, 3.2
     */
    public function test_queue_recovery_maintains_session_continuity()
    {
        // Queue some jobs with session context
        $this->actingAs($this->user1);
        $sessionId1 = session()->getId();
        Queue::push(new \App\Jobs\TestJob(['user_id' => $this->user1->id]));

        $this->actingAs($this->user2);
        $sessionId2 = session()->getId();
        Queue::push(new \App\Jobs\TestJob(['user_id' => $this->user2->id]));

        // Simulate failover during job processing
        $this->simulateRedisFailover();

        // Recover queued jobs
        $queueRecovery = app(QueueRecoveryService::class);
        $queueRecovery->recoverFailedJobs();

        // Verify sessions are still valid
        $this->actingAs($this->user1);
        $this->assertEquals($sessionId1, session()->getId());
        
        $this->actingAs($this->user2);
        $this->assertEquals($sessionId2, session()->getId());

        // Verify jobs are recovered
        $this->assertGreaterThanOrEqual(2, Queue::size());
    }

    /**
     * Test: Multi-tenant isolation + Cache consistency integration
     * Validates: Requirements 4.5, 5.1, 5.2
     */
    public function test_multi_tenant_cache_isolation_during_failover()
    {
        // Set tenant-specific cache data
        Cache::tags(["tenant:{$this->school1->id}"])->put('data', 'school1_data', 3600);
        Cache::tags(["tenant:{$this->school2->id}"])->put('data', 'school2_data', 3600);

        // Simulate failover
        $this->simulateRedisFailover();

        // Verify tenant isolation is maintained
        $school1Data = Cache::tags(["tenant:{$this->school1->id}"])->get('data');
        $school2Data = Cache::tags(["tenant:{$this->school2->id}"])->get('data');

        $this->assertEquals('school1_data', $school1Data);
        $this->assertEquals('school2_data', $school2Data);
        $this->assertNotEquals($school1Data, $school2Data);
    }

    /**
     * Test: Monitoring + Alerting + Failover integration
     * Validates: Requirements 6.1, 6.2, 6.3
     */
    public function test_monitoring_triggers_alerts_during_failover()
    {
        $monitoringService = app(\App\Services\RedisMonitoringService::class);
        $alertManager = app(\App\Services\DR\AlertManager::class);

        // Monitor health before failover
        $healthBefore = $monitoringService->checkHealth();
        $this->assertTrue($healthBefore['healthy']);

        // Simulate degraded performance (pre-failover state)
        $this->simulateRedisPerformanceDegradation();

        // Check if alerts are triggered
        $alerts = $alertManager->getPendingAlerts();
        $this->assertNotEmpty($alerts);
        $this->assertStringContainsString('degraded', strtolower($alerts[0]['message']));

        // Simulate actual failover
        $this->simulateRedisFailover();

        // Verify failover event is tracked
        $failoverEvents = $monitoringService->getFailoverEvents();
        $this->assertNotEmpty($failoverEvents);
        $this->assertArrayHasKey('timestamp', $failoverEvents[0]);
    }

    /**
     * Test: Backup + Recovery + Session restoration integration
     * Validates: Requirements 7.1, 7.2, 7.4, 2.1
     */
    public function test_backup_recovery_restores_sessions_and_data()
    {
        // Create sessions and cache data
        $this->actingAs($this->user1);
        session(['important_data' => 'critical_value']);
        Cache::put('critical_cache', 'important_cache_value', 3600);

        // Trigger backup
        $this->artisan('backup:run')->assertExitCode(0);

        // Simulate catastrophic failure
        $this->simulateCatastrophicRedisFailure();

        // Restore from backup
        $this->artisan('disaster-recovery:restore')->assertExitCode(0);

        // Verify session data is restored
        $this->actingAs($this->user1);
        $this->assertEquals('critical_value', session('important_data'));

        // Verify cache data is restored
        $this->assertEquals('important_cache_value', Cache::get('critical_cache'));
    }

    /**
     * Test: Performance optimization + Horizontal scaling integration
     * Validates: Requirements 9.1, 9.2, 9.4
     */
    public function test_horizontal_scaling_maintains_performance_under_load()
    {
        $scalerService = app(\App\Services\QueueWorkerScalerService::class);

        // Create high load scenario
        for ($i = 0; $i < 1000; $i++) {
            Cache::put("load_test_key_{$i}", "value_{$i}", 60);
        }

        // Measure initial performance
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            Cache::get("load_test_key_{$i}");
        }
        $initialLatency = microtime(true) - $startTime;

        // Trigger horizontal scaling
        $scalerService->scaleUp();

        // Measure performance after scaling
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            Cache::get("load_test_key_{$i}");
        }
        $scaledLatency = microtime(true) - $startTime;

        // Performance should be maintained or improved
        $this->assertLessThanOrEqual($initialLatency * 1.2, $scaledLatency);
    }

    /**
     * Test: Security + Audit logging + Multi-tenant isolation integration
     * Validates: Requirements 10.1, 10.4, 5.2
     */
    public function test_security_audit_logging_tracks_cross_tenant_access_attempts()
    {
        $auditSystem = app(\App\Services\DR\AuditTrailSystem::class);

        // Attempt cross-tenant access
        $this->actingAs($this->user1);
        try {
            // Try to access school2's data
            Cache::tags(["tenant:{$this->school2->id}"])->get('sensitive_data');
        } catch (\Exception $e) {
            // Expected to fail
        }

        // Verify audit log captured the attempt
        $auditLogs = $auditSystem->getRecentLogs();
        $crossTenantAttempts = array_filter($auditLogs, function ($log) {
            return isset($log['type']) && $log['type'] === 'cross_tenant_access_attempt';
        });

        $this->assertNotEmpty($crossTenantAttempts);
    }

    /**
     * Test: End-to-end failover with all components
     * Validates: Requirements 1.1, 2.1, 3.1, 4.1, 5.1, 6.1
     */
    public function test_end_to_end_failover_with_all_components()
    {
        // Setup: Create sessions, cache, and queue jobs
        $this->actingAs($this->user1);
        session(['e2e_test' => 'session_value']);
        Cache::put('e2e_cache', 'cache_value', 3600);
        Queue::push(new \App\Jobs\TestJob(['test' => 'e2e']));

        // Monitor initial state
        $monitoringService = app(\App\Services\RedisMonitoringService::class);
        $healthBefore = $monitoringService->checkHealth();

        // Trigger failover
        $failoverStart = microtime(true);
        $this->simulateRedisFailover();
        $failoverDuration = microtime(true) - $failoverStart;

        // Verify failover timing (< 30 seconds)
        $this->assertLessThan(30, $failoverDuration);

        // Warm cache
        app(CacheWarmingService::class)->warmCache();

        // Recover queue
        app(QueueRecoveryService::class)->recoverFailedJobs();

        // Verify all components are functional
        $this->actingAs($this->user1);
        $this->assertEquals('session_value', session('e2e_test'));
        $this->assertEquals('cache_value', Cache::get('e2e_cache'));
        $this->assertGreaterThan(0, Queue::size());

        // Verify monitoring detected failover
        $healthAfter = $monitoringService->checkHealth();
        $this->assertTrue($healthAfter['healthy']);
    }

    // Helper methods for simulation

    protected function simulateRedisFailover(): void
    {
        // Simulate failover by flushing and reconnecting
        try {
            Redis::connection()->flushdb();
        } catch (\Exception $e) {
            // Expected during failover
        }
        
        // Reconnect to simulate new master
        Redis::reconnect();
    }

    protected function simulateRedisPerformanceDegradation(): void
    {
        // Simulate by adding artificial delay
        Cache::put('_performance_degradation_marker', true, 60);
    }

    protected function simulateCatastrophicRedisFailure(): void
    {
        // Simulate complete Redis failure
        try {
            Redis::connection()->flushall();
        } catch (\Exception $e) {
            // Expected
        }
    }
}
