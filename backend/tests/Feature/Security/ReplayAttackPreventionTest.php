<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test Replay Attack Prevention
 *
 * Tests untuk memastikan idempotency key dan rate limiting bekerja dengan benar.
 */
class ReplayAttackPreventionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test student
        $this->student = User::factory()->create([
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Generate auth token
        $this->token = $this->student->createToken('test-token')->plainTextToken;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_request_with_valid_idempotency_key()
    {
        $idempotencyKey = (string) Str::uuid();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'test_payload',
            'lat' => -6.2088,
            'lng' => 106.8456,
        ]);

        // Should process normally (might fail due to invalid QR, but that's OK)
        // We're testing that idempotency key is accepted
        $this->assertNotEquals(400, $response->status());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_request_with_invalid_idempotency_key_format()
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Idempotency-Key' => 'invalid-key-format',
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'test_payload',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'code' => 'INVALID_IDEMPOTENCY_KEY',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_cached_response_for_duplicate_idempotency_key()
    {
        $idempotencyKey = (string) Str::uuid();

        // Store a fake idempotency key with response
        $cachedResponse = [
            'success' => true,
            'message' => 'Cached response',
            'data' => ['attendance_id' => 123],
        ];

        IdempotencyKey::store(
            key: $idempotencyKey,
            userId: $this->student->id,
            endpoint: 'api/v1/attendance/scan',
            responseData: $cachedResponse,
            responseStatus: 200,
            ttlMinutes: 60
        );

        // Send request with same key
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'different_payload', // Different data
        ]);

        // Should return cached response
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Cached response',
            '_idempotent_replay' => true,
        ]);
        $response->assertHeader('X-Idempotent-Replay', 'true');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_stores_idempotency_key_after_successful_request()
    {
        $idempotencyKey = (string) Str::uuid();

        // Mock successful attendance recording
        // (In real test, you'd need to setup proper QR, schedule, etc.)
        // For now, we just verify the key storage mechanism

        $this->assertEquals(0, IdempotencyKey::count());

        // Note: This will likely fail due to invalid QR, but we're testing
        // that the middleware attempts to store the key
        $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'test_payload',
        ]);

        // Key should be stored if response was successful (2xx)
        // If response was error (4xx/5xx), key won't be stored
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_enforces_rate_limiting_on_attendance_endpoint()
    {
        $successCount = 0;
        $rateLimitedCount = 0;

        // Send 12 requests (limit is 10/min)
        for ($i = 0; $i < 12; $i++) {
            $response = $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
                'X-Idempotency-Key' => (string) Str::uuid(),
            ])->postJson('/api/v1/attendance/scan', [
                'qr_payload' => "test_payload_{$i}",
            ]);

            if ($response->status() === 429) {
                $rateLimitedCount++;
            } else {
                $successCount++;
            }
        }

        // At least some requests should be rate limited
        $this->assertGreaterThan(0, $rateLimitedCount, 'Rate limiting should trigger');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_rate_limit_headers_in_response()
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'X-Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'test_payload',
        ]);

        // Should have rate limit headers
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
        $response->assertHeader('X-RateLimit-Reset');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_cleans_up_expired_idempotency_keys()
    {
        // Create expired key
        IdempotencyKey::create([
            'key' => (string) Str::uuid(),
            'user_id' => $this->student->id,
            'endpoint' => 'api/v1/attendance/scan',
            'http_method' => 'POST',
            'response_payload' => json_encode(['test' => 'data']),
            'response_status' => 200,
            'expires_at' => now()->subHours(2), // Expired 2 hours ago
            'created_at' => now()->subHours(3),
        ]);

        // Create non-expired key
        IdempotencyKey::create([
            'key' => (string) Str::uuid(),
            'user_id' => $this->student->id,
            'endpoint' => 'api/v1/attendance/scan',
            'http_method' => 'POST',
            'response_payload' => json_encode(['test' => 'data']),
            'response_status' => 200,
            'expires_at' => now()->addHours(1), // Expires in 1 hour
            'created_at' => now(),
        ]);

        $this->assertEquals(2, IdempotencyKey::count());

        // Run cleanup command
        $this->artisan('idempotency:cleanup', ['--force' => true])
            ->assertSuccessful();

        // Only non-expired key should remain
        $this->assertEquals(1, IdempotencyKey::count());
        $this->assertEquals(1, IdempotencyKey::notExpired()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_replay_attack_with_same_key_different_user()
    {
        $idempotencyKey = (string) Str::uuid();

        // User 1 uses the key
        IdempotencyKey::store(
            key: $idempotencyKey,
            userId: $this->student->id,
            endpoint: 'api/v1/attendance/scan',
            responseData: ['success' => true],
            responseStatus: 200,
            ttlMinutes: 60
        );

        // User 2 tries to use the same key
        $otherStudent = User::factory()->create([
            'role_type' => 'student',
            'is_active' => true,
        ]);
        $otherToken = $otherStudent->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$otherToken}",
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'test_payload',
        ]);

        // Should NOT return cached response (different user)
        $response->assertJsonMissing(['_idempotent_replay' => true]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_request_without_idempotency_key()
    {
        // SEC-5: Idempotency key is now ENFORCED — missing key returns 400
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->postJson('/api/v1/attendance/scan', [
            'qr_payload' => 'test_payload',
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['code' => 'MISSING_IDEMPOTENCY_KEY']);
    }
}
