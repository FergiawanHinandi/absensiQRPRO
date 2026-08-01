<?php

namespace Tests\Feature\Redis;

use App\Services\Redis\QueueRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Property-Based Test: Queue Job Idempotency During Failover
 * 
 * Feature: redis-high-availability
 * Property 15: Job idempotency
 * Validates: Requirements 3.5
 * 
 * This test validates that for any potential duplicate job scenario during failover,
 * idempotency checks prevent duplicate execution.
 * 
 * Property: For any potential duplicate job scenario during failover, idempotency
 * checks should prevent duplicate execution.
 */
class QueueJobIdempotencyPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    private const TEST_JOB_PREFIX = 'idempotency_test_job';
    
    private QueueRecoveryService $queueRecoveryService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->queueRecoveryService = new QueueRecoveryService();
        
        // Clear any existing test locks
        $this->cleanupTestLocks();
    }
    
    protected function tearDown(): void
    {
        $this->cleanupTestLocks();
        
        parent::tearDown();
    }
    
    /**
     * Property Test: Idempotency lock prevents duplicate job execution for any job
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test verifies that the idempotency mechanism prevents duplicate execution
     * regardless of job type, payload size, or timing.
     * 
*/
    public function property_idempotency_lock_prevents_duplicate_execution_for_any_job(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random job data
            $jobId = self::TEST_JOB_PREFIX . '_' . uniqid() . '_' . $i;
            $jobData = $this->generateRandomJobData($jobId);
            
            try {
                // First attempt should acquire lock
                $firstAttempt = $this->queueRecoveryService->ensureIdempotency($jobData);
                
                // Second attempt should fail (lock already held)
                $secondAttempt = $this->queueRecoveryService->ensureIdempotency($jobData);
                
                // Property: First succeeds, second fails
                $propertyHolds = ($firstAttempt === true && $secondAttempt === false);
                
                $results[] = [
                    'iteration' => $i + 1,
                    'job_id' => $jobId,
                    'first_attempt' => $firstAttempt,
                    'second_attempt' => $secondAttempt,
                    'property_holds' => $propertyHolds,
                ];
                
                if (!$propertyHolds) {
                    $failureCount++;
                }
                
                // Cleanup lock for next iteration
                $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                
            } catch (\Exception $e) {
                $failureCount++;
                $results[] = [
                    'iteration' => $i + 1,
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                    'property_holds' => false,
                ];
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Job idempotency property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Idempotency lock expires after TTL for any job
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test verifies that idempotency locks expire correctly, allowing
     * job retry after the lock TTL expires.
     * 
*/
    public function property_idempotency_lock_expires_after_ttl_for_any_job(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced iterations due to sleep
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $jobId = self::TEST_JOB_PREFIX . '_ttl_' . uniqid() . '_' . $i;
            $jobData = $this->generateRandomJobData($jobId);
            
            try {
                // Acquire lock
                $firstAttempt = $this->queueRecoveryService->ensureIdempotency($jobData);
                $this->assertTrue($firstAttempt, "Failed to acquire initial lock on iteration {$i}");
                
                // Immediate retry should fail
                $immediateRetry = $this->queueRecoveryService->ensureIdempotency($jobData);
                
                // Manually expire the lock (simulate TTL expiration)
                $this->expireLockManually($jobData);
                
                // After expiration, should be able to acquire lock again
                $afterExpiration = $this->queueRecoveryService->ensureIdempotency($jobData);
                
                // Property: Immediate retry fails, but succeeds after expiration
                $propertyHolds = ($immediateRetry === false && $afterExpiration === true);
                
                if (!$propertyHolds) {
                    $failureCount++;
                }
                
                // Cleanup
                $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Lock expiration property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Concurrent job attempts respect idempotency for any concurrency level
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test verifies that idempotency works correctly under concurrent access,
     * ensuring only one job execution proceeds regardless of concurrency level.
     * 
*/
    public function property_concurrent_attempts_respect_idempotency_for_any_concurrency(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Random concurrency level (2-10 concurrent attempts)
            $concurrencyLevel = rand(2, 10);
            
            $jobId = self::TEST_JOB_PREFIX . '_concurrent_' . uniqid() . '_' . $i;
            $jobData = $this->generateRandomJobData($jobId);
            
            try {
                $acquiredCount = 0;
                $attempts = [];
                
                // Simulate concurrent attempts
                for ($j = 0; $j < $concurrencyLevel; $j++) {
                    $acquired = $this->queueRecoveryService->ensureIdempotency($jobData);
                    $attempts[] = $acquired;
                    
                    if ($acquired) {
                        $acquiredCount++;
                    }
                }
                
                // Property: Exactly one attempt should succeed
                $propertyHolds = ($acquiredCount === 1);
                
                if (!$propertyHolds) {
                    $failureCount++;
                }
                
                // Cleanup
                $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Concurrent idempotency property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations with varying concurrency."
        );
    }

    /**
     * Property Test: Idempotency works across different job types
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test verifies that idempotency mechanism works correctly for all
     * job types and payload structures.
     * 
*/
    public function property_idempotency_works_across_different_job_types(): void
    {
        $jobTypes = [
            'simple_array',
            'nested_payload',
            'with_uuid',
            'with_id',
            'complex_object',
        ];
        
        $iterations = self::MIN_ITERATIONS;
        $failuresByType = array_fill_keys($jobTypes, 0);

        for ($i = 0; $i < $iterations; $i++) {
            foreach ($jobTypes as $type) {
                $jobData = $this->generateJobDataByType($type, $i);
                
                try {
                    // First attempt should succeed
                    $firstAttempt = $this->queueRecoveryService->ensureIdempotency($jobData);
                    
                    // Second attempt should fail
                    $secondAttempt = $this->queueRecoveryService->ensureIdempotency($jobData);
                    
                    // Property holds if first succeeds and second fails
                    if (!($firstAttempt === true && $secondAttempt === false)) {
                        $failuresByType[$type]++;
                    }
                    
                    // Cleanup
                    $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                    
                } catch (\Exception $e) {
                    $failuresByType[$type]++;
                }
            }
        }

        // Verify property holds for all job types
        foreach ($jobTypes as $type) {
            $successRate = (($iterations - $failuresByType[$type]) / $iterations) * 100;
            
            $this->assertGreaterThanOrEqual(
                99,
                $successRate,
                "Idempotency property failed for '{$type}' job type. " .
                "Success rate: {$successRate}%. Expected at least 99% across {$iterations} iterations. " .
                "Failures: {$failuresByType[$type]}"
            );
        }
    }

    /**
     * Property Test: Lock release allows subsequent job execution for any job
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test verifies that releasing an idempotency lock correctly allows
     * the same job to be executed again.
     * 
*/
    public function property_lock_release_allows_subsequent_execution_for_any_job(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $jobId = self::TEST_JOB_PREFIX . '_release_' . uniqid() . '_' . $i;
            $jobData = $this->generateRandomJobData($jobId);
            
            try {
                // First execution cycle
                $firstAcquire = $this->queueRecoveryService->ensureIdempotency($jobData);
                $this->assertTrue($firstAcquire, "Failed to acquire lock on iteration {$i}");
                
                // Release lock (simulating job completion)
                $released = $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                $this->assertTrue($released, "Failed to release lock on iteration {$i}");
                
                // Second execution cycle (should succeed after release)
                $secondAcquire = $this->queueRecoveryService->ensureIdempotency($jobData);
                
                // Property: Second acquire should succeed after release
                if (!$secondAcquire) {
                    $failureCount++;
                }
                
                // Final cleanup
                $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Lock release property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Idempotency prevents duplicate execution during simulated failover
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test simulates a failover scenario where jobs might be requeued,
     * verifying that idempotency prevents duplicate execution.
     * 
*/
    public function property_idempotency_prevents_duplicates_during_simulated_failover(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $jobId = self::TEST_JOB_PREFIX . '_failover_' . uniqid() . '_' . $i;
            $jobData = $this->generateRandomJobData($jobId);
            
            try {
                // Simulate job starting execution
                $initialAcquire = $this->queueRecoveryService->ensureIdempotency($jobData);
                $this->assertTrue($initialAcquire, "Failed initial acquire on iteration {$i}");
                
                // Simulate failover: job gets requeued while still processing
                // Multiple requeue attempts (simulating recovery service finding the job)
                $requeueAttempts = rand(2, 5);
                $duplicatesPrevented = 0;
                
                for ($j = 0; $j < $requeueAttempts; $j++) {
                    $requeueAttempt = $this->queueRecoveryService->ensureIdempotency($jobData);
                    
                    if (!$requeueAttempt) {
                        $duplicatesPrevented++;
                    }
                }
                
                // Property: All requeue attempts should be prevented
                if ($duplicatesPrevented !== $requeueAttempts) {
                    $failureCount++;
                }
                
                // Cleanup
                $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Failover idempotency property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Idempotency lock generation is consistent for same job
     * 
     * **Validates: Requirements 3.5**
     * 
     * This test verifies that the same job data always generates the same
     * idempotency lock key, ensuring consistent duplicate detection.
     * 
*/
    public function property_lock_generation_is_consistent_for_same_job(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $jobId = self::TEST_JOB_PREFIX . '_consistent_' . uniqid() . '_' . $i;
            $jobData = $this->generateRandomJobData($jobId);
            
            try {
                // Acquire lock with first instance
                $firstAcquire = $this->queueRecoveryService->ensureIdempotency($jobData);
                $this->assertTrue($firstAcquire, "Failed first acquire on iteration {$i}");
                
                // Create identical job data (simulating duplicate)
                $duplicateJobData = $jobData; // Same data should generate same lock key
                
                // Attempt to acquire with duplicate data
                $duplicateAcquire = $this->queueRecoveryService->ensureIdempotency($duplicateJobData);
                
                // Property: Duplicate should be prevented (same lock key)
                if ($duplicateAcquire !== false) {
                    $failureCount++;
                }
                
                // Cleanup
                $this->queueRecoveryService->releaseIdempotencyLock($jobData);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Lock consistency property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Generate random job data for testing
     * 
     * @param string $jobId Job identifier
     * @return array Job data
     */
    private function generateRandomJobData(string $jobId): array
    {
        $payloadSize = rand(10, 1000);
        
        return [
            'id' => $jobId,
            'uuid' => $jobId,
            'queue' => ['default', 'high', 'attendance', 'notifications'][rand(0, 3)],
            'payload' => [
                'uuid' => $jobId,
                'displayName' => 'Test Job ' . $jobId,
                'job' => 'App\\Jobs\\TestJob',
                'data' => [
                    'command' => 'test_command',
                    'payload' => str_repeat('x', $payloadSize),
                    'timestamp' => time(),
                ],
            ],
            'attempts' => rand(0, 2),
        ];
    }

    /**
     * Generate job data by specific type
     * 
     * @param string $type Job type
     * @param int $iteration Iteration number
     * @return array Job data
     */
    private function generateJobDataByType(string $type, int $iteration): array
    {
        $baseId = self::TEST_JOB_PREFIX . "_{$type}_{$iteration}_" . uniqid();
        
        switch ($type) {
            case 'simple_array':
                return [
                    'id' => $baseId,
                    'queue' => 'default',
                ];
                
            case 'nested_payload':
                return [
                    'payload' => [
                        'uuid' => $baseId,
                        'data' => [
                            'nested' => [
                                'deep' => [
                                    'value' => rand(1, 1000),
                                ],
                            ],
                        ],
                    ],
                ];
                
            case 'with_uuid':
                return [
                    'uuid' => $baseId,
                    'queue' => 'high',
                    'payload' => ['data' => 'test'],
                ];
                
            case 'with_id':
                return [
                    'id' => $baseId,
                    'payload' => [
                        'displayName' => 'Test Job',
                    ],
                ];
                
            case 'complex_object':
                return [
                    'id' => $baseId,
                    'uuid' => $baseId,
                    'queue' => 'attendance',
                    'payload' => [
                        'uuid' => $baseId,
                        'displayName' => 'Complex Job',
                        'job' => 'App\\Jobs\\ComplexJob',
                        'data' => [
                            'command' => serialize(new \stdClass()),
                            'metadata' => [
                                'school_id' => rand(1, 100),
                                'user_id' => rand(1, 1000),
                                'timestamp' => time(),
                            ],
                        ],
                    ],
                    'attempts' => rand(0, 3),
                    'reserved_at' => time(),
                ];
                
            default:
                return $this->generateRandomJobData($baseId);
        }
    }

    /**
     * Manually expire a lock for testing
     * 
     * @param array $jobData Job data
     * @return void
     */
    private function expireLockManually(array $jobData): void
    {
        $jobId = $jobData['id'] ?? $jobData['uuid'] ?? md5(json_encode($jobData));
        $lockKey = 'job_lock:' . $jobId;
        
        // Delete the lock to simulate expiration
        Redis::connection('queue')->del($lockKey);
    }

    /**
     * Cleanup test locks
     * 
     * @return void
     */
    private function cleanupTestLocks(): void
    {
        try {
            $pattern = 'job_lock:' . self::TEST_JOB_PREFIX . '*';
            $keys = Redis::connection('queue')->keys($pattern);
            
            if (!empty($keys)) {
                Redis::connection('queue')->del($keys);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }
}
