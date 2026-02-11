<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\School;
use App\Models\SubscriptionPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * REFACTORED Midtrans Webhook Controller
 * 
 * Requirements Met:
 * 1. ✅ Validate signature_key
 * 2. ✅ Validate gross_amount
 * 3. ✅ Validate order_id exists
 * 4. ✅ Use DB::transaction
 * 5. ✅ Redis idempotency: Redis::set("payment_{$orderId}", 1, 'EX', 300, 'NX')
 * 6. ✅ Log all callbacks
 * 
 * SECURITY:
 * - Signature verification using SHA512
 * - Amount validation to prevent tampering
 * - Idempotency to prevent duplicate processing
 * - Atomic transactions
 * - Comprehensive audit logging
 */
class MidtransWebhookController extends Controller
{
    /**
     * Handle Midtrans payment notification webhook
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleNotification(Request $request)
    {
        // ============================================================
        // STEP 1: LOG INCOMING WEBHOOK
        // ============================================================
        
        Log::channel('audit')->info('midtrans_webhook_received', [
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 100),
            'payload_keys' => array_keys($request->all()),
            'order_id' => $request->input('order_id'),
            'transaction_status' => $request->input('transaction_status'),
        ]);

        // ============================================================
        // STEP 2: EXTRACT AND VALIDATE REQUIRED FIELDS
        // ============================================================
        
        $orderId = $request->input('order_id');
        $statusCode = $request->input('status_code');
        $grossAmount = $request->input('gross_amount');
        $signatureKey = $request->input('signature_key');
        $transactionStatus = $request->input('transaction_status');

        // Validate required fields
        if (!$orderId || !$statusCode || !$grossAmount || !$signatureKey) {
            Log::warning('midtrans_webhook_missing_fields', [
                'ip' => $request->ip(),
                'has_order_id' => !empty($orderId),
                'has_status_code' => !empty($statusCode),
                'has_gross_amount' => !empty($grossAmount),
                'has_signature_key' => !empty($signatureKey),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Missing required fields',
            ], 400);
        }

        // ============================================================
        // STEP 3: VALIDATE SIGNATURE
        // ============================================================
        
        if (!$this->validateSignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
            Log::warning('midtrans_webhook_invalid_signature', [
                'order_id' => $orderId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 401);
        }

        // ============================================================
        // STEP 4: VALIDATE ORDER EXISTS
        // ============================================================
        
        $payment = Payment::where('transaction_id', $orderId)->first();

        if (!$payment) {
            Log::warning('midtrans_webhook_order_not_found', [
                'order_id' => $orderId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        // ============================================================
        // STEP 5: VALIDATE GROSS AMOUNT
        // ============================================================
        
        if (!$this->validateGrossAmount($payment, $grossAmount)) {
            Log::warning('midtrans_webhook_amount_mismatch', [
                'order_id' => $orderId,
                'expected_amount' => $payment->amount,
                'received_amount' => $grossAmount,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Amount mismatch',
            ], 400);
        }

        // ============================================================
        // STEP 6: REDIS IDEMPOTENCY CHECK
        // ============================================================
        // Key format: payment_{order_id}
        // TTL: 300 seconds (5 minutes)
        // Uses SET NX (Set if Not eXists) for atomic check-and-set
        
        $redisKey = "payment_{$orderId}";
        
        $lockAcquired = Redis::set($redisKey, 1, 'EX', 300, 'NX');
        
        if (!$lockAcquired) {
            // Another webhook is processing or already processed
            Log::info('midtrans_webhook_duplicate_blocked', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Already processed',
                'idempotent' => true,
            ], 200);
        }

        // ============================================================
        // STEP 7: PROCESS WEBHOOK IN TRANSACTION
        // ============================================================
        
        try {
            $result = DB::transaction(function () use ($request, $payment, $orderId, $transactionStatus) {
                // Map Midtrans status to internal status
                $internalStatus = $this->mapTransactionStatus(
                    $transactionStatus,
                    $request->input('payment_type'),
                    $request->input('fraud_status')
                );

                // Update payment status
                $oldStatus = $payment->status;
                $payment->status = $internalStatus;

                // Set payment date if successful and not already set
                if ($internalStatus === 'paid' && !$payment->payment_date) {
                    $payment->payment_date = now();
                    
                    // Apply package to school
                    if ($payment->package_id) {
                        $this->applyPackageToSchool($payment->school_id, $payment->package_id);
                    }
                }

                $payment->save();

                // Log successful processing
                Log::channel('audit')->info('midtrans_webhook_processed', [
                    'order_id' => $orderId,
                    'old_status' => $oldStatus,
                    'new_status' => $internalStatus,
                    'transaction_status' => $transactionStatus,
                    'payment_type' => $request->input('payment_type'),
                    'school_id' => $payment->school_id,
                    'amount' => $payment->amount,
                ]);

                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'status' => $internalStatus,
                ];
            });

            return response()->json($result, 200);

        } catch (\Exception $e) {
            // Release Redis lock on error
            Redis::del($redisKey);
            
            Log::error('midtrans_webhook_processing_failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Processing failed',
            ], 500);
        }
    }

    /**
     * Validate Midtrans signature
     * 
     * Formula: SHA512(order_id + status_code + gross_amount + server_key)
     * 
     * @param string $orderId
     * @param string $statusCode
     * @param string $grossAmount
     * @param string $signatureKey
     * @return bool
     */
    private function validateSignature(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $signatureKey
    ): bool {
        $serverKey = config('services.midtrans.server_key');
        
        if (!$serverKey) {
            Log::error('midtrans_server_key_not_configured');
            return false;
        }

        // Calculate expected signature
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        
        // Compare signatures (timing-safe comparison)
        $isValid = hash_equals($expectedSignature, $signatureKey);
        
        if (!$isValid) {
            Log::warning('midtrans_signature_verification_failed', [
                'order_id' => $orderId,
                'expected' => substr($expectedSignature, 0, 16) . '...',
                'received' => substr($signatureKey, 0, 16) . '...',
            ]);
        }
        
        return $isValid;
    }

    /**
     * Validate gross amount matches payment amount
     * 
     * @param Payment $payment
     * @param string $grossAmount
     * @return bool
     */
    private function validateGrossAmount(Payment $payment, string $grossAmount): bool
    {
        // Convert to float for comparison
        $expectedAmount = (float) $payment->amount;
        $receivedAmount = (float) $grossAmount;
        
        // Allow small floating point differences (0.01)
        $difference = abs($expectedAmount - $receivedAmount);
        
        return $difference < 0.01;
    }

    /**
     * Map Midtrans transaction status to internal status
     * 
     * @param string $transactionStatus
     * @param string|null $paymentType
     * @param string|null $fraudStatus
     * @return string
     */
    private function mapTransactionStatus(
        string $transactionStatus,
        ?string $paymentType = null,
        ?string $fraudStatus = null
    ): string {
        // Handle capture status for credit card
        if ($transactionStatus === 'capture') {
            if ($paymentType === 'credit_card') {
                return $fraudStatus === 'challenge' ? 'pending' : 'paid';
            }
            return 'paid';
        }

        // Map other statuses
        return match ($transactionStatus) {
            'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'expire', 'cancel' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Apply subscription package to school
     * 
     * @param int $schoolId
     * @param int $packageId
     * @return void
     */
    private function applyPackageToSchool(int $schoolId, int $packageId): void
    {
        $school = School::find($schoolId);
        $package = SubscriptionPackage::find($packageId);

        if (!$school || !$package) {
            Log::warning('midtrans_webhook_invalid_school_or_package', [
                'school_id' => $schoolId,
                'package_id' => $packageId,
            ]);
            return;
        }

        // Parse features
        $features = is_string($package->features) 
            ? json_decode($package->features, true) 
            : $package->features;

        // Update school with package features
        $school->update([
            'package_type' => $package->name,
            'max_students' => $features['max_students'] ?? $school->max_students,
            'max_teachers' => $features['max_teachers'] ?? $school->max_teachers,
            'max_classes' => $features['max_classes'] ?? $school->max_classes,
            'package_updated_at' => now(),
        ]);

        // Clear subscription cache
        \App\Http\Middleware\CheckActiveSubscription::clearCache($schoolId);

        Log::info('midtrans_webhook_package_applied', [
            'school_id' => $schoolId,
            'package_id' => $packageId,
            'package_name' => $package->name,
        ]);
    }
}
