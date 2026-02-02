<?php

namespace App\Core\Services\Attendance;

use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use App\Services\HybridQrValidationService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enhanced Attendance Service dengan Hybrid QR Validation
 * 
 * Improvements:
 * - Menggunakan HybridQrValidationService untuk 2-layer validation
 * - HMAC validation (cepat) + Database verification (aman)
 * - Logging untuk security anomalies
 */
use App\Events\AttendanceRecorded;
use App\Events\AttendanceLate;
use App\Events\AttendanceAbsent;
use App\Models\Schedule;

class EnhancedAttendanceService
{
    public function __construct(
        private QRCodeService $qrCodeService,
        private HybridQrValidationService $hybridQrValidator,
        private \App\Core\Domain\Repositories\AttendanceRepositoryInterface $attendanceRepo
    ) {}

    /**
     * Process QR scan dengan hybrid validation
     * 
     * @param User $student Siswa yang melakukan scan
     * @param array $data Data scan (qr_token, lat, lng, device_id, dll)
     * @return Attendance
     * 
     * @throws Exception
     */
    public function processScan(User $student, array $data): Attendance
    {
        $requestId = $data['request_id'] ?? request()->header('X-Request-ID') ?? (string) Str::uuid();

        try {
            // ========================================
            // STEP 1: DECRYPT & VALIDATE QR TOKEN
            // ========================================
            // Ini untuk schedule QR (yang di-generate guru)
            $payload = $this->qrCodeService->decryptAndValidate($data['qr_token']);
            $scheduleId = $payload['id'];

            // Load Schedule to get subject_id
            $schedule = \App\Models\Schedule::find($scheduleId);
            if (!$schedule) {
                throw new Exception('Jadwal tidak ditemukan.');
            }

            // ========================================
            // STEP 2: HYBRID VALIDATION - STUDENT STATUS
            // ========================================
            // Validasi bahwa siswa masih aktif dan belong to correct school
            // Ini menggunakan student QR card validation logic
            
            // Cek apakah siswa aktif dan valid
            if (!$student->is_active) {
                Log::warning('Inactive student attempted to scan', [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'school_id' => $student->school_id,
                ]);

                throw new Exception('Akun Anda tidak aktif. Silakan hubungi admin sekolah.');
            }

            // ========================================
            // STEP 3: VALIDATE DEVICE ID (ANTI-JOKI)
            // ========================================
            $this->hybridQrValidator->validateDeviceId($student, $data['device_id'] ?? null);

            // ========================================
            // STEP 4: GEOFENCE VALIDATION
            // ========================================
            $school = School::find($student->school_id);
            if ($school && $school->latitude && $school->longitude) {
                $distance = $this->calculateDistance(
                    $data['lat'],
                    $data['lng'],
                    $school->latitude,
                    $school->longitude
                );

                $maxRadius = $school->radius_meters ?? 100;

                if ($distance > $maxRadius) {
                    Log::info('Student outside geofence', [
                        'student_id' => $student->id,
                        'distance' => $distance,
                        'max_radius' => $maxRadius,
                        'student_lat' => $data['lat'],
                        'student_lng' => $data['lng'],
                        'school_lat' => $school->latitude,
                        'school_lng' => $school->longitude,
                    ]);

                    throw new Exception("Anda berada di luar radius sekolah ({$distance}m dari {$maxRadius}m).");
                }
            }

            // ========================================
            // STEP 5: DUPLICATE CHECK
            // ========================================
            $exists = $this->attendanceRepo->hasAttended(
                $student->id,
                $scheduleId,
                now()->toDateString()
            );

            if ($exists) {
                throw new Exception('Anda sudah melakukan absensi untuk jadwal ini.');
            }

            // ========================================
            // STEP 6: IDEMPOTENCY CHECK
            // ========================================
            if ($requestId) {
                $existing = $this->attendanceRepo->findByRequestId($requestId);
                if ($existing) {
                    Log::info('Idempotent request detected, returning existing attendance', [
                        'request_id' => $requestId,
                        'attendance_id' => $existing->id,
                    ]);
                    return $existing;
                }
            }

            // ========================================
            // STEP 7: RECORD ATTENDANCE
            // ========================================
            $attendance = $this->attendanceRepo->create([
                'school_id' => $student->school_id,
                'schedule_id' => $scheduleId,
                'subject_id' => $schedule->subject_id,
                'student_id' => $student->id,
                'attendance_date' => now()->toDateString(),
                'status' => 'present',
                'check_in_time' => now(),
                'lat_in' => $data['lat'],
                'lng_in' => $data['lng'],
                'device_id_in' => $data['device_id'] ?? null,
                'is_manual' => false,
                'request_id' => $requestId,
            ]);

            Log::info('Attendance recorded successfully', [
                'attendance_id' => $attendance->id,
                'student_id' => $student->id,
                'schedule_id' => $scheduleId,
                'request_id' => $requestId,
            ]);

            return $attendance;

        } catch (Exception $e) {
            // Log error untuk debugging
            Log::error('Attendance scan failed', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
                'request_id' => $requestId,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Process scan dengan student QR card (bukan schedule QR)
     * 
     * Ini untuk kasus dimana siswa scan QR card mereka sendiri
     * ke QR reader yang ada di kelas
     * 
     * @param string $studentQrToken QR token dari kartu siswa
     * @param int $scheduleId Schedule ID yang sedang berlangsung
     * @param array $scanData Data tambahan (lat, lng, device_id)
     * @return Attendance
     */
    public function processScanWithStudentCard(
        string $studentQrToken,
        int $scheduleId,
        array $scanData
    ): Attendance {
        $requestId = $scanData['request_id'] ?? (string) Str::uuid();

        try {
            // ========================================
            // STEP 1: HYBRID VALIDATION - STUDENT QR
            // ========================================
            // Validasi QR card siswa dengan 2 layer: HMAC + DB
            $validatedData = $this->hybridQrValidator->validateStudentQr(
                $studentQrToken,
                $scanData['expected_school_id'] ?? null
            );

            $studentId = $validatedData['student_id'];
            $schoolId = $validatedData['school_id'];

            // ========================================
            // STEP 2: LOAD STUDENT FROM VALIDATED DATA
            // ========================================
            $student = User::find($studentId);

            if (!$student) {
                throw new Exception('Siswa tidak ditemukan.');
            }

            // ========================================
            // STEP 3: VALIDATE SCHEDULE
            // ========================================
            $schedule = \App\Models\Schedule::where('id', $scheduleId)
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->first();

            if (!$schedule) {
                throw new Exception('Jadwal tidak ditemukan atau tidak aktif.');
            }

            // ========================================
            // STEP 4: DUPLICATE CHECK
            // ========================================
            $exists = $this->attendanceRepo->hasAttended(
                $studentId,
                $scheduleId,
                now()->toDateString()
            );

            if ($exists) {
                throw new Exception('Siswa sudah melakukan absensi untuk jadwal ini.');
            }

            // ========================================
            // STEP 5: IDEMPOTENCY CHECK
            // ========================================
            if ($requestId) {
                $existing = $this->attendanceRepo->findByRequestId($requestId);
                if ($existing) {
                    return $existing;
                }
            }

            // ========================================
            // STEP 6: RECORD ATTENDANCE
            // ========================================

            // Calculate Status based on Grace Period
            $settings = SchoolSettingsHelper::getSettingsWithCache($schoolId);
            $gracePeriod = $settings['grace_period_minutes'] ?? 15;
            
            $scheduleStart = Carbon::parse($schedule->start_time)->setDateFrom(now());
            $lateThreshold = $scheduleStart->copy()->addMinutes($gracePeriod);
            $checkInTime = now();
            
            $status = 'present';
            if ($checkInTime->gt($lateThreshold)) {
                $status = 'late';
            }

            $attendance = $this->attendanceRepo->create([
                'school_id' => $schoolId,
                'schedule_id' => $scheduleId,
                'student_id' => $studentId,
                'attendance_date' => now()->toDateString(),
                'status' => $status,
                'check_in_time' => $checkInTime,
                'lat_in' => $scanData['lat'] ?? null,
                'lng_in' => $scanData['lng'] ?? null,
                'device_id_in' => $scanData['device_id'] ?? null,
                'is_manual' => false,
                'request_id' => $requestId,
            ]);

            // Dispatch Notification Events
            if ($status === 'present') {
                AttendanceRecorded::dispatch($attendance);
            } elseif ($status === 'late') {
                AttendanceLate::dispatch($attendance);
            }

            Log::info('Attendance recorded via student card', [
                'attendance_id' => $attendance->id,
                'student_id' => $studentId,
                'student_name' => $validatedData['student_name'],
                'schedule_id' => $scheduleId,
                'validation_method' => $validatedData['validation_method'],
            ]);

            return $attendance;

        } catch (Exception $e) {
            Log::error('Student card scan failed', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);

            throw $e;
        }
    }

    /**
     * Haversine Formula untuk menghitung jarak dalam meter
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
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

        return round($angle * $earthRadius, 2);
    }

    /**
     * Process manual attendance (Teacher/Admin only)
     */
    public function manualAttendance(array $data, int $recordedBy): Attendance
    {
        // Validate that student is active
        $student = User::where('id', $data['student_id'])
            ->where('role_type', 'student')
            ->first();

        if (!$student) {
            throw new Exception('Siswa tidak ditemukan.');
        }

        if (!$student->is_active) {
            throw new Exception('Siswa tidak aktif. Tidak dapat mencatat absensi.');
        }

        // Check for duplicate attendance on the same date
        $exists = $this->attendanceRepo->hasAttended(
            $data['student_id'],
            $data['schedule_id'],
            $data['attendance_date']
        );

        if ($exists) {
            // If exists, update instead of create
            $attendance = Attendance::where([
                'student_id' => $data['student_id'],
                'schedule_id' => $data['schedule_id'],
                'attendance_date' => $data['attendance_date'],
            ])->first();

            $attendance->update([
                'status' => $data['status'],
            // Dispatch events
            if ($attendance->status === 'absent') {
                AttendanceAbsent::dispatch($attendance);
            } elseif ($attendance->status === 'late') {
                AttendanceLate::dispatch($attendance);
            } elseif ($attendance->status === 'present') {
                AttendanceRecorded::dispatch($attendance);
            }

                'notes' => $data['notes'] ?? null,
                'is_manual' => true,
                'recorded_by' => $recordedBy,
           Fetch Schedule to get Subject ID
        $schedule = Schedule::find($data['schedule_id']);
        if (!$schedule) {
            throw new Exception('Jadwal tidak ditemukan.');
        }

        //  ]);
$atedance= 
            return $attendance;
        }

        // Create new attendance record
        return $this->attendanceRepo->create([
            'school_id' => $data['school_id'],
            'schedule_id' => $data['schedule_id'],
            'subject_id' => $schedule->subject_id,
            'student_id' => $data['student_id'],
         );

        // Dispatch events
        if ($attendance->status === 'absent') {
            AttendanceAbsent::dispatch($attendance);
        } elseif ($attendance->status === 'late') {
            AttendanceLate::dispatch($attendance ;
        } elseif ($attendance->status === 'present') {
            AttendanceRecorded::dispatch($attendance);
        }

        return $attendance  'attendance_date' => $data['attendance_date'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'is_manual' => true,
            'recorded_by' => $recordedBy,
        ]);
    }
}
