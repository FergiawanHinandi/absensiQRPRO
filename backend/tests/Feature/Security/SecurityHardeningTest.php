<?php

namespace Tests\Feature\Security;

use App\Models\IdempotencyKey;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security Hardening Tests
 *
 * Tests for:
 * 1. Timing Attack Prevention (Login)
 * 2. Replay Attack Prevention (Idempotency)
 * 3. Brute Force Protection (Rate Limiting)
 *
 * @group security
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $validUser;
    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::factory()->create(['is_active' => true]);

        // Create valid user
        $this->validUser = User::factory()->create([
            'school_id' => $this->school->id,
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('ValidPassword123!'),
            'is_active' => true,
            'role_type' => 'teacher',
        ]);
    }

    protected function tearDown(): void
    {
        // Clear rate limiter
        RateLimiter::clear('testuser|127.0.0.1');
        RateLimiter::clear('test@example.com|127.0.0.1');
        RateLimiter::clear('nonexistent@example.com|127.0.0.1');
        
        parent::tearDown();
    }

    // ========================================
    // TIMING ATTACK PREVENTION TESTS
    // ========================================

    /**
     * @test
     * @group timing-attack
     */
    public function login_response_time_is_consistent_for_valid_and_invalid_users(): void
    {
        // Test with valid user + wrong password
        $startValid = hrtime(true);
        $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'WrongPassword123!',
        ]);
        $timeValidUser = (hrtime(true) - $startValid) / 1e6; // ms

        // Clear rate limiter to avoid interference
        RateLimiter::clear('testuser|127.0.0.1');

        // Test with invalid user
        $startInvalid = hrtime(true);
        $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent@example.com',
            'password' => 'AnyPassword123!',
        ]);
        $timeInvalidUser = (hrtime(true) - $startInvalid) / 1e6; // ms

        // Both should take at least 100ms (minimum enforced response time)
        $this->assertGreaterThanOrEqual(100, $timeValidUser, 'Valid user request should take at least 100ms');
        $this->assertGreaterThanOrEqual(100, $timeInvalidUser, 'Invalid user request should take at least 100ms');

        // Time difference should be less than 50ms (accounting for jitter)
        $timeDifference = abs($timeValidUser - $timeInvalidUser);
        $this->assertLessThan(
            100, // Allow 100ms variance due to jitter
            $timeDifference,
            "Response times should be similar. Valid: {$timeValidUser}ms, Invalid: {$timeInvalidUser}ms"
        );
    }

    /**
     * @test
     * @group timing-attack
     */
    public function login_returns_generic_error_message_for_both_invalid_user_and_password(): void
    {
        // Wrong password for valid user
        $responseWrongPassword = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'WrongPassword123!',
        ]);

        // Clear rate limiter
        RateLimiter::clear('testuser|127.0.0.1');

        // Non-existent user
        $responseInvalidUser = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent@example.com',
            'password' => 'AnyPassword123!',
        ]);

        // Both should return 422 with same generic message
        $responseWrongPassword->assertStatus(422);
        $responseInvalidUser->assertStatus(422);

        // Messages should be identical (no user enumeration)
        $messageWrongPassword = $responseWrongPassword->json('errors.email.0');
        $messageInvalidUser = $responseInvalidUser->json('errors.email.0');

        $this->assertEquals($messageWrongPassword, $messageInvalidUser);
        $this->assertStringContainsString('tidak valid', $messageWrongPassword);
    }

    // ========================================
    // BRUTE FORCE PROTECTION TESTS
    // ========================================

    /**
     * @test
     * @group brute-force
     */
    public function login_is_rate_limited_after_5_failed_attempts(): void
    {
        // Make 5 failed login attempts
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'WrongPassword' . $i,
            ]);
            
            $response->assertStatus(422);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'AnyPassword',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Terlalu banyak percobaan',
            $response->json('errors.email.0')
        );
    }

    /**
     * @test
     * @group brute-force
     */
    public function account_is_locked_after_10_failed_attempts(): void
    {
        // Make 10 failed login attempts (need to bypass rate limiter)
        for ($i = 1; $i <= 10; $i++) {
            // Clear rate limiter between attempts to test account lockout
            RateLimiter::clear('testuser|127.0.0.1');
            
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'WrongPassword' . $i,
            ]);
        }

        // Clear rate limiter again
        RateLimiter::clear('testuser|127.0.0.1');

        // Next attempt should show account locked message
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'dikunci',
            $response->json('errors.email.0')
        );
    }

    /**
     * @test
     * @group brute-force
     */
    public function successful_login_clears_failed_attempts(): void
    {
        // Make some failed attempts
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'testuser',
                'password' => 'WrongPassword' . $i,
            ]);
        }

        // Successful login
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(200);

        // Verify failed_login_attempts is cleared
        $this->validUser->refresh();
        $this->assertEquals(0, $this->validUser->failed_login_attempts);
    }

    // ========================================
    // REPLAY ATTACK PREVENTION TESTS
    // ========================================

    /**
     * @test
     * @group replay-attack
     */
    public function attendance_endpoint_requires_idempotency_key(): void
    {
        $this->actingAs($this->validUser, 'sanctum');

        // Request without X-Idempotency-Key header
        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-token',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'code' => 'MISSING_IDEMPOTENCY_KEY',
        ]);
    }

    /**
     * @test
     * @group replay-attack
     */
    public function attendance_endpoint_validates_uuid_format(): void
    {
        $this->actingAs($this->validUser, 'sanctum');

        // Request with invalid idempotency key format
        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-token',
        ], [
            'X-Idempotency-Key' => 'invalid-key-format',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'code' => 'INVALID_IDEMPOTENCY_KEY',
        ]);
    }

    /**
     * @test
     * @group replay-attack
     */
    public function duplicate_request_returns_409_conflict(): void
    {
        $this->actingAs($this->validUser, 'sanctum');

        $idempotencyKey = (string) Str::uuid();

        // Create existing idempotency key
        IdempotencyKey::create([
            'key' => $idempotencyKey,
            'user_id' => $this->validUser->id,
            'endpoint' => 'api/v1/attendance/scan',
            'http_method' => 'POST',
            'response_payload' => json_encode(['success' => true]),
            'response_status' => 200,
            'expires_at' => now()->addMinutes(2),
        ]);

        // Try to submit with same key
        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-token',
        ], [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'code' => 'DUPLICATE_SUBMISSION',
        ]);
        $response->assertHeader('X-Duplicate-Request', 'true');
    }

    /**
     * @test
     * @group replay-attack
     */
    public function expired_idempotency_key_allows_new_request(): void
    {
        $this->actingAs($this->validUser, 'sanctum');

        $idempotencyKey = (string) Str::uuid();

        // Create expired idempotency key
        IdempotencyKey::create([
            'key' => $idempotencyKey,
            'user_id' => $this->validUser->id,
            'endpoint' => 'api/v1/attendance/scan',
            'http_method' => 'POST',
            'response_payload' => json_encode(['success' => true]),
            'response_status' => 200,
            'expires_at' => now()->subMinutes(1), // Expired
        ]);

        // Request with expired key should be processed (not 409)
        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-token',
        ], [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        // Should not be 409 (expired key doesn't block)
        $this->assertNotEquals(409, $response->status());
    }

    /**
     * @test
     * @group replay-attack
     */
    public function idempotency_key_is_scoped_to_user(): void
    {
        $idempotencyKey = (string) Str::uuid();

        // Create key for first user
        IdempotencyKey::create([
            'key' => $idempotencyKey,
            'user_id' => $this->validUser->id,
            'endpoint' => 'api/v1/attendance/scan',
            'http_method' => 'POST',
            'response_payload' => json_encode(['success' => true]),
            'response_status' => 200,
            'expires_at' => now()->addMinutes(2),
        ]);

        // Create another user
        $otherUser = User::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
            'role_type' => 'teacher',
        ]);

        $this->actingAs($otherUser, 'sanctum');

        // Same key but different user should NOT be duplicate
        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-token',
        ], [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        // Should not be 409 for different user
        $this->assertNotEquals(409, $response->status());
    }

    // ========================================
    // REQUEST ID LOGGING TESTS
    // ========================================

    /**
     * @test
     * @group logging
     */
    public function response_includes_request_id_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Request-ID');
        
        $requestId = $response->headers->get('X-Request-ID');
        $this->assertNotEmpty($requestId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}-\d{4}$/', $requestId);
    }

    /**
     * @test
     * @group logging
     */
    public function error_response_includes_request_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent',
            'password' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertHeader('X-Request-ID');
        
        // Check request_id is in response body for API errors
        $this->assertArrayHasKey('request_id', $response->json());
    }

    /**
     * @test
     * @group logging
     */
    public function exception_response_includes_request_id(): void
    {
        // Try to access protected endpoint without auth
        $response = $this->getJson('/api/v1/profile');

        $response->assertStatus(401);
        $response->assertHeader('X-Request-ID');
    }
}
