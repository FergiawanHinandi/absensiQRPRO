<?php

namespace App\Core\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class SecurityEventLogger
{
    /**
     * Log a student scanning outside their schedule
     */
    public function logOutsideSchedule(
        User $student,
        int $scheduleId,
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_OUTSIDE_SCHEDULE,
            severity: SecurityEvent::SEVERITY_MEDIUM,
            user: $student,
            message: "Siswa {$student->name} mencoba scan di luar jadwal (Schedule ID: {$scheduleId})",
            context: array_merge([
                'schedule_id' => $scheduleId,
                'attempted_at' => now()->toIso8601String(),
            ], $context)
        );
    }

    /**
     * Log a teacher scanning outside school radius
     */
    public function logOutsideRadius(
        User $teacher,
        float $distance,
        float $maxAllowed,
        array $location = [],
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_OUTSIDE_RADIUS,
            severity: SecurityEvent::SEVERITY_HIGH,
            user: $teacher,
            message: "Guru {$teacher->name} mencoba absen dari luar radius sekolah ({$distance}m, maks: {$maxAllowed}m)",
            context: array_merge([
                'distance_meters' => round($distance, 2),
                'max_allowed_meters' => $maxAllowed,
                'excess_meters' => round($distance - $maxAllowed, 2),
                'location' => $location,
            ], $context),
            latitude: $location['lat'] ?? null,
            longitude: $location['lng'] ?? null
        );
    }

    /**
     * Log an unapproved device attempt
     */
    public function logUnapprovedDevice(
        User $user,
        string $deviceId,
        ?string $deviceName = null,
        array $context = []
    ): SecurityEvent {
        $deviceLabel = $deviceName ?? $deviceId;
        
        return $this->log(
            eventType: SecurityEvent::EVENT_UNAPPROVED_DEVICE,
            severity: SecurityEvent::SEVERITY_HIGH,
            user: $user,
            message: "Pengguna {$user->name} mencoba akses dari perangkat yang tidak disetujui ({$deviceLabel})",
            context: array_merge([
                'device_id' => $deviceId,
                'device_name' => $deviceName,
            ], $context),
            deviceId: $deviceId
        );
    }

    /**
     * Log a duplicate attendance attempt
     */
    public function logDuplicateAttempt(
        User $user,
        string $attendanceType,
        ?int $scheduleId = null,
        array $context = []
    ): SecurityEvent {
        $typeLabel = $attendanceType === 'teacher' ? 'Guru' : 'Siswa';
        
        return $this->log(
            eventType: SecurityEvent::EVENT_DUPLICATE_ATTEMPT,
            severity: SecurityEvent::SEVERITY_MEDIUM,
            user: $user,
            message: "{$typeLabel} {$user->name} mencoba melakukan absensi duplikat",
            context: array_merge([
                'attendance_type' => $attendanceType,
                'schedule_id' => $scheduleId,
                'attempted_at' => now()->toIso8601String(),
            ], $context)
        );
    }

    /**
     * Log mock location detection
     */
    public function logMockLocation(
        User $user,
        array $location = [],
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_MOCK_LOCATION,
            severity: SecurityEvent::SEVERITY_CRITICAL,
            user: $user,
            message: "Lokasi palsu (mock location) terdeteksi dari {$user->name}",
            context: array_merge([
                'location' => $location,
            ], $context),
            latitude: $location['lat'] ?? null,
            longitude: $location['lng'] ?? null
        );
    }

    /**
     * Log QR replay attack attempt
     */
    public function logQrReplay(
        User $user,
        string $nonce,
        ?int $scheduleId = null,
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_QR_REPLAY,
            severity: SecurityEvent::SEVERITY_HIGH,
            user: $user,
            message: "Percobaan QR replay attack terdeteksi dari {$user->name}",
            context: array_merge([
                'nonce' => $nonce,
                'schedule_id' => $scheduleId,
            ], $context)
        );
    }

    /**
     * Log QR ownership violation
     */
    public function logQrOwnershipViolation(
        User $user,
        int $qrOwnerId,
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_QR_OWNERSHIP_VIOLATION,
            severity: SecurityEvent::SEVERITY_HIGH,
            user: $user,
            message: "User {$user->name} (ID: {$user->id}) mencoba menggunakan QR milik user ID: {$qrOwnerId}",
            context: array_merge([
                'qr_owner_id' => $qrOwnerId,
                'scanner_id' => $user->id,
            ], $context)
        );
    }

    /**
     * Log poor GPS accuracy
     */
    public function logPoorGpsAccuracy(
        User $user,
        float $accuracy,
        float $maxAllowed,
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_POOR_GPS_ACCURACY,
            severity: SecurityEvent::SEVERITY_LOW,
            user: $user,
            message: "Akurasi GPS buruk terdeteksi dari {$user->name} ({$accuracy}m, maks: {$maxAllowed}m)",
            context: array_merge([
                'accuracy_meters' => $accuracy,
                'max_allowed_meters' => $maxAllowed,
            ], $context)
        );
    }

    /**
     * Log suspicious device change
     */
    public function logSuspiciousDeviceChange(
        User $user,
        string $oldDeviceId,
        string $newDeviceId,
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_SUSPICIOUS_DEVICE_CHANGE,
            severity: SecurityEvent::SEVERITY_MEDIUM,
            user: $user,
            message: "Pergantian perangkat mencurigakan dari {$user->name}",
            context: array_merge([
                'old_device_id' => $oldDeviceId,
                'new_device_id' => $newDeviceId,
            ], $context),
            deviceId: $newDeviceId
        );
    }

    /**
     * Log impossible travel (location jump)
     */
    public function logImpossibleTravel(
        User $user,
        float $distance,
        int $timeMinutes,
        array $fromLocation = [],
        array $toLocation = [],
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: SecurityEvent::EVENT_IMPOSSIBLE_TRAVEL,
            severity: SecurityEvent::SEVERITY_HIGH,
            user: $user,
            message: "Perjalanan tidak mungkin terdeteksi dari {$user->name}: {$distance}m dalam {$timeMinutes} menit",
            context: array_merge([
                'distance_meters' => round($distance, 2),
                'time_minutes' => $timeMinutes,
                'from_location' => $fromLocation,
                'to_location' => $toLocation,
            ], $context),
            latitude: $toLocation['lat'] ?? null,
            longitude: $toLocation['lng'] ?? null
        );
    }

    /**
     * Log rate limit exceeded
     */
    public function logRateLimitExceeded(
        ?User $user,
        string $endpoint,
        int $limit,
        array $context = []
    ): SecurityEvent {
        $userName = $user ? $user->name : 'Anonymous';
        
        return $this->log(
            eventType: SecurityEvent::EVENT_RATE_LIMIT_EXCEEDED,
            severity: SecurityEvent::SEVERITY_MEDIUM,
            user: $user,
            message: "Rate limit terlampaui oleh {$userName} pada endpoint {$endpoint}",
            context: array_merge([
                'endpoint' => $endpoint,
                'limit' => $limit,
            ], $context)
        );
    }

    /**
     * Log custom security event
     */
    public function logCustom(
        string $eventType,
        string $severity,
        ?User $user,
        string $message,
        array $context = []
    ): SecurityEvent {
        return $this->log(
            eventType: $eventType,
            severity: $severity,
            user: $user,
            message: $message,
            context: $context
        );
    }

    /**
     * Core logging method
     */
    protected function log(
        string $eventType,
        string $severity,
        ?User $user,
        string $message,
        array $context = [],
        ?string $deviceId = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): SecurityEvent {
        try {
            // Get request metadata
            $request = Request::instance();
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
            $requestDeviceId = $deviceId ?? $request->input('device_id');

            // Create the security event
            $event = SecurityEvent::create([
                'school_id' => $user?->school_id,
                'user_id' => $user?->id,
                'user_type' => $user?->role_type,
                'event_type' => $eventType,
                'severity' => $severity,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'device_id' => $requestDeviceId,
                'latitude' => $latitude ?? $request->input('lat'),
                'longitude' => $longitude ?? $request->input('lng'),
                'context' => $context,
                'message' => $message,
            ]);

            // Also log to the security channel for immediate visibility
            $logLevel = match ($severity) {
                SecurityEvent::SEVERITY_LOW => 'info',
                SecurityEvent::SEVERITY_MEDIUM => 'warning',
                SecurityEvent::SEVERITY_HIGH => 'warning',
                SecurityEvent::SEVERITY_CRITICAL => 'critical',
                default => 'info',
            };

            Log::channel('security')->log($logLevel, $message, [
                'event_id' => $event->id,
                'event_type' => $eventType,
                'user_id' => $user?->id,
                'school_id' => $user?->school_id,
                'ip' => $ipAddress,
                'context' => $context,
            ]);

            return $event;
        } catch (\Exception $e) {
            // If database logging fails, ensure we still log to file
            Log::channel('security')->error("Failed to log security event to database: {$e->getMessage()}", [
                'event_type' => $eventType,
                'user_id' => $user?->id,
                'original_message' => $message,
                'context' => $context,
            ]);

            // Return a non-persisted event object
            return new SecurityEvent([
                'event_type' => $eventType,
                'severity' => $severity,
                'message' => $message,
                'context' => $context,
            ]);
        }
    }

    /**
     * Get recent events summary for dashboard
     */
    public function getRecentSummary(int $schoolId, int $hours = 24): array
    {
        $events = SecurityEvent::where('school_id', $schoolId)
            ->where('created_at', '>=', now()->subHours($hours))
            ->get();

        return [
            'total' => $events->count(),
            'unresolved' => $events->where('is_resolved', false)->count(),
            'by_severity' => [
                'critical' => $events->where('severity', SecurityEvent::SEVERITY_CRITICAL)->count(),
                'high' => $events->where('severity', SecurityEvent::SEVERITY_HIGH)->count(),
                'medium' => $events->where('severity', SecurityEvent::SEVERITY_MEDIUM)->count(),
                'low' => $events->where('severity', SecurityEvent::SEVERITY_LOW)->count(),
            ],
            'by_type' => $events->groupBy('event_type')->map->count()->toArray(),
        ];
    }

    /**
     * Get user's security events
     */
    public function getUserEvents(int $userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return SecurityEvent::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Resolve multiple events
     */
    public function bulkResolve(array $eventIds, int $resolverId, ?string $notes = null): int
    {
        return SecurityEvent::whereIn('id', $eventIds)
            ->where('is_resolved', false)
            ->update([
                'is_resolved' => true,
                'resolved_by' => $resolverId,
                'resolved_at' => now(),
                'resolution_notes' => $notes,
            ]);
    }
}
