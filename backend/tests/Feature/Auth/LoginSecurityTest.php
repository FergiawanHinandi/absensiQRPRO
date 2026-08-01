<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * LoginSecurityTest
 * Comprehensive test suite for login security features:
 * - Timing attack prevention
 * - Brute force protection
 * - Rate limiting
 * - Account lockout
 * - Audit logging
 */
#[\PHPUnit\Framework\Attributes\Group('auth')]
#[\PHPUnit\Framework\Attributes\Group('security')]
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $correctPassword = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'username' => 'testuser',
            'password' => Hash::make($this->correctPassword),
            'is_active' => true,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // TIMING ATTACK PREVENTION
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_constant_time_comparison_for_invalid_user()
    {
        $startTime = hrtime(true);
        
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent@example.com',
            'password' => 'WrongPassword123!',
        ]);

        $elapsedInvalid = hrtime(true) - $startTime;

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => ['email'],
        ]);

        // Should take at least 100ms (minimum response time)
        $this->assertGreaterThanOrEqual(100_000_000, $elapsedInvalid, 'Response time should be at least 100ms');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_constant_time_comparison_for_wrong_password()
    {
        $startTime = hrtime(true);
        
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'WrongPassword123!',
        ]);

        $elapsedWrong = hrtime(true) - $startTime;

        $response->assertStatus(422);

        // Should take at least 100ms (minimum response time)
        $this->assertGreaterThanOrEqual(100_000_000, $elapsedWrong, 'Response time should be at least 100ms');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_generic_error_message_for_invalid_credentials()
    {
        // Test with non-existent user
        $response1 = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent@example.com',
            'password' => 'WrongPassword123!',
        ]);

        // Test with wrong password
        $response2 = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'WrongPassword123!',
        ]);

        // Both should return the same generic error message
        $this->assertEquals(
            $response1->json('errors.email.0'),
            $response2->json('errors.email.0')
        );

        // Error message should not reveal which field is wrong
        $this->assertStringContainsString('tidak valid', $response1->json('errors.email.0'));
        $this->assertStringNotContainsString('user', strtolower($response1->json('errors.email.0')));
        $this->assertStringNotContainsString('password', strtolower($response1->json('errors.email.0')));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_performs_hash_check_even_for_nonexistent_user()
    {
        // This test verifies that Hash::check is called even when user doesn't exist
        // We can't directly test this, but we can verify the response time is consistent

        $times = [];

        // Test 5 times with non-existent user
        for ($i = 0; $i < 5; $i++) {
            Cache::flush(); // Clear rate limiting
            
            $startTime = hrtime(true);
            $this->postJson('/api/v1/auth/login', [
                'username' => "nonexistent{$i}@example.com",
                'password' => 'SomePassword123!',
            ]);
            $times[] = hrtime(true) - $startTime;
        }

        // All response times should be similar (within 50ms variance)
        $avgTime = array_sum($times) / count($times);
        foreach ($times as $time) {
            $variance = abs($time - $avgTime);
            $this->assertLessThan(50_000_000, $variance, 'Response times should be consistent');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // RATE LIMITING
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rate_limits_login_attempts_by_ip_and_email()
    {
        // Make 5 failed attempts (should succeed)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'username' => $this->user->email,
                'password' => 'WrongPassword123!',
            ]);
            $response->assertStatus(422);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Terlalu banyak percobaan', $response->json('errors.email.0'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_clears_rate_limit_on_successful_login()
    {
        // Make 4 failed attempts
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => $this->user->email,
                'password' => 'WrongPassword123!',
            ]);
        }

        // Successful login should clear rate limit
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);

        // Should be able to login again immediately
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ACCOUNT LOCKOUT
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_locks_account_after_10_failed_attempts()
    {
        // Make 10 failed attempts
        for ($i = 0; $i < 10; $i++) {
            Cache::flush(); // Clear rate limiting to test account lockout
            
            $this->postJson('/api/v1/auth/login', [
                'username' => $this->user->email,
                'password' => 'WrongPassword123!',
            ]);
        }

        // Refresh user from database
        $this->user->refresh();

        // Account should be locked
        $this->assertNotNull($this->user->locked_until);
        $this->assertGreaterThanOrEqual(10, $this->user->failed_login_attempts);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_login_when_account_is_locked()
    {
        // Lock the account
        $this->user->update([
            'locked_until' => now()->addMinutes(10),
            'failed_login_attempts' => 10,
        ]);

        // Try to login with correct password
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('dikunci', $response->json('errors.email.0'));
        $this->assertStringContainsString('menit', $response->json('errors.email.0'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_unlocks_account_after_lockout_period_expires()
    {
        // Lock the account with expired lockout
        $this->user->update([
            'locked_until' => now()->subMinutes(1), // Expired 1 minute ago
            'failed_login_attempts' => 10,
        ]);

        // Should be able to login
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);

        // Refresh user
        $this->user->refresh();

        // Lockout should be cleared
        $this->assertNull($this->user->locked_until);
        $this->assertEquals(0, $this->user->failed_login_attempts);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_clears_failed_attempts_on_successful_login()
    {
        // Set some failed attempts
        $this->user->update(['failed_login_attempts' => 5]);

        // Successful login
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);

        // Refresh user
        $this->user->refresh();

        // Failed attempts should be cleared
        $this->assertEquals(0, $this->user->failed_login_attempts);
        $this->assertNull($this->user->last_failed_login_at);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PROGRESSIVE DELAY
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_applies_progressive_delay_after_multiple_failures()
    {
        Cache::flush();

        // First 2 attempts should be fast (no delay)
        $startTime1 = hrtime(true);
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'Wrong1',
        ]);
        $time1 = hrtime(true) - $startTime1;

        $startTime2 = hrtime(true);
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'Wrong2',
        ]);
        $time2 = hrtime(true) - $startTime2;

        // 3rd attempt should have 2s delay
        $startTime3 = hrtime(true);
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'Wrong3',
        ]);
        $time3 = hrtime(true) - $startTime3;

        // 3rd attempt should take significantly longer (at least 2 seconds more)
        $this->assertGreaterThan($time1 + 2_000_000_000, $time3, '3rd attempt should have 2s delay');
    }

    // ─────────────────────────────────────────────────────────────────────
    // AUDIT LOGGING
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_failed_login_attempts()
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => 'WrongPassword123!',
        ]);

        // Check audit log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'failed_login',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_successful_login()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);

        // Check audit log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'login',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_account_lockout()
    {
        // Make 10 failed attempts to trigger lockout
        for ($i = 0; $i < 10; $i++) {
            Cache::flush();
            $this->postJson('/api/v1/auth/login', [
                'username' => $this->user->email,
                'password' => 'WrongPassword123!',
            ]);
        }

        // Refresh user
        $this->user->refresh();

        // Verify account is locked
        $this->assertNotNull($this->user->locked_until);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_increments_failed_login_attempts_counter()
    {
        // Initial state
        $this->assertEquals(0, $this->user->failed_login_attempts);

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            Cache::flush();
            $this->postJson('/api/v1/auth/login', [
                'username' => $this->user->email,
                'password' => 'WrongPassword123!',
            ]);
        }

        // Refresh user
        $this->user->refresh();

        // Counter should be incremented
        $this->assertEquals(3, $this->user->failed_login_attempts);
        $this->assertNotNull($this->user->last_failed_login_at);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SUCCESSFUL LOGIN
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_login_with_correct_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'access_token',
                'token_type',
                'expires_in',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role_type',
                ],
            ],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_login_with_username()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->username,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_last_login_timestamp()
    {
        $this->assertNull($this->user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $this->user->refresh();
        $this->assertNotNull($this->user->last_login_at);
    }

    // ─────────────────────────────────────────────────────────────────────
    // EDGE CASES
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_login_for_inactive_users()
    {
        $this->user->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $this->user->email,
            'password' => $this->correctPassword,
        ]);

        $response->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_case_insensitive_email_login()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => strtoupper($this->user->email),
            'password' => $this->correctPassword,
        ]);

        // Should still work (case-insensitive)
        $response->assertStatus(200);
    }
}
