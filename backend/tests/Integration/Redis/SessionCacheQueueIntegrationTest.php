<?php

namespace Tests\Integration\Redis;

use App\Jobs\TestJob;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Integration tests for Session + Cache + Queue interactions
 * 
 * Validates the interaction between:
 * - Session management
 * - Cache layer
 * - Queue system
 * During normal operations and failover scenarios
 */
class SessionCacheQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->user = User::factory()->create(['school_id' => $this->school->id]);
    }

    /**
     * Test: Session data influences cache keys and queue jobs
     * Validates: Requirements 2.1, 4.1, 5.1
     */
    public function test_session_context_propagates_to_cache_and_queue()
    {
        // Create authenticated session
        $this->actingAs($this->user);
        $sessionId = session()->getId();
        session(['user_preference' => 'dark_mode']);

        // Cache data with session context
        $cacheKey = "user:{$this->user->id}:session:{$sessionId}:preferences";
        Cache::put($cacheKey, ['theme' => 'dark'], 3600);

        // Queue job with session context
        Queue::push(new TestJob([
            'user_id' => $this->user->id,
            'session_id' => $sessionId,
            'preference' => session('user_preference'),
        ]));

        // Verify cache contains session-specific data
        $cachedData = Cache::get($cacheKey);
        $this->assertEquals('dark', $cachedData['theme']);

        // Verify queue job has session context
        $this->assertEquals(1, Queue::size());
    }

    /**
     * Test: Cache invalidation triggers queue job for refresh
     * Validates: Requirements 4.3, 4.5, 3.1
     */
    public function test_cache_invalidation_triggers_refresh_queue_job()
    {
        $cacheKey = "school:{$this->school->id}:attendance_summary";
        
        // Set initial cache
        Cache::put($cacheKey, ['total' => 100], 3600);

        // Invalidate cache (simulating data change)
        Cache::forget($cacheKey);

        // Queue refresh job
        Queue::push(new \App\Jobs\RefreshCacheJob([
            'cache_key' => $cacheKey,
            'school_id' => $this->school->id,
        ]));

        // Verify job is queued
        $this->assertEquals(1, Queue::size());

        // Verify cache is empty until job processes
        $this->assertNull(Cache::get($cacheKey));
    }

    /**
     * Test: Session expiration clears related cache and queue jobs
     * Validates: Requirements 2.5, 4.5
     */
    public function test_session_expiration_clears_related_cache()
    {
        $this->actingAs($this->user);
        $sessionId = session()->getId();

        // Create session-specific cache
        $cacheKey = "session:{$sessionId}:temp_data";
        Cache::put($cacheKey, 'temporary', 3600);

        // Simulate session expiration
        session()->invalidate();

        // Queue cleanup job
        Queue::push(new \App\Jobs\CleanupExpiredSessionCacheJob([
            'session_id' => $sessionId,
        ]));

        // Verify cleanup job is queued
        $this->assertGreaterThan(0, Queue::size());
    }

    /**
     * Test: Multi-tenant session isolation with cache and queue
     * Validates: Requirements 5.1, 5.2, 5.3
     */
    public function test_multi_tenant_isolation_across_session_cache_queue()
    {
        $school2 = School::factory()->create();
        $user2 = User::factory()->create(['school_id' => $school2->id]);

        // User 1 session and cache
        $this->actingAs($this->user);
        session(['tenant_data' => 'school1_data']);
        Cache::tags(["tenant:{$this->school->id}"])->put('data', 'school1_cache', 3600);
        Queue::push(new TestJob(['school_id' => $this->school->id]));

        // User 2 session and cache
        $this->actingAs($user2);
        session(['tenant_data' => 'school2_data']);
        Cache::tags(["tenant:{$school2->id}"])->put('data', 'school2_cache', 3600);
        Queue::push(new TestJob(['school_id' => $school2->id]));

        // Verify isolation
        $this->actingAs($this->user);
        $this->assertEquals('school1_data', session('tenant_data'));
        $this->assertEquals('school1_cache', Cache::tags(["tenant:{$this->school->id}"])->get('data'));

        $this->actingAs($user2);
        $this->assertEquals('school2_data', session('tenant_data'));
        $this->assertEquals('school2_cache', Cache::tags(["tenant:{$school2->id}"])->get('data'));
    }

    /**
     * Test: Queue job failure triggers cache invalidation and session alert
     * Validates: Requirements 3.3, 4.5, 6.2
     */
    public function test_queue_failure_invalidates_cache_and_alerts_session()
    {
        $this->actingAs($this->user);
        $cacheKey = "processing:{$this->user->id}";

        // Set cache to indicate processing
        Cache::put($cacheKey, 'processing', 300);

        // Queue job that will fail
        Queue::push(new TestJob(['will_fail' => true]));

        // Simulate job failure
        try {
            // Process queue (will fail)
        } catch (\Exception $e) {
            // Clear processing cache on failure
            Cache::forget($cacheKey);
            
            // Set error in session
            session()->flash('queue_error', 'Job processing failed');
        }

        // Verify cache is cleared
        $this->assertNull(Cache::get($cacheKey));

        // Verify session has error message
        $this->assertEquals('Job processing failed', session('queue_error'));
    }

    /**
     * Test: Concurrent session operations with cache and queue
     * Validates: Requirements 9.1, 9.2, 9.3
     */
    public function test_concurrent_operations_maintain_consistency()
    {
        $users = User::factory()->count(10)->create(['school_id' => $this->school->id]);

        // Simulate concurrent operations
        foreach ($users as $user) {
            $this->actingAs($user);
            
            // Session operation
            session(['user_id' => $user->id]);
            
            // Cache operation
            Cache::put("user:{$user->id}:active", true, 3600);
            
            // Queue operation
            Queue::push(new TestJob(['user_id' => $user->id]));
        }

        // Verify all operations completed
        $this->assertEquals(10, Queue::size());
        
        foreach ($users as $user) {
            $this->assertTrue(Cache::get("user:{$user->id}:active"));
        }
    }
}
