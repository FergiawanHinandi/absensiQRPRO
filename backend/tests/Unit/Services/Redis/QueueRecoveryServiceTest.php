<?php

namespace Tests\Unit\Services\Redis;

use Tests\TestCase;
use App\Services\Redis\QueueRecoveryService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

/**
 * Queue Recovery Service Unit Tests
 * 
 * Tests job recovery mechanisms, idempotency checks, and retry logic.
 * 
 * Requirements: 3.1, 3.3, 3.5
 */
class QueueRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;
    
    private QueueRecoveryService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new QueueRecoveryService();
        
        // Clear Redis before each test
        Redis::connection('queue')->flushdb();
    }
    
    protected function tearDown(): void
    {
        // Clean up Redis after each test
        Redis::connection('queue')->flushdb();
        
        parent::tearDown();
    }
    
    /**
     * Test idempotency lock acquisition
     * 
*/
    public function it_acquires_idempotency_lock_for_new_job(): void
    {
        $job = [
            'id' => 'test-job-123',
            'payload' => ['data' => 'test'],
        ];
        
        $acquired = $this->service->ensureIdempotency($job);
        
        $this->assertTrue($acquired, 'Should acquire lock for new job');
    }
    
    /**
     * Test idempotency lock prevents duplicate execution
     * 
*/
    public function it_prevents_duplicate_job_execution(): void
    {
        $job = [
            'id' => 'test-job-456',
            'payload' => ['data' => 'test'],
        ];
        
        // First acquisition should succeed
        $firstAcquired = $this->service->ensureIdempotency($job);
        $this->assertTrue($firstAcquired);
        
        // Second acquisition should fail (lock already exists)
        $secondAcquired = $this->service->ensureIdempotency($job);
        $this->assertFalse($secondAcquired, 'Should not acquire lock for duplicate job');
    }
    
    /**
     * Test idempotency lock release
     * 
*/
    public function it_releases_idempotency_lock(): void
    {
        $job = [
            'id' => 'test-job-789',
            'payload' => ['data' => 'test'],
        ];
        
        // Acquire lock
        $this->service->ensureIdempotency($job);
        
        // Release lock
        $released = $this->service->releaseIdempotencyLock($job);
        $this->assertTrue($released);
        
        // Should be able to acquire again after release
        $reacquired = $this->service->ensureIdempotency($job);
        $this->assertTrue($reacquired, 'Should acquire lock after release');
    }
    
    /**
     * Test idempotency lock expires automatically
     * 
*/
    public function it_expires_idempotency_lock_after_ttl(): void
    {
        $job = [
            'id' => 'test-job-ttl',
            'payload' => ['data' => 'test'],
        ];
        
        // Acquire lock
        $this->service->ensureIdempotency($job);
        
        // Check lock exists
        $lockKey = 'job_lock:test-job-ttl';
        $prefix = config('database.redis.options.prefix', '');
        $fullKey = $prefix . $lockKey;
        
        $ttl = Redis::connection('queue')->ttl($fullKey);
        
        $this->assertGreaterThan(0, $ttl, 'Lock should have TTL set');
        $this->assertLessThanOrEqual(300, $ttl, 'Lock TTL should not exceed 5 minutes');
    }
    
    /**
     * Test queue health monitoring
     * 
*/
    public function it_monitors_queue_health(): void
    {
        $health = $this->service->monitorQueueHealth();
        
        $this->assertIsArray($health);
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('queues', $health);
        $this->assertArrayHasKey('issues', $health);
        
        $this->assertContains($health['status'], ['healthy', 'degraded', 'unhealthy']);
    }
    
    /**
     * Test queue health returns healthy status for empty queues
     * 
*/
    public function it_returns_healthy_status_for_empty_queues(): void
    {
        $health = $this->service->monitorQueueHealth();
        
        $this->assertEquals('healthy', $health['status']);
        $this->assertEmpty($health['issues']);
    }
    
    /**
     * Test failed job recovery with no failed jobs
     * 
*/
    public function it_handles_recovery_with_no_failed_jobs(): void
    {
        $stats = $this->service->recoverFailedJobs();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_found', $stats);
        $this->assertArrayHasKey('recovered', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertArrayHasKey('failed', $stats);
        
        $this->assertEquals(0, $stats['total_found']);
    }
    
    /**
     * Test job recovery with database failed jobs
     * 
*/
    public function it_recovers_failed_jobs_from_database(): void
    {
        // Create a failed job in database
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-failed-job-1',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => 'test-failed-job-1',
                'displayName' => 'TestJob',
                'job' => 'TestJob',
                'maxTries' => 3,
                'attempts' => 1,
                'data' => ['test' => 'data'],
            ]),
            'exception' => 'Test exception',
            'failed_at' => Carbon::now(),
        ]);
        
        $stats = $this->service->recoverFailedJobs();
        
        $this->assertGreaterThan(0, $stats['total_found']);
    }
    
    /**
     * Test exponential backoff calculation
     * 
*/
    public function it_calculates_exponential_backoff(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateBackoff');
        $method->setAccessible(true);
        
        // Test backoff increases exponentially
        $backoff0 = $method->invoke($this->service, 0);
        $backoff1 = $method->invoke($this->service, 1);
        $backoff2 = $method->invoke($this->service, 2);
        
        $this->assertEquals(10, $backoff0); // 10 * 2^0 = 10
        $this->assertEquals(20, $backoff1); // 10 * 2^1 = 20
        $this->assertEquals(40, $backoff2); // 10 * 2^2 = 40
        
        // Test max backoff cap
        $backoffMax = $method->invoke($this->service, 10);
        $this->assertEquals(300, $backoffMax); // Capped at 300 seconds
    }
    
    /**
     * Test job ID generation from different formats
     * 
*/
    public function it_generates_job_id_from_various_formats(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateJobId');
        $method->setAccessible(true);
        
        // Test with array containing id
        $job1 = ['id' => 'test-123'];
        $id1 = $method->invoke($this->service, $job1);
        $this->assertEquals('test-123', $id1);
        
        // Test with array containing uuid
        $job2 = ['uuid' => 'test-uuid-456'];
        $id2 = $method->invoke($this->service, $job2);
        $this->assertEquals('test-uuid-456', $id2);
        
        // Test with array containing payload with uuid
        $job3 = ['payload' => ['uuid' => 'test-payload-789']];
        $id3 = $method->invoke($this->service, $job3);
        $this->assertEquals('test-payload-789', $id3);
        
        // Test fallback to hash
        $job4 = ['data' => 'some-data'];
        $id4 = $method->invoke($this->service, $job4);
        $this->assertIsString($id4);
        $this->assertEquals(32, strlen($id4)); // MD5 hash length
    }
    
    /**
     * Test max attempts extraction from job payload
     * 
*/
    public function it_extracts_max_attempts_from_job(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getMaxAttempts');
        $method->setAccessible(true);
        
        // Test with maxTries in payload
        $job1 = ['payload' => ['maxTries' => 5]];
        $maxAttempts1 = $method->invoke($this->service, $job1);
        $this->assertEquals(5, $maxAttempts1);
        
        // Test with maxTries in data
        $job2 = ['payload' => ['data' => ['maxTries' => 7]]];
        $maxAttempts2 = $method->invoke($this->service, $job2);
        $this->assertEquals(7, $maxAttempts2);
        
        // Test default value
        $job3 = ['payload' => []];
        $maxAttempts3 = $method->invoke($this->service, $job3);
        $this->assertEquals(3, $maxAttempts3); // Default
    }
    
    /**
     * Test should retry logic
     * 
*/
    public function it_determines_if_job_should_retry(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldRetry');
        $method->setAccessible(true);
        
        // Job with attempts below max should retry
        $job1 = [
            'attempts' => 1,
            'payload' => ['maxTries' => 3],
        ];
        $shouldRetry1 = $method->invoke($this->service, $job1);
        $this->assertTrue($shouldRetry1);
        
        // Job with attempts at max should not retry
        $job2 = [
            'attempts' => 3,
            'payload' => ['maxTries' => 3],
        ];
        $shouldRetry2 = $method->invoke($this->service, $job2);
        $this->assertFalse($shouldRetry2);
        
        // Job with attempts exceeding max should not retry
        $job3 = [
            'attempts' => 5,
            'payload' => ['maxTries' => 3],
        ];
        $shouldRetry3 = $method->invoke($this->service, $job3);
        $this->assertFalse($shouldRetry3);
    }
    
    /**
     * Test attempts extraction from job payload
     * 
*/
    public function it_extracts_attempts_from_job_payload(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractAttempts');
        $method->setAccessible(true);
        
        // Test with attempts in payload root
        $payload1 = ['attempts' => 2];
        $attempts1 = $method->invoke($this->service, $payload1);
        $this->assertEquals(2, $attempts1);
        
        // Test with attempts in data
        $payload2 = ['data' => ['attempts' => 3]];
        $attempts2 = $method->invoke($this->service, $payload2);
        $this->assertEquals(3, $attempts2);
        
        // Test with no attempts (default to 0)
        $payload3 = ['data' => ['other' => 'value']];
        $attempts3 = $method->invoke($this->service, $payload3);
        $this->assertEquals(0, $attempts3);
    }
    
    /**
     * Test job recovery skips jobs exceeding max attempts
     * 
*/
    public function it_skips_jobs_exceeding_max_attempts(): void
    {
        // Create a failed job with max attempts exceeded
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-max-attempts',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => 'test-max-attempts',
                'displayName' => 'TestJob',
                'job' => 'TestJob',
                'maxTries' => 3,
                'attempts' => 5, // Exceeds max
                'data' => ['test' => 'data'],
            ]),
            'exception' => 'Test exception',
            'failed_at' => Carbon::now(),
        ]);
        
        $stats = $this->service->recoverFailedJobs();
        
        $this->assertGreaterThan(0, $stats['total_found']);
        $this->assertGreaterThan(0, $stats['skipped']);
        $this->assertEquals(0, $stats['recovered']);
    }
    
    /**
     * Test idempotency lock with different job formats
     * 
*/
    public function it_handles_idempotency_for_different_job_formats(): void
    {
        // Test with simple array
        $job1 = ['id' => 'format-test-1'];
        $acquired1 = $this->service->ensureIdempotency($job1);
        $this->assertTrue($acquired1);
        
        // Test with uuid
        $job2 = ['uuid' => 'format-test-2'];
        $acquired2 = $this->service->ensureIdempotency($job2);
        $this->assertTrue($acquired2);
        
        // Test with nested payload
        $job3 = ['payload' => ['uuid' => 'format-test-3']];
        $acquired3 = $this->service->ensureIdempotency($job3);
        $this->assertTrue($acquired3);
    }
    
    /**
     * Test lock release for non-existent lock
     * 
*/
    public function it_handles_release_of_non_existent_lock(): void
    {
        $job = ['id' => 'non-existent-lock'];
        
        // Try to release lock that doesn't exist
        $released = $this->service->releaseIdempotencyLock($job);
        
        // Should return false since lock doesn't exist
        $this->assertFalse($released);
    }
    
    /**
     * Test queue health detects stuck jobs
     * 
*/
    public function it_detects_stuck_jobs_in_queue_health(): void
    {
        $prefix = config('database.redis.options.prefix', '');
        $reservedKey = $prefix . 'queues:default:reserved';
        
        // Add a stuck job (reserved more than 5 minutes ago)
        $stuckTimestamp = Carbon::now()->subMinutes(10)->timestamp;
        $jobData = json_encode([
            'uuid' => 'stuck-job-1',
            'displayName' => 'StuckJob',
            'data' => ['test' => 'data'],
        ]);
        
        Redis::connection('queue')->zadd($reservedKey, $stuckTimestamp, $jobData);
        
        $health = $this->service->monitorQueueHealth();
        
        $this->assertEquals('degraded', $health['status']);
        $this->assertNotEmpty($health['issues']);
        $this->assertEquals('stuck_jobs', $health['issues'][0]['type']);
    }
    
    /**
     * Test recovery handles multiple failed jobs
     * 
*/
    public function it_recovers_multiple_failed_jobs(): void
    {
        // Create multiple failed jobs
        for ($i = 1; $i <= 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => "test-multi-job-{$i}",
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode([
                    'uuid' => "test-multi-job-{$i}",
                    'displayName' => 'TestJob',
                    'job' => 'TestJob',
                    'maxTries' => 3,
                    'attempts' => 1,
                    'data' => ['test' => "data-{$i}"],
                ]),
                'exception' => 'Test exception',
                'failed_at' => Carbon::now(),
            ]);
        }
        
        $stats = $this->service->recoverFailedJobs();
        
        $this->assertEquals(3, $stats['total_found']);
        $this->assertGreaterThan(0, $stats['recovered'] + $stats['skipped']);
    }
    
    /**
     * Test backoff calculation never exceeds maximum
     * 
*/
    public function it_caps_backoff_at_maximum_value(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateBackoff');
        $method->setAccessible(true);
        
        // Test with very high attempts
        $backoff = $method->invoke($this->service, 100);
        
        $this->assertEquals(300, $backoff, 'Backoff should be capped at 300 seconds');
    }
    
    /**
     * Test job reconstruction from payload
     * 
*/
    public function it_reconstructs_job_from_payload(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('reconstructJob');
        $method->setAccessible(true);
        
        // Test with displayName (serialized job)
        $payload1 = [
            'displayName' => 'TestJob',
            'job' => 'TestJob',
            'data' => ['test' => 'data'],
        ];
        $reconstructed1 = $method->invoke($this->service, $payload1);
        $this->assertEquals($payload1, $reconstructed1);
        
        // Test with plain payload
        $payload2 = ['data' => ['test' => 'data']];
        $reconstructed2 = $method->invoke($this->service, $payload2);
        $this->assertEquals($payload2, $reconstructed2);
    }
    
    /**
     * Test queue health monitoring for multiple queues
     * 
*/
    public function it_monitors_health_for_all_queues(): void
    {
        $health = $this->service->monitorQueueHealth();
        
        $expectedQueues = ['high', 'default', 'attendance', 'notifications', 'low'];
        
        foreach ($expectedQueues as $queue) {
            $this->assertArrayHasKey($queue, $health['queues']);
            $this->assertArrayHasKey('pending', $health['queues'][$queue]);
            $this->assertArrayHasKey('reserved', $health['queues'][$queue]);
        }
    }
    
    /**
     * Test idempotency lock allows job after TTL expiration
     * 
*/
    public function it_allows_job_execution_after_lock_expiration(): void
    {
        $job = ['id' => 'ttl-test-job'];
        
        // Acquire lock
        $this->service->ensureIdempotency($job);
        
        // Manually expire the lock by deleting it (simulating TTL expiration)
        $this->service->releaseIdempotencyLock($job);
        
        // Should be able to acquire again
        $reacquired = $this->service->ensureIdempotency($job);
        $this->assertTrue($reacquired);
    }
    
    /**
     * Test recovery handles jobs with missing payload data
     * 
*/
    public function it_handles_jobs_with_missing_payload_gracefully(): void
    {
        // Create a failed job with minimal payload
        DB::table('failed_jobs')->insert([
            'uuid' => 'minimal-payload-job',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => 'minimal-payload-job',
            ]),
            'exception' => 'Test exception',
            'failed_at' => Carbon::now(),
        ]);
        
        // Should not throw exception
        $stats = $this->service->recoverFailedJobs();
        
        $this->assertIsArray($stats);
        $this->assertGreaterThan(0, $stats['total_found']);
    }
    
    /**
     * Test recovery statistics are accurate
     * 
*/
    public function it_provides_accurate_recovery_statistics(): void
    {
        $stats = $this->service->recoverFailedJobs();
        
        // Verify all required keys exist
        $this->assertArrayHasKey('total_found', $stats);
        $this->assertArrayHasKey('recovered', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertArrayHasKey('failed', $stats);
        
        // Verify statistics are non-negative
        $this->assertGreaterThanOrEqual(0, $stats['total_found']);
        $this->assertGreaterThanOrEqual(0, $stats['recovered']);
        $this->assertGreaterThanOrEqual(0, $stats['skipped']);
        $this->assertGreaterThanOrEqual(0, $stats['failed']);
        
        // Verify total equals sum of outcomes
        $this->assertEquals(
            $stats['total_found'],
            $stats['recovered'] + $stats['skipped'] + $stats['failed']
        );
    }
}
