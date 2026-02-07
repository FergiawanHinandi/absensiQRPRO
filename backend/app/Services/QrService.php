<?php

namespace App\Services;

use App\DTOs\QrPayload;
use App\Exceptions\InvalidQrException;
use App\Exceptions\QrExpiredException;
use Illuminate\Support\Str;

/**
 * QR Code Service (Stateless, Cryptographic Only)
 *
 * RULES (DO NOT VIOLATE):
 * - NO database access
 * - NO business logic
 * - ONLY cryptographic operations
 * - Uses HMAC-SHA256, NOT Laravel encrypt()
 *
 * Note: Expiry and max age settings are now configurable via SecurityPolicyService
 */
final class QrService
{
    protected ?SecurityPolicyService $policyService = null;

    /**
     * Get policy service (lazy loaded to avoid circular dependency)
     */
    protected function getPolicyService(): SecurityPolicyService
    {
        if ($this->policyService === null) {
            $this->policyService = app(SecurityPolicyService::class);
        }

        return $this->policyService;
    }

    /**
     * Generate stateless HMAC-signed QR token
     *
     * @param  array  $data  Must contain: schedule_id, qr_id, type
     * @param  int|null  $schoolId  Optional school ID for school-specific expiry
     * @return string Base64 encoded payload + HMAC signature
     */
    public function generate(array $data, ?int $schoolId = null): string
    {
        // Get expiry from policy service (configurable per school) or fall back to config
        $expiryMinutes =
            $this->getPolicyService()->getQrExpiryMinutes($schoolId) ??
            config('qr.expiry_minutes', 10);

        $payload = [
            'sid' => $data['schedule_id'],
            'qid' => $data['qr_id'],
            'typ' => $data['type'], // 'in' | 'out'
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes($expiryMinutes)->timestamp,
            'nonce' => Str::random(16),
            'v' => 1,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        // Format: payload.signature
        return $encoded.'.'.$signature;
    }

    /**
     * Validate QR token (stateless, no database lookup)
     *
     * @param  string  $token  Format: base64(payload).signature
     * @param  int|null  $schoolId  Optional school ID for school-specific settings
     * @return QrPayload Decoded and validated payload
     *
     * @throws InvalidQrException
     * @throws QrExpiredException
     */
    public function validate(string $token, ?int $schoolId = null): QrPayload
    {
        // 1. Check format
        if (! str_contains($token, '.')) {
            throw new InvalidQrException('Invalid token format');
        }

        [$encoded, $signature] = explode('.', $token, 2);

        // 2. Verify HMAC signature (TIMING ATTACK PROTECTION)
        $expectedSignature = hash_hmac('sha256', $encoded, config('qr.secret'));

        if (! hash_equals($expectedSignature, $signature)) {
            throw new InvalidQrException('Invalid signature');
        }

        // 3. Decode payload
        $payload = json_decode(base64_decode($encoded), true);

        if (! $payload || ! is_array($payload)) {
            throw new InvalidQrException('Invalid payload');
        }

        // 4. Check required fields (nonce is mandatory for replay protection)
        $requiredFields = ['sid', 'qid', 'typ', 'iat', 'exp', 'nonce'];
        foreach ($requiredFields as $field) {
            if (empty($payload[$field])) {
                throw new InvalidQrException(
                    "Missing or empty field: {$field}",
                );
            }
        }

        // 5. Check expiry (CRITICAL: Prevent expired QR usage)
        if ($payload['exp'] < now()->timestamp) {
            throw new QrExpiredException('QR code expired');
        }

        // 6. Check not too old (anti-replay, prevent very old tokens)
        // Get max age from policy service (configurable per school)
        $maxAgeHours =
            $this->getPolicyService()->get('qr.max_age_hours', $schoolId) ??
            config('qr.max_age_hours', 24);
        $maxAge = $maxAgeHours * 3600;

        if ($payload['iat'] < now()->timestamp - $maxAge) {
            throw new InvalidQrException('QR code too old');
        }

        // 7. CRITICAL: Strict nonce validation to prevent replay attacks
        $cacheKey = 'qr_nonce:'.$payload['nonce'];
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            throw new InvalidQrException(
                'QR code already used (replay attack detected)',
            );
        }

        // Get nonce TTL from policy service (configurable per school)
        $nonceTtl =
            $this->getPolicyService()->get('qr.nonce_ttl_seconds', $schoolId) ??
            max(60, $payload['exp'] - now()->timestamp);

        // Store nonce for replay protection
        \Illuminate\Support\Facades\Cache::put($cacheKey, true, $nonceTtl);

        return new QrPayload($payload);
    }
}
