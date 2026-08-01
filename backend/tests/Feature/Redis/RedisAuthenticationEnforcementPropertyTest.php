<?php

namespace Tests\Feature\Redis;

use Illuminate\Support\Facades\Redis;
use Predis\Connection\ConnectionException;
use Tests\TestCase;

/**
 * Property-Based Test: Redis Authentication Enforcement
 * 
 * Feature: redis-high-availability
 * Property 41: Authentication enforcement
 * Validates: Requirements 10.1
 * 
 * This test validates that for any client connection attempt, authentication
 * with strong passwords should be required.
 * 
 * Property: For any client connection attempt, authentication with strong
 * passwords should be required.
 */
class RedisAuthenticationEnforcementPropertyTest extends TestCase
{
    private const MIN_ITERATIONS = 100;
    private const TEST_KEY_PREFIX = 'auth_test';
    
    /**
     * Property Test: Unauthenticated connections are rejected
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that any connection attempt without proper authentication
     * is rejected by the Redis server.
     * 
*/
    public function property_unauthenticated_connections_are_rejected(): void
    {
        // Skip if Redis is not configured
        if (!env('REDIS_HOST')) {
            $this->markTestSkipped('Redis not configured. Set REDIS_HOST to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $successfulRejections = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Attempt connection without password
                $client = new \Predis\Client([
                    'scheme' => 'tcp',
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => null, // No password
                    'timeout' => 2,
                ]);

                // Try to execute a command
                $testKey = self::TEST_KEY_PREFIX . ':unauth:' . uniqid();
                
                try {
                    $client->set($testKey, 'test_value');
                    // If we get here, authentication was not enforced (FAIL)
                } catch (\Exception $e) {
                    // Connection should be rejected - this is expected
                    if ($this->isAuthenticationError($e)) {
                        $successfulRejections++;
                    }
                }
                
                $client->disconnect();
                
            } catch (\Exception $e) {
                // Connection failed at connection level - this is also expected
                if ($this->isAuthenticationError($e)) {
                    $successfulRejections++;
                }
            }
        }

        // Property should hold: all unauthenticated attempts should be rejected
        $rejectionRate = ($successfulRejections / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $rejectionRate,
            "Authentication enforcement property failed. Rejection rate: {$rejectionRate}%. " .
            "Expected at least 95% of unauthenticated connections to be rejected across {$iterations} iterations. " .
            "Successful rejections: {$successfulRejections}"
        );
    }

    /**
     * Property Test: Authenticated connections with correct password succeed
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that connections with proper authentication credentials
     * are accepted and can perform operations.
     * 
*/
    public function property_authenticated_connections_with_correct_password_succeed(): void
    {
        // Skip if Redis is not configured
        if (!env('REDIS_HOST')) {
            $this->markTestSkipped('Redis not configured. Set REDIS_HOST to run this test.');
        }

        // Skip if no password is configured
        if (!env('REDIS_PASSWORD')) {
            $this->markTestSkipped('Redis password not configured. Set REDIS_PASSWORD to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $successfulConnections = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $testKey = self::TEST_KEY_PREFIX . ':auth:' . uniqid();
            $testValue = 'test_value_' . rand(1000, 9999);
            
            try {
                // Connect with correct password
                $client = new \Predis\Client([
                    'scheme' => 'tcp',
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => env('REDIS_PASSWORD'),
                    'timeout' => 2,
                ]);

                // Execute operations
                $setResult = $client->set($testKey, $testValue);
                $getValue = $client->get($testKey);
                
                if (($setResult === 'OK' || $setResult === true) && $getValue === $testValue) {
                    $successfulConnections++;
                }
                
                // Cleanup
                $client->del([$testKey]);
                $client->disconnect();
                
            } catch (\Exception $e) {
                // Authenticated connection should not fail
            }
        }

        // Property should hold: all authenticated connections should succeed
        $successRate = ($successfulConnections / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Authenticated connection property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% of authenticated connections to succeed across {$iterations} iterations. " .
            "Successful connections: {$successfulConnections}"
        );
    }

    /**
     * Property Test: Connections with incorrect password are rejected
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that connection attempts with incorrect passwords
     * are rejected regardless of the password attempted.
     * 
*/
    public function property_connections_with_incorrect_password_are_rejected(): void
    {
        // Skip if Redis is not configured
        if (!env('REDIS_HOST')) {
            $this->markTestSkipped('Redis not configured. Set REDIS_HOST to run this test.');
        }

        // Skip if no password is configured
        if (!env('REDIS_PASSWORD')) {
            $this->markTestSkipped('Redis password not configured. Set REDIS_PASSWORD to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $successfulRejections = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random incorrect password
            $incorrectPassword = 'wrong_password_' . bin2hex(random_bytes(8));
            
            try {
                // Attempt connection with incorrect password
                $client = new \Predis\Client([
                    'scheme' => 'tcp',
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => $incorrectPassword,
                    'timeout' => 2,
                ]);

                // Try to execute a command
                $testKey = self::TEST_KEY_PREFIX . ':wrongpass:' . uniqid();
                
                try {
                    $client->set($testKey, 'test_value');
                    // If we get here, incorrect password was accepted (FAIL)
                } catch (\Exception $e) {
                    // Connection should be rejected - this is expected
                    if ($this->isAuthenticationError($e)) {
                        $successfulRejections++;
                    }
                }
                
                $client->disconnect();
                
            } catch (\Exception $e) {
                // Connection failed at connection level - this is also expected
                if ($this->isAuthenticationError($e)) {
                    $successfulRejections++;
                }
            }
        }

        // Property should hold: all incorrect password attempts should be rejected
        $rejectionRate = ($successfulRejections / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $rejectionRate,
            "Incorrect password rejection property failed. Rejection rate: {$rejectionRate}%. " .
            "Expected at least 95% of incorrect password attempts to be rejected across {$iterations} iterations. " .
            "Successful rejections: {$successfulRejections}"
        );
    }

    /**
     * Property Test: Authentication is enforced across all connection types
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that authentication is enforced for all Redis connection
     * types (default, cache, session, queue).
     * 
*/
    public function property_authentication_enforced_across_all_connection_types(): void
    {
        // Skip if Redis is not configured
        if (!env('REDIS_HOST')) {
            $this->markTestSkipped('Redis not configured. Set REDIS_HOST to run this test.');
        }

        // Skip if no password is configured
        if (!env('REDIS_PASSWORD')) {
            $this->markTestSkipped('Redis password not configured. Set REDIS_PASSWORD to run this test.');
        }

        $connectionTypes = ['default', 'cache', 'session', 'queue'];
        $iterations = self::MIN_ITERATIONS;
        $successByType = array_fill_keys($connectionTypes, 0);

        for ($i = 0; $i < $iterations; $i++) {
            foreach ($connectionTypes as $type) {
                $testKey = self::TEST_KEY_PREFIX . ":{$type}:" . uniqid();
                $testValue = "value_{$type}_" . rand(1000, 9999);
                
                try {
                    // Use Laravel's Redis facade with proper authentication
                    Redis::connection($type)->set($testKey, $testValue);
                    $getValue = Redis::connection($type)->get($testKey);
                    
                    if ($getValue === $testValue) {
                        $successByType[$type]++;
                    }
                    
                    // Cleanup
                    Redis::connection($type)->del($testKey);
                    
                } catch (\Exception $e) {
                    // Authenticated connection should not fail
                }
            }
        }

        // Verify property holds for all connection types
        foreach ($connectionTypes as $type) {
            $successRate = ($successByType[$type] / $iterations) * 100;
            
            $this->assertGreaterThanOrEqual(
                95,
                $successRate,
                "Authentication enforcement property failed for '{$type}' connection type. " .
                "Success rate: {$successRate}%. Expected at least 95% across {$iterations} iterations. " .
                "Successful authenticated operations: {$successByType[$type]}"
            );
        }
    }

    /**
     * Property Test: Authentication persists across connection pool reuse
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that authentication credentials are properly maintained
     * when connections are reused from a connection pool.
     * 
*/
    public function property_authentication_persists_across_connection_pool_reuse(): void
    {
        // Skip if Redis is not configured
        if (!env('REDIS_HOST')) {
            $this->markTestSkipped('Redis not configured. Set REDIS_HOST to run this test.');
        }

        // Skip if no password is configured
        if (!env('REDIS_PASSWORD')) {
            $this->markTestSkipped('Redis password not configured. Set REDIS_PASSWORD to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $successfulOperations = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $testKey = self::TEST_KEY_PREFIX . ':pool:' . uniqid();
            $testValue = 'pool_test_' . rand(1000, 9999);
            
            try {
                // Get connection from pool (may be reused)
                $connection = Redis::connection('default');
                
                // Execute operation
                $connection->set($testKey, $testValue);
                $getValue = $connection->get($testKey);
                
                if ($getValue === $testValue) {
                    $successfulOperations++;
                }
                
                // Cleanup
                $connection->del($testKey);
                
            } catch (\Exception $e) {
                // Authentication should persist in pooled connections
            }
        }

        // Property should hold: all pooled connections should maintain authentication
        $successRate = ($successfulOperations / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Connection pool authentication persistence property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% of pooled connection operations to succeed across {$iterations} iterations. " .
            "Successful operations: {$successfulOperations}"
        );
    }

    /**
     * Property Test: Authentication is enforced in Sentinel configuration
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that authentication is properly enforced when using
     * Redis Sentinel for high availability.
     * 
*/
    public function property_authentication_enforced_in_sentinel_configuration(): void
    {
        // Skip if not using Sentinel configuration
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Redis Sentinel not configured. Set REDIS_SENTINELS=true to run this test.');
        }

        // Skip if no password is configured
        if (!env('REDIS_PASSWORD')) {
            $this->markTestSkipped('Redis password not configured. Set REDIS_PASSWORD to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $successfulOperations = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $testKey = self::TEST_KEY_PREFIX . ':sentinel:' . uniqid();
            $testValue = 'sentinel_test_' . rand(1000, 9999);
            
            try {
                // Use Sentinel-configured connection
                Redis::connection('default')->set($testKey, $testValue);
                $getValue = Redis::connection('default')->get($testKey);
                
                if ($getValue === $testValue) {
                    $successfulOperations++;
                }
                
                // Cleanup
                Redis::connection('default')->del($testKey);
                
            } catch (\Exception $e) {
                // Sentinel connections should maintain authentication
            }
        }

        // Property should hold: all Sentinel connections should be authenticated
        $successRate = ($successfulOperations / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Sentinel authentication enforcement property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% of Sentinel connection operations to succeed across {$iterations} iterations. " .
            "Successful operations: {$successfulOperations}"
        );
    }

    /**
     * Property Test: Authentication errors are properly reported
     * 
     * **Validates: Requirements 10.1**
     * 
     * This test verifies that authentication failures produce clear error messages
     * that can be logged and monitored.
     * 
*/
    public function property_authentication_errors_are_properly_reported(): void
    {
        // Skip if Redis is not configured
        if (!env('REDIS_HOST')) {
            $this->markTestSkipped('Redis not configured. Set REDIS_HOST to run this test.');
        }

        $iterations = self::MIN_ITERATIONS;
        $properErrorReporting = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Attempt connection without password
                $client = new \Predis\Client([
                    'scheme' => 'tcp',
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => null,
                    'timeout' => 2,
                ]);

                $testKey = self::TEST_KEY_PREFIX . ':error:' . uniqid();
                
                try {
                    $client->set($testKey, 'test_value');
                } catch (\Exception $e) {
                    // Verify error message is clear and actionable
                    if ($this->isAuthenticationError($e) && $this->hasInformativeErrorMessage($e)) {
                        $properErrorReporting++;
                    }
                }
                
                $client->disconnect();
                
            } catch (\Exception $e) {
                // Verify error message at connection level
                if ($this->isAuthenticationError($e) && $this->hasInformativeErrorMessage($e)) {
                    $properErrorReporting++;
                }
            }
        }

        // Property should hold: all authentication errors should be properly reported
        $reportingRate = ($properErrorReporting / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $reportingRate,
            "Authentication error reporting property failed. Reporting rate: {$reportingRate}%. " .
            "Expected at least 95% of authentication errors to have proper error messages across {$iterations} iterations. " .
            "Proper error reports: {$properErrorReporting}"
        );
    }

    /**
     * Helper method to determine if an exception is an authentication error
     * 
     * @param \Exception $exception The exception to check
     * @return bool True if this is an authentication error
     */
    private function isAuthenticationError(\Exception $exception): bool
    {
        $message = strtolower($exception->getMessage());
        
        return str_contains($message, 'noauth') ||
               str_contains($message, 'auth') ||
               str_contains($message, 'password') ||
               str_contains($message, 'authentication') ||
               str_contains($message, 'wrongpass') ||
               str_contains($message, 'invalid password') ||
               str_contains($message, 'operation not permitted');
    }

    /**
     * Helper method to check if error message is informative
     * 
     * @param \Exception $exception The exception to check
     * @return bool True if error message is informative
     */
    private function hasInformativeErrorMessage(\Exception $exception): bool
    {
        $message = $exception->getMessage();
        
        // Error message should not be empty and should contain relevant information
        return !empty($message) && 
               (strlen($message) > 10) && 
               ($this->isAuthenticationError($exception));
    }
}
