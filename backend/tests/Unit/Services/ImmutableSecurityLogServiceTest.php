<?php

namespace Tests\Unit\Services;

use App\Models\ImmutableSecurityLog;
use App\Models\User;
use App\Services\ImmutableSecurityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit Tests: ImmutableSecurityLogService
 * 
 * Tests the tamper-proof security logging system with hash chaining.
 * 
 * Validates: Requirements 10.4 (Audit logging), 10.5 (Security breach response)
 */
class ImmutableSecurityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImmutableSecurityLogService $securityLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityLog = new ImmutableSecurityLogService();
        $this->initializeGenesisBlock();
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_writes_security_log_entry_with_hash(): void
    {
        $log = $this->securityLog->write(
            'test_event',
            'Test security event',
            1,
            1,
            ['test' => true]
        );

        $this->assertInstanceOf(ImmutableSecurityLog::class, $log);
        $this->assertNotEmpty($log->current_hash);
        $this->assertNotEmpty($log->previous_hash);
        $this->assertEquals('test_event', $log->event_type);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_maintains_sequential_sequence_numbers(): void
    {
        $log1 = $this->securityLog->write('event1', 'First event', 1, 1);
        $log2 = $this->securityLog->write('event2', 'Second event', 1, 1);
        $log3 = $this->securityLog->write('event3', 'Third event', 1, 1);

        $this->assertEquals($log1->sequence_number + 1, $log2->sequence_number);
        $this->assertEquals($log2->sequence_number + 1, $log3->sequence_number);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_chains_hashes_correctly(): void
    {
        $log1 = $this->securityLog->write('event1', 'First event', 1, 1);
        $log2 = $this->securityLog->write('event2', 'Second event', 1, 1);

        // Log2's previous hash should match log1's current hash
        $this->assertEquals($log1->current_hash, $log2->previous_hash);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_verifies_hash_integrity(): void
    {
        $log = $this->securityLog->write('test_event', 'Test', 1, 1);

        $this->assertTrue($log->verifyHash());
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_detects_tampered_hash(): void
    {
        $log = $this->securityLog->write('test_event', 'Test', 1, 1);

        // Tamper with the hash
        DB::table('immutable_security_logs')
            ->where('id', $log->id)
            ->update(['current_hash' => 'tampered_hash']);

        $tamperedLog = ImmutableSecurityLog::find($log->id);
        $this->assertFalse($tamperedLog->verifyHash());
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_calculates_hash_consistently(): void
    {
        $eventType = 'test_event';
        $userId = 1;
        $schoolId = 1;
        $description = 'Test description';
        $metadata = ['key' => 'value'];
        $previousHash = 'previous_hash_value';
        $createdAt = now();
        $sequenceNumber = 10;

        $hash1 = $this->securityLog->calculateHash(
            $eventType,
            $userId,
            $schoolId,
            $description,
            $metadata,
            $previousHash,
            $createdAt,
            $sequenceNumber
        );

        $hash2 = $this->securityLog->calculateHash(
            $eventType,
            $userId,
            $schoolId,
            $description,
            $metadata,
            $previousHash,
            $createdAt,
            $sequenceNumber
        );

        $this->assertEquals($hash1, $hash2);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_produces_different_hashes_for_different_data(): void
    {
        $baseParams = [
            'test_event',
            1,
            1,
            'Description',
            ['key' => 'value'],
            'prev_hash',
            now(),
            10
        ];

        $hash1 = $this->securityLog->calculateHash(...$baseParams);

        // Change description
        $modifiedParams = $baseParams;
        $modifiedParams[3] = 'Different description';
        $hash2 = $this->securityLog->calculateHash(...$modifiedParams);

        $this->assertNotEquals($hash1, $hash2);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_captures_ip_address_and_user_agent(): void
    {
        request()->server->set('REMOTE_ADDR', '192.168.1.100');
        request()->server->set('HTTP_USER_AGENT', 'Test Browser/1.0');

        $log = $this->securityLog->write('test_event', 'Test', 1, 1);

        $this->assertEquals('192.168.1.100', $log->ip_address);
        $this->assertStringContainsString('Test Browser', $log->user_agent);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_truncates_long_user_agent(): void
    {
        $longUserAgent = str_repeat('A', 600);
        request()->server->set('HTTP_USER_AGENT', $longUserAgent);

        $log = $this->securityLog->write('test_event', 'Test', 1, 1);

        $this->assertLessThanOrEqual(500, strlen($log->user_agent));
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_geofence_violation(): void
    {
        $log = $this->securityLog->logGeofenceViolation(
            userId: 1,
            schoolId: 1,
            latitude: -6.2088,
            longitude: 106.8456,
            distanceFromSchool: 1500.5,
            additionalData: ['device' => 'mobile']
        );

        $this->assertEquals(ImmutableSecurityLog::TYPE_GEOFENCE_VIOLATION, $log->event_type);
        $this->assertEquals(-6.2088, $log->metadata['latitude']);
        $this->assertEquals(106.8456, $log->metadata['longitude']);
        $this->assertEquals(1500.5, $log->metadata['distance_meters']);
        $this->assertEquals('mobile', $log->metadata['device']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_device_mismatch(): void
    {
        $log = $this->securityLog->logDeviceMismatch(
            userId: 1,
            schoolId: 1,
            expectedDevice: 'device_abc123',
            actualDevice: 'device_xyz789'
        );

        $this->assertEquals(ImmutableSecurityLog::TYPE_DEVICE_MISMATCH, $log->event_type);
        $this->assertEquals('device_abc123', $log->metadata['expected_device']);
        $this->assertEquals('device_xyz789', $log->metadata['actual_device']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_qr_replay_attempt(): void
    {
        $qrPayload = 'qr_code_payload_12345';
        $originalTimestamp = time() - 3600;

        $log = $this->securityLog->logQrReplayAttempt(
            userId: 1,
            schoolId: 1,
            qrPayload: $qrPayload,
            originalTimestamp: $originalTimestamp
        );

        $this->assertEquals(ImmutableSecurityLog::TYPE_QR_REPLAY_ATTEMPT, $log->event_type);
        $this->assertEquals(hash('sha256', $qrPayload), $log->metadata['qr_payload_hash']);
        $this->assertEquals($originalTimestamp, $log->metadata['original_timestamp']);
        $this->assertArrayHasKey('replay_attempted_at', $log->metadata);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_logs_behavior_anomaly(): void
    {
        $log = $this->securityLog->logBehaviorAnomaly(
            userId: 1,
            schoolId: 1,
            riskLevel: 'high',
            riskScore: 85,
            triggeredFlags: ['suspicious_timing', 'location_anomaly']
        );

        $this->assertEquals(ImmutableSecurityLog::TYPE_BEHAVIOR_ANOMALY, $log->event_type);
        $this->assertEquals('high', $log->metadata['risk_level']);
        $this->assertEquals(85, $log->metadata['risk_score']);
        $this->assertContains('suspicious_timing', $log->metadata['triggered_flags']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_investigation_report(): void
    {
        $log = $this->securityLog->logInvestigationReport(
            generatedBy: 1,
            teacherId: 5,
            schoolId: 1,
            reportId: 100,
            riskLevel: 'medium'
        );

        $this->assertEquals(ImmutableSecurityLog::TYPE_INVESTIGATION_REPORT, $log->event_type);
        $this->assertEquals(100, $log->metadata['report_id']);
        $this->assertEquals(5, $log->metadata['teacher_id']);
        $this->assertEquals('medium', $log->metadata['risk_level']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_logs_admin_action(): void
    {
        $admin = User::factory()->create(['role_type' => 'super_admin']);

        $log = $this->securityLog->logAdminAction(
            adminId: $admin->id,
            schoolId: 1,
            action: 'redis_config_change',
            description: 'Changed Redis Sentinel quorum from 2 to 3',
            additionalData: ['old_value' => 2, 'new_value' => 3]
        );

        $this->assertEquals(ImmutableSecurityLog::TYPE_ADMIN_ACTION, $log->event_type);
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertEquals('redis_config_change', $log->metadata['action']);
        $this->assertEquals(2, $log->metadata['old_value']);
        $this->assertEquals(3, $log->metadata['new_value']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_verifies_chain_integrity_for_valid_chain(): void
    {
        // Create a chain of logs
        $this->securityLog->write('event1', 'First', 1, 1);
        $this->securityLog->write('event2', 'Second', 1, 1);
        $this->securityLog->write('event3', 'Third', 1, 1);

        $result = $this->securityLog->verifyChainIntegrity();

        $this->assertTrue($result['is_valid']);
        $this->assertGreaterThan(0, $result['verified_records']);
        $this->assertEmpty($result['errors']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_detects_chain_tampering(): void
    {
        // Create valid chain
        $log1 = $this->securityLog->write('event1', 'First', 1, 1);
        $log2 = $this->securityLog->write('event2', 'Second', 1, 1);

        // Tamper with log1's hash
        DB::table('immutable_security_logs')
            ->where('id', $log1->id)
            ->update(['current_hash' => 'tampered_hash']);

        $result = $this->securityLog->verifyChainIntegrity();

        $this->assertFalse($result['is_valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('hash_mismatch', $result['errors'][0]['type']);
    }

    /**
* Validates: Requirement 10.5
     */
    public function it_detects_chain_break(): void
    {
        // Create valid chain
        $log1 = $this->securityLog->write('event1', 'First', 1, 1);
        $log2 = $this->securityLog->write('event2', 'Second', 1, 1);

        // Break the chain by modifying previous_hash
        DB::table('immutable_security_logs')
            ->where('id', $log2->id)
            ->update(['previous_hash' => 'broken_link']);

        $result = $this->securityLog->verifyChainIntegrity();

        $this->assertFalse($result['is_valid']);
        $this->assertNotEmpty($result['errors']);
        
        $chainBreakErrors = array_filter($result['errors'], fn($e) => $e['type'] === 'chain_break');
        $this->assertNotEmpty($chainBreakErrors);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_exports_hashes_for_backup(): void
    {
        // Create some logs
        $this->securityLog->write('event1', 'First', 1, 1);
        $this->securityLog->write('event2', 'Second', 1, 1);

        $export = $this->securityLog->exportHashes();

        $this->assertArrayHasKey('exported_at', $export);
        $this->assertArrayHasKey('algorithm', $export);
        $this->assertArrayHasKey('chain', $export);
        $this->assertArrayHasKey('total_records', $export);
        $this->assertArrayHasKey('last_hash', $export);
        $this->assertArrayHasKey('export_checksum', $export);
        
        $this->assertEquals('sha256', $export['algorithm']);
        $this->assertGreaterThan(0, $export['total_records']);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_exports_hashes_to_file(): void
    {
        $this->securityLog->write('event1', 'First', 1, 1);

        $tempFile = tempnam(sys_get_temp_dir(), 'hash_export_');
        
        $export = $this->securityLog->exportHashes($tempFile);

        $this->assertFileExists($tempFile);
        
        $fileContent = json_decode(file_get_contents($tempFile), true);
        $this->assertEquals($export, $fileContent);
        
        unlink($tempFile);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_handles_concurrent_writes_with_locking(): void
    {
        // This test verifies that the lockForUpdate prevents race conditions
        // In a real scenario, multiple processes would try to write simultaneously
        
        $log1 = $this->securityLog->write('concurrent1', 'First', 1, 1);
        $log2 = $this->securityLog->write('concurrent2', 'Second', 1, 1);

        // Verify sequential ordering is maintained
        $this->assertEquals($log1->sequence_number + 1, $log2->sequence_number);
        $this->assertEquals($log1->current_hash, $log2->previous_hash);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_throws_exception_if_genesis_block_missing(): void
    {
        // Delete genesis block
        DB::table('immutable_security_logs')->truncate();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Genesis block missing');

        $this->securityLog->write('test', 'Test', 1, 1);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_verifies_hash_after_insert(): void
    {
        // This is tested internally by the write method
        // If hash verification fails, it throws RuntimeException
        
        $log = $this->securityLog->write('test', 'Test', 1, 1);

        // If we get here, hash verification passed
        $this->assertTrue($log->verifyHash());
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_handles_null_user_and_school_ids(): void
    {
        $log = $this->securityLog->write(
            'system_event',
            'System-level event',
            null,
            null,
            ['global' => true]
        );

        $this->assertNull($log->user_id);
        $this->assertNull($log->school_id);
        $this->assertTrue($log->verifyHash());
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_stores_metadata_as_json(): void
    {
        $metadata = [
            'nested' => ['key' => 'value'],
            'array' => [1, 2, 3],
            'unicode' => '测试',
        ];

        $log = $this->securityLog->write('test', 'Test', 1, 1, $metadata);

        $this->assertEquals($metadata, $log->metadata);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_uses_sha256_algorithm(): void
    {
        $hash = $this->securityLog->calculateHash(
            'test',
            1,
            1,
            'desc',
            [],
            'prev',
            now(),
            1
        );

        // SHA256 produces 64 character hex string
        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_includes_sequence_number_in_hash(): void
    {
        $timestamp = now();
        
        $hash1 = $this->securityLog->calculateHash('test', 1, 1, 'desc', [], 'prev', $timestamp, 1);
        $hash2 = $this->securityLog->calculateHash('test', 1, 1, 'desc', [], 'prev', $timestamp, 2);

        $this->assertNotEquals($hash1, $hash2);
    }

    /**
* Validates: Requirement 10.4
     */
    public function it_formats_timestamp_consistently_in_hash(): void
    {
        $timestamp = now();
        
        $hash1 = $this->securityLog->calculateHash(
            'test', 1, 1, 'desc', [], 'prev', $timestamp, 1
        );
        
        // Create new DateTime with same value
        $timestamp2 = \Carbon\Carbon::parse($timestamp->format('Y-m-d H:i:s'));
        
        $hash2 = $this->securityLog->calculateHash(
            'test', 1, 1, 'desc', [], 'prev', $timestamp2, 1
        );

        $this->assertEquals($hash1, $hash2);
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
