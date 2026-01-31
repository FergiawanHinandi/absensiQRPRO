<?php

namespace App\Services\Security;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AttendanceFraudService
 *
 * Detects fraudulent attendance patterns (jokit, location spoofing, bots).
 * This service runs AFTER a successful check-in to flag suspicious records.
 *
 * DETECTED PATTERNS:
 * 1. Device Sharing (Joki): Same device used by >3 students in 1 day
 * 2. Impossible Travel: >2 locations in 10 minutes (teleportation)
 * 3. Bot Timing: Consistent check-in at exact same second daily
 * 4. Brute Force: Excessive invalid QR attempts (via cache/log analysis)
 */
class AttendanceFraudService
{
    private const CACHE_PREFIX = 'fraud_detection:';

    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Analyze a new attendance record for fraud
     *
     * @param Attendance $attendance The newly created attendance
     * @param string|null $deviceFingerprint SHA-256 fingerprint hash
     */
    public function analyze(Attendance $attendance, ?string $deviceFingerprint = null): void
    {
        $anomalies = [];
        $studentId = $attendance->student_id;
        $deviceId = $attendance->device_id_in; // Fallback if fingerprint not provided
        
        // 1. Check for Joki (Device Sharing)
        if ($jokiResult = $this->detectJoki($deviceId, $deviceFingerprint, $studentId)) {
            $anomalies[] = $jokiResult;
        }

        // 2. Check for Impossible Travel
        if ($travelResult = $this->detectImpossibleTravel($attendance)) {
            $anomalies[] = $travelResult;
        }

        // 3. Check for Bot Pattern (Timing)
        if ($botResult = $this->detectBotTiming($attendance)) {
            $anomalies[] = $botResult;
        }

        // 4. Check for Brute Force History
        if ($bruteResult = $this->detectBruteForceHistory($studentId)) {
            $anomalies[] = $bruteResult;
        }

        // Log findings
        if (!empty($anomalies)) {
            $this->logFraud($attendance, $anomalies);
            
            // Flag the attendance record (without blocking)
            // Storing in logs table to keep main table clean
            AttendanceLog::create([
                'attendance_id' => $attendance->id,
                'action_type' => 'fraud_flag',
                'description' => 'Suspicious patterns detected: ' . implode(', ', array_column($anomalies, 'type')),
                'metadata' => ['anomalies' => $anomalies],
                'user_id' => null, // System event
            ]);
        }
    }

    /**
     * Rule 1: Same device used by >3 students in 1 day (Joki)
     */
    private function detectJoki(?string $deviceId, ?string $fingerprint, int $studentId): ?array
    {
        if (empty($deviceId) && empty($fingerprint)) {
            return null;
        }

        // Use fingerprint if available (more secure), else raw device ID
        $identifier = $fingerprint ?: "raw:{$deviceId}";
        $today = now()->toDateString();
        $cacheKey = self::CACHE_PREFIX . "device_users:{$identifier}:{$today}";

        $users = Cache::get($cacheKey, []);
        
        if (!in_array($studentId, $users)) {
            $users[] = $studentId;
            Cache::put($cacheKey, $users, 86400); // 24 hours
        }

        $count = count($users);
        if ($count > 3) {
            return [
                'type' => 'joki_device_sharing',
                'severity' => 'HIGH',
                'details' => "Device used by {$count} distinct students today.",
                'user_count' => $count,
                'identifier' => $identifier
            ];
        }

        return null;
    }

    /**
     * Rule 2: Student attends from >2 locations in 10 minutes (Impossible Travel)
     */
    private function detectImpossibleTravel(Attendance $current): ?array
    {
        if (!$current->lat_in || !$current->lng_in) {
            return null;
        }

        // Get previous attendance for this student within last 10 minutes
        // (Could be different class, or duplicate attempt that was logged)
        $previous = Attendance::where('student_id', $current->student_id)
            ->where('id', '!=', $current->id)
            ->where('attendance_date', $current->attendance_date)
            ->where('check_in_time', '>=', $current->check_in_time->subMinutes(10))
            ->whereNotNull('lat_in')
            ->latest('check_in_time')
            ->first();

        if (!$previous) {
            return null;
        }

        $distance = $this->calculateDistance(
            $current->lat_in, $current->lng_in,
            $previous->lat_in, $previous->lng_in
        );

        // Limit: > 500 meters in < 10 minutes (approx > 3 km/h walking speed is normal, but let's be generous)
        // If they moved > 1km in 10 mins (6 km/h) it's possible, but > 5km is suspicious if timestamps are very close.
        // Let's use a simpler heuristic: distinct locations > 2 in short time?
        // Or strictly velocity: Distance / Time.
        
        $timeDiffSeconds = max(1, $current->check_in_time->diffInSeconds($previous->check_in_time));
        $speedMps = $distance / $timeDiffSeconds; // meters per second

        // Threshold: 10 m/s (36 km/h) is reasonable for vehicle, but unlikely for classroom changes.
        // Let's flag if > 1000 meters difference.
        if ($distance > 1000) {
            return [
                'type' => 'impossible_travel',
                'severity' => 'MEDIUM',
                'details' => sprintf(
                    "Moved %.2f meters in %d seconds (Speed: %.2f m/s). Impossible for classroom change.",
                    $distance, $timeDiffSeconds, $speedMps
                ),
                'distance_meters' => $distance,
                'previous_id' => $previous->id
            ];
        }

        return null;
    }

    /**
     * Rule 3: Attendance always at exact same second daily (Bot)
     */
    private function detectBotTiming(Attendance $current): ?array
    {
        // Get last 5 attendances
        $history = Attendance::where('student_id', $current->student_id)
            ->where('id', '!=', $current->id)
            ->latest('attendance_date')
            ->limit(5)
            ->get();

        if ($history->count() < 3) {
            return null;
        }

        $sameSecondCount = 0;
        $currentSecond = $current->check_in_time->second;

        foreach ($history as $record) {
            if ($record->check_in_time && $record->check_in_time->second === $currentSecond) {
                $sameSecondCount++;
            }
        }

        // If >= 3 previous records have EXACT same second
        if ($sameSecondCount >= 3) {
            return [
                'type' => 'bot_timing_pattern',
                'severity' => 'LOW',
                'details' => "Consistent check-in at :{$currentSecond} seconds across last {$sameSecondCount} days.",
            ];
        }

        return null;
    }

    /**
     * Rule 4: Excessive invalid QR attempts (Brute Force)
     */
    private function detectBruteForceHistory(int $studentId): ?array
    {
        // Check rate limiter hit count for this user
        // Note: This relies on the rate limiter key used in AttendanceScanThrottle
        // Key format: "attendance:scan:user:{id}"
        $key = "attendance:scan:user:{$studentId}";
        $attempts = Cache::get($key . ':timer'); // No direct way to inspect RateLimiter attempts easily without keys

        // Alternative: Check DB logs for recent failures
        // Assuming we log failures to a separate table or log channel
        
        // For this task, we'll simulate checking a cache counter we increment on failure
        $failureCount = Cache::get(self::CACHE_PREFIX . "failures:{$studentId}", 0);

        if ($failureCount > 5) {
            return [
                'type' => 'excessive_qr_failures',
                'severity' => 'MEDIUM',
                'details' => "User had {$failureCount} failed QR attempts recently.",
            ];
        }

        return null;
    }

    /**
     * Log detected fraud to security channel
     */
    private function logFraud(Attendance $attendance, array $anomalies): void
    {
        $maxSeverity = 'LOW';
        foreach ($anomalies as $a) {
            if (($a['severity'] ?? '') === 'HIGH') $maxSeverity = 'HIGH';
            elseif (($a['severity'] ?? '') === 'MEDIUM' && $maxSeverity !== 'HIGH') $maxSeverity = 'MEDIUM';
        }

        $logLevel = match ($maxSeverity) {
            'HIGH' => 'critical',
            'MEDIUM' => 'warning',
            default => 'info',
        };

        Log::channel('security_json')->{$logLevel}('Attendance Fraud Detected', [
            'event' => 'fraud.attendance_flagged',
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'risk_level' => $maxSeverity,
            'anomalies' => $anomalies,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Helper: Haversine Distance in meters
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371000;
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
}
