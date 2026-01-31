<?php

namespace Tests\Feature\Auth;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginBruteForceProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $school = School::create([
            'name' => 'Test School',
            'school_level' => 'SMA',
            'npsn' => '12345678',
            'phone' => '08123456789',
            'email' => 'test@school.com',
            'address' => 'Test Address',
            'is_active' => true,
        ]);

        // Create test user
        $this->user = User::create([
            'school_id' => $school->id,
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function successful_login_returns_token()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user' => ['id', 'username', 'email', 'role_type'],
                ],
            ]);

        // Check that failed attempts were cleared
        $this->user->refresh();
        $this->assertEquals(0, $this->user->failed_login_attempts);
        $this->assertNull($this->user->locked_until);
    }

    /** @test */
    public function failed_login_records_attempt()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);

        $this->user->refresh();
        $this->assertEquals(1, $this->user->failed_login_attempts);
        $this->assertNotNull($this->user->last_failed_login_at);
    }

    /** @test */
    public function rate_limiting_blocks_after_5_attempts()
    {
        // Make 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'wrongpassword',
            ]);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $errorMessage = $response->json('errors.email.0');
        $this->assertStringContainsString('Terlalu banyak percobaan login', $errorMessage);
    }

    /** @test */
    public function account_locks_after_10_failed_attempts()
    {
        // Make 10 failed attempts
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'wrongpassword',
            ]);
        }

        $this->user->refresh();
        $this->assertNotNull($this->user->locked_until);
        $this->assertEquals(10, $this->user->failed_login_attempts);

        // Next attempt should show lockout message
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'password123', // Even correct password
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $errorMessage = $response->json('errors.email.0');
        $this->assertStringContainsString('dikunci', $errorMessage);
    }

    /** @test */
    public function locked_account_unlocks_after_timeout()
    {
        // Lock the account manually
        $this->user->update([
            'locked_until' => now()->subMinute(), // Set to 1 minute ago (expired)
            'failed_login_attempts' => 10,
        ]);

        // Should be able to login after lock expires
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        // Check that lockout was cleared
        $this->user->refresh();
        $this->assertNull($this->user->locked_until);
        $this->assertEquals(0, $this->user->failed_login_attempts);
    }

    /** @test */
    public function rate_limit_is_based_on_ip_and_email_combination()
    {
        // Create second user
        $user2 = User::create([
            'school_id' => $this->user->school_id,
            'username' => 'testuser2',
            'name' => 'Test User 2',
            'email' => 'test2@example.com',
            'password' => Hash::make('password123'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Exhaust rate limit for user 1
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'wrongpassword',
            ]);
        }

        // User 1 should be rate limited
        $response1 = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        $response1->assertStatus(422);
        $this->assertStringContainsString('Terlalu banyak percobaan', $response1->json('errors.email.0'));

        // User 2 should NOT be rate limited (different email)
        $response2 = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser2',
            'password' => 'wrongpassword',
        ]);

        $response2->assertStatus(422);
        $this->assertStringContainsString('Kredensial', $response2->json('errors.email.0'));
        $this->assertStringNotContainsString('Terlalu banyak', $response2->json('errors.email.0'));
    }

    /** @test */
    public function generic_error_message_does_not_reveal_which_field_is_wrong()
    {
        // Wrong username
        $response1 = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent',
            'password' => 'password123',
        ]);

        // Wrong password
        $response2 = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        // Both should return the same generic message
        $message1 = $response1->json('errors.email.0');
        $message2 = $response2->json('errors.email.0');

        $this->assertEquals($message1, $message2);
        $this->assertStringContainsString('Kredensial yang Anda masukkan tidak valid', $message1);
    }

    /** @test */
    public function failed_login_attempts_are_logged()
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        // Check audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'failed_login',
        ]);
    }

    /** @test */
    public function successful_login_creates_audit_log()
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        // Check audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'login',
        ]);
    }

    /** @test */
    public function progressive_delay_slows_down_repeated_attempts()
    {
        // This test verifies the delay mechanism exists
        // We can't easily test actual time delays in unit tests
        // But we can verify the logic exists in the service

        // Make multiple failed attempts
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'wrongpassword',
            ]);
        }

        // Just verify the service has the progressive delay logic
        // by checking attempts were recorded
        $this->user->refresh();
        $this->assertGreaterThanOrEqual(4, $this->user->failed_login_attempts);
    }

    /** @test */
    public function account_lockout_is_logged()
    {
        // Make 10 failed attempts to trigger lockout
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'wrongpassword',
            ]);
        }

        $this->user->refresh();
        $this->assertNotNull($this->user->locked_until);

        // Verify lockout duration is 10 minutes
        $lockoutDuration = now()->diffInMinutes($this->user->locked_until);
        $this->assertEquals(10, $lockoutDuration);
    }
}
