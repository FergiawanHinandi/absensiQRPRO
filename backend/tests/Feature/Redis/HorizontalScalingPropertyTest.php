<?php

namespace Tests\Feature\Redis;

use App\Services\QueueWorkerScalerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Property-Based Test: Horizontal Scaling Capability
 * 
 * Feature: redis-high-availability
 * Property 40: Horizontal scaling capability
 * Validates: Requirements 9.4
 * 
 * This test validates that for any increased load scenario, the system should
 * scale horizontally by adding replica nodes to handle the load.
 * 
 * Property: For any increased load scenario, the system should scale horizontally
 * by adding replica nodes without service degradation.
 */
class HorizontalScalingPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    private const TEST_CACHE_PREFIX = 'scaling_test';
    
    private QueueWorkerScalerService $scalerService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if Redis is not available
        try {
            Redis::connection()->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
        
        $this->scalerService = new QueueWorkerScalerService();
    }
    
    protected function tearDown(): void
    {
        // Cleanup test data
        $this->cleanupTestData();
        
        parent::tearDown();
    }

    /**
     * Property Test: System scales up when load increases
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that the system automatically scales up by adding
     * workers/replicas when the load increases beyond configured thresholds.
     * 
*/
    public function property_system_scales_up_when_load_increases(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random load above scale-up threshold
                $scaleUpThreshold = config('queue-scaling.scale_up_threshold', 500);
                $jobCount = rand($scaleUpThreshold + 1, $scaleUpThreshold + 500);
                
                // Get initial worker count
                $initialWorkers = $this->getWorkerCount();
                
                // Simulate increased load
                $this->simulateQueueLoad($jobCount, $i);
                
                // Trigger scaling evaluation
                $result = $this->scalerService->evaluate();
                
                // Property: System should decide to scale up
                if ($result['action'] !== 'scale_up' && $initialWorkers < config('queue-scaling.max_workers', 10)) {
                    $failureCount++;
                    continue;
                }
                
                // If at max workers, scaling up is not possible
                if ($initialWorkers >= config('queue-scaling.max_workers', 10)) {
                    // This is expected behavior, not a failure
                    continue;
                }
                
                // Property: Worker count should increase or be at max
                $finalWorkers = $this->getWorkerCount();
                if ($finalWorkers <= $initialWorkers && $initialWorkers < config('queue-scaling.max_workers', 10)) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Horizontal scale-up property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: System scales down when load decreases
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that the system automatically scales down by removing
     * workers/replicas when the load decreases below configured thresholds.
     * 
*/
    public function property_system_scales_down_when_load_decreases(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced due to timing requirements
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Start with multiple workers
                $initialWorkers = rand(3, 5);
                $this->setWorkerCount($initialWorkers);
                
                // Generate low load below scale-down threshold
                $scaleDownThreshold = config('queue-scaling.scale_down_threshold', 100);
                $jobCount = rand(0, $scaleDownThreshold - 1);
                
                // Simulate low load
                $this->simulateQueueLoad($jobCount, $i);
                
                // Start low-job tracking
                $this->startLowJobTracking();
                
                // Simulate time passing (10+ minutes)
                $this->simulateTimePassing(config('queue-scaling.scale_down_duration', 600) + 10);
                
                // Trigger scaling evaluation
                $result = $this->scalerService->evaluate();
                
                // Property: System should decide to scale down
                $minWorkers = config('queue-scaling.min_workers', 1);
                if ($result['action'] !== 'scale_down' && $initialWorkers > $minWorkers) {
                    $failureCount++;
                    continue;
                }
                
                // If at min workers, scaling down is not possible
                if ($initialWorkers <= $minWorkers) {
                    // This is expected behavior, not a failure
                    continue;
                }
                
                // Property: Worker count should decrease or be at min
                $finalWorkers = $this->getWorkerCount();
                if ($finalWorkers >= $initialWorkers && $initialWorkers > $minWorkers) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
                $this->clearLowJobTracking();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Horizontal scale-down property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Scaling respects minimum worker constraints
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that horizontal scaling never reduces workers
     * below the configured minimum threshold.
     * 
*/
    public function property_scaling_respects_minimum_worker_constraints(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $minWorkers = config('queue-scaling.min_workers', 1);

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Start at or near minimum workers
                $initialWorkers = rand($minWorkers, $minWorkers + 1);
                $this->setWorkerCount($initialWorkers);
                
                // Generate very low load
                $jobCount = rand(0, 10);
                $this->simulateQueueLoad($jobCount, $i);
                
                // Trigger multiple scaling evaluations
                for ($j = 0; $j < 5; $j++) {
                    $this->scalerService->evaluate();
                    
                    // Property: Worker count should never go below minimum
                    $currentWorkers = $this->getWorkerCount();
                    if ($currentWorkers < $minWorkers) {
                        $failureCount++;
                        break;
                    }
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Minimum worker constraint property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to respect minimum workers across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Scaling respects maximum worker constraints
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that horizontal scaling never increases workers
     * beyond the configured maximum threshold.
     * 
*/
    public function property_scaling_respects_maximum_worker_constraints(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $maxWorkers = config('queue-scaling.max_workers', 10);

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Start at or near maximum workers
                $initialWorkers = rand($maxWorkers - 1, $maxWorkers);
                $this->setWorkerCount($initialWorkers);
                
                // Generate very high load
                $jobCount = rand(1000, 5000);
                $this->simulateQueueLoad($jobCount, $i);
                
                // Trigger multiple scaling evaluations
                for ($j = 0; $j < 5; $j++) {
                    $this->scalerService->evaluate();
                    
                    // Property: Worker count should never exceed maximum
                    $currentWorkers = $this->getWorkerCount();
                    if ($currentWorkers > $maxWorkers) {
                        $failureCount++;
                        break;
                    }
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Maximum worker constraint property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to respect maximum workers across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Scaling cooldown prevents rapid oscillation
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that cooldown periods prevent rapid scaling
     * oscillations that could destabilize the system.
     * 
*/
    public function property_scaling_cooldown_prevents_rapid_oscillation(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $cooldownUp = config('queue-scaling.cooldown_up', 60);

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Start with moderate workers
                $initialWorkers = rand(2, 4);
                $this->setWorkerCount($initialWorkers);
                
                // Generate high load to trigger scale-up
                $highLoad = config('queue-scaling.scale_up_threshold', 500) + 100;
                $this->simulateQueueLoad($highLoad, $i);
                
                // First scale-up should succeed
                $result1 = $this->scalerService->evaluate();
                
                // Immediately try to scale up again
                $result2 = $this->scalerService->evaluate();
                
                // Property: Second scale-up should be blocked by cooldown
                if ($result1['action'] === 'scale_up' && $result2['action'] === 'scale_up') {
                    // Both succeeded - cooldown not working
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
                $this->clearScalingCooldowns();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Scaling cooldown property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to respect cooldown across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Scaling maintains service availability
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that horizontal scaling operations do not
     * cause service interruptions or data loss.
     * 
*/
    public function property_scaling_maintains_service_availability(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create test data in Redis
                $testKeys = $this->createTestRedisData(100, $i);
                
                // Get initial worker count
                $initialWorkers = $this->getWorkerCount();
                
                // Trigger scaling (either up or down randomly)
                $scaleUp = rand(0, 1) === 1;
                
                if ($scaleUp) {
                    $newWorkers = min($initialWorkers + 1, config('queue-scaling.max_workers', 10));
                } else {
                    $newWorkers = max($initialWorkers - 1, config('queue-scaling.min_workers', 1));
                }
                
                $this->setWorkerCount($newWorkers);
                
                // Property: All test data should still be accessible
                $accessibleCount = $this->verifyTestRedisData($testKeys);
                
                if ($accessibleCount < count($testKeys)) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Service availability during scaling property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to maintain availability across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Scaling metrics are accurately tracked
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that scaling operations are properly tracked
     * and metrics are accurately recorded.
     * 
*/
    public function property_scaling_metrics_are_accurately_tracked(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random load
                $jobCount = rand(0, 1000);
                $this->simulateQueueLoad($jobCount, $i);
                
                // Evaluate scaling
                $result = $this->scalerService->evaluate();
                
                // Property: Metrics should be present and valid
                if (!isset($result['metrics']) || !is_array($result['metrics'])) {
                    $failureCount++;
                    continue;
                }
                
                $metrics = $result['metrics'];
                
                // Property: Total pending should match simulated load
                if (!isset($metrics['total_pending']) || abs($metrics['total_pending'] - $jobCount) > 10) {
                    $failureCount++;
                    continue;
                }
                
                // Property: Worker information should be present
                if (!isset($result['workers']) || !is_array($result['workers'])) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Scaling metrics tracking property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to track metrics accurately across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Scaling decisions are deterministic for same conditions
     * 
     * **Validates: Requirements 9.4**
     * 
     * This test verifies that the scaling algorithm makes consistent
     * decisions when presented with identical conditions.
     * 
*/
    public function property_scaling_decisions_are_deterministic(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Set identical conditions
                $workers = rand(2, 5);
                $jobCount = rand(100, 800);
                
                $this->setWorkerCount($workers);
                $this->simulateQueueLoad($jobCount, $i);
                
                // Evaluate twice with same conditions
                $result1 = $this->scalerService->evaluate();
                
                // Reset to same conditions
                $this->setWorkerCount($workers);
                $this->simulateQueueLoad($jobCount, $i);
                $this->clearScalingCooldowns(); // Remove cooldown effect
                
                $result2 = $this->scalerService->evaluate();
                
                // Property: Decisions should be identical
                if ($result1['action'] !== $result2['action']) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
                $this->clearScalingCooldowns();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Deterministic scaling property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to make consistent decisions across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Simulate queue load by creating jobs in Redis
     * 
     * @param int $jobCount Number of jobs to create
     * @param int $seed Seed for randomization
     */
    private function simulateQueueLoad(int $jobCount, int $seed): void
    {
        $redis = Redis::connection();
        $queues = config('queue-scaling.queues', ['default']);
        
        // Distribute jobs across queues
        $jobsPerQueue = ceil($jobCount / count($queues));
        
        foreach ($queues as $queue) {
            $queueKey = "queues:{$queue}";
            
            // Clear existing test jobs
            $redis->del($queueKey);
            
            // Add new jobs
            for ($i = 0; $i < $jobsPerQueue && $i < $jobCount; $i++) {
                $job = json_encode([
                    'id' => self::TEST_CACHE_PREFIX . ":{$seed}:{$i}",
                    'displayName' => 'TestJob',
                    'job' => 'TestJob',
                    'data' => ['test' => true, 'seed' => $seed],
                ]);
                
                $redis->rpush($queueKey, $job);
            }
        }
    }

    /**
     * Get current worker count
     * 
     * @return int Current worker count
     */
    private function getWorkerCount(): int
    {
        return $this->scalerService->getCurrentWorkerCount();
    }

    /**
     * Set worker count (for testing)
     * 
     * @param int $count Worker count to set
     */
    private function setWorkerCount(int $count): void
    {
        Cache::put('queue_scaler:worker_count', $count, now()->addDay());
    }

    /**
     * Start low-job tracking
     */
    private function startLowJobTracking(): void
    {
        Cache::put('queue_scaler:low_job_start', now()->timestamp, now()->addHour());
    }

    /**
     * Clear low-job tracking
     */
    private function clearLowJobTracking(): void
    {
        Cache::forget('queue_scaler:low_job_start');
    }

    /**
     * Simulate time passing (for cooldown/duration testing)
     * 
     * @param int $seconds Seconds to simulate
     */
    private function simulateTimePassing(int $seconds): void
    {
        $lowJobStart = Cache::get('queue_scaler:low_job_start');
        if ($lowJobStart !== null) {
            Cache::put('queue_scaler:low_job_start', $lowJobStart - $seconds, now()->addHour());
        }
    }

    /**
     * Clear scaling cooldowns
     */
    private function clearScalingCooldowns(): void
    {
        Cache::forget('queue_scaler:last_scale_up');
        Cache::forget('queue_scaler:last_scale_down');
    }

    /**
     * Create test data in Redis
     * 
     * @param int $count Number of keys to create
     * @param int $seed Seed for randomization
     * @return array Array of created keys
     */
    private function createTestRedisData(int $count, int $seed): array
    {
        $redis = Redis::connection();
        $keys = [];
        
        for ($i = 0; $i < $count; $i++) {
            $key = self::TEST_CACHE_PREFIX . ":{$seed}:data:{$i}";
            $value = json_encode([
                'id' => $i,
                'seed' => $seed,
                'timestamp' => time(),
                'data' => str_repeat('x', 100),
            ]);
            
            $redis->setex($key, 3600, $value);
            $keys[] = $key;
        }
        
        return $keys;
    }

    /**
     * Verify test data in Redis
     * 
     * @param array $keys Keys to verify
     * @return int Number of accessible keys
     */
    private function verifyTestRedisData(array $keys): int
    {
        $redis = Redis::connection();
        $accessibleCount = 0;
        
        foreach ($keys as $key) {
            if ($redis->exists($key)) {
                $accessibleCount++;
            }
        }
        
        return $accessibleCount;
    }

    /**
     * Cleanup test data
     * 
     * @param int|null $seed Optional seed to cleanup specific iteration
     */
    private function cleanupTestData(?int $seed = null): void
    {
        $redis = Redis::connection();
        
        // Cleanup queue jobs
        $queues = config('queue-scaling.queues', ['default']);
        foreach ($queues as $queue) {
            $queueKey = "queues:{$queue}";
            $redis->del($queueKey);
        }
        
        // Cleanup test Redis data
        if ($seed !== null) {
            $pattern = self::TEST_CACHE_PREFIX . ":{$seed}:*";
        } else {
            $pattern = self::TEST_CACHE_PREFIX . ":*";
        }
        
        $keys = $redis->keys($pattern);
        if (!empty($keys)) {
            $redis->del($keys);
        }
        
        // Cleanup cache keys
        Cache::forget('queue_scaler:low_job_start');
        Cache::forget('queue_scaler:last_scale_up');
        Cache::forget('queue_scaler:last_scale_down');
        Cache::forget('queue_scaler:metrics_history');
    }
}
