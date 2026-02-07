<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FailSecureException
 *
 * Exception thrown when a fail-secure condition is triggered.
 * This exception indicates that the system is denying access
 * due to validation uncertainty or service unavailability.
 *
 * CORE PRINCIPLE: When in doubt, DENY.
 */
class FailSecureException extends Exception
{
    /**
     * Failure types
     */
    public const TYPE_LOCATION_VALIDATION = 'location_validation_failed';

    public const TYPE_DEVICE_VALIDATION = 'device_validation_failed';

    public const TYPE_POLICY_SERVICE = 'policy_service_unavailable';

    public const TYPE_RATE_LIMIT = 'rate_limit_service_unavailable';

    public const TYPE_GEOFENCE = 'geofence_validation_failed';

    public const TYPE_QR_VALIDATION = 'qr_validation_failed';

    public const TYPE_SECURITY_SERVICE = 'security_service_unavailable';

    public const TYPE_DATABASE = 'database_unavailable';

    public const TYPE_CACHE = 'cache_unavailable';

    public const TYPE_UNKNOWN = 'unknown_validation_failure';

    /**
     * The failure type
     */
    protected string $failureType;

    /**
     * Additional context data
     */
    protected array $context;

    /**
     * Whether this is a critical failure
     */
    protected bool $isCritical;

    /**
     * User-friendly message
     */
    protected string $userMessage;

    /**
     * HTTP status code for response
     */
    protected int $httpStatusCode;

    /**
     * Create a new FailSecureException
     *
     * @param  string  $message  Technical message (for logging)
     * @param  string  $failureType  Type of failure
     * @param  string|null  $userMessage  User-friendly message
     * @param  array  $context  Additional context data
     * @param  bool  $isCritical  Whether this is a critical failure
     * @param  int  $httpStatusCode  HTTP status code
     * @param  \Throwable|null  $previous  Previous exception
     */
    public function __construct(
        string $message,
        string $failureType = self::TYPE_UNKNOWN,
        ?string $userMessage = null,
        array $context = [],
        bool $isCritical = false,
        int $httpStatusCode = 503,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->failureType = $failureType;
        $this->context = $context;
        $this->isCritical = $isCritical;
        $this->httpStatusCode = $httpStatusCode;
        $this->userMessage = $userMessage ?? $this->getDefaultUserMessage($failureType);
    }

    /**
     * Get the failure type
     */
    public function getFailureType(): string
    {
        return $this->failureType;
    }

    /**
     * Get additional context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Check if this is a critical failure
     */
    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    /**
     * Get user-friendly message
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Get HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get default user message based on failure type
     */
    protected function getDefaultUserMessage(string $failureType): string
    {
        return match ($failureType) {
            self::TYPE_LOCATION_VALIDATION => 'Lokasi tidak dapat diverifikasi. Pastikan GPS aktif dan coba lagi.',
            self::TYPE_DEVICE_VALIDATION => 'Perangkat tidak dapat diverifikasi. Hubungi administrator.',
            self::TYPE_POLICY_SERVICE => 'Layanan konfigurasi tidak tersedia. Coba lagi nanti.',
            self::TYPE_RATE_LIMIT => 'Terlalu banyak permintaan. Tunggu beberapa saat.',
            self::TYPE_GEOFENCE => 'Anda berada di luar area yang diizinkan.',
            self::TYPE_QR_VALIDATION => 'QR code tidak dapat diverifikasi. Coba lagi.',
            self::TYPE_SECURITY_SERVICE => 'Layanan keamanan tidak tersedia. Coba lagi nanti.',
            self::TYPE_DATABASE => 'Layanan sedang tidak tersedia. Coba lagi nanti.',
            self::TYPE_CACHE => 'Layanan sedang sibuk. Coba lagi.',
            default => 'Validasi gagal. Silakan coba lagi atau hubungi administrator.',
        };
    }

    /**
     * Render the exception as an HTTP response
     */
    public function render(Request $request): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $this->userMessage,
            'error_type' => 'fail_secure',
            'failure_type' => $this->failureType,
        ];

        // Add technical details in debug mode
        if (config('app.debug')) {
            $response['debug'] = [
                'technical_message' => $this->getMessage(),
                'context' => $this->context,
                'is_critical' => $this->isCritical,
            ];
        }

        return response()->json($response, $this->httpStatusCode);
    }

    /**
     * Report the exception for logging
     */
    public function report(): void
    {
        $logContext = [
            'failure_type' => $this->failureType,
            'is_critical' => $this->isCritical,
            'context' => $this->context,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'path' => request()->path(),
        ];

        if ($this->isCritical) {
            \Log::channel('security')->critical('FailSecure: '.$this->getMessage(), $logContext);
        } else {
            \Log::channel('security')->warning('FailSecure: '.$this->getMessage(), $logContext);
        }
    }

    /**
     * Create exception for location validation failure
     */
    public static function locationValidationFailed(
        string $reason = 'GPS data missing or invalid',
        array $context = []
    ): self {
        return new self(
            "Location validation failed: {$reason}",
            self::TYPE_LOCATION_VALIDATION,
            null,
            $context,
            false,
            422
        );
    }

    /**
     * Create exception for device validation failure
     */
    public static function deviceValidationFailed(
        string $reason = 'Device not registered or service unavailable',
        array $context = []
    ): self {
        return new self(
            "Device validation failed: {$reason}",
            self::TYPE_DEVICE_VALIDATION,
            null,
            $context,
            false,
            422
        );
    }

    /**
     * Create exception for geofence violation
     */
    public static function geofenceViolation(
        float $distance,
        float $maxRadius,
        array $context = []
    ): self {
        $context['distance'] = $distance;
        $context['max_radius'] = $maxRadius;

        return new self(
            "Geofence violation: {$distance}m from school (max: {$maxRadius}m)",
            self::TYPE_GEOFENCE,
            "Anda berada di luar area sekolah ({$distance}m, maks: {$maxRadius}m).",
            $context,
            false,
            422
        );
    }

    /**
     * Create exception for QR validation failure
     */
    public static function qrValidationFailed(
        string $reason = 'QR code invalid or expired',
        array $context = []
    ): self {
        return new self(
            "QR validation failed: {$reason}",
            self::TYPE_QR_VALIDATION,
            null,
            $context,
            false,
            422
        );
    }

    /**
     * Create exception for service unavailable
     */
    public static function serviceUnavailable(
        string $service,
        string $reason = 'Service is unavailable',
        array $context = []
    ): self {
        $type = match ($service) {
            'policy' => self::TYPE_POLICY_SERVICE,
            'rate_limit' => self::TYPE_RATE_LIMIT,
            'security' => self::TYPE_SECURITY_SERVICE,
            'database' => self::TYPE_DATABASE,
            'cache' => self::TYPE_CACHE,
            default => self::TYPE_UNKNOWN,
        };

        return new self(
            "{$service} service unavailable: {$reason}",
            $type,
            null,
            array_merge($context, ['service' => $service]),
            true,
            503
        );
    }

    /**
     * Create exception for rate limit exceeded
     */
    public static function rateLimitExceeded(
        int $retryAfter = 60,
        array $context = []
    ): self {
        $context['retry_after'] = $retryAfter;

        return new self(
            "Rate limit exceeded, retry after {$retryAfter} seconds",
            self::TYPE_RATE_LIMIT,
            "Terlalu banyak permintaan. Tunggu {$retryAfter} detik.",
            $context,
            false,
            429
        );
    }

    /**
     * Create a critical failure exception
     */
    public static function critical(
        string $message,
        string $failureType = self::TYPE_UNKNOWN,
        array $context = []
    ): self {
        return new self(
            $message,
            $failureType,
            'Terjadi kesalahan sistem. Silakan hubungi administrator.',
            $context,
            true,
            500
        );
    }

    /**
     * Convert to array for logging/serialization
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'user_message' => $this->userMessage,
            'failure_type' => $this->failureType,
            'is_critical' => $this->isCritical,
            'http_status_code' => $this->httpStatusCode,
            'context' => $this->context,
        ];
    }
}
