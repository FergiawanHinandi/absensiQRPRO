<?php

namespace App\Services;

use App\Jobs\SendSecurityAlertNotification;
use App\Models\ImmutableSecurityLog;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Security Alert Service
 *
 * Central service for creating and managing security alerts.
 * Handles rate limiting to prevent alert spam and dispatches notifications.
 * All security events are also logged to the immutable audit trail.
 */
class SecurityAlertService
{
    protected ?ImmutableSecurityLogService $immutableLogService = null;

    public function __construct()
    {
        // Lazy-load to avoid circular dependency
        // The service will be resolved when first needed
    }

    /**
     * Get the immutable log service (lazy loaded)
     */
    protected function getImmutableLogService(): ImmutableSecurityLogService
    {
        if ($this->immutableLogService === null) {
            $this->immutableLogService = app(
                ImmutableSecurityLogService::class,
            );
        }

        return $this->immutableLogService;
    }

    // Event Type Constants (mirror SecurityAlert model)
    public const TYPE_GEOFENCE_VIOLATION = 'geofence_violation';

    public const TYPE_UNAPPROVED_DEVICE = 'unapproved_device';

    public const TYPE_QR_REPLAY = 'qr_replay_attempt';

    public const TYPE_UNAUTHORIZED_SCHEDULE = 'unauthorized_schedule';

    public const TYPE_FAILED_ATTEMPT_SPIKE = 'failed_attempt_spike';

    public const TYPE_RACE_CONDITION_BLOCKED = 'race_condition_blocked';

    public const TYPE_IMPOSSIBLE_TRAVEL = 'impossible_travel';

    public const TYPE_BACKUP_FAILURE = 'backup_failure';

    public const TYPE_LOG_TAMPERING = 'log_tampering_detected';

    public const TYPE_SECURITY_POLICY_CHANGED = 'security_policy_changed';

    public const TYPE_SECURITY_ANOMALY = 'security_anomaly';

    // Severity Constants
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Rate limit window in seconds for same event type per user
     */
    private const RATE_LIMIT_WINDOW = 300; // 5 minutes

    /**
     * Create a security alert
     *
     * @param  string  $eventType  Event type constant
     * @param  string  $severity  Severity level (low, medium, high, critical)
     * @param  string  $description  Human-readable description
     * @param  array  $metadata  Additional context data
     * @param  int|null  $userId  Related user ID
     * @param  int|null  $schoolId  School ID
     * @param  string|null  $ipAddress  IP address
     * @param  string|null  $deviceId  Device identifier
     * @param  bool  $forceNotify  Force notification even if rate limited
     * @return SecurityAlert|null Returns null if rate limited
     */
    public function createAlert(
        string $eventType,
        string $severity,
        string $description,
        array $metadata = [],
        ?int $userId = null,
        ?int $schoolId = null,
        ?string $ipAddress = null,
        ?string $deviceId = null,
        bool $forceNotify = false,
    ): ?SecurityAlert {
        // Check rate limit to prevent spam
        if (
            ! $forceNotify &&
            $this->isRateLimited($eventType, $userId, $schoolId)
        ) {
            Log::channel('security')->debug('Security alert rate limited', [
                'event_type' => $eventType,
                'user_id' => $userId,
                'school_id' => $schoolId,
            ]);

            return null;
        }

        // Create the alert
        $alert = SecurityAlert::createAlert([
            'type' => $eventType,
            'severity' => $severity,
            'description' => $description,
            'school_id' => $schoolId,
            'ip_address' => $ipAddress ?? request()->ip(),
        ]);

        // Log to security channel
        Log::channel('security')->warning("Security Alert: {$eventType}", [
            'alert_id' => $alert->id,
            'severity' => $severity,
            'description' => $description,
            'user_id' => $userId,
            'school_id' => $schoolId,
            'ip' => $ipAddress ?? request()->ip(),
        ]);

        // Set rate limit cache
        $this->setRateLimit($eventType, $userId, $schoolId);

        // Dispatch notification for high/critical alerts
        if ($alert->shouldNotify()) {
            SendSecurityAlertNotification::dispatch($alert)->onQueue(
                'notifications',
            );
        }

        // Log to immutable audit trail (async, non-blocking)
        $this->logToImmutableTrail(
            $eventType,
            $description,
            $userId,
            $schoolId,
            $metadata,
        );

        return $alert;
    }

    /**
     * Log security event to the tamper-proof immutable audit trail.
     * This creates a hash-chained record that cannot be modified or deleted.
     */
    protected function logToImmutableTrail(
        string $eventType,
        string $description,
        ?int $userId,
        ?int $schoolId,
        array $metadata,
    ): void {
        try {
            // Map SecurityAlertService types to ImmutableSecurityLog types
            $immutableType = $this->mapToImmutableLogType($eventType);

            $this->getImmutableLogService()->write(
                $immutableType,
                $description,
                $userId,
                $schoolId,
                $metadata,
            );
        } catch (\Exception $e) {
            // Don't let immutable log failures affect the main flow
            // But log them for investigation
            Log::channel('security')->error('Failed to write immutable log', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map SecurityAlertService event types to ImmutableSecurityLog types
     */
    protected function mapToImmutableLogType(string $eventType): string
    {
        return match ($eventType) {
            self::TYPE_GEOFENCE_VIOLATION => ImmutableSecurityLog::TYPE_GEOFENCE_VIOLATION,
            self::TYPE_UNAPPROVED_DEVICE => ImmutableSecurityLog::TYPE_DEVICE_MISMATCH,
            self::TYPE_QR_REPLAY => ImmutableSecurityLog::TYPE_QR_REPLAY_ATTEMPT,
            self::TYPE_UNAUTHORIZED_SCHEDULE => ImmutableSecurityLog::TYPE_UNAUTHORIZED_ACCESS,
            self::TYPE_FAILED_ATTEMPT_SPIKE => ImmutableSecurityLog::TYPE_FAILED_ATTEMPT_SPIKE,
            self::TYPE_RACE_CONDITION_BLOCKED => ImmutableSecurityLog::TYPE_RACE_CONDITION_BLOCKED,
            self::TYPE_IMPOSSIBLE_TRAVEL => ImmutableSecurityLog::TYPE_IMPOSSIBLE_TRAVEL,
            self::TYPE_BACKUP_FAILURE => ImmutableSecurityLog::TYPE_BACKUP_FAILURE,
            self::TYPE_LOG_TAMPERING => ImmutableSecurityLog::TYPE_LOG_TAMPERING,
            default => ImmutableSecurityLog::TYPE_SECURITY_EVENT,
        };
    }

    /**
     * Create geofence violation alert
     *
     * @param  int|User  $user  User ID or User model
     * @param  int|null  $schoolId  School ID (optional if User model provided)
     * @param  float|null  $lat  Latitude
     * @param  float|null  $lng  Longitude
     * @param  float  $distance  Distance from school in meters
     * @param  float  $maxRadius  Maximum allowed radius
     * @param  string|null  $ipAddress  IP address
     */
    public function alertGeofenceViolation(
        int|User $user,
        ?int $schoolId,
        ?float $lat,
        ?float $lng,
        float $distance,
        float $maxRadius,
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        $userId = $user instanceof User ? $user->id : $user;
        $schoolId =
            $schoolId ?? ($user instanceof User ? $user->school_id : null);
        $userName = $user instanceof User ? $user->name : "User ID:{$userId}";

        return $this->createAlert(
            self::TYPE_GEOFENCE_VIOLATION,
            self::SEVERITY_HIGH,
            "{$userName} attempted attendance from {$distance}m away (max: {$maxRadius}m)",
            [
                'distance' => $distance,
                'max_radius' => $maxRadius,
                'latitude' => $lat,
                'longitude' => $lng,
            ],
            $userId,
            $schoolId,
            $ipAddress,
        );
    }

    /**
     * Create unapproved device alert
     *
     * @param  int|User  $user  User ID or User model
     * @param  int|null  $schoolId  School ID
     * @param  string  $deviceId  Device identifier
     * @param  string  $deviceInfo  Device name/model
     * @param  string|null  $ipAddress  IP address
     */
    public function alertUnapprovedDevice(
        int|User $user,
        ?int $schoolId,
        string $deviceId,
        string $deviceInfo = 'Unknown device',
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        $userId = $user instanceof User ? $user->id : $user;
        $schoolId =
            $schoolId ?? ($user instanceof User ? $user->school_id : null);
        $userName = $user instanceof User ? $user->name : "User ID:{$userId}";

        return $this->createAlert(
            self::TYPE_UNAPPROVED_DEVICE,
            self::SEVERITY_MEDIUM,
            "{$userName} attempted to use unapproved device: {$deviceInfo}",
            [
                'device_id' => substr($deviceId, 0, 16).'...',
                'device_info' => $deviceInfo,
            ],
            $userId,
            $schoolId,
            $ipAddress,
            $deviceId,
        );
    }

    /**
     * Create QR replay attempt alert
     *
     * @param  int  $studentId  Student user ID
     * @param  int|null  $schoolId  School ID
     * @param  string  $reason  Replay detection reason
     * @param  string|null  $ipAddress  IP address
     */
    public function alertQrReplay(
        int $studentId,
        ?int $schoolId,
        string $reason = 'QR code nonce replay detected',
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        $student = User::find($studentId);

        return $this->createAlert(
            self::TYPE_QR_REPLAY,
            self::SEVERITY_MEDIUM,
            'QR replay attempt: '.
                ($student?->name ?? "Student ID:{$studentId}").
                " - {$reason}",
            [
                'reason' => $reason,
            ],
            $studentId,
            $schoolId,
            $ipAddress,
        );
    }

    /**
     * Create unauthorized schedule access alert
     *
     * @param  int|User  $teacher  Teacher ID or model
     * @param  int|null  $schoolId  School ID
     * @param  int  $scheduleId  Schedule ID
     * @param  string|null  $ipAddress  IP address
     */
    public function alertUnauthorizedSchedule(
        int|User $teacher,
        ?int $schoolId,
        int $scheduleId,
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;
        $schoolId =
            $schoolId ??
            ($teacher instanceof User ? $teacher->school_id : null);
        $teacherName =
            $teacher instanceof User
                ? $teacher->name
                : "Teacher ID:{$teacherId}";

        return $this->createAlert(
            self::TYPE_UNAUTHORIZED_SCHEDULE,
            self::SEVERITY_HIGH,
            "{$teacherName} attempted to scan attendance for schedule #{$scheduleId} not assigned to them",
            [
                'schedule_id' => $scheduleId,
            ],
            $teacherId,
            $schoolId,
            $ipAddress,
        );
    }

    /**
     * Create rate limit breach alert (failed attempts spike)
     */
    public function alertFailedAttemptSpike(
        ?int $userId,
        ?int $schoolId,
        int $attemptCount,
        string $reason = 'Multiple failed attendance attempts',
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        return $this->createAlert(
            self::TYPE_FAILED_ATTEMPT_SPIKE,
            self::SEVERITY_CRITICAL,
            "{$attemptCount} failed attempts detected in 15 minutes",
            [
                'attempt_count' => $attemptCount,
                'reason' => $reason,
                'window_minutes' => 15,
            ],
            $userId,
            $schoolId,
            $ipAddress,
            null,
            true, // Force notify for critical
        );
    }

    /**
     * Create race condition blocked alert
     */
    public function alertRaceConditionBlocked(
        int $userId,
        ?int $schoolId,
        int $scheduleId,
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        $user = User::find($userId);

        return $this->createAlert(
            self::TYPE_RACE_CONDITION_BLOCKED,
            self::SEVERITY_MEDIUM,
            'Duplicate attendance scan blocked for '.
                ($user?->name ?? "User ID:{$userId}"),
            [
                'schedule_id' => $scheduleId,
            ],
            $userId,
            $schoolId,
            $ipAddress,
        );
    }

    /**
     * Create impossible travel alert
     */
    public function alertImpossibleTravel(
        int|User $user,
        ?int $schoolId,
        float $distance,
        int $timeDiffMinutes,
        array $locations,
        ?string $ipAddress = null,
    ): ?SecurityAlert {
        $userId = $user instanceof User ? $user->id : $user;
        $schoolId =
            $schoolId ?? ($user instanceof User ? $user->school_id : null);
        $userName = $user instanceof User ? $user->name : "User ID:{$userId}";

        return $this->createAlert(
            self::TYPE_IMPOSSIBLE_TRAVEL,
            self::SEVERITY_HIGH,
            "{$userName} detected at locations {$distance}m apart within {$timeDiffMinutes} minutes",
            [
                'distance_meters' => $distance,
                'time_diff_minutes' => $timeDiffMinutes,
                'locations' => $locations,
            ],
            $userId,
            $schoolId,
            $ipAddress,
        );
    }

    /**
     * Create attendance spike alert
     */
    public function alertAttendanceSpike(
        int $schoolId,
        int $classId,
        int $count,
        int $normalAverage,
    ): ?SecurityAlert {
        return $this->createAlert(
            'attendance_spike',
            self::SEVERITY_MEDIUM,
            "Unusual attendance spike: {$count} records (normal avg: {$normalAverage})",
            [
                'class_id' => $classId,
                'count' => $count,
                'normal_average' => $normalAverage,
            ],
            null,
            $schoolId,
        );
    }

    /**
     * Check if similar alert is rate limited
     */
    private function isRateLimited(
        string $eventType,
        ?int $userId,
        ?int $schoolId,
    ): bool {
        $key = $this->getRateLimitKey($eventType, $userId, $schoolId);

        return Cache::has($key);
    }

    /**
     * Set rate limit for alert
     */
    private function setRateLimit(
        string $eventType,
        ?int $userId,
        ?int $schoolId,
    ): void {
        $key = $this->getRateLimitKey($eventType, $userId, $schoolId);
        Cache::put($key, true, self::RATE_LIMIT_WINDOW);
    }

    /**
     * Generate rate limit cache key
     */
    private function getRateLimitKey(
        string $eventType,
        ?int $userId,
        ?int $schoolId,
    ): string {
        return "security_alert_ratelimit:{$eventType}:{$userId}:{$schoolId}";
    }

    /**
     * Get recent alerts count by type
     */
    public function getRecentAlertCount(string $eventType, int $hours = 24): int
    {
        return SecurityAlert::where('type', $eventType)
            ->where('created_at', '>=', now()->subHours($hours))
            ->count();
    }

    /**
     * Get unresolved critical alerts count
     */
    public function getUnresolvedCriticalCount(?int $schoolId = null): int
    {
        return SecurityAlert::unresolved()
            ->critical()
            ->when($schoolId, fn ($q) => $q->forSchool($schoolId))
            ->count();
    }
}
