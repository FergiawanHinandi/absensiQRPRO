<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CacheFailoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear all caches before each test
        Cache::flush();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_store_is_configured_correctly(): void
    {
        $stores = Config::get('cache.stores.failover.stores');
        
        $this->assertIsArray($stores);
        $this->assertCount(3, $stores);
        $this->assertEquals('redis', $stores[0]);
        $this->assertEquals('database', $stores[1]);
        $this->assertEquals('array', $stores[2]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_can_store_and_retrieve_values(): void
    {
        Cache::store('failover')->put('test_key', 'test_value', 60);
        
        $value = Cache::store('failover')->get('test_key');
        
        $this->assertEquals('test_value', $value);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_can_store_complex_data(): void
    {
        $data = [
            'school_id' => 1,
            'name' => 'Test School',
            'settings' => [
                'timezone' => 'Asia/Jakarta',
                'locale' => 'id',
            ],
        ];
        
        Cache::store('failover')->put('school_data', $data, 60);
        
        $retrieved = Cache::store('failover')->get('school_data');
        
        $this->assertEquals($data, $retrieved);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_respects_ttl(): void
    {
        Cache::store('failover')->put('expiring_key', 'value', 1);
        
        $this->assertEquals('value', Cache::store('failover')->get('expiring_key'));
        
        // Wait for expiration
        sleep(2);
        
        $this->assertNull(Cache::store('failover')->get('expiring_key'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_can_forget_keys(): void
    {
        Cache::store('failover')->put('key_to_forget', 'value', 60);
        
        $this->assertEquals('value', Cache::store('failover')->get('key_to_forget'));
        
        Cache::store('failover')->forget('key_to_forget');
        
        $this->assertNull(Cache::store('failover')->get('key_to_forget'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_can_flush_all_keys(): void
    {
        Cache::store('failover')->put('key1', 'value1', 60);
        Cache::store('failover')->put('key2', 'value2', 60);
        
        Cache::store('failover')->flush();
        
        $this->assertNull(Cache::store('failover')->get('key1'));
        $this->assertNull(Cache::store('failover')->get('key2'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function failover_cache_remember_works_correctly(): void
    {
        $callCount = 0;
        
        $value = Cache::store('failover')->remember('remember_key', 60, function () use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        $this->assertEquals('computed_value', $value);
        $this->assertEquals(1, $callCount);
        
        // Second call should use cached value
        $value = Cache::store('failover')->remember('remember_key', 60, function () use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        $this->assertEquals('computed_value', $value);
        $this->assertEquals(1, $callCount); // Should not increment
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function database_cache_works_as_fallback(): void
    {
        // Use database cache directly
        Cache::store('database')->put('db_key', 'db_value', 60);
        
        $value = Cache::store('database')->get('db_key');
        
        $this->assertEquals('db_value', $value);
        
        // Verify it's stored in database (check if cache table exists first)
        if (Schema::hasTable('cache')) {
            $this->assertDatabaseHas('cache', [
                'key' => Config::get('cache.prefix') . 'db_key',
            ]);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function array_cache_works_as_final_fallback(): void
    {
        // Use array cache directly
        Cache::store('array')->put('array_key', 'array_value', 60);
        
        $value = Cache::store('array')->get('array_key');
        
        $this->assertEquals('array_value', $value);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cache_prefix_is_applied_correctly(): void
    {
        $prefix = Config::get('cache.prefix');
        
        $this->assertNotEmpty($prefix);
        $this->assertStringContainsString('cache', $prefix);
    }

    /**
     * Test that verifies failover behavior when Redis is unavailable.
     * Note: This test requires Redis to be stopped manually to verify failover.
     * In CI/CD, this can be automated by stopping the Redis service.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\Group('manual')]
    public function failover_cache_falls_back_when_redis_unavailable(): void
    {
        // This test is marked as manual because it requires Redis to be stopped
        // To run: php artisan test --group=manual
        
        try {
            // Try to connect to Redis
            Redis::connection()->ping();
            $this->markTestSkipped('Redis is available. Stop Redis to test failover.');
        } catch (\Exception $e) {
            // Redis is down, test failover
            Cache::store('failover')->put('failover_test', 'works', 60);
            $value = Cache::store('failover')->get('failover_test');
            
            $this->assertEquals('works', $value);
            
            // Verify it's in database (fallback) if cache table exists
            if (Schema::hasTable('cache')) {
                $this->assertDatabaseHas('cache', [
                    'key' => Config::get('cache.prefix') . 'failover_test',
                ]);
            }
        }
    }
}
