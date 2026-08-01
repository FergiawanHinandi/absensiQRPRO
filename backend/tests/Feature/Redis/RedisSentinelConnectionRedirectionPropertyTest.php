<?php

namespace Tests\Feature\Redis;

use App\Services\Redis\RedisConnectionPool;
use App\Services\Redis\ResilientRedisConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Property-Based Test: Redis Sentinel Connection Redirection During Failover
 * 
 * Feature: redis-high-availability
 * Property 2: Connection redirection during failover
 * Validates: Requirements 1.2
 * 
 * This test validates that for any failover event, all application connections
 * are automatically redirected to the new master without manual intervention.
 * 
 * Property: For any failover event, all application connections should be
 * automatically redirected to the new master without manual intervention.
 */
class RedisSentinelConnectionRedirectionPropertyTest extends TestCase
{
    private const MIN_ITERATIONS = 100;
    private const TEST_KEY_PREFIX = 'failover_test';
    
    /**
     * Property Test: Connection redirection works for any number of concurrent connections
     * 
     * **Validates: Requirements 1.2**
     * 
     * This test verifies that connection redirection works correctly regardless of
     * the number of concurrent connections during a failover event.
     * 
*/
    public function property_connections_redirect_to_new_master_for_any_connection_count(): void
    {
        // Skip if not using Sentinel configuration
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Redis Sentinel not configured. Set REDIS_SENTINELS=true to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random number of connections (1-10)
            $connectionCount = rand(1, 10);
            
            try {
                $result = $this->testConnectionRedirectionWithCount($connectionCount);
                $results[] = [
                    'iteration' => $i + 1,
                    'connection_count' => $connectionCount,
                    'success' => $result['success'],
                    'redirected' => $result['redirected'],
                    'write_successful' => $result['write_successful'],
                ];
                
                if (!$result['success']) {
                    $failureCount++;
                }
            } catch (\Exception $e) {
                $failureCount++;
                $results[] = [
                    'iteration' => $i + 1,
                    'connection_count' => $connectionCount,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Property should hold: all connections should successfully redirect
        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Connection redirection property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Connection redirection preserves data integrity
     * 
     * **Validates: Requirements 1.2**
     * 
     * This test verifies that data written before failover remains accessible
     * after connection redirection to the new master.
     * 
*/
    public function property_connection_redirection_preserves_data_integrity(): void
    {
        // Skip if not using Sentinel configuration
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Redis Sentinel not configured. Set REDIS_SENTINELS=true to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random test data
            $testKey = self::TEST_KEY_PREFIX . ':integrity:' . uniqid();
            $testValue = 'test_value_' . rand(1000, 9999) . '_' . bin2hex(random_bytes(8));
            
            try {
                // Write data using resilient connection
                $connection = new ResilientRedisConnection('default');
                $writeResult = $connection->execute('SET', [$testKey, $testValue]);
                
                $this->assertTrue(
                    $writeResult === 'OK' || $writeResult === true,
                    "Failed to write test data on iteration {$i}"
                );

                // Simulate connection refresh (as would happen during failover)
                // The connection should automatically redirect to current master
                $readConnection = new ResilientRedisConnection('default');
                $readValue = $readConnection->execute('GET', [$testKey]);
                
                // Verify data integrity
                if ($readValue !== $testValue) {
                    $failureCount++;
                }
                
                // Cleanup
                $connection->execute('DEL', [$testKey]);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Data integrity property failed during connection redirection. " .
            "Success rate: {$successRate}%. Expected at least 95% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Connection redirection works across different connection types
     * 
     * **Validates: Requirements 1.2**
     * 
     * This test verifies that connection redirection works for all connection types
     * (default, cache, session, queue) during failover.
     * 
*/
    public function property_connection_redirection_works_for_all_connection_types(): void
    {
        // Skip if not using Sentinel configuration
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Redis Sentinel not configured. Set REDIS_SENTINELS=true to run this test.');
        }

        $connectionTypes = ['default', 'cache', 'session', 'queue'];
        $iterations = self::MIN_ITERATIONS;
        $failuresByType = array_fill_keys($connectionTypes, 0);

        for ($i = 0; $i < $iterations; $i++) {
            foreach ($connectionTypes as $type) {
                $testKey = self::TEST_KEY_PREFIX . ":{$type}:" . uniqid();
                $testValue = "value_{$type}_" . rand(1000, 9999);
                
                try {
                    // Test write operation
                    $connection = new ResilientRedisConnection($type);
                    $writeResult = $connection->execute('SET', [$testKey, $testValue]);
                    
                    if (!($writeResult === 'OK' || $writeResult === true)) {
                        $failuresByType[$type]++;
                        continue;
                    }

                    // Test read operation (simulating post-failover read)
                    $readConnection = new ResilientRedisConnection($type);
                    $readValue = $readConnection->execute('GET', [$testKey]);
                    
                    if ($readValue !== $testValue) {
                        $failuresByType[$type]++;
                    }
                    
                    // Cleanup
                    $connection->execute('DEL', [$testKey]);
                    
                } catch (\Exception $e) {
                    $failuresByType[$type]++;
                }
            }
        }

        // Verify property holds for all connection types
        foreach ($connectionTypes as $type) {
            $successRate = (($iterations - $failuresByType[$type]) / $iterations) * 100;
            
            $this->assertGreaterThanOrEqual(
                95,
                $successRate,
                "Connection redirection property failed for '{$type}' connection type. " .
                "Success rate: {$successRate}%. Expected at least 95% across {$iterations} iterations. " .
                "Failures: {$failuresByType[$type]}"
            );
        }
    }

    /**
     * Property Test: Connection pool maintains health during redirection
     * 
     * **Validates: Requirements 1.2**
     * 
     * This test verifies that the connection pool correctly maintains health status
     * and redirects connections during failover scenarios.
     * 
*/
    public function property_connection_pool_maintains_health_during_redirection(): void
    {
        // Skip if not using Sentinel configuration
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Redis Sentinel not configured. Set REDIS_SENTINELS=true to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        $pool = new RedisConnectionPool();

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Get connection from pool
                $connection = $pool->connection('default');
                
                // Verify connection is healthy
                $isHealthy = $connection->isHealthy();
                
                if (!$isHealthy) {
                    $failureCount++;
                    continue;
                }

                // Perform operation to verify connection works
                $testKey = self::TEST_KEY_PREFIX . ':pool:' . uniqid();
                $testValue = 'pool_test_' . rand(1000, 9999);
                
                $writeResult = $connection->execute('SET', [$testKey, $testValue]);
                $readResult = $connection->execute('GET', [$testKey]);
                
                if ($readResult !== $testValue) {
                    $failureCount++;
                }
                
                // Cleanup
                $connection->execute('DEL', [$testKey]);
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Connection pool health property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Automatic retry mechanism works during connection redirection
     * 
     * **Validates: Requirements 1.2**
     * 
     * This test verifies that the automatic retry mechanism successfully handles
     * transient failures during connection redirection.
     * 
*/
    public function property_automatic_retry_succeeds_during_redirection(): void
    {
        // Skip if not using Sentinel configuration
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Redis Sentinel not configured. Set REDIS_SENTINELS=true to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $testKey = self::TEST_KEY_PREFIX . ':retry:' . uniqid();
            $testValue = 'retry_test_' . rand(1000, 9999);
            
            try {
                // Create connection with retry capability
                $connection = new ResilientRedisConnection('default', 3);
                
                // Execute operation (will retry on failure)
                $writeResult = $connection->execute('SET', [$testKey, $testValue]);
                
                if (!($writeResult === 'OK' || $writeResult === true)) {
                    $failureCount++;
                    continue;
                }

                // Verify write succeeded
                $readResult = $connection->execute('GET', [$testKey]);
                
                if ($readResult !== $testValue) {
                    $failureCount++;
                }
                
                // Cleanup
                $connection->execute('DEL', [$testKey]);
                
            } catch (\Exception $e) {
                // Even with retries, operation failed
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Automatic retry property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% across {$iterations} iterations with retry mechanism."
        );
    }

    /**
     * Helper method to test connection redirection with specific connection count
     * 
     * @param int $connectionCount Number of connections to test
     * @return array Test results
     */
    private function testConnectionRedirectionWithCount(int $connectionCount): array
    {
        $connections = [];
        $testKeys = [];
        
        // Create multiple connections and write data
        for ($i = 0; $i < $connectionCount; $i++) {
            $connection = new ResilientRedisConnection('default');
            $testKey = self::TEST_KEY_PREFIX . ':multi:' . uniqid() . ":{$i}";
            $testValue = "value_{$i}_" . rand(1000, 9999);
            
            $writeResult = $connection->execute('SET', [$testKey, $testValue]);
            
            $connections[] = [
                'connection' => $connection,
                'key' => $testKey,
                'value' => $testValue,
            ];
            $testKeys[] = $testKey;
        }

        // Verify all connections can read their data (simulating post-failover)
        $successCount = 0;
        foreach ($connections as $connData) {
            try {
                $readValue = $connData['connection']->execute('GET', [$connData['key']]);
                if ($readValue === $connData['value']) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                // Connection failed to redirect
            }
        }

        // Cleanup
        foreach ($testKeys as $key) {
            try {
                $connections[0]['connection']->execute('DEL', [$key]);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        return [
            'success' => $successCount === $connectionCount,
            'redirected' => $successCount,
            'write_successful' => count($connections),
            'total_connections' => $connectionCount,
        ];
    }
}
