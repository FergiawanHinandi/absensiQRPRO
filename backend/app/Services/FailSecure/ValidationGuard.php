<?php

namespace App\Services\FailSecure;

use App\Exceptions\FailSecureException;
use App\Services\SecurityPolicyService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ValidationGuard
 *
 * Provides fail-secure validation for critical security checks.
 * Ensures that validation failures result in DENIAL, not bypass.
 *
 * CORE PRINCIPLE: When validation status is uncertain, DENY.
 *
 * This guard handles:
 * - Location/GPS validation
 * - Device binding validation
 * - Policy service validation
 * - QR code validation
 * - Geofence validation
 */
class ValidationGuard
{
    protected FailSecureService $failSecureService;
    protected ?SecurityPolicyService $policyService = null;

    /**
     * Validation result constants
     */
    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';
    public const RESULT_DENY = 'deny'; // Used when uncertain

    /**
     * Validation types for logging
     */
    public const TYPE_LOCATION = 'location';
    public const TYPE_DEVICE = 'device';
    public const TYPE_POLICY = 'policy';
    public const TYPE_GEOFENCE = 'geofence';
    public const TYPE_QR = 'qr';
    public const TYPE_SCHEDULE = 'schedule';
    public const TYPE_STUDENT = 'student';

    public function __construct(FailSecureService $failSecureService)
    {
        $this->failSecureService = $failSecureService;
    }

    /**
     * Get policy service (lazy loaded)
     */
    protected function getPolicyService(): SecurityPolicyService
    {
        if ($this->policyService === null) {
            $this->policyService = app(SecurityPolicyService::class);
        }
        return $this->policyService;
    }

    /**
     * Validate location data
     *
     * FAIL-SECURE: If GPS data is missing or malformed, DENY
     *
     * @param float|null $latitude
     * @param float|null $longitude
     * @param bool $required Whether location is required
     * @return array ['valid' => bool, 'message' => string, 'data' => array]
     * @throws FailSecureException
     */
    public function validateLocation(
        ?float $latitude,
        ?float $longitude,
        bool $required = true
    ): array {
        try {
            // Check if location data is present
            if ($latitude === null || $longitude === null) {
                if ($required) {
                    $this->logValidationFailure(
                        self::TYPE_LOCATION,
                        'GPS data missing',
                        ['latitude' => $latitude, 'longitude' => $longitude]
                    );

                    return [
                        'valid' => false,
                        'result' => self::RESULT_DENY,
                        'message' => 'Lokasi tidak dapat diverifikasi. Pastikan GPS aktif.',
                        'data' => [],
                    ];
                }

                // Not required, pass validation
                return [
                    'valid' => true,
                    'result' => self::RESULT_PASS,
                    'message' => 'Location not required',
                    'data' => ['location_provided' => false],
                ];
            }

            // Validate latitude range (-90 to 90)
            if ($latitude < -90 || $latitude > 90) {
                $this->logValidationFailure(
                    self::TYPE_LOCATION,
                    'Invalid latitude value',
                    ['latitude' => $latitude]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'Data lokasi tidak valid (latitude).',
                    'data' => [],
                ];
            }

            // Validate longitude range (-180 to 180)
            if ($longitude < -180 || $longitude > 180) {
                $this->logValidationFailure(
                    self::TYPE_LOCATION,
                    'Invalid longitude value',
                    ['longitude' => $longitude]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'Data lokasi tidak valid (longitude).',
                    'data' => [],
                ];
            }

            // Check for suspicious coordinates (0,0 is often default/error)
            if (abs($latitude) < 0.0001 && abs($longitude) < 0.0001) {
                $this->logValidationFailure(
                    self::TYPE_LOCATION,
                    'Suspicious null island coordinates',
                    ['latitude' => $latitude, 'longitude' => $longitude]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'Lokasi tidak dapat diverifikasi. Data GPS mencurigakan.',
                    'data' => [],
                ];
            }

            return [
                'valid' => true,
                'result' => self::RESULT_PASS,
                'message' => 'Location valid',
                'data' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'location_provided' => true,
                ],
            ];
        } catch (Throwable $e) {
            // FAIL-SECURE: Any exception during validation = DENY
            $this->logValidationFailure(
                self::TYPE_LOCATION,
                'Exception during location validation: ' . $e->getMessage(),
                ['exception' => get_class($e)]
            );

            return [
                'valid' => false,
                'result' => self::RESULT_DENY,
                'message' => 'Lokasi tidak dapat diverifikasi.',
                'data' => ['error' => 'validation_exception'],
            ];
        }
    }

    /**
     * Validate geofence (distance from school)
     *
     * FAIL-SECURE: If geofence check fails, DENY
     *
     * @param float $userLat User latitude
     * @param float $userLng User longitude
     * @param float $schoolLat School latitude
     * @param float $schoolLng School longitude
     * @param int|null $schoolId For school-specific radius
     * @param string $userType 'student' or 'teacher'
     * @return array
     */
    public function validateGeofence(
        float $userLat,
        float $userLng,
        float $schoolLat,
        float $schoolLng,
        ?int $schoolId = null,
        string $userType = 'student'
    ): array {
        try {
            // First validate the location data
            $locationCheck = $this->validateLocation($userLat, $userLng, true);
            if (!$locationCheck['valid']) {
                return $locationCheck;
            }

            $schoolLocationCheck = $this->validateLocation($schoolLat, $schoolLng, true);
            if (!$schoolLocationCheck['valid']) {
                $this->logValidationFailure(
                    self::TYPE_GEOFENCE,
                    'School coordinates invalid',
                    ['school_lat' => $schoolLat, 'school_lng' => $schoolLng]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'Lokasi sekolah tidak dapat diverifikasi.',
                    'data' => [],
                ];
            }

            // Get max radius from policy service with fail-secure fallback
            $maxRadius = $this->getGeofenceRadiusWithFallback($schoolId, $userType);

            // Calculate distance
            $distance = $this->calculateHaversineDistance(
                $userLat,
                $userLng,
                $schoolLat,
                $schoolLng
            );

            if ($distance > $maxRadius) {
                $this->logValidationFailure(
                    self::TYPE_GEOFENCE,
                    'User outside geofence',
                    [
                        'distance' => round($distance, 2),
                        'max_radius' => $maxRadius,
                        'user_type' => $userType,
                    ]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_FAIL,
                    'message' => "Anda berada di luar area sekolah ({$distance}m, maks: {$maxRadius}m).",
                    'data' => [
                        'distance' => round($distance, 2),
                        'max_radius' => $maxRadius,
                    ],
                ];
            }

            return [
                'valid' => true,
                'result' => self::RESULT_PASS,
                'message' => 'Geofence valid',
                'data' => [
                    'distance' => round($distance, 2),
                    'max_radius' => $maxRadius,
                ],
            ];
        } catch (Throwable $e) {
            // FAIL-SECURE: Exception during geofence check = DENY
            $this->logValidationFailure(
                self::TYPE_GEOFENCE,
                'Exception during geofence validation: ' . $e->getMessage(),
                ['exception' => get_class($e)]
            );

            return [
                'valid' => false,
                'result' => self::RESULT_DENY,
                'message' => 'Validasi lokasi gagal. Coba lagi nanti.',
                'data' => ['error' => 'geofence_exception'],
            ];
        }
    }

    /**
     * Validate device binding
     *
     * FAIL-SECURE: If device validation service fails, DENY
     *
     * @param string|null $deviceId Device identifier
     * @param int $userId User ID
     * @param callable|null $deviceCheckCallback Custom device check logic
     * @return array
     */
    public function validateDevice(
        ?string $deviceId,
        int $userId,
        ?callable $deviceCheckCallback = null
    ): array {
        try {
            // Check if device ID is provided
            if (empty($deviceId)) {
                $this->logValidationFailure(
                    self::TYPE_DEVICE,
                    'Device ID missing',
                    ['user_id' => $userId]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'Identifikasi perangkat tidak tersedia.',
                    'data' => [],
                ];
            }

            // If custom callback provided, use it
            if ($deviceCheckCallback !== null) {
                try {
                    $isValid = $deviceCheckCallback($deviceId, $userId);

                    if (!$isValid) {
                        $this->logValidationFailure(
                            self::TYPE_DEVICE,
                            'Device not registered/approved',
                            ['user_id' => $userId, 'device_id' => substr($deviceId, 0, 16) . '...']
                        );

                        return [
                            'valid' => false,
                            'result' => self::RESULT_FAIL,
                            'message' => 'Perangkat tidak terdaftar atau belum disetujui.',
                            'data' => [],
                        ];
                    }

                    return [
                        'valid' => true,
                        'result' => self::RESULT_PASS,
                        'message' => 'Device validated',
                        'data' => ['device_id' => substr($deviceId, 0, 16) . '...'],
                    ];
                } catch (Throwable $e) {
                    // FAIL-SECURE: Device service error = DENY
                    $this->failSecureService->recordFallbackEvent(
                        FailSecureService::EVENT_DEVICE_VALIDATION_UNAVAILABLE,
                        FailSecureService::COMPONENT_DEVICE,
                        'Denying due to device validation service failure',
                        $e->getMessage(),
                        ['user_id' => $userId]
                    );

                    return [
                        'valid' => false,
                        'result' => self::RESULT_DENY,
                        'message' => 'Validasi perangkat gagal. Coba lagi nanti.',
                        'data' => ['error' => 'device_service_unavailable'],
                    ];
                }
            }

            // Basic validation passed (no custom check)
            return [
                'valid' => true,
                'result' => self::RESULT_PASS,
                'message' => 'Device ID present',
                'data' => ['device_id' => substr($deviceId, 0, 16) . '...'],
            ];
        } catch (Throwable $e) {
            // FAIL-SECURE: Any exception = DENY
            $this->logValidationFailure(
                self::TYPE_DEVICE,
                'Exception during device validation: ' . $e->getMessage(),
                ['user_id' => $userId, 'exception' => get_class($e)]
            );

            return [
                'valid' => false,
                'result' => self::RESULT_DENY,
                'message' => 'Validasi perangkat gagal.',
                'data' => ['error' => 'validation_exception'],
            ];
        }
    }

    /**
     * Validate QR code data
     *
     * FAIL-SECURE: If QR validation fails, DENY
     *
     * @param string|null $qrToken QR token
     * @param callable|null $qrValidationCallback Custom QR validation logic
     * @return array
     */
    public function validateQrCode(
        ?string $qrToken,
        ?callable $qrValidationCallback = null
    ): array {
        try {
            // Check if QR token is provided
            if (empty($qrToken)) {
                $this->logValidationFailure(
                    self::TYPE_QR,
                    'QR token missing',
                    []
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'QR code tidak valid atau tidak ditemukan.',
                    'data' => [],
                ];
            }

            // Basic format validation
            if (strlen($qrToken) < 10) {
                $this->logValidationFailure(
                    self::TYPE_QR,
                    'QR token too short',
                    ['length' => strlen($qrToken)]
                );

                return [
                    'valid' => false,
                    'result' => self::RESULT_DENY,
                    'message' => 'QR code tidak valid.',
                    'data' => [],
                ];
            }

            // If custom validation provided
            if ($qrValidationCallback !== null) {
                try {
                    $result = $qrValidationCallback($qrToken);

                    if ($result === false || (is_array($result) && !($result['valid'] ?? true))) {
                        $this->logValidationFailure(
                            self::TYPE_QR,
                            'QR validation failed',
                            ['reason' => $result['message'] ?? 'unknown']
                        );

                        return [
                            'valid' => false,
                            'result' => self::RESULT_FAIL,
                            'message' => is_array($result) ? ($result['message'] ?? 'QR code tidak valid.') : 'QR code tidak valid.',
                            'data' => is_array($result) ? ($result['data'] ?? []) : [],
                        ];
                    }

                    return [
                        'valid' => true,
                        'result' => self::RESULT_PASS,
                        'message' => 'QR validated',
                        'data' => is_array($result) ? ($result['data'] ?? []) : [],
                    ];
                } catch (Throwable $e) {
                    // FAIL-SECURE: QR service error = DENY
                    $this->logValidationFailure(
                        self::TYPE_QR,
                        'QR validation service error: ' . $e->getMessage(),
                        ['exception' => get_class($e)]
                    );

                    return [
                        'valid' => false,
                        'result' => self::RESULT_DENY,
                        'message' => 'Validasi QR code gagal. Coba lagi.',
                        'data' => ['error' => 'qr_service_unavailable'],
                    ];
                }
            }

            // Basic validation passed
            return [
                'valid' => true,
                'result' => self::RESULT_PASS,
                'message' => 'QR format valid',
                'data' => [],
            ];
        } catch (Throwable $e) {
            // FAIL-SECURE: Any exception = DENY
            $this->logValidationFailure(
                self::TYPE_QR,
                'Exception during QR validation: ' . $e->getMessage(),
                ['exception' => get_class($e)]
            );

            return [
                'valid' => false,
                'result' => self::RESULT_DENY,
                'message' => 'Validasi QR code gagal.',
                'data' => ['error' => 'validation_exception'],
            ];
        }
    }

    /**
     * Validate policy access with fail-secure fallback
     *
     * @param string $key Policy key
     * @param int|null $schoolId School ID
     * @return mixed Policy value (uses safe defaults on failure)
     */
    public function getPolicyWithFallback(string $key, ?int $schoolId = null): mixed
    {
        try {
            return $this->getPolicyService()->get($key, $schoolId);
        } catch (Throwable $e) {
            // FAIL-SECURE: Use safe defaults
            $safeDefault = $this->failSecureService->getSafeDefault($key);

            $this->failSecureService->recordFallbackEvent(
                FailSecureService::EVENT_POLICY_FALLBACK,
                FailSecureService::COMPONENT_POLICY,
                "Using safe default for {$key}: {$safeDefault}",
                $e->getMessage(),
                ['key' => $key, 'school_id' => $schoolId, 'safe_default' => $safeDefault]
            );

            return $safeDefault;
        }
    }

    /**
     * Get geofence radius with fail-secure fallback
     */
    protected function getGeofenceRadiusWithFallback(?int $schoolId, string $userType): int
    {
        $key = $userType === 'teacher'
            ? 'attendance.teacher_geofence_radius_meters'
            : 'attendance.geofence_radius_meters';

        $value = $this->getPolicyWithFallback($key, $schoolId);

        return (int) ($value ?? ($userType === 'teacher' ? 100 : 30));
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     *
     * @return float Distance in meters
     */
    protected function calculateHaversineDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earthRadius = 6371000; // meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lng1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lng2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }

    /**
     * Log validation failure
     */
    protected function logValidationFailure(
        string $type,
        string $reason,
        array $context = []
    ): void {
        $this->failSecureService->recordFallbackEvent(
            FailSecureService::EVENT_LOCATION_VALIDATION_FAILED,
            $type,
            $reason,
            null,
            array_merge($context, [
                'validation_type' => $type,
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
            ]),
            'warning'
        );

        Log::channel('security')->warning("ValidationGuard: {$type} validation failed", [
            'reason' => $reason,
            'context' => $context,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Run multiple validations and return combined result
     *
     * FAIL-SECURE: If ANY validation fails, the overall result is FAIL/DENY
     *
     * @param array $validations Array of validation results
     * @return array Combined validation result
     */
    public function combineValidations(array $validations): array
    {
        $allValid = true;
        $messages = [];
        $data = [];

        foreach ($validations as $name => $result) {
            if (!($result['valid'] ?? false)) {
                $allValid = false;
                $messages[] = $result['message'] ?? "Validation {$name} failed";
            }
            $data[$name] = $result;
        }

        return [
            'valid' => $allValid,
            'result' => $allValid ? self::RESULT_PASS : self::RESULT_FAIL,
            'message' => $allValid ? 'All validations passed' : implode('; ', $messages),
            'validations' => $data,
        ];
    }

    /**
     * Create a fail-secure validation wrapper
     *
     * @param callable $validation The validation to run
     * @param string $failMessage Message if validation fails
     * @param string $type Validation type for logging
     * @return array Validation result
     */
    public function wrapValidation(
        callable $validation,
        string $failMessage,
        string $type = 'custom'
    ): array {
        try {
            $result = $validation();

            if ($result === true) {
                return [
                    'valid' => true,
                    'result' => self::RESULT_PASS,
                    'message' => 'Validation passed',
                    'data' => [],
                ];
            }

            if ($result === false) {
                return [
                    'valid' => false,
                    'result' => self::RESULT_FAIL,
                    'message' => $failMessage,
                    'data' => [],
                ];
            }

            // Assume array result
            return $result;
        } catch (Throwable $e) {
            // FAIL-SECURE: Exception = DENY
            $this->logValidationFailure(
                $type,
                'Validation exception: ' . $e->getMessage(),
                ['exception' => get_class($e)]
            );

            return [
                'valid' => false,
                'result' => self::RESULT_DENY,
                'message' => $failMessage,
                'data' => ['error' => 'validation_exception'],
            ];
        }
    }
}
