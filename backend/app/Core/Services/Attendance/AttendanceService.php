<?php

namespace App\Core\Services\Attendance;

use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @deprecated This service is deprecated and will be removed in a future version.
 * Use App\Services\AttendanceCheckInService for student attendance.
 * Use App\Core\Services\Attendance\TeacherAttendanceService for teacher attendance.
 */
class AttendanceService
{
    protected $qrCodeService;

    protected $attendanceRepo;

    protected $replayPreventionService;

    protected $anomalyService;

    public function __construct(
        QRCodeService $qrCodeService,
        \App\Core\Domain\Repositories\AttendanceRepositoryInterface $attendanceRepo,
        QrReplayPreventionService $replayPreventionService,
        LocationAnomalyService $anomalyService
    ) {
        $this->qrCodeService = $qrCodeService;
        $this->attendanceRepo = $attendanceRepo;
        $this->replayPreventionService = $replayPreventionService;
        $this->anomalyService = $anomalyService;
    }

    public function processScan(User $student, array $data)
    {
        $schoolId = $student->school_id;

        // 1. Decrypt & Validate Token
        $payload = $this->qrCodeService->decryptAndValidate($data['qr_token']);
        $scheduleId = $payload['id'];
        $nonce = $payload['n'] ?? null; // QR nonce for replay prevention
        $requestId = $data['request_id'] ?? request()->header('X-Request-ID') ?? (string) Str::uuid();

        // 2. QR NONCE REPLAY CHECK (fail fast)
        if ($nonce) {
            $previousUsage = $this->replayPreventionService->checkNonceReplay($nonce, $schoolId);
            if ($previousUsage) {
                $this->replayPreventionService->logRepeatedAttempt(
                    $student->id, $scheduleId, $schoolId, $nonce, 'nonce_replay_detected'
                );
                throw new Exception('QR Code sudah digunakan. Setiap QR hanya dapat digunakan satu kali.');
            }
        }

        // 3. CACHE-BASED DUPLICATE CHECK (fail fast)
        if ($this->replayPreventionService->hasStudentScanned($student->id, $scheduleId, $schoolId)) {
            $this->replayPreventionService->logRepeatedAttempt(
                $student->id, $scheduleId, $schoolId, $nonce, 'duplicate_scan_attempt'
            );
            throw new Exception('Absen sudah tercatat. Anda telah melakukan absensi untuk jadwal ini.');
        }

        // 4. Validate Device ID (Anti-Joki)
        if ($student->device_id && $student->device_id !== $data['device_id']) {
            throw new Exception('Perangkat tidak dikenali. Harap gunakan HP Anda sendiri yang terdaftar.');
        }

        // 5. Geofence Validation
        $school = School::find($schoolId);
        if ($school && $school->latitude && $school->longitude) {
            $distance = $this->calculateDistance(
                $data['lat'], $data['lng'], $school->latitude, $school->longitude
            );
            $maxRadius = $school->radius_meters ?? 100;
            if ($distance > $maxRadius) {
                throw new Exception("Anda berada di luar radius sekolah ({$distance}m).");
            }
        }

        // 6. Time Window Validation (Moved up to avoid locking invalid attempts)
        $schedule = \App\Models\Schedule::find($scheduleId);
        if (! $schedule) {
            throw new Exception('Jadwal tidak ditemukan.');
        }

        $timeValidation = $this->validateAttendanceTime($schedule, $school ?? $student->school);
        if ($timeValidation['status'] === 'invalid') {
            throw new Exception($timeValidation['message']);
        }
        $attendanceStatus = $timeValidation['attendance_status'];

        // 7. ATOMIC PROCESSING (Cache Lock + DB Transaction)
        // Prevent parallel requests for same student
        return Cache::lock("attendance_scan_student_{$student->id}", 5)->block(3, function () use ($student, $scheduleId, $schoolId, $attendanceStatus, $data, $requestId, $nonce) {

            // Start DB Transaction
            return DB::transaction(function () use ($student, $scheduleId, $schoolId, $attendanceStatus, $data, $requestId, $nonce) {

                // 0. ATOMIC NONCE CHECK (Prevent Replay)
                if ($nonce) {
                    $nonceUsed = \App\Models\QrNonce::where('school_id', $schoolId)
                        ->where('nonce', $nonce)
                        ->lockForUpdate()
                        ->exists();

                    if ($nonceUsed) {
                        $this->replayPreventionService->logRepeatedAttempt(
                            $student->id, $scheduleId, $schoolId, $nonce, 'nonce_replay_db_detected'
                        );
                        throw new Exception('QR Code sudah digunakan (Replay Detected).');
                    }

                    // Mark as used immediately (locks this nonce for this transaction)
                    \App\Models\QrNonce::create([
                        'nonce' => $nonce,
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'schedule_id' => $scheduleId,
                    ]);
                }

                // A. Lock Student Row/Check Duplicate (FOR UPDATE)
                // This ensures serialization at DB level
                // A. Lock Student Row/Check Duplicate (FOR UPDATE)
                // This ensures serialization at DB level
                $existing = $this->findExistingAttendance($student->id, $scheduleId, now()->toDateString(), true);

                if ($existing) {
                    // Update cache to reflect DB state
                    $this->replayPreventionService->markStudentScanned($student->id, $scheduleId, $schoolId);

                    // Idempotency check: If same request ID, return original success
                    if ($requestId && $existing->request_id === $requestId) {
                        return $existing;
                    }
                    throw new Exception('Anda sudah melakukan absensi untuk jadwal ini.');
                }

                // B. Register Device ID if first time (Atomic update)
                if (empty($student->device_id)) {
                    $student->update(['device_id' => $data['device_id']]);
                }

                // C. Create Attendance Record
                $attendance = $this->attendanceRepo->create([
                    'school_id' => $schoolId,
                    'schedule_id' => $scheduleId,
                    'student_id' => $student->id,
                    'attendance_date' => now()->toDateString(),
                    'status' => $attendanceStatus,
                    'check_in_time' => now(),
                    'lat_in' => $data['lat'],
                    'lng_in' => $data['lng'],
                    'device_id_in' => $data['device_id'],
                    'is_manual' => false,
                    'request_id' => $requestId,
                ]);

                // D. Anomaly Check (Strict: Rollback on failure)
                try {
                    $this->anomalyService->checkAndFlag($attendance, $data);
                } catch (\Exception $e) {
                    // Log and rethrow to ensure atomic rollback
                    \Illuminate\Support\Facades\Log::error('Anomaly check failed, rolling back attendance: '.$e->getMessage());
                    throw $e;
                }

                // E. Mark Redis Keys (After DB insert)
                // Used insde transaction to ensure it happens if DB succeeds
                if ($nonce) {
                    $this->replayPreventionService->markNonceUsed(
                        $nonce, $schoolId, $student->id, $scheduleId
                    );
                }
                $this->replayPreventionService->markStudentScanned(
                    $student->id, $scheduleId, $schoolId, $attendance->id
                );

                return $attendance;
            });
        });
    }

    /**
     * Haversine Formula for distance in meters
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    /**
     * Process manual attendance (Teacher/Admin only)
     *
     * @param  int  $recordedBy  User ID who records the attendance
     */
    public function manualAttendance(array $data, int $recordedBy): Attendance
    {
        // Validate that student and schedule exist and belong to same school
        // This validation should be done in controller via ValidatesSchoolOwnership trait

        return DB::transaction(function () use ($data, $recordedBy) {
            // Check for duplicate attendance on the same date with row lock
            $attendance = $this->findExistingAttendance(
                $data['student_id'],
                $data['schedule_id'],
                $data['attendance_date'],
                true // Lock for update
            );

            if ($attendance) {
                // If exists, update instead of create
                $attendance->update([
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?? null,
                    'is_manual' => true,
                    'recorded_by' => $recordedBy,
                ]);

                return $attendance;
            }

            // Create new attendance record
            return $this->attendanceRepo->create([
                'school_id' => $data['school_id'],
                'schedule_id' => $data['schedule_id'],
                'student_id' => $data['student_id'],
                'attendance_date' => $data['attendance_date'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_manual' => true,
                'recorded_by' => $recordedBy,
            ]);
        });
    }

    /**
     * Validate attendance time against schedule
     */
    private function validateAttendanceTime($schedule, $school): array
    {
        $now = now();

        // Parse Schedule Times (Assuming format H:i:s)
        $startTime = \Carbon\Carbon::parse($now->format('Y-m-d').' '.$schedule->start_time);
        $endTime = \Carbon\Carbon::parse($now->format('Y-m-d').' '.$schedule->end_time);

        // Get Configs (Defaults: 15 mins early, 15 mins late tolerance)
        $settings = $school->settings ?? [];
        $earlyGrace = $settings['attendance_grace_early'] ?? 15;
        $lateTolerance = $settings['attendance_grace_late'] ?? 15;

        // Calculate Windows
        $earliestAllowed = $startTime->copy()->subMinutes($earlyGrace);
        $lateThreshold = $startTime->copy()->addMinutes($lateTolerance); // After this, it's 'late'

        // 1. Too Early?
        if ($now->lessThan($earliestAllowed)) {
            return [
                'status' => 'invalid',
                'message' => "Absensi belum dibuka. Silakan scan mulai pukul {$earliestAllowed->format('H:i')}.",
                'attendance_status' => null,
            ];
        }

        // 2. Class Ended?
        if ($now->greaterThan($endTime)) {
            return [
                'status' => 'invalid',
                'message' => 'Kelas telah berakhir. Anda tidak dapat melakukan absensi lagi.',
                'attendance_status' => null,
            ];
        }

        // 3. Determine Status
        $status = 'present';
        if ($now->greaterThan($lateThreshold)) {
            $status = 'late';
        }

        // 4. Edge Case Logging ("Scanning at edge of window")
        // If within 1 minute of closing/late threshold
        $diffToLate = $now->diffInSeconds($lateThreshold);
        if ($status === 'present' && $now->lessThan($lateThreshold) && $diffToLate < 60) {
            \Illuminate\Support\Facades\Log::channel('security')->info("Student scanned at edge of 'present' window", [
                'student_id' => request()->user()->id ?? 'unknown',
                'schedule_id' => $schedule->id,
                'time' => $now->toTimeString(),
                'late_threshold' => $lateThreshold->toTimeString(),
            ]);
        }

        return [
            'status' => 'valid',
            'message' => 'Success',
            'attendance_status' => $status,
        ];
    }

    /**
     * Find existing attendance to avoid duplicates
     */
    private function findExistingAttendance($studentId, $scheduleId, $date, $lock = false)
    {
        $query = Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', $date);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
