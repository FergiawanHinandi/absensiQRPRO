<?php

namespace App\Services;

use App\Core\Services\Attendance\QrReplayPreventionService;
use App\Events\AttendanceLate;
use App\Events\AttendanceRecorded;
use App\Events\StudentAttended;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\ClassStudent;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttendanceService
{
    protected StudentQrService $qrService;

    protected QrReplayPreventionService $replayPreventionService;

    protected SecurityAlertService $alertService;

    protected SecurityPolicyService $policyService;

    /**
     * Lock timeout for preventing race conditions (seconds)
     */
    private const ATTENDANCE_LOCK_TIMEOUT = 5;

    public function __construct(
        StudentQrService $qrService,
        QrReplayPreventionService $replayPreventionService,
        SecurityAlertService $alertService,
        SecurityPolicyService $policyService,
    ) {
        $this->qrService = $qrService;
        $this->replayPreventionService = $replayPreventionService;
        $this->alertService = $alertService;
        $this->policyService = $policyService;
    }

    /**
     * Handle attendance recording via Teacher Scan
     *
     * STRICT RULES:
     * - Only teachers can scan
     * - Teacher must own the schedule
     * - Student must be in the schedule's class
     * - Must be within time window
     * - Atomic transaction with row-level locking
     *
     * @param  User  $teacher  The teacher performing the scan
     * @param  string  $qrToken  The encrypted QR token from student card
     * @param  float|null  $lat  Latitude
     * @param  float|null  $lng  Longitude
     * @return array Result data for response
     *
     * @throws AttendanceException Business logic errors (safe to show user)
     * @throws \Exception Unexpected errors (logged internally)
     */
    public function recordByTeacherScan(
        User $teacher,
        string $qrToken,
        ?float $lat,
        ?float $lng,
        ?string $deviceId = null,
        ?string $requestId = null,
    ): array {
        // ============================================================
        // STEP 1: VERIFY TEACHER ROLE
        // ============================================================
        if ($teacher->role_type !== 'teacher') {
            $this->logSecurityAnomaly('invalid_role_scan_attempt', [
                'user_id' => $teacher->id,
                'role' => $teacher->role_type,
                'expected' => 'teacher',
            ]);
            throw AttendanceException::invalidRole();
        }

        // ============================================================
        // STEP 2: VERIFY QR SIGNATURE & EXTRACT PAYLOAD
        // ============================================================
        try {
            $payload = $this->qrService->verify($qrToken);
        } catch (\Exception $e) {
            $this->logSecurityEvent(
                'signature_failed',
                $teacher,
                $qrToken,
                $e->getMessage(),
            );
            throw new AttendanceException('QR Code tidak valid atau rusak.');
        }

        $studentId = $payload['sid'];
        $schoolId = $payload['sch'];
        $nonce = $payload['n'] ?? null;

        // Validate school isolation
        if ($schoolId !== $teacher->school_id) {
            $this->logSecurityAnomaly('cross_school_scan_attempt', [
                'teacher_id' => $teacher->id,
                'teacher_school' => $teacher->school_id,
                'qr_school' => $schoolId,
            ]);
            throw new AttendanceException(
                'QR Code tidak valid untuk sekolah ini.',
            );
        }

        // Check QR expiration
        if (isset($payload['exp']) && $payload['exp'] < now()->timestamp) {
            $this->logSecurityEvent(
                'qr_expired',
                $teacher,
                $qrToken,
                'QR Expired',
            );
            throw AttendanceException::expired();
        }

        // ============================================================
        // STEP 3: VALIDATE STUDENT EXISTS AND IS ACTIVE
        // ============================================================
        try {
            $student = $this->qrService->validateStudentStatus(
                $payload,
                $teacher->school_id,
            );
        } catch (\Exception $e) {
            $this->logSecurityEvent(
                'student_validation_failed',
                $teacher,
                $qrToken,
                $e->getMessage(),
            );
            throw new AttendanceException($e->getMessage());
        }

        // ============================================================
        // STEP 4: FIND AND VERIFY SCHEDULE OWNERSHIP
        // ============================================================
        $dayOfWeek = now()->dayOfWeek;
        $now = now();
        $currentTime = $now->format('H:i:s');

        // Get configurable tolerances from policy service
        $toleranceBefore = $this->policyService->getScheduleToleranceBefore(
            $teacher->school_id,
        );
        $toleranceAfter = $this->policyService->getScheduleToleranceAfter(
            $teacher->school_id,
        );

        // Calculate time window boundaries using dynamic policy values
        $windowStartTime = $now
            ->copy()
            ->subMinutes($toleranceAfter)
            ->format('H:i:s');
        $windowEndTime = $now
            ->copy()
            ->addMinutes($toleranceBefore)
            ->format('H:i:s');

        // Load schedule within time window (with tolerance)
        // Teacher can scan X minutes BEFORE schedule starts (configurable)
        // Teacher can scan X minutes AFTER schedule ends (configurable)
        $schedule = Schedule::with(['class', 'subject'])
            ->where('school_id', $teacher->school_id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->where('start_time', '<=', $windowEndTime) // Schedule starts before current + tolerance
            ->where('end_time', '>=', $windowStartTime) // Schedule ends after current - tolerance
            ->first();

        if (! $schedule) {
            throw AttendanceException::scheduleNotFound();
        }

        // CRITICAL: Verify teacher owns this schedule
        if ($schedule->teacher_id !== $teacher->id) {
            $this->logSecurityAnomaly('unauthorized_schedule_scan', [
                'teacher_id' => $teacher->id,
                'schedule_id' => $schedule->id,
                'schedule_teacher_id' => $schedule->teacher_id,
            ]);
            throw new AttendanceException(
                'Anda bukan pengajar pada jadwal ini.',
            );
        }

        // ============================================================
        // STEP 5: VERIFY TIME WINDOW (STRICT)
        // ============================================================
        $scheduleStart = Carbon::parse($schedule->start_time);
        $scheduleEnd = Carbon::parse($schedule->end_time);
        $windowStart = $scheduleStart->copy()->subMinutes($toleranceBefore);
        $windowEnd = $scheduleEnd->copy()->addMinutes($toleranceAfter);

        $currentTimeCarbon = Carbon::parse($now->format('H:i:s'));

        if (
            $currentTimeCarbon->lt($windowStart) ||
            $currentTimeCarbon->gt($windowEnd)
        ) {
            throw AttendanceException::outsideTimeWindow();
        }

        // ============================================================
        // STEP 6: VERIFY CLASS MATCH
        // ============================================================
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

        // ============================================================
        // STEP 6b: VERIFY TEACHER GEOFENCE (Location Validation)
        // ============================================================
        $geofenceEnabled = $this->policyService->isGeofenceEnabled(
            $teacher->school_id,
        );

        if ($geofenceEnabled && $lat !== null && $lng !== null) {
            $school = School::find($teacher->school_id);
            if ($school && $school->latitude && $school->longitude) {
                $distance = $this->calculateDistance(
                    $lat,
                    $lng,
                    $school->latitude,
                    $school->longitude,
                );

                // Get teacher geofence radius from policy service (configurable per school)
                $maxRadius = $this->policyService->getTeacherGeofenceRadius(
                    $teacher->school_id,
                );

                // Allow school-specific override if set
                if ($school->radius_meters) {
                    $maxRadius = $school->radius_meters;
                }

                if ($distance > $maxRadius) {
                    $this->logSecurityAnomaly('teacher_geofence_violation', [
                        'teacher_id' => $teacher->id,
                        'school_id' => $teacher->school_id,
                        'distance' => round($distance, 2),
                        'max_radius' => $maxRadius,
                        'lat' => $lat,
                        'lng' => $lng,
                        'geofence_policy' => 'attendance.teacher_geofence_radius_meters',
                    ]);

                    // Dispatch security alert
                    $this->alertService->alertGeofenceViolation(
                        $teacher,
                        $teacher->school_id,
                        $lat,
                        $lng,
                        round($distance, 2),
                        $maxRadius,
                        request()->ip(),
                    );

                    throw new AttendanceException(
                        "Anda berada di luar radius sekolah ({$distance}m dari titik sekolah, maksimal {$maxRadius}m).",
                    );
                }
            }
        }

        // ============================================================
        // STEP 7: ATOMIC ATTENDANCE RECORDING WITH RACE CONDITION PREVENTION
        // ============================================================
        $lockKey =
            "student_attendance_{$student->id}_{$schedule->id}_".
            today()->format('Y-m-d');

        $lock = Cache::lock($lockKey, self::ATTENDANCE_LOCK_TIMEOUT);

        if (! $lock->get()) {
            // Another request is processing this exact student+schedule
            throw new AttendanceException(
                'Sedang memproses absensi. Coba lagi dalam beberapa detik.',
            );
        }

        try {
            return DB::transaction(function () use (
                $teacher,
                $student,
                $schedule,
                $schoolId,
                $nonce,
                $lat,
                $lng,
                $deviceId,
                $requestId,
                $qrToken,
            ) {
                // ============================================================
                // STEP 7a: CHECK NONCE REPLAY INSIDE TRANSACTION
                // ============================================================
                if ($nonce) {
                    $previousUsage = $this->replayPreventionService->checkNonceReplay(
                        $nonce,
                        $schoolId,
                    );

                    if ($previousUsage) {
                        $this->replayPreventionService->logRepeatedAttempt(
                            $student->id,
                            $schedule->id,
                            $schoolId,
                            $nonce,
                            'teacher_scan_nonce_replay',
                        );

                        $this->logSecurityEvent(
                            'nonce_replay',
                            $teacher,
                            $qrToken,
                            'QR nonce already used',
                        );
                        throw AttendanceException::replayDetected();
                    }
                }

                // ============================================================
                // STEP 7b: CHECK DUPLICATE WITH ROW-LEVEL LOCK
                // ============================================================
                $existingAttendance = Attendance::where(
                    'student_id',
                    $student->id,
                )
                    ->where('schedule_id', $schedule->id)
                    ->whereDate('attendance_date', today())
                    ->lockForUpdate()
                    ->first();

                if ($existingAttendance) {
                    // Update cache to sync
                    $this->replayPreventionService->markStudentScanned(
                        $student->id,
                        $schedule->id,
                        $schoolId,
                        $existingAttendance->id,
                    );
                    throw AttendanceException::alreadyRecorded();
                }

                // ============================================================
                // STEP 7c: IDEMPOTENCY CHECK
                // ============================================================
                $reqId = $requestId ?: request()->header('X-Request-ID');
                if ($reqId) {
                    $existing = Attendance::where('request_id', $reqId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $this->buildSuccessResponse(
                            $student,
                            $schedule,
                            $existing->id,
                        );
                    }
                }

                // ============================================================
                // STEP 7d: CREATE ATTENDANCE RECORD
                // ============================================================
                $attendance = Attendance::create([
                    'school_id' => $teacher->school_id,
                    'class_id' => $schedule->class_id,
                    'schedule_id' => $schedule->id,
                    'subject_id' => $schedule->subject_id,
                    'student_id' => $student->id,
                    'attendance_date' => today(),
                    'attendance_type' => 'teacher_scan',
                    'status' => $this->determineAttendanceStatus(
                        $schedule,
                        $teacher->school_id,
                    ),
                    'check_in_time' => now(),
                    'lat_in' => $lat,
                    'lng_in' => $lng,
                    'device_id_in' => $deviceId,
                    'recorded_by' => $teacher->id, // WHO SCANNED
                    'is_manual' => false,
                    'request_id' => $reqId ?: (string) Str::uuid(),
                ]);

                // ============================================================
                // STEP 7e: MARK NONCE AS USED (INSIDE TRANSACTION)
                // ============================================================
                if ($nonce) {
                    $this->replayPreventionService->markNonceUsed(
                        $nonce,
                        $schoolId,
                        $student->id,
                        $schedule->id,
                    );
                }

                // ============================================================
                // STEP 7f: MARK STUDENT SCANNED IN CACHE
                // ============================================================
                $this->replayPreventionService->markStudentScanned(
                    $student->id,
                    $schedule->id,
                    $schoolId,
                    $attendance->id,
                );

                // Dispatch event which awards points
                StudentAttended::dispatch($attendance, $schoolId);

                // Dispatch notification events
                if ($attendance->status === 'late') {
                    AttendanceLate::dispatch($attendance);
                } else {
                    AttendanceRecorded::dispatch($attendance);
                }

                return $this->buildSuccessResponse(
                    $student,
                    $schedule,
                    $attendance->id,
                );
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Determine attendance status based on time
     */
    private function determineAttendanceStatus(
        Schedule $schedule,
        ?int $schoolId = null,
    ): string {
        $scheduleStart = Carbon::parse($schedule->start_time);
        $now = Carbon::parse(now()->format('H:i:s'));

        // Get late threshold from policy service (configurable per school)
        $lateThreshold = $this->policyService->getLateThreshold($schoolId);

        // If checked in after schedule start + threshold minutes, mark as late
        if ($now->gt($scheduleStart->copy()->addMinutes($lateThreshold))) {
            return 'late';
        }

        return 'present';
    }

    /**
     * Build success response array
     */
    private function buildSuccessResponse(
        User $student,
        Schedule $schedule,
        int $attendanceId,
    ): array {
        return [
            'student_name' => $student->name,
            'class' => $schedule->class->name,
            'subject' => $schedule->subject->name,
            'time' => now()->format('H:i'),
            'status' => 'Berhasil',
            'attendance_id' => $attendanceId,
        ];
    }

    /**
     * Log security events to attendance channel
     */
    private function logSecurityEvent(
        string $type,
        User $actor,
        string $token,
        string $details,
    ): void {
        Log::channel('attendance')->warning("Security Alert: {$type}", [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'token_snippet' => substr($token, 0, 10).'...',
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log security anomalies (unauthorized access attempts)
     */
    private function logSecurityAnomaly(string $type, array $context): void
    {
        Log::channel('security')->alert(
            "ANOMALY: {$type}",
            array_merge($context, [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ]),
        );

        // Track repeated anomalies per IP
        $anomalyKey = "security_anomaly_{$type}_".md5(request()->ip());
        $count = Cache::increment($anomalyKey);

        if ($count === 1) {
            Cache::put($anomalyKey, 1, now()->addHour());
        }

        // Alert on repeated anomalies
        if ($count >= 3) {
            Log::channel('security')->critical("REPEATED ANOMALY: {$type}", [
                'count' => $count,
                'ip' => request()->ip(),
            ]);
        }

        // Dispatch real-time security alerts based on anomaly type
        $this->dispatchSecurityAlert($type, $context);
    }

    /**
     * Dispatch security alerts to monitoring system
     */
    private function dispatchSecurityAlert(string $type, array $context): void
    {
        try {
            $userId =
                $context['teacher_id'] ??
                ($context['student_id'] ?? ($context['user_id'] ?? null));
            $schoolId = $context['school_id'] ?? auth()->user()?->school_id;

            switch ($type) {
                case 'teacher_geofence_violation':
                    $this->alertService->alertGeofenceViolation(
                        $userId,
                        $schoolId,
                        $context['lat'] ?? 0,
                        $context['lng'] ?? 0,
                        $context['distance'] ?? 0,
                        $context['max_radius'] ?? 0,
                        request()->ip(),
                    );
                    break;

                case 'teacher_not_schedule_owner':
                case 'schedule_ownership_mismatch':
                    $this->alertService->alertUnauthorizedSchedule(
                        $userId,
                        $schoolId,
                        $context['schedule_id'] ?? 0,
                        request()->ip(),
                    );
                    break;

                case 'nonce_replay':
                case 'teacher_scan_nonce_replay':
                    $this->alertService->alertQrReplay(
                        $context['student_id'] ?? $userId,
                        $schoolId,
                        'QR code nonce replay detected',
                        request()->ip(),
                    );
                    break;

                case 'student_class_mismatch':
                    $this->alertService->createAlert(
                        SecurityAlertService::TYPE_UNAUTHORIZED_SCHEDULE,
                        'medium',
                        "Student (ID: {$context['student_id']}) attempted to scan in wrong class",
                        $context,
                        $context['student_id'],
                        $schoolId,
                        request()->ip(),
                    );
                    break;

                case 'race_condition_blocked':
                    $this->alertService->alertRaceConditionBlocked(
                        $userId,
                        $schoolId,
                        $context['schedule_id'] ?? 0,
                        request()->ip(),
                    );
                    break;

                default:
                    // Generic security alert for unhandled types
                    $this->alertService->createAlert(
                        'security_anomaly',
                        'medium',
                        "Security anomaly detected: {$type}",
                        $context,
                        $userId,
                        $schoolId,
                        request()->ip(),
                    );
            }
        } catch (\Throwable $e) {
            // Don't let alert failures affect the main flow
            Log::error('Failed to dispatch security alert', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     *
     * @return float Distance in meters
     */
    private function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $earthRadius = 6371000; // Earth's radius in meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lng1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lng2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle =
            2 *
            asin(
                sqrt(
                    pow(sin($latDelta / 2), 2) +
                        cos($latFrom) *
                            cos($latTo) *
                            pow(sin($lonDelta / 2), 2),
                ),
            );

        return round($angle * $earthRadius);
    }
}
