<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * QR Code Generator Service
 * 
 * Generates secure QR codes with HMAC signature
 * 
 * SECURITY FEATURES:
 * 1. ✅ HMAC-SHA256 signature
 * 2. ✅ 60-second expiration
 * 3. ✅ Unique idempotency key per QR
 * 4. ✅ School isolation
 * 
 * @version 2.0.0 - Security Hardened
 */
class QRCodeGeneratorService
{
    /**
     * Generate secure QR payload for attendance
     * 
     * @param int $scheduleId
     * @param int $schoolId
     * @param int $expirySeconds Default: 60 seconds
     * @return array
     */
    public function generateAttendanceQR(int $scheduleId, int $schoolId, int $expirySeconds = 60): array
    {
        // Create payload data
        $data = [
            'schedule_id' => $scheduleId,
            'school_id' => $schoolId,
            'expires_at' => Carbon::now()->addSeconds($expirySeconds)->toIso8601String(),
            'idempotency_key' => Str::uuid()->toString(),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];

        // Generate HMAC signature
        $signature = hash_hmac(
            'sha256',
            json_encode($data),
            config('app.key')
        );

        return [
            'data' => $data,
            'signature' => $signature,
        ];
    }

    /**
     * Generate QR code as JSON string (for mobile/web)
     * 
     * @param int $scheduleId
     * @param int $schoolId
     * @param int $expirySeconds
     * @return string
     */
    public function generateQRString(int $scheduleId, int $schoolId, int $expirySeconds = 60): string
    {
        $payload = $this->generateAttendanceQR($scheduleId, $schoolId, $expirySeconds);
        return json_encode($payload);
    }

    /**
     * Verify QR signature (for testing)
     * 
     * @param array $payload
     * @return bool
     */
    public function verifySignature(array $payload): bool
    {
        if (!isset($payload['signature']) || !isset($payload['data'])) {
            return false;
        }

        $expectedSignature = hash_hmac(
            'sha256',
            json_encode($payload['data']),
            config('app.key')
        );

        return hash_equals($expectedSignature, $payload['signature']);
    }

    /**
     * Check if QR is expired
     * 
     * @param array $payload
     * @return bool
     */
    public function isExpired(array $payload): bool
    {
        if (!isset($payload['data']['expires_at'])) {
            return true;
        }

        $expiresAt = Carbon::parse($payload['data']['expires_at']);
        return Carbon::now()->greaterThan($expiresAt);
    }
}
