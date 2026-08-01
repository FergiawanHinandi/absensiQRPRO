<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckActiveSubscription;
use App\Models\School;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Subscription Cache Property-Based Tests (Week 2 Day 10)
 * 
 * Property-Based Testing for subscription cache to ensure:
 * - Property 30: Cache TTL is 60 seconds
 * - Property 31: Expires_at validated after cache hit
 * - Property 32: Cache cleared on subscription update
 * - Property 33: Webhook clears subscription cache
 * 
 * These tests validate universal correctness properties that must hold
 * across all subscription cache operations to prevent revenue leakage.
 * 
 * Risk Reduction: CRITICAL (10/10) - Prevents revenue leak
 * 
 * Validates: Requirements Week 2 Day 10.1, 10.2, 10.3, 10.4
 * 
 */
#[\PHPUnit\Framework\Attributes\Group('feature')]
#[\PHPUnit\Framework\Attributes\Group('subscription')]
#[\PHPUnit\Framework\Attributes\Group('cache')]
class SubscriptionCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Log::spy();
        
        // Explicitly register observer to ensure it fires in tests
        Subscription::observe(\App\Observers\SubscriptionObserver::class);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 30: Cache TTL is 60 seconds
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * **Property 30: Cache TTL is 60 seconds**
     * 
     * Universal property: For any school's subscription cache, the TTL MUST be
     * exactly 60 seconds to prevent stale data from persisting too long while
     * maintaining reasonable performance.
     * 
     * PROPERTY: ∀ school_id, cache_key = "school_sub_{school_id}"
     *           → Cache::has(cache_key) at t=0 ⇒ Cache::has(cache_key) at t=59
     *           → Cache::has(cache_key) at t=0 ⇒ ¬Cache::has(cache_key) at t=61
     * 
*/
    public function property_30_cache_ttl_is_60_seconds(): void
    {
        // Arrange: Create school with active subscription
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: First request caches subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        
        // Assert: Cache exists immediately after request
        $this->assertTrue(
            Cache::has($cacheKey),
            'Cache should exist immediately after first request'
        );
        
        // Assert: Cache still exists at t=59 seconds
        $this->travel(59)->seconds();
        $this->assertTrue(
            Cache::has($cacheKey),
            'Cache should still exist at 59 seconds (within TTL)'
        );
        
        // Assert: Cache expired at t=61 seconds
        $this->travel(2)->seconds(); // Total 61 seconds
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be expired at 61 seconds (beyond TTL)'
        );
    }

    /**
     * **Property 30: Cache TTL is consistent across multiple schools**
     * 
     * Universal property: The 60-second TTL applies uniformly to all schools,
     * ensuring consistent cache behavior across the multi-tenant system.
     * 
*/
    public function property_30_cache_ttl_consistent_across_schools(): void
    {
        // Arrange: Create multiple schools
        $schools = School::factory()->count(3)->create();
        $cacheKeys = [];
        
        foreach ($schools as $school) {
            $user = User::factory()->create(['school_id' => $school->id]);
            
            Subscription::factory()->create([
                'school_id' => $school->id,
                'is_active' => true,
                'expires_at' => now()->addDays(30),
            ]);
            
            // Act: Cache each school's subscription
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/student/dashboard')
                ->assertStatus(200);
                
            $cacheKeys[] = "school_sub_{$school->id}";
        }
        
        // Assert: All caches exist
        foreach ($cacheKeys as $key) {
            $this->assertTrue(Cache::has($key));
        }
        
        // Assert: All caches expire at same time
        $this->travel(61)->seconds();
        foreach ($cacheKeys as $key) {
            $this->assertFalse(
                Cache::has($key),
                "Cache for {$key} should expire after 61 seconds"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 31: Expires_at validated after cache hit
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * **Property 31: Expires_at validated after cache hit**
     * 
     * Universal property: For any cached subscription with expires_at field,
     * the middleware MUST validate expires_at against current time even when
     * serving from cache, preventing revenue leakage from stale cache.
     * 
     * PROPERTY: ∀ cached_subscription where cached_subscription.active = true
     *           ∧ cached_subscription.expires_at < now()
     *           → request MUST return 402 Payment Required
     *           ∧ cache MUST be cleared
     * 
*/
    public function property_31_expires_at_validated_after_cache_hit(): void
    {
        // Arrange: Create subscription expiring in 30 seconds
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addSeconds(30),
        ]);

        // Act: First request caches subscription (valid)
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Travel 31 seconds - subscription expired but cache still valid
        $this->travel(31)->seconds();
        
        // Assert: Cache still exists (TTL 60s not reached)
        $this->assertTrue(
            Cache::has($cacheKey),
            'Cache should still exist (within 60s TTL)'
        );
        
        // Assert: Request fails due to expires_at validation
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard');
            
        $response->assertStatus(402);
        $response->assertJson([
            'success' => false,
            'error' => 'SUBSCRIPTION_EXPIRED',
        ]);
        
        // Assert: Cache cleared after detecting expiration
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be cleared after detecting expired subscription'
        );
        
        // Assert: Expiration logged
        Log::shouldHaveReceived('channel')
            ->with('audit')
            ->once();
    }

    /**
     * **Property 31: Validation prevents access with various expiration times**
     * 
     * Universal property: Expires_at validation works correctly regardless
     * of how far in the past the expiration date is.
     * 
*/
    public function property_31_validation_works_for_various_expiration_times(): void
    {
        $expirationDeltas = [1, 5, 30, 60, 300]; // seconds in the past
        
        foreach ($expirationDeltas as $delta) {
            Cache::flush();
            
            $school = School::factory()->create();
            $user = User::factory()->create(['school_id' => $school->id]);
            
            // Create subscription that expired $delta seconds ago
            Subscription::factory()->create([
                'school_id' => $school->id,
                'is_active' => true,
                'expires_at' => now()->subSeconds($delta),
            ]);
            
            // Act: Request with expired subscription
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/student/dashboard');
            
            // Assert: Always returns 402 regardless of expiration time
            $response->assertStatus(402, 
                "Should return 402 for subscription expired {$delta} seconds ago"
            );
            $response->assertJson([
                'success' => false,
                'error' => 'SUBSCRIPTION_EXPIRED',
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 32: Cache cleared on subscription update
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * **Property 32: Cache cleared on subscription update**
     * 
     * Universal property: For any subscription update operation (create, update,
     * delete), the cache MUST be immediately cleared to prevent serving stale
     * data and ensure immediate effect of subscription changes.
     * 
     * PROPERTY: ∀ subscription_operation ∈ {create, update, delete}
     *           → Cache::has("school_sub_{school_id}") before operation
     *           → ¬Cache::has("school_sub_{school_id}") after operation
     * 
*/
    public function property_32_cache_cleared_on_subscription_update(): void
    {
        // Arrange: Create school with subscription
        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'admin',
        ]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Cache subscription by calling middleware directly
        $cacheKey = "school_sub_{$school->id}";
        Cache::put($cacheKey, [
            'active' => true,
            'subscription_id' => $subscription->id,
            'expires_at' => $subscription->expires_at->toIso8601String(),
        ], 60);
        
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Update subscription
        $subscription->update([
            'expires_at' => now()->addDays(60),
        ]);
        
        // Assert: Cache automatically cleared by observer
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be cleared immediately after subscription update'
        );
    }

    /**
     * **Property 32: Cache cleared on subscription creation**
     * 
     * Universal property: When a new subscription is created for a school,
     * any existing cache MUST be cleared to ensure immediate access.
     * 
*/
    public function property_32_cache_cleared_on_subscription_creation(): void
    {
        // Arrange: School without subscription
        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'admin',
        ]);

        // Act: Manually cache expired subscription
        $cacheKey = "school_sub_{$school->id}";
        Cache::put($cacheKey, [
            'active' => false,
            'reason' => 'no_active_subscription',
        ], 60);
        
        $this->assertTrue(Cache::has($cacheKey));
        
        // Act: Create new active subscription
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);
        
        // Assert: Cache cleared by observer
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be cleared when new subscription is created'
        );
    }

    /**
     * **Property 32: Cache cleared on subscription deletion**
     * 
     * Universal property: When a subscription is deleted, the cache MUST be
     * cleared to ensure immediate access denial.
     * 
*/
    public function property_32_cache_cleared_on_subscription_deletion(): void
    {
        // Arrange: School with active subscription
        $school = School::factory()->create();
        $user = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'admin',
        ]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Cache subscription
        $cacheKey = "school_sub_{$school->id}";
        Cache::put($cacheKey, [
            'active' => true,
            'subscription_id' => $subscription->id,
            'expires_at' => $subscription->expires_at->toIso8601String(),
        ], 60);
        
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Delete subscription
        $subscription->delete();
        
        // Assert: Cache cleared by observer
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be cleared when subscription is deleted'
        );
    }

    /**
     * **Property 32: Cache cleared on package changes**
     * 
     * Universal property: When a school's subscription package is changed
     * (upgrade/downgrade), the cache MUST be cleared to ensure immediate
     * effect of the package change.
     * 
*/
    public function property_32_cache_cleared_on_package_change(): void
    {
        // Arrange: School with active subscription
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'plan_type' => 'basic',
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Manually cache subscription
        $cacheKey = "school_sub_{$school->id}";
        Cache::put($cacheKey, [
            'active' => true,
            'subscription_id' => $subscription->id,
            'plan_type' => 'basic',
            'expires_at' => $subscription->expires_at->toIso8601String(),
        ], 60);
        
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Change package (upgrade to premium)
        $subscription->update([
            'plan_type' => 'premium',
            'max_students' => 1000,
            'max_teachers' => 100,
        ]);
        
        // Assert: Cache automatically cleared by observer
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be cleared immediately after package change'
        );
    }

    /**
     * **Property 32: Cache cleared on multiple package attribute changes**
     * 
     * Universal property: Any change to subscription attributes that affect
     * access (plan_type, max_students, max_teachers, features) MUST clear cache.
     * 
*/
    public function property_32_cache_cleared_on_multiple_package_attribute_changes(): void
    {
        $attributeChanges = [
            ['plan_type' => 'premium'],
            ['max_students' => 500],
            ['max_teachers' => 50],
            ['features' => ['advanced_reports' => true, 'api_access' => true]],
        ];
        
        foreach ($attributeChanges as $index => $change) {
            // Create unique school for each test case (avoid Cache::flush() between iterations)
            $school = School::factory()->create();
            $user = User::factory()->create(['school_id' => $school->id]);
            
            // Create subscription with different initial values to ensure update actually changes something
            $subscription = Subscription::factory()->create([
                'school_id' => $school->id,
                'is_active' => true,
                'plan_type' => 'basic',  // Different from 'premium'
                'max_students' => 100,    // Different from 500
                'max_teachers' => 10,     // Different from 50
                'features' => [],         // Different from the test features
                'expires_at' => now()->addDays(30),
            ]);

            // Manually cache subscription
            $cacheKey = "school_sub_{$school->id}";
            Cache::put($cacheKey, [
                'active' => true,
                'subscription_id' => $subscription->id,
                'expires_at' => $subscription->expires_at->toIso8601String(),
            ], 60);
            
            $this->assertTrue(Cache::has($cacheKey), "Cache should exist before update");

            // Update subscription attribute
            $subscription->update($change);
            
            // Assert: Cache cleared for each attribute change
            $this->assertFalse(
                Cache::has($cacheKey),
                "Cache should be cleared when changing: " . json_encode($change)
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 33: Webhook clears subscription cache
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * **Property 33: Webhook clears subscription cache**
     * 
     * Universal property: For any webhook that modifies subscription status
     * (payment success, cancellation, expiration), the cache MUST be cleared
     * to ensure immediate effect of payment gateway events.
     * 
     * PROPERTY: ∀ webhook_event ∈ {settlement, cancel, deny, expire}
     *           → Cache::has("school_sub_{school_id}") before webhook
     *           → ¬Cache::has("school_sub_{school_id}") after webhook
     * 
*/
    public function property_33_webhook_clears_cache_on_subscription_deactivation(): void
    {
        // Arrange: School with active subscription
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Cache subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Simulate webhook deactivating subscription
        // This mimics what happens in MidtransWebhookController
        $subscription->update(['status' => 'inactive']);
        CheckActiveSubscription::clearCache($subscription->school_id);
        
        // Assert: Cache cleared
        $this->assertFalse(
            Cache::has($cacheKey),
            'Cache should be cleared when webhook deactivates subscription'
        );
        
        // Assert: Next request fails
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(402);
    }

    /**
     * **Property 33: Webhook cache clearing is idempotent**
     * 
     * Universal property: Clearing cache multiple times (e.g., duplicate webhooks)
     * should be safe and not cause errors.
     * 
*/
    public function property_33_webhook_cache_clearing_is_idempotent(): void
    {
        // Arrange: School with subscription
        $school = School::factory()->create();
        $user = User::factory()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Cache subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(200);

        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Act: Clear cache multiple times (simulating duplicate webhooks)
        CheckActiveSubscription::clearCache($school->id);
        CheckActiveSubscription::clearCache($school->id);
        CheckActiveSubscription::clearCache($school->id);
        
        // Assert: No errors, cache is cleared
        $this->assertFalse(Cache::has($cacheKey));
        
        // Assert: Clearing logged each time
        Log::shouldHaveReceived('info')
            ->with('Subscription cache cleared', \Mockery::type('array'))
            ->times(3);
    }

    /**
     * **Property 33: Webhook cache clearing is school-specific**
     * 
     * Universal property: Webhook clearing cache for one school should not
     * affect other schools' caches.
     * 
*/
    public function property_33_webhook_cache_clearing_is_school_specific(): void
    {
        // Arrange: Two schools with subscriptions
        $school1 = School::factory()->create();
        $school2 = School::factory()->create();
        
        $user1 = User::factory()->create(['school_id' => $school1->id]);
        $user2 = User::factory()->create(['school_id' => $school2->id]);
        
        Subscription::factory()->create([
            'school_id' => $school1->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);
        
        Subscription::factory()->create([
            'school_id' => $school2->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // Act: Cache both subscriptions
        $this->actingAs($user1, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(200);
            
        $this->actingAs($user2, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertStatus(200);

        $cacheKey1 = "school_sub_{$school1->id}";
        $cacheKey2 = "school_sub_{$school2->id}";
        
        $this->assertTrue(Cache::has($cacheKey1));
        $this->assertTrue(Cache::has($cacheKey2));

        // Act: Webhook clears only school1's cache
        CheckActiveSubscription::clearCache($school1->id);
        
        // Assert: Only school1's cache cleared
        $this->assertFalse(
            Cache::has($cacheKey1),
            'School 1 cache should be cleared'
        );
        $this->assertTrue(
            Cache::has($cacheKey2),
            'School 2 cache should remain intact'
        );
    }
}

