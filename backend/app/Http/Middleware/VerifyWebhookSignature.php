<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRITICAL: HMAC Signature Verification Middleware
 * 
 * FIXES:
 * - Prevents fake webhook attacks
 * - Validates Midtrans signature before processing
 * - Timing attack protection with hash_equals
 * - Comprehensive security logging
 */
class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $provider = 'midtrans'): Response
    {
        // Skip verification in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        try {
            $isValid = match ($provider) {
                'midtrans' => $this->verifyMidtransSignature($request),
                'github' => $this->verifyGithubSignature($request),
                'stripe' => $this->verifyStripeSignature($request),
                default => throw new \InvalidArgumentException("Unsupported webhook provider: {$provider}")
            };

            if (!$isValid) {
                // CRITICAL: Log security violation
                Log::warning('Webhook signature verification failed', [
                    'provider' => $provider,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'timestamp' => now()->toISOString(),
                    'order_id' => $request->input('order_id'),
                    'transaction_id' => $request->input('transaction_id'),
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                    'message' => 'Webhook signature verification failed'
                ], 401);
            }

            // CRITICAL: Log successful verification
            Log::info('Webhook signature verified successfully', [
                'provider' => $provider,
                'ip' => $request->ip(),
                'order_id' => $request->input('order_id'),
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Webhook signature verification error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Signature verification failed',
                'message' => 'Unable to verify webhook signature'
            ], 500);
        }
    }

    /**
     * CRITICAL: Verify Midtrans HMAC Signature
     */
    private function verifyMidtransSignature(Request $request): bool
    {
        $serverKey = config('services.midtrans.server_key');
        $orderId = $request->input('order_id');
        $statusCode = $request->input('status_code');
        $grossAmount = $request->input('gross_amount');
        $receivedSignature = $request->input('signature_key');

        // CRITICAL: Validate required fields
        if (!$serverKey || !$orderId || !$statusCode || !$grossAmount || !$receivedSignature) {
            Log::warning('Midtrans webhook missing required fields', [
                'has_server_key' => !empty($serverKey),
                'has_order_id' => !empty($orderId),
                'has_status_code' => !empty($statusCode),
                'has_gross_amount' => !empty($grossAmount),
                'has_signature' => !empty($receivedSignature),
            ]);
            return false;
        }

        // CRITICAL: Generate expected signature using Midtrans algorithm
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        // CRITICAL: Use hash_equals to prevent timing attacks
        return hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * CRITICAL: Verify GitHub Webhook Signature (X-Hub-Signature-256)
     */
    private function verifyGithubSignature(Request $request): bool
    {
        $secret = config('services.github.webhook_secret');
        $signature = $request->header('X-Hub-Signature-256');
        $payload = $request->getContent();

        if (!$secret || !$signature || !$payload) {
            return false;
        }

        // Remove 'sha256=' prefix
        $signature = str_replace('sha256=', '', $signature);
        
        // Generate expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Use hash_equals to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * CRITICAL: Verify Stripe Webhook Signature
     */
    private function verifyStripeSignature(Request $request): bool
    {
        $secret = config('services.stripe.webhook_secret');
        $signature = $request->header('Stripe-Signature');
        $payload = $request->getContent();
        $timestamp = time();

        if (!$secret || !$signature || !$payload) {
            return false;
        }

        // Parse signature header
        $elements = explode(',', $signature);
        $signatureData = [];
        
        foreach ($elements as $element) {
            $parts = explode('=', $element, 2);
            if (count($parts) === 2) {
                $signatureData[$parts[0]] = $parts[1];
            }
        }

        if (!isset($signatureData['t']) || !isset($signatureData['v1'])) {
            return false;
        }

        $webhookTimestamp = (int) $signatureData['t'];
        $receivedSignature = $signatureData['v1'];

        // CRITICAL: Check timestamp tolerance (5 minutes)
        if (abs($timestamp - $webhookTimestamp) > 300) {
            Log::warning('Stripe webhook timestamp too old', [
                'webhook_timestamp' => $webhookTimestamp,
                'current_timestamp' => $timestamp,
                'difference' => abs($timestamp - $webhookTimestamp),
            ]);
            return false;
        }

        // Generate expected signature
        $signedPayload = $webhookTimestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        // Use hash_equals to prevent timing attacks
        return hash_equals($expectedSignature, $receivedSignature);
    }
}