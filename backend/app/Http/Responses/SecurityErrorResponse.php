<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/**
 * SecurityErrorResponse - Standardized Error Contract
 *
 * STANDARD ERROR RESPONSE FORMAT:
 * {
 *   "success": false,
 *   "error": {
 *     "code": "ERROR_CODE_CONSTANT",
 *     "message": "Human readable message in Indonesian",
 *     "details": {
 *       "field": "Optional field-specific error",
 *       "action_required": "What user should do",
 *       ...
 *     }
 *   },
 *   "meta": {
 *     "request_id": "uuid-v4",
 *     "timestamp": "2026-02-07T10:30:00+07:00",
 *     "retry_allowed": true|false,
 *     "retry_after_seconds": 30  // Optional
 *   }
 * }
 *
 * ERROR CODES:
 * - REPLAY_ATTACK_DETECTED     - QR code/nonce already used
 * - GPS_SPOOFING_DETECTED      - Fake location detected
 * - TIMESTAMP_MANIPULATION     - Client timestamp drift
 * - REQUEST_IN_PROGRESS        - Duplicate request being processed
 * - IDEMPOTENCY_CONFLICT       - Same request_id with different data
 * - RATE_LIMIT_EXCEEDED        - Too many requests
 * - DEVICE_NOT_REGISTERED      - Unknown device
 * - DEVICE_MISMATCH            - Device ID changed
 * - SESSION_EXPIRED            - Auth token expired
 * - GEOFENCE_VIOLATION         - Outside allowed area
 * - SCHEDULE_NOT_ACTIVE        - No active class schedule
 * - ALREADY_CHECKED_IN         - Duplicate attendance
 * - INVALID_QR_SIGNATURE       - QR tampering detected
 * - QR_EXPIRED                 - QR code timeout
 * - INTERNAL_ERROR             - Server error
 */
class SecurityErrorResponse implements Responsable
{
    // ─────────────────────────────────────────────────────────────────────
    // ERROR CODE CONSTANTS
    // ─────────────────────────────────────────────────────────────────────

    // Replay & Nonce Errors
    public const REPLAY_ATTACK = 'REPLAY_ATTACK_DETECTED';
    public const NONCE_INVALID = 'NONCE_INVALID';
    public const NONCE_EXPIRED = 'NONCE_EXPIRED';

    // Idempotency Errors
    public const REQUEST_IN_PROGRESS = 'REQUEST_IN_PROGRESS';
    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    public const DUPLICATE_REQUEST = 'DUPLICATE_REQUEST';

    // GPS & Location Errors
    public const GPS_SPOOFING = 'GPS_SPOOFING_DETECTED';
    public const GEOFENCE_VIOLATION = 'GEOFENCE_VIOLATION';
    public const LOCATION_REQUIRED = 'LOCATION_REQUIRED';
    public const IMPOSSIBLE_TRAVEL = 'IMPOSSIBLE_TRAVEL_DETECTED';

    // Time Errors
    public const TIMESTAMP_MANIPULATION = 'TIMESTAMP_MANIPULATION';
    public const QR_EXPIRED = 'QR_EXPIRED';
    public const SCHEDULE_NOT_ACTIVE = 'SCHEDULE_NOT_ACTIVE';
    public const OUTSIDE_ATTENDANCE_WINDOW = 'OUTSIDE_ATTENDANCE_WINDOW';

    // Device Errors
    public const DEVICE_NOT_REGISTERED = 'DEVICE_NOT_REGISTERED';
    public const DEVICE_MISMATCH = 'DEVICE_MISMATCH';
    public const DEVICE_INTEGRITY_FAILED = 'DEVICE_INTEGRITY_FAILED';

    // Rate Limiting
    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    // Authentication
    public const SESSION_EXPIRED = 'SESSION_EXPIRED';
    public const UNAUTHORIZED = 'UNAUTHORIZED';

    // QR Code Errors
    public const INVALID_QR_SIGNATURE = 'INVALID_QR_SIGNATURE';
    public const QR_MALFORMED = 'QR_MALFORMED';

    // Attendance Errors
    public const ALREADY_CHECKED_IN = 'ALREADY_CHECKED_IN';
    public const MUST_CHECK_IN_FIRST = 'MUST_CHECK_IN_FIRST';
    public const STUDENT_NOT_ENROLLED = 'STUDENT_NOT_ENROLLED';

    // Server Errors
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    // ─────────────────────────────────────────────────────────────────────
    // PROPERTIES
    // ─────────────────────────────────────────────────────────────────────

    private string $code;
    private string $message;
    private int $httpStatus;
    private array $details;
    private ?string $requestId;
    private bool $retryAllowed;
    private ?int $retryAfterSeconds;

    // ─────────────────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    public function __construct(
        string $code,
        string $message,
        int $httpStatus = 400,
        array $details = [],
        bool $retryAllowed = false,
        ?int $retryAfterSeconds = null,
        ?string $requestId = null
    ) {
        $this->code = $code;
        $this->message = $message;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
        $this->retryAllowed = $retryAllowed;
        $this->retryAfterSeconds = $retryAfterSeconds;
        $this->requestId = $requestId ?? request()->input('request_id') ?? (string) \Illuminate\Support\Str::uuid();
    }

    // ─────────────────────────────────────────────────────────────────────
    // FACTORY METHODS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Replay attack detected
     */
    public static function replayAttack(?string $requestId = null): self
    {
        return new self(
            code: self::REPLAY_ATTACK,
            message: 'Kode QR sudah pernah digunakan. Silakan scan ulang QR yang baru.',
            httpStatus: 403,
            details: ['action_required' => 'rescan_qr'],
            retryAllowed: false,
            requestId: $requestId
        );
    }

    /**
     * GPS spoofing detected
     */
    public static function gpsSpoofing(array $indicators = [], ?string $requestId = null): self
    {
        return new self(
            code: self::GPS_SPOOFING,
            message: 'Lokasi tidak valid. Pastikan GPS aktif dan tidak menggunakan aplikasi fake location.',
            httpStatus: 403,
            details: [
                'action_required' => 'verify_location',
                'indicators' => app()->isProduction() ? [] : $indicators,
            ],
            retryAllowed: true,
            requestId: $requestId
        );
    }

    /**
     * Request already in progress (idempotency)
     */
    public static function requestInProgress(?string $requestId = null): self
    {
        return new self(
            code: self::REQUEST_IN_PROGRESS,
            message: 'Permintaan sebelumnya masih diproses. Mohon tunggu.',
            httpStatus: 409,
            details: [],
            retryAllowed: true,
            retryAfterSeconds: 5,
            requestId: $requestId
        );
    }

    /**
     * Rate limit exceeded
     */
    public static function rateLimitExceeded(int $retryAfter = 60, ?string $requestId = null): self
    {
        return new self(
            code: self::RATE_LIMIT_EXCEEDED,
            message: 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
            httpStatus: 429,
            details: [],
            retryAllowed: true,
            retryAfterSeconds: $retryAfter,
            requestId: $requestId
        );
    }

    /**
     * QR code expired
     */
    public static function qrExpired(?string $requestId = null): self
    {
        return new self(
            code: self::QR_EXPIRED,
            message: 'Kode QR sudah kadaluarsa. Silakan minta QR baru dari guru.',
            httpStatus: 400,
            details: ['action_required' => 'request_new_qr'],
            retryAllowed: false,
            requestId: $requestId
        );
    }

    /**
     * Invalid QR signature
     */
    public static function invalidQrSignature(?string $requestId = null): self
    {
        return new self(
            code: self::INVALID_QR_SIGNATURE,
            message: 'Kode QR tidak valid atau telah dimodifikasi.',
            httpStatus: 400,
            details: ['action_required' => 'rescan_qr'],
            retryAllowed: false,
            requestId: $requestId
        );
    }

    /**
     * Already checked in
     */
    public static function alreadyCheckedIn(string $checkInTime, ?string $requestId = null): self
    {
        return new self(
            code: self::ALREADY_CHECKED_IN,
            message: 'Anda sudah melakukan absensi masuk.',
            httpStatus: 409,
            details: ['check_in_time' => $checkInTime],
            retryAllowed: false,
            requestId: $requestId
        );
    }

    /**
     * Geofence violation
     */
    public static function geofenceViolation(float $distance, ?string $requestId = null): self
    {
        return new self(
            code: self::GEOFENCE_VIOLATION,
            message: 'Anda berada di luar area yang diizinkan untuk absensi.',
            httpStatus: 403,
            details: [
                'distance_meters' => round($distance),
                'action_required' => 'move_closer_to_school',
            ],
            retryAllowed: true,
            requestId: $requestId
        );
    }

    /**
     * Device not registered
     */
    public static function deviceNotRegistered(?string $requestId = null): self
    {
        return new self(
            code: self::DEVICE_NOT_REGISTERED,
            message: 'Perangkat belum terdaftar. Silakan hubungi admin untuk registrasi.',
            httpStatus: 403,
            details: ['action_required' => 'register_device'],
            retryAllowed: false,
            requestId: $requestId
        );
    }

    /**
     * Schedule not active
     */
    public static function scheduleNotActive(?string $requestId = null): self
    {
        return new self(
            code: self::SCHEDULE_NOT_ACTIVE,
            message: 'Tidak ada jadwal pelajaran aktif saat ini.',
            httpStatus: 400,
            details: [],
            retryAllowed: true,
            retryAfterSeconds: 300, // Check again in 5 minutes
            requestId: $requestId
        );
    }

    /**
     * Internal server error
     */
    public static function internalError(?string $requestId = null): self
    {
        return new self(
            code: self::INTERNAL_ERROR,
            message: 'Terjadi kesalahan pada server. Silakan coba lagi.',
            httpStatus: 500,
            details: [],
            retryAllowed: true,
            retryAfterSeconds: 10,
            requestId: $requestId
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // RESPONSE BUILDING
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
            ],
            'meta' => [
                'request_id' => $this->requestId,
                'timestamp' => now()->toIso8601String(),
                'retry_allowed' => $this->retryAllowed,
            ],
        ];

        if (!empty($this->details)) {
            $response['error']['details'] = $this->details;
        }

        if ($this->retryAfterSeconds !== null) {
            $response['meta']['retry_after_seconds'] = $this->retryAfterSeconds;
        }

        return $response;
    }

    /**
     * Create JSON response
     */
    public function toResponse($request): JsonResponse
    {
        $response = response()->json($this->toArray(), $this->httpStatus);

        // Add Retry-After header for rate limiting
        if ($this->retryAfterSeconds !== null) {
            $response->header('Retry-After', $this->retryAfterSeconds);
        }

        // Add request ID header
        $response->header('X-Request-ID', $this->requestId);

        return $response;
    }

    /**
     * Get HTTP status code
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Get error code
     */
    public function getCode(): string
    {
        return $this->code;
    }
}
