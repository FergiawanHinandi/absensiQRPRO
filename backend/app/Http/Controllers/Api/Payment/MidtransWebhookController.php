<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\ProcessedWebhook;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    /**
     * Handle Midtrans Payment Notification
     * 
     * SECURITY:
     * - Idempotent Request: Uses Atomic Redis Lock & ProcessedWebhook table.
     * - Double-Spending Prevention: DB Transaction.
     * - Signature Validation: HMAC SHA-512.
     */
    public function handle(Request $request)
    {
        // 1. Validate Signature (HMAC SHA-512)
        $serverKey = config('services.midtrans.server_key');
        $payload = $request->input();
        
        $signatureKey = hash('sha512', 
            ($payload['order_id'] ?? '') . 
            ($payload['status_code'] ?? '') . 
            ($payload['gross_amount'] ?? '') . 
            $serverKey
        );

        if (($payload['signature_key'] ?? '') !== $signatureKey) {
            Log::channel('security')->warning('webhook_invalid_signature', [
                'payload' => $payload,
                'ip' => $request->ip()
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $orderId = $payload['order_id'];
        $transactionStatus = $payload['transaction_status'];
        $fraudStatus = $payload['fraud_status'] ?? '';
        $paymentType = $payload['payment_type'] ?? '';
        $grossAmount = $payload['gross_amount'];

        // 2. Redis Mutex Lock (300s TTL)
        // Ensures only ONE process handles this specific order_id concurrently.
        $lockKey = "webhook_lock:{$orderId}";
        $lock = Cache::lock($lockKey, 300);

        if (!$lock->get()) {
            // Already being processed by another worker
            Log::info('webhook_duplicate_lock', ['order_id' => $orderId]);
            return response()->json(['message' => 'Processing'], 200); // 200 to satisfy Midtrans retry logic
        }

        try {
            // 3. Database Transaction & Idempotency Check
            DB::transaction(function () use ($orderId, $transactionStatus, $fraudStatus, $paymentType, $grossAmount, $payload) {

                // A. Check if already processed (Idempotency Key)
                // Use lockForUpdate only if row exists to check (gap lock issue prevention)
                // Here "exists()" is usually safe enough inside transaction with Redis Lock upstream.
                $exists = ProcessedWebhook::where('order_id', $orderId)->exists();

                if ($exists) {
                    Log::info('webhook_already_processed_db', ['order_id' => $orderId]);
                    return; // Already processed, commit & exit
                }

                // B. Find Subscription & Lock Row
                // Assume order_id format: "SUB-{school_id}-{timestamp}" or lookup table
                // Let's assume order_id is stored in subscription or we parse it.
                // For this example, let's assume we store 'last_order_id' on Subscription or look it up.
                // Assuming simple mapping: logic to find subscription from order_id
                // Implementation Details: In a real app, you'd parse school_id or use a payment_logs table.
                
                // For demonstration, let's assume we find subscription by `last_order_id` or similar logic.
                // Here we simulate finding the subscription.
                $subscription = Subscription::where('last_order_id', $orderId)->lockForUpdate()->first();

                if (!$subscription) {
                    // Log warning but record webhook as processed to stop retries if invalid order_id
                    Log::warning('webhook_subscription_not_found', ['order_id' => $orderId]);
                    
                    ProcessedWebhook::create([
                        'order_id' => $orderId,
                        'transaction_status' => 'ignored_no_sub',
                        'payment_type' => $paymentType,
                        'gross_amount' => $grossAmount,
                        'raw_payload' => $payload,
                        'processed_at' => now(),
                    ]);
                    return;
                }

                // C. Process Logic
                if ($transactionStatus == 'capture') {
                    if ($fraudStatus == 'challenge') {
                        // Handle challenge
                    } else if ($fraudStatus == 'accept') {
                        $this->activateSubscription($subscription);
                    }
                } else if ($transactionStatus == 'settlement') {
                    $this->activateSubscription($subscription);
                } else if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
                    $subscription->update(['status' => 'inactive']);
                } else if ($transactionStatus == 'pending') {
                    // Update status to pending
                }

                // D. Record Webhook as Processed (Atomic Insert)
                ProcessedWebhook::create([
                    'order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                    'payment_type' => $paymentType,
                    'gross_amount' => $grossAmount,
                    'raw_payload' => $payload,
                    'processed_at' => now(),
                ]);

                Log::info('webhook_processed_success', ['order_id' => $orderId, 'status' => $transactionStatus]);
            });

        } catch (\Exception $e) {
            Log::error('webhook_processing_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            // If DB Transaction fails, we return 500 so Midtrans retries later.
            // The Redis lock will expire in 10s allowing retry.
            return response()->json(['message' => 'Error processing webhook'], 500);
        } finally {
            $lock->release();
        }

        return response()->json(['message' => 'OK']);
    }

    private function activateSubscription(Subscription $subscription)
    {
        // Update DB
        $subscription->update([
            'is_active' => true,
            'status' => 'active',
            'expires_at' => now()->addMonth(), // Example logic
        ]);

        // Clear Cache with Lock (Thundering Herd Protection)
        // Wait up to 3 seconds for lock, hold for 5 seconds
        $cacheKey = "subscription_lock:{$subscription->school_id}";
        
        try {
            Cache::lock($cacheKey, 5)->block(3, function () use ($subscription) {
                // Invalidate Cache
                // The middleware re-populates it on next request
                Cache::forget("school_sub_{$subscription->school_id}");
                Log::info('subscription_cache_cleared', ['school_id' => $subscription->school_id]);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Could not acquire lock, force clear anyway as fallback
            Cache::forget("school_sub_{$subscription->school_id}");
        }
    }
}
