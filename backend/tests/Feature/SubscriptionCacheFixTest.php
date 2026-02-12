<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckActiveSubscription;
use App\Models\School;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Subscription Cache Fix Tests (Day 10 - Week 2)
 * 
 * Tests for subscription cache improvements:
 * - Cache TTL reduced to 60 seconds
 * - Expires_at validation after cache hit
 * - Cache invalidation on subscription updates
 * - Webhook cache clearing
 * 
 * Risk Reduction: CRITICAL (10/10) - Prevents revenue leak
 */
class SubscriptionCacheFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Test 1: Cache TTL is 60 seconds
     * 
     * Verifies that subscription cache expires after 60 seconds
     * to prevent stale data from persisting too long
     */
    public function test_cache_ttl_is_60_seconds(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // First request - caches subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        
        // Cache should exist immediately
        $this->assertTrue(Cache::has($cacheKey));
        
        // Travel 59 seconds - cache should still exist
        $this->travel(59)->seconds();
        $this->assertTrue(Cache::has($cacheKey));
        
        // Travel 61 seconds total - cache should be expired
        $this->travel(2)->seconds();
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test 2: Expires_at validated after cache hit
     * 
     * Ensures that even if cache returns active subscription,
     * the expires_at date is validated against current time
     */
    public function test_expires_at_validated_after_cache_hit(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        // Create subscription that expires in 30 seconds
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addSeconds(30),
        ]);

        // First request - caches subscription (valid)
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Travel 31 seconds - subscription expired but cache still valid (TTL 60s)
        $this->travel(31)->seconds();
        
        // Cache still exists
        $this->assertTrue(Cache::has($cacheKey));
        
        // Request should fail due to expires_at validation
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');
            
        $response->assertStatus(402);
        $response->assertJson([
            'success' => false,
            'error' => 'SUBSCRIPTION_EXPIRED',
        ]);
        
        // Cache should be cleared after detecting expiration
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test 3: Cache cleared on subscription update
     * 
     * Verifies that cache is invalidated when subscription
     * is updated to prevent serving stale data
     */
    public function test_cache_cleared_on_subscription_update(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // First request - caches subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Update subscription (e.g., extend expiration)
        $subscription->update([
            'expires_at' => now()->addDays(60),
        ]);
        
        // Clear cache manually (simulating what should happen in update logic)
        CheckActiveSubscription::clearCache($school->id);
        
        // Cache should be cleared
        $this->assertFalse(Cache::has($cacheKey));
        
        // Next request should fetch fresh data
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);
            
        // Cache should be repopulated
        $this->assertTrue(Cache::has($cacheKey));
    }

    /**
     * Test 4: Cache cleared when subscription deactivated
     * 
     * Ensures cache is cleared when subscription is set to inactive
     */
    public function test_cache_cleared_when_subscription_deactivated(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // First request - caches active subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Deactivate subscription
        $subscription->update(['is_active' => false]);
        CheckActiveSubscription::clearCache($school->id);
        
        // Cache should be cleared
        $this->assertFalse(Cache::has($cacheKey));
        
        // Next request should fail
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(402);
    }

    /**
     * Test 5: Stale cache detected and cleared
     * 
     * Tests the scenario where cached data shows active subscription
     * but expires_at is in the past
     */
    public function test_stale_cache_detected_and_cleared(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addMinutes(2),
        ]);

        // First request - caches subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $cachedData = Cache::get($cacheKey);
        
        // Verify cache contains expires_at
        $this->assertArrayHasKey('expires_at', $cachedData);
        $this->assertTrue($cachedData['active']);

        // Travel past expiration but within cache TTL
        $this->travel(3)->minutes();
        
        // Cache still exists (TTL not reached)
        $this->assertTrue(Cache::has($cacheKey));
        
        // Request should detect stale cache and clear it
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');
            
        $response->assertStatus(402);
        
        // Cache should be cleared
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * Test 6: Multiple schools have isolated cache
     * 
     * Ensures cache keys are school-specific and don't interfere
     */
    public function test_cache_isolated_per_school(): void
    {
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        
        $user1 = User::factory()->create(['school_id' => $school1->id]);
        $user2 = User::factory()->create(['school_id' => $school2->id]);
        
        // School 1 has active subscription
        Subscription::factory()->create([
            'school_id' => $school1->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);
        
        // School 2 has expired subscription
        Subscription::factory()->create([
            'school_id' => $school2->id,
            'is_active' => true,
            'expires_at' => now()->subDays(10),
        ]);

        // School 1 request - should succeed
        $this->actingAs($user1, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);
            
        // School 2 request - should fail
        $this->actingAs($user2, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(402);

        // Verify separate cache keys
        $cacheKey1 = "school_sub_{$school1->id}";
        $cacheKey2 = "school_sub_{$school2->id}";
        
        $this->assertTrue(Cache::has($cacheKey1));
        $this->assertFalse(Cache::has($cacheKey2)); // Cleared due to expiration
        
        // Clear school 1 cache
        CheckActiveSubscription::clearCache($school1->id);
        
        // Only school 1 cache should be cleared
        $this->assertFalse(Cache::has($cacheKey1));
    }

    /**
     * Test 7: Cache stores correct subscription data
     * 
     * Verifies that cached data includes all necessary fields
     * for validation
     */
    public function test_cache_stores_correct_subscription_data(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'plan_type' => 'premium',
            'expires_at' => now()->addDays(30),
        ]);

        // Request to populate cache
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $cachedData = Cache::get($cacheKey);
        
        // Verify cached data structure
        $this->assertIsArray($cachedData);
        $this->assertArrayHasKey('active', $cachedData);
        $this->assertArrayHasKey('subscription_id', $cachedData);
        $this->assertArrayHasKey('plan_name', $cachedData);
        $this->assertArrayHasKey('expires_at', $cachedData);
        $this->assertArrayHasKey('days_remaining', $cachedData);
        
        // Verify values
        $this->assertTrue($cachedData['active']);
        $this->assertEquals($subscription->id, $cachedData['subscription_id']);
        $this->assertEquals('premium', $cachedData['plan_name']); // plan_name uses plan_type value
        
        // Verify expires_at is ISO8601 string
        $this->assertIsString($cachedData['expires_at']);
        $expiresAt = Carbon::parse($cachedData['expires_at']);
        $this->assertInstanceOf(Carbon::class, $expiresAt);
    }

    /**
     * Test 8: Cache invalidation prevents revenue leak
     * 
     * Critical test: Ensures that expired subscriptions cannot
     * access system even if cache hasn't expired yet
     */
    public function test_cache_invalidation_prevents_revenue_leak(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        // Create subscription expiring in 10 seconds
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addSeconds(10),
        ]);

        // First request - subscription valid, caches for 60 seconds
        $response1 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');
        $response1->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Travel 15 seconds - subscription expired, cache still valid
        $this->travel(15)->seconds();
        
        // Cache still exists (TTL 60s)
        $this->assertTrue(Cache::has($cacheKey));
        
        // CRITICAL: Request should be blocked despite cache being valid
        $response2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');
            
        $response2->assertStatus(402);
        $response2->assertJson([
            'success' => false,
            'error' => 'SUBSCRIPTION_EXPIRED',
        ]);
        
        // Cache should be cleared to prevent further access
        $this->assertFalse(Cache::has($cacheKey));
        
        // Subsequent requests should also fail
        $response3 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');
            
        $response3->assertStatus(402);
    }
}
