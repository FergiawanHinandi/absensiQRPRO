<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\School;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Security Test: Subscription Enforcement
 * Tests subscription-based access control via the `subscription.active`
 * middleware (CheckActiveSubscription):
 * - No active subscription blocks API access (402)
 * - Expired / cancelled subscription blocks API access
 * - Active subscription allows access
 * - Stale cache expiry detection (subscription expired mid-cache-TTL)
 *
 * Note: Webhook idempotency has its own dedicated suites
 * (WebhookIdempotencyTest, WebhookConcurrencyTest).
 */
#[\PHPUnit\Framework\Attributes\Group('security')]
#[\PHPUnit\Framework\Attributes\Group('subscription')]
class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear subscription cache between tests (school IDs repeat).
        Cache::flush();

        $this->school = School::factory()->create();
        $this->user = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        Route::middleware(['auth:sanctum', 'subscription.active'])
            ->prefix('api/test')
            ->get('/subscription-protected', fn () => response()->json(['ok' => true]));
    }

    private function protectedRequest(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->user);

        return $this->getJson('/api/test/subscription-protected');
    }

    private function assertBlocked(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(402);
        $response->assertJson([
            'success' => false,
            'error' => 'SUBSCRIPTION_EXPIRED',
            'message' => 'Langganan sekolah Anda telah berakhir. Silakan perpanjang langganan untuk melanjutkan.',
        ]);
    }

    /**
     * ST-001: No subscription blocks API access
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_api_access_without_active_subscription(): void
    {
        $response = $this->protectedRequest();

        $this->assertBlocked($response);
    }

    /**
     * ST-002: Expired subscription blocks API access
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_api_access_with_expired_subscription(): void
    {
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan_type' => 'basic',
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->protectedRequest();

        $this->assertBlocked($response);
    }

    /**
     * ST-003: Inactive (cancelled/payment-failed) subscription blocks access
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_api_access_with_inactive_subscription(): void
    {
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan_type' => 'premium',
            'is_active' => false,
            'expires_at' => now()->addDays(30),
            'cancelled_at' => now()->subDay(),
        ]);

        $response = $this->protectedRequest();

        $this->assertBlocked($response);
    }

    /**
     * ST-004: Active subscription allows API access
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_api_access_with_active_subscription(): void
    {
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan_type' => 'basic',
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->protectedRequest();

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
    }

    /**
     * ST-005: Subscription expiring during cache TTL is still detected
     * (middleware re-validates cached expires_at and denies stale access)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_detects_expiry_despite_stale_subscription_cache(): void
    {
        Subscription::factory()->create([
            'school_id' => $this->school->id,
            'plan_type' => 'premium',
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        // First request caches the active status
        $first = $this->protectedRequest();
        $first->assertStatus(200);

        // Advance time beyond the cached expiry (cache entry is untouched,
        // simulating a subscription expiring mid-TTL)
        $this->travel(31)->days();

        $second = $this->protectedRequest();

        $this->assertBlocked($second);
    }
}
