<?php

namespace Tests\Feature\RateLimiting;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Isolated Rate Limiting Tests
 * 
 * Tests ONLY rate limiting without any business logic, policies, or validation.
 * Uses dedicated test-only routes that return simple JSON responses.
 */
class IsolatedRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;
    private School $schoolB;
    private User $userSchoolA;
    private User $userSchoolB;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();

        // Create two schools for testing separation
        $this->schoolA = School::create([
            'name' => 'School A',
            'npsn' => '11111111',
            'school_level' => 'SMA',
            'address' => 'Address A',
            'is_active' => true,
        ]);

        $this->schoolB = School::create([
            'name' => 'School B',
            'npsn' => '22222222',
            'school_level' => 'SMA',
            'address' => 'Address B',
            'is_active' => true,
        ]);

        // Create users for each school
        $this->userSchoolA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'User A',
            'username' => 'usera',
            'email' => 'usera@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->userSchoolB = User::create([
            'school_id' => $this->schoolB->id,
            'name' => 'User B',
            'username' => 'userb',
            'email' => 'userb@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);
    }

    public function test_custom_rate_limit_blocks_after_5_requests()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // First 5 requests should succeed
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/test/rate-limit');
            
            $response->assertStatus(200)
                ->assertJson(['success' => true]);
            
            // Check rate limit headers
            $this->assertNotNull($response->headers->get('X-RateLimit-Limit'));
            $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
        }

        // 6th request should be rate limited
        $response = $this->postJson('/api/v1/test/rate-limit');
        
        $response->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_api_rate_limit_allows_60_requests_per_minute()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // First 60 requests should succeed
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->getJson('/api/v1/test/rate-limit/api');
            
            $response->assertStatus(200)
                ->assertJson(['ok' => true]);
        }

        // 61st request should be rate limited
        $response = $this->getJson('/api/v1/test/rate-limit/api');
        
        $response->assertStatus(429);
    }

    public function test_scan_rate_limit_blocks_after_10_requests()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // First 10 requests should succeed
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->getJson('/api/v1/test/rate-limit/scan');
            
            $response->assertStatus(200)
                ->assertJson(['ok' => true]);
        }

        // 11th request should be rate limited
        $response = $this->getJson('/api/v1/test/rate-limit/scan');
        
        $response->assertStatus(429);
    }

    public function test_rate_limit_is_per_school()
    {
        // School A user makes 5 requests (reaches limit)
        Sanctum::actingAs($this->userSchoolA, ['*']);
        
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/test/rate-limit');
            $response->assertStatus(200);
        }

        // 6th request from School A should be blocked
        $response = $this->postJson('/api/v1/test/rate-limit');
        $response->assertStatus(429);

        // School B user should have separate counter - all 5 requests succeed
        Sanctum::actingAs($this->userSchoolB, ['*']);
        
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/test/rate-limit');
            $response->assertStatus(200);
        }

        // 6th request from School B should now be blocked
        $response = $this->postJson('/api/v1/test/rate-limit');
        $response->assertStatus(429);
    }

    public function test_rate_limit_headers_are_present()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        $response = $this->postJson('/api/v1/test/rate-limit');
        
        $response->assertStatus(200);
        
        // Verify rate limit headers exist (values may vary due to multiple middleware layers)
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        
        // Verify the remaining count is less than limit
        $limit = (int) $response->headers->get('X-RateLimit-Limit');
        $remaining = (int) $response->headers->get('X-RateLimit-Remaining');
        $this->assertGreaterThan(0, $limit);
        $this->assertLessThan($limit, $remaining);
    }

    public function test_rate_limit_remaining_decreases_with_each_request()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // Make 3 requests and verify remaining decreases
        $previousRemaining = null;
        
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/v1/test/rate-limit');
            
            $response->assertStatus(200);
            $remaining = (int) $response->headers->get('X-RateLimit-Remaining');
            
            if ($previousRemaining !== null) {
                // Remaining should decrease with each request
                $this->assertLessThan($previousRemaining, $remaining);
            }
            
            $previousRemaining = $remaining;
        }
    }

    public function test_rate_limit_resets_after_decay_period()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // Make 5 requests to hit the limit
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/test/rate-limit');
        }

        // 6th should be blocked
        $response = $this->postJson('/api/v1/test/rate-limit');
        $response->assertStatus(429);

        // Travel 61 seconds into future (decay period is 1 minute)
        $this->travel(61)->seconds();

        // Should work again after decay period
        $response = $this->postJson('/api/v1/test/rate-limit');
        $response->assertStatus(200);
    }

    public function test_different_endpoints_have_separate_limits()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // Hit custom rate limit (5 requests)
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/test/rate-limit');
            $response->assertStatus(200);
        }

        // Custom endpoint should be blocked
        $response = $this->postJson('/api/v1/test/rate-limit');
        $response->assertStatus(429);

        // But API endpoint should still work (different limit, different key)
        $response = $this->getJson('/api/v1/test/rate-limit/api');
        $response->assertStatus(200);

        // And scan endpoint should still work
        $response = $this->getJson('/api/v1/test/rate-limit/scan');
        $response->assertStatus(200);
    }

    public function test_unauthenticated_requests_are_not_rate_limited_by_school()
    {
        // Without authentication, school-based rate limiting is bypassed
        // (It returns next($request) when no user or school_id)
        
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->postJson('/api/v1/test/rate-limit');
            // Should get 401 Unauthenticated, not 429 Rate Limited
            $response->assertStatus(401);
        }
    }

    public function test_rate_limit_response_includes_retry_after()
    {
        Sanctum::actingAs($this->userSchoolA, ['*']);

        // Hit the limit
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/test/rate-limit');
        }

        // Get rate limited response
        $response = $this->postJson('/api/v1/test/rate-limit');
        
        $response->assertStatus(429)
            ->assertJsonStructure(['retry_after'])
            ->assertJson([
                'retry_after' => 60, // 1 minute in seconds
            ]);
    }
}
