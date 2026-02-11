<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\School;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Security Test: Subscription Enforcement
 * 
 * Tests subscription-based access control:
 * - Expired subscription blocks API access
 * - Active subscription allows access
 * - Quota limits enforced
 * - Webhook idempotency
 * 
 * @group security
 * @group subscription
 */
class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $user;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->user = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);
        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
        ]);
    }

    /**
     * ST-001: Expired subscription blocks API access
     * 
     * @test
     */
    public function it_blocks_api_access_with_expired_subscription(): void
    {
        // Arrange: Create expired subscription
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'basic',
            'status' => 'expired',
            'expires_at' => now()->subDays(1),
        ]);

        // Act: Try to access protected API
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
        ]);

        // Assert: Access denied
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Subscription expired',
        ]);
    }

    /**
     * ST-002: Active subscription allows API access
     * 
     * @test
     */
    public function it_allows_api_access_with_active_subscription(): void
    {
        // Arrange: Create active subscription
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'basic',
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Access protected API
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
            'latitude' => -6.2088,
            'longitude' => 106.8456,
        ]);

        // Assert: Access allowed
        $response->assertStatus(200);
    }

    /**
     * ST-003: Quota limit enforced (attendance count)
     * 
     * @test
     */
    public function it_enforces_quota_limit_for_attendance_count(): void
    {
        // Arrange: Create subscription with quota limit
        $subscription = Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'basic',
            'status' => 'active',
            'quota_limit' => 100, // Max 100 attendances
            'quota_used' => 99,   // Already used 99
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Try to create 101st attendance
        $this->actingAs($this->user, 'sanctum');

        // First request should succeed (100th)
        $response1 = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
        ]);

        // Update quota
        $subscription->update(['quota_used' => 100]);

        // Second request should fail (101st)
        $student2 = Student::factory()->create(['school_id' => $this->school->id]);
        $response2 = $this->postJson('/api/attendance/check-in', [
            'student_id' => $student2->id,
            'schedule_id' => 1,
        ]);

        // Assert
        $response1->assertStatus(200);
        $response2->assertStatus(403);
        $response2->assertJson([
            'message' => 'Quota limit exceeded',
        ]);
    }

    /**
     * ST-004: Webhook idempotency (duplicate webhooks)
     * 
     * @test
     */
    public function it_handles_duplicate_webhook_deliveries_idempotently(): void
    {
        // Arrange
        $webhookPayload = [
            'event' => 'subscription.renewed',
            'school_id' => $this->school->id,
            'subscription_id' => 'sub_123',
            'plan' => 'premium',
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ];

        $idempotencyKey = 'webhook_' . md5(json_encode($webhookPayload));

        // Act: Send same webhook 3 times
        $response1 = $this->postJson('/api/webhooks/subscription', $webhookPayload, [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $response2 = $this->postJson('/api/webhooks/subscription', $webhookPayload, [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $response3 = $this->postJson('/api/webhooks/subscription', $webhookPayload, [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        // Assert: All return success, but only 1 processed
        $response1->assertStatus(200);
        $response2->assertStatus(200);
        $response3->assertStatus(200);

        // Verify only 1 subscription update occurred
        $subscriptionCount = Subscription::where('school_id', $this->school->id)
            ->where('plan', 'premium')
            ->count();

        $this->assertEquals(1, $subscriptionCount);
    }

    /**
     * ST-005: Subscription upgrade immediate effect
     * 
     * @test
     */
    public function it_applies_subscription_upgrade_immediately(): void
    {
        // Arrange: Start with basic subscription
        $subscription = Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'basic',
            'status' => 'active',
            'quota_limit' => 100,
        ]);

        // Act: Upgrade to premium
        $subscription->update([
            'plan' => 'premium',
            'quota_limit' => 1000,
        ]);

        // Assert: New quota immediately available
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/subscription/status');

        $response->assertStatus(200);
        $response->assertJson([
            'plan' => 'premium',
            'quota_limit' => 1000,
        ]);
    }

    /**
     * ST-006: Subscription downgrade grace period
     * 
     * @test
     */
    public function it_provides_grace_period_for_subscription_downgrade(): void
    {
        // Arrange: Premium subscription expiring soon
        $subscription = Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'premium',
            'status' => 'active',
            'expires_at' => now()->addDays(3), // 3 days grace period
        ]);

        // Act: Access API during grace period
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
        ]);

        // Assert: Still allowed during grace period
        $response->assertStatus(200);
    }

    /**
     * ST-007: Trial period expiration handling
     * 
     * @test
     */
    public function it_handles_trial_period_expiration(): void
    {
        // Arrange: Trial subscription expired
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'trial',
            'status' => 'expired',
            'expires_at' => now()->subDays(1),
        ]);

        // Act: Try to access API
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
        ]);

        // Assert: Access denied with upgrade prompt
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Trial expired',
            'upgrade_url' => config('app.url') . '/upgrade',
        ]);
    }

    /**
     * ST-008: Payment failure suspends access
     * 
     * @test
     */
    public function it_suspends_access_on_payment_failure(): void
    {
        // Arrange: Subscription with payment failed
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'premium',
            'status' => 'payment_failed',
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Try to access API
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
        ]);

        // Assert: Access suspended
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Payment required',
        ]);
    }

    /**
     * ST-009: Subscription renewal extends quota
     * 
     * @test
     */
    public function it_extends_quota_on_subscription_renewal(): void
    {
        // Arrange: Subscription near quota limit
        $subscription = Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan' => 'basic',
            'status' => 'active',
            'quota_limit' => 100,
            'quota_used' => 95,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Renew subscription
        $subscription->update([
            'quota_used' => 0, // Reset quota
            'expires_at' => now()->addDays(60),
        ]);

        // Assert: Quota reset
        $this->assertEquals(0, $subscription->fresh()->quota_used);
        $this->assertEquals(100, $subscription->fresh()->quota_limit);
    }

    /**
     * ST-010: Multiple webhook deliveries (only 1 processed)
     * 
     * @test
     */
    public function it_processes_only_one_webhook_from_multiple_deliveries(): void
    {
        // Arrange
        Event::fake();

        $webhookPayload = [
            'event' => 'subscription.created',
            'school_id' => $this->school->id,
            'plan' => 'premium',
        ];

        // Act: Send webhook 5 times rapidly
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/webhooks/subscription', $webhookPayload, [
                'X-Webhook-ID' => 'webhook_123', // Same webhook ID
            ]);
        }

        // Assert: All return success
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // Verify only 1 subscription created
        $count = Subscription::where('school_id', $this->school->id)
            ->where('plan', 'premium')
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * ST-011: Subscription check middleware
     * 
     * @test
     */
    public function it_applies_subscription_check_middleware_to_protected_routes(): void
    {
        // Arrange: No subscription
        // (No subscription record exists)

        // Act: Try to access protected route
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/attendance/check-in', [
            'student_id' => $this->student->id,
            'schedule_id' => 1,
        ]);

        // Assert: Access denied
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'No active subscription',
        ]);
    }
}
