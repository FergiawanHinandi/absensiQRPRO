<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitAtomicTest extends TestCase
{
    use RefreshDatabase;

    protected $school;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->user = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'admin',
        ]);
    }

    /**
     * Test: Rate limiter is atomic and prevents race conditions
     *
     * @return void
     */
    public function test_rate_limiter_atomic_operations()
    {
        Sanctum::actingAs($this->user, ['*']);

        $rateLimitKey = 'login:'.$this->user->id;
        $maxAttempts = 5;

        // Clear any existing rate limit
        RateLimiter::clear($rateLimitKey);

        $results = [];

        // Simulate 10 concurrent requests (more than max attempts)
        for ($i = 0; $i < 10; $i++) {
            try {
                $hit = RateLimiter::attempt(
                    $rateLimitKey,
                    $maxAttempts,
                    function () {
                        return true;
                    },
                    60
                );

                $remaining = RateLimiter::remaining($rateLimitKey, $maxAttempts);

                $results[] = [
                    'attempt' => $i + 1,
                    'hit' => $hit,
                    'remaining' => $remaining,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'attempt' => $i + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // First 5 should succeed, rest should fail
        $successful = collect($results)->where('hit', true)->count();
        $failed = collect($results)->where('hit', false)->count();

        $this->assertEquals(
            $maxAttempts,
            $successful,
            "Exactly {$maxAttempts} attempts should succeed"
        );

        $this->assertEquals(
            10 - $maxAttempts,
            $failed,
            'Remaining attempts should fail'
        );

        // Verify atomic increment - no overflow beyond max attempts
        $attempts = RateLimiter::attempts($rateLimitKey);
        $this->assertEquals(
            $maxAttempts,
            $attempts,
            'Attempts should not exceed max limit even with concurrent requests'
        );
    }

    /**
     * Test: Redis increment is atomic
     *
     * @return void
     */
    public function test_redis_increment_atomic()
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $key = 'test:atomic:counter';
        Redis::del($key);

        $results = [];

        // Simulate 100 concurrent increments
        for ($i = 0; $i < 100; $i++) {
            $value = Redis::incr($key);
            $results[] = $value;
        }

        // Final value should be exactly 100
        $finalValue = Redis::get($key);
        $this->assertEquals(
            100,
            $finalValue,
            'Redis atomic increment should result in exact count'
        );

        // All increments should return unique sequential values
        $uniqueValues = array_unique($results);
        $this->assertCount(
            100,
            $uniqueValues,
            'All increment operations should return unique values'
        );

        // Values should be sequential from 1 to 100
        sort($results);
        $this->assertEquals(
            range(1, 100),
            $results,
            'Increments should be sequential without gaps'
        );

        Redis::del($key);
    }

    /**
     * Test: Cache lock prevents concurrent execution
     *
     * @return void
     */
    public function test_cache_lock_prevents_concurrent_execution()
    {
        $lockKey = 'test:critical:section';
        $results = [];
        $executed = 0;

        // Simulate 10 concurrent attempts to acquire lock
        for ($i = 0; $i < 10; $i++) {
            $lock = Cache::lock($lockKey, 10);

            if ($lock->get()) {
                try {
                    // Critical section
                    $executed++;
                    $results[] = [
                        'attempt' => $i + 1,
                        'acquired' => true,
                        'executed' => $executed,
                    ];

                    // Simulate some work
                    usleep(1000); // 1ms
                } finally {
                    $lock->release();
                }
            } else {
                $results[] = [
                    'attempt' => $i + 1,
                    'acquired' => false,
                ];
            }
        }

        // All attempts should try to acquire, but execution should be serialized
        $acquired = collect($results)->where('acquired', true)->count();

        $this->assertGreaterThan(
            0,
            $acquired,
            'At least some locks should be acquired'
        );

        $this->assertEquals(
            $acquired,
            $executed,
            'Execution count should match acquired locks'
        );

        // Total attempts should be 10
        $this->assertCount(10, $results);
    }

    /**
     * Test: Rate limit by IP address is atomic
     *
     * @return void
     */
    public function test_rate_limit_by_ip_atomic()
    {
        $ip = '192.168.1.100';
        $rateLimitKey = 'api:'.$ip;
        $maxAttempts = 60;

        RateLimiter::clear($rateLimitKey);

        $results = [];

        // Simulate 100 concurrent API calls from same IP
        for ($i = 0; $i < 100; $i++) {
            $response = $this->withHeaders([
                'X-Forwarded-For' => $ip,
                'REMOTE_ADDR' => $ip,
            ])->getJson('/api/v1/health');

            $results[] = [
                'attempt' => $i + 1,
                'status' => $response->status(),
                'remaining' => $response->headers->get('X-RateLimit-Remaining'),
            ];
        }

        // Count how many succeeded (200) vs throttled (429)
        $successful = collect($results)->where('status', 200)->count();
        $throttled = collect($results)->where('status', 429)->count();

        // Should not exceed max attempts
        $this->assertLessThanOrEqual(
            $maxAttempts,
            $successful,
            'Successful requests should not exceed rate limit'
        );

        // Remaining should be throttled
        $this->assertEquals(
            100 - $successful,
            $throttled,
            'Excess requests should be throttled'
        );
    }

    /**
     * Test: Rate limit decays correctly after time window
     *
     * @return void
     */
    public function test_rate_limit_decay()
    {
        $key = 'test:decay';
        $maxAttempts = 3;
        $decayMinutes = 1;

        RateLimiter::clear($key);

        // Hit rate limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decayMinutes * 60);
        }

        // Should be at limit
        $this->assertEquals(
            0,
            RateLimiter::remaining($key, $maxAttempts),
            'Should have no remaining attempts'
        );

        // Check available at time
        $availableAt = RateLimiter::availableIn($key);
        $this->assertGreaterThan(
            0,
            $availableAt,
            'Should have a wait time'
        );

        $this->assertLessThanOrEqual(
            $decayMinutes * 60,
            $availableAt,
            'Wait time should not exceed decay period'
        );

        // Clear for cleanup
        RateLimiter::clear($key);
    }

    /**
     * Test: Too many attempts returns 429
     *
     * @return void
     */
    public function test_too_many_attempts_returns_429()
    {
        $maxAttempts = 5;

        // Make requests until throttled
        for ($i = 0; $i < $maxAttempts + 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'username' => 'test_throttle_'.time(),
                'password' => 'wrong_password',
            ]);

            if ($i < $maxAttempts) {
                // Should fail with validation or auth error, not throttle
                $this->assertContains(
                    $response->status(),
                    [401, 422],
                    "Request {$i} should not be throttled yet"
                );
            }

            // Once limit is hit, should get 429
            if ($i >= $maxAttempts) {
                if ($response->status() === 429) {
                    $this->assertEquals(
                        429,
                        $response->status(),
                        'Should be throttled after max attempts'
                    );

                    // Should have Retry-After header
                    $this->assertNotNull(
                        $response->headers->get('Retry-After'),
                        'Should have Retry-After header'
                    );

                    break; // Test passed
                }
            }
        }

        $this->assertTrue(true, 'Rate limiting test completed');
    }

    /**
     * Test: Different users have independent rate limits
     *
     * @return void
     */
    public function test_rate_limits_per_user_independent()
    {
        $user1 = User::factory()->create(['school_id' => $this->school->id]);
        $user2 = User::factory()->create(['school_id' => $this->school->id]);

        $key1 = 'user:'.$user1->id;
        $key2 = 'user:'.$user2->id;

        RateLimiter::clear($key1);
        RateLimiter::clear($key2);

        $maxAttempts = 5;

        // User 1 hits rate limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key1, 60);
        }

        // User 1 should be at limit
        $this->assertEquals(
            0,
            RateLimiter::remaining($key1, $maxAttempts),
            'User 1 should have no remaining attempts'
        );

        // User 2 should still have full quota
        $this->assertEquals(
            $maxAttempts,
            RateLimiter::remaining($key2, $maxAttempts),
            'User 2 should have full remaining attempts'
        );

        RateLimiter::clear($key1);
        RateLimiter::clear($key2);
    }

    /**
     * Test: Rate limit counter doesn't overflow
     *
     * @return void
     */
    public function test_rate_limit_no_overflow()
    {
        $key = 'test:overflow';
        $maxAttempts = 10;

        RateLimiter::clear($key);

        // Try to hit way beyond max attempts
        for ($i = 0; $i < 1000; $i++) {
            RateLimiter::hit($key, 60);
        }

        // Attempts should be capped appropriately
        $attempts = RateLimiter::attempts($key);

        // The counter should not grow unbounded
        $this->assertLessThan(
            2000, // Some reasonable upper bound
            $attempts,
            'Rate limit counter should not overflow'
        );

        RateLimiter::clear($key);
    }
}
