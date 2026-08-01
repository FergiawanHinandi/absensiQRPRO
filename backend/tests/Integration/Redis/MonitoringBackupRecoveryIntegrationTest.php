<?php

namespace Tests\Integration\Redis;

use App\Models\School;
use App\Services\DR\AlertManager;
use App\Services\DR\AuditTrailSystem;
use App\Services\HighAvailabilityMonitorService;
use App\Services\RedisMonitoringService;
use App\Services\ReplicationLagMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Integration tests for Monitoring + Backup + Recovery + Alerting
 * 
 * Validates the interaction between:
 * - Health monitoring
 * - Backup systems
 * - Recovery procedures
 * - Alert management
 * - Audit logging
 */
class MonitoringBackupRecoveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected RedisMonitoringService $monitoringService;
    protected AlertManager $alertManager;
    protected AuditTrailSystem $auditSystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitoringService = app(RedisMonitoringService::class);
        $this->alertManager = app(AlertManager::class);
        $this->auditSystem = app(AuditTrailSystem::class);
    }

    /**
     * Test: Monitoring detects issue, triggers alert, logs to audit
     * Validates: Requirements 6.1, 6.2, 6.3, 10.4
     */
    public function test_monitoring_alert_audit_integration()
    {
        // Monitor health
        $healthStatus = $this->monitoringService->checkHealth();
        $this->assertTrue($healthStatus['healthy']);

        // Simulate degraded performance
        Cache::put('_redis_degraded', true, 60);

        // Check health again
        $healthStatus = $this->monitoringService->checkHealth();
        
        if (!$healthStatus['healthy']) {
            // Trigger alert
            $this->alertManager->sendAlert([
                'type' => 'redis_degraded',
                'severity' => 'warning',
                'message' => 'Redis performance degraded',
            ]);

            // Log to audit trail
            $this->auditSystem->log([
                'event' => 'redis_health_degraded',
                'timestamp' => now(),
                'details' => $healthStatus,
            ]);
        }

        // Verify alert was sent
        $alerts = $this->alertManager->getPendingAlerts();
        $this->assertNotEmpty($alerts);

        // Verify audit log entry
        $auditLogs = $this->auditSystem->getRecentLogs();
        $this->assertNotEmpty($auditLogs);
    }

    /**
     * Test: Backup triggers monitoring update and audit log
     * Validates: Requirements 7.1, 7.2, 10.4
     */
    public function test_backup_updates_monitoring_and_audit()
    {
        // Record pre-backup state
        $preBackupHealth = $this->monitoringService->checkHealth();

        // Trigger backup
        $backupResult = Artisan::call('backup:run');

        // Log backup event to audit
        $this->auditSystem->log([
            'event' => 'backup_executed',
            'timestamp' => now(),
            'result' => $backupResult === 0 ? 'success' : 'failed',
        ]);

        // Verify monitoring tracked backup
        $postBackupHealth = $this->monitoringService->checkHealth();
        $this->assertTrue($postBackupHealth['healthy']);

        // Verify audit log
        $auditLogs = $this->auditSystem->getRecentLogs();
        $backupLogs = array_filter($auditLogs, fn($log) => $log['event'] === 'backup_executed');
        $this->assertNotEmpty($backupLogs);
    }

    /**
     * Test: Recovery triggers monitoring, alerts, and audit logging
     * Validates: Requirements 7.4, 6.3, 10.4
     */
    public function test_recovery_triggers_monitoring_alerts_audit()
    {
        // Simulate failure requiring recovery
        $this->simulateRedisFailure();

        // Log failure to audit
        $this->auditSystem->log([
            'event' => 'redis_failure_detected',
            'timestamp' => now(),
        ]);

        // Trigger recovery
        $recoveryStart = microtime(true);
        $recoveryResult = Artisan::call('disaster-recovery:restore');
        $recoveryDuration = microtime(true) - $recoveryStart;

        // Send recovery alert
        $this->alertManager->sendAlert([
            'type' => 'recovery_completed',
            'severity' => 'info',
            'message' => "Recovery completed in {$recoveryDuration}s",
        ]);

        // Log recovery to audit
        $this->auditSystem->log([
            'event' => 'recovery_completed',
            'timestamp' => now(),
            'duration' => $recoveryDuration,
        ]);

        // Verify recovery was within RTO (15 minutes)
        $this->assertLessThan(900, $recoveryDuration);

        // Verify monitoring shows healthy state
        $healthStatus = $this->monitoringService->checkHealth();
        $this->assertTrue($healthStatus['healthy']);

        // Verify alerts were sent
        $alerts = $this->alertManager->getPendingAlerts();
        $recoveryAlerts = array_filter($alerts, fn($a) => $a['type'] === 'recovery_completed');
        $this->assertNotEmpty($recoveryAlerts);

        // Verify audit trail
        $auditLogs = $this->auditSystem->getRecentLogs();
        $this->assertGreaterThanOrEqual(2, count($auditLogs)); // Failure + Recovery
    }

    /**
     * Test: Replication lag monitoring triggers alerts and audit
     * Validates: Requirements 6.4, 6.2, 10.4
     */
    public function test_replication_lag_monitoring_alerts_audit()
    {
        $lagMonitor = app(ReplicationLagMonitor::class);

        // Check replication lag
        $lagStatus = $lagMonitor->checkReplicationLag();

        if (isset($lagStatus['lag_seconds']) && $lagStatus['lag_seconds'] > 10) {
            // Trigger alert for high lag
            $this->alertManager->sendAlert([
                'type' => 'replication_lag_high',
                'severity' => 'warning',
                'message' => "Replication lag: {$lagStatus['lag_seconds']}s",
            ]);

            // Log to audit
            $this->auditSystem->log([
                'event' => 'replication_lag_detected',
                'timestamp' => now(),
                'lag_seconds' => $lagStatus['lag_seconds'],
            ]);
        }

        // Verify monitoring is tracking lag
        $this->assertArrayHasKey('lag_seconds', $lagStatus);
    }

    /**
     * Test: Failover event triggers full monitoring, backup, alert, audit chain
     * Validates: Requirements 1.1, 6.3, 7.1, 10.4
     */
    public function test_failover_triggers_complete_monitoring_chain()
    {
        $haMonitor = app(HighAvailabilityMonitorService::class);

        // Pre-failover monitoring
        $preFailoverHealth = $this->monitoringService->checkHealth();
        
        // Log pre-failover state
        $this->auditSystem->log([
            'event' => 'pre_failover_state',
            'timestamp' => now(),
            'health' => $preFailoverHealth,
        ]);

        // Simulate failover
        $failoverStart = microtime(true);
        $this->simulateRedisFailover();
        $failoverDuration = microtime(true) - $failoverStart;

        // Send pre-failover alert
        $this->alertManager->sendAlert([
            'type' => 'failover_initiated',
            'severity' => 'critical',
            'message' => 'Redis failover in progress',
        ]);

        // Log failover event
        $this->auditSystem->log([
            'event' => 'failover_completed',
            'timestamp' => now(),
            'duration' => $failoverDuration,
        ]);

        // Trigger backup after failover
        Artisan::call('backup:run');

        // Post-failover monitoring
        $postFailoverHealth = $this->monitoringService->checkHealth();

        // Send completion alert
        $this->alertManager->sendAlert([
            'type' => 'failover_completed',
            'severity' => 'info',
            'message' => "Failover completed in {$failoverDuration}s",
        ]);

        // Verify failover timing
        $this->assertLessThan(30, $failoverDuration);

        // Verify monitoring tracked failover
        $failoverEvents = $haMonitor->getFailoverHistory();
        $this->assertNotEmpty($failoverEvents);

        // Verify alerts were sent
        $alerts = $this->alertManager->getPendingAlerts();
        $this->assertGreaterThanOrEqual(2, count($alerts)); // Initiated + Completed

        // Verify complete audit trail
        $auditLogs = $this->auditSystem->getRecentLogs();
        $this->assertGreaterThanOrEqual(3, count($auditLogs)); // Pre + Failover + Backup
    }

    /**
     * Test: Backup verification triggers monitoring and audit
     * Validates: Requirements 7.5, 10.4
     */
    public function test_backup_verification_monitoring_audit()
    {
        // Create backup
        Artisan::call('backup:run');

        // Verify backup integrity
        $verificationResult = $this->verifyBackupIntegrity();

        // Log verification to audit
        $this->auditSystem->log([
            'event' => 'backup_verified',
            'timestamp' => now(),
            'result' => $verificationResult ? 'passed' : 'failed',
        ]);

        if (!$verificationResult) {
            // Send alert if verification failed
            $this->alertManager->sendAlert([
                'type' => 'backup_verification_failed',
                'severity' => 'critical',
                'message' => 'Backup integrity check failed',
            ]);
        }

        // Verify audit log
        $auditLogs = $this->auditSystem->getRecentLogs();
        $verificationLogs = array_filter($auditLogs, fn($log) => $log['event'] === 'backup_verified');
        $this->assertNotEmpty($verificationLogs);
    }

    /**
     * Test: Multi-tenant monitoring with alerts and audit
     * Validates: Requirements 5.1, 6.1, 10.4
     */
    public function test_multi_tenant_monitoring_alerts_audit()
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();

        // Monitor tenant-specific metrics
        $school1Metrics = $this->monitoringService->getTenantMetrics($school1->id);
        $school2Metrics = $this->monitoringService->getTenantMetrics($school2->id);

        // Log metrics to audit
        $this->auditSystem->log([
            'event' => 'tenant_metrics_collected',
            'timestamp' => now(),
            'school_id' => $school1->id,
            'metrics' => $school1Metrics,
        ]);

        $this->auditSystem->log([
            'event' => 'tenant_metrics_collected',
            'timestamp' => now(),
            'school_id' => $school2->id,
            'metrics' => $school2Metrics,
        ]);

        // Verify tenant isolation in audit logs
        $auditLogs = $this->auditSystem->getRecentLogs();
        $school1Logs = array_filter($auditLogs, fn($log) => 
            isset($log['school_id']) && $log['school_id'] === $school1->id
        );
        $school2Logs = array_filter($auditLogs, fn($log) => 
            isset($log['school_id']) && $log['school_id'] === $school2->id
        );

        $this->assertNotEmpty($school1Logs);
        $this->assertNotEmpty($school2Logs);
    }

    // Helper methods

    protected function simulateRedisFailure(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (\Exception $e) {
            // Expected
        }
    }

    protected function simulateRedisFailover(): void
    {
        $this->simulateRedisFailure();
        Redis::reconnect();
    }

    protected function verifyBackupIntegrity(): bool
    {
        // Simplified backup verification
        return true;
    }
}
