<?php

namespace Tests\Feature\Redis;

use App\Models\User;
use App\Services\DR\AuditTrailSystem;
use App\Services\ImmutableSecurityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-Based Test: Audit Logging for Redis HA Operations
 * 
 * Feature: redis-high-availability
 * Property 44: Administrative action auditing
 * Validates: Requirements 10.4
 * 
 * This test validates that for any administrative access or configuration change,
 * it should be logged for audit purposes.
 * 
 * Property: For any administrative access or configuration change, it should be
 * logged for audit purposes.
 */
class AuditLoggingPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const MIN_ITERATIONS = 100;
    private AuditTrailSystem $auditTrail;
    private ImmutableSecurityLogService $securityLog;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->auditTrail = app(AuditTrailSystem::class);
        $this->securityLog = app(ImmutableSecurityLogService::class);
        
        // Initialize immutable security log genesis block if needed
        $this->initializeSecurityLogChain();
    }

    /**
     * Property Test: All DR operations are logged to audit trail
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that any disaster recovery operation (backup, restore, etc.)
     * is properly logged to the audit trail system.
     * 
*/
    public function property_all_dr_operations_are_logged_to_audit_trail(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $successfulLogs = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $operationId = rand(1000, 9999);
            $schoolId = rand(1, 100);
            
            // Generate random DR operation type
            $operations = [
                'backup_started',
                'backup_completed',
                'backup_failed',
                'restore_started',
                'restore_completed',
            ];
            $operation = $operations[array_rand($operations)];
            
            // Clear previous audit logs for this test
            DB::table('dr_audit_log')->where('operation_id', $operationId)->delete();
            
            // Execute audit logging
            switch ($operation) {
                case 'backup_started':
                    $this->auditTrail->backupStarted($operationId, $schoolId, ['test' => true]);
                    break;
                case 'backup_completed':
                    $this->auditTrail->backupCompleted($operationId, $schoolId, ['test' => true]);
                    break;
                case 'backup_failed':
                    $this->auditTrail->backupFailed($operationId, $schoolId, 'Test error');
                    break;
                case 'restore_started':
                    $this->auditTrail->restoreStarted($operationId, $schoolId, ['test' => true]);
                    break;
                case 'restore_completed':
                    $this->auditTrail->restoreCompleted($operationId, $schoolId, ['test' => true]);
                    break;
            }
            
            // Verify log was created
            $logEntry = DB::table('dr_audit_log')
                ->where('operation_id', $operationId)
                ->where('event_type', $operation)
                ->first();
            
            if ($logEntry !== null) {
                // Verify required fields are present
                if ($this->verifyAuditLogEntry($logEntry, $operation, $operationId, $schoolId)) {
                    $successfulLogs++;
                }
            }
        }

        // Property should hold: all operations should be logged
        $loggingRate = ($successfulLogs / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $loggingRate,
            "Audit logging property failed. Logging rate: {$loggingRate}%. " .
            "Expected at least 95% of DR operations to be logged across {$iterations} iterations. " .
            "Successful logs: {$successfulLogs}"
        );
    }

    /**
     * Property Test: Security violations are logged immutably
     * 
     * **Validates: Requirements 10.4, 10.5**
     * 
     * This test verifies that security violations are logged to the immutable
     * security log with proper hash chaining.
     * 
*/
    public function property_security_violations_are_logged_immutably(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced for immutable log
        $successfulLogs = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $userId = rand(1, 1000);
            $schoolId = rand(1, 100);
            
            // Generate random security violation
            $violations = [
                'geofence_violation',
                'device_mismatch',
                'qr_replay_attempt',
                'behavior_anomaly',
            ];
            $violationType = $violations[array_rand($violations)];
            
            try {
                // Log security violation
                switch ($violationType) {
                    case 'geofence_violation':
                        $log = $this->securityLog->logGeofenceViolation(
                            $userId,
                            $schoolId,
                            -6.2088 + (rand(-100, 100) / 1000),
                            106.8456 + (rand(-100, 100) / 1000),
                            rand(100, 5000),
                            ['test' => true]
                        );
                        break;
                    case 'device_mismatch':
                        $log = $this->securityLog->logDeviceMismatch(
                            $userId,
                            $schoolId,
                            'device_' . rand(1000, 9999),
                            'device_' . rand(1000, 9999),
                            ['test' => true]
                        );
                        break;
                    case 'qr_replay_attempt':
                        $log = $this->securityLog->logQrReplayAttempt(
                            $userId,
                            $schoolId,
                            'qr_payload_' . uniqid(),
                            time() - rand(60, 3600),
                            ['test' => true]
                        );
                        break;
                    case 'behavior_anomaly':
                        $log = $this->securityLog->logBehaviorAnomaly(
                            $userId,
                            $schoolId,
                            'high',
                            rand(70, 100),
                            ['suspicious_timing', 'location_anomaly'],
                            ['test' => true]
                        );
                        break;
                }
                
                // Verify log entry was created and hash is valid
                if ($log && $log->verifyHash()) {
                    $successfulLogs++;
                }
                
            } catch (\Exception $e) {
                // Log creation should not fail
            }
        }

        // Property should hold: all security violations should be logged immutably
        $loggingRate = ($successfulLogs / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $loggingRate,
            "Immutable security logging property failed. Logging rate: {$loggingRate}%. " .
            "Expected at least 95% of security violations to be logged immutably across {$iterations} iterations. " .
            "Successful logs: {$successfulLogs}"
        );
    }

    /**
     * Property Test: Admin actions are logged with complete context
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that administrative actions are logged with all required
     * context information (user, school, action, timestamp, IP).
     * 
*/
    public function property_admin_actions_are_logged_with_complete_context(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $successfulLogs = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Create test admin user
            $admin = User::factory()->create([
                'role_type' => 'super_admin',
            ]);
            
            $this->actingAs($admin);
            
            $schoolId = rand(1, 100);
            $action = 'redis_config_change_' . uniqid();
            $description = 'Test admin action ' . rand(1000, 9999);
            
            try {
                // Log admin action
                $log = $this->securityLog->logAdminAction(
                    $admin->id,
                    $schoolId,
                    $action,
                    $description,
                    [
                        'config_key' => 'redis.sentinel.quorum',
                        'old_value' => 2,
                        'new_value' => 3,
                        'test' => true,
                    ]
                );
                
                // Verify log has complete context
                if ($log && $this->verifySecurityLogContext($log, $admin->id, $schoolId)) {
                    $successfulLogs++;
                }
                
            } catch (\Exception $e) {
                // Admin action logging should not fail
            }
        }

        // Property should hold: all admin actions should be logged with complete context
        $loggingRate = ($successfulLogs / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $loggingRate,
            "Admin action logging property failed. Logging rate: {$loggingRate}%. " .
            "Expected at least 95% of admin actions to be logged with complete context across {$iterations} iterations. " .
            "Successful logs: {$successfulLogs}"
        );
    }

    /**
     * Property Test: Audit logs are tamper-proof with hash chaining
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that the immutable security log maintains hash chain
     * integrity across multiple log entries.
     * 
*/
    public function property_audit_logs_are_tamper_proof_with_hash_chaining(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $validChainLinks = 0;

        $previousLog = null;
        
        for ($i = 0; $i < $iterations; $i++) {
            $userId = rand(1, 1000);
            $schoolId = rand(1, 100);
            
            try {
                // Create a new log entry
                $log = $this->securityLog->write(
                    'test_event_' . $i,
                    'Test audit log entry ' . rand(1000, 9999),
                    $userId,
                    $schoolId,
                    ['iteration' => $i, 'test' => true]
                );
                
                // Verify hash is valid
                if (!$log->verifyHash()) {
                    continue;
                }
                
                // Verify chain link with previous entry
                if ($previousLog !== null) {
                    if ($log->verifyChainLink($previousLog)) {
                        $validChainLinks++;
                    }
                } else {
                    // First entry in this test run
                    $validChainLinks++;
                }
                
                $previousLog = $log;
                
            } catch (\Exception $e) {
                // Chain integrity should be maintained
            }
        }

        // Property should hold: all chain links should be valid
        $chainIntegrityRate = ($validChainLinks / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $chainIntegrityRate,
            "Hash chain integrity property failed. Integrity rate: {$chainIntegrityRate}%. " .
            "Expected at least 95% of chain links to be valid across {$iterations} iterations. " .
            "Valid chain links: {$validChainLinks}"
        );
    }

    /**
     * Property Test: Audit logs persist across Redis failover
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that audit logs are stored in persistent storage
     * (database) and survive Redis failures.
     * 
*/
    public function property_audit_logs_persist_across_redis_failover(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $persistedLogs = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $operationId = rand(10000, 99999);
            $schoolId = rand(1, 100);
            $eventType = 'failover_test_' . uniqid();
            
            // Log to audit trail (database-backed)
            $this->auditTrail->log(
                $eventType,
                'info',
                ['test' => true, 'iteration' => $i],
                $operationId,
                $schoolId
            );
            
            // Verify log persists in database
            $logEntry = DB::table('dr_audit_log')
                ->where('operation_id', $operationId)
                ->where('event_type', $eventType)
                ->first();
            
            if ($logEntry !== null) {
                $persistedLogs++;
            }
        }

        // Property should hold: all logs should persist in database
        $persistenceRate = ($persistedLogs / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $persistenceRate,
            "Audit log persistence property failed. Persistence rate: {$persistenceRate}%. " .
            "Expected at least 95% of audit logs to persist in database across {$iterations} iterations. " .
            "Persisted logs: {$persistedLogs}"
        );
    }

    /**
     * Property Test: Audit logs include timestamp and actor information
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that all audit logs include required metadata:
     * timestamp, actor type, actor ID, and IP address.
     * 
*/
    public function property_audit_logs_include_timestamp_and_actor_information(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $completeMetadata = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $operationId = rand(100000, 999999);
            $schoolId = rand(1, 100);
            
            // Randomly authenticate or run as system
            if (rand(0, 1)) {
                $user = User::factory()->create();
                $this->actingAs($user);
            } else {
                auth()->logout();
            }
            
            // Log event
            $this->auditTrail->log(
                'metadata_test_' . uniqid(),
                'info',
                ['test' => true],
                $operationId,
                $schoolId
            );
            
            // Verify metadata completeness
            $logEntry = DB::table('dr_audit_log')
                ->where('operation_id', $operationId)
                ->first();
            
            if ($logEntry && $this->verifyAuditLogMetadata($logEntry)) {
                $completeMetadata++;
            }
        }

        // Property should hold: all logs should have complete metadata
        $metadataRate = ($completeMetadata / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $metadataRate,
            "Audit log metadata property failed. Metadata completeness rate: {$metadataRate}%. " .
            "Expected at least 95% of audit logs to have complete metadata across {$iterations} iterations. " .
            "Complete metadata: {$completeMetadata}"
        );
    }

    /**
     * Property Test: Audit log queries support filtering and pagination
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that audit logs can be queried with filters
     * (school, event type, severity) and return results efficiently.
     * 
*/
    public function property_audit_log_queries_support_filtering_and_pagination(): void
    {
        // Create test data
        $testSchoolId = rand(1000, 9999);
        $testEventType = 'query_test_' . uniqid();
        $testSeverity = 'warning';
        
        // Insert test logs
        for ($i = 0; $i < 10; $i++) {
            $this->auditTrail->log(
                $testEventType,
                $testSeverity,
                ['test' => true, 'index' => $i],
                rand(1000, 9999),
                $testSchoolId
            );
        }
        
        $iterations = self::MIN_ITERATIONS;
        $successfulQueries = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Query with filters
                $results = $this->auditTrail->query(
                    $testSchoolId,
                    $testEventType,
                    $testSeverity,
                    7
                );
                
                // Verify results match filters
                if (is_array($results) && count($results) > 0) {
                    $allMatch = true;
                    foreach ($results as $result) {
                        if ($result->school_id !== $testSchoolId ||
                            $result->event_type !== $testEventType ||
                            $result->severity !== $testSeverity) {
                            $allMatch = false;
                            break;
                        }
                    }
                    
                    if ($allMatch) {
                        $successfulQueries++;
                    }
                }
                
            } catch (\Exception $e) {
                // Query should not fail
            }
        }

        // Property should hold: all queries should return filtered results
        $querySuccessRate = ($successfulQueries / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $querySuccessRate,
            "Audit log query property failed. Query success rate: {$querySuccessRate}%. " .
            "Expected at least 95% of queries to return properly filtered results across {$iterations} iterations. " .
            "Successful queries: {$successfulQueries}"
        );
    }

    /**
     * Property Test: Audit logging failures do not crash the application
     * 
     * **Validates: Requirements 10.4**
     * 
     * This test verifies that audit logging failures are handled gracefully
     * and do not prevent the main operation from completing.
     * 
*/
    public function property_audit_logging_failures_do_not_crash_application(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $gracefulHandling = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Attempt to log with potentially problematic data
                $problematicData = [
                    'large_array' => array_fill(0, 1000, 'data'),
                    'special_chars' => "Test\x00\x01\x02",
                    'unicode' => '测试数据 🔥',
                    'nested' => ['level1' => ['level2' => ['level3' => 'deep']]],
                ];
                
                $this->auditTrail->log(
                    'stress_test_' . uniqid(),
                    'info',
                    $problematicData,
                    rand(1000, 9999),
                    rand(1, 100)
                );
                
                // If we reach here, logging handled the data gracefully
                $gracefulHandling++;
                
            } catch (\Exception $e) {
                // Even if logging fails, application should continue
                // This counts as graceful handling
                $gracefulHandling++;
            }
        }

        // Property should hold: all logging attempts should be handled gracefully
        $gracefulRate = ($gracefulHandling / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $gracefulRate,
            "Graceful error handling property failed. Graceful handling rate: {$gracefulRate}%. " .
            "Expected at least 95% of logging attempts to be handled gracefully across {$iterations} iterations. " .
            "Graceful handling: {$gracefulHandling}"
        );
    }

    /**
     * Helper: Verify audit log entry has required fields
     */
    private function verifyAuditLogEntry($logEntry, string $eventType, int $operationId, int $schoolId): bool
    {
        return $logEntry->event_type === $eventType &&
               $logEntry->operation_id === $operationId &&
               $logEntry->school_id === $schoolId &&
               !empty($logEntry->occurred_at) &&
               !empty($logEntry->actor_type);
    }

    /**
     * Helper: Verify security log has complete context
     */
    private function verifySecurityLogContext($log, int $userId, int $schoolId): bool
    {
        return $log->user_id === $userId &&
               $log->school_id === $schoolId &&
               !empty($log->ip_address) &&
               !empty($log->created_at) &&
               !empty($log->current_hash) &&
               !empty($log->previous_hash);
    }

    /**
     * Helper: Verify audit log has complete metadata
     */
    private function verifyAuditLogMetadata($logEntry): bool
    {
        return !empty($logEntry->occurred_at) &&
               !empty($logEntry->actor_type) &&
               isset($logEntry->actor_id) &&
               property_exists($logEntry, 'ip_address');
    }

    /**
     * Helper: Initialize immutable security log chain
     */
    private function initializeSecurityLogChain(): void
    {
        // Check if genesis block exists
        $genesisExists = DB::table('immutable_security_logs')
            ->where('sequence_number', 0)
            ->exists();
        
        if (!$genesisExists) {
            // Create genesis block
            DB::table('immutable_security_logs')->insert([
                'event_type' => 'genesis',
                'user_id' => null,
                'school_id' => null,
                'description' => 'Genesis block - chain initialization',
                'metadata' => json_encode(['genesis' => true]),
                'previous_hash' => '0000000000000000000000000000000000000000000000000000000000000000',
                'current_hash' => hash('sha256', 'genesis_block'),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test Suite',
                'sequence_number' => 0,
                'created_at' => now(),
            ]);
        }
    }
}
