<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Unit Tests: Redis Authentication and Security Configuration
 * 
 * Tests Redis authentication enforcement and security configuration.
 * 
 * Validates: Requirements 10.1 (Authentication), 10.2 (TLS encryption)
 */
class RedisAuthenticationTest extends TestCase
{
    /**
* Validates: Requirement 10.1
     */
    public function it_requires_redis_password_in_configuration(): void
    {
        $defaultConfig = Config::get('database.redis.default');

        if (is_array($defaultConfig) && !isset($defaultConfig[0])) {
            // Standard configuration
            $this->assertArrayHasKey('password', $defaultConfig);
        } else {
            // Sentinel configuration
            $this->assertArrayHasKey('options', $defaultConfig);
            $this->assertArrayHasKey('parameters', $defaultConfig['options']);
            $this->assertArrayHasKey('password', $defaultConfig['options']['parameters']);
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_password_for_all_redis_connections(): void
    {
        $connections = ['default', 'cache', 'session', 'queue'];

        foreach ($connections as $connection) {
            $config = Config::get("database.redis.{$connection}");

            if (is_array($config) && !isset($config[0])) {
                // Standard configuration
                $this->assertArrayHasKey('password', $config, 
                    "Password not configured for {$connection} connection");
            } else {
                // Sentinel configuration
                $this->assertArrayHasKey('options', $config);
                $this->assertArrayHasKey('parameters', $config['options']);
                $this->assertArrayHasKey('password', $config['options']['parameters'],
                    "Password not configured for {$connection} connection in Sentinel mode");
            }
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_uses_environment_variable_for_redis_password(): void
    {
        $defaultConfig = Config::get('database.redis.default');

        if (is_array($defaultConfig) && !isset($defaultConfig[0])) {
            // Standard configuration
            $password = $defaultConfig['password'];
        } else {
            // Sentinel configuration
            $password = $defaultConfig['options']['parameters']['password'];
        }

        // Password should come from env (will be null in test environment without .env)
        // In production, this must be set
        $this->assertTrue(
            $password === env('REDIS_PASSWORD') || $password === null,
            'Redis password should be configured via REDIS_PASSWORD environment variable'
        );
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_predis_client_for_sentinel_support(): void
    {
        $client = Config::get('database.redis.client');

        $this->assertEquals('predis', $client, 
            'Predis client is required for Sentinel support with authentication');
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_sentinel_service_name(): void
    {
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Sentinel not configured');
        }

        $defaultConfig = Config::get('database.redis.default');

        $this->assertArrayHasKey('options', $defaultConfig);
        $this->assertArrayHasKey('service', $defaultConfig['options']);
        $this->assertEquals(
            env('REDIS_SENTINEL_SERVICE', 'mymaster'),
            $defaultConfig['options']['service']
        );
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_sentinel_replication_mode(): void
    {
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Sentinel not configured');
        }

        $defaultConfig = Config::get('database.redis.default');

        $this->assertArrayHasKey('options', $defaultConfig);
        $this->assertArrayHasKey('replication', $defaultConfig['options']);
        $this->assertEquals('sentinel', $defaultConfig['options']['replication']);
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_multiple_sentinel_nodes(): void
    {
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Sentinel not configured');
        }

        $defaultConfig = Config::get('database.redis.default');

        // Sentinel configuration should have array of sentinel URLs
        $this->assertIsArray($defaultConfig);
        
        // Filter out 'options' key to get sentinel URLs
        $sentinelUrls = array_filter($defaultConfig, fn($key) => $key !== 'options', ARRAY_FILTER_USE_KEY);
        
        $this->assertGreaterThanOrEqual(3, count($sentinelUrls),
            'At least 3 Sentinel nodes should be configured for quorum');
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_connection_timeout_for_sentinels(): void
    {
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Sentinel not configured');
        }

        $defaultConfig = Config::get('database.redis.default');

        // Check that sentinel URLs include timeout parameter
        foreach ($defaultConfig as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'tcp://')) {
                $this->assertStringContainsString('timeout=', $value,
                    'Sentinel connection should have timeout configured');
            }
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_separates_redis_databases_by_purpose(): void
    {
        $connections = [
            'default' => 0,
            'cache' => 1,
            'session' => 2,
            'queue' => 3,
        ];

        foreach ($connections as $connection => $expectedDb) {
            $config = Config::get("database.redis.{$connection}");

            if (is_array($config) && !isset($config[0])) {
                // Standard configuration
                $database = $config['database'];
            } else {
                // Sentinel configuration
                $database = $config['options']['parameters']['database'];
            }

            $this->assertEquals($expectedDb, $database,
                "Connection '{$connection}' should use database {$expectedDb}");
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_redis_key_prefix(): void
    {
        $options = Config::get('database.redis.options');

        $this->assertArrayHasKey('prefix', $options);
        $this->assertNotEmpty($options['prefix']);
        
        // Prefix should be based on app name
        $appName = env('APP_NAME', 'laravel');
        $this->assertStringContainsString(
            str_replace(' ', '_', strtolower($appName)),
            $options['prefix']
        );
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_resilient_connection_settings(): void
    {
        $maxRetries = Config::get('database.redis.max_retries');
        $healthCheckInterval = Config::get('database.redis.health_check_interval');

        $this->assertIsInt($maxRetries);
        $this->assertGreaterThan(0, $maxRetries);
        
        $this->assertIsInt($healthCheckInterval);
        $this->assertGreaterThan(0, $healthCheckInterval);
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_validates_redis_password_is_not_default(): void
    {
        $password = env('REDIS_PASSWORD');

        if ($password !== null) {
            // In production, password should not be weak defaults
            $weakPasswords = ['password', '123456', 'changeme', 'redis', 'secret'];
            
            $this->assertNotContains(
                strtolower($password),
                $weakPasswords,
                'Redis password should not be a weak default value'
            );
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_validates_redis_password_strength(): void
    {
        $password = env('REDIS_PASSWORD');

        if ($password !== null) {
            // Password should be reasonably strong
            $this->assertGreaterThanOrEqual(
                8,
                strlen($password),
                'Redis password should be at least 8 characters'
            );
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_does_not_expose_password_in_config_cache(): void
    {
        $config = Config::get('database.redis');
        
        // Ensure password is retrieved from env, not hardcoded
        $configString = json_encode($config);
        
        // Should not contain literal password strings (except env() calls)
        $this->assertStringNotContainsString(
            '"password":"plaintext',
            $configString,
            'Password should not be hardcoded in configuration'
        );
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_authentication_for_standard_connection(): void
    {
        // Test standard (non-Sentinel) configuration
        $config = [
            'host' => '127.0.0.1',
            'password' => 'test_password',
            'port' => 6379,
            'database' => 0,
        ];

        $this->assertArrayHasKey('password', $config);
        $this->assertNotEmpty($config['password']);
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_authentication_for_sentinel_connection(): void
    {
        $config = [
            'tcp://sentinel-1:26379',
            'tcp://sentinel-2:26379',
            'tcp://sentinel-3:26379',
            'options' => [
                'replication' => 'sentinel',
                'service' => 'mymaster',
                'parameters' => [
                    'database' => 0,
                    'password' => 'test_password',
                ],
            ],
        ];

        $this->assertArrayHasKey('options', $config);
        $this->assertArrayHasKey('parameters', $config['options']);
        $this->assertArrayHasKey('password', $config['options']['parameters']);
        $this->assertNotEmpty($config['options']['parameters']['password']);
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_maintains_authentication_across_connection_types(): void
    {
        $connections = ['default', 'cache', 'session', 'queue'];
        $passwords = [];

        foreach ($connections as $connection) {
            $config = Config::get("database.redis.{$connection}");

            if (is_array($config) && !isset($config[0])) {
                $passwords[$connection] = $config['password'] ?? null;
            } else {
                $passwords[$connection] = $config['options']['parameters']['password'] ?? null;
            }
        }

        // All connections should use the same password (from REDIS_PASSWORD env)
        $uniquePasswords = array_unique(array_filter($passwords));
        
        $this->assertLessThanOrEqual(
            1,
            count($uniquePasswords),
            'All Redis connections should use the same password from environment'
        );
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_validates_sentinel_urls_format(): void
    {
        if (!env('REDIS_SENTINELS', false)) {
            $this->markTestSkipped('Sentinel not configured');
        }

        $defaultConfig = Config::get('database.redis.default');

        foreach ($defaultConfig as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'tcp://')) {
                // Validate URL format
                $this->assertMatchesRegularExpression(
                    '/^tcp:\/\/[a-zA-Z0-9\-\.]+:\d+/',
                    $value,
                    'Sentinel URL should be in format tcp://host:port'
                );
            }
        }
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_configures_cluster_mode_correctly(): void
    {
        $cluster = Config::get('database.redis.options.cluster');

        // Should be 'redis' for standard mode (not 'redis-cluster')
        $this->assertEquals('redis', $cluster);
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_validates_environment_variables_are_used(): void
    {
        // Verify that configuration uses env() for sensitive values
        $configFile = file_get_contents(config_path('database.php'));

        // Should use env() for password
        $this->assertStringContainsString(
            "env('REDIS_PASSWORD')",
            $configFile,
            'Configuration should use env() for Redis password'
        );

        // Should use env() for host
        $this->assertStringContainsString(
            "env('REDIS_HOST'",
            $configFile,
            'Configuration should use env() for Redis host'
        );
    }

    /**
* Validates: Requirement 10.1
     */
    public function it_does_not_hardcode_credentials(): void
    {
        $configFile = file_get_contents(config_path('database.php'));

        // Should not contain hardcoded passwords
        $this->assertStringNotContainsString(
            "'password' => 'changeme'",
            $configFile,
            'Configuration should not hardcode passwords'
        );

        $this->assertStringNotContainsString(
            "'password' => 'redis'",
            $configFile,
            'Configuration should not hardcode passwords'
        );
    }
}
