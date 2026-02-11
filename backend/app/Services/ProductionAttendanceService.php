<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Helpers\Timezone;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Production-Hardened Attendance Service (Timezone Safe Edition)
 * 
 * 100% Timezone Consistent Logic for Distributed Schools.
 * 
 * Features:
 * - Timezone-aware Date Handling (No Server UTC Leaks)
 * - Redis idempotency locks (10s TTL)
 * - Atomic database transactions
 * - Deadlock retry mechanism (3x)
 * - Duplicate detection & recovery
 * - Fail-secure design (Redis down -> 503)
 * 
 * @author Senior Laravel Timezone Architect
 * @version 4.0.0 (Timezone Hardened)
 */
class ProductionAttendanceService
{
    private const SCAN_LOCK_TTL = 10;
    private const QR_LOCK_TTL = 60;
    private const MAX_DEADLOCK_RETRIES = 3;

    /**
     * Process QR code scan for attendance
     * 
     * TIMEZONE CONSISTENCY:
     * - School Timezone is the ONLY source of truth.
     * - Redis Lock Key uses School Date.
     * - Attendance Date uses School Date.
     * - Check-in Time uses School Time.
     * 
     * @param User $student
     * @param array $scanData ['qr_token', 'latitude', 'longitude', 'device_id']
     * @return array
     * @throws AttendanceException
     */
    public function scan(User $student, array $scanData): array
    {
        // 1. Validate Student & School Context
        if (!$student->school_id) {
            throw new AttendanceException('Siswa tidak terdaftar di sekolah manapun.');
        }

        // Fetch dedicated School model for timezone config
        // Don't rely on relationship cache as timezone configuration is critical
        $school = School::find($student->school_id);
        if (!$school) {
            throw new AttendanceException('Sekolah tidak ditemukan.');
        }

        // 2. Determine "NOW" in School's Timezone
        // This is the single source of truth for the entire transaction
        $now = Timezone::now($school);
        $attendanceDate = $now->toDateString();
        
        // 3. Decode Token
        $qrPayload = $this->validateAndDecodeQrToken($scanData['qr_token'], $school);
        $scheduleId = $qrPayload['schedule_id'];
        $qrSchoolId = $qrPayload['school_id'];

        if ($student->school_id !== $qrSchoolId) {
            Log::channel('security')->warning('attendance_school_mismatch', [
                'student_id' => $student->id,
                'target_school_id' => $qrSchoolId,
                'actual_school_id' => $student->school_id
            ]);
            throw new AttendanceException('QR code tidak valid untuk sekolah Anda.');
        }

        // 4. Redis Idempotency Lock (Timezone Aware Key)
        // Key: attendance_lock:{school_id}:{schedule_id}:{student_id}:{YYYY-MM-DD}
        // Using School Date ensures lock handles midnight correctly
        $lockKey = "attendance_lock:{$qrSchoolId}:{$scheduleId}:{$student->id}:{$attendanceDate}";
        
        try {
            $acquired = Redis::set($lockKey, 1, 'EX', self::SCAN_LOCK_TTL, 'NX');
        } catch (\Exception $e) {
            Log::critical('attendance_redis_down', ['error' => $e->getMessage()]);
            // Fail Secure: Do not proceed without lock
            throw new AttendanceException('Layanan absensi sedang gangguan (R). Silakan coba lagi.', 503);
        }

        if (!$acquired) {
            Log::info('attendance_lock_failed', [
                'student_id' => $student->id,
                'schedule_id' => $scheduleId,
                'reason' => 'Duplicate request within 10s'
            ]);
            
            // Check existing (Timezone Aware Query)
            $existing = $this->findExistingAttendance($student->id, $scheduleId, $attendanceDate);
            
            if ($existing) {
                return [
                    'success' => true,
                    'status' => 'duplicate',
                    'message' => 'Kehadiran sudah tercatat sebelumnya.',
                    'attendance' => $existing,
                ];
            }
            throw new AttendanceException('Sedang memproses kehadiran. Mohon tunggu.', 409);
        }

        try {
            // 5. DB Transaction (Timezone Aware Context)
            return DB::transaction(function () use ($student, $scheduleId, $qrSchoolId, $scanData, $now, $attendanceDate, $school) {
                
                // 5.1 Fetch Schedule with Lock
                $schedule = Schedule::where('id', $scheduleId)
                    ->where('school_id', $qrSchoolId)
                    ->lockForUpdate()
                    ->first();

                if (!$schedule || !$schedule->is_active) {
                    throw new AttendanceException('Jadwal tidak ditemukan atau tidak aktif.');
                }

                // 5.2 Validate GPS (Optional)
                if (isset($scanData['latitude'], $scanData['longitude'])) {
                    $this->validateLocation($qrSchoolId, $scanData['latitude'], $scanData['longitude']);
                }

                // 5.3 Determine Status based on School Time
                $status = $this->calculateStatus($schedule, $now);

                // 5.4 Insert Attendance (using firstOrCreate for duplicate prevention)
                try {
                    $attendance = Attendance::firstOrCreate(
                        [
                            'student_id' => $student->id,
                            'schedule_id' => $schedule->id,
                            'attendance_date' => $attendanceDate,
                            'school_id' => $qrSchoolId,
                        ],
                        [
                            'class_id' => $schedule->class_id,
                            'status' => $status,
                            'check_in_time' => $now, // DateTime (School TZ)
                            'latitude' => $scanData['latitude'] ?? null,
                            'longitude' => $scanData['longitude'] ?? null,
                            'device_id' => $scanData['device_id'] ?? null,
                            'scan_method' => 'qr_code',
                        ]
                    );

                    Log::info('attendance_success', [
                        'attendance_id' => $attendance->id,
                        'school_tz' => $school->timezone,
                        'check_in_local' => $now->toDateTimeString(),
                        'attendance_date' => $attendanceDate
                    ]);

                    // ASYNC DASHBOARD UPDATE (Event Driven)
                    // Fire & Forget: Update summary tables in background worker
                    \App\Events\AttendanceRecorded::dispatch($attendance);

                    return [
                        'success' => true,
                        'status' => $status,
                        'message' => 'Kehadiran berhasil dicatat.',
                        'attendance' => $attendance,
                    ];

                } catch (QueryException $e) {
                    // 5.5 Handle Duplicate Entry (Race Condition Bypass)
                    if ($e->getCode() === '23000') {
                        $existing = $this->findExistingAttendance($student->id, $schedule->id, $attendanceDate);
                        return [
                            'success' => true,
                            'status' => 'duplicate',
                            'message' => 'Kehadiran sudah tercatat (DB).',
                            'attendance' => $existing,
                        ];
                    }
                    throw $e;
                }

            }, self::MAX_DEADLOCK_RETRIES);
            
        } catch (QueryException $e) {
            if ($e->getCode() === '40001') {
                throw new AttendanceException('Sistem sibuk (Deadlock). Silakan coba lagi.', 503);
            }
            throw $e;
        } catch (\Exception $e) {
            if ($e instanceof AttendanceException) throw $e;
            
            Log::error('attendance_system_error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new AttendanceException('Terjadi kesalahan sistem.', 500);
        }
    }

    /**
     * Generate Secure QR Code (Timezone Aware)
     */
    public function generateQR(int $scheduleId, User $teacher): array
    {
        // 1. Fetch School & Teacher validation
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
             throw new AttendanceException('Akses ditolak.');
        }
        
        // Fetch fresh School model for timezone
        $school = School::find($teacher->school_id);
        if (!$school) {
            throw new AttendanceException('Sekolah tidak terdaftar.');
        }

        // 2. Validate Schedule ownership
        $schedule = Schedule::where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->where('is_active', true)
            ->firstOrFail();

        // 3. Generate Payload with School Time
        $qrKey = "qr_active:{$school->id}:{$scheduleId}";
        
        try {
            // Check existing (Redis is fast enough regardless of timezone, but logic consistency matters)
            $existingJson = Redis::get($qrKey);
            if ($existingJson) {
                return json_decode($existingJson, true);
            }

            $now = Timezone::now($school);
            $expiry = self::QR_LOCK_TTL;
            $expiresAt = $now->copy()->addSeconds($expiry);
            
            $payload = [
                'schedule_id' => $scheduleId,
                'school_id' => $school->id,
                'nonce' => Str::random(16),
                'issued_at_tz' => $now->toIso8601String(), // Debugging purpose
                'expires_at_timestamp' => $expiresAt->timestamp // Timestamp is absolute, safe for cross-timezone
            ];
            
            $payload['signature'] = hash_hmac('sha256', json_encode($payload), config('app.qr_secret_key'));
            $token = base64_encode(json_encode($payload));

            $response = [
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'seconds_remaining' => $expiry
            ];

            // Atomic Set
            $set = Redis::set($qrKey, json_encode($response), 'EX', $expiry, 'NX');
            
            if (!$set) {
                $newItem = Redis::get($qrKey);
                return $newItem ? json_decode($newItem, true) : $response;
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('qr_gen_failed', ['msg' => $e->getMessage()]);
            throw new AttendanceException('Gagal generate QR.', 500);
        }
    }

    private function validateAndDecodeQrToken(string $token, School $school): array
    {
        $json = base64_decode($token);
        $payload = json_decode($json, true);

        if (!$payload || !isset($payload['signature'])) {
            throw new AttendanceException('QR Code rusak.');
        }

        $sig = $payload['signature'];
        unset($payload['signature']);
        
        $calcSig = hash_hmac('sha256', json_encode($payload), config('app.qr_secret_key'));

        if (!hash_equals($calcSig, $sig)) {
            throw new AttendanceException('QR Code palsu.');
        }

        // Expiry Check (Using Timestamp is safest, but comparing logical time is good too)
        // Here payload uses timestamp which is absolute.
        // It's safer to compare timestamp against School's NOW timestamp
        $schoolNowTimestamp = Timezone::now($school)->timestamp;

        if ($schoolNowTimestamp > ($payload['expires_at_timestamp'] ?? 0)) {
            throw new AttendanceException('QR Code kadaluarsa.');
        }

        return $payload;
    }

    private function findExistingAttendance($studentId, $scheduleId, $dateString)
    {
        return Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', $dateString)
            ->first();
    }

    private function validateLocation($schoolId, $lat, $lng)
    {
        // Placeholder for GPS logic
    }

    private function calculateStatus(Schedule $schedule, Carbon $checkInTime): string
    {
        // Parse start_time using School's timezone context
        // $schedule->start_time is usually '07:00:00'
        
        // Make a copy of checkInTime to set the schedule time, keeping the date same as check-in
        $scheduleTime = $checkInTime->copy()->setTimeFromTimeString($schedule->start_time);
        
        // Add late tolerance (e.g., 15 mins)
        $lateThreshold = $scheduleTime->copy()->addMinutes(15);
        
        if ($checkInTime->gt($lateThreshold)) {
            return 'late';
        }

        return 'present';
    }
}
