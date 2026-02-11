<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Unit tests for LoginRateLimiter
 *
 * @group auth
 * @group rate-limiting
 */
class LoginRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected LoginRateLimiter $rateLimiter;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rateLimiter = new LoginRateLimiter(app(RateLimiter::class));
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'username' => 'testuser',
            'failed_login_attempts' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up rate limiter
        $request = Request::create('/', 'POST');
        $this->rateLimiter->clear($request, 'test@example.com');
        
        parent::tearDown();
    }

    protected function createRequest(): Request
    {
        return Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
    }

    /**
     * @test
     */
    public function initial_attempts_returns_zero(): void
    {
        $request = $this->createRequest();
        
        $attempts = $this->rateLimiter->attempts($request, 'test@example.com');
        
        $this->assertEquals(0, $attempts);
    }

    /**
     * @test
     */
    public function hit_increments_attempt_counter(): void
    {
        $request = $this->createRequest();

        $this->rateLimiter->hit($request, 'test@example.com');

        $this->assertEquals(1, $this->rateLimiter->attempts($request, 'test@example.com'));

        $this->rateLimiter->hit($request, 'test@example.com');
        
        $this->assertEquals(2, $this->rateLimiter->attempts($request, 'test@example.com'));
    }

    /**
     * @test
     */
    public function too_many_attempts_returns_false_under_threshold(): void
    {
        $request = $this->createRequest();

        // Make 4 attempts (threshold is 5)
        for ($i = 0; $i < 4; $i++) {
            $this->rateLimiter->hit($request, 'test@example.com');
        }

        $this->assertFalse($this->rateLimiter->tooManyAttempts($request, 'test@example.com'));
    }

    /**
     * @test
     */
    public function too_many_attempts_returns_true_at_threshold(): void
    {
        $request = $this->createRequest();

        // Make 5 attempts (reaches threshold)
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->hit($request, 'test@example.com');
        }

        $this->assertTrue($this->rateLimiter->tooManyAttempts($request, 'test@example.com'));
    }

    /**
     * @test
     */
    public function clear_resets_attempts(): void
    {
        $request = $this->createRequest();

        // Make some attempts
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->hit($request, 'test@example.com');
        }

        $this->assertEquals(3, $this->rateLimiter->attempts($request, 'test@example.com'));

        // Clear attempts
        $this->rateLimiter->clear($request, 'test@example.com');

        $this->assertEquals(0, $this->rateLimiter->attempts($request, 'test@example.com'));
    }

    /**
     * @test
     */
    public function record_failed_attempt_increments_user_counter(): void
    {
        $this->assertEquals(0, $this->user->failed_login_attempts);

        $this->rateLimiter->recordFailedAttempt($this->user);
        $this->user->refresh();

        $this->assertEquals(1, $this->user->failed_login_attempts);
        $this->assertNotNull($this->user->last_failed_login_at);
    }

    /**
     * @test
     */
    public function should_lock_user_returns_true_at_threshold(): void
    {
        $this->user->update(['failed_login_attempts' => 9]);
        $this->assertFalse($this->rateLimiter->shouldLockUser($this->user));

        $this->user->update(['failed_login_attempts' => 10]);
        $this->assertTrue($this->rateLimiter->shouldLockUser($this->user));
    }

    /**
     * @test
     */
    public function lock_account_sets_locked_until(): void
    {
        $this->assertNull($this->user->locked_until);

        $this->rateLimiter->lockAccount($this->user);
        $this->user->refresh();

        $this->assertNotNull($this->user->locked_until);
        $this->assertTrue($this->user->locked_until->isFuture());
    }

    /**
     * @test
     */
    public function is_account_locked_returns_true_when_locked(): void
    {
        $this->assertFalse($this->rateLimiter->isAccountLocked($this->user));

        $this->user->update(['locked_until' => now()->addMinutes(10)]);

        $this->assertTrue($this->rateLimiter->isAccountLocked($this->user));
    }

    /**
     * @test
     */
    public function is_account_locked_returns_false_when_lock_expired(): void
    {
        $this->user->update(['locked_until' => now()->subMinutes(1)]);

        $this->assertFalse($this->rateLimiter->isAccountLocked($this->user));
        
        // Should also clear the expired lock
        $this->user->refresh();
        $this->assertNull($this->user->locked_until);
    }

    /**
     * @test
     */
    public function clear_account_attempts_resets_user_counters(): void
    {
        $this->user->update([
            'failed_login_attempts' => 5,
            'last_failed_login_at' => now(),
            'locked_until' => now()->addMinutes(10),
        ]);

        $this->rateLimiter->clearAccountAttempts($this->user);
        $this->user->refresh();

        $this->assertEquals(0, $this->user->failed_login_attempts);
        $this->assertNull($this->user->last_failed_login_at);
        $this->assertNull($this->user->locked_until);
    }

    /**
     * @test
     */
    public function lockout_remaining_seconds_returns_correct_value(): void
    {
        $this->user->update(['locked_until' => now()->addMinutes(5)]);

        $remaining = $this->rateLimiter->lockoutRemainingSeconds($this->user);

        // Should be around 300 seconds (5 minutes)
        $this->assertGreaterThan(290, $remaining);
        $this->assertLessThanOrEqual(300, $remaining);
    }

    /**
     * @test
     */
    public function throttle_key_combines_email_and_ip(): void
    {
        $request1 = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $request2 = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '192.168.1.2']);

        // Same email, different IPs should have separate counters
        $this->rateLimiter->hit($request1, 'test@example.com');
        $this->rateLimiter->hit($request1, 'test@example.com');
        
        $this->rateLimiter->hit($request2, 'test@example.com');

        $this->assertEquals(2, $this->rateLimiter->attempts($request1, 'test@example.com'));
        $this->assertEquals(1, $this->rateLimiter->attempts($request2, 'test@example.com'));
    }
}
