<?php

namespace App\Services\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Attendance;

/**
 * AttendanceLogger - Structured Logging for Attendance Events
 *
 * Provides consistent, structured logging for all attendance-related events.
 * Logs are output in JSON format for easy parsing by log aggregation tools
 * like ELK Stack, Datadog, or CloudWatch.
 *
 * LOG EVENTS:
 * - check_in.success       - Successful attendance check-in
 * - check_in.failed        - Failed validation during check-in
 * - check_in.duplicate     - Duplicate attendance attempt
 * - check_in.expired_qr    - Expired QR code used
 * - check_in.invalid_qr    - Invalid/tampered QR code
 * - check_in.late          - Late check-in (after threshold)
 * - check_out.success      - Successful check-out
 * - manual.created         - Manual attendance entry created
 * - manual.updated         - Attendance record updated
 * - security.anomaly       - Security anomaly detected
 *
 * USAGE:
 * app(AttendanceLogger::class)->checkInSuccess($attendance, $request);
 */
class AttendanceLogger
{
    /**
     * Log channel for attendance events
     */
    protected const CHANNEL = 'attendance_json';

    /**
     * Log successful check-in
     */
    public function checkInSuccess(
        Attendance $attendance,
        Request $request,
        array $extra = []
    ): void {
        $this->log('info', 'check_in.success', [
            'message' => 'Attendance check-in successful',
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'student_name' => $attendance->student?->name,
            'schedule_id' => $attendance->schedule_id,
            'school_id' => $attendance->school_id,
            'status' => $attendance->status,
            'check_in_time' => $attendance->check_in_time?->toTimeString(),
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'is_late' => $attendance->status === 'late',
            'scan_method' => $extra['scan_method'] ?? 'qr_scan',
            'location' => $this->extractLocation($request, $extra),
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$extra,
        ]);
    }

    /**
     * Log failed validation during check-in
     */
    public function checkInFailed(
        Request $request,
        string $reason,
        array $context = []
    ): void {
        $user = $request->user();

        $this->log('warning', 'check_in.failed', [
            'message' => 'Attendance check-in failed',
            'reason' => $reason,
            'student_id' => $user?->id,
            'student_name' => $user?->name,
            'school_id' => $user?->school_id,
            'schedule_id' => $context['schedule_id'] ?? null,
            'validation_errors' => $context['errors'] ?? null,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$context,
        ]);
    }

    /**
     * Log duplicate attendance attempt
     */
    public function checkInDuplicate(
        Request $request,
        int $existingAttendanceId,
        array $context = []
    ): void {
        $user = $request->user();

        $this->log('warning', 'check_in.duplicate', [
            'message' => 'Duplicate attendance attempt blocked',
            'student_id' => $user?->id,
            'student_name' => $user?->name,
            'school_id' => $user?->school_id,
            'existing_attendance_id' => $existingAttendanceId,
            'schedule_id' => $context['schedule_id'] ?? null,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$context,
        ]);
    }

    /**
     * Log expired QR code usage
     */
    public function expiredQr(
        Request $request,
        array $qrData,
        array $context = []
    ): void {
        $user = $request->user();

        $this->log('warning', 'check_in.expired_qr', [
            'message' => 'Expired QR code presented',
            'student_id' => $user?->id,
            'student_name' => $user?->name,
            'school_id' => $user?->school_id,
            'schedule_id' => $qrData['schedule_id'] ?? null,
            'qr_expired_at' => $qrData['expires_at'] ?? null,
            'seconds_expired' => $qrData['seconds_expired'] ?? null,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$context,
        ]);
    }

    /**
     * Log invalid/tampered QR code
     */
    public function invalidQr(
        Request $request,
        string $reason,
        array $context = []
    ): void {
        $user = $request->user();

        // This is a security event - also log to security channel
        $this->log('error', 'check_in.invalid_qr', [
            'message' => 'Invalid QR code presented',
            'reason' => $reason,
            'student_id' => $user?->id,
            'student_name' => $user?->name,
            'school_id' => $user?->school_id,
            'is_security_event' => true,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$context,
        ], 'security_json');
    }

    /**
     * Log late check-in
     */
    public function lateCheckIn(
        Attendance $attendance,
        Request $request,
        int $minutesLate,
        array $context = []
    ): void {
        $this->log('info', 'check_in.late', [
            'message' => 'Late attendance recorded',
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'student_name' => $attendance->student?->name,
            'school_id' => $attendance->school_id,
            'schedule_id' => $attendance->schedule_id,
            'minutes_late' => $minutesLate,
            'check_in_time' => $attendance->check_in_time?->toTimeString(),
            'scheduled_start' => $context['scheduled_start'] ?? null,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$context,
        ]);
    }

    /**
     * Log successful check-out
     */
    public function checkOutSuccess(
        Attendance $attendance,
        Request $request,
        array $extra = []
    ): void {
        $this->log('info', 'check_out.success', [
            'message' => 'Attendance check-out successful',
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'student_name' => $attendance->student?->name,
            'school_id' => $attendance->school_id,
            'schedule_id' => $attendance->schedule_id,
            'check_in_time' => $attendance->check_in_time?->toTimeString(),
            'check_out_time' => $attendance->check_out_time?->toTimeString(),
            'duration_minutes' => $extra['duration_minutes'] ?? null,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$extra,
        ]);
    }

    /**
     * Log manual attendance creation
     */
    public function manualCreated(
        Attendance $attendance,
        User $recorder,
        Request $request,
        string $reason = ''
    ): void {
        $this->log('info', 'manual.created', [
            'message' => 'Manual attendance entry created',
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'student_name' => $attendance->student?->name,
            'school_id' => $attendance->school_id,
            'schedule_id' => $attendance->schedule_id,
            'status' => $attendance->status,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'recorded_by_id' => $recorder->id,
            'recorded_by_name' => $recorder->name,
            'recorded_by_role' => $recorder->role_type,
            'reason' => $reason,
            'request' => $this->extractRequestInfo($request),
        ]);
    }

    /**
     * Log attendance update
     */
    public function updated(
        Attendance $attendance,
        User $updater,
        array $changes,
        Request $request
    ): void {
        $this->log('info', 'manual.updated', [
            'message' => 'Attendance record updated',
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'school_id' => $attendance->school_id,
            'changes' => $changes,
            'updated_by_id' => $updater->id,
            'updated_by_name' => $updater->name,
            'updated_by_role' => $updater->role_type,
            'request' => $this->extractRequestInfo($request),
        ]);
    }

    /**
     * Log security anomaly
     */
    public function securityAnomaly(
        Request $request,
        string $anomalyType,
        string $description,
        array $context = []
    ): void {
        $user = $request->user();

        $this->log('error', 'security.anomaly', [
            'message' => 'Security anomaly detected',
            'anomaly_type' => $anomalyType,
            'description' => $description,
            'student_id' => $user?->id,
            'student_name' => $user?->name,
            'school_id' => $user?->school_id,
            'is_security_event' => true,
            'severity' => $context['severity'] ?? 'high',
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
            ...$context,
        ], 'security_json');
    }

    /**
     * Log rate limit violation
     */
    public function rateLimitExceeded(
        Request $request,
        int $violationCount,
        bool $isBlocked = false
    ): void {
        $user = $request->user();

        $this->log('warning', 'security.rate_limit', [
            'message' => $isBlocked ? 'IP blocked for rate limit violations' : 'Rate limit exceeded',
            'student_id' => $user?->id,
            'school_id' => $user?->school_id,
            'violation_count' => $violationCount,
            'is_blocked' => $isBlocked,
            'device' => $this->extractDeviceInfo($request),
            'request' => $this->extractRequestInfo($request),
        ], 'security_json');
    }

    /**
     * Extract location data from request
     */
    protected function extractLocation(Request $request, array $extra = []): array
    {
        return [
            'latitude' => $request->input('latitude') ?? $extra['latitude'] ?? null,
            'longitude' => $request->input('longitude') ?? $extra['longitude'] ?? null,
            'accuracy' => $request->input('accuracy') ?? $extra['accuracy'] ?? null,
        ];
    }

    /**
     * Extract device information from request
     */
    protected function extractDeviceInfo(Request $request): array
    {
        return [
            'device_id' => $request->header('X-Device-ID')
                ?? $request->input('device_info.device_id')
                ?? $request->input('device_id'),
            'platform' => $request->header('X-Platform')
                ?? $this->detectPlatform($request->userAgent()),
            'app_version' => $request->header('X-App-Version'),
            'user_agent' => substr($request->userAgent() ?? '', 0, 200),
        ];
    }

    /**
     * Extract request metadata
     */
    protected function extractRequestInfo(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'request_id' => $request->header('X-Request-ID')
                ?? $request->input('request_id'),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'timestamp' => now()->toIso8601String(),
            'timestamp_unix' => now()->timestamp,
        ];
    }

    /**
     * Detect platform from user agent
     */
    protected function detectPlatform(?string $userAgent): string
    {
        if (!$userAgent) return 'unknown';

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'android')) return 'android';
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) return 'ios';
        if (str_contains($ua, 'windows')) return 'windows';
        if (str_contains($ua, 'macintosh')) return 'macos';
        if (str_contains($ua, 'linux')) return 'linux';

        return 'unknown';
    }

    /**
     * Write log entry
     */
    protected function log(
        string $level,
        string $event,
        array $context,
        string $channel = self::CHANNEL
    ): void {
        // Add standard fields
        $context = array_merge([
            'event' => $event,
            'service' => 'attendance',
            'environment' => app()->environment(),
        ], $context);

        // Log to specified channel
        Log::channel($channel)->{$level}($context['message'] ?? $event, $context);
    }
}
