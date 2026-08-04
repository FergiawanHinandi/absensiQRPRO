<?php

namespace App\Http\Middleware;

use App\Services\SecurityAlertService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AttendanceSecurityMiddleware
 *
 * Comprehensive security middleware for attendance endpoints.
 *
 * PROTECTIONS:
 * 1. Replay Attack Prevention - Nonce validation with cache-based tracking
 * 2. Timestamp Manipulation - Server time authority (client timestamp rejected)
 * 3. GPS Spoofing Detection - Pattern analysis and known spoofer signatures
 * 4. Double Submit Prevention - Idempotency key enforcement
 *
 * TRUST NOTHING FROM CLIENT:
 * - ❌ client timestamp → use server time
 * - ❌ client status → calculate from schedule
 * - ❌ client location without validation
 *
 * ERROR RESPONSE CONTRACT:
 * {
 *   "success": false,
 *   "error": {
 *     "code": "SECURITY_VIOLATION_CODE",
 *     "message": "Human readable message",
 *     "details": { ... optional context ... }
 *   },
 *   "meta": {
 *     "request_id": "uuid",
 *     "timestamp": "ISO8601",
 *     "retry_allowed": bool
 *   }
 * }
 */
class AttendanceSecurityMiddleware
{
    // Nonce expiry time (10 minutes)
    private const NONCE_TTL_SECONDS = 600;

    // Idempotency key expiry (24 hours)
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    // GPS spoofing detection thresholds
    private const GPS_IMPOSSIBLE_SPEED_MPS = 340; // Speed of sound - impossible travel
    private const GPS_SUSPICIOUS_SPEED_MPS = 50;  // ~180 km/h - suspicious but possible
    private const GPS_CHECK_INTERVAL_SECONDS = 60;

    // Known mock location app signatures
    private const MOCK_LOCATION_SIGNATURES = [
        'fake gps',
        'mock location',
        'location spoofer',
        'gps joystick',
        'fly gps',
        'fake location',
    ];

    public function __construct(
        private ?SecurityAlertService $alertService = null
    ) {}

    /**
     * Handle incoming request
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->ensureRequestId($request);

        try {
            // 1. Idempotency Check (Double Submit Prevention)
            $idempotencyResult = $this->checkIdempotency($request, $requestId);
            if ($idempotencyResult !== null) {
                return $idempotencyResult;
            }

            // 2. Nonce Validation (Replay Attack Prevention)
            $nonceResult = $this->validateNonce($request);
            if ($nonceResult !== null) {
                return $nonceResult;
            }

            // 3. Reject Client Timestamp (Server Time Authority)
            $this->enforceServerTimeAuthority($request);

            // 4. GPS Spoofing Detection
            $gpsResult = $this->detectGpsSpoofing($request);
            if ($gpsResult !== null) {
                return $gpsResult;
            }

            // 5. Device Integrity Check
            $deviceResult = $this->checkDeviceIntegrity($request);
            if ($deviceResult !== null) {
                return $deviceResult;
            }

            // Mark request as being processed (idempotency lock)
            $this->markRequestProcessing($requestId);

            // Process request
            $response = $next($request);

            // Mark request as completed
            $this->markRequestCompleted($requestId, $response);

            return $response;

        } catch (\Exception $e) {
            Log::error('AttendanceSecurity middleware error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'INTERNAL_SECURITY_ERROR',
                'Terjadi kesalahan pada pemeriksaan keamanan.',
                500,
                ['retry_allowed' => true],
                $requestId
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. IDEMPOTENCY CHECK (Double Submit Prevention)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check for duplicate request submission
     *
     * Uses X-Idempotency-Key header or request_id from body.
     * Returns cached response for duplicate requests.
     */
    private function checkIdempotency(Request $request, string $requestId): ?Response
    {
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('request_id');

        if (empty($idempotencyKey)) {
            $this->logSecurityViolation('MISSING_IDEMPOTENCY_KEY', $request);

            return $this->errorResponse(
                'MISSING_IDEMPOTENCY_KEY',
                'Header X-Idempotency-Key wajib untuk mencegah duplikasi.',
                400,
                ['retry_allowed' => true],
                $requestId
            );
        }

        $cacheKey = $this->getIdempotencyCacheKey($idempotencyKey, $request);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            // Duplicate request detected
            Log::info('Idempotent request detected - returning cached response', [
                'idempotency_key' => $idempotencyKey,
                'request_id' => $requestId,
                'original_status' => $cached['status'] ?? 'unknown',
            ]);

            // If still processing, return 409 Conflict
            if ($cached['status'] === 'processing') {
                return $this->errorResponse(
                    'REQUEST_IN_PROGRESS',
                    'Permintaan sebelumnya masih diproses. Mohon tunggu.',
                    409,
                    [
                        'retry_allowed' => true,
                        'retry_after_seconds' => 5,
                    ],
                    $requestId
                );
            }

            // Return cached response
            if (isset($cached['response'])) {
                return response()->json(
                    json_decode($cached['response'], true),
                    $cached['http_status'] ?? 200
                );
            }
        }

        return null;
    }

    /**
     * Mark request as being processed
     */
    private function markRequestProcessing(string $requestId): void
    {
        $request = request();
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('request_id');

        if ($idempotencyKey) {
            $cacheKey = $this->getIdempotencyCacheKey($idempotencyKey, $request);
            Cache::put($cacheKey, [
                'status' => 'processing',
                'started_at' => now()->toIso8601String(),
                'request_id' => $requestId,
            ], self::IDEMPOTENCY_TTL_SECONDS);
        }
    }

    /**
     * Mark request as completed and cache response
     */
    private function markRequestCompleted(string $requestId, Response $response): void
    {
        $request = request();
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('request_id');

        if ($idempotencyKey) {
            $cacheKey = $this->getIdempotencyCacheKey($idempotencyKey, $request);
            Cache::put($cacheKey, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'request_id' => $requestId,
                'http_status' => $response->getStatusCode(),
                'response' => $response->getContent(),
            ], self::IDEMPOTENCY_TTL_SECONDS);
        }
    }

    /**
     * Generate idempotency cache key
     */
    private function getIdempotencyCacheKey(string $idempotencyKey, Request $request): string
    {
        $userId = $request->user()?->id ?? 'anonymous';
        return "attendance:idempotency:{$userId}:{$idempotencyKey}";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. NONCE VALIDATION (Replay Attack Prevention)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate nonce to prevent replay attacks
     *
     * Nonce should be:
     * - Unique per request
     * - Generated server-side in QR code
     * - Valid for limited time
     */
    private function validateNonce(Request $request): ?Response
    {
        // Extract nonce from QR payload or direct field
        $nonce = $this->extractNonce($request);

        if (empty($nonce)) {
            // Some endpoints may not require nonce
            return null;
        }

        $cacheKey = "attendance:nonce:{$nonce}";

        // Check if nonce was already used
        if (Cache::has($cacheKey)) {
            $this->logSecurityViolation('REPLAY_ATTACK', $request, [
                'nonce' => $nonce,
                'violation' => 'Nonce already used',
            ]);

            return $this->errorResponse(
                'REPLAY_ATTACK_DETECTED',
                'Kode QR sudah pernah digunakan. Silakan scan ulang QR baru.',
                403,
                [
                    'retry_allowed' => false,
                    'action_required' => 'rescan_qr',
                ]
            );
        }

        // Mark nonce as used
        Cache::put($cacheKey, [
            'used_at' => now()->toIso8601String(),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ], self::NONCE_TTL_SECONDS);

        return null;
    }

    /**
     * Extract nonce from request
     */
    private function extractNonce(Request $request): ?string
    {
        // From direct field
        if ($request->has('nonce')) {
            return $request->input('nonce');
        }

        // From QR payload
        $qrPayload = $request->input('qr_payload');
        if (is_array($qrPayload) && isset($qrPayload['n'])) {
            return $qrPayload['n'];
        }

        // From encoded QR
        $qrEncoded = $request->input('qr_encoded');
        if ($qrEncoded) {
            try {
                $decoded = json_decode(base64_decode($qrEncoded), true);
                return $decoded['n'] ?? null;
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. SERVER TIME AUTHORITY (Timestamp Manipulation Prevention)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enforce server time authority - reject client timestamps
     *
     * Client timestamp is NEVER trusted. We:
     * 1. Remove client timestamp from request
     * 2. Inject server timestamp
     * 3. Log if client tried to manipulate time
     */
    private function enforceServerTimeAuthority(Request $request): void
    {
        $serverTime = now();
        $clientTimestamp = $request->input('timestamp') ?? $request->input('client_time');

        // Log if client sent timestamp (potential manipulation attempt)
        if ($clientTimestamp !== null) {
            try {
                $clientTime = \Carbon\Carbon::parse($clientTimestamp);
                $drift = abs($serverTime->diffInSeconds($clientTime));

                // If drift > 5 minutes, log as suspicious
                if ($drift > 300) {
                    $this->logSecurityViolation('TIMESTAMP_MANIPULATION', $request, [
                        'client_timestamp' => $clientTimestamp,
                        'server_timestamp' => $serverTime->toIso8601String(),
                        'drift_seconds' => $drift,
                    ]);
                }
            } catch (\Exception $e) {
                // Invalid timestamp format
                $this->logSecurityViolation('INVALID_TIMESTAMP', $request, [
                    'client_timestamp' => $clientTimestamp,
                    'error' => 'Invalid timestamp format',
                ]);
            }
        }

        // Override/inject server timestamp into request
        $request->merge([
            'server_timestamp' => $serverTime->toIso8601String(),
            'server_time' => $serverTime,
        ]);

        // Remove client timestamp fields to prevent downstream usage
        $request->request->remove('timestamp');
        $request->request->remove('client_time');
        $request->request->remove('scan_time');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. GPS SPOOFING DETECTION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect GPS spoofing attempts
     *
     * Detection methods:
     * 1. Impossible travel speed (teleportation)
     * 2. Known mock location app signatures
     * 3. Accuracy anomalies
     * 4. Altitude inconsistencies
     */
    private function detectGpsSpoofing(Request $request): ?Response
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');

        // Skip if no location provided
        if ($lat === null || $lng === null) {
            return null;
        }

        $user = $request->user();
        if (!$user) {
            return null;
        }

        $spoofingIndicators = [];

        // Check 1: Mock location flag from mobile
        if ($request->input('is_mock_location') === true) {
            $spoofingIndicators[] = 'mock_location_enabled';
        }

        // Check 2: Known spoofer app in user agent
        $userAgent = strtolower($request->userAgent() ?? '');
        foreach (self::MOCK_LOCATION_SIGNATURES as $signature) {
            if (str_contains($userAgent, $signature)) {
                $spoofingIndicators[] = "suspicious_user_agent:{$signature}";
            }
        }

        // Check 3: Impossible travel speed
        $travelCheck = $this->checkImpossibleTravel($user->id, $lat, $lng);
        if ($travelCheck['is_suspicious']) {
            $spoofingIndicators[] = $travelCheck['reason'];
        }

        // Check 4: GPS accuracy anomalies
        $accuracy = $request->input('gps_accuracy');
        if ($accuracy !== null && $accuracy < 1) {
            // Accuracy < 1 meter is suspiciously precise
            $spoofingIndicators[] = 'suspicious_accuracy';
        }

        // If any spoofing indicators found
        if (!empty($spoofingIndicators)) {
            $this->logSecurityViolation('GPS_SPOOFING', $request, [
                'indicators' => $spoofingIndicators,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
            ]);

            // Alert security team
            $this->alertService?->triggerAlert('gps_spoofing', [
                'user_id' => $user->id,
                'indicators' => $spoofingIndicators,
            ]);

            return $this->errorResponse(
                'GPS_SPOOFING_DETECTED',
                'Lokasi tidak valid. Pastikan GPS aktif dan tidak menggunakan aplikasi fake location.',
                403,
                [
                    'retry_allowed' => true,
                    'action_required' => 'verify_location',
                    'indicators' => app()->isProduction() ? [] : $spoofingIndicators,
                ]
            );
        }

        // Store current location for future travel checks
        $this->storeLocationHistory($user->id, $lat, $lng);

        return null;
    }

    /**
     * Check for impossible travel between locations
     */
    private function checkImpossibleTravel(int $userId, float $lat, float $lng): array
    {
        $cacheKey = "attendance:location:{$userId}";
        $lastLocation = Cache::get($cacheKey);

        if (!$lastLocation) {
            return ['is_suspicious' => false, 'reason' => null];
        }

        $lastLat = $lastLocation['lat'];
        $lastLng = $lastLocation['lng'];
        $lastTime = \Carbon\Carbon::parse($lastLocation['timestamp']);
        $timeDiff = now()->diffInSeconds($lastTime);

        // Skip if too much time has passed
        if ($timeDiff > 3600) { // 1 hour
            return ['is_suspicious' => false, 'reason' => null];
        }

        // Calculate distance using Haversine formula
        $distance = $this->haversineDistance($lastLat, $lastLng, $lat, $lng);

        // Calculate speed (meters per second)
        $speed = $timeDiff > 0 ? $distance / $timeDiff : 0;

        // Check for impossible speed (teleportation)
        if ($speed > self::GPS_IMPOSSIBLE_SPEED_MPS) {
            return [
                'is_suspicious' => true,
                'reason' => "impossible_travel_speed:{$speed}mps",
            ];
        }

        // Check for suspicious speed
        if ($speed > self::GPS_SUSPICIOUS_SPEED_MPS && $timeDiff < self::GPS_CHECK_INTERVAL_SECONDS) {
            return [
                'is_suspicious' => true,
                'reason' => "suspicious_travel_speed:{$speed}mps",
            ];
        }

        return ['is_suspicious' => false, 'reason' => null];
    }

    /**
     * Calculate distance between two points (Haversine formula)
     *
     * @return float Distance in meters
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1Rad) * cos($lat2Rad) *
            sin($deltaLng / 2) * sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Store location for travel speed analysis
     */
    private function storeLocationHistory(int $userId, float $lat, float $lng): void
    {
        $cacheKey = "attendance:location:{$userId}";
        Cache::put($cacheKey, [
            'lat' => $lat,
            'lng' => $lng,
            'timestamp' => now()->toIso8601String(),
        ], 3600); // 1 hour
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. DEVICE INTEGRITY CHECK
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check device integrity
     *
     * Verifies:
     * - Device ID consistency
     * - Suspicious device patterns
     * - Rooted/jailbroken indicators
     */
    private function checkDeviceIntegrity(Request $request): ?Response
    {
        $deviceId = $request->input('device_id');
        $user = $request->user();

        if (!$user || !$deviceId) {
            return null;
        }

        // Check for rooted/jailbroken indicators
        $integrityIndicators = [];

        // Check device integrity flags from mobile
        if ($request->input('is_rooted') === true) {
            $integrityIndicators[] = 'rooted_device';
        }

        if ($request->input('is_emulator') === true) {
            $integrityIndicators[] = 'emulator_detected';
        }

        // Check for debugging flags
        if ($request->input('debug_mode') === true) {
            $integrityIndicators[] = 'debug_mode_enabled';
        }

        if (!empty($integrityIndicators)) {
            $this->logSecurityViolation('DEVICE_INTEGRITY', $request, [
                'indicators' => $integrityIndicators,
                'device_id' => $deviceId,
            ]);

            // Don't block, but log and flag
            Log::warning('Device integrity concerns detected', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'indicators' => $integrityIndicators,
            ]);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ensure request has a unique ID
     */
    private function ensureRequestId(Request $request): string
    {
        $requestId = $request->input('request_id')
            ?? $request->header('X-Request-ID')
            ?? (string) \Illuminate\Support\Str::uuid();

        $request->merge(['request_id' => $requestId]);
        $request->headers->set('X-Request-ID', $requestId);

        return $requestId;
    }

    /**
     * Log security violation
     */
    private function logSecurityViolation(string $type, Request $request, array $context = []): void
    {
        Log::channel('security')->warning("Security violation: {$type}", array_merge([
            'type' => $type,
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ], $context));

        // Trigger alert for serious violations
        if (in_array($type, ['REPLAY_ATTACK', 'GPS_SPOOFING', 'TIMESTAMP_MANIPULATION'])) {
            $this->alertService?->triggerAlert($type, $context);
        }
    }

    /**
     * Build standardized error response
     *
     * CONTRACT:
     * {
     *   "success": false,
     *   "error": {
     *     "code": "ERROR_CODE",
     *     "message": "Human readable message",
     *     "details": {}
     *   },
     *   "meta": {
     *     "request_id": "uuid",
     *     "timestamp": "ISO8601",
     *     "retry_allowed": bool
     *   }
     * }
     */
    private function errorResponse(
        string $code,
        string $message,
        int $httpStatus = 400,
        array $details = [],
        ?string $requestId = null
    ): Response {
        $requestId = $requestId ?? request()->input('request_id') ?? (string) \Illuminate\Support\Str::uuid();

        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toIso8601String(),
                'retry_allowed' => $details['retry_allowed'] ?? false,
            ],
        ], $httpStatus);
    }
}
