<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProcessedWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRITICAL: Test Webhook Idempotency Protection
 *
 * TESTS:
 * - Duplicate webhook prevention
 * - Anti-replay attack protection
 * - Proper transaction handling
 */
class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data manually
        $this->school = \App\Models\School::create([
            'name' => 'Test School',
            'email' => 'test@school.com',
            'phone' => '123456789',
            'address' => 'Test Address',
            'package_type' => 'basic',
            'max_students' => 100,
            'max_teachers' => 10,
            'max_classes' => 5,
            'is_active' => true,
        ]);

        $this->package = \App\Models\SubscriptionPackage::create([
            'name' => 'Premium',
            'price' => 500000,
            'duration_months' => 12,
            'features' => json_encode([
                'max_students' => 500,
                'max_teachers' => 50,
                'max_classes' => 25,
            ]),
            'is_active' => true,
        ]);

        $this->payment = \App\Models\Payment::create([
            'school_id' => $this->school->id,
            'package_id' => $this->package->id,
            'transaction_id' => 'TEST_ORDER_123',
            'amount' => 500000,
            'status' => 'pending',
            'payment_method' => 'midtrans',
        ]);
    }

    /** @test */
    public function webhook_processes_successfully_first_time()
    {
        $webhookData = [
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_ORDER_123',
            'status' => 'settlement',
            'transaction_status' => 'settlement',
        ];

        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Check payment updated
        $this->payment->refresh();
        $this->assertEquals('paid', $this->payment->status);
        $this->assertNotNull($this->payment->payment_date);

        // Check webhook recorded
        $this->assertDatabaseHas('processed_webhooks', [
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_ORDER_123',
            'status' => 'success',
        ]);
    }

    /** @test */
    public function webhook_prevents_duplicate_processing()
    {
        $webhookData = [
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_ORDER_123',
            'status' => 'settlement',
            'transaction_status' => 'settlement',
        ];

        // First webhook
        $response1 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response1->assertStatus(200);

        // Check initial state
        $this->payment->refresh();
        $initialPaymentDate = $this->payment->payment_date;
        $this->assertEquals('paid', $this->payment->status);

        // Second webhook (duplicate)
        $response2 = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Already processed',
                'idempotent' => true,
            ]);

        // Check payment not changed
        $this->payment->refresh();
        $this->assertEquals('paid', $this->payment->status);
        $this->assertEquals($initialPaymentDate, $this->payment->payment_date);

        // Check only one webhook record
        $this->assertEquals(1, ProcessedWebhook::where('order_id', 'TEST_ORDER_123')->count());
    }

    /** @test */
    public function webhook_prevents_replay_attack_with_different_status()
    {
        // First webhook - success
        $webhookData1 = [
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_ORDER_123',
            'status' => 'settlement',
        ];

        $response1 = $this->postJson('/api/v1/webhooks/payment', $webhookData1);
        $response1->assertStatus(200);

        $this->payment->refresh();
        $this->assertEquals('paid', $this->payment->status);

        // Replay attack - trying to change to failed
        $webhookData2 = [
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_ORDER_123',
            'status' => 'failed',
        ];

        $response2 = $this->postJson('/api/v1/webhooks/payment', $webhookData2);

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Already processed',
                'idempotent' => true,
            ]);

        // Payment status should remain 'paid'
        $this->payment->refresh();
        $this->assertEquals('paid', $this->payment->status);
    }

    /** @test */
    public function webhook_handles_missing_order_id()
    {
        $webhookData = [
            'status' => 'settlement',
            // Missing order_id and transaction_id
        ];

        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Missing order_id or transaction_id']);
    }

    /** @test */
    public function webhook_records_failed_processing()
    {
        $webhookData = [
            'order_id' => 'NONEXISTENT_ORDER',
            'transaction_id' => 'NONEXISTENT_ORDER',
            'status' => 'settlement',
        ];

        $response = $this->postJson('/api/v1/webhooks/payment', $webhookData);

        $response->assertStatus(500);

        // Check failed webhook recorded
        $this->assertDatabaseHas('processed_webhooks', [
            'order_id' => 'NONEXISTENT_ORDER',
            'transaction_id' => 'NONEXISTENT_ORDER',
            'status' => 'failed',
        ]);
    }

    /** @test */
    public function webhook_prevents_package_reapplication()
    {
        $webhookData = [
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_ORDER_123',
            'status' => 'settlement',
        ];

        // First webhook - should apply package
        $response1 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response1->assertStatus(200);

        $this->school->refresh();
        $initialPackageType = $this->school->package_type;
        $initialMaxStudents = $this->school->max_students;

        // Second webhook - should not reapply package
        $response2 = $this->postJson('/api/v1/webhooks/payment', $webhookData);
        $response2->assertStatus(200)
            ->assertJson(['idempotent' => true]);

        $this->school->refresh();
        $this->assertEquals($initialPackageType, $this->school->package_type);
        $this->assertEquals($initialMaxStudents, $this->school->max_students);
    }

    /** @test */
    public function processed_webhook_model_methods_work_correctly()
    {
        // Test isAlreadyProcessed
        $this->assertFalse(ProcessedWebhook::isAlreadyProcessed('TEST_ORDER_123'));

        ProcessedWebhook::markAsProcessed([
            'order_id' => 'TEST_ORDER_123',
            'transaction_id' => 'TEST_TXN_123',
            'status' => 'success',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue(ProcessedWebhook::isAlreadyProcessed('TEST_ORDER_123'));
        $this->assertTrue(ProcessedWebhook::isTransactionProcessed('TEST_TXN_123'));

        // Test getProcessingHistory
        $history = ProcessedWebhook::getProcessingHistory('TEST_ORDER_123');
        $this->assertCount(1, $history);
        $this->assertEquals('success', $history->first()->status);
    }
}
