<?php

namespace App\Core\Services\Attendance;

use App\Core\Services\GeofenceService;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherAttendanceAnomaly;
use App\Models\TeacherDevice;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TeacherAttendanceService
{
    protected QRCodeService $qrCodeService;
    protected GeofenceService $geofenceService;

    /**
     * Teacher Attendance Rules:
     * 1. Teacher scans THEIR OWN QR
     * 2. Only from APPROVED device
     * 3. Must be within 50m school radius
     * 4. Must be during working hours
     */

    public function __construct(QRCodeService $qrCodeService, GeofenceService $geofenceService)
    {
        $this->qrCodeService = $qrCodeService;
        $this->geofenceService = $geofenceService;
    }

    /**
     * Process teacher check-in via QR scan
     *
     * @param User $teacher The authenticated teacher
     * @param array $data Scan data including qr_token, lat, lng, accuracy, device_id, is_mock_location
     * @return TeacherAttendance
     * @throws Exception
     */
    public function processCheckIn(User $teacher, array $data): TeacherAttendance
    {
        $schoolId = $teacher->school_id;
        $requestId = $data['request_id'] ?? request()->header('X-Request-ID') ?? (string) Str::uuid();

        // 1. VERIFY QR OWNERSHIP - QR teacher_id must equal auth()->id()
        $payload = $this->qrCodeService->decryptAndValidate($data['qr_token']);
        $this->verifyQrOwnership($payload, $teacher);

        // 2. MOCK LOCATION DETECTION - Reject if is_mock_location = true
        $this->validateMockLocation($data);

        // 3. GPS ACCURACY VALIDATION - Reject if accuracy > 100m
        $this->validateGpsAccuracy($data);

        // 4. DEVICE BINDING CHECK - Must be approved device
        $device = $this->validateDeviceBinding($teacher, $data);

        // 5. GEOFENCE VALIDATION (CRITICAL) - Must be within 50m
        $school = School::find($schoolId);
        $distance = $this->validateGeofence($school, $data, $teacher);

        // 6. TIME WINDOW VALIDATION - Working hours ± 30 min
        $attendanceStatus = $this->validateTimeWindow($school, $teacher);

        // 7. ATOMIC PROCESSING - Prevent duplicates with lockForUpdate()
        return Cache::lock("teacher_attendance_{$teacher->id}", 5)->block(3, function () use (
            $teacher, $schoolId, $attendanceStatus, $data, $requestId, $distance, $device
        ) {
            return DB::transaction(function () use (
                $teacher, $schoolId, $attendanceStatus, $data, $requestId, $distance, $device
            ) {
                // 7a. PREVENT DUPLICATE CHECK-IN - Lock row
                $existing = TeacherAttendance::getTodayForTeacher($teacher->id, lock: true);

                if ($existing && $existing->check_in_time) {
                    // Idempotency check
                    if ($requestId && $existing->request_id === $requestId) {
                        return $existing;
                    }
                    throw new Exception('Anda sudah melakukan check-in hari ini.');
                }

                // 7b. Create or update attendance record
                $attendance = $existing ?? new TeacherAttendance();
                $attendance->fill([
                    'school_id' => $schoolId,
                    'teacher_id' => $teacher->id,
                    'attendance_date' => now()->toDateString(),
                    'status' => $attendanceStatus,
                    'check_in_time' => now(),
                    'lat_in' => $data['lat'],
                    'lng_in' => $data['lng'],
                    'accuracy_in' => $data['accuracy'] ?? null,
                    'device_id_in' => $data['device_id'],
                    'distance_in' => $distance,
                    'is_manual' => false,
                    'request_id' => $requestId,
                ]);
                $attendance->save();

                // 8. SAVE DEVICE USAGE - Update last_used_at
                $device->markAsUsed();

                return $attendance;
            });
        });
    }

    /**
     * Process teacher check-out via QR scan
     */
    public function processCheckOut(User $teacher, array $data): TeacherAttendance
    {
        $schoolId = $teacher->school_id;
        $requestId = $data['request_id'] ?? request()->header('X-Request-ID') ?? (string) Str::uuid();

        // 1. VERIFY QR OWNERSHIP
        $payload = $this->qrCodeService->decryptAndValidate($data['qr_token']);
        $this->verifyQrOwnership($payload, $teacher);

        // 2. MOCK LOCATION DETECTION
        $this->validateMockLocation($data);

        // 3. GPS ACCURACY VALIDATION
        $this->validateGpsAccuracy($data);

        // 4. DEVICE BINDING CHECK
        $device = $this->validateDeviceBinding($teacher, $data);

        // 5. GEOFENCE VALIDATION
        $school = School::find($schoolId);
        $distance = $this->validateGeofence($school, $data, $teacher);

        // 6. ATOMIC PROCESSING
        return Cache::lock("teacher_attendance_{$teacher->id}", 5)->block(3, function () use (
            $teacher, $data, $requestId, $distance, $device
        ) {
            return DB::transaction(function () use (
                $teacher, $data, $requestId, $distance, $device
            ) {
                // Must have checked in first
                $attendance = TeacherAttendance::getTodayForTeacher($teacher->id, lock: true);

                if (!$attendance || !$attendance->check_in_time) {
                    throw new Exception('Anda belum melakukan check-in hari ini.');
                }

                if ($attendance->check_out_time) {
                    // Idempotency check
                    if ($requestId && $attendance->request_id === $requestId) {
                        return $attendance;
                    }
                    throw new Exception('Anda sudah melakukan check-out hari ini.');
                }

                // Update with check-out data
                $attendance->update([
                    'check_out_time' => now(),
                    'lat_out' => $data['lat'],
                    'lng_out' => $data['lng'],
                    'accuracy_out' => $data['accuracy'] ?? null,
                    'device_id_out' => $data['device_id'],
                    'distance_out' => $distance,
                ]);

                // Update device usage
                $device->markAsUsed();

                return $attendance;
            });
        });
    }

    /**
     * 1. VERIFY QR OWNERSHIP - QR must belong to the scanning teacher
     */
    private function verifyQrOwnership(array $payload, User $teacher): void
    {
        $qrTeacherId = $payload['teacher_id'] ?? $payload['id'] ?? null;

        // For teacher QR, the payload should contain teacher_id matching auth user
        if ($qrTeacherId !== $teacher->id) {
            Log::channel('security')->warning('QR Ownership Violation', [
                'teacher_id' => $teacher->id,
                'qr_owner_id' => $qrTeacherId,
            ]);
            throw new Exception('QR Code ini bukan milik Anda. Gunakan QR pribadi Anda.');
        }
    }

    /**
     * 2. DEVICE BINDING CHECK - Device must be in teacher_devices and approved
     */
    private function validateDeviceBinding(User $teacher, array $data): TeacherDevice
    {
        $deviceId = $data['device_id'] ?? null;

        if (!$deviceId) {
            throw new Exception('ID perangkat tidak ditemukan dalam permintaan.');
        }

        $device = TeacherDevice::getApprovedDevice($teacher->id, $deviceId);

        if (!$device) {
            // 9. LOG ANOMALY - New device attempt
            $this->logAnomaly($teacher, null, 'new_device_attempt', 'high', [
                'message' => 'Percobaan akses dari perangkat tidak terdaftar/belum disetujui',
                'device_id' => $deviceId,
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'] ?? null,
            ], $data);

            // Check if device exists but not approved
            $pendingDevice = TeacherDevice::where('teacher_id', $teacher->id)
                ->where('device_id', $deviceId)
                ->where('is_approved', false)
                ->exists();

            if ($pendingDevice) {
                throw new Exception('Perangkat Anda menunggu persetujuan admin. Hubungi admin sekolah.');
            }

            // Register new device for approval
            TeacherDevice::create([
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'device_id' => $deviceId,
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'] ?? null,
                'device_model' => $data['device_model'] ?? null,
                'is_approved' => false,
            ]);

            throw new Exception('Perangkat baru terdeteksi dan perlu disetujui. Hubungi admin sekolah Anda.');
        }

        return $device;
    }

    /**
     * 3. GEOFENCE VALIDATION (CRITICAL) - Must be within 50m of school
     */
    private function validateGeofence(School $school, array $data, User $teacher): float
    {
        $maxRadius = 50; // CRITICAL: 50 meters

        if (!$school || !$school->latitude || !$school->longitude) {
            throw new Exception('Koordinat sekolah belum dikonfigurasi. Hubungi admin.');
        }

        if (!isset($data['lat']) || !isset($data['lng'])) {
            throw new Exception('Lokasi GPS tidak tersedia. Pastikan GPS aktif.');
        }

        // Use GeofenceService for distance calculation
        $geofenceResult = $this->geofenceService->check(
            $school->latitude,
            $school->longitude,
            $data['lat'],
            $data['lng'],
            $maxRadius
        );

        if (!$geofenceResult['is_within_radius']) {
            // 9. LOG ANOMALY - Outside radius attempt
            $this->logAnomaly($teacher, null, 'outside_radius', 'high', [
                'message' => "Percobaan absen dari luar area sekolah ({$geofenceResult['distance_meters']}m)",
                'distance' => $geofenceResult['distance_meters'],
                'max_allowed' => $maxRadius,
                'excess_meters' => $geofenceResult['excess_meters'],
                'teacher_lat' => $data['lat'],
                'teacher_lng' => $data['lng'],
                'school_lat' => $school->latitude,
                'school_lng' => $school->longitude,
            ], $data);

            throw new Exception("Anda di luar area sekolah. Jarak Anda: {$geofenceResult['distance_meters']}m (maksimal: {$maxRadius}m).");
        }

        return $geofenceResult['distance_meters'];
    }

    /**
     * 4. GPS ACCURACY VALIDATION - Reject if accuracy > 100m
     */
    private function validateGpsAccuracy(array $data): void
    {
        $accuracy = $data['accuracy'] ?? null;
        $maxAccuracy = 100; // meters

        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            throw new Exception("Akurasi GPS terlalu rendah ({$accuracy}m). Pastikan Anda berada di tempat terbuka dan GPS aktif.");
        }
    }

    /**
     * 5. MOCK LOCATION DETECTION - Reject fake GPS
     */
    private function validateMockLocation(array $data): void
    {
        $isMockLocation = $data['is_mock_location'] ?? false;

        if ($isMockLocation === true || $isMockLocation === 'true' || $isMockLocation === 1) {
            // Log this critical security event
            Log::channel('security')->critical('Mock Location Detected for Teacher Attendance', [
                'teacher_id' => auth()->id(),
                'device_id' => $data['device_id'] ?? 'unknown',
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ]);

            throw new Exception('Lokasi palsu terdeteksi. Absensi ditolak.');
        }
    }

    /**
     * 6. TIME WINDOW VALIDATION - Teacher can check-in between work_start - 30min and work_end + 30min
     */
    private function validateTimeWindow(School $school, User $teacher): string
    {
        $now = now();
        $gracePeriod = 30; // minutes

        // Get school working hours from settings or use defaults
        $settings = $school->settings ?? [];
        $workStartTime = $settings['work_start_time'] ?? '07:00';
        $workEndTime = $settings['work_end_time'] ?? '16:00';

        // Parse times for today
        $workStart = now()->setTimeFromTimeString($workStartTime . ':00');
        $workEnd = now()->setTimeFromTimeString($workEndTime . ':00');

        // Calculate allowed windows
        $earliestCheckIn = $workStart->copy()->subMinutes($gracePeriod);
        $latestCheckIn = $workEnd->copy()->addMinutes($gracePeriod);

        // Check if current time is within window
        if ($now->lt($earliestCheckIn)) {
            throw new Exception("Belum waktunya absen. Waktu absen dimulai pukul " . $earliestCheckIn->format('H:i') . ".");
        }

        if ($now->gt($latestCheckIn)) {
            throw new Exception("Waktu absen sudah berakhir. Batas waktu absen adalah pukul " . $latestCheckIn->format('H:i') . ".");
        }

        // Determine status (present vs late)
        $lateThreshold = $workStart->copy()->addMinutes(15); // 15 min grace for "on time"
        
        if ($now->gt($lateThreshold)) {
            return 'late';
        }

        return 'present';
    }

    /**
     * 9. LOG ANOMALIES - Record security events
     */
    private function logAnomaly(
        User $teacher,
        ?TeacherAttendance $attendance,
        string $type,
        string $severity,
        array $details,
        array $scanData = []
    ): void {
        try {
            TeacherAttendanceAnomaly::create([
                'school_id' => $teacher->school_id,
                'teacher_id' => $teacher->id,
                'teacher_attendance_id' => $attendance?->id,
                'anomaly_type' => $type,
                'severity' => $severity,
                'details' => $details,
                'latitude' => $scanData['lat'] ?? null,
                'longitude' => $scanData['lng'] ?? null,
                'device_id' => $scanData['device_id'] ?? null,
            ]);

            // Also log to security channel
            Log::channel('security')->warning("Teacher Attendance Anomaly: {$type}", [
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'severity' => $severity,
                'details' => $details,
            ]);
        } catch (\Exception $e) {
            // Don't let logging failure affect the main flow
            Log::error('Failed to log teacher attendance anomaly', [
                'error' => $e->getMessage(),
                'type' => $type,
            ]);
        }
    }

    // NOTE: calculateDistance moved to GeofenceService for reusability

    /**
     * Manual attendance entry by Admin
     */
    public function manualAttendance(array $data, int $recordedBy): TeacherAttendance
    {
        return DB::transaction(function () use ($data, $recordedBy) {
            $existing = TeacherAttendance::where('teacher_id', $data['teacher_id'])
                ->whereDate('attendance_date', $data['attendance_date'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?? null,
                    'is_manual' => true,
                    'recorded_by' => $recordedBy,
                ]);
                return $existing;
            }

            return TeacherAttendance::create([
                'school_id' => $data['school_id'],
                'teacher_id' => $data['teacher_id'],
                'attendance_date' => $data['attendance_date'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_manual' => true,
                'recorded_by' => $recordedBy,
            ]);
        });
    }

    /**
     * Get teacher's attendance summary for a date range
     */
    public function getSummary(int $teacherId, string $startDate, string $endDate): array
    {
        $attendances = TeacherAttendance::where('teacher_id', $teacherId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->get();

        return [
            'total_days' => $attendances->count(),
            'present' => $attendances->where('status', 'present')->count(),
            'late' => $attendances->where('status', 'late')->count(),
            'absent' => $attendances->where('status', 'absent')->count(),
            'sick' => $attendances->where('status', 'sick')->count(),
            'permit' => $attendances->where('status', 'permit')->count(),
        ];
    }
}
