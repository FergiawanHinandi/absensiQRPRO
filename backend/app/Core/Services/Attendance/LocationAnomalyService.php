<?php

namespace App\Core\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceFlag;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LocationAnomalyService
{
    /**
     * Check for location anomalies and flag if detected.
     * Does NOT block the attendance, only flags it.
     * UNLESS: Suspicious activity + New/Untrusted Device -> Force Logout
     *
     * @param  Attendance  $currentAttendance  The newly created attendance record
     * @param  array  $scanData  Raw scan data including lat, lng, accuracy, device_id
     */
    public function checkAndFlag(Attendance $currentAttendance, array $scanData): void
    {
        $studentId = $currentAttendance->student_id;
        $schoolId = $currentAttendance->school_id;

        // 1. Get the previous attendance for this student (excluding the current one)
        $lastAttendance = Attendance::where('student_id', $studentId)
            ->where('id', '!=', $currentAttendance->id)
            ->orderBy('created_at', 'desc')
            ->first();

        // Perform Checks & track suspicion
        $anomalyDetected = false;

        if ($this->checkImpossibleTravel($currentAttendance, $lastAttendance)) {
            $anomalyDetected = true;
        }

        if ($this->checkAccuracyAnomaly($currentAttendance, $scanData, $lastAttendance)) {
            $anomalyDetected = true;
        }

        if ($this->checkDeviceChurn($currentAttendance, $scanData, $studentId)) {
            $anomalyDetected = true;
        }

        // CRITICAL SECURITY: If anomaly detected AND device is not trusted -> Force Logout
        if ($anomalyDetected && isset($scanData['device_id'])) {
            $isTrusted = \App\Models\TrustedDevice::where('user_id', $studentId)
                ->where('device_id', $scanData['device_id'])
                ->where('is_trusted', true)
                ->exists();

            if (! $isTrusted) {
                Log::channel('security')->critical('Suspicious Activity on Untrusted Device - Forcing Logout', [
                    'student_id' => $studentId,
                    'device_id' => $scanData['device_id'],
                ]);

                // Revoke all tokens
                User::find($studentId)->tokens()->delete();

                throw new \Exception('Aktivitas mencurigakan terdeteksi dari perangkat yang tidak dikenali demi keamanan akun anda. Silakan login kembali untuk verifikasi.');
            }
        }
    }

    /**
     * Check 1: Impossible Travel
     * Same student scans from 2 locations > 1km apart within 10 minutes
     */
    private function checkImpossibleTravel(Attendance $current, ?Attendance $last): bool
    {
        if (! $last || ! $last->lat_in || ! $last->lng_in || ! $current->lat_in || ! $current->lng_in) {
            return false;
        }

        $minutesDiff = $current->created_at->diffInMinutes($last->created_at);
        if ($minutesDiff <= 10) {
            $distance = $this->calculateDistance(
                $current->lat_in,
                $current->lng_in,
                $last->lat_in,
                $last->lng_in
            );

            if ($distance > 1000) {
                $this->flagAttendance($current, 'impossible_travel', 'high', [
                    'message' => "Detected travel of {$distance}m in {$minutesDiff} minutes.",
                    'prev_lat' => $last->lat_in,
                    'prev_lng' => $last->lng_in,
                    'curr_lat' => $current->lat_in,
                    'curr_lng' => $current->lng_in,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Check 2: Accuracy Jump
     */
    private function checkAccuracyAnomaly(Attendance $current, array $scanData, ?Attendance $last): bool
    {
        $currentAccuracy = $scanData['accuracy'] ?? 0;
        $flagged = false;

        if ($currentAccuracy > 2000) {
            $this->flagAttendance($current, 'poor_accuracy', 'medium', [
                'message' => "GPS Accuracy is remarkably poor ({$currentAccuracy}m).",
                'accuracy' => $currentAccuracy,
            ]);
            $flagged = true;
        } elseif ($currentAccuracy > 500) {
            $this->flagAttendance($current, 'accuracy_jump', 'low', [
                'message' => "GPS Accuracy is poor ({$currentAccuracy}m).",
                'accuracy' => $currentAccuracy,
            ]);
            $flagged = true;
        }

        return $flagged;
    }

    /**
     * Check 3: Device ID Churn
     */
    private function checkDeviceChurn(Attendance $current, array $scanData, int $studentId): bool
    {
        $currentDeviceId = $scanData['device_id'] ?? null;
        if (! $currentDeviceId) {
            return false;
        }

        $recentDevices = Attendance::where('student_id', $studentId)
            ->where('id', '!=', $current->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('device_id_in')
            ->filter()
            ->unique();

        if ($recentDevices->count() >= 2) {
            if (! $recentDevices->contains($currentDeviceId)) {
                $this->flagAttendance($current, 'device_churn', 'medium', [
                    'message' => 'Frequent device changes detected.',
                    'current_device' => $currentDeviceId,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Create a Flag Record and Log
     */
    private function flagAttendance(Attendance $attendance, string $type, string $severity, array $details): void
    {
        // 1. Create DB Record in attendance_flags
        AttendanceFlag::create([
            'school_id' => $attendance->school_id,
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'flag_type' => $type,
            'severity' => $severity,
            'details' => $details,
            // 'device_id' => $attendance->device_id_in // Optional, if column exists in Flags
        ]);

        // 2. Log to Security Channel
        Log::channel('security')->warning("Security Anomaly Detected: {$type}", [
            'student_id' => $attendance->student_id,
            'attendance_id' => $attendance->id,
            'severity' => $severity,
            'details' => $details,
        ]);
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
}
