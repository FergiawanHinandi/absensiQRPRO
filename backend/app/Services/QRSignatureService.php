<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * QR Signature Service - Cryptographic Signature Validation & Anti-Replay Protection
 *
 * SECURITY FEATURES:
 * - HMAC-SHA256 signature validation using app.key
 * - 10-second expiration window (configurable)
 * - Timing-safe signature comparison (hash_equals)
 * - ONE-TIME USE: Each QR signature can only be used once (anti-replay)
 * - Atomic cache operations to prevent race conditions
 * - Comprehensive security logging
 *
 * @author Security Team
 * @version 2.0.0 - Added anti-replay protection
 */
final class QRSignatureService
{
    /**
     * Default QR expiration time in seconds
     */
    private const DEFAULT_EXPIRATION_SECONDS = 10;

    /**
     * Minimum acceptable expiration time (seconds)
     */
    private const MIN_EXPIRATION_SECONDS = 5;

    /**
     * Maximum acceptable expiration time (seconds)
     */
    private const MAX_EXPIRATION_SECONDS = 60;

    /**
     * Buffer time to keep used signatures in cache (seconds)
     * This prevents replay even if clocks are slightly skewed
     */
    private const REPLAY_PROTECTION_BUFFER_SECONDS = 5;

    /**
     * Cache key prefix for used QR signatures
     */
    private const CACHE_PREFIX = 'qr_used:';

    /**
     * The secret key used for HMAC signing
     */
    private string $secretKey;

    /**
     * Expiration window in seconds
     */
    private int $expirationSeconds;

    public function __construct()
    {
        $this->secretKey = config('app.key');
        $this->expirationSeconds = config(
            'qr.signature_expiration_seconds',
            self::DEFAULT_EXPIRATION_SECONDS
        );

        // Ensure expiration is within bounds
        $this->expirationSeconds = max(
            self::MIN_EXPIRATION_SECONDS,
            min(self::MAX_EXPIRATION_SECONDS, $this->expirationSeconds)
        );
    }

    /**
     * Generate a signed QR payload
     *
     * @param int $studentId The student's database ID
     * @param string $nisn The student's unique identifier (NISN)
     * @param int|null $timestamp Optional timestamp (defaults to current)
     * @return array Contains payload and full QR data
     */
    public function generateSignedPayload(
        int $studentId,
        string $nisn,
        ?int $timestamp = null
    ): array {
        $timestamp = $timestamp ?? now()->timestamp;

        // Generate HMAC signature
        $signature = $this->generateSignature($studentId, $nisn, $timestamp);

        $payload = [
            'student_id' => $studentId,
            'nisn' => $nisn,
            'generated_at' => $timestamp,
            'signature' => $signature,
        ];

        // Also return encoded string for QR code generation
        $encoded = base64_encode(json_encode($payload));

        return [
            'payload' => $payload,
            'encoded' => $encoded,
            'expires_at' => Carbon::createFromTimestamp($timestamp)
                ->addSeconds($this->expirationSeconds)
                ->toIso8601String(),
            'valid_for_seconds' => $this->expirationSeconds,
        ];
    }

    /**
     * Verify a QR payload signature and expiration
     *
     * @param array $payload The decoded QR payload
     * @param bool $markAsUsed Whether to mark this QR as used (default: true)
     * @return array Contains verification result and student info
     * @throws \Exception If verification fails
     */
    public function verifyPayload(array $payload, bool $markAsUsed = true): array
    {
        // Step 1: Validate required fields
        $this->validateRequiredFields($payload);

        $studentId = $payload['student_id'];
        $nisn = $payload['nisn'];
        $timestamp = $payload['generated_at'];
        $providedSignature = $payload['signature'];

        // Step 2: Check expiration FIRST (fail fast on expired QR)
        $expirationResult = $this->checkExpiration($timestamp);
        if (!$expirationResult['valid']) {
            $this->logFailedAttempt('qr_expired', [
                'student_id' => $studentId,
                'nisn' => $nisn,
                'generated_at' => $timestamp,
                'age_seconds' => $expirationResult['age_seconds'],
                'max_age_seconds' => $this->expirationSeconds,
            ]);

            throw new \Exception(
                "QR Code sudah kadaluarsa. Berlaku hanya {$this->expirationSeconds} detik."
            );
        }

        // Step 3: Re-calculate signature
        $expectedSignature = $this->generateSignature($studentId, $nisn, $timestamp);

        // Step 4: Timing-safe signature comparison
        if (!hash_equals($expectedSignature, $providedSignature)) {
            $this->logFailedAttempt('invalid_signature', [
                'student_id' => $studentId,
                'nisn' => $nisn,
                'generated_at' => $timestamp,
                'reason' => 'HMAC signature mismatch',
            ]);

            throw new \Exception('Tanda tangan QR tidak valid. QR mungkin telah dimodifikasi.');
        }

        // Step 5: ANTI-REPLAY PROTECTION - Ensure one-time use
        if ($markAsUsed) {
            $signatureHash = $this->getSignatureHash($providedSignature);
            
            if (!$this->markSignatureAsUsedAtomic($signatureHash, $timestamp)) {
                // This is a replay attack!
                $this->logReplayAttack($studentId, $nisn, $timestamp, $signatureHash);
                
                throw new \Exception(
                    'QR Code ini sudah digunakan. Setiap QR hanya dapat digunakan sekali.'
                );
            }
        }

        // Step 6: All checks passed
        $this->logSuccessfulVerification($studentId, $nisn);

        return [
            'valid' => true,
            'student_id' => $studentId,
            'nisn' => $nisn,
            'generated_at' => Carbon::createFromTimestamp($timestamp)->toIso8601String(),
            'age_seconds' => $expirationResult['age_seconds'],
        ];
    }

    /**
     * Mark a signature as used atomically (prevents race conditions)
     *
     * Uses Redis SETNX (SET if Not eXists) semantics via Cache::add()
     * This is atomic and prevents two concurrent requests from both succeeding.
     *
     * @param string $signatureHash Hash of the signature
     * @param int $qrTimestamp Original QR generation timestamp
     * @return bool True if successfully marked (first use), false if already used (replay)
     */
    private function markSignatureAsUsedAtomic(string $signatureHash, int $qrTimestamp): bool
    {
        $cacheKey = self::CACHE_PREFIX . $signatureHash;
        
        // Calculate TTL: time until QR expires + buffer
        // We keep the key in cache until after the QR would expire anyway
        $qrExpiresAt = $qrTimestamp + $this->expirationSeconds;
        $now = now()->timestamp;
        $remainingValidity = max(0, $qrExpiresAt - $now);
        $ttl = $remainingValidity + self::REPLAY_PROTECTION_BUFFER_SECONDS;
        
        // Minimum TTL to prevent edge cases
        $ttl = max($ttl, self::REPLAY_PROTECTION_BUFFER_SECONDS);
        
        // Cache::add() returns true only if key did not exist (atomic SETNX)
        // Returns false if key already exists (replay attempt)
        $usageData = [
            'first_used_at' => now()->toIso8601String(),
            'ip_address' => request()?->ip(),
            'user_agent' => substr(request()?->userAgent() ?? '', 0, 100),
        ];
        
        return Cache::add($cacheKey, $usageData, $ttl);
    }

    /**
     * Check if a signature has already been used
     *
     * @param string $providedSignature The signature to check
     * @return bool True if already used
     */
    public function isSignatureUsed(string $providedSignature): bool
    {
        $signatureHash = $this->getSignatureHash($providedSignature);
        $cacheKey = self::CACHE_PREFIX . $signatureHash;
        
        return Cache::has($cacheKey);
    }

    /**
     * Get usage information for a signature (for debugging/audit)
     *
     * @param string $providedSignature The signature to check
     * @return array|null Usage data or null if not used
     */
    public function getSignatureUsageInfo(string $providedSignature): ?array
    {
        $signatureHash = $this->getSignatureHash($providedSignature);
        $cacheKey = self::CACHE_PREFIX . $signatureHash;
        
        return Cache::get($cacheKey);
    }

    /**
     * Generate a hash of the signature for cache key
     * Using SHA256 to shorten the key while maintaining uniqueness
     *
     * @param string $signature The HMAC signature
     * @return string Hashed signature for cache key
     */
    private function getSignatureHash(string $signature): string
    {
        // The signature is already an HMAC, but we hash it again
        // to ensure consistent key length and avoid special characters
        return hash('sha256', $signature);
    }

    /**
     * Log replay attack attempt as CRITICAL security event
     *
     * @param int $studentId Student ID from the QR
     * @param string $nisn NISN from the QR
     * @param int $timestamp Original QR timestamp
     * @param string $signatureHash Hash of the replayed signature
     */
    private function logReplayAttack(
        int $studentId,
        string $nisn,
        int $timestamp,
        string $signatureHash
    ): void {
        $cacheKey = self::CACHE_PREFIX . $signatureHash;
        $firstUsage = Cache::get($cacheKey);
        
        Log::channel('security_json')->critical('QR REPLAY ATTACK DETECTED', [
            'event' => 'security.replay_attack',
            'severity' => 'CRITICAL',
            'is_security_event' => true,
            'student_id' => $studentId,
            'nisn' => substr($nisn, 0, 4) . '****',
            'qr_generated_at' => Carbon::createFromTimestamp($timestamp)->toIso8601String(),
            'signature_hash' => substr($signatureHash, 0, 16) . '...',
            'first_usage' => $firstUsage,
            'replay_attempt' => [
                'ip_address' => request()?->ip(),
                'user_agent' => substr(request()?->userAgent() ?? '', 0, 100),
                'device_id' => request()?->header('X-Device-ID'),
                'timestamp' => now()->toIso8601String(),
            ],
            'recommendation' => 'Investigate if same QR was shared or captured',
        ]);

        // Also log to attendance security channel
        Log::channel('attendance_security')->critical('QR Replay Attack', [
            'student_id' => $studentId,
            'nisn' => substr($nisn, 0, 4) . '****',
            'first_used_at' => $firstUsage['first_used_at'] ?? 'unknown',
            'first_used_ip' => $firstUsage['ip_address'] ?? 'unknown',
            'replay_ip' => request()?->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Verify from encoded string (base64 JSON)
     *
     * @param string $encodedPayload Base64 encoded JSON payload
     * @return array Verification result
     * @throws \Exception If verification fails
     */
    public function verifyEncodedPayload(string $encodedPayload): array
    {
        // Decode base64
        $decoded = base64_decode($encodedPayload, true);

        if ($decoded === false) {
            $this->logFailedAttempt('invalid_encoding', [
                'reason' => 'Base64 decode failed',
                'payload_snippet' => substr($encodedPayload, 0, 20) . '...',
            ]);

            throw new \Exception('Format QR tidak valid.');
        }

        // Parse JSON
        $payload = json_decode($decoded, true);

        if (!is_array($payload)) {
            $this->logFailedAttempt('invalid_json', [
                'reason' => 'JSON parse failed',
            ]);

            throw new \Exception('Data QR tidak dapat dibaca.');
        }

        return $this->verifyPayload($payload);
    }

    /**
     * Generate HMAC-SHA256 signature
     *
     * @param int $studentId Student ID
     * @param string $nisn NISN
     * @param int $timestamp Unix timestamp
     * @return string HMAC signature
     */
    public function generateSignature(int $studentId, string $nisn, int $timestamp): string
    {
        // Build canonical string: student_id|nisn|timestamp
        $dataToSign = "{$studentId}|{$nisn}|{$timestamp}";

        return hash_hmac('sha256', $dataToSign, $this->secretKey);
    }

    /**
     * Check if timestamp is within expiration window
     *
     * @param int $timestamp The QR generation timestamp
     * @return array Contains validity and age information
     */
    private function checkExpiration(int $timestamp): array
    {
        $now = now()->timestamp;
        $ageSeconds = $now - $timestamp;

        // Check if QR is from the future (clock skew protection)
        if ($ageSeconds < -5) {
            return [
                'valid' => false,
                'reason' => 'future_timestamp',
                'age_seconds' => $ageSeconds,
            ];
        }

        // Check if QR has expired
        if ($ageSeconds > $this->expirationSeconds) {
            return [
                'valid' => false,
                'reason' => 'expired',
                'age_seconds' => $ageSeconds,
            ];
        }

        return [
            'valid' => true,
            'age_seconds' => $ageSeconds,
        ];
    }

    /**
     * Validate that all required fields are present
     *
     * @param array $payload The payload to validate
     * @throws \Exception If required fields are missing
     */
    private function validateRequiredFields(array $payload): void
    {
        $requiredFields = ['student_id', 'nisn', 'generated_at', 'signature'];

        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            $this->logFailedAttempt('missing_fields', [
                'missing' => $missingFields,
            ]);

            throw new \Exception('Format QR tidak lengkap. Field hilang: ' . implode(', ', $missingFields));
        }

        // Validate types
        if (!is_int($payload['student_id']) && !ctype_digit((string) $payload['student_id'])) {
            throw new \Exception('Format student_id tidak valid.');
        }

        if (!is_int($payload['generated_at']) && !ctype_digit((string) $payload['generated_at'])) {
            throw new \Exception('Format timestamp tidak valid.');
        }
    }

    /**
     * Log failed verification attempt to security channel
     *
     * @param string $type The type of failure
     * @param array $context Additional context
     */
    private function logFailedAttempt(string $type, array $context): void
    {
        Log::channel('attendance_security')->warning(
            "QR Verification Failed: {$type}",
            array_merge($context, [
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'timestamp' => now()->toIso8601String(),
                'severity' => $this->getSeverity($type),
            ])
        );
    }

    /**
     * Log successful verification
     *
     * @param int $studentId Student ID
     * @param string $nisn NISN
     */
    private function logSuccessfulVerification(int $studentId, string $nisn): void
    {
        Log::channel('attendance_security')->info(
            'QR Verification Success',
            [
                'student_id' => $studentId,
                'nisn' => substr($nisn, 0, 4) . '****', // Mask NISN for privacy
                'ip_address' => request()?->ip(),
                'timestamp' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get severity level for different failure types
     *
     * @param string $type Failure type
     * @return string Severity level
     */
    private function getSeverity(string $type): string
    {
        return match ($type) {
            'invalid_signature' => 'CRITICAL',  // Possible tampering
            'replay_attack' => 'CRITICAL',      // QR reuse attempt
            'future_timestamp' => 'HIGH',       // Possible manipulation
            'expired' => 'LOW',                 // Normal expiration
            'qr_expired' => 'LOW',
            'missing_fields' => 'MEDIUM',
            'invalid_encoding' => 'MEDIUM',
            'invalid_json' => 'MEDIUM',
            default => 'MEDIUM',
        };
    }

    /**
     * Get the current expiration window in seconds
     *
     * @return int Expiration seconds
     */
    public function getExpirationSeconds(): int
    {
        return $this->expirationSeconds;
    }

    /**
     * Create a QR payload for a student (convenience method)
     *
     * @param \App\Models\User $student The student model
     * @return array The generated payload data
     */
    public function createForStudent(\App\Models\User $student): array
    {
        // Get NISN from student profile or use username as fallback
        $nisn = $student->profile?->nisn
            ?? $student->studentProfile?->nisn
            ?? $student->nisn
            ?? $student->username;

        return $this->generateSignedPayload(
            $student->id,
            $nisn
        );
    }
}
