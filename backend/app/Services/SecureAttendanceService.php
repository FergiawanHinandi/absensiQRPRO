<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Helpers\TimezoneHelper;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Secure Attendance Service
 * 
 * SECURITY FEATURES:
 * 1. ✅ HMAC Signature Validation (Prevents forgery)
 * 2. ✅ Timestamp Validation (60 second window)
 * 3. ✅ Replay Attack Prevention (Idempotency key)
 * 4. ✅ Redis Lock (5 seconds per student)
 * 5. ✅ Rate Limiting (30/minute via middleware)
 * 6. ✅ School Isolation (Multi-tenant security)
 * 7. ✅ Audit Logging (Invalid signatures)
 * 
 * @version 2.0.0 - Security Hardened
 */
class SecureAttendanceService
{
    /**
     * Process QR code scan with comprehensive security
     * 
     * SECURITY FLOW:
     * 1. Validate HMAC signature
     * 2. Validate timestamp (60s window)
     * 3. Validate school match
     * 4. Check idempotency key (replay prevention)
     * 5. Acquire Redis lock (5s)
     * 6. DB transaction with firstOrCreate
     * 7. Log all security events
     * 
     * @param User $student
     * @param array $qrPayload Decoded QR payload
     * @param array $scanData GPS, device info
     * @return Attendance
     * @throws AttendanceException
     */
    public function scan(User $student, array $qrPayload, array $scanData): Attendance
    {
        // 1. VALIDATE SIGNATURE
        $this->validateSignature($qrPayload);

        // 2. VALIDATE TIMESTAMP (60 second window)
        $this->validateTimestamp($qrPayload);

        // 3. VALIDATE SCHOOL MATCH
        $this->validateSchoolMatch($student, $qrPayload);

        // 4. CHECK IDEMPOTENCY KEY (Replay Prevention)
        $this->checkIdempotencyKey($qrPayload);

        // 5. ACQUIRE REDIS LOCK (5 seconds per student)
        $lockKey = "attendance_scan:{$student->id}:{$qrPayload['schedule_id']}";
        
        return Cache::lock($lockKey, 5)->block(3, function () use ($student, $qrPayload, $scanData) {
            // 6. DB TRANSACTION with firstOrCreate
            return DB::transaction(function () use ($student, $qrPayload, $scanData) {
                return $this->processAttendance($student, $qrPayload, $scanData);
            });
        });
    }

    /**
     * Validate HMAC signature
     * 
     * SECURITY: Prevents QR forgery
     * 
     * @param array $payload
     * @throws AttendanceException
     */
    protected function validateSignature(array $payload): void
    {
        if (!isset($payload['signature']) || !isset($payload['data'])) {
            $this->logSecurityEvent('missing_signature', $payload);
            throw new AttendanceException('QR Code tidak valid: signature tidak ditemukan.');
        }

        // Recreate signature from data
        $expectedSignature = hash_hmac(
            'sha256',
            json_encode($payload['data']),
            config('app.key')
        );

        if (!hash_equals($expectedSignature, $payload['signature'])) {
            $this->logSecurityEvent('invalid_signature', $payload);
            throw new AttendanceException('QR Code tidak valid: signature tidak cocok.');
        }
    }

    /**
     * Validate timestamp (60 second window)
     * 
     * SECURITY: Prevents replay attacks with old QR codes
     * 
     * @param array $payload
     * @throws AttendanceException
     */
    protected function validateTimestamp(array $payload): void
    {
        if (!isset($payload['data']['expires_at'])) {
            $this->logSecurityEvent('missing_timestamp', $payload);
            throw new AttendanceException('QR Code tidak valid: timestamp tidak ditemukan.');
        }

        $expiresAt = TimezoneHelper::parse($payload['data']['expires_at']);
        $now = TimezoneHelper::now();

        // Check if expired (60 second window)
        if ($now->greaterThan($expiresAt)) {
            $this->logSecurityEvent('expired_qr', [
                'expires_at' => $expiresAt->toIso8601String(),
                'now' => $now->toIso8601String(),
                'diff_seconds' => $now->diffInSeconds($expiresAt),
            ]);
            throw new AttendanceException('QR Code sudah kadaluarsa. Silakan refresh QR code.');
        }

        // Check if too far in future (clock manipulation)
        if ($expiresAt->greaterThan($now->addMinutes(2))) {
            $this->logSecurityEvent('future_timestamp', [
                'expires_at' => $expiresAt->toIso8601String(),
                'now' => TimezoneHelper::now()->toIso8601String(),
            ]);
            throw new AttendanceException('QR Code tidak valid: timestamp tidak wajar.');
        }
    }

    /**
     * Validate school match
     * 
     * SECURITY: Multi-tenant isolation
     * 
     * @param User $student
     * @param array $payload
     * @throws AttendanceException
     */
    protected function validateSchoolMatch(User $student, array $payload): void
    {
        if (!isset($payload['data']['school_id'])) {
            $this->logSecurityEvent('missing_school_id', $payload);
            throw new AttendanceException('QR Code tidak valid: school_id tidak ditemukan.');
        }

        if ($student->school_id !== $payload['data']['school_id']) {
            $this->logSecurityEvent('school_mismatch', [
                'student_school_id' => $student->school_id,
                'qr_school_id' => $payload['data']['school_id'],
                'student_id' => $student->id,
            ]);
            throw new AttendanceException('QR Code tidak valid: sekolah tidak cocok.');
        }
    }

    /**
     * Check idempotency key (replay prevention)
     * 
     * SECURITY: Prevents replay attacks
     * 
     * @param array $payload
     * @throws AttendanceException
     */
    protected function checkIdempotencyKey(array $payload): void
    {
        if (!isset($payload['data']['idempotency_key'])) {
            $this->logSecurityEvent('missing_idempotency_key', $payload);
            throw new AttendanceException('QR Code tidak valid: idempotency_key tidak ditemukan.');
        }

        $idempotencyKey = $payload['data']['idempotency_key'];
        $cacheKey = "idempotency:{$idempotencyKey}";

        // Check if already used (TTL: 5 minutes)
        if (Cache::has($cacheKey)) {
            $this->logSecurityEvent('replay_attack_detected', [
                'idempotency_key' => $idempotencyKey,
                'schedule_id' => $payload['data']['schedule_id'] ?? null,
            ]);
            throw new AttendanceException('QR Code sudah digunakan. Replay attack terdeteksi.');
        }

        // Mark as used (TTL: 5 minutes)
        Cache::put($cacheKey, true, 300);
    }

    /**
     * Process attendance with DB transaction
     * 
     * Uses firstOrCreate to handle race conditions
     * 
     * @param User $student
     * @param array $qrPayload
     * @param array $scanData
     * @return Attendance
     * @throws AttendanceException
     */
    protected function processAttendance(User $student, array $qrPayload, array $scanData): Attendance
    {
        $scheduleId = $qrPayload['data']['schedule_id'];
        $schoolId = $student->school_id;
        
        // Get school for timezone-aware date
        $school = $student->school;
        $attendanceDate = TimezoneHelper::today($school);

        // Validate schedule exists and belongs to school
        $schedule = Schedule::where('id', $scheduleId)
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->firstOrFail();

        // Determine attendance status (present/late)
        $status = $this->determineAttendanceStatus($schedule);

        // Validate GPS location (if enabled)
        if (isset($scanData['latitude']) && isset($scanData['longitude'])) {
            $this->validateGPSLocation($student, $scanData);
        }

        // firstOrCreate: Atomic operation to prevent duplicates
        $attendance = Attendance::firstOrCreate(
            [
                'student_id' => $student->id,
                'schedule_id' => $scheduleId,
                'attendance_date' => $attendanceDate,
                'school_id' => $schoolId,
            ],
            [
                'status' => $status,
                'check_in_time' => TimezoneHelper::schoolNow($school)->format('H:i:s'),
                'is_manual' => false,
                'attendance_type' => 'qr_scan',
                'device_id' => $scanData['device_id'] ?? null,
                'latitude' => $scanData['latitude'] ?? null,
                'longitude' => $scanData['longitude'] ?? null,
                'idempotency_key' => $qrPayload['data']['idempotency_key'],
            ]
        );

        // If already exists (wasRecentlyCreated = false), throw error
        if (!$attendance->wasRecentlyCreated) {
            throw new AttendanceException('Anda sudah melakukan absensi untuk jadwal ini.');
        }

        // Log successful scan
        Log::channel('audit')->info('attendance_scan_success', [
            'attendance_id' => $attendance->id,
            'student_id' => $student->id,
            'schedule_id' => $scheduleId,
            'school_id' => $schoolId,
            'status' => $status,
            'timestamp' => TimezoneHelper::now()->toIso8601String(),
        ]);

        return $attendance->load(['schedule.subject', 'schedule.class']);
    }

    /**
     * Determine attendance status (present/late)
     * 
     * @param Schedule $schedule
     * @return string
     */
    protected function determineAttendanceStatus(Schedule $schedule): string
    {
        $school = $schedule->school;
        $now = TimezoneHelper::schoolNow($school);
        $startTime = TimezoneHelper::timeFromString($schedule->start_time, $school);
        
        // Get late tolerance from school settings (default: 15 minutes)
        $lateTolerance = $school->settings['late_tolerance_minutes'] ?? 15;
        $lateThreshold = $startTime->copy()->addMinutes($lateTolerance);

        return $now->greaterThan($lateThreshold) ? 'late' : 'present';
    }

    /**
     * Validate GPS location
     * 
     * SECURITY: Prevents attendance from outside school
     * 
     * @param User $student
     * @param array $scanData
     * @throws AttendanceException
     */
    protected function validateGPSLocation(User $student, array $scanData): void
    {
        $school = $student->school;

        // Skip if school doesn't have GPS coordinates
        if (!$school->latitude || !$school->longitude) {
            return;
        }

        $distance = $this->calculateDistance(
            $scanData['latitude'],
            $scanData['longitude'],
            $school->latitude,
            $school->longitude
        );

        $maxRadius = $school->radius_meters ?? 100;

        if ($distance > $maxRadius) {
            $this->logSecurityEvent('gps_out_of_range', [
                'student_id' => $student->id,
                'school_id' => $student->school_id,
                'distance' => $distance,
                'max_radius' => $maxRadius,
                'student_lat' => $scanData['latitude'],
                'student_lng' => $scanData['longitude'],
                'school_lat' => $school->latitude,
                'school_lng' => $school->longitude,
            ]);
            throw new AttendanceException("Anda berada di luar radius sekolah ({$distance}m dari {$maxRadius}m).");
        }
    }

    /**
     * Calculate distance between two GPS coordinates (Haversine formula)
     * 
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in meters
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters

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

        return round($angle * $earthRadius, 2);
    }

    /**
     * Log security event
     * 
     * @param string $event
     * @param array $context
     */
    protected function logSecurityEvent(string $event, array $context): void
    {
        Log::channel('security')->warning("qr_security_event: {$event}", array_merge($context, [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => TimezoneHelper::now()->toIso8601String(),
        ]));

        // Also log to activity_logs table
        activity()
            ->causedBy(auth()->user())
            ->withProperties($context)
            ->log("qr_security_event: {$event}");
    }
}
