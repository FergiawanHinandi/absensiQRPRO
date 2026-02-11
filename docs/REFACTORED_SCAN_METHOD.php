<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * REFACTORED AttendanceService::scan() - 100% Atomic
 * 
 * Requirements Met:
 * 1. ✅ DB::transaction() for atomicity
 * 2. ✅ try/catch QueryException for duplicate key errors
 * 3. ✅ Returns existing record on duplicate
 * 4. ✅ Redis idempotency with SET NX
 * 5. ✅ Returns "already processed" if Redis lock fails
 * 6. ✅ Comprehensive logging
 * 7. ✅ No firstOrCreate() - manual INSERT with duplicate handling
 * 8. ✅ Production-safe with proper error handling
 */
class AttendanceServiceRefactored
{
    /**
     * Process QR code scan for attendance - 100% Atomic Implementation
     * 
     * @param User $student The authenticated student
     * @param array $scanData QR scan data (qr_token, lat, lng, device_id, schedule_id, etc)
     * @param \Illuminate\Http\Request $request HTTP request for logging
     * @return Attendance
     * @throws AttendanceException
     */
    public function scan(User $student, array $scanData, $request): Attendance
    {
        // Validate student has school_id
        if (!$student->school_id) {
            throw new AttendanceException('Siswa tidak terdaftar di sekolah manapun.');
        }

        // Extract required data
        $scheduleId = $scanData['schedule_id'] ?? null;
        $qrToken = $scanData['qr_token'] ?? null;
        $latitude = $scanData['lat'] ?? null;
        $longitude = $scanData['lng'] ?? null;
        $deviceId = $scanData['device_id'] ?? null;
        $requestId = $scanData['request_id'] ?? (string) Str::uuid();

        if (!$scheduleId) {
            throw new AttendanceException('Schedule ID tidak ditemukan dalam QR code.');
        }

        // Use server date for consistency
        $serverDate = now()->toDateString();

        // ============================================================
        // LAYER 1: REDIS IDEMPOTENCY CHECK (FASTEST - ~1ms)
        // ============================================================
        // Key format: attendance_scan:{schedule_id}:{student_id}:{date}
        // TTL: 120 seconds
        // Uses SET NX (Set if Not eXists) for atomic check-and-set
        // ============================================================
        
        $redisKey = "attendance_scan:{$scheduleId}:{$student->id}:{$serverDate}";
        
        // Try to acquire Redis lock atomically
        // SET key value EX 120 NX
        // Returns true if key was set, false if key already exists
        $lockAcquired = Redis::set($redisKey, 1, 'EX', 120, 'NX');
        
        if (!$lockAcquired) {
            // Lock acquisition failed - another request is processing or already processed
            Log::channel('attendance')->info('attendance_duplicate_attempt', [
                'student_id' => $student->id,
                'schedule_id' => $scheduleId,
                'date' => $serverDate,
                'reason' => 'redis_idempotency_blocked',
                'ip' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 100),
            ]);

            // Try to return existing attendance record
            $existing = Attendance::where('student_id', $student->id)
                ->where('schedule_id', $scheduleId)
                ->whereDate('attendance_date', $serverDate)
                ->first();

            if ($existing) {
                Log::channel('attendance')->info('attendance_duplicate_attempt_returned_existing', [
                    'attendance_id' => $existing->id,
                    'student_id' => $student->id,
                    'schedule_id' => $scheduleId,
                    'status' => $existing->status,
                ]);

                return $existing;
            }

            // No record found yet - another request is still processing
            throw new AttendanceException('Absensi sedang diproses. Silakan tunggu beberapa saat.');
        }

        // ============================================================
        // LAYER 2: DATABASE TRANSACTION (ACID Guarantees)
        // ============================================================
        
        try {
            $attendance = DB::transaction(function () use (
                $student,
                $scheduleId,
                $serverDate,
                $latitude,
                $longitude,
                $deviceId,
                $requestId,
                $request
            ) {
                // ============================================================
                // STEP 1: Fetch and validate schedule with row lock
                // ============================================================
                
                $schedule = Schedule::where('id', $scheduleId)
                    ->where('school_id', $student->school_id)
                    ->where('is_active', true)
                    ->lockForUpdate() // Acquire exclusive lock on schedule row
                    ->first();

                if (!$schedule) {
                    throw new AttendanceException('Jadwal tidak ditemukan atau tidak aktif.');
                }

                // ============================================================
                // STEP 2: Check for existing attendance with row lock
                // ============================================================
                // This prevents race conditions at database level
                // If row exists, we get exclusive lock
                // If row doesn't exist, we get gap lock (prevents concurrent INSERT)
                
                $existing = Attendance::where('student_id', $student->id)
                    ->where('schedule_id', $scheduleId)
                    ->whereDate('attendance_date', $serverDate)
                    ->lockForUpdate() // Acquire exclusive lock or gap lock
                    ->first();

                if ($existing) {
                    // Duplicate detected - log and return existing record
                    Log::channel('attendance')->warning('attendance_duplicate_attempt', [
                        'attendance_id' => $existing->id,
                        'student_id' => $student->id,
                        'schedule_id' => $scheduleId,
                        'date' => $serverDate,
                        'existing_status' => $existing->status,
                        'existing_check_in_time' => $existing->check_in_time?->toDateTimeString(),
                        'reason' => 'database_duplicate_found',
                        'ip' => $request->ip(),
                    ]);

                    return $existing;
                }

                // ============================================================
                // STEP 3: Validate time window and determine status
                // ============================================================
                
                $status = $this->determineAttendanceStatus($schedule);

                // ============================================================
                // STEP 4: Create new attendance record
                // ============================================================
                // Wrapped in try-catch to handle duplicate key violations
                // that might slip through (e.g., if gap locks aren't supported)
                
                try {
                    $attendance = Attendance::create([
                        'school_id' => $student->school_id,
                        'schedule_id' => $scheduleId,
                        'student_id' => $student->id,
                        'attendance_date' => $serverDate,
                        'status' => $status,
                        'check_in_time' => now(), // Server time
                        'lat_in' => $latitude,
                        'lng_in' => $longitude,
                        'device_id_in' => $deviceId,
                        'is_manual' => false,
                        'attendance_type' => 'qr_scan',
                        'recorded_by' => null,
                        'request_id' => $requestId,
                    ]);

                    // Log successful creation
                    Log::channel('attendance')->info('attendance_success', [
                        'attendance_id' => $attendance->id,
                        'student_id' => $student->id,
                        'schedule_id' => $scheduleId,
                        'date' => $serverDate,
                        'status' => $status,
                        'check_in_time' => $attendance->check_in_time->toDateTimeString(),
                        'ip' => $request->ip(),
                        'device_id' => substr($deviceId ?? '', 0, 16),
                    ]);

                    return $attendance;

                } catch (QueryException $e) {
                    // ============================================================
                    // HANDLE DUPLICATE KEY VIOLATION
                    // ============================================================
                    // This catches race conditions that slip through row locks
                    // (e.g., MyISAM tables, or concurrent requests on different servers)
                    
                    if ($this->isDuplicateKeyError($e)) {
                        Log::channel('attendance')->warning('attendance_duplicate_attempt', [
                            'student_id' => $student->id,
                            'schedule_id' => $scheduleId,
                            'date' => $serverDate,
                            'reason' => 'duplicate_key_exception_caught',
                            'error_code' => $e->getCode(),
                            'ip' => $request->ip(),
                        ]);

                        // Fetch and return the existing record
                        $existingRecord = Attendance::where('student_id', $student->id)
                            ->where('schedule_id', $scheduleId)
                            ->whereDate('attendance_date', $serverDate)
                            ->first();

                        if ($existingRecord) {
                            Log::channel('attendance')->info('attendance_duplicate_attempt_returned_existing', [
                                'attendance_id' => $existingRecord->id,
                                'status' => $existingRecord->status,
                            ]);

                            return $existingRecord;
                        }

                        // Edge case: duplicate key error but can't find record
                        throw new AttendanceException('Absensi sudah tercatat.');
                    }

                    // Re-throw if not a duplicate key error
                    throw $e;
                }
            }); // End DB::transaction

            return $attendance;

        } catch (AttendanceException $e) {
            // Release Redis lock on business logic errors
            Redis::del($redisKey);
            throw $e;
        } catch (QueryException $e) {
            // Release Redis lock on database errors
            Redis::del($redisKey);
            
            Log::channel('attendance')->error('attendance_database_error', [
                'student_id' => $student->id,
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw new AttendanceException('Terjadi kesalahan saat menyimpan absensi. Silakan coba lagi.');
        } catch (\Exception $e) {
            // Release Redis lock on unexpected errors
            Redis::del($redisKey);
            
            Log::channel('attendance')->error('attendance_unexpected_error', [
                'student_id' => $student->id,
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new AttendanceException('Terjadi kesalahan sistem. Silakan hubungi administrator.');
        }
    }

    /**
     * Determine attendance status based on schedule time window
     * 
     * @param Schedule $schedule
     * @return string 'present' or 'late'
     */
    private function determineAttendanceStatus(Schedule $schedule): string
    {
        $now = now();
        $today = $now->format('Y-m-d');
        
        $startTime = Carbon::parse("{$today} {$schedule->start_time}");
        
        // Get late tolerance from school settings (default: 15 minutes)
        $lateTolerance = 15; // Can be fetched from school settings
        $lateThreshold = $startTime->copy()->addMinutes($lateTolerance);
        
        // Determine status: present or late
        return $now->greaterThan($lateThreshold) ? 'late' : 'present';
    }

    /**
     * Check if exception is a duplicate key violation
     * 
     * @param QueryException $e
     * @return bool
     */
    private function isDuplicateKeyError(QueryException $e): bool
    {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();
        
        // PostgreSQL: 23505 (unique_violation)
        if ($errorCode == 23505) {
            return true;
        }
        
        // MySQL: 1062 (ER_DUP_ENTRY)
        if ($errorCode == 1062 || $errorCode == '23000') {
            return true;
        }
        
        // Check error message for duplicate key patterns
        $duplicatePatterns = [
            'duplicate key',
            'unique constraint',
            'UNIQUE constraint failed',
            'Duplicate entry',
        ];
        
        foreach ($duplicatePatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
