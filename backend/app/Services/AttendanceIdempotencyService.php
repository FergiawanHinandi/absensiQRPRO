<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * AttendanceIdempotencyService
 *
 * Redis-based idempotency check for attendance operations.
 * This provides the FASTEST layer of duplicate prevention using Redis SET NX.
 *
 * PROTECTION LAYERS (in order):
 * 1. Redis idempotency (this service) - ~1ms, prevents 99% of duplicates
 * 2. Application-level lock (AttendanceLockService) - ~5ms, prevents concurrent access
 * 3. Database transaction with row lock - ~20ms, guarantees atomicity
 * 4. Unique constraint - final safety net
 *
 * WHY REDIS FIRST?
 * - Redis SET NX is atomic and ~100x faster than database operations
 * - Prevents expensive database transactions for obvious duplicates
 * - Offloads traffic from database during peak scan times
 *
 * @version 1.0.0
 */
class AttendanceIdempotencyService
{
    /**
     * TTL for idempotency keys in seconds
     * 120 seconds = 2 minutes, enough to cover:
     * - Network retries (30s timeout + retries)
     * - App crash recovery
     * - Offline queue processing
     */
    private const IDEMPOTENCY_TTL = 120;

    /**
     * Key prefix for idempotency keys
     */
    private const KEY_PREFIX = 'attendance_scan';

    /**
     * Check if attendance scan is already being processed or was processed
     *
     * Uses Redis SET NX (Set if Not eXists) for atomic check-and-set.
     *
     * IMPORTANT: This method MUST be called BEFORE any other validation.
     * If it returns false, the request should be rejected immediately.
     *
     * @param int $scheduleId
     * @param int $studentId
     * @param string $date Format: Y-m-d
     * @return bool True if this is a new request, false if duplicate
     */
    public function tryAcquire(int $scheduleId, int $studentId, string $date): bool
    {
        $key = $this->buildKey($scheduleId, $studentId, $date);

        try {
            // Redis SET NX (only set if key doesn't exist)
            // Returns true if key was set (new request)
            // Returns false if key already exists (duplicate)
            $result = Redis::set($key, 1, 'EX', self::IDEMPOTENCY_TTL, 'NX');

            if (!$result) {
                Log::channel('attendance')->info('Idempotency check blocked duplicate', [
                    'key' => $key,
                    'schedule_id' => $scheduleId,
                    'student_id' => $studentId,
                    'date' => $date,
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // If Redis fails, log and allow request to proceed
            // (other protection layers will catch duplicates)
            Log::channel('attendance')->warning('Redis idempotency check failed, proceeding', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Release idempotency key (e.g., if transaction fails)
     *
     * Call this if the attendance creation fails AFTER tryAcquire returned true.
     * This allows the student to retry if something went wrong.
     *
     * @param int $scheduleId
     * @param int $studentId
     * @param string $date
     * @return void
     */
    public function release(int $scheduleId, int $studentId, string $date): void
    {
        $key = $this->buildKey($scheduleId, $studentId, $date);

        try {
            Redis::del($key);
            Log::channel('attendance')->debug('Idempotency key released', ['key' => $key]);
        } catch (\Exception $e) {
            // Silent fail - key will expire anyway
            Log::channel('attendance')->warning('Failed to release idempotency key', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if attendance was already processed (read-only check)
     *
     * Unlike tryAcquire, this doesn't set the key.
     * Useful for checking status without side effects.
     *
     * @param int $scheduleId
     * @param int $studentId
     * @param string $date
     * @return bool True if already processed
     */
    public function wasProcessed(int $scheduleId, int $studentId, string $date): bool
    {
        $key = $this->buildKey($scheduleId, $studentId, $date);

        try {
            return Redis::exists($key) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get existing attendance if idempotency key exists
     *
     * Useful for returning the existing record on retry.
     *
     * @param int $scheduleId
     * @param int $studentId
     * @param string $date
     * @return Attendance|null
     */
    public function getExistingAttendance(int $scheduleId, int $studentId, string $date): ?Attendance
    {
        if (!$this->wasProcessed($scheduleId, $studentId, $date)) {
            return null;
        }

        return Attendance::where('schedule_id', $scheduleId)
            ->where('student_id', $studentId)
            ->whereDate('attendance_date', $date)
            ->first();
    }

    /**
     * Build Redis key for idempotency check
     *
     * Key format: attendance_scan:{schedule_id}:{student_id}:{date}
     *
     * @param int $scheduleId
     * @param int $studentId
     * @param string $date
     * @return string
     */
    private function buildKey(int $scheduleId, int $studentId, string $date): string
    {
        return sprintf(
            '%s:%d:%d:%s',
            self::KEY_PREFIX,
            $scheduleId,
            $studentId,
            $date
        );
    }

    /**
     * Health check for Redis connection
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
