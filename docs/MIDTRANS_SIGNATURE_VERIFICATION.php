<?php

/**
 * MIDTRANS SIGNATURE VERIFICATION - Detailed Logic
 * 
 * This file explains the signature verification process for Midtrans webhooks
 * to ensure authenticity and prevent tampering.
 */

namespace App\Services\Payment;

use Illuminate\Support\Facades\Log;

class MidtransSignatureVerifier
{
    /**
     * Verify Midtrans webhook signature
     * 
     * ALGORITHM:
     * 1. Concatenate: order_id + status_code + gross_amount + server_key
     * 2. Hash using SHA512
     * 3. Compare with signature_key from webhook
     * 
     * SECURITY:
     * - Uses timing-safe comparison (hash_equals)
     * - Prevents timing attacks
     * - Server key never exposed in logs
     * 
     * @param array $webhookData
     * @return bool
     */
    public static function verify(array $webhookData): bool
    {
        // ============================================================
        // STEP 1: EXTRACT REQUIRED FIELDS
        // ============================================================
        
        $orderId = $webhookData['order_id'] ?? null;
        $statusCode = $webhookData['status_code'] ?? null;
        $grossAmount = $webhookData['gross_amount'] ?? null;
        $signatureKey = $webhookData['signature_key'] ?? null;

        // Validate all fields are present
        if (!$orderId || !$statusCode || !$grossAmount || !$signatureKey) {
            Log::warning('midtrans_signature_missing_fields', [
                'has_order_id' => !empty($orderId),
                'has_status_code' => !empty($statusCode),
                'has_gross_amount' => !empty($grossAmount),
                'has_signature_key' => !empty($signatureKey),
            ]);
            return false;
        }

        // ============================================================
        // STEP 2: GET SERVER KEY FROM CONFIG
        // ============================================================
        
        $serverKey = config('services.midtrans.server_key');
        
        if (!$serverKey) {
            Log::error('midtrans_server_key_not_configured');
            return false;
        }

        // ============================================================
        // STEP 3: BUILD SIGNATURE STRING
        // ============================================================
        // Format: order_id + status_code + gross_amount + server_key
        // Example: "ORDER-123" + "200" + "100000.00" + "SB-Mid-server-xxx"
        
        $signatureString = $orderId . $statusCode . $grossAmount . $serverKey;

        // ============================================================
        // STEP 4: HASH USING SHA512
        // ============================================================
        
        $expectedSignature = hash('sha512', $signatureString);

        // ============================================================
        // STEP 5: TIMING-SAFE COMPARISON
        // ============================================================
        // Use hash_equals() to prevent timing attacks
        // DO NOT use === or strcmp() for security-sensitive comparisons
        
        $isValid = hash_equals($expectedSignature, $signatureKey);

        // ============================================================
        // STEP 6: LOG VERIFICATION RESULT
        // ============================================================
        
        if ($isValid) {
            Log::info('midtrans_signature_valid', [
                'order_id' => $orderId,
            ]);
        } else {
            Log::warning('midtrans_signature_invalid', [
                'order_id' => $orderId,
                'expected_prefix' => substr($expectedSignature, 0, 16) . '...',
                'received_prefix' => substr($signatureKey, 0, 16) . '...',
            ]);
        }

        return $isValid;
    }

    /**
     * EXAMPLE: Manual signature calculation for testing
     * 
     * @param string $orderId
     * @param string $statusCode
     * @param string $grossAmount
     * @return string
     */
    public static function calculateSignature(
        string $orderId,
        string $statusCode,
        string $grossAmount
    ): string {
        $serverKey = config('services.midtrans.server_key');
        
        $signatureString = $orderId . $statusCode . $grossAmount . $serverKey;
        
        return hash('sha512', $signatureString);
    }

    /**
     * EXAMPLE: Verify signature with detailed logging
     * 
     * @param array $webhookData
     * @return array
     */
    public static function verifyWithDetails(array $webhookData): array
    {
        $orderId = $webhookData['order_id'] ?? null;
        $statusCode = $webhookData['status_code'] ?? null;
        $grossAmount = $webhookData['gross_amount'] ?? null;
        $signatureKey = $webhookData['signature_key'] ?? null;
        $serverKey = config('services.midtrans.server_key');

        // Build signature string
        $signatureString = $orderId . $statusCode . $grossAmount . $serverKey;
        $expectedSignature = hash('sha512', $signatureString);
        
        // Compare
        $isValid = hash_equals($expectedSignature, $signatureKey);

        return [
            'valid' => $isValid,
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_string_length' => strlen($signatureString),
            'expected_signature_prefix' => substr($expectedSignature, 0, 16) . '...',
            'received_signature_prefix' => substr($signatureKey, 0, 16) . '...',
            'signatures_match' => $expectedSignature === $signatureKey,
        ];
    }
}

/**
 * ============================================================
 * EXAMPLE USAGE
 * ============================================================
 */

// Example 1: Basic verification
$webhookData = [
    'order_id' => 'ORDER-12345',
    'status_code' => '200',
    'gross_amount' => '100000.00',
    'signature_key' => 'abc123...',
];

$isValid = MidtransSignatureVerifier::verify($webhookData);

if ($isValid) {
    // Process webhook
} else {
    // Reject webhook
}

// Example 2: Calculate signature for testing
$expectedSignature = MidtransSignatureVerifier::calculateSignature(
    'ORDER-12345',
    '200',
    '100000.00'
);

// Example 3: Detailed verification
$details = MidtransSignatureVerifier::verifyWithDetails($webhookData);
print_r($details);

/**
 * ============================================================
 * SIGNATURE CALCULATION EXAMPLES
 * ============================================================
 */

// Example 1: Successful payment
$orderId = 'ORDER-12345';
$statusCode = '200';
$grossAmount = '100000.00';
$serverKey = 'SB-Mid-server-xxxxxxxx';

$signatureString = $orderId . $statusCode . $grossAmount . $serverKey;
// Result: "ORDER-12345200100000.00SB-Mid-server-xxxxxxxx"

$signature = hash('sha512', $signatureString);
// Result: "a1b2c3d4e5f6..." (128 characters)

// Example 2: Pending payment
$orderId = 'ORDER-67890';
$statusCode = '201';
$grossAmount = '250000.00';
$serverKey = 'SB-Mid-server-xxxxxxxx';

$signatureString = $orderId . $statusCode . $grossAmount . $serverKey;
// Result: "ORDER-67890201250000.00SB-Mid-server-xxxxxxxx"

$signature = hash('sha512', $signatureString);

/**
 * ============================================================
 * COMMON PITFALLS
 * ============================================================
 */

// ❌ WRONG: Using === for comparison (vulnerable to timing attacks)
if ($expectedSignature === $signatureKey) {
    // Vulnerable!
}

// ✅ CORRECT: Using hash_equals() (timing-safe)
if (hash_equals($expectedSignature, $signatureKey)) {
    // Secure!
}

// ❌ WRONG: Logging full server key
Log::info('Server key: ' . $serverKey); // NEVER DO THIS!

// ✅ CORRECT: Logging signature prefix only
Log::info('Signature prefix: ' . substr($signature, 0, 16) . '...');

// ❌ WRONG: Incorrect concatenation order
$signatureString = $serverKey . $orderId . $statusCode . $grossAmount; // WRONG ORDER!

// ✅ CORRECT: Correct concatenation order
$signatureString = $orderId . $statusCode . $grossAmount . $serverKey;

// ❌ WRONG: Using MD5 or SHA1 (weak algorithms)
$signature = md5($signatureString); // Too weak!
$signature = sha1($signatureString); // Still weak!

// ✅ CORRECT: Using SHA512 (strong algorithm)
$signature = hash('sha512', $signatureString);

/**
 * ============================================================
 * TESTING SIGNATURE VERIFICATION
 * ============================================================
 */

// Test 1: Valid signature
$testData = [
    'order_id' => 'TEST-001',
    'status_code' => '200',
    'gross_amount' => '50000.00',
    'signature_key' => MidtransSignatureVerifier::calculateSignature('TEST-001', '200', '50000.00'),
];

assert(MidtransSignatureVerifier::verify($testData) === true);

// Test 2: Invalid signature (tampered amount)
$testData = [
    'order_id' => 'TEST-001',
    'status_code' => '200',
    'gross_amount' => '1.00', // Tampered!
    'signature_key' => MidtransSignatureVerifier::calculateSignature('TEST-001', '200', '50000.00'),
];

assert(MidtransSignatureVerifier::verify($testData) === false);

// Test 3: Missing signature
$testData = [
    'order_id' => 'TEST-001',
    'status_code' => '200',
    'gross_amount' => '50000.00',
    // Missing signature_key
];

assert(MidtransSignatureVerifier::verify($testData) === false);

/**
 * ============================================================
 * PRODUCTION CONFIGURATION
 * ============================================================
 */

// config/services.php
return [
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
        'is_3ds' => env('MIDTRANS_IS_3DS', true),
    ],
];

// .env
/*
MIDTRANS_SERVER_KEY=SB-Mid-server-xxxxxxxx
MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxxxxxx
MIDTRANS_IS_PRODUCTION=false
MIDTRANS_IS_SANITIZED=true
MIDTRANS_IS_3DS=true
*/

/**
 * ============================================================
 * SECURITY CHECKLIST
 * ============================================================
 */

// ✅ Use hash_equals() for comparison
// ✅ Use SHA512 for hashing
// ✅ Never log server key
// ✅ Validate all required fields
// ✅ Use timing-safe comparison
// ✅ Verify signature before processing
// ✅ Log verification failures
// ✅ Use HTTPS for webhook endpoint
// ✅ Implement rate limiting
// ✅ Implement idempotency
