<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Services\Logging\AttendanceLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Events\AttendanceRecorded;
use App\Events\AttendanceLate;

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
 * @version 2.0.0
 */
final class AttendanceCheckInService
{
    /**
     * Lock timeout for preventing race conditions (seconds)
     */
    private const LOCK_TIMEOUT = 5;

    /**
     * Lock wait time (seconds)
     */
    private const LOCK_WAIT = 3;

    public function __construct(
        private StudentQrService $qrService,
        private AttendanceLogger $logger,
        private StudentNotificationService $notificationService,
        private GamificationService $gamificationService,
        private ?QRSignatureService $signatureService = null
    ) {}

    /**
     * Process student check-in via QR scan
     *
     * @param User $student The authenticated student
     * @param array $data Scan data with keys: qr_token, lat, lng, device_id, request_id
     * @param Request|null $request HTTP request for logging context
     * @return AttendanceResult
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
     * @param string $requestId The unique request identifier
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
        if (!$student->is_active) {
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
     * @param array $payload The QR payload
     * @param Request|null $request For logging context
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
        if (!$qrTimestamp && !$expTimestamp) {
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
        if (!$scheduleId) {
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

        if (!$schedule) {
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
                        'registered_device' => substr($student->device_id, 0, 8) . '...',
                        'incoming_device' => substr($deviceId, 0, 8) . '...',
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
        if (!$school || !$school->latitude || !$school->longitude) {
            // No geofence configured, skip validation
            return;
        }

        if (!$lat || !$lng) {
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
         * - Works across multiple app servers
         * 
         * Layer 2: Database Transaction
         * - Ensures all-or-nothing semantics
         * - Auto-rollback on any failure
         * 
         * Layer 3: Database Row Lock (SELECT ... FOR UPDATE)
         * - Locks specific row(s) for the duration of transaction
         * - Other transactions WAIT until lock is released
         * - Prevents concurrent modifications to same row
         * 
         * Layer 4: Gap Lock (for non-existent rows)
         * - When no row exists, acquires lock on the "gap" in the index
         * - Prevents concurrent INSERTs of the same unique row
         * - Requires proper composite index on (student_id, schedule_id, attendance_date)
         */

        // Layer 1: Application-level distributed lock
        $lockKey = "attendance_checkin:{$student->id}:{$schedule->id}:" . now()->toDateString();

        return Cache::lock($lockKey, self::LOCK_TIMEOUT)->block(self::LOCK_WAIT, function () use (
            $student, $schedule, $status, $data, $requestId, $nonce, $request
        ) {
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
                
                // B. Register device if first time
                if (empty($student->device_id) && !empty($data['device_id'])) {
                    $student->update(['device_id' => $data['device_id']]);
                }

                // C. Create attendance record
                // The unique constraint on (student_id, schedule_id, attendance_date)
                // plus our gap lock guarantees no duplicate can be inserted
                try {
                    $attendance = Attendance::create([
                        'school_id' => $student->school_id,
                        'schedule_id' => $schedule->id,
                        'student_id' => $student->id,
                        'attendance_date' => $serverDate,
                        'status' => $status,
                        'check_in_time' => $serverNow,
                        'lat_in' => $data['lat'] ?? null,
                        'lng_in' => $data['lng'] ?? null,
                        'device_id_in' => $data['device_id'] ?? null,
                        'is_manual' => false,
                        'request_id' => $requestId,
                        'nonce' => $nonce,
                        'client_scanned_at' => isset($data['scanned_at']) 
                            ? Carbon::parse($data['scanned_at'])->toDateTimeString() 
                            : null,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Handle race condition edge case: unique constraint violation
                    // This can happen if gap lock wasn't acquired (e.g., MyISAM table)
                    if ($this->isDuplicateKeyException($e)) {
                        Log::channel('attendance_security')->warning('Duplicate key race condition caught', [
                            'student_id' => $student->id,
                            'schedule_id' => $schedule->id,
                            'date' => $serverDate,
                        ]);
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
     * @param int $studentId Student ID
     * @param int $scheduleId Schedule ID  
     * @param string $date Attendance date (Y-m-d)
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
}
