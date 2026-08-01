<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\DR\AuditTrailSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit Tests: AuditTrailSystem
 * 
 * Tests the DR audit logging system for security and administrative actions.
 * 
 * Validates: Requirements 10.4 (Audit logging for administrative actions)
 */
class AuditTrailSystemTest extends TestCase
{
    use RefreshDatabase;

    private AuditTrailSystem $auditTrail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditTrail = new AuditTrailSystem();
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_backup_started_event(): void
    {
        $operationId = 12345;
        $schoolId = 1;
        $context = ['backup_type' => 'full', 'size' => '100MB'];

        $this->auditTrail->backupStarted($operationId, $schoolId, $context);

        $this->assertDatabaseHas('dr_audit_log', [
            'operation_id' => $operationId,
            'school_id' => $schoolId,
            'event_type' => 'backup_started',
            'severity' => 'info',
        ]);

        $log = DB::table('dr_audit_log')
            ->where('operation_id', $operationId)
            ->first();

        $details = json_decode($log->details, true);
        $this->assertEquals('full', $details['backup_type']);
        $this->assertEquals('100MB', $details['size']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_backup_completed_event(): void
    {
        $operationId = 12346;
        $schoolId = 2;
        $context = ['duration' => '5m', 'files' => 150];

        $this->auditTrail->backupCompleted($operationId, $schoolId, $context);

        $this->assertDatabaseHas('dr_audit_log', [
            'operation_id' => $operationId,
            'school_id' => $schoolId,
            'event_type' => 'backup_completed',
            'severity' => 'info',
        ]);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_backup_failed_event_with_error(): void
    {
        $operationId = 12347;
        $schoolId = 3;
        $error = 'Disk space insufficient';

        $this->auditTrail->backupFailed($operationId, $schoolId, $error);

        $this->assertDatabaseHas('dr_audit_log', [
            'operation_id' => $operationId,
            'school_id' => $schoolId,
            'event_type' => 'backup_failed',
            'severity' => 'error',
        ]);

        $log = DB::table('dr_audit_log')
            ->where('operation_id', $operationId)
            ->first();

        $details = json_decode($log->details, true);
        $this->assertEquals($error, $details['error']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_restore_started_event_with_warning_severity(): void
    {
        $operationId = 12348;
        $schoolId = 4;
        $context = ['backup_id' => 'backup_20240101'];

        $this->auditTrail->restoreStarted($operationId, $schoolId, $context);

        $this->assertDatabaseHas('dr_audit_log', [
            'operation_id' => $operationId,
            'school_id' => $schoolId,
            'event_type' => 'restore_started',
            'severity' => 'warning', // Restore operations are marked as warning
        ]);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_restore_completed_event(): void
    {
        $operationId = 12349;
        $schoolId = 5;
        $context = ['restored_files' => 200, 'duration' => '10m'];

        $this->auditTrail->restoreCompleted($operationId, $schoolId, $context);

        $this->assertDatabaseHas('dr_audit_log', [
            'operation_id' => $operationId,
            'school_id' => $schoolId,
            'event_type' => 'restore_completed',
            'severity' => 'info',
        ]);
    }

    /**
* Validates: Requirement 10.5 (Security breach response)
     */
    public function it_logs_security_violation_with_critical_severity(): void
    {
        $violation = 'cross_tenant_access_attempt';
        $context = [
            'user_id' => 123,
            'attempted_school_id' => 10,
            'actual_school_id' => 5,
        ];

        $this->auditTrail->securityViolation($violation, $context);

        $this->assertDatabaseHas('dr_audit_log', [
            'event_type' => 'security_violation',
            'severity' => 'critical',
        ]);

        $log = DB::table('dr_audit_log')
            ->where('event_type', 'security_violation')
            ->latest('occurred_at')
            ->first();

        $details = json_decode($log->details, true);
        $this->assertEquals($violation, $details['violation']);
        $this->assertEquals(123, $details['user_id']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_drill_execution_with_success_status(): void
    {
        $drillType = 'failover_simulation';
        $success = true;
        $metrics = ['duration' => '30s', 'downtime' => '5s'];

        $this->auditTrail->drillExecuted($drillType, $success, $metrics);

        $this->assertDatabaseHas('dr_audit_log', [
            'event_type' => 'drill_executed',
            'severity' => 'info', // Success = info
        ]);

        $log = DB::table('dr_audit_log')
            ->where('event_type', 'drill_executed')
            ->latest('occurred_at')
            ->first();

        $details = json_decode($log->details, true);
        $this->assertEquals($drillType, $details['drill_type']);
        $this->assertTrue($details['success']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_drill_execution_with_failure_status(): void
    {
        $drillType = 'backup_restore_test';
        $success = false;
        $metrics = ['error' => 'Timeout exceeded'];

        $this->auditTrail->drillExecuted($drillType, $success, $metrics);

        $this->assertDatabaseHas('dr_audit_log', [
            'event_type' => 'drill_executed',
            'severity' => 'warning', // Failure = warning
        ]);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_captures_actor_type_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->auditTrail->log('test_event', 'info', [], 999, 1);

        $log = DB::table('dr_audit_log')
            ->where('operation_id', 999)
            ->first();

        $this->assertEquals('user', $log->actor_type);
        $this->assertEquals($user->id, $log->actor_id);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_captures_actor_type_for_scheduler(): void
    {
        // Simulate console/scheduler context
        $this->app['env'] = 'testing';
        
        $this->auditTrail->log('scheduled_backup', 'info', [], 1000, 1);

        $log = DB::table('dr_audit_log')
            ->where('operation_id', 1000)
            ->first();

        $this->assertContains($log->actor_type, ['scheduler', 'system']);
        $this->assertNull($log->actor_id);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_captures_ip_address_from_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Simulate request with IP
        request()->server->set('REMOTE_ADDR', '192.168.1.100');

        $this->auditTrail->log('test_event', 'info', [], 1001, 1);

        $log = DB::table('dr_audit_log')
            ->where('operation_id', 1001)
            ->first();

        $this->assertNotNull($log->ip_address);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_queries_audit_logs_by_school_id(): void
    {
        // Create logs for different schools
        $this->auditTrail->log('event1', 'info', [], 2001, 10);
        $this->auditTrail->log('event2', 'info', [], 2002, 10);
        $this->auditTrail->log('event3', 'info', [], 2003, 20);

        $results = $this->auditTrail->query(schoolId: 10, days: 7);

        $this->assertCount(2, $results);
        foreach ($results as $log) {
            $this->assertEquals(10, $log->school_id);
        }
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_queries_audit_logs_by_event_type(): void
    {
        $this->auditTrail->log('backup_started', 'info', [], 3001, 1);
        $this->auditTrail->log('backup_completed', 'info', [], 3002, 1);
        $this->auditTrail->log('backup_started', 'info', [], 3003, 1);

        $results = $this->auditTrail->query(eventType: 'backup_started', days: 7);

        $this->assertCount(2, $results);
        foreach ($results as $log) {
            $this->assertEquals('backup_started', $log->event_type);
        }
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_queries_audit_logs_by_severity(): void
    {
        $this->auditTrail->log('event1', 'info', [], 4001, 1);
        $this->auditTrail->log('event2', 'error', [], 4002, 1);
        $this->auditTrail->log('event3', 'critical', [], 4003, 1);

        $results = $this->auditTrail->query(severity: 'error', days: 7);

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]->severity);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_queries_audit_logs_with_time_range(): void
    {
        // Create old log (should not be returned)
        DB::table('dr_audit_log')->insert([
            'operation_id' => 5001,
            'event_type' => 'old_event',
            'severity' => 'info',
            'actor_type' => 'system',
            'details' => json_encode([]),
            'occurred_at' => now()->subDays(10),
        ]);

        // Create recent log
        $this->auditTrail->log('recent_event', 'info', [], 5002, 1);

        $results = $this->auditTrail->query(days: 7);

        // Should only return recent log
        $recentEvents = array_filter($results, fn($log) => $log->event_type === 'recent_event');
        $oldEvents = array_filter($results, fn($log) => $log->event_type === 'old_event');

        $this->assertNotEmpty($recentEvents);
        $this->assertEmpty($oldEvents);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_limits_query_results_to_500_records(): void
    {
        // Create more than 500 logs
        for ($i = 0; $i < 600; $i++) {
            DB::table('dr_audit_log')->insert([
                'operation_id' => 6000 + $i,
                'event_type' => 'bulk_test',
                'severity' => 'info',
                'actor_type' => 'system',
                'details' => json_encode(['index' => $i]),
                'occurred_at' => now(),
            ]);
        }

        $results = $this->auditTrail->query(eventType: 'bulk_test', days: 7);

        $this->assertLessThanOrEqual(500, count($results));
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_handles_logging_failures_gracefully(): void
    {
        // Temporarily break database connection
        DB::disconnect();

        // This should not throw exception
        $this->auditTrail->log('test_event', 'info', [], 7001, 1);

        // Reconnect for cleanup
        DB::reconnect();

        // Verify log was not created (graceful failure)
        $log = DB::table('dr_audit_log')
            ->where('operation_id', 7001)
            ->first();

        $this->assertNull($log);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_stores_complex_details_as_json(): void
    {
        $complexDetails = [
            'nested' => [
                'level1' => [
                    'level2' => 'deep_value'
                ]
            ],
            'array' => [1, 2, 3],
            'unicode' => '测试数据',
        ];

        $this->auditTrail->log('complex_event', 'info', $complexDetails, 8001, 1);

        $log = DB::table('dr_audit_log')
            ->where('operation_id', 8001)
            ->first();

        $storedDetails = json_decode($log->details, true);
        $this->assertEquals($complexDetails, $storedDetails);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_allows_null_operation_id_and_school_id(): void
    {
        $this->auditTrail->log('system_event', 'info', ['global' => true], null, null);

        $this->assertDatabaseHas('dr_audit_log', [
            'event_type' => 'system_event',
            'operation_id' => null,
            'school_id' => null,
        ]);
    }
}
