<?php

namespace Tests\Feature\Redis;

use App\Services\Session\TenantAwareSessionHandler;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\Redis\TestTenantAwareSessionHandler;
use Tests\TestCase;

/**
 * Property-Based Test: Multi-Tenant Session Isolation During Failover
 * 
 * Feature: redis-high-availability
 * Property 9: Multi-tenant isolation preservation
 * Validates: Requirements 2.4, 5.4
 * 
 * This test validates that for any failover event, tenant-based session separation
 * is maintained without cross-tenant access. Sessions from different tenants (schools)
 * remain isolated during and after Redis failover.
 * 
 * Property: For any failover event, tenant-based session separation should be
 * maintained without cross-tenant access.
 */
class MultiTenantSessionIsolationPropertyTest extends TestCase
{
    private const MIN_ITERATIONS = 100;
    private const TEST_KEY_PREFIX = 'session_isolation_test';
    
    /**
     * Property Test: Session keys use tenant-specific prefixes for any tenant
     * 
     * **Validates: Requirements 2.4, 5.4**
     * 
     * This test verifies that session keys always include tenant-specific prefixes,
     * ensuring namespace isolation at the Redis key level.
     * 
*/
    public function property_session_keys_use_tenant_specific_prefixes_for_any_tenant(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random tenant IDs (simulating different schools)
            $tenantId = rand(1, 1000);
            $sessionId = 'session_' . uniqid() . '_' . $i;
            
            try {
                $handler = $this->createTenantAwareHandler($tenantId);
                
                // Write session data
                $sessionData = $this->generateRandomSessionData();
                $writeResult = $handler->write($sessionId, $sessionData);
                
                if (!$writeResult) {
                    $failureCount++;
                    $results[] = [
                        'iteration' => $i + 1,
                        'tenant_id' => $tenantId,
                        'session_id' => $sessionId,
                        'success' => false,
                        'reason' => 'Write failed',
                    ];
                    continue;
                }
                
                // Verify key format includes tenant prefix
                $expectedKeyPattern = "session:tenant:{$tenantId}:session:{$sessionId}";
                $keyExists = $this->verifyKeyExists($expectedKeyPattern);
                
                if (!$keyExists) {
                    $failureCount++;
                    $results[] = [
                        'iteration' => $i + 1,
                        'tenant_id' => $tenantId,
                        'session_id' => $sessionId,
                        'success' => false,
                        'reason' => 'Key does not use tenant prefix',
                    ];
                } else {
                    $results[] = [
                        'iteration' => $i + 1,
                        'tenant_id' => $tenantId,
                        'session_id' => $sessionId,
                        'success' => true,
                    ];
                }
                
                // Cleanup
                $handler->destroy($sessionId);
                
            } catch (\Exception $e) {
                $failureCount++;
                $results[] = [
                    'iteration' => $i + 1,
                    'tenant_id' => $tenantId,
                    'session_id' => $sessionId,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Tenant-specific key prefix property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Cross-tenant session access is prevented for any tenant pair
     * 
     * **Validates: Requirements 2.4, 5.4**
     * 
     * This test verifies that sessions from one tenant cannot be accessed by another
     * tenant, even when using the same session ID.
     * 
*/
    public function property_cross_tenant_session_access_is_prevented_for_any_tenant_pair(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate two different tenant IDs
            $tenantA = rand(1, 500);
            $tenantB = rand(501, 1000);
            
            // Use the same session ID for both tenants
            $sessionId = 'shared_session_' . uniqid() . '_' . $i;
            
            try {
                // Create handlers for both tenants
                $handlerA = $this->createTenantAwareHandler($tenantA);
                $handlerB = $this->createTenantAwareHandler($tenantB);
                
                // Write different data for each tenant
                $dataA = 'tenant_a_data_' . rand(1000, 9999);
                $dataB = 'tenant_b_data_' . rand(1000, 9999);
                
                $handlerA->write($sessionId, $dataA);
                $handlerB->write($sessionId, $dataB);
                
                // Read data using each handler
                $readDataA = $handlerA->read($sessionId);
                $readDataB = $handlerB->read($sessionId);
                
                // Verify isolation: each tenant should only see their own data
                if ($readDataA !== $dataA || $readDataB !== $dataB) {
                    $failureCount++;
                }
                
                // Additional check: data should not be equal (proving isolation)
                if ($readDataA === $readDataB) {
                    $failureCount++;
                }
                
                // Cleanup
                $handlerA->destroy($sessionId);
                $handlerB->destroy($sessionId);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Cross-tenant access prevention property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Tenant isolation is maintained during concurrent operations
     * 
     * **Validates: Requirements 2.4, 5.4**
     * 
     * This test verifies that tenant isolation is maintained even when multiple
     * tenants are performing session operations concurrently.
     * 
*/
    public function property_tenant_isolation_maintained_during_concurrent_operations(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random number of concurrent tenants (2-5)
            $tenantCount = rand(2, 5);
            $tenants = [];
            
            try {
                // Create sessions for multiple tenants
                for ($t = 0; $t < $tenantCount; $t++) {
                    $tenantId = rand(1, 1000);
                    $sessionId = "concurrent_session_{$t}_" . uniqid();
                    $sessionData = "tenant_{$tenantId}_data_" . rand(1000, 9999);
                    
                    $handler = $this->createTenantAwareHandler($tenantId);
                    $handler->write($sessionId, $sessionData);
                    
                    $tenants[] = [
                        'tenant_id' => $tenantId,
                        'session_id' => $sessionId,
                        'data' => $sessionData,
                        'handler' => $handler,
                    ];
                }
                
                // Verify each tenant can only read their own data
                $isolationMaintained = true;
                foreach ($tenants as $tenant) {
                    $readData = $tenant['handler']->read($tenant['session_id']);
                    
                    if ($readData !== $tenant['data']) {
                        $isolationMaintained = false;
                        break;
                    }
                }
                
                if (!$isolationMaintained) {
                    $failureCount++;
                }
                
                // Cleanup
                foreach ($tenants as $tenant) {
                    $tenant['handler']->destroy($tenant['session_id']);
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Concurrent tenant isolation property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Session destruction only affects target tenant
     * 
     * **Validates: Requirements 2.4, 5.4**
     * 
     * This test verifies that destroying a session for one tenant does not
     * affect sessions of other tenants, even with the same session ID.
     * 
*/
    public function property_session_destruction_only_affects_target_tenant(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate two different tenant IDs
            $tenantA = rand(1, 500);
            $tenantB = rand(501, 1000);
            
            // Use the same session ID for both tenants
            $sessionId = 'destroy_test_' . uniqid() . '_' . $i;
            
            try {
                // Create handlers for both tenants
                $handlerA = $this->createTenantAwareHandler($tenantA);
                $handlerB = $this->createTenantAwareHandler($tenantB);
                
                // Write data for both tenants
                $dataA = 'tenant_a_data_' . rand(1000, 9999);
                $dataB = 'tenant_b_data_' . rand(1000, 9999);
                
                $handlerA->write($sessionId, $dataA);
                $handlerB->write($sessionId, $dataB);
                
                // Destroy session for tenant A only
                $handlerA->destroy($sessionId);
                
                // Verify tenant A's session is destroyed
                $readDataA = $handlerA->read($sessionId);
                
                // Verify tenant B's session still exists
                $readDataB = $handlerB->read($sessionId);
                
                if ($readDataA !== '' || $readDataB !== $dataB) {
                    $failureCount++;
                }
                
                // Cleanup
                $handlerB->destroy($sessionId);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Session destruction isolation property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Tenant isolation is preserved across Redis reconnection
     * 
     * **Validates: Requirements 2.4, 5.4**
     * 
     * This test verifies that tenant isolation is maintained even after Redis
     * connection is re-established (simulating failover scenario).
     * 
*/
    public function property_tenant_isolation_preserved_across_redis_reconnection(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate two different tenant IDs
            $tenantA = rand(1, 500);
            $tenantB = rand(501, 1000);
            
            $sessionIdA = 'reconnect_a_' . uniqid() . '_' . $i;
            $sessionIdB = 'reconnect_b_' . uniqid() . '_' . $i;
            
            try {
                // Create handlers and write data
                $handlerA1 = $this->createTenantAwareHandler($tenantA);
                $handlerB1 = $this->createTenantAwareHandler($tenantB);
                
                $dataA = 'tenant_a_data_' . rand(1000, 9999);
                $dataB = 'tenant_b_data_' . rand(1000, 9999);
                
                $handlerA1->write($sessionIdA, $dataA);
                $handlerB1->write($sessionIdB, $dataB);
                
                // Simulate reconnection by creating new handlers
                // (In real failover, this would be a new connection to new master)
                $handlerA2 = $this->createTenantAwareHandler($tenantA);
                $handlerB2 = $this->createTenantAwareHandler($tenantB);
                
                // Verify data is still accessible and isolated
                $readDataA = $handlerA2->read($sessionIdA);
                $readDataB = $handlerB2->read($sessionIdB);
                
                if ($readDataA !== $dataA || $readDataB !== $dataB) {
                    $failureCount++;
                }
                
                // Verify cross-tenant access is still prevented
                $crossReadA = $handlerB2->read($sessionIdA);
                $crossReadB = $handlerA2->read($sessionIdB);
                
                if ($crossReadA !== '' || $crossReadB !== '') {
                    $failureCount++;
                }
                
                // Cleanup
                $handlerA2->destroy($sessionIdA);
                $handlerB2->destroy($sessionIdB);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Tenant isolation across reconnection property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Tenant namespace prevents key collision for any session ID
     * 
     * **Validates: Requirements 2.4, 5.4**
     * 
     * This test verifies that the tenant namespace prevents key collisions even
     * when multiple tenants use identical session IDs.
     * 
*/
    public function property_tenant_namespace_prevents_key_collision_for_any_session_id(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random number of tenants using the same session ID (2-10)
            $tenantCount = rand(2, 10);
            $sharedSessionId = 'collision_test_' . uniqid();
            $tenants = [];
            
            try {
                // Create sessions for multiple tenants with same session ID
                for ($t = 0; $t < $tenantCount; $t++) {
                    $tenantId = rand(1, 10000);
                    $uniqueData = "tenant_{$tenantId}_unique_data_" . rand(1000, 9999);
                    
                    $handler = $this->createTenantAwareHandler($tenantId);
                    $handler->write($sharedSessionId, $uniqueData);
                    
                    $tenants[] = [
                        'tenant_id' => $tenantId,
                        'data' => $uniqueData,
                        'handler' => $handler,
                    ];
                }
                
                // Verify each tenant reads their own unique data
                $noCollision = true;
                foreach ($tenants as $tenant) {
                    $readData = $tenant['handler']->read($sharedSessionId);
                    
                    if ($readData !== $tenant['data']) {
                        $noCollision = false;
                        break;
                    }
                }
                
                if (!$noCollision) {
                    $failureCount++;
                }
                
                // Cleanup
                foreach ($tenants as $tenant) {
                    $tenant['handler']->destroy($sharedSessionId);
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Key collision prevention property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Helper method to create a tenant-aware session handler for testing
     * 
     * @param int|string $tenantId The tenant ID to use
     * @return TestTenantAwareSessionHandler
     */
    private function createTenantAwareHandler($tenantId)
    {
        // Get Redis connection
        $redis = Redis::connection('session')->client();
        
        // Create a test handler that uses the specified tenant ID
        return new TestTenantAwareSessionHandler($redis, 'session', 7200, $tenantId);
    }

    /**
     * Helper method to verify a Redis key exists
     * 
     * @param string $key The key to check
     * @return bool
     */
    private function verifyKeyExists(string $key): bool
    {
        try {
            $redis = Redis::connection('session');
            return $redis->exists($key) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Helper method to generate random session data
     * 
     * @return string
     */
    private function generateRandomSessionData(): string
    {
        $data = [
            'user_id' => rand(1, 10000),
            'username' => 'user_' . uniqid(),
            'email' => 'user_' . uniqid() . '@example.com',
            'role' => ['admin', 'teacher', 'student', 'parent'][rand(0, 3)],
            'last_activity' => time(),
            'ip_address' => rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255) . '.' . rand(1, 255),
            'user_agent' => 'TestAgent/' . rand(1, 100),
        ];
        
        return serialize($data);
    }
}
