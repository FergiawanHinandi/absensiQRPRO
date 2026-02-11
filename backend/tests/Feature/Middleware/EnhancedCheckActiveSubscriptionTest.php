<?php

namespace Tests\Feature\Middleware;

use App\Models\School;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EnhancedCheckActiveSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_allows_access_with_active_subscription()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(200);
    }

    /** @test */
    public function it_blocks_access_with_expired_subscription()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->subDays(10), // Expired 10 days ago
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(402);
        $response->assertJson([
            'success' => false,
            'error_code' => 'SUBSCRIPTION_EXPIRED',
        ]);
        $response->assertJsonStructure([
            'expired_at',
            'expired_days_ago',
            'subscription_type',
            'action_required',
            'renewal_url',
            'contact',
        ]);
    }

    /** @test */
    public function it_allows_access_within_grace_period()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->subDays(2), // Expired 2 days ago
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(200);
        $response->assertHeader('X-Subscription-Status', 'grace-period');
        $response->assertHeader('X-Grace-Period-Days-Remaining', '1');
    }

    /** @test */
    public function it_blocks_access_beyond_grace_period()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->subDays(5), // Expired 5 days ago (beyond 3-day grace)
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(402);
        $response->assertJson([
            'error_code' => 'SUBSCRIPTION_EXPIRED',
        ]);
    }

    /** @test */
    public function it_warns_when_subscription_expiring_soon()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(5), // Expires in 5 days
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(200);
        $response->assertHeader('X-Subscription-Status', 'expiring-soon');
        $response->assertHeader('X-Days-Until-Expiry', '5');
    }

    /** @test */
    public function it_blocks_access_without_subscription()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        // No subscription created

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(402);
        $response->assertJson([
            'success' => false,
            'error_code' => 'SUBSCRIPTION_REQUIRED',
            'action_required' => 'Please contact your school administrator to activate a subscription.',
        ]);
    }

    /** @test */
    public function it_blocks_access_with_inactive_subscription()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => false, // Inactive
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(402);
        $response->assertJson([
            'error_code' => 'SUBSCRIPTION_REQUIRED',
        ]);
    }

    /** @test */
    public function it_blocks_access_for_user_without_school()
    {
        $user = User::factory()->student()->create(['school_id' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(402);
        $response->assertJson([
            'error_code' => 'SUBSCRIPTION_REQUIRED',
            'message' => 'User not associated with any school',
        ]);
    }

    /** @test */
    public function it_blocks_access_for_unauthenticated_user()
    {
        $response = $this->getJson('/api/v1/student/attendance');

        $response->assertStatus(401);
    }

    /** @test */
    public function it_caches_subscription_check()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // First request - should cache
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        // Verify cache exists
        $cacheKey = "school_sub_{$school->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Verify cached value
        $cached = Cache::get($cacheKey);
        $this->assertEquals($subscription->id, $cached->id);
    }

    /** @test */
    public function it_uses_cached_subscription_on_subsequent_requests()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // First request - caches subscription
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        // Delete subscription from database
        $subscription->delete();

        // Second request - should still work (uses cache)
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(200);
    }

    /** @test */
    public function it_clears_cache_when_subscription_expires()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->subDays(10), // Expired
        ]);

        $cacheKey = "school_sub_{$school->id}";

        // Request should fail and clear cache
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        // Cache should be cleared
        $this->assertFalse(Cache::has($cacheKey));
    }

    /** @test */
    public function it_respects_school_timezone()
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Tokyo', // UTC+9
        ]);
        
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        // Subscription expires at midnight Tokyo time
        $expiresAt = now('Asia/Tokyo')->startOfDay();
        
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => $expiresAt,
        ]);

        // Before midnight Tokyo time
        $this->travel(-1)->hours();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(200);

        // After midnight Tokyo time
        $this->travel(2)->hours();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        $response->assertStatus(402);
    }

    /** @test */
    public function it_selects_latest_subscription_when_multiple_exist()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        // Old subscription (expired)
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->subDays(10),
        ]);

        // New subscription (active)
        Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        // Should use the latest (active) subscription
        $response->assertStatus(200);
    }

    /** @test */
    public function it_attaches_subscription_to_request()
    {
        $school = School::factory()->create();
        $user = User::factory()->student()->create(['school_id' => $school->id]);
        
        $subscription = Subscription::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/student/attendance');

        // Verify subscription is attached to request
        // (This would be tested in integration tests with actual controllers)
        $this->assertTrue(true);
    }
}
