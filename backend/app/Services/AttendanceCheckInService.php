<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Models\ClassStudent;
use App\Core\Services\Attendance\QrReplayPreventionService;
use App\Services\SecurityAlertService;
use App\Services\SecurityPolicyService;
use App\Services\Logging\AttendanceLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AttendanceCheckInService
 *
 * Clean Architecture Service Layer for Attendance Check-In
 *
 * RESPONSIBILITIES:
 * - Validate student exists and is active
 * - Check for active schedule
 * - Prevent duplicate attendance
 * - Store attendance with atomic transaction
 * - Return structured result
 *
 * CONTROLLER SHOULD NOT:
 * - Contain business logic
 * - Access DB directly
 * - Make business decisions
 *
 * @author Clean Architecture Team
 *
 * @version 2.0.0
 */
final class AttendanceCheckInService
{
    /**
     * @deprecated Use AttendanceLockService instead
     */
    private const LOCK_TIMEOUT = 10;  // Increased from 5 to 10 seconds

    /**
     * @deprecated Use AttendanceLockService instead
     */
    private const LOCK_WAIT = 8;  // Increased from 3 to 8 seconds

    public function __construct(
        private StudentQrService $qrService,
        private AttendanceLogger $logger,
        private StudentNotificationService $notificationService,
        private GamificationService $gamificationService,
        private AttendanceLockService $lockService,
        private AttendanceIdempotencyService $idempotencyService,
        private ?QRSignatureService $signatureService = null,
        private ?QrReplayPreventionService $replayPreventionService = null,
        private ?SecurityAlertService $alertService = null,
        private ?SecurityPolicyService $policyService = null
    ) {}

    /**
     * Process student check-in via QR scan
     *
     * @param  User  $student  The authenticated student
     * @param  array  $data  Scan data with keys: qr_token, lat, lng, device_id, request_id
     * @param  Request|null  $request  HTTP request for logging context
     *
     * @throws AttendanceException
     */
    public function checkIn(User $student, array $data, ?Request $request = null): AttendanceResult
    {
        // Extract or generate request_id for idempotency
        $requestId = $data['request_id'] ?? (string) Str::uuid();

        /*
         * IDEMPOTENCY CHECK (EARLY EXIT)
         * ==============================
         *
         * If a request with this request_id has already been processed,
         * return the existing result immediately WITHOUT re-running validations.
         *
         * This enables:
         * 1. Safe retries from mobile apps
         * 2. Offline sync with pre-generated request IDs
         * 3. Network timeout recovery
         *
         * The client should generate a UUID client-side and include it in
         * every request. If the request times out, the client can safely
         * retry with the SAME request_id.
         */
        $existingByRequestId = $this->findByRequestId($requestId);
        if ($existingByRequestId) {
            Log::channel('attendance')->info('Idempotent request returned existing attendance', [
                'request_id' => $requestId,
                'attendance_id' => $existingByRequestId->id,
                'student_id' => $student->id,
                'is_retry' => true,
            ]);

            return new AttendanceResult(
                success: true,
                attendance: $existingByRequestId,
                message: 'Absensi sudah tercatat sebelumnya.',
                status: $existingByRequestId->status,
                isIdempotentRetry: true
            );
        }

        // STEP 1: Validate student exists and is active
        $this->validateStudent($student, $request);

        // STEP 2: Validate QR token and extract payload
        $payload = $this->validateQrToken($data['qr_token'] ?? '', $request);
        $scheduleId = $payload['id'] ?? $payload['schedule_id'] ?? null;
        $nonce = $payload['n'] ?? null;

        // STEP 3: Check for active schedule
        $schedule = $this->findActiveSchedule($scheduleId, $student->school_id, $request);

        /*
         * STEP 3.5: REDIS IDEMPOTENCY CHECK (FASTEST LAYER)
         * =================================================
         *
         * This is the FASTEST protection layer against duplicates (~1ms).
         * Uses Redis SET NX (Set if Not eXists) to atomically check and set a key.
         *
         * Key format: attendance_scan:{schedule_id}:{student_id}:{date}
         * TTL: 120 seconds
         *
         * If this fails, we already have an attendance being processed.
         * Return early to prevent:
         * - Expensive database transactions
         * - Concurrent lock contention
         * - Multiple validations for same scan
         *
         * This complements (not replaces) the other protection layers:
         * - Database transaction with row lock
         * - Unique constraint
         */
        $serverDate = now()->toDateString();
        if (!$this->idempotencyService->tryAcquire($schedule->id, $student->id, $serverDate)) {
            Log::channel('attendance')->info('Redis idempotency blocked duplicate scan', [
                'schedule_id' => $schedule->id,
                'student_id' => $student->id,
                'date' => $serverDate,
            ]);

            // Try to return existing attendance record
            $existingAttendance = $this->idempotencyService->getExistingAttendance(
                $schedule->id,
                $student->id,
                $serverDate
            );

            if ($existingAttendance) {
                return new AttendanceResult(
                    success: true,
                    attendance: $existingAttendance,
                    message: 'Absensi sudah tercatat sebelumnya.',
                    status: $existingAttendance->status,
                    isIdempotentRetry: true
                );
            }

            // If no record found yet, another request is still processing
            throw AttendanceException::alreadyRecorded();
        }

        // Wrap validation steps in try-catch to release idempotency key on failure
        try {
            // STEP 4: Validate device (anti-joki)
            $this->validateDevice($student, $data['device_id'] ?? null, $request);

            // STEP 5: Validate geofence
            $this->validateLocation($student->school, $data['lat'] ?? null, $data['lng'] ?? null, $request);

            // STEP 6: Validate time window
            $attendanceStatus = $this->validateTimeWindow($schedule, $student->school);

            // STEP 7: Atomic check-in with race condition prevention
            $attendance = $this->atomicCheckIn(
                $student,
                $schedule,
                $attendanceStatus,
                $data,
                $requestId,
                $nonce,
                $request
            );
        } catch (\Exception $e) {
            // Release idempotency key if validation fails, allowing retry
            $this->idempotencyService->release($schedule->id, $student->id, $serverDate);
            throw $e;
        }

        // STEP 8: Send Notification (Async/Fire & Forget)
        try {
            $this->notificationService->sendCheckInNotification($attendance, $student);
        } catch (\Exception $e) {
            // Do not fail the check-in if notification fails
            Log::warning('Failed to send check-in notification', ['error' => $e->getMessage()]);
        }

        // STEP 9: Award Points (Async/Fire & Forget)
        try {
            $this->gamificationService->awardDailyPoints($attendance, $student);
        } catch (\Exception $e) {
            Log::warning('Failed to award points', ['error' => $e->getMessage()]);
        }

        return new AttendanceResult(
            success: true,
            attendance: $attendance,
            message: 'Absensi berhasil dicatat.',
            status: $attendanceStatus,
            isIdempotentRetry: false
        );
    }

    /**
     * Process manual attendance (Teacher/Admin only)
     *
     * @param  array  $data  Manual attendance data
     * @param  int    $recordedBy The user ID recording the attendance
     * @return Attendance
     */
    public function manualCheckIn(array $data, int $recordedBy): Attendance
    {
        return DB::transaction(function () use ($data, $recordedBy) {
            // Check for duplicate attendance on the same date with row lock
            $existing = Attendance::where('student_id', $data['student_id'])
                ->where('schedule_id', $data['schedule_id'])
                ->whereDate('attendance_date', $data['attendance_date'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // If exists, update instead of create
                $existing->update([
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?? null,
                    'is_manual' => true,
                    'attendance_type' => 'manual',
                    'recorded_by' => $recordedBy,
                ]);

                return $existing;
            }

            // Create new attendance record (using firstOrCreate)
            return Attendance::firstOrCreate(
                [
                    'student_id' => $data['student_id'],
                    'schedule_id' => $data['schedule_id'],
                    'attendance_date' => $data['attendance_date'],
                    'school_id' => $data['school_id'],
                ],
                [
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?? null,
                    'is_manual' => true,
                    'attendance_type' => 'manual',
                    'recorded_by' => $recordedBy,
                    'check_in_time' => now(),
                ]
            );
        });
    }

    /**
     * Process teacher scan of student QR
     *
     * @param  User  $teacher  The teacher performing the scan
     * @param  string  $qrToken  The encrypted QR token
     * @param  array  $data  Scan data (lat, lng, request_id)
     * @param  Request|null  $request
     * @return AttendanceResult
     */
    public function teacherCheckIn(User $teacher, string $qrToken, array $data, ?Request $request = null): AttendanceResult
    {
        // 1. Verify Teacher Role
        if (! in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
             throw AttendanceException::invalidRole();
        }

        // 2. Validate QR & Extract Payload
        $payload = $this->validateQrToken($qrToken, $request);
        $studentId = $payload['id'] ?? $payload['sid'] ?? null;
        $schoolId = $payload['sch'] ?? $payload['school_id'] ?? null;
        $nonce = $payload['n'] ?? null;

        if ($schoolId != $teacher->school_id) {
             throw new AttendanceException('QR Code tidak valid untuk sekolah ini.');
        }

        // 3. Find & Validate Student
        $student = User::where('id', $studentId)
            ->where('school_id', $teacher->school_id)
            ->where('role_type', 'student')
            ->first();

        if (! $student || ! $student->is_active) {
            throw AttendanceException::studentNotFound();
        }

        // 4. Find Schedule (Must be owned by teacher)
        $schedule = $this->findTeacherSchedule($teacher, $request);

        // 5. Verify Student in Class
        $this->validateStudentInClass($student, $schedule);

        // 6. Validate Teacher Location (Geofence)
        $this->validateLocation($teacher->school, $data['lat'] ?? null, $data['lng'] ?? null, $request);

        // 7. Validate Time Window
        $attendanceStatus = $this->validateTimeWindow($schedule, $teacher->school);

        // 8. Atomic Check-In
        $requestId = $data['request_id'] ?? (string) Str::uuid();

        $attendance = $this->atomicCheckIn(
            $student,
            $schedule,
            $attendanceStatus,
            $data,
            $requestId,
            $nonce,
            $request,
            'teacher_scan',
            $teacher->id
        );

        return new AttendanceResult(
            success: true,
            attendance: $attendance,
            message: 'Absensi berhasil dicatat oleh guru.',
            status: $attendanceStatus,
            isIdempotentRetry: false
        );
    }

    /**
     * Find attendance by request_id for idempotency support
     *
     * This allows clients to safely retry requests without creating duplicates.
     * The client should generate a UUID client-side and include it in the request.
     *
     * USE CASES:
     * 1. Network timeout - client retries with same request_id
     * 2. Offline sync - mobile app queues requests with pre-generated IDs
     * 3. App crash recovery - request data persisted with ID
     *
     * @param  string  $requestId  The unique request identifier
     * @return Attendance|null Existing attendance or null if not found
     */
    private function findByRequestId(string $requestId): ?Attendance
    {
        if (empty($requestId)) {
            return null;
        }

        return Attendance::where('request_id', $requestId)->first();
    }

    /**
     * STEP 1: Validate student exists and is active
     */
    private function validateStudent(User $student, ?Request $request = null): void
    {
        if (! $student->is_active) {
            if ($request) {
                $this->logger->checkInFailed($request, 'student_inactive', [
                    'student_id' => $student->id,
                    'school_id' => $student->school_id,
                ]);
            }
            throw AttendanceException::studentNotFound();
        }

        if ($student->role_type !== 'student') {
            if ($request) {
                $this->logger->checkInFailed($request, 'invalid_role', [
                    'role_type' => $student->role_type,
                ]);
            }
            throw AttendanceException::invalidRole();
        }
    }

    /**
     * STEP 2: Validate QR token signature and extract payload
     *
     * SERVER-TRUST PRINCIPLE:
     * - Client timestamps are ONLY used for validation
     * - Actual attendance timestamps use server time (now())
     * - Detects and logs clock manipulation attempts
     */
    private function validateQrToken(string $token, ?Request $request = null): array
    {
        if (empty($token)) {
            if ($request) {
                $this->logger->invalidQr($request, 'empty_token');
            }
            throw AttendanceException::invalidQrCode();
        }

        try {
            $payload = $this->qrService->verify($token);

            // Validate QR timestamp with server-trust principles
            $this->validateQrTimestamp($payload, $request);

            return $payload;
        } catch (AttendanceException $e) {
            // Re-throw attendance exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            if ($request) {
                $this->logger->invalidQr($request, 'verification_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            throw AttendanceException::invalidQrCode();
        }
    }

    /**
     * Validate QR timestamp with server-trust principles
     *
     * RULES:
     * 1. generated_at must NOT be in the future (clock tampering detection)
     * 2. generated_at must NOT be older than configured expiry
     * 3. Only server time is used for actual attendance records
     *
     * @param  array  $payload  The QR payload
     * @param  Request|null  $request  For logging context
     *
     * @throws AttendanceException
     */
    private function validateQrTimestamp(array $payload, ?Request $request = null): void
    {
        $serverNow = now()->timestamp;

        // Get timestamp from various possible field names
        $qrTimestamp = $payload['generated_at']
            ?? $payload['exp'] - $this->getQrExpirySeconds()
            ?? null;

        $expTimestamp = $payload['exp'] ?? null;

        // If no timestamp available, skip time validation (legacy QR)
        if (! $qrTimestamp && ! $expTimestamp) {
            Log::channel('attendance_security')->warning('QR without timestamp detected', [
                'payload_keys' => array_keys($payload),
                'ip' => $request?->ip(),
            ]);

            return;
        }

        // RULE 1: Detect future timestamps (clock tampering)
        // Allow 5 seconds of clock skew for network/processing delays
        $clockSkewTolerance = 5;

        if ($qrTimestamp && $qrTimestamp > ($serverNow + $clockSkewTolerance)) {
            $this->logClockManipulation($request, 'future_timestamp', [
                'qr_generated_at' => $qrTimestamp,
                'server_time' => $serverNow,
                'difference_seconds' => $qrTimestamp - $serverNow,
                'student_id' => $payload['student_id'] ?? $payload['id'] ?? null,
            ]);

            throw AttendanceException::custom(
                'Waktu QR tidak valid. Pastikan waktu perangkat Anda sudah benar.'
            );
        }

        // RULE 2: Check expiration based on server time
        $expirySeconds = $this->getQrExpirySeconds();

        if ($expTimestamp) {
            // If exp field exists, use it directly
            if ($serverNow > $expTimestamp) {
                $secondsExpired = $serverNow - $expTimestamp;

                if ($request) {
                    $this->logger->expiredQr($request, [
                        'schedule_id' => $payload['id'] ?? null,
                        'expires_at' => $expTimestamp,
                        'seconds_expired' => $secondsExpired,
                    ]);
                }

                throw AttendanceException::custom(
                    'Kode QR sudah kadaluarsa. Minta guru untuk menampilkan QR baru.'
                );
            }
        } elseif ($qrTimestamp) {
            // Calculate expiry from generated_at
            $expiresAt = $qrTimestamp + $expirySeconds;

            if ($serverNow > $expiresAt) {
                $secondsExpired = $serverNow - $expiresAt;

                if ($request) {
                    $this->logger->expiredQr($request, [
                        'schedule_id' => $payload['id'] ?? null,
                        'generated_at' => $qrTimestamp,
                        'expires_at' => $expiresAt,
                        'seconds_expired' => $secondsExpired,
                    ]);
                }

                throw AttendanceException::custom(
                    'Kode QR sudah kadaluarsa. Minta guru untuk menampilkan QR baru.'
                );
            }
        }

        // RULE 3: Detect suspiciously old QR (possible replay from different day)
        $maxAgeSeconds = 3600; // 1 hour max
        if ($qrTimestamp && ($serverNow - $qrTimestamp) > $maxAgeSeconds) {
            $this->logClockManipulation($request, 'stale_qr', [
                'qr_generated_at' => $qrTimestamp,
                'server_time' => $serverNow,
                'age_seconds' => $serverNow - $qrTimestamp,
                'max_age_seconds' => $maxAgeSeconds,
            ]);

            throw AttendanceException::custom('Kode QR terlalu lama. Gunakan QR yang baru.');
        }
    }

    /**
     * Get QR expiry seconds from config
     */
    private function getQrExpirySeconds(): int
    {
        return config('qr.signature_expiration_seconds', 10);
    }

    /**
     * Log clock manipulation attempt as security event
     */
    private function logClockManipulation(?Request $request, string $type, array $context): void
    {
        $logData = array_merge($context, [
            'event' => 'security.clock_manipulation',
            'manipulation_type' => $type,
            'severity' => 'HIGH',
            'is_security_event' => true,
            'ip_address' => $request?->ip(),
            'user_agent' => substr($request?->userAgent() ?? '', 0, 100),
            'device_id' => $request?->header('X-Device-ID'),
            'timestamp' => now()->toIso8601String(),
            'recommendation' => match ($type) {
                'future_timestamp' => 'Client clock is ahead of server - possible tampering',
                'stale_qr' => 'QR is unusually old - possible replay from different session',
                default => 'Investigate timestamp anomaly',
            },
        ]);

        Log::channel('security_json')->warning('Clock Manipulation Detected', $logData);
        Log::channel('attendance_security')->warning("Clock Manipulation: {$type}", $logData);
    }

    /**
     * STEP 3: Find active schedule for today
     */
    private function findActiveSchedule(?int $scheduleId, int $schoolId, ?Request $request = null): Schedule
    {
        if (! $scheduleId) {
            if ($request) {
                $this->logger->checkInFailed($request, 'no_schedule_id');
            }
            throw AttendanceException::scheduleNotFound();
        }

        $schedule = Schedule::where('id', $scheduleId)
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->where('day_of_week', strtolower(now()->format('l')))
            ->first();

        if (! $schedule) {
            if ($request) {
                $this->logger->checkInFailed($request, 'schedule_not_found', [
                    'schedule_id' => $scheduleId,
                    'day_of_week' => strtolower(now()->format('l')),
                ]);
            }
            throw AttendanceException::noActiveSchedule();
        }

        return $schedule;
    }

    /**
     * STEP 4: Validate device ID (anti-joki)
     */
    private function validateDevice(User $student, ?string $deviceId, ?Request $request = null): void
    {
        // If student has registered device and incoming device doesn't match
        if ($student->device_id && $deviceId && $student->device_id !== $deviceId) {
            if ($request) {
                $this->logger->securityAnomaly(
                    $request,
                    'device_mismatch',
                    'Student attempt with different device (potential joki)',
                    [
                        'registered_device' => substr($student->device_id, 0, 8).'...',
                        'incoming_device' => substr($deviceId, 0, 8).'...',
                        'severity' => 'high',
                    ]
                );
            }
            throw AttendanceException::deviceMismatch();
        }
    }

    /**
     * STEP 5: Validate geofence location
     */
    private function validateLocation(
        ?School $school,
        ?float $lat,
        ?float $lng,
        ?Request $request = null
    ): void {
        if (! $school || ! $school->latitude || ! $school->longitude) {
            // No geofence configured, skip validation
            return;
        }

        if (! $lat || ! $lng) {
            // Location not provided, skip validation (or throw if required)
            return;
        }

        $distance = $this->calculateDistance($lat, $lng, $school->latitude, $school->longitude);
        $maxRadius = $school->radius_meters ?? 100;

        if ($distance > $maxRadius) {
            if ($request) {
                $this->logger->securityAnomaly(
                    $request,
                    'outside_geofence',
                    'Attendance attempt from outside allowed radius',
                    [
                        'distance_meters' => round($distance, 2),
                        'max_radius' => $maxRadius,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'severity' => 'medium',
                    ]
                );
            }
            throw AttendanceException::outsideRadius();
        }
    }

    /**
     * STEP 6: Validate time window and determine status
     */
    private function validateTimeWindow(Schedule $schedule, ?School $school): string
    {
        $now = now();
        $today = $now->format('Y-m-d');

        $startTime = Carbon::parse("{$today} {$schedule->start_time}");
        $endTime = Carbon::parse("{$today} {$schedule->end_time}");

        // Get grace period settings
        $settings = $school?->settings ?? [];
        $earlyGrace = $settings['attendance_grace_early'] ?? 15;
        $lateTolerance = $settings['attendance_grace_late'] ?? 15;

        $earliestAllowed = $startTime->copy()->subMinutes($earlyGrace);
        $lateThreshold = $startTime->copy()->addMinutes($lateTolerance);

        // Too early
        if ($now->lessThan($earliestAllowed)) {
            throw AttendanceException::custom(
                "Absensi belum dibuka. Silakan scan mulai pukul {$earliestAllowed->format('H:i')}."
            );
        }

        // Class ended
        if ($now->greaterThan($endTime)) {
            throw AttendanceException::outsideTimeWindow();
        }

        // Determine status: present or late
        return $now->greaterThan($lateThreshold) ? 'late' : 'present';
    }

    /**
     * STEP 7: Atomic check-in with DB transaction and row-level locking
     *
     * SERVER-TRUST PRINCIPLE:
     * - attendance_date = SERVER date (now()->toDateString())
     * - check_in_time = SERVER time (now())
     * - Client-provided timestamps are NEVER used for the attendance record
     * - This prevents clients from manipulating their attendance time
     */
    private function atomicCheckIn(
        User $student,
        Schedule $schedule,
        string $status,
        array $data,
        string $requestId,
        ?string $nonce,
        ?Request $request = null
    ): Attendance {
        /*
         * CONCURRENCY PROTECTION STRATEGY
         * ===============================
         *
         * Layer 1: Application-Level Lock (Cache/Redis)
         * - Provides fast, distributed lock before touching the database
         * - Reduces database contention under high load
         * MULTI-LAYER CONCURRENCY PROTECTION
         * ===================================
         *
         * Layer 1: Application-level distributed lock (NEW: AttendanceLockService)
         * - Prevents concurrent requests from different app servers
         * - Retry mechanism with exponential backoff
         * - Comprehensive logging
         *
         * Layer 2: Database transaction with serializable isolation
         * - Ensures atomicity of read-check-insert
         * - Rollback on any error
         *
         * Layer 3: Row-level locking (SELECT ... FOR UPDATE)
         * - Prevents concurrent INSERTs of the same unique row
         * - Requires proper composite index on (student_id, schedule_id, attendance_date)
         */

        // Layer 1: Application-level distributed lock with retry
        return $this->lockService->lockStudentCheckIn(
            $student->id,
            $schedule->id,
            now()->toDateString(),
            function () use ($student, $schedule, $status, $data, $requestId, $nonce, $request) {
                // Layer 2: Database transaction with serializable isolation for this critical section
                return DB::transaction(function () use ($student, $schedule, $status, $data, $requestId, $nonce, $request) {
                    // SERVER TIME - Used for all timestamp comparisons
                    $serverNow = now();
                    $serverDate = $serverNow->toDateString();

                    /*
                     * Layer 3 & 4: Row Lock with Gap Lock Fallback
                     *
                     * CRITICAL: This MUST happen INSIDE the transaction
                     *
                     * Case A: Row EXISTS
                     * - lockForUpdate() acquires exclusive lock on the row
                     * - Other transactions WAIT at this point
                     * - When lock is acquired, we check and reject duplicate
                     *
                     * Case B: Row DOES NOT EXIST
                     * - lockForUpdate() returns NULL (no row to lock)
                     * - We acquire a "gap lock" on the unique index
                     * - This prevents concurrent INSERT with same (student, schedule, date)
                     */

                    // Acquire lock on existing row OR gap in index
                    $existing = $this->findExistingAttendanceWithLock(
                        $student->id,
                        $schedule->id,
                        $serverDate
                    );

                    if ($existing) {
                        // Row exists - we have exclusive lock on it

                        // Idempotency: same request_id returns original (for retry handling)
                        if ($requestId && $existing->request_id === $requestId) {
                            Log::channel('attendance')->debug('Idempotent retry detected', [
                                'attendance_id' => $existing->id,
                                'request_id' => $requestId,
                            ]);

                            return $existing;
                        }

                        // Different request trying to check in = duplicate attempt
                        if ($request) {
                            $this->logger->checkInDuplicate($request, $existing->id, [
                                'schedule_id' => $schedule->id,
                                'existing_check_in_time' => $existing->check_in_time?->toTimeString(),
                                'new_request_id' => $requestId,
                            ]);
                        }

                        throw AttendanceException::alreadyRecorded();
                    }

                    // No existing row - we hold gap lock, safe to INSERT

                    // A. Validate nonce for race condition prevention
                    if ($nonce) {
                        $this->validateNonce($nonce, $student->id, $schedule->id);
                    }

                // B. Register device if first time
                if (empty($student->device_id) && ! empty($data['device_id'])) {
                    $student->update(['device_id' => $data['device_id']]);
                }

                // C. Create attendance record
                // The unique constraint on (student_id, schedule_id, attendance_date)
                // plus our gap lock guarantees no duplicate can be inserted
                $attendanceType = $request ? 'qr_scan' : 'manual';
                $recordedBy = $request ? null : auth()->id();
                
                try {
                    $attendance = Attendance::firstOrCreate(
                        [
                            'student_id' => $student->id,
                            'schedule_id' => $schedule->id,
                            'attendance_date' => $serverDate,
                            'school_id' => $student->school_id,
                        ],
                        [
                            'status' => $status,
                            'check_in_time' => $serverNow,
                            'lat_in' => $data['lat'] ?? null,
                            'lng_in' => $data['lng'] ?? null,
                            'device_id_in' => $data['device_id'] ?? null,
                            'is_manual' => false,
                            'attendance_type' => $attendanceType,
                            'recorded_by' => $recordedBy,
                            'request_id' => $requestId,
                            'nonce' => $nonce,
                            'client_scanned_at' => isset($data['scanned_at'])
                                ? Carbon::parse($data['scanned_at'])->toDateTimeString()
                                : null,
                        ]
                    );
                } catch (\Illuminate\Database\QueryException $e) {
                    // Handle race condition edge case: unique constraint violation
                    // This can happen if gap lock wasn't acquired (e.g., MyISAM table)
                    if ($this->isDuplicateKeyException($e)) {
                        Log::channel('attendance_security')->warning('Duplicate key race condition caught', [
                            'student_id' => $student->id,
                            'schedule_id' => $schedule->id,
                            'date' => $serverDate,
                        ]);

                        // IMPROVEMENT: Return existing record instead of throwing
                        // This makes the API idempotent for concurrent requests
                        $existingRecord = Attendance::where('student_id', $student->id)
                            ->where('schedule_id', $schedule->id)
                            ->whereDate('attendance_date', $serverDate)
                            ->first();

                        if ($existingRecord) {
                            return $existingRecord;
                        }

                        // If we can't find the record (edge case), throw
                        throw AttendanceException::alreadyRecorded();
                    }
                    throw $e;
                }

                // D. Log successful check-in
                if ($request) {
                    $this->logger->checkInSuccess($attendance, $request, [
                        'scan_method' => 'qr_scan',
                        'latitude' => $data['lat'] ?? null,
                        'longitude' => $data['lng'] ?? null,
                        'accuracy' => $data['accuracy'] ?? null,
                    ]);

                    if ($status === 'late') {
                        $scheduledStart = Carbon::parse($schedule->start_time);
                        $minutesLate = now()->diffInMinutes($scheduledStart);

                        $this->logger->lateCheckIn($attendance, $request, $minutesLate, [
                            'scheduled_start' => $scheduledStart->toTimeString(),
                        ]);
                    }
                }

                return $attendance;
            });
        });
    }

    /**
     * Find existing attendance with exclusive row lock
     *
     * CONCURRENCY BEHAVIOR:
     *
     * 1. If row EXISTS:
     *    - Acquires exclusive lock (X lock) on the row
     *    - Other transactions will BLOCK at their lockForUpdate() call
     *    - Lock is held until transaction COMMIT or ROLLBACK
     *
     * 2. If row DOES NOT EXIST:
     *    - In InnoDB with proper index, acquires a "gap lock"
     *    - Gap lock prevents INSERT of row with same key values
     *    - This is why the unique index on (student_id, schedule_id, attendance_date) is CRITICAL
     *
     * WHY THIS PREVENTS DUPLICATES:
     *
     * Timeline WITHOUT proper locking:
     *   T1: Check exists? → No
     *   T2: Check exists? → No        (T1's insert not committed yet)
     *   T1: INSERT → Success
     *   T2: INSERT → DUPLICATE!       (or worse, both succeed with race)
     *
     * Timeline WITH lockForUpdate():
     *   T1: Check exists with LOCK → No (acquires gap lock)
     *   T2: Check exists with LOCK → BLOCKED (waiting for T1's lock)
     *   T1: INSERT → Success, COMMIT → releases lock
     *   T2: Lock acquired → Check exists? → YES! → Reject duplicate
     *
     * @param  int  $studentId  Student ID
     * @param  int  $scheduleId  Schedule ID
     * @param  string  $date  Attendance date (Y-m-d)
     * @return Attendance|null Existing attendance or null
     */
    private function findExistingAttendanceWithLock(
        int $studentId,
        int $scheduleId,
        string $date
    ): ?Attendance {
        // Uses the composite index: idx_attendance_unique (student_id, schedule_id, attendance_date)
        // This enables efficient gap locking in InnoDB
        return Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', $date)
            ->lockForUpdate()  // SELECT ... FOR UPDATE
            ->first();
    }

    /**
     * @deprecated Use findExistingAttendanceWithLock instead
     */
    private function findExistingAttendance(
        int $studentId,
        int $scheduleId,
        string $date,
        bool $lockForUpdate = false
    ): ?Attendance {
        $query = Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', $date);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Check if exception is a duplicate key violation
     */
    private function isDuplicateKeyException(\Illuminate\Database\QueryException $e): bool
    {
        $errorCode = $e->errorInfo[1] ?? null;

        // MySQL: 1062 = Duplicate entry
        // PostgreSQL: 23505 = unique_violation
        return in_array($errorCode, [1062, 23505], true)
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'unique constraint');
    }

    /**
     * Haversine formula for distance calculation (in meters)
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Earth's radius in meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }

    /**
     * Check if student already checked in today (without schedule)
     */
    public function hasCheckedInToday(int $studentId): bool
    {
        return Attendance::where('student_id', $studentId)
            ->whereDate('attendance_date', now()->toDateString())
            ->exists();
    }

    /**
     * Handle attendance recording via Teacher Scan (Migrated from AttendanceService)
     *
     * @param  User  $teacher
     * @param  string  $qrToken
     * @param  float|null  $lat
     * @param  float|null  $lng
     * @param  string|null  $deviceId
     * @param  string|null  $requestId
     * @return array
     * @throws AttendanceException
     */
    public function recordByTeacherScan(
        User $teacher,
        string $qrToken,
        ?float $lat,
        ?float $lng,
        ?string $deviceId = null,
        ?string $requestId = null,
    ): array {
        // 1. Verify Teacher Role
        if ($teacher->role_type !== 'teacher') {
            $this->logSecurityAnomaly('invalid_role_scan_attempt', [
                'user_id' => $teacher->id,
                'role' => $teacher->role_type,
                'expected' => 'teacher',
            ]);
            throw AttendanceException::invalidRole();
        }

        // 2. Verify QR & Extract Payload
        try {
            $payload = $this->qrService->verify($qrToken);
        } catch (\Exception $e) {
            $this->logSecurityEvent('signature_failed', $teacher, $qrToken, $e->getMessage());
            throw new AttendanceException('QR Code tidak valid atau rusak.');
        }

        $studentId = $payload['sid'] ?? $payload['student_id'] ?? null;
        $schoolId = $payload['sch'] ?? $payload['school_id'] ?? null;
        $nonce = $payload['n'] ?? $payload['nonce'] ?? null;

        // Validate school isolation
        if ($schoolId != $teacher->school_id) {
             $this->logSecurityAnomaly('cross_school_scan_attempt', [
                'teacher_id' => $teacher->id,
                'teacher_school' => $teacher->school_id,
                'qr_school' => $schoolId,
            ]);
            throw new AttendanceException('QR Code tidak valid untuk sekolah ini.');
        }

        // Check QR expiration
        if (isset($payload['exp']) && $payload['exp'] < now()->timestamp) {
            $this->logSecurityEvent('qr_expired', $teacher, $qrToken, 'QR Expired');
            throw AttendanceException::expired();
        }

        // 3. Validate Student
        try {
            $student = $this->qrService->validateStudentStatus($payload, $teacher->school_id);
        } catch (\Exception $e) {
             $this->logSecurityEvent('student_validation_failed', $teacher, $qrToken, $e->getMessage());
             throw new AttendanceException($e->getMessage());
        }

        // 4. Find Active Schedule (Teacher specific logic)
        $dayOfWeek = now()->dayOfWeek;
        $now = now();
        
        // Get tolerances from policy service if available, otherwise default
        $toleranceBefore = $this->policyService ? $this->policyService->getScheduleToleranceBefore($teacher->school_id) : 15;
        $toleranceAfter = $this->policyService ? $this->policyService->getScheduleToleranceAfter($teacher->school_id) : 15;

        $windowStartTime = $now->copy()->subMinutes($toleranceAfter)->format('H:i:s');
        $windowEndTime = $now->copy()->addMinutes($toleranceBefore)->format('H:i:s');

        $schedule = Schedule::with(['class', 'subject'])
            ->where('school_id', $teacher->school_id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->where('start_time', '<=', $windowEndTime)
            ->where('end_time', '>=', $windowStartTime)
            ->first();

        if (! $schedule) {
            throw AttendanceException::scheduleNotFound();
        }

        if ($schedule->teacher_id !== $teacher->id) {
             $this->logSecurityAnomaly('unauthorized_schedule_scan', [
                'teacher_id' => $teacher->id,
                'schedule_id' => $schedule->id,
                'schedule_teacher_id' => $schedule->teacher_id,
            ]);
            throw new AttendanceException('Anda bukan pengajar pada jadwal ini.');
        }

        // 5. Verify Time Window (Strict)
        $scheduleStart = Carbon::parse($schedule->start_time);
        $scheduleEnd = Carbon::parse($schedule->end_time);
        $windowStart = $scheduleStart->copy()->subMinutes($toleranceBefore);
        $windowEnd = $scheduleEnd->copy()->addMinutes($toleranceAfter);
        $currentTimeCarbon = Carbon::parse($now->format('H:i:s'));

        if ($currentTimeCarbon->lt($windowStart) || $currentTimeCarbon->gt($windowEnd)) {
             throw AttendanceException::outsideTimeWindow();
        }

        // 6. Verify Class Match
        $isStudentInClass = ClassStudent::where('student_id', $student->id)
            ->where('class_id', $schedule->class_id)
            ->where('status', 'active')
            ->exists();

        if (! $isStudentInClass) {
             $this->logSecurityAnomaly('student_class_mismatch', [
                'student_id' => $student->id,
                'student_class_id' => $student->class_id ?? 'N/A',
                'schedule_class_id' => $schedule->class_id,
            ]);
            throw AttendanceException::studentNotInClass();
        }

        // 7. Atomic Check-in with retry mechanism
        return $this->lockService->lockTeacherCheckIn(
            $teacher->id,
            $student->id,
            $schedule->id,
            today()->format('Y-m-d'),
            function () use ($teacher, $student, $schedule, $schoolId, $nonce, $lat, $lng, $deviceId, $requestId, $qrToken) {
                return DB::transaction(function () use ($teacher, $student, $schedule, $schoolId, $nonce, $lat, $lng, $deviceId, $requestId, $qrToken) {
                    // Nonce Check
                    if ($nonce && $this->replayPreventionService) {
                         if ($this->replayPreventionService->checkNonceReplay($nonce, $schoolId)) {
                             $this->replayPreventionService->logRepeatedAttempt($student->id, $schedule->id, $schoolId, $nonce, 'teacher_scan_nonce_replay');
                             $this->logSecurityEvent('nonce_replay', $teacher, $qrToken, 'QR nonce already used');
                             throw AttendanceException::replayDetected();
                         }
                    }

                // Check Existing
                $existingAttendance = $this->findExistingAttendanceWithLock($student->id, $schedule->id, today()->toDateString());
                
                if ($existingAttendance) {
                     if ($this->replayPreventionService) {
                        $this->replayPreventionService->markStudentScanned($student->id, $schedule->id, $schoolId, $existingAttendance->id);
                     }
                     throw AttendanceException::alreadyRecorded();
                }

                // Idempotency
                $reqId = $requestId ?: request()->header('X-Request-ID');
                if ($reqId) {
                    $existing = Attendance::where('request_id', $reqId)->lockForUpdate()->first();
                    if ($existing) {
                         return [
                            'attendance' => $existing,
                            'student' => $student,
                            'schedule' => $schedule
                        ];
                    }
                }

                // Create (using firstOrCreate)
                $status = $this->determineAttendanceStatus($schedule, $teacher->school_id);
                $attendance = Attendance::firstOrCreate(
                    [
                        'student_id' => $student->id,
                        'schedule_id' => $schedule->id,
                        'attendance_date' => today(),
                        'school_id' => $teacher->school_id,
                    ],
                    [
                        'class_id' => $schedule->class_id,
                        'subject_id' => $schedule->subject_id,
                        'attendance_type' => 'teacher_scan',
                        'status' => $status,
                        'check_in_time' => now(),
                        'lat_in' => $lat,
                        'lng_in' => $lng,
                        'device_id_in' => $deviceId,
                        'is_manual' => false,
                        'recorded_by' => $teacher->id,
                        'request_id' => $reqId,
                        'nonce' => $nonce,
                    ]
                );

                // Post-Create Actions
                if ($nonce && $this->replayPreventionService) {
                    $this->replayPreventionService->markNonceUsed($nonce, $schoolId, $student->id, $schedule->id);
                }
                if ($this->replayPreventionService) {
                    $this->replayPreventionService->markStudentScanned($student->id, $schedule->id, $schoolId, $attendance->id);
                }

                return [
                    'attendance' => $attendance,
                    'student' => $student,
                    'schedule' => $schedule
                ];
            });
        });
    }

    private function logSecurityAnomaly(string $type, array $context): void
    {
        Log::channel('security_json')->warning("Security Anomaly: $type", $context);
    }

    private function logSecurityEvent(string $type, User $user, string $token, string $message): void
    {
        Log::channel('security_json')->warning("Security Event: $type", [
            'user_id' => $user->id,
            'token_preview' => substr($token, 0, 10) . '...',
            'message' => $message
        ]);
    }

    private function determineAttendanceStatus(Schedule $schedule, int $schoolId): string
    {
        $lateTolerance = $this->policyService ? $this->policyService->getScheduleToleranceAfter($schoolId) : 15;
        $lateThreshold = Carbon::parse($schedule->start_time)->addMinutes($lateTolerance);
        return now()->format('H:i:s') > $lateThreshold->format('H:i:s') ? 'late' : 'present';
    }

    /**
     * Validate nonce to prevent race conditions and replay attacks
     *
     * @param string $nonce The nonce from QR token
     * @param int $studentId Student ID
     * @param int $scheduleId Schedule ID
     * @throws AttendanceException
     */
    private function validateNonce(string $nonce, int $studentId, int $scheduleId): void
    {
        // Check if nonce exists and is valid
        $qrNonce = \App\Models\QrNonce::where('nonce', $nonce)
            ->where('student_id', $studentId)
            ->where('qr_code_id', $scheduleId) // Assuming qr_code_id maps to schedule
            ->first();

        if (!$qrNonce) {
            throw new AttendanceException('QR Code tidak valid atau sudah kedaluwarsa.');
        }

        if (!$qrNonce->isValid()) {
            throw new AttendanceException('QR Code sudah digunakan atau kedaluwarsa.');
        }

        // Mark nonce as used to prevent replay
        $qrNonce->markAsUsed();
    }
}
