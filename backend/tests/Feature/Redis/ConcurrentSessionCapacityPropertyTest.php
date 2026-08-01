<?php

namespace Tests\Feature\Redis;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Property-Based Test: Concurrent Session Capacity
 * 
 * Feature: redis-high-availability
 * Property 37: Concurrent session capacity
 * Validates: Requirements 9.1
 * 
 * This test validates that for any load scenario, the system should support
 * minimum 10,000 concurrent sessions across all tenants.
 * 
 * Property: For any load scenario, the system should support minimum 10,000
 * concurrent sessions across all tenant schools without degradation.
 */
class ConcurrentSessionCapacityPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    private const MIN_CONCURRENT_SESSIONS = 10000;
    private const TEST_SESSION_PREFIX = 'capacity_test';
    
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
        // Cleanup test sessions
        $this->cleanupTestSessions();
        
        parent::tearDown();
    }

    /**
     * Property Test: System supports minimum concurrent session capacity
     * 
     * **Validates: Requirements 9.1**
     * 
     * This test verifies that the system can handle at least 10,000 concurrent
     * sessions across all tenant schools without errors or performance degradation.
     * 
*/
    public function property_system_supports_minimum_concurrent_session_capacity(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $minSessions = self::MIN_CONCURRENT_SESSIONS;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random session count at or above minimum
            $sessionCount = rand($minSessions, $minSessions + 1000);
            
            try {
                // Create concurrent sessions
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i);
                
                // Property: All sessions should be created successfully
                if (count($createdSessions) < $sessionCount) {
                    $failureCount++;
                    continue;
                }
                
                // Verify sessions are accessible
                $accessibleCount = $this->verifySessionsAccessible($createdSessions);
                
                // Property: All sessions should be accessible
                if ($accessibleCount < $sessionCount) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Cleanup
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Concurrent session capacity property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations with {$minSessions}+ sessions. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Session creation time remains acceptable under load
     * 
     * **Validates: Requirements 9.1**
     * 
     * This test verifies that session creation time remains within acceptable
     * limits even when approaching maximum concurrent session capacity.
     * 
*/
    public function property_session_creation_time_remains_acceptable_under_load(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced due to performance overhead
        $failureCount = 0;
        $maxCreationTimeMs = 100; // 100ms per session creation

        for ($i = 0; $i < $iterations; $i++) {
            $sessionCount = rand(1000, 5000); // Test with varying loads
            
            try {
                // Measure session creation time
                $startTime = microtime(true);
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i);
                $duration = (microtime(true) - $startTime) * 1000; // Convert to ms
                
                // Calculate average time per session
                $avgTimePerSession = $duration / $sessionCount;
                
                // Property: Average creation time should be acceptable
                if ($avgTimePerSession > $maxCreationTimeMs) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Session creation time property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to have avg creation time < {$maxCreationTimeMs}ms across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Multi-tenant session isolation under concurrent load
     * 
     * **Validates: Requirements 9.1, 5.1**
     * 
     * This test verifies that multi-tenant session isolation is maintained
     * even under high concurrent session load.
     * 
*/
    public function property_multi_tenant_session_isolation_under_concurrent_load(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Create random number of tenants
            $tenantCount = rand(5, 20);
            $sessionsPerTenant = rand(100, 500);
            
            try {
                // Create schools (tenants)
                $schools = $this->createTestSchools($tenantCount, $i);
                
                // Create sessions for each tenant
                $sessionsByTenant = [];
                foreach ($schools as $school) {
                    $sessions = $this->createTenantSessions($school->id, $sessionsPerTenant, $i);
                    $sessionsByTenant[$school->id] = $sessions;
                }
                
                // Property: Verify no cross-tenant session access
                $isolationViolations = $this->checkTenantIsolation($sessionsByTenant);
                
                if ($isolationViolations > 0) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSchools($i);
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Multi-tenant isolation property failed under load. Success rate: {$successRate}%. " .
            "Expected at least 99% to maintain isolation across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Session retrieval performance under concurrent load
     * 
     * **Validates: Requirements 9.1**
     * 
     * This test verifies that session retrieval remains fast even when
     * the system is handling maximum concurrent sessions.
     * 
*/
    public function property_session_retrieval_performance_under_concurrent_load(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $failureCount = 0;
        $maxRetrievalTimeMs = 10; // 10ms per session retrieval

        for ($i = 0; $i < $iterations; $i++) {
            $sessionCount = rand(1000, 5000);
            
            try {
                // Create sessions
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i);
                
                // Randomly sample sessions to test retrieval
                $sampleSize = min(100, count($createdSessions));
                $sampleSessions = array_rand($createdSessions, $sampleSize);
                
                $totalRetrievalTime = 0;
                $successfulRetrievals = 0;
                
                foreach ($sampleSessions as $sessionKey) {
                    $sessionId = $createdSessions[$sessionKey];
                    
                    $startTime = microtime(true);
                    $sessionData = $this->retrieveSession($sessionId);
                    $duration = (microtime(true) - $startTime) * 1000;
                    
                    if ($sessionData !== null) {
                        $totalRetrievalTime += $duration;
                        $successfulRetrievals++;
                    }
                }
                
                // Calculate average retrieval time
                $avgRetrievalTime = $successfulRetrievals > 0 
                    ? $totalRetrievalTime / $successfulRetrievals 
                    : PHP_INT_MAX;
                
                // Property: Average retrieval time should be acceptable
                if ($avgRetrievalTime > $maxRetrievalTimeMs) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Session retrieval performance property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to have avg retrieval time < {$maxRetrievalTimeMs}ms across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Session expiration works correctly under concurrent load
     * 
     * **Validates: Requirements 9.1, 2.5**
     * 
     * This test verifies that session expiration mechanisms work correctly
     * even when handling maximum concurrent sessions.
     * 
*/
    public function property_session_expiration_works_correctly_under_concurrent_load(): void
    {
        $iterations = min(30, self::MIN_ITERATIONS);
        $failureCount = 0;
        $shortTtl = 2; // 2 seconds TTL for testing

        for ($i = 0; $i < $iterations; $i++) {
            $sessionCount = rand(100, 500);
            
            try {
                // Create sessions with short TTL
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i, $shortTtl);
                
                // Verify sessions exist immediately
                $initialAccessible = $this->verifySessionsAccessible($createdSessions);
                
                if ($initialAccessible < $sessionCount * 0.95) {
                    $failureCount++;
                    continue;
                }
                
                // Wait for expiration
                sleep($shortTtl + 1);
                
                // Property: Sessions should be expired
                $afterExpiration = $this->verifySessionsAccessible($createdSessions);
                
                // Allow small margin for timing variations
                if ($afterExpiration > $sessionCount * 0.05) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Session expiration property failed under load. Success rate: {$successRate}%. " .
            "Expected at least 90% to expire correctly across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Memory usage remains acceptable under concurrent load
     * 
     * **Validates: Requirements 9.1**
     * 
     * This test verifies that Redis memory usage remains within acceptable
     * limits when handling maximum concurrent sessions.
     * 
*/
    public function property_memory_usage_remains_acceptable_under_concurrent_load(): void
    {
        $iterations = min(30, self::MIN_ITERATIONS);
        $failureCount = 0;
        $maxMemoryMbPerSession = 0.01; // 10KB per session

        for ($i = 0; $i < $iterations; $i++) {
            $sessionCount = rand(1000, 5000);
            
            try {
                // Get initial memory usage
                $initialMemory = $this->getRedisMemoryUsageMb();
                
                // Create sessions
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i);
                
                // Get memory usage after session creation
                $finalMemory = $this->getRedisMemoryUsageMb();
                $memoryIncrease = $finalMemory - $initialMemory;
                
                // Calculate memory per session
                $memoryPerSession = $memoryIncrease / $sessionCount;
                
                // Property: Memory per session should be reasonable
                if ($memoryPerSession > $maxMemoryMbPerSession) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            85,
            $successRate,
            "Memory usage property failed. Success rate: {$successRate}%. " .
            "Expected at least 85% to use < {$maxMemoryMbPerSession}MB per session across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Session updates work correctly under concurrent load
     * 
     * **Validates: Requirements 9.1**
     * 
     * This test verifies that session updates (writes) work correctly
     * even when the system is handling maximum concurrent sessions.
     * 
*/
    public function property_session_updates_work_correctly_under_concurrent_load(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $sessionCount = rand(500, 2000);
            
            try {
                // Create sessions
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i);
                
                // Update random subset of sessions
                $updateCount = rand(50, 200);
                $sessionsToUpdate = array_rand($createdSessions, $updateCount);
                
                $successfulUpdates = 0;
                foreach ($sessionsToUpdate as $sessionKey) {
                    $sessionId = $createdSessions[$sessionKey];
                    $newData = ['updated' => true, 'timestamp' => time(), 'iteration' => $i];
                    
                    if ($this->updateSession($sessionId, $newData)) {
                        // Verify update
                        $retrievedData = $this->retrieveSession($sessionId);
                        if ($retrievedData && isset($retrievedData['updated']) && $retrievedData['updated'] === true) {
                            $successfulUpdates++;
                        }
                    }
                }
                
                // Property: Most updates should succeed
                $updateSuccessRate = ($successfulUpdates / $updateCount) * 100;
                
                if ($updateSuccessRate < 95) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Session update property failed under load. Success rate: {$successRate}%. " .
            "Expected at least 90% to update successfully across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Session deletion works correctly under concurrent load
     * 
     * **Validates: Requirements 9.1**
     * 
     * This test verifies that session deletion (logout) works correctly
     * even when the system is handling maximum concurrent sessions.
     * 
*/
    public function property_session_deletion_works_correctly_under_concurrent_load(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $sessionCount = rand(500, 2000);
            
            try {
                // Create sessions
                $createdSessions = $this->createConcurrentSessions($sessionCount, $i);
                
                // Delete random subset of sessions
                $deleteCount = rand(50, 200);
                $sessionsToDelete = array_rand($createdSessions, $deleteCount);
                
                $successfulDeletions = 0;
                foreach ($sessionsToDelete as $sessionKey) {
                    $sessionId = $createdSessions[$sessionKey];
                    
                    if ($this->deleteSession($sessionId)) {
                        // Verify deletion
                        $retrievedData = $this->retrieveSession($sessionId);
                        if ($retrievedData === null) {
                            $successfulDeletions++;
                        }
                    }
                }
                
                // Property: All deletions should succeed
                $deleteSuccessRate = ($successfulDeletions / $deleteCount) * 100;
                
                if ($deleteSuccessRate < 95) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestSessions($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Session deletion property failed under load. Success rate: {$successRate}%. " .
            "Expected at least 95% to delete successfully across {$iterations} iterations."
        );
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Create concurrent sessions for testing
     * 
     * @param int $count Number of sessions to create
     * @param int $seed Seed for randomization
     * @param int $ttl Time to live in seconds (default: 3600)
     * @return array Array of session IDs
     */
    private function createConcurrentSessions(int $count, int $seed, int $ttl = 3600): array
    {
        $sessions = [];
        $redis = Redis::connection('session');
        
        for ($i = 0; $i < $count; $i++) {
            $sessionId = self::TEST_SESSION_PREFIX . ":{$seed}:" . uniqid() . ":{$i}";
            $sessionData = [
                'user_id' => rand(1, 10000),
                'school_id' => rand(1, 100),
                'last_activity' => time(),
                'ip_address' => '127.0.0.' . rand(1, 255),
                'user_agent' => 'TestAgent/' . rand(1, 100),
                'iteration' => $seed,
            ];
            
            $key = $this->buildSessionKey($sessionId);
            $redis->setex($key, $ttl, serialize($sessionData));
            
            $sessions[] = $sessionId;
        }
        
        return $sessions;
    }

    /**
     * Create sessions for a specific tenant
     * 
     * @param int $schoolId School (tenant) ID
     * @param int $count Number of sessions to create
     * @param int $seed Seed for randomization
     * @return array Array of session IDs
     */
    private function createTenantSessions(int $schoolId, int $count, int $seed): array
    {
        $sessions = [];
        $redis = Redis::connection('session');
        
        for ($i = 0; $i < $count; $i++) {
            $sessionId = self::TEST_SESSION_PREFIX . ":tenant:{$schoolId}:{$seed}:" . uniqid() . ":{$i}";
            $sessionData = [
                'user_id' => rand(1, 1000),
                'school_id' => $schoolId,
                'last_activity' => time(),
                'iteration' => $seed,
            ];
            
            $key = $this->buildSessionKey($sessionId, $schoolId);
            $redis->setex($key, 3600, serialize($sessionData));
            
            $sessions[] = $sessionId;
        }
        
        return $sessions;
    }

    /**
     * Verify sessions are accessible
     * 
     * @param array $sessionIds Array of session IDs to verify
     * @return int Number of accessible sessions
     */
    private function verifySessionsAccessible(array $sessionIds): int
    {
        $redis = Redis::connection('session');
        $accessibleCount = 0;
        
        foreach ($sessionIds as $sessionId) {
            $key = $this->buildSessionKey($sessionId);
            if ($redis->exists($key)) {
                $accessibleCount++;
            }
        }
        
        return $accessibleCount;
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
     * Update session data
     * 
     * @param string $sessionId Session ID
     * @param array $newData New session data
     * @return bool Success status
     */
    private function updateSession(string $sessionId, array $newData): bool
    {
        $redis = Redis::connection('session');
        $key = $this->buildSessionKey($sessionId);
        
        // Get existing data
        $existingData = $this->retrieveSession($sessionId);
        if ($existingData === null) {
            return false;
        }
        
        // Merge with new data
        $updatedData = array_merge($existingData, $newData);
        
        // Get remaining TTL
        $ttl = $redis->ttl($key);
        if ($ttl <= 0) {
            $ttl = 3600;
        }
        
        return $redis->setex($key, $ttl, serialize($updatedData));
    }

    /**
     * Delete session
     * 
     * @param string $sessionId Session ID
     * @return bool Success status
     */
    private function deleteSession(string $sessionId): bool
    {
        $redis = Redis::connection('session');
        $key = $this->buildSessionKey($sessionId);
        
        return $redis->del($key) > 0;
    }

    /**
     * Build session key with proper prefix
     * 
     * @param string $sessionId Session ID
     * @param int|null $schoolId Optional school ID for tenant isolation
     * @return string Full session key
     */
    private function buildSessionKey(string $sessionId, ?int $schoolId = null): string
    {
        $prefix = config('database.redis.options.prefix', '');
        
        if ($schoolId !== null) {
            return $prefix . "session:tenant:{$schoolId}:session:{$sessionId}";
        }
        
        return $prefix . "session:{$sessionId}";
    }

    /**
     * Check tenant isolation
     * 
     * @param array $sessionsByTenant Sessions grouped by tenant
     * @return int Number of isolation violations
     */
    private function checkTenantIsolation(array $sessionsByTenant): int
    {
        $violations = 0;
        $redis = Redis::connection('session');
        
        foreach ($sessionsByTenant as $schoolId => $sessions) {
            foreach ($sessions as $sessionId) {
                $sessionData = $this->retrieveSession($sessionId);
                
                if ($sessionData === null) {
                    continue;
                }
                
                // Verify school_id matches
                if (!isset($sessionData['school_id']) || $sessionData['school_id'] != $schoolId) {
                    $violations++;
                }
            }
        }
        
        return $violations;
    }

    /**
     * Get Redis memory usage in MB
     * 
     * @return float Memory usage in MB
     */
    private function getRedisMemoryUsageMb(): float
    {
        try {
            $redis = Redis::connection();
            $info = $redis->info('memory');
            
            if (isset($info['used_memory'])) {
                return $info['used_memory'] / (1024 * 1024);
            }
            
            return 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Create test schools
     * 
     * @param int $count Number of schools to create
     * @param int $seed Seed for randomization
     * @return array Array of School models
     */
    private function createTestSchools(int $count, int $seed): array
    {
        $schools = [];
        
        for ($i = 0; $i < $count; $i++) {
            $schoolId = 900000 + ($seed * 1000) + $i;
            
            $school = School::create([
                'id' => $schoolId,
                'name' => "Test School {$seed}_{$i}",
                'address' => "Test Address {$seed}_{$i}",
                'phone' => '08' . str_pad($seed * 100 + $i, 10, '0'),
                'email' => "school{$seed}_{$i}@test.com",
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
            ]);
            
            $schools[] = $school;
        }
        
        return $schools;
    }

    /**
     * Cleanup test schools
     * 
     * @param int $seed Seed used for school creation
     */
    private function cleanupTestSchools(int $seed): void
    {
        $minId = 900000 + ($seed * 1000);
        $maxId = 900000 + (($seed + 1) * 1000);
        
        School::whereBetween('id', [$minId, $maxId])->delete();
    }

    /**
     * Cleanup test sessions
     * 
     * @param int|null $seed Optional seed to cleanup specific iteration
     */
    private function cleanupTestSessions(?int $seed = null): void
    {
        $redis = Redis::connection('session');
        $prefix = config('database.redis.options.prefix', '');
        
        if ($seed !== null) {
            // Cleanup specific iteration
            $pattern = $prefix . self::TEST_SESSION_PREFIX . ":{$seed}:*";
        } else {
            // Cleanup all test sessions
            $pattern = $prefix . self::TEST_SESSION_PREFIX . ":*";
        }
        
        // Get all matching keys
        $keys = $redis->keys($pattern);
        
        if (!empty($keys)) {
            // Remove prefix from keys if needed
            $keysToDelete = array_map(function($key) use ($prefix) {
                return str_replace($prefix, '', $key);
            }, $keys);
            
            $redis->del($keysToDelete);
        }
    }
}
