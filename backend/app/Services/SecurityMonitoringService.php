<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\SuspiciousDevice;
use App\Models\SuspiciousStudent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SecurityMonitoringService
{
    /**
     * Log a security event.
     */
    public function logEvent(array $data): SecurityEvent
    {
        $event = SecurityEvent::create([
            'school_id' => $data['school_id'],
            'user_id' => $data['user_id'] ?? null,
            'student_id' => $data['student_id'] ?? null,
            'event_type' => $data['event_type'],
            'severity' => $data['severity'] ?? 'medium',
            'ip_address' => $data['ip_address'] ?? request()->ip(),
            'device_id' => $data['device_id'] ?? null,
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
            'context' => $data['context'] ?? ($data['details'] ?? null),
        ]);

        // Auto-flag if thresholds exceeded
        if (isset($data['student_id'])) {
            $this->checkAndFlagStudent($data['student_id'], $data['event_type']);
        }

        // Track suspicious device
        if (isset($data['device_id']) && isset($data['student_id'])) {
            $this->trackDevice($data['school_id'], $data['device_id'], $data['student_id'], $data['ip_address'] ?? null);
        }

        return $event;
    }

    /**
     * Check if student should be flagged based on violations.
     */
    protected function checkAndFlagStudent(int $studentId, string $eventType): void
    {
        $student = User::where('id', $studentId)
            ->where('role_type', 'student')
            ->first();
            
        if (! $student) {
            return;
        }

        $weekAgo = Carbon::now()->subDays(7);

        // Count violations in last 7 days
        $violationCount = SecurityEvent::where('student_id', $studentId)
            ->where('created_at', '>=', $weekAgo)
            ->whereIn('event_type', [
                'invalid_qr_attempt',
                'expired_qr_scan',
                'location_mismatch',
                'qr_sharing_suspected',
            ])
            ->count();

        // Threshold: >3 violations in a week
        if ($violationCount > 3) {
            $this->flagStudent($student->school_id, $studentId, 'multiple_invalid_scans', $violationCount);
        }

        // Check for multiple devices
        $deviceCount = SecurityEvent::where('student_id', $studentId)
            ->where('created_at', '>=', $weekAgo)
            ->distinct('device_id')
            ->count('device_id');

        if ($deviceCount > 3) {
            $this->flagStudent($student->school_id, $studentId, 'multiple_devices', $deviceCount);
        }

        // Check for location mismatch pattern
        $locationViolations = SecurityEvent::where('student_id', $studentId)
            ->where('event_type', 'location_mismatch')
            ->where('created_at', '>=', $weekAgo)
            ->count();

        if ($locationViolations > 2) {
            $this->flagStudent($student->school_id, $studentId, 'location_mismatch_pattern', $locationViolations);
        }
    }

    /**
     * Flag a student as suspicious.
     */
    protected function flagStudent(int $schoolId, int $studentId, string $reason, int $violationCount): void
    {
        $existing = SuspiciousStudent::where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('status', 'flagged')
            ->first();

        if ($existing) {
            // Update existing flag
            $existing->incrementViolations();

            $evidence = $existing->evidence ?? [];
            $evidence[] = [
                'reason' => $reason,
                'count' => $violationCount,
                'detected_at' => now()->toDateTimeString(),
            ];

            $existing->update(['evidence' => $evidence]);
        } else {
            // Create new flag
            SuspiciousStudent::create([
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'flag_reason' => $reason,
                'violation_count' => $violationCount,
                'evidence' => [[
                    'reason' => $reason,
                    'count' => $violationCount,
                    'detected_at' => now()->toDateTimeString(),
                ]],
                'status' => 'flagged',
                'flagged_at' => now(),
            ]);
        }
    }

    /**
     * Track suspicious device usage.
     */
    protected function trackDevice(int $schoolId, string $deviceId, int $studentId, ?string $ipAddress): void
    {
        $device = SuspiciousDevice::where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->first();

        if ($device) {
            $device->addStudent($studentId);
            if ($ipAddress) {
                $device->addIpAddress($ipAddress);
            }
            $device->incrementScans();
        } else {
            SuspiciousDevice::create([
                'school_id' => $schoolId,
                'device_id' => $deviceId,
                'unique_students_count' => 1,
                'scan_attempt_count' => 1,
                'failed_attempt_count' => 0,
                'student_ids' => [$studentId],
                'ip_addresses' => $ipAddress ? [$ipAddress] : [],
                'risk_level' => 'low',
                'blocked' => false,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }
    }

    /**
     * Record failed scan attempt.
     */
    public function recordFailedScan(int $schoolId, string $deviceId, int $studentId): void
    {
        $device = SuspiciousDevice::where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->first();

        if ($device) {
            $device->incrementScans(true); // true = failed
        }
    }

    /**
     * Check if device is blocked.
     */
    public function isDeviceBlocked(int $schoolId, string $deviceId): bool
    {
        return SuspiciousDevice::where('school_id', $schoolId)
            ->where('device_id', $deviceId)
            ->where('blocked', true)
            ->exists();
    }

    /**
     * Get security metrics for dashboard.
     */
    public function getDashboardMetrics(int $schoolId, ?string $date = null): array
    {
        $date = $date ?? now()->toDateString();

        return [
            'total_events_today' => SecurityEvent::where('school_id', $schoolId)
                ->whereDate('created_at', $date)
                ->count(),

            'high_severity_count' => SecurityEvent::where('school_id', $schoolId)
                ->whereIn('severity', ['high', 'critical'])
                ->whereDate('created_at', $date)
                ->count(),

            'flagged_students_count' => SuspiciousStudent::where('school_id', $schoolId)
                ->where('status', 'flagged')
                ->count(),

            'suspicious_devices_count' => SuspiciousDevice::where('school_id', $schoolId)
                ->whereIn('risk_level', ['high', 'critical'])
                ->count(),

            'unreviewed_events_count' => SecurityEvent::where('school_id', $schoolId)
                ->where('is_resolved', false)
                ->whereIn('severity', ['high', 'critical'])
                ->count(),
        ];
    }

    /**
     * Get event type breakdown.
     */
    public function getEventTypeBreakdown(int $schoolId, int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);

        return SecurityEvent::where('school_id', $schoolId)
            ->where('created_at', '>=', $startDate)
            ->select('event_type', DB::raw('count(*) as count'))
            ->groupBy('event_type')
            ->orderBy('count', 'desc')
            ->get()
            ->pluck('count', 'event_type')
            ->toArray();
    }

    /**
     * Get severity breakdown.
     */
    public function getSeverityBreakdown(int $schoolId, int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);

        return SecurityEvent::where('school_id', $schoolId)
            ->where('created_at', '>=', $startDate)
            ->select('severity', DB::raw('count(*) as count'))
            ->groupBy('severity')
            ->get()
            ->pluck('count', 'severity')
            ->toArray();
    }

    /**
     * Get event trend (daily counts).
     */
    public function getTrend(int $schoolId, int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $events = SecurityEvent::where('school_id', $schoolId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN severity = "critical" THEN 1 ELSE 0 END) as critical'),
                DB::raw('SUM(CASE WHEN severity = "high" THEN 1 ELSE 0 END) as high'),
                DB::raw('SUM(CASE WHEN severity = "medium" THEN 1 ELSE 0 END) as medium'),
                DB::raw('SUM(CASE WHEN severity = "low" THEN 1 ELSE 0 END) as low')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->toArray();

        return $events;
    }

    /**
     * Get recent critical/high severity events.
     */
    public function getRecentCritical(int $schoolId, int $limit = 20): array
    {
        return SecurityEvent::where('school_id', $schoolId)
            ->whereIn('severity', ['high', 'critical'])
            ->with(['user:id,name,email', 'student:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity,
                    'student_id' => $event->student_id,
                    'user_id' => $event->user_id,
                    'ip_address' => $event->ip_address,
                    'device_id' => $event->device_id,
                    'context' => $event->context,
                    'created_at' => $event->created_at->toIso8601String(),
                    'user_name' => $event->user->name ?? 'Unknown',
                    'student_name' => $event->student->name ?? null,
                ];
            })
            ->toArray();
    }
}
