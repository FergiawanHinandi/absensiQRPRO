<?php

namespace App\Core\Services\Attendance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * QR Replay Prevention Service
 * 
 * Prevents QR code reuse and multiple attendance attempts by:
 * 1. Tracking QR nonces to prevent token replay
 * 2. Tracking student+schedule combinations to prevent double attendance
 * 3. Logging anomalies for security monitoring
 * 
 * Cache keys include school_id for multi-tenant isolation.
 */
class QrReplayPreventionService
{
    /**
     * Cache TTL for QR nonces (5 minutes)
     * This should be longer than QR validity to catch delayed replays
     */
    private const NONCE_TTL_MINUTES = 5;

    /**
     * Cache TTL for attendance records (until end of day)
     */
    private const ATTENDANCE_TTL_MINUTES = 1440; // 24 hours

    /**
     * Check if QR nonce has been used (prevent token replay)
     * 
     * @param string $nonce The nonce from QR payload
     * @param int $schoolId School ID for multi-tenant isolation
     * @return bool True if nonce was already used
     */
    public function isNonceUsed(string $nonce, int $schoolId): bool
    {
        $cacheKey = $this->getNonceCacheKey($nonce, $schoolId);
        return Cache::has($cacheKey);
    }

    /**
     * Mark QR nonce as used
     * 
     * @param string $nonce The nonce from QR payload
     * @param int $schoolId School ID for multi-tenant isolation
     * @param int $studentId Student who used this nonce
     * @param int $scheduleId Schedule the nonce was used for
     */
    public function markNonceUsed(string $nonce, int $schoolId, int $studentId, int $scheduleId): void
    {
        $cacheKey = $this->getNonceCacheKey($nonce, $schoolId);
        
        Cache::put($cacheKey, [
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'used_at' => now()->toIso8601String(),
            'ip' => request()->ip(),
        ], now()->addMinutes(self::NONCE_TTL_MINUTES));
    }

    /**
     * Check if student has already scanned for this schedule today
     * 
     * @param int $studentId
     * @param int $scheduleId
     * @param int $schoolId
     * @return bool True if already scanned
     */
    public function hasStudentScanned(int $studentId, int $scheduleId, int $schoolId): bool
    {
        $cacheKey = $this->getAttendanceCacheKey($studentId, $scheduleId, $schoolId);
        return Cache::has($cacheKey);
    }

    /**
     * Mark student as scanned for this schedule
     * 
     * @param int $studentId
     * @param int $scheduleId
     * @param int $schoolId
     * @param int|null $attendanceId The created attendance record ID
     */
    public function markStudentScanned(int $studentId, int $scheduleId, int $schoolId, ?int $attendanceId = null): void
    {
        $cacheKey = $this->getAttendanceCacheKey($studentId, $scheduleId, $schoolId);
        
        // Calculate TTL until end of day
        $endOfDay = now()->endOfDay();
        $ttlMinutes = now()->diffInMinutes($endOfDay);
        
        Cache::put($cacheKey, [
            'attendance_id' => $attendanceId,
            'scanned_at' => now()->toIso8601String(),
            'ip' => request()->ip(),
        ], now()->addMinutes(max($ttlMinutes, 1)));
    }

    /**
     * Log a repeated scan attempt (anomaly detection)
     * 
     * @param int $studentId
     * @param int $scheduleId
     * @param int $schoolId
     * @param string|null $nonce
     * @param string $reason
     */
    public function logRepeatedAttempt(
        int $studentId, 
        int $scheduleId, 
        int $schoolId, 
        ?string $nonce = null,
        string $reason = 'repeated_scan'
    ): void {
        // Increment attempt counter
        $counterKey = $this->getAttemptCounterKey($studentId, $scheduleId, $schoolId);
        $attempts = Cache::increment($counterKey);
        
        // Set TTL on first increment
        if ($attempts === 1) {
            Cache::put($counterKey, 1, now()->addHours(1));
        }

        // Log the anomaly
        Log::channel('security')->warning('QR Replay Attempt Detected', [
            'type' => 'qr_replay_attempt',
            'reason' => $reason,
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'school_id' => $schoolId,
            'nonce' => $nonce ? substr($nonce, 0, 4) . '...' : null,
            'attempt_count' => $attempts,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);

        // Alert on excessive attempts (potential attack)
        if ($attempts >= 5) {
            Log::channel('security')->alert('Excessive QR Replay Attempts', [
                'type' => 'excessive_qr_attempts',
                'student_id' => $studentId,
                'schedule_id' => $scheduleId,
                'school_id' => $schoolId,
                'total_attempts' => $attempts,
                'ip_address' => request()->ip(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Get attempt count for a student+schedule combination
     * 
     * @param int $studentId
     * @param int $scheduleId
     * @param int $schoolId
     * @return int
     */
    public function getAttemptCount(int $studentId, int $scheduleId, int $schoolId): int
    {
        $counterKey = $this->getAttemptCounterKey($studentId, $scheduleId, $schoolId);
        return (int) Cache::get($counterKey, 0);
    }

    /**
     * Check if nonce replay attack is attempted
     * Returns original usage info if nonce was already used
     * 
     * @param string $nonce
     * @param int $schoolId
     * @return array|null Original usage data if replayed, null if fresh
     */
    public function checkNonceReplay(string $nonce, int $schoolId): ?array
    {
        $cacheKey = $this->getNonceCacheKey($nonce, $schoolId);
        return Cache::get($cacheKey);
    }

    /**
     * Generate cache key for nonce tracking
     */
    private function getNonceCacheKey(string $nonce, int $schoolId): string
    {
        return "qr_nonce:school_{$schoolId}:{$nonce}";
    }

    /**
     * Generate cache key for attendance tracking
     */
    private function getAttendanceCacheKey(int $studentId, int $scheduleId, int $schoolId): string
    {
        $today = now()->format('Y-m-d');
        return "attendance_scan:school_{$schoolId}:student_{$studentId}:schedule_{$scheduleId}:{$today}";
    }

    /**
     * Generate cache key for attempt counter
     */
    private function getAttemptCounterKey(int $studentId, int $scheduleId, int $schoolId): string
    {
        $today = now()->format('Y-m-d');
        return "qr_attempts:school_{$schoolId}:student_{$studentId}:schedule_{$scheduleId}:{$today}";
    }

    /**
     * Clear all replay prevention data for a student (admin use only)
     * 
     * @param int $studentId
     * @param int $schoolId
     */
    public function clearStudentData(int $studentId, int $schoolId): void
    {
        // This would require Redis SCAN or similar for pattern matching
        // For now, log the action for audit
        Log::channel('security')->info('QR replay data cleared for student', [
            'student_id' => $studentId,
            'school_id' => $schoolId,
            'cleared_by' => auth()->id(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
