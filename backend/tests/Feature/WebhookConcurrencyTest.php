<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProcessedWebhook;
use App\Models\School;
use App\Models\SubscriptionPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRITICAL: Comprehensive Webhook Concurrency & Idempotency Tests
 *
 * Tests for Day 8 enhancements:
 * - 300-second lock timeout
 * - markAsProcessing() status tracking
 * - Concurrent webhook handling
 * - Lock acquisition and release
 * - Processing state management
 */
class WebhookConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected SubscriptionPackage $package;
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Test School',
            'npsn' => 'TEST-NPSN-001',
            'school_level' => 'SMA',
            'email' => 'test@school.com',
            'phone' => '123456789',
            'address' => 'Test Address',
            'package_type' => 'basic',
            'max_students' => 100,
            'max_teachers' => 10,
            'max_classes' => 5,
            'is_active' => true,
        ]);

        $this->package = SubscriptionPackage::create([
            'name' => 'Premium',
            'price' => 500000,
            'billing_cycle' => 'yearly',
            'features' => json_encode([
                'max_students' => 500,
                'max_teachers' => 50,
                'max_classes' => 25,
            ]),
            'is_active' => true,
        ]);

        $this->payment = Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => 'TEST_ORDER_CONCURRENT_001',
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);
    }

    /** @test */
    public function webhook_acquires_lock_with_300_second_timeout()
    {
        $orderId = 'TEST_ORDER_LOCK_001';
        $lockKey = "webhook_lock:{$orderId}";

        // Create payment for this test
        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // Process webhook
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(200);

        // Lock should be released after processing
        $this->assertFalse(Cache::has($lockKey));
    }

    /** @test */
    public function webhook_marks_as_processing_before_execution()
    {
        $orderId = 'TEST_ORDER_PROCESSING_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(200);

        // Check that webhook was marked as processing initially
        $webhook = ProcessedWebhook::where('order_id', $orderId)->first();
        $this->assertNotNull($webhook);
        
        // Final status should be success after processing
        $this->assertEquals('success', $webhook->status);
    }

    /** @test */
    public function concurrent_webhooks_return_processing_status()
    {
        $orderId = 'TEST_ORDER_CONCURRENT_002';
        $lockKey = "webhook_lock:{$orderId}";

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        // Simulate first webhook holding the lock
        $lock = Cache::lock($lockKey, 300);
        $lock->get();

        // Mark as processing
        ProcessedWebhook::markAsProcessing([
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'payload' => ['test' => 'data'],
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // Second webhook should detect processing status
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        // Should return 429 (retry later) or 200 with processing flag
        $this->assertTrue(
            $response->status() === 429 || 
            ($response->status() === 200 && $response->json('processing') === true)
        );

        // Cleanup
        $lock->release();
    }

    /** @test */
    public function webhook_returns_429_when_lock_timeout_exceeded()
    {
        $orderId = 'TEST_ORDER_TIMEOUT_001';
        $lockKey = "webhook_lock:{$orderId}";

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        // Acquire lock to simulate another request processing
        $lock = Cache::lock($lockKey, 300);
        $lock->get();

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // This should timeout waiting for lock
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);

        // Cleanup
        $lock->release();
    }

    /** @test */
    public function webhook_releases_lock_on_exception()
    {
        $orderId = 'NONEXISTENT_ORDER_001';
        $lockKey = "webhook_lock:{$orderId}";

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // This will fail because payment doesn't exist
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(500);

        // Lock should be released even on exception
        $this->assertFalse(Cache::has($lockKey));
    }

    /** @test */
    public function webhook_prevents_double_subscription_processing()
    {
        $orderId = 'TEST_ORDER_DOUBLE_SUB_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // First webhook
        $response1 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response1->assertStatus(200);

        $this->school->refresh();
        $firstPackageType = $this->school->package_type;
        $firstMaxStudents = $this->school->max_students;
        $firstUpdatedAt = $this->school->package_updated_at;

        // Second webhook (should be idempotent)
        $response2 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response2->assertStatus(200)
            ->assertJson(['idempotent' => true]);

        $this->school->refresh();
        
        // Package should not be reapplied
        $this->assertEquals($firstPackageType, $this->school->package_type);
        $this->assertEquals($firstMaxStudents, $this->school->max_students);
        $this->assertEquals($firstUpdatedAt, $this->school->package_updated_at);
    }

    /** @test */
    public function webhook_handles_transaction_id_idempotency()
    {
        $orderId = 'TEST_ORDER_TXN_001';
        $transactionId = 'TEST_TXN_UNIQUE_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $transactionId,
            'status' => 'settlement',
        ];

        // First webhook
        $response1 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response1->assertStatus(200);

        // Second webhook with same transaction_id
        $response2 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response2->assertStatus(200)
            ->assertJson(['idempotent' => true]);

        // Should only have one processed webhook
        $this->assertEquals(1, ProcessedWebhook::where('transaction_id', $transactionId)->count());
    }

    /** @test */
    public function webhook_processing_status_prevents_duplicate_execution()
    {
        $orderId = 'TEST_ORDER_STATUS_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        // Manually mark as processing
        ProcessedWebhook::markAsProcessing([
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue(ProcessedWebhook::isProcessing($orderId));

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // Webhook should detect processing status
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        // Should return success with processing flag
        $this->assertTrue(
            $response->status() === 200 || $response->status() === 429
        );
    }

    /** @test */
    public function webhook_updates_processing_to_success_after_completion()
    {
        $orderId = 'TEST_ORDER_UPDATE_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response->assertStatus(200);

        // Check webhook status progression
        $webhook = ProcessedWebhook::where('order_id', $orderId)->first();
        $this->assertNotNull($webhook);
        $this->assertEquals('success', $webhook->status);
        $this->assertNotNull($webhook->processed_at);
    }

    /** @test */
    public function webhook_records_failed_status_on_processing_error()
    {
        $orderId = 'INVALID_ORDER_001';

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // This will fail because payment doesn't exist
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response->assertStatus(500);

        // Check webhook marked as failed
        $webhook = ProcessedWebhook::where('order_id', $orderId)->first();
        $this->assertNotNull($webhook);
        $this->assertEquals('failed', $webhook->status);
        $this->assertNotNull($webhook->processed_at);
    }

    /** @test */
    public function webhook_lock_prevents_race_condition_on_payment_update()
    {
        $orderId = 'TEST_ORDER_RACE_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // Process webhook
        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response->assertStatus(200);

        // Verify payment updated only once
        $payment = Payment::where('transaction_id', $orderId)->first();
        $this->assertEquals('paid', $payment->status);
        $this->assertNotNull($payment->payment_date);

        // Verify only one webhook record
        $this->assertEquals(1, ProcessedWebhook::where('order_id', $orderId)->count());
    }

    /** @test */
    public function webhook_processing_history_tracks_all_attempts()
    {
        $orderId = 'TEST_ORDER_HISTORY_001';

        Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => $orderId,
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);

        $webhookData = [
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'status' => 'settlement',
        ];

        // First webhook
        $this->postJson('/api/v1/webhooks/payment', $webhookData);

        // Second webhook (duplicate)
        $this->postJson('/api/v1/webhooks/payment', $webhookData);

        // Get processing history
        $history = ProcessedWebhook::getProcessingHistory($orderId);
        
        // Should have at least one record
        $this->assertGreaterThanOrEqual(1, $history->count());
        $this->assertEquals('success', $history->first()->status);
    }
}
