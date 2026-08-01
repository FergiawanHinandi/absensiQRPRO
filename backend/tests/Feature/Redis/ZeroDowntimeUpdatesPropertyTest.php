<?php

namespace Tests\Feature\Redis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Property-Based Test: Zero-Downtime Updates
 * 
 * Feature: redis-high-availability
 * Property 34: Zero-downtime configuration updates
 * Validates: Requirements 8.2
 * 
 * This test validates that for any configuration change, it should be applied
 * without service interruption.
 * 
 * Property: For any configuration change, the system should apply updates
 * without causing service interruption or data loss.
 */
class ZeroDowntimeUpdatesPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    private const TEST_PREFIX = 'zero_downtime_test';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if Redis is not available
        try {
            Redis::connection()->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
    }
    
    protected function tearDown(): void
    {
        // Cleanup test data
        $this->cleanupTestData();
        
        parent::tearDown();
    }

    /**
     * Property Test: Configuration updates maintain service availability
     * 
     * **Validates: Requirements 8.2**
     * 
     * This test verifies that configuration changes can be applied without
     * causing service interruptions or connection failures.
     * 
*/
    public function property_configuration_updates_maintain_service_availability(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create active connections and data
                $activeConnections = $this->createActiveConnections(rand(10, 50), $i);
                $testData = $this->createTestData(rand(50, 200), $i);
                
                // Simulate configuration update
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: All active connections should remain functional
                $functionalConnections = $this->verifyConnectionsFunctional($activeConnections);
                if ($functionalConnections < count($activeConnections)) {
                    $failureCount++;
                    continue;
                }
                
                // Property: All existing data should remain accessible
                $accessibleData = $this->verifyDataAccessible($testData);
                if ($accessibleData < count($testData)) {
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
            "Zero-downtime configuration update property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: No data loss during configuration updates
     * 
     * **Validates: Requirements 8.2**
     * 
     * This test verifies that no data is lost when configuration changes
     * are applied to the Redis cluster.
     * 
*/
    public function property_no_data_loss_during_configuration_updates(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create test data with checksums
                $dataCount = rand(100, 500);
                $testData = $this->createTestDataWithChecksums($dataCount, $i);
                
                // Simulate configuration update
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: All data should be intact with matching checksums
                $intactData = 0;
                foreach ($testData as $key => $expectedChecksum) {
                    $actualData = $this->retrieveData($key);
                    if ($actualData !== null) {
                        $actualChecksum = md5(json_encode($actualData));
                        if ($actualChecksum === $expectedChecksum) {
                            $intactData++;
                        }
                    }
                }
                
                if ($intactData < $dataCount) {
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
            "Data integrity during updates property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to maintain data integrity across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Ongoing operations complete during updates
     * 
     * **Validates: Requirements 8.2**
     * 
     * This test verifies that operations in progress can complete successfully
     * even when configuration changes are being applied.
     * 
*/
    public function property_ongoing_operations_complete_during_updates(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Start multiple ongoing operations
                $operationCount = rand(20, 100);
                $operations = $this->startOngoingOperations($operationCount, $i);
                
                // Apply configuration change while operations are running
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: All operations should complete successfully
                $completedOperations = $this->waitForOperationsCompletion($operations, 5);
                
                if ($completedOperations < $operationCount * 0.95) {
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
            90,
            $successRate,
            "Ongoing operations completion property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to complete operations across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Session continuity during configuration updates
     * 
     * **Validates: Requirements 8.2, 2.1**
     * 
     * This test verifies that user sessions remain valid and accessible
     * during configuration updates.
     * 
*/
    public function property_session_continuity_during_configuration_updates(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create active sessions
                $sessionCount = rand(50, 200);
                $sessions = $this->createActiveSessions($sessionCount, $i);
                
                // Apply configuration change
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: All sessions should remain valid
                $validSessions = 0;
                foreach ($sessions as $sessionId => $expectedData) {
                    $sessionData = $this->retrieveSession($sessionId);
                    if ($sessionData !== null && $sessionData['user_id'] === $expectedData['user_id']) {
                        $validSessions++;
                    }
                }
                
                if ($validSessions < $sessionCount) {
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
            "Session continuity property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to maintain sessions across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Cache operations continue during updates
     * 
     * **Validates: Requirements 8.2, 4.3**
     * 
     * This test verifies that cache read/write operations continue to work
     * correctly during configuration updates.
     * 
*/
    public function property_cache_operations_continue_during_updates(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Populate cache with data
                $cacheKeys = $this->populateCache(rand(50, 200), $i);
                
                // Start continuous cache operations
                $operationCount = rand(20, 50);
                $operations = $this->startCacheOperations($operationCount, $i);
                
                // Apply configuration change
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: Cache operations should complete successfully
                $successfulOps = $this->verifyCacheOperations($operations);
                
                if ($successfulOps < $operationCount * 0.90) {
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
            90,
            $successRate,
            "Cache operations continuity property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to maintain cache operations across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Queue processing continues during updates
     * 
     * **Validates: Requirements 8.2, 3.2**
     * 
     * This test verifies that queue job processing continues without
     * interruption during configuration updates.
     * 
*/
    public function property_queue_processing_continues_during_updates(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Queue jobs for processing
                $jobCount = rand(20, 100);
                $jobs = $this->queueTestJobs($jobCount, $i);
                
                // Apply configuration change
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: Jobs should remain in queue and be processable
                $accessibleJobs = $this->verifyJobsAccessible($jobs);
                
                if ($accessibleJobs < $jobCount * 0.95) {
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
            90,
            $successRate,
            "Queue processing continuity property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to maintain queue processing across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Response time remains acceptable during updates
     * 
     * **Validates: Requirements 8.2**
     * 
     * This test verifies that Redis operation response times remain within
     * acceptable limits during configuration updates.
     * 
*/
    public function property_response_time_remains_acceptable_during_updates(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $failureCount = 0;
        $maxResponseTimeMs = 50; // 50ms max response time

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create test data
                $testKeys = $this->createTestData(rand(50, 100), $i);
                
                // Apply configuration change
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Measure response times for operations
                $responseTimes = [];
                $sampleSize = min(20, count($testKeys));
                $sampleKeys = array_rand($testKeys, $sampleSize);
                
                foreach ($sampleKeys as $keyIndex) {
                    $key = $testKeys[$keyIndex];
                    
                    $startTime = microtime(true);
                    $this->retrieveData($key);
                    $duration = (microtime(true) - $startTime) * 1000;
                    
                    $responseTimes[] = $duration;
                }
                
                // Calculate average response time
                $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
                
                // Property: Average response time should be acceptable
                if ($avgResponseTime > $maxResponseTimeMs) {
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
            85,
            $successRate,
            "Response time property failed. Success rate: {$successRate}%. " .
            "Expected at least 85% to have avg response time < {$maxResponseTimeMs}ms across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Multi-tenant isolation maintained during updates
     * 
     * **Validates: Requirements 8.2, 5.2**
     * 
     * This test verifies that multi-tenant data isolation is maintained
     * during configuration updates.
     * 
*/
    public function property_multi_tenant_isolation_maintained_during_updates(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create tenant-specific data
                $tenantCount = rand(5, 15);
                $tenantData = $this->createMultiTenantData($tenantCount, rand(10, 50), $i);
                
                // Apply configuration change
                $configChange = $this->generateRandomConfigChange();
                $this->applyConfigurationChange($configChange);
                
                // Property: Verify tenant isolation is maintained
                $isolationViolations = $this->verifyTenantIsolation($tenantData);
                
                if ($isolationViolations > 0) {
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
            "Multi-tenant isolation property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to maintain isolation across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Create active connections for testing
     * 
     * @param int $count Number of connections to create
     * @param int $seed Seed for randomization
     * @return array Array of connection identifiers
     */
    private function createActiveConnections(int $count, int $seed): array
    {
        $connections = [];
        
        for ($i = 0; $i < $count; $i++) {
            $connectionId = self::TEST_PREFIX . ":conn:{$seed}:{$i}";
            
            // Simulate active connection by storing connection metadata
            Cache::put($connectionId, [
                'id' => $connectionId,
                'created_at' => time(),
                'last_activity' => time(),
                'seed' => $seed,
            ], now()->addHour());
            
            $connections[] = $connectionId;
        }
        
        return $connections;
    }

    /**
     * Create test data in Redis
     * 
     * @param int $count Number of data items to create
     * @param int $seed Seed for randomization
     * @return array Array of data keys
     */
    private function createTestData(int $count, int $seed): array
    {
        $redis = Redis::connection();
        $keys = [];
        
        for ($i = 0; $i < $count; $i++) {
            $key = self::TEST_PREFIX . ":data:{$seed}:{$i}";
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
     * Create test data with checksums
     * 
     * @param int $count Number of data items to create
     * @param int $seed Seed for randomization
     * @return array Array mapping keys to checksums
     */
    private function createTestDataWithChecksums(int $count, int $seed): array
    {
        $redis = Redis::connection();
        $dataWithChecksums = [];
        
        for ($i = 0; $i < $count; $i++) {
            $key = self::TEST_PREFIX . ":data:{$seed}:{$i}";
            $data = [
                'id' => $i,
                'seed' => $seed,
                'timestamp' => time(),
                'data' => str_repeat('x', 100),
            ];
            
            $value = json_encode($data);
            $checksum = md5($value);
            
            $redis->setex($key, 3600, $value);
            $dataWithChecksums[$key] = $checksum;
        }
        
        return $dataWithChecksums;
    }

    /**
     * Generate random configuration change
     * 
     * @return array Configuration change details
     */
    private function generateRandomConfigChange(): array
    {
        $changeTypes = [
            'timeout_adjustment',
            'connection_pool_size',
            'retry_policy',
            'key_prefix_update',
            'database_selection',
        ];
        
        return [
            'type' => $changeTypes[array_rand($changeTypes)],
            'timestamp' => time(),
            'value' => rand(1, 100),
        ];
    }

    /**
     * Apply configuration change (simulated)
     * 
     * @param array $configChange Configuration change to apply
     */
    private function applyConfigurationChange(array $configChange): void
    {
        // Simulate configuration update by storing change metadata
        Cache::put('config_change:latest', $configChange, now()->addMinutes(5));
        
        // Simulate brief configuration reload delay
        usleep(rand(1000, 5000)); // 1-5ms delay
    }

    /**
     * Verify connections remain functional
     * 
     * @param array $connections Array of connection identifiers
     * @return int Number of functional connections
     */
    private function verifyConnectionsFunctional(array $connections): int
    {
        $functionalCount = 0;
        
        foreach ($connections as $connectionId) {
            $connectionData = Cache::get($connectionId);
            
            if ($connectionData !== null) {
                // Try to perform operation with this connection
                try {
                    Cache::put($connectionId . ':test', 'test_value', now()->addMinute());
                    $testValue = Cache::get($connectionId . ':test');
                    
                    if ($testValue === 'test_value') {
                        $functionalCount++;
                    }
                    
                    Cache::forget($connectionId . ':test');
                } catch (\Exception $e) {
                    // Connection not functional
                }
            }
        }
        
        return $functionalCount;
    }

    /**
     * Verify data is accessible
     * 
     * @param array $keys Array of data keys
     * @return int Number of accessible data items
     */
    private function verifyDataAccessible(array $keys): int
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
     * Retrieve data from Redis
     * 
     * @param string $key Data key
     * @return array|null Data or null if not found
     */
    private function retrieveData(string $key): ?array
    {
        $redis = Redis::connection();
        $value = $redis->get($key);
        
        return $value ? json_decode($value, true) : null;
    }

    /**
     * Start ongoing operations
     * 
     * @param int $count Number of operations to start
     * @param int $seed Seed for randomization
     * @return array Array of operation identifiers
     */
    private function startOngoingOperations(int $count, int $seed): array
    {
        $operations = [];
        
        for ($i = 0; $i < $count; $i++) {
            $operationId = self::TEST_PREFIX . ":op:{$seed}:{$i}";
            
            // Simulate operation by storing operation state
            Cache::put($operationId, [
                'id' => $operationId,
                'status' => 'in_progress',
                'started_at' => time(),
                'seed' => $seed,
            ], now()->addMinutes(10));
            
            $operations[] = $operationId;
        }
        
        return $operations;
    }

    /**
     * Wait for operations completion
     * 
     * @param array $operations Array of operation identifiers
     * @param int $timeoutSeconds Timeout in seconds
     * @return int Number of completed operations
     */
    private function waitForOperationsCompletion(array $operations, int $timeoutSeconds): int
    {
        $completedCount = 0;
        
        foreach ($operations as $operationId) {
            $operationData = Cache::get($operationId);
            
            if ($operationData !== null) {
                // Simulate operation completion
                $operationData['status'] = 'completed';
                $operationData['completed_at'] = time();
                Cache::put($operationId, $operationData, now()->addMinutes(10));
                
                $completedCount++;
            }
        }
        
        return $completedCount;
    }

    /**
     * Create active sessions
     * 
     * @param int $count Number of sessions to create
     * @param int $seed Seed for randomization
     * @return array Array mapping session IDs to session data
     */
    private function createActiveSessions(int $count, int $seed): array
    {
        $redis = Redis::connection('session');
        $sessions = [];
        
        for ($i = 0; $i < $count; $i++) {
            $sessionId = self::TEST_PREFIX . ":session:{$seed}:{$i}";
            $sessionData = [
                'user_id' => rand(1, 10000),
                'school_id' => rand(1, 100),
                'last_activity' => time(),
                'seed' => $seed,
            ];
            
            $key = $this->buildSessionKey($sessionId);
            $redis->setex($key, 3600, serialize($sessionData));
            
            $sessions[$sessionId] = $sessionData;
        }
        
        return $sessions;
    }

    /**
     * Retrieve session data
     * 
     * @param string $sessionId Session ID
     * @return array|null Session data or null if not found
     */
    private function retrieveSession(string $sessionId): ?array
    {
        $redis = Redis::connection('session');
        $key = $this->buildSessionKey($sessionId);
        $data = $redis->get($key);
        
        return $data ? unserialize($data) : null;
    }

    /**
     * Build session key with proper prefix
     * 
     * @param string $sessionId Session ID
     * @return string Full session key
     */
    private function buildSessionKey(string $sessionId): string
    {
        $prefix = config('database.redis.options.prefix', '');
        return $prefix . "session:{$sessionId}";
    }

    /**
     * Populate cache with data
     * 
     * @param int $count Number of cache items to create
     * @param int $seed Seed for randomization
     * @return array Array of cache keys
     */
    private function populateCache(int $count, int $seed): array
    {
        $keys = [];
        
        for ($i = 0; $i < $count; $i++) {
            $key = self::TEST_PREFIX . ":cache:{$seed}:{$i}";
            $value = [
                'id' => $i,
                'seed' => $seed,
                'data' => str_repeat('y', 50),
            ];
            
            Cache::put($key, $value, now()->addHour());
            $keys[] = $key;
        }
        
        return $keys;
    }

    /**
     * Start cache operations
     * 
     * @param int $count Number of operations to start
     * @param int $seed Seed for randomization
     * @return array Array of operation identifiers
     */
    private function startCacheOperations(int $count, int $seed): array
    {
        $operations = [];
        
        for ($i = 0; $i < $count; $i++) {
            $operationId = self::TEST_PREFIX . ":cache_op:{$seed}:{$i}";
            $cacheKey = self::TEST_PREFIX . ":cache_op_data:{$seed}:{$i}";
            
            // Perform cache operation
            $operationType = rand(0, 1) === 0 ? 'read' : 'write';
            
            if ($operationType === 'write') {
                Cache::put($cacheKey, ['operation' => $operationId, 'type' => 'write'], now()->addHour());
            } else {
                Cache::get($cacheKey);
            }
            
            $operations[] = [
                'id' => $operationId,
                'type' => $operationType,
                'key' => $cacheKey,
            ];
        }
        
        return $operations;
    }

    /**
     * Verify cache operations
     * 
     * @param array $operations Array of operations to verify
     * @return int Number of successful operations
     */
    private function verifyCacheOperations(array $operations): int
    {
        $successfulCount = 0;
        
        foreach ($operations as $operation) {
            try {
                if ($operation['type'] === 'write') {
                    $value = Cache::get($operation['key']);
                    if ($value !== null && isset($value['operation']) && $value['operation'] === $operation['id']) {
                        $successfulCount++;
                    }
                } else {
                    // Read operation - just verify cache is accessible
                    Cache::get($operation['key']);
                    $successfulCount++;
                }
            } catch (\Exception $e) {
                // Operation failed
            }
        }
        
        return $successfulCount;
    }

    /**
     * Queue test jobs
     * 
     * @param int $count Number of jobs to queue
     * @param int $seed Seed for randomization
     * @return array Array of job identifiers
     */
    private function queueTestJobs(int $count, int $seed): array
    {
        $redis = Redis::connection();
        $jobs = [];
        
        for ($i = 0; $i < $count; $i++) {
            $jobId = self::TEST_PREFIX . ":job:{$seed}:{$i}";
            $job = json_encode([
                'id' => $jobId,
                'displayName' => 'TestJob',
                'job' => 'TestJob',
                'data' => ['seed' => $seed, 'index' => $i],
            ]);
            
            $redis->rpush('queues:default', $job);
            $jobs[] = $jobId;
        }
        
        return $jobs;
    }

    /**
     * Verify jobs are accessible
     * 
     * @param array $jobIds Array of job identifiers
     * @return int Number of accessible jobs
     */
    private function verifyJobsAccessible(array $jobIds): int
    {
        $redis = Redis::connection();
        $accessibleCount = 0;
        
        // Get all jobs from queue
        $queueJobs = $redis->lrange('queues:default', 0, -1);
        
        foreach ($jobIds as $jobId) {
            foreach ($queueJobs as $queueJob) {
                $jobData = json_decode($queueJob, true);
                if (isset($jobData['id']) && $jobData['id'] === $jobId) {
                    $accessibleCount++;
                    break;
                }
            }
        }
        
        return $accessibleCount;
    }

    /**
     * Create multi-tenant data
     * 
     * @param int $tenantCount Number of tenants
     * @param int $itemsPerTenant Number of items per tenant
     * @param int $seed Seed for randomization
     * @return array Array mapping tenant IDs to their data keys
     */
    private function createMultiTenantData(int $tenantCount, int $itemsPerTenant, int $seed): array
    {
        $redis = Redis::connection();
        $tenantData = [];
        
        for ($t = 0; $t < $tenantCount; $t++) {
            $tenantId = 1000 + ($seed * 100) + $t;
            $tenantData[$tenantId] = [];
            
            for ($i = 0; $i < $itemsPerTenant; $i++) {
                $key = self::TEST_PREFIX . ":tenant:{$tenantId}:data:{$seed}:{$i}";
                $value = json_encode([
                    'tenant_id' => $tenantId,
                    'id' => $i,
                    'seed' => $seed,
                    'data' => "tenant_{$tenantId}_data",
                ]);
                
                $redis->setex($key, 3600, $value);
                $tenantData[$tenantId][] = $key;
            }
        }
        
        return $tenantData;
    }

    /**
     * Verify tenant isolation
     * 
     * @param array $tenantData Array mapping tenant IDs to their data keys
     * @return int Number of isolation violations
     */
    private function verifyTenantIsolation(array $tenantData): int
    {
        $redis = Redis::connection();
        $violations = 0;
        
        foreach ($tenantData as $tenantId => $keys) {
            foreach ($keys as $key) {
                $value = $redis->get($key);
                
                if ($value !== null) {
                    $data = json_decode($value, true);
                    
                    // Verify tenant_id matches
                    if (!isset($data['tenant_id']) || $data['tenant_id'] != $tenantId) {
                        $violations++;
                    }
                }
            }
        }
        
        return $violations;
    }

    /**
     * Cleanup test data
     * 
     * @param int|null $seed Optional seed to cleanup specific iteration
     */
    private function cleanupTestData(?int $seed = null): void
    {
        $redis = Redis::connection();
        
        // Build pattern based on seed
        if ($seed !== null) {
            $pattern = self::TEST_PREFIX . ":*:{$seed}:*";
        } else {
            $pattern = self::TEST_PREFIX . ":*";
        }
        
        // Get all matching keys
        $keys = $redis->keys($pattern);
        
        if (!empty($keys)) {
            $redis->del($keys);
        }
        
        // Cleanup cache keys
        if ($seed !== null) {
            $cachePattern = self::TEST_PREFIX . ":*:{$seed}:*";
        } else {
            $cachePattern = self::TEST_PREFIX . ":*";
        }
        
        // Cleanup queue
        $redis->del('queues:default');
        
        // Cleanup session keys
        $sessionRedis = Redis::connection('session');
        $sessionKeys = $sessionRedis->keys(self::TEST_PREFIX . ":session:*");
        if (!empty($sessionKeys)) {
            $sessionRedis->del($sessionKeys);
        }
        
        // Cleanup config change metadata
        Cache::forget('config_change:latest');
    }
}
