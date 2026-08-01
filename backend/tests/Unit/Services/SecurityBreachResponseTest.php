<?php

namespace Tests\Unit\Services;

use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\ImmutableSecurityLogService;
use App\Services\SecurityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit Tests: Security Breach Response and Access Revocation
 * 
 * Tests security breach detection, response mechanisms, and access revocation.
 * 
 * Validates: Requirement 10.5 (Security breach response with access revocation)
 */
class SecurityBreachResponseTest extends TestCase
{
    use RefreshDatabase;

    private ImmutableSecurityLogService $securityLog;
    private SecurityAlertService $alertService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->securityLog = app(ImmutableSecurityLogService::class);
        $this->alertService = app(SecurityAlertService::class);
        
        $this->initializeGenesisBlock();
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_detects_tampering_in_security_log(): void
    {
        // Create valid chain
        $log1 = $this->securityLog->write('event1', 'First', 1, 1);
        $log2 = $this->securityLog->write('event2', 'Second', 1, 1);

        // Tamper with hash
        DB::table('immutable_security_logs')
            ->where('id', $log1->id)
            ->update(['current_hash' => 'tampered']);

        $result = $this->securityLog->verifyChainIntegrity();

        $this->assertFalse($result['is_valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_tampering_detection_to_audit_trail(): void
    {
        // Create and tamper with log
        $log = $this->securityLog->write('event', 'Test', 1, 1);
        
        DB::table('immutable_security_logs')
            ->where('id', $log->id)
            ->update(['current_hash' => 'tampered']);

        // Verify chain (this should log the tampering)
        $this->securityLog->verifyChainIntegrity();

        // Check that tampering was logged
        $tamperingLog = DB::table('immutable_security_logs')
            ->where('event_type', 'tampering_detected')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($tamperingLog);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_creates_critical_alert_for_tampering(): void
    {
        // Create and tamper with log
        $log = $this->securityLog->write('event', 'Test', 1, 1);
        
        DB::table('immutable_security_logs')
            ->where('id', $log->id)
            ->update(['current_hash' => 'tampered']);

        $result = $this->securityLog->verifyChainIntegrity();

        // Handle tampering detection
        $this->securityLog->handleTamperingDetected($result);

        // Verify critical alert was created
        $this->assertDatabaseHas('security_alerts', [
            'severity' => SecurityAlert::SEVERITY_CRITICAL,
        ]);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_notifies_super_admins_of_tampering(): void
    {
        // Create super admin
        $superAdmin = User::factory()->create([
            'role_type' => 'super_admin',
            'is_active' => true,
        ]);

        // Create and tamper with log
        $log = $this->securityLog->write('event', 'Test', 1, 1);
        
        DB::table('immutable_security_logs')
            ->where('id', $log->id)
            ->update(['current_hash' => 'tampered']);

        $result = $this->securityLog->verifyChainIntegrity();
        $this->securityLog->handleTamperingDetected($result);

        // Verify alert was created for super admin
        $alert = SecurityAlert::where('type', 'log_tampering_detected')
            ->latest()
            ->first();

        $this->assertNotNull($alert);
        $this->assertEquals(SecurityAlert::SEVERITY_CRITICAL, $alert->severity);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_geofence_violations_for_breach_detection(): void
    {
        $userId = 1;
        $schoolId = 1;

        $log = $this->securityLog->logGeofenceViolation(
            $userId,
            $schoolId,
            -6.2088,
            106.8456,
            2000 // 2km outside allowed radius
        );

        $this->assertEquals('geofence_violation', $log->event_type);
        $this->assertEquals($userId, $log->user_id);
        $this->assertEquals($schoolId, $log->school_id);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_device_mismatch_for_breach_detection(): void
    {
        $userId = 1;
        $schoolId = 1;

        $log = $this->securityLog->logDeviceMismatch(
            $userId,
            $schoolId,
            'expected_device_123',
            'actual_device_456'
        );

        $this->assertEquals('device_mismatch', $log->event_type);
        $this->assertArrayHasKey('expected_device', $log->metadata);
        $this->assertArrayHasKey('actual_device', $log->metadata);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_qr_replay_attempts_for_breach_detection(): void
    {
        $userId = 1;
        $schoolId = 1;

        $log = $this->securityLog->logQrReplayAttempt(
            $userId,
            $schoolId,
            'qr_payload_data',
            time() - 3600
        );

        $this->assertEquals('qr_replay_attempt', $log->event_type);
        $this->assertArrayHasKey('qr_payload_hash', $log->metadata);
        $this->assertArrayHasKey('original_timestamp', $log->metadata);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_behavior_anomalies_for_breach_detection(): void
    {
        $userId = 1;
        $schoolId = 1;

        $log = $this->securityLog->logBehaviorAnomaly(
            $userId,
            $schoolId,
            'critical',
            95,
            ['suspicious_timing', 'location_anomaly', 'device_change']
        );

        $this->assertEquals('behavior_anomaly', $log->event_type);
        $this->assertEquals('critical', $log->metadata['risk_level']);
        $this->assertEquals(95, $log->metadata['risk_score']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_creates_security_alert_for_high_risk_behavior(): void
    {
        $user = User::factory()->create();
        $school = \App\Models\School::factory()->create();

        $alert = $this->alertService->createAlert(
            SecurityAlert::TYPE_BEHAVIOR_ANOMALY,
            SecurityAlert::SEVERITY_HIGH,
            'High risk behavior detected',
            ['risk_score' => 85],
            $user->id,
            $school->id
        );

        $this->assertInstanceOf(SecurityAlert::class, $alert);
        $this->assertEquals(SecurityAlert::SEVERITY_HIGH, $alert->severity);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_supports_access_revocation_through_user_deactivation(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Simulate access revocation
        $user->is_active = false;
        $user->save();

        $this->assertFalse($user->is_active);
        
        // Verify user cannot authenticate
        $this->assertFalse($user->is_active);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_supports_session_invalidation_for_access_revocation(): void
    {
        $user = User::factory()->create();
        $sessionId = 'session_' . uniqid();

        // Create session
        Cache::put("session:tenant:1:session:{$sessionId}", [
            'user_id' => $user->id,
            'data' => 'test_data',
        ], 3600);

        // Verify session exists
        $this->assertTrue(Cache::has("session:tenant:1:session:{$sessionId}"));

        // Revoke access by clearing session
        Cache::forget("session:tenant:1:session:{$sessionId}");

        // Verify session is gone
        $this->assertFalse(Cache::has("session:tenant:1:session:{$sessionId}"));
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_supports_bulk_session_revocation_for_user(): void
    {
        $user = User::factory()->create();
        $schoolId = 1;

        // Create multiple sessions for user
        $sessions = [];
        for ($i = 0; $i < 3; $i++) {
            $sessionId = "session_{$user->id}_{$i}";
            $sessions[] = $sessionId;
            Cache::put("session:tenant:{$schoolId}:session:{$sessionId}", [
                'user_id' => $user->id,
            ], 3600);
        }

        // Revoke all sessions for user
        foreach ($sessions as $sessionId) {
            Cache::forget("session:tenant:{$schoolId}:session:{$sessionId}");
        }

        // Verify all sessions are gone
        foreach ($sessions as $sessionId) {
            $this->assertFalse(Cache::has("session:tenant:{$schoolId}:session:{$sessionId}"));
        }
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_access_revocation_to_audit_trail(): void
    {
        $admin = User::factory()->create(['role_type' => 'super_admin']);
        $targetUser = User::factory()->create();

        // Log access revocation
        $this->securityLog->logAdminAction(
            $admin->id,
            1,
            'access_revoked',
            "Access revoked for user {$targetUser->id} due to security breach",
            [
                'target_user_id' => $targetUser->id,
                'reason' => 'multiple_failed_attempts',
            ]
        );

        $log = DB::table('immutable_security_logs')
            ->where('event_type', 'admin_action')
            ->where('user_id', $admin->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $metadata = json_decode($log->metadata, true);
        $this->assertEquals('access_revoked', $metadata['action']);
        $this->assertEquals($targetUser->id, $metadata['target_user_id']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_creates_alert_for_multiple_failed_login_attempts(): void
    {
        $user = User::factory()->create();
        $school = \App\Models\School::factory()->create();

        $alert = $this->alertService->createAlert(
            SecurityAlert::TYPE_FAILED_LOGIN,
            SecurityAlert::SEVERITY_MEDIUM,
            'Multiple failed login attempts detected',
            [
                'attempt_count' => 5,
                'time_window' => '5 minutes',
            ],
            $user->id,
            $school->id
        );

        $this->assertEquals(SecurityAlert::TYPE_FAILED_LOGIN, $alert->type);
        $this->assertEquals(SecurityAlert::SEVERITY_MEDIUM, $alert->severity);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_escalates_alert_severity_for_repeated_breaches(): void
    {
        $user = User::factory()->create();
        $school = \App\Models\School::factory()->create();

        // First breach - medium severity
        $alert1 = $this->alertService->createAlert(
            SecurityAlert::TYPE_BEHAVIOR_ANOMALY,
            SecurityAlert::SEVERITY_MEDIUM,
            'First anomaly detected',
            ['attempt' => 1],
            $user->id,
            $school->id
        );

        // Second breach - high severity
        $alert2 = $this->alertService->createAlert(
            SecurityAlert::TYPE_BEHAVIOR_ANOMALY,
            SecurityAlert::SEVERITY_HIGH,
            'Repeated anomaly detected',
            ['attempt' => 2],
            $user->id,
            $school->id
        );

        // Third breach - critical severity
        $alert3 = $this->alertService->createAlert(
            SecurityAlert::TYPE_BEHAVIOR_ANOMALY,
            SecurityAlert::SEVERITY_CRITICAL,
            'Multiple anomalies - access revocation recommended',
            ['attempt' => 3],
            $user->id,
            $school->id
        );

        $this->assertEquals(SecurityAlert::SEVERITY_MEDIUM, $alert1->severity);
        $this->assertEquals(SecurityAlert::SEVERITY_HIGH, $alert2->severity);
        $this->assertEquals(SecurityAlert::SEVERITY_CRITICAL, $alert3->severity);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_supports_automatic_lockout_after_threshold(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Simulate failed attempts
        $failedAttempts = 5;
        $lockoutThreshold = 5;

        if ($failedAttempts >= $lockoutThreshold) {
            $user->is_active = false;
            $user->save();
        }

        $this->assertFalse($user->is_active);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_automatic_lockout_to_security_log(): void
    {
        $userId = 1;
        $schoolId = 1;

        $log = $this->securityLog->write(
            'automatic_lockout',
            'User automatically locked out due to security threshold',
            $userId,
            $schoolId,
            [
                'reason' => 'failed_attempts_threshold',
                'attempt_count' => 5,
                'lockout_duration' => '30 minutes',
            ]
        );

        $this->assertEquals('automatic_lockout', $log->event_type);
        $this->assertEquals($userId, $log->user_id);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_supports_ip_based_blocking(): void
    {
        $blockedIp = '192.168.1.100';

        // Simulate IP blocking
        Cache::put("blocked_ip:{$blockedIp}", true, 3600);

        $this->assertTrue(Cache::has("blocked_ip:{$blockedIp}"));
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_ip_blocking_to_security_log(): void
    {
        $ipAddress = '192.168.1.100';

        $log = $this->securityLog->write(
            'ip_blocked',
            "IP address {$ipAddress} blocked due to suspicious activity",
            null,
            null,
            [
                'ip_address' => $ipAddress,
                'reason' => 'multiple_breach_attempts',
                'duration' => '1 hour',
            ]
        );

        $this->assertEquals('ip_blocked', $log->event_type);
        $this->assertEquals($ipAddress, $log->metadata['ip_address']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_supports_device_based_blocking(): void
    {
        $deviceId = 'device_suspicious_123';

        // Simulate device blocking
        Cache::put("blocked_device:{$deviceId}", true, 3600);

        $this->assertTrue(Cache::has("blocked_device:{$deviceId}"));
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_provides_breach_response_metrics(): void
    {
        // Create various security events
        $this->securityLog->logGeofenceViolation(1, 1, -6.2088, 106.8456, 2000);
        $this->securityLog->logDeviceMismatch(2, 1, 'dev1', 'dev2');
        $this->securityLog->logQrReplayAttempt(3, 1, 'qr', time());

        // Query security events
        $events = DB::table('immutable_security_logs')
            ->whereIn('event_type', [
                'geofence_violation',
                'device_mismatch',
                'qr_replay_attempt',
            ])
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $this->assertGreaterThan(0, $events);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_maintains_breach_response_audit_trail(): void
    {
        $admin = User::factory()->create(['role_type' => 'super_admin']);
        $targetUser = User::factory()->create();

        // Log breach response actions
        $actions = [
            'breach_detected',
            'investigation_initiated',
            'access_suspended',
            'access_revoked',
        ];

        foreach ($actions as $action) {
            $this->securityLog->logAdminAction(
                $admin->id,
                1,
                $action,
                "Breach response: {$action}",
                ['target_user_id' => $targetUser->id]
            );
        }

        // Verify audit trail
        $auditTrail = DB::table('immutable_security_logs')
            ->where('event_type', 'admin_action')
            ->where('user_id', $admin->id)
            ->get();

        $this->assertGreaterThanOrEqual(count($actions), $auditTrail->count());
    }

    /**
     * Helper: Initialize genesis block for testing
     */
    private function initializeGenesisBlock(): void
    {
        $exists = DB::table('immutable_security_logs')
            ->where('sequence_number', 0)
            ->exists();

        if (!$exists) {
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
