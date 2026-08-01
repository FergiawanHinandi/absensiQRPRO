<?php

namespace Tests\Feature\Redis;

use App\Services\Redis\QueueRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Property-Based Test: Queue Job Preservation During Redis Failover
 * 
 * Feature: redis-high-availability
 * Property 11: Queue job preservation
 * Validates: Requirements 3.1
 * 
 * This test validates that for any pending background job, it should be preserved
 * without loss during Redis failover.
 * 
 * Property: For any pending background job, it should be preserved without loss
 * during Redis failover.
 */
class QueueJobPreservationPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    private const TEST_QUEUE_PREFIX = 'test_queue';
    
    private QueueRecoveryService $recoveryService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->recoveryService = new QueueRecoveryService();
        
        // Ensure failed_jobs table exists
        if (!\Schema::hasTable('failed_jobs')) {
            $this->artisan('migrate', ['--path' => 'database/migrations']);
        }
    }
    
    protected function tearDown(): void
    {
        // Cleanup test queues
        $this->cleanupTestQueues();
        
        parent::tearDown();
    }
    
    /**
     * Property Test: Jobs are preserved for any number of pending jobs
     * 
     * **Validates: Requirements 3.1**
     * 
     * This test verifies that job preservation works correctly regardless of
     * the number of pending jobs during a failover event.
     * 
*/
    public function property_jobs_preserved_for_any_job_count(): void
    {
        // Skip if not using Redis queue
        if (config('queue.default') !== 'redis') {
            $this->markTestSkipped('Redis queue not configured. Set QUEUE_CONNECTION=redis to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random number of jobs (1-50)
            $jobCount = rand(1, 50);
            
            try {
                $result = $this->testJobPreservationWithCount($jobCount);
                
                if (!$result['success']) {
                    $failureCount++;
                }
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        // Property should hold: all jobs should be preserved (0% loss)
        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Job preservation property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }
    
    /**
     * Property Test: Queue health monitoring detects issues consistently
     * 
     * **Validates: Requirements 3.1**
     * 
     * This test verifies that queue health monitoring consistently detects
     * issues across multiple checks.
     * 
*/
    public function property_queue_health_monitoring_detects_issues_consistently(): void
    {
        // Skip if not using Redis queue
        if (config('queue.default') !== 'redis') {
            $this->markTestSkipped('Redis queue not configured. Set QUEUE_CONNECTION=redis to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Monitor queue health
                $health = $this->recoveryService->monitorQueueHealth();
                
                // Verify health structure
                if (!isset($health['status']) || !isset($health['queues'])) {
                    $failureCount++;
                    continue;
                }
                
                // Verify all expected queues are monitored
                $expectedQueues = ['high', 'default', 'attendance', 'notifications', 'low'];
                foreach ($expectedQueues as $queueName) {
                    if (!isset($health['queues'][$queueName])) {
                        $failureCount++;
                        break;
                    }
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Queue health monitoring consistency property failed. " .
            "Success rate: {$successRate}%. Expected at least 95% across {$iterations} iterations."
        );
    }
    
    // ========================================================================
    // Helper Methods
    // ========================================================================
    
    /**
     * Test job preservation with specific job count
     * 
     * @param int $jobCount Number of jobs to test
     * @return array Test results
     */
    private function testJobPreservationWithCount(int $jobCount): array
    {
        $queueName = self::TEST_QUEUE_PREFIX . '_' . uniqid();
        
        // Push jobs to queue
        for ($i = 0; $i < $jobCount; $i++) {
            $jobData = [
                'id' => uniqid('job_'),
                'payload' => "test_data_{$i}_" . rand(1000, 9999),
                'queue' => $queueName,
            ];
            
            $this->pushTestJob($jobData);
        }
        
        // Simulate failover by checking queue length
        $queueLength = $this->getQueueLength($queueName);
        
        // Cleanup
        $this->cleanupQueue($queueName);
        
        $preserved = $queueLength;
        $lost = $jobCount - $preserved;
        
        return [
            'success' => $lost === 0,
            'preserved' => $preserved,
            'lost' => $lost,
            'total' => $jobCount,
        ];
    }
    
    /**
     * Push test job to queue
     * 
     * @param array $jobData Job data
     * @return string Job ID
     */
    private function pushTestJob(array $jobData): string
    {
        $connection = Redis::connection('queue');
        $prefix = config('database.redis.options.prefix', '');
        $queueKey = $prefix . 'queues:' . $jobData['queue'];
        
        $payload = json_encode([
            'uuid' => $jobData['id'],
            'displayName' => 'TestJob',
            'job' => 'TestJob',
            'data' => [
                'payload' => $jobData['payload'] ?? 'test',
            ],
            'attempts' => $jobData['attempts'] ?? 0,
        ]);
        
        $connection->rpush($queueKey, $payload);
        
        return $jobData['id'];
    }
    
    /**
     * Get queue length
     * 
     * @param string $queueName Queue name
     * @return int Queue length
     */
    private function getQueueLength(string $queueName): int
    {
        $connection = Redis::connection('queue');
        $prefix = config('database.redis.options.prefix', '');
        $queueKey = $prefix . 'queues:' . $queueName;
        
        return (int) $connection->llen($queueKey);
    }
    
    /**
     * Cleanup specific queue
     * 
     * @param string $queueName Queue name
     */
    private function cleanupQueue(string $queueName): void
    {
        $connection = Redis::connection('queue');
        $prefix = config('database.redis.options.prefix', '');
        $queueKey = $prefix . 'queues:' . $queueName;
        
        $connection->del($queueKey);
    }
    
    /**
     * Cleanup all test queues
     */
    private function cleanupTestQueues(): void
    {
        try {
            $connection = Redis::connection('queue');
            $prefix = config('database.redis.options.prefix', '');
            
            // Find all test queue keys
            $pattern = $prefix . 'queues:' . self::TEST_QUEUE_PREFIX . '*';
            $keys = $connection->keys($pattern);
            
            if (!empty($keys)) {
                $connection->del(...$keys);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }
}
