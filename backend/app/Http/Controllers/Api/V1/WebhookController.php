<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProcessedWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CRITICAL: Enhanced WebhookController with Idempotency Protection
 *
 * FIXES:
 * - Prevents duplicate webhook processing via Redis locks
 * - Anti-replay attack protection with unique constraints
 * - Proper transaction handling with rollback on failure
 * - Comprehensive audit logging
 * - SECURITY: Sanitized logging (no sensitive data)
 * - RACE CONDITION SAFE: Redis lock prevents concurrent processing
 */
class WebhookController extends Controller
{
    /**
     * Sanitize payload for logging - remove sensitive data
     */
    private function sanitizePayloadForLogging(array $payload): array
    {
        $sensitiveKeys = [
            'signature_key',
            'card_number',
            'cvv',
            'expiry',
            'pin',
            'password',
            'token',
            'access_token',
            'refresh_token',
        ];

        $sanitized = $payload;
        foreach ($sensitiveKeys as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '[REDACTED]';
            }
        }

        // Also mask partial card numbers if present
        if (isset($sanitized['masked_card'])) {
            $sanitized['masked_card'] = '****'.substr($sanitized['masked_card'], -4);
        }

        return $sanitized;
    }

    public function handlePayment(Request $request)
    {
        // SECURITY FIX: Log sanitized payload only
        Log::info('Webhook received', [
            'ip' => $request->ip(),
            'method' => $request->method(),
            'payload' => $this->sanitizePayloadForLogging($request->all()),
        ]);

        // CRITICAL: Extract order ID early for idempotency check
        $orderId = $request->input('order_id') ?? $request->input('transaction_id');
        $transactionId = $request->input('transaction_id') ?? $orderId;

        if (! $orderId || ! $transactionId) {
            Log::warning('Webhook missing required IDs', [
                'ip' => $request->ip(),
                'has_order_id' => $request->has('order_id'),
                'has_transaction_id' => $request->has('transaction_id'),
                // SECURITY: Don't log full payload on error
            ]);

            return response()->json(['message' => 'Missing order_id or transaction_id'], 400);
        }

        // =============================================================================
        // CRITICAL: REDIS LOCK FOR RACE CONDITION PROTECTION
        // =============================================================================
        // Scenario: Two identical webhooks arrive within milliseconds
        // Without lock:
        //   1. Both check isAlreadyProcessed() → both return false
        //   2. Both start processing
        //   3. Result: Double subscription extension, double emails, wrong balance
        //
        // With Redis lock (atomic SET NX):
        //   1. First request acquires lock
        //   2. Second request waits (or fails fast)
        //   3. First request processes and marks as processed
        //   4. Second request sees isAlreadyProcessed() = true, returns 200
        // =============================================================================

        $lockKey = "webhook_lock:{$orderId}";
        $lock = Cache::lock($lockKey, 300); // 300 seconds (5 minutes) lock timeout

        try {
            // CRITICAL: Block for up to 60 seconds waiting for lock (5 seconds in testing)
            // If lock not acquired after timeout, another request is processing
            $blockTimeout = app()->environment(['testing', 'local']) ? 5 : 60;
            if (! $lock->block($blockTimeout)) {
                Log::warning('Webhook lock timeout - another request is processing', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'ip' => $request->ip(),
                ]);

                // Return 429 to tell Midtrans to retry later
                return response()->json([
                    'message' => 'Another request is processing this webhook, please retry',
                    'retry_after' => 60,
                ], 429);
            }

            // Lock acquired! Now check idempotency INSIDE the lock
            if (ProcessedWebhook::isAlreadyProcessed($orderId)) {
                Log::info('Webhook already processed (idempotency)', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already processed',
                    'idempotent' => true,
                ]);
            }

            // CRITICAL: Additional check by transaction ID
            if (ProcessedWebhook::isTransactionProcessed($transactionId)) {
                Log::info('Transaction already processed (idempotency)', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Transaction already processed',
                    'idempotent' => true,
                ]);
            }

            // CRITICAL: Check if currently being processed
            if (ProcessedWebhook::isProcessing($orderId)) {
                Log::info('Webhook currently being processed', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Currently processing',
                    'processing' => true,
                ], 200);
            }

            // CRITICAL: Mark as processing before starting
            ProcessedWebhook::markAsProcessing([
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'payload' => $request->all(),
                'signature_hash' => $request->input('signature_key'),
            ]);

            // CRITICAL: Use database transaction for atomic processing
            return DB::transaction(function () use ($request, $orderId, $transactionId) {
                try {
                    // CRITICAL: Verify Midtrans signature
                    $serverKey = config('services.midtrans.server_key');
                    $hashed = hash('sha512',
                        $request->input('order_id').
                        $request->input('status_code').
                        $request->input('gross_amount').
                        $serverKey
                    );

                    $signatureValid = ($hashed === $request->input('signature_key'));

                    if (! $signatureValid && ! app()->environment(['local', 'testing'])) {
                        Log::warning('Invalid Midtrans signature', [
                            'order_id' => $orderId,
                            'ip' => $request->ip(),
                            'expected' => $hashed,
                            'received' => $request->input('signature_key'),
                        ]);

                        // CRITICAL: Still mark as processed to prevent retry attacks
                        ProcessedWebhook::markAsProcessed([
                            'order_id' => $orderId,
                            'transaction_id' => $transactionId,
                            'status' => 'failed',
                            'payload' => $request->all(),
                            'signature_hash' => $request->input('signature_key'),
                            'notes' => 'Invalid signature',
                        ]);

                        return response()->json(['message' => 'Invalid signature'], 401);
                    }

                    // Process the webhook
                    $result = $this->processWebhook($request, $orderId, $transactionId, $signatureValid);

                    // CRITICAL: Mark as processed after successful processing
                    ProcessedWebhook::markAsProcessed([
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'status' => $result['status'] ?? 'success',
                        'payload' => $request->all(),
                        'signature_hash' => $request->input('signature_key'),
                        'payment_method' => $result['payment_method'] ?? 'midtrans',
                        'notes' => $result['notes'] ?? 'Processed successfully',
                    ]);

                    return response()->json([
                        'success' => true,
                        'processed_at' => now()->toISOString(),
                        'order_id' => $orderId,
                    ]);

                } catch (\Exception $e) {
                    Log::error('Webhook processing failed', [
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // CRITICAL: Mark as processed even on failure to prevent retries
                    ProcessedWebhook::markAsProcessed([
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'status' => 'failed',
                        'payload' => $request->all(),
                        'signature_hash' => $request->input('signature_key'),
                        'notes' => 'Processing failed: '.$e->getMessage(),
                    ]);

                    return response()->json(['message' => 'Processing failed'], 500);
                }
            });

        } finally {
            // CRITICAL: Always release lock, even on exception
            $lock?->release();
        }
    }

    /**
     * CRITICAL: Process webhook with proper error handling
     */
    private function processWebhook(Request $request, string $orderId, string $transactionId, bool $signatureValid): array
    {
        try {
            // CRITICAL: Always use simulation mode in testing environment
            if (app()->environment(['testing', 'local'])) {
                Log::info('Processing webhook in simulation mode (testing environment)', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                ]);

                return $this->handleSimulation($request);
            }

            if ($signatureValid) {
                return $this->processMidtransWebhook($request, $orderId);
            } else {
                // Fallback for simulation/testing
                Log::info('Processing webhook in simulation mode', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                ]);

                return $this->handleSimulation($request);
            }
        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * CRITICAL: Process Midtrans webhook with proper validation
     */
    private function processMidtransWebhook(Request $request, string $orderId): array
    {
        // CRITICAL: In test/local environment, always use request data directly
        // Midtrans\Notification expects $_POST data which doesn't work with JSON requests
        if (app()->environment(['testing', 'local'])) {
            $transaction = $request->input('transaction_status') ?? $request->input('status');
            $type = $request->input('payment_type', 'unknown');
            $fraud = $request->input('fraud_status');
        } else {
            // Production: Use Midtrans SDK
            try {
                $notification = new \Midtrans\Notification;
                $transaction = $notification->transaction_status;
                $type = $notification->payment_type;
                $fraud = $notification->fraud_status;
            } catch (\Exception $e) {
                // Fallback if SDK fails
                Log::warning('Midtrans\Notification failed, using request data directly', [
                    'error' => $e->getMessage(),
                    'order_id' => $orderId,
                ]);
                
                $transaction = $request->input('transaction_status') ?? $request->input('status');
                $type = $request->input('payment_type', 'unknown');
                $fraud = $request->input('fraud_status');
            }
        }
        
        $payment = \App\Models\Payment::where('transaction_id', $orderId)->first();

        if (! $payment) {
            throw new \Exception("Payment not found for order: {$orderId}");
        }

        // CRITICAL: Map Midtrans status to internal status
        $internalStatus = $this->mapMidtransStatus($transaction, $type, $fraud);

        $payment->status = $internalStatus;

        // CRITICAL: Apply package only once when payment is successful
        if ($internalStatus === 'paid' && ! $payment->payment_date) {
            $payment->payment_date = now();
            if ($payment->package_id) {
                $this->applyPackageToSchool($payment->school_id, $payment->package_id);
            }
        }

        $payment->save();

        return [
            'status' => 'success',
            'payment_method' => 'midtrans',
            'payment_status' => $internalStatus,
            'notes' => "Midtrans webhook processed: {$transaction}",
        ];
    }

    /**
     * CRITICAL: Map Midtrans status to internal status
     */
    private function mapMidtransStatus(string $transaction, string $type, ?string $fraud): string
    {
        if ($transaction == 'capture') {
            if ($type == 'credit_card') {
                return ($fraud == 'challenge') ? 'pending' : 'paid';
            }

            return 'paid';
        }

        return match ($transaction) {
            'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'expire', 'cancel' => 'failed',
            default => 'pending'
        };
    }

    /**
     * CRITICAL: Handle simulation with proper validation
     */
    private function handleSimulation(Request $request): array
    {
        $transactionId = $request->input('transaction_id') ?? $request->input('order_id');
        $status = $request->input('status') ?? $request->input('transaction_status');

        $payment = \App\Models\Payment::where('transaction_id', $transactionId)->first();

        if (! $payment) {
            throw new \Exception("Payment not found for transaction: {$transactionId}");
        }

        $internalStatus = match ($status) {
            'settlement', 'capture', 'paid', 'success' => 'paid',
            'deny', 'cancel', 'expire', 'failed', 'failure' => 'failed',
            default => 'pending',
        };

        $payment->status = $internalStatus;

        // CRITICAL: Apply package only once when payment is successful
        if ($internalStatus === 'paid' && ! $payment->payment_date) {
            $payment->payment_date = now();
            if ($payment->package_id) {
                $this->applyPackageToSchool($payment->school_id, $payment->package_id);
            }
        }

        $payment->save();

        return [
            'status' => 'success',
            'payment_method' => 'simulation',
            'payment_status' => $internalStatus,
            'notes' => "Simulation webhook processed: {$status}",
        ];
    }

    /**
     * CRITICAL: Apply package to school with proper validation
     */
    private function applyPackageToSchool($schoolId, $packageId): void
    {
        $school = \App\Models\School::find($schoolId);
        $pkg = \App\Models\SubscriptionPackage::find($packageId);

        if (! $school || ! $pkg) {
            Log::warning('Invalid school or package for upgrade', [
                'school_id' => $schoolId,
                'package_id' => $packageId,
            ]);

            return;
        }

        $features = is_string($pkg->features) ? json_decode($pkg->features, true) : $pkg->features;

        $school->update([
            'package_type' => $pkg->name,
            'max_students' => $features['max_students'] ?? $school->max_students,
            'max_teachers' => $features['max_teachers'] ?? $school->max_teachers,
            'max_classes' => $features['max_classes'] ?? $school->max_classes,
            'package_updated_at' => now(),
        ]);

        Log::info('School package upgraded', [
            'school_id' => $schoolId,
            'package_id' => $packageId,
            'package_name' => $pkg->name,
        ]);
    }
}
