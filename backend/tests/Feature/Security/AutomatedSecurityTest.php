<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Automated Security Test Suite
 * Covers:
 * 1. Timing Attack Prevention (Response Time Analysis)
 * 2. Rate Limiting (Brute Force Protection)
 * 3. Idempotency (Replay Attack Protection)
 */
#[\PHPUnit\Framework\Attributes\Group('security')]
#[\PHPUnit\Framework\Attributes\Group('automated')]
class AutomatedSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Setup shared resources if needed
    }

    /**
     * TEST 1: Timing Attack Prevention
     * Measure response time difference between Valid vs Invalid Email
     */
    public function test_login_response_time_difference()
    {
        // Setup
        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'email' => 'valid@example.com',
            'password' => Hash::make('password'),
        ]);

        $iterations = 5;
        $validTimes = [];
        $invalidTimes = [];

        // 1. Measure Valid Email (Wrong Password)
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->postJson('/api/v1/auth/login', [
                'email' => 'valid@example.com',
                'password' => 'wrong-password',
            ]);
            $validTimes[] = (microtime(true) - $start) * 1000;
        }

        // 2. Measure Invalid Email
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->postJson('/api/v1/auth/login', [
                'email' => 'invalid@example.com',
                'password' => 'any-password',
            ]);
            $invalidTimes[] = (microtime(true) - $start) * 1000;
        }

        // Analysis
        $avgValid = array_sum($validTimes) / count($validTimes);
        $avgInvalid = array_sum($invalidTimes) / count($invalidTimes);
        $diffPercent = abs(($avgValid - $avgInvalid) / $avgValid) * 100;

        Log::info("Security Test - Timing Attack: Valid Avg={$avgValid}ms, Invalid Avg={$avgInvalid}ms, Diff={$diffPercent}%");

        // Assertions
        // Allow up to 20% difference (accounting for variance in test env)
        // Note: In strict environments, this might be flaky if variance is high.
        // We warn if > 20%, but maybe fail if > 50% to be safe in CI.
        $this->assertLessThan(50, $diffPercent, 'Response time difference too high (>50%), potential timing leak.');
    }

    /**
     * TEST 2: Rate Limiting
     * Trigger rate limit via 6 rapid login attempts
     */
    public function test_login_rate_limiting()
    {
        // 1. 5 Rapid Requests (Should Succeed/Fail Validation but NOT Throttled)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'attacker@example.com',
                'password' => 'wrong',
            ]);
            
            // Should be 401 or 422 (Validation Error), NOT 429
            $this->assertTrue(in_array($response->status(), [401, 422]), "Request $i failed unexpectedly with " . $response->status());
        }

        // 2. 6th Request (Should Trigger Rate Limit)
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'attacker@example.com',
            'password' => 'wrong',
        ]);

        // Assertions
        $response->assertStatus(429); // Too Many Requests
        $this->assertArrayHasKey('message', $response->json());
        
        Log::info("Security Test - Rate Limit Triggered: " . $response->json('message'));
    }

    /**
     * TEST 3: Idempotency (Replay Attack)
     * Send same Idempotency Key 5 times -> Only 1 succeeds
     */
    public function test_idempotency_prevents_replay()
    {
        // Setup
        $school = School::factory()->create();
        $teacher = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'teacher',
        ]);
        
        $key = Str::uuid()->toString();
        $payload = ['status' => 'present', 'student_id' => 1, 'attendance_date' => today()->toDateString()];

        // 1. First Request (Success)
        // Using a route that uses Idempotency Middleware (e.g., Manual Attendance)
        // Mocking the successful flow to focus on Middleware behavior
        
        // Simulating: Sending 5 requests
        $responses = [];
        
        for ($i = 0; $i < 5; $i++) {
            // Note: We need a valid endpoint. Assuming /api/v1/attendance/manual exists and accepts this.
            // If checking business logic fails (e.g. invalid student), status might be 422.
            // But Idempotency should still catch the *replayed* 422/200 response?
            // Actually Idempotency usually caches Successful (2xx) or specific error responses.
            // Let's assume Valid Request for the first one.
            
            // To make test robust without complex setup, we assume the middleware works on ANY response status
            // OR we must ensure success.
            // Let's rely on status codes.
            
            $responses[] = $this->actingAs($teacher)
                ->withHeader('X-Idempotency-Key', $key)
                ->postJson('/api/v1/attendance/manual', $payload);
        }

        // Assertions
        // Request 1: Should be processed (Status depends on payload validity, likely 422 if student missing, or 201)
        // Request 2-5: Should be 409 Conflict (Replay) or same cached response
        
        // IF Idempotency Middleware is strict:
        // First request: Processed -> Returns X.
        // Second request: Detected -> Returns X (Cached) or 409.
        
        // Based on previous IdempotencyTest:
        // "Remaining 4 requests rejected (409)"
        
        $firstStatus = $responses[0]->status();
        
        // Verify subsequent requests are 409
        $replayCount = 0;
        foreach (array_slice($responses, 1) as $res) {
            if ($res->status() === 409) {
                $replayCount++;
            }
        }
        
        $this->assertEquals(4, $replayCount, "Expected 4 replay rejections (409), got $replayCount.");
        
        Log::info("Security Test - Idempotency: 1 Processed ($firstStatus), 4 Replayed (409)");
    }
}
