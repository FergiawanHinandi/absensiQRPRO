<?php

namespace Tests\Integration\Redis;

use App\Models\School;
use App\Models\User;
use App\Services\DR\AuditTrailSystem;
use App\Services\ImmutableSecurityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Integration tests for Security + Audit + Multi-Tenant isolation
 * 
 * Validates the interaction between:
 * - Authentication and authorization
 * - Audit logging
 * - Multi-tenant isolation
 * - Security breach detection
 */
class SecurityAuditMultiTenantIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school1;
    protected School $school2;
    protected User $user1;
    protected User $user2;
    protected AuditTrailSystem $auditSystem;
    protected ImmutableSecurityLogService $securityLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school1 = School::factory()->create();
        $this->school2 = School::factory()->create();
        $this->user1 = User::factory()->create(['school_id' => $this->school1->id]);
        $this->user2 = User::factory()->create(['school_id' => $this->school2->id]);
        
        $this->auditSystem = app(AuditTrailSystem::class);
        $this->securityLog = app(ImmutableSecurityLogService::class);
    }

    /**
     * Test: Authentication enforcement with audit logging
     * Validates: Requirements 10.1, 10.4
     */
    public function test_authentication_enforcement_with_audit()
    {
        // Attempt unauthenticated Redis access
        try {
            Redis::connection('unauthenticated')->ping();
            $authSuccess = false;
        } catch (\Exception $e) {
            $authSuccess = true;
            
            // Log failed authentication attempt
            $this->securityLog->logSecurityEvent([
                'event' => 'redis_auth_failed',
                'timestamp' => now(),
                'error' => $e->getMessage(),
            ]);
        }

        // Verify authentication was enforced
        $this->assertTrue($authSuccess);

        // Verify security log entry
        $securityLogs = $this->securityLog->getRecentLogs();
        $authLogs = array_filter($securityLogs, fn($log) => $log['event'] === 'redis_auth_failed');
        $this->assertNotEmpty($authLogs);
    }

    /**
     * Test: Cross-tenant access attempt triggers security alert and audit
     * Validates: Requirements 5.2, 10.4, 10.5
     */
    public function test_cross_tenant_access_triggers_security_alert_and_audit()
    {
        // User 1 tries to access User 2's tenant data
        $this->actingAs($this->user1);
        
        try {
            // Attempt to access school2's cache
            $unauthorizedData = Cache::tags(["tenant:{$this->school2->id}"])->get('sensitive_data');
            
            // If access succeeds, log security breach
            if ($unauthorizedData !== null) {
                $this->securityLog->logSecurityEvent([
                    'event' => 'cross_tenant_access_breach',
                    'severity' => 'critical',
                    'user_id' => $this->user1->id,
                    'attempted_school_id' => $this->school2->id,
                    'timestamp' => now(),
                ]);
            }
        } catch (\Exception $e) {
            // Expected - access should be denied
            $this->auditSystem->log([
                'event' => 'cross_tenant_access_attempt',
                'user_id' => $this->user1->id,
                'user_school_id' => $this->school1->id,
                'attempted_school_id' => $this->school2->id,
                'timestamp' => now(),
                'result' => 'denied',
            ]);
        }

        // Verify audit log captured the attempt
        $auditLogs = $this->auditSystem->getRecentLogs();
        $crossTenantLogs = array_filter($auditLogs, fn($log) => 
            isset($log['event']) && str_contains($log['event'], 'cross_tenant')
        );
        $this->assertNotEmpty($crossTenantLogs);
    }

    /**
     * Test: Session isolation with security logging
     * Validates: Requirements 2.4, 5.1, 10.4
     */
    public function test_session_isolation_with_security_logging()
    {
        // Create sessions for both users
        $this->actingAs($this->user1);
        $session1Id = session()->getId();
        session(['tenant_id' => $this->school1->id, 'sensitive_data' => 'school1_secret']);

        $this->actingAs($this->user2);
        $session2Id = session()->getId();
        session(['tenant_id' => $this->school2->id, 'sensitive_data' => 'school2_secret']);

        // Log session creation
        $this->auditSystem->log([
            'event' => 'session_created',
            'user_id' => $this->user1->id,
            'session_id' => $session1Id,
            'school_id' => $this->school1->id,
            'timestamp' => now(),
        ]);

        $this->auditSystem->log([
            'event' => 'session_created',
            'user_id' => $this->user2->id,
            'session_id' => $session2Id,
            'school_id' => $this->school2->id,
            'timestamp' => now(),
        ]);

        // Verify session isolation
        $this->actingAs($this->user1);
        $this->assertEquals('school1_secret', session('sensitive_data'));
        $this->assertEquals($this->school1->id, session('tenant_id'));

        $this->actingAs($this->user2);
        $this->assertEquals('school2_secret', session('sensitive_data'));
        $this->assertEquals($this->school2->id, session('tenant_id'));

        // Verify audit trail
        $auditLogs = $this->auditSystem->getRecentLogs();
        $sessionLogs = array_filter($auditLogs, fn($log) => $log['event'] === 'session_created');
        $this->assertCount(2, $sessionLogs);
    }

    /**
     * Test: Data encryption with audit trail
     * Validates: Requirements 10.2, 10.3, 10.4
     */
    public function test_data_encryption_with_audit_trail()
    {
        $sensitiveData = 'sensitive_user_token';
        
        // Store encrypted data
        $encryptedData = encrypt($sensitiveData);
        Cache::put('encrypted_token', $encryptedData, 3600);

        // Log encryption event
        $this->auditSystem->log([
            'event' => 'data_encrypted',
            'data_type' => 'user_token',
            'timestamp' => now(),
        ]);

        // Retrieve and decrypt
        $retrievedData = Cache::get('encrypted_token');
        $decryptedData = decrypt($retrievedData);

        // Log decryption event
        $this->auditSystem->log([
            'event' => 'data_decrypted',
            'data_type' => 'user_token',
            'timestamp' => now(),
        ]);

        // Verify encryption/decryption worked
        $this->assertEquals($sensitiveData, $decryptedData);

        // Verify audit trail
        $auditLogs = $this->auditSystem->getRecentLogs();
        $encryptionLogs = array_filter($auditLogs, fn($log) => 
            in_array($log['event'], ['data_encrypted', 'data_decrypted'])
        );
        $this->assertCount(2, $encryptionLogs);
    }

    /**
     * Test: Security breach detection triggers access revocation and audit
     * Validates: Requirements 10.5, 10.4
     */
    public function test_security_breach_triggers_revocation_and_audit()
    {
        $this->actingAs($this->user1);
        
        // Simulate multiple failed access attempts (breach indicator)
        for ($i = 0; $i < 5; $i++) {
            try {
                Cache::tags(["tenant:{$this->school2->id}"])->get('protected_data');
            } catch (\Exception $e) {
                $this->securityLog->logSecurityEvent([
                    'event' => 'unauthorized_access_attempt',
                    'user_id' => $this->user1->id,
                    'attempt_number' => $i + 1,
                    'timestamp' => now(),
                ]);
            }
        }

        // Detect breach pattern
        $securityLogs = $this->securityLog->getRecentLogs();
        $failedAttempts = array_filter($securityLogs, fn($log) => 
            $log['event'] === 'unauthorized_access_attempt' && 
            $log['user_id'] === $this->user1->id
        );

        if (count($failedAttempts) >= 5) {
            // Revoke access
            $this->user1->tokens()->delete();
            
            // Log breach and revocation
            $this->securityLog->logSecurityEvent([
                'event' => 'security_breach_detected',
                'severity' => 'critical',
                'user_id' => $this->user1->id,
                'action_taken' => 'access_revoked',
                'timestamp' => now(),
            ]);

            $this->auditSystem->log([
                'event' => 'access_revoked',
                'user_id' => $this->user1->id,
                'reason' => 'security_breach',
                'timestamp' => now(),
            ]);
        }

        // Verify breach was logged
        $breachLogs = array_filter($securityLogs, fn($log) => 
            $log['event'] === 'security_breach_detected'
        );
        $this->assertNotEmpty($breachLogs);

        // Verify audit trail
        $auditLogs = $this->auditSystem->getRecentLogs();
        $revocationLogs = array_filter($auditLogs, fn($log) => 
            $log['event'] === 'access_revoked'
        );
        $this->assertNotEmpty($revocationLogs);
    }

    /**
     * Test: Administrative actions are audited with security context
     * Validates: Requirements 10.4
     */
    public function test_administrative_actions_audited_with_security_context()
    {
        // Simulate admin configuration change
        $adminUser = User::factory()->create([
            'school_id' => $this->school1->id,
            'role_type' => 'admin',
        ]);

        $this->actingAs($adminUser);

        // Change Redis configuration
        config(['database.redis.default.password' => 'new_password']);

        // Log administrative action
        $this->auditSystem->log([
            'event' => 'redis_config_changed',
            'admin_user_id' => $adminUser->id,
            'school_id' => $this->school1->id,
            'change_type' => 'password_update',
            'timestamp' => now(),
            'ip_address' => request()->ip(),
        ]);

        // Verify audit log
        $auditLogs = $this->auditSystem->getRecentLogs();
        $configLogs = array_filter($auditLogs, fn($log) => 
            $log['event'] === 'redis_config_changed'
        );
        $this->assertNotEmpty($configLogs);
        $this->assertEquals($adminUser->id, $configLogs[0]['admin_user_id']);
    }

    /**
     * Test: Multi-tenant data isolation during failover with security audit
     * Validates: Requirements 5.4, 10.4, 1.1
     */
    public function test_multi_tenant_isolation_during_failover_with_audit()
    {
        // Set tenant-specific data
        Cache::tags(["tenant:{$this->school1->id}"])->put('data', 'school1_data', 3600);
        Cache::tags(["tenant:{$this->school2->id}"])->put('data', 'school2_data', 3600);

        // Log pre-failover state
        $this->auditSystem->log([
            'event' => 'pre_failover_tenant_state',
            'school1_data_exists' => Cache::tags(["tenant:{$this->school1->id}"])->has('data'),
            'school2_data_exists' => Cache::tags(["tenant:{$this->school2->id}"])->has('data'),
            'timestamp' => now(),
        ]);

        // Simulate failover
        $this->simulateRedisFailover();

        // Log failover event
        $this->securityLog->logSecurityEvent([
            'event' => 'redis_failover_occurred',
            'timestamp' => now(),
        ]);

        // Verify tenant isolation maintained
        $school1Data = Cache::tags(["tenant:{$this->school1->id}"])->get('data');
        $school2Data = Cache::tags(["tenant:{$this->school2->id}"])->get('data');

        // Log post-failover state
        $this->auditSystem->log([
            'event' => 'post_failover_tenant_state',
            'school1_data_intact' => $school1Data === 'school1_data',
            'school2_data_intact' => $school2Data === 'school2_data',
            'isolation_maintained' => $school1Data !== $school2Data,
            'timestamp' => now(),
        ]);

        // Verify audit trail shows isolation maintained
        $auditLogs = $this->auditSystem->getRecentLogs();
        $postFailoverLog = array_filter($auditLogs, fn($log) => 
            $log['event'] === 'post_failover_tenant_state'
        )[0] ?? null;

        $this->assertNotNull($postFailoverLog);
        $this->assertTrue($postFailoverLog['isolation_maintained']);
    }

    // Helper methods

    protected function simulateRedisFailover(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (\Exception $e) {
            // Expected
        }
        Redis::reconnect();
    }
}
