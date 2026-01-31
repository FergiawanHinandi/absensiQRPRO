<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceFlag;
use App\Models\SecurityAlert;
use App\Models\TeacherAttendanceAnomaly;
use App\Models\TeacherDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin Security Alerts Controller
 * 
 * Aggregates security-related alerts from multiple sources:
 * - Suspicious attendance patterns (AttendanceFlag)
 * - Location violations (impossible travel, geofence)
 * - Device violations (new devices, revoked devices, device churn)
 * - Teacher attendance anomalies
 */
class AdminSecurityAlertController extends Controller
{
    /**
     * GET /api/v1/admin/security-alerts
     * 
     * Returns aggregated security alerts for the school admin
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $limit = min($request->input('limit', 20), 100);
        $type = $request->input('type'); // Optional filter: 'attendance', 'location', 'device'
        $severity = $request->input('severity'); // Optional: 'low', 'medium', 'high', 'critical'
        $resolved = $request->has('resolved') ? filter_var($request->input('resolved'), FILTER_VALIDATE_BOOLEAN) : null;

        $alerts = [];

        // =========================================================
        // 1. SUSPICIOUS ATTENDANCE (AttendanceFlag)
        // =========================================================
        if (!$type || $type === 'attendance' || $type === 'location') {
            $attendanceFlags = AttendanceFlag::where('school_id', $schoolId)
                ->with(['student:id,name,email', 'attendance:id,attendance_date,status,check_in_time'])
                ->when($severity, fn($q) => $q->where('severity', $severity))
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            foreach ($attendanceFlags as $flag) {
                $alerts[] = [
                    'id' => 'af_' . $flag->id,
                    'source' => 'attendance_flag',
                    'type' => $this->mapFlagType($flag->flag_type),
                    'category' => $this->categorizeFlag($flag->flag_type),
                    'severity' => $flag->severity,
                    'title' => $this->getFlagTitle($flag->flag_type),
                    'description' => $this->getFlagDescription($flag),
                    'actor' => $flag->student ? [
                        'id' => $flag->student->id,
                        'name' => $flag->student->name,
                        'type' => 'student',
                    ] : null,
                    'details' => $flag->details,
                    'device_id' => $flag->device_id ? substr($flag->device_id, 0, 8) . '...' : null,
                    'attendance_id' => $flag->attendance_id,
                    'is_resolved' => false, // AttendanceFlag doesn't have resolved status yet
                    'created_at' => $flag->created_at->toIso8601String(),
                ];
            }
        }

        // =========================================================
        // 2. TEACHER ATTENDANCE ANOMALIES
        // =========================================================
        if (!$type || $type === 'attendance' || $type === 'teacher') {
            $teacherAnomalies = TeacherAttendanceAnomaly::where('school_id', $schoolId)
                ->with(['teacher:id,name,email'])
                ->when($severity, fn($q) => $q->where('severity', $severity))
                ->when($resolved !== null, fn($q) => $q->where('is_reviewed', $resolved))
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            foreach ($teacherAnomalies as $anomaly) {
                $alerts[] = [
                    'id' => 'ta_' . $anomaly->id,
                    'source' => 'teacher_anomaly',
                    'type' => $anomaly->anomaly_type,
                    'category' => $this->categorizeTeacherAnomaly($anomaly->anomaly_type),
                    'severity' => $anomaly->severity,
                    'title' => $this->getTeacherAnomalyTitle($anomaly->anomaly_type),
                    'description' => $this->getTeacherAnomalyDescription($anomaly),
                    'actor' => $anomaly->teacher ? [
                        'id' => $anomaly->teacher->id,
                        'name' => $anomaly->teacher->name,
                        'type' => 'teacher',
                    ] : null,
                    'details' => $anomaly->details,
                    'device_id' => $anomaly->device_id ? substr($anomaly->device_id, 0, 8) . '...' : null,
                    'location' => $anomaly->latitude && $anomaly->longitude ? [
                        'lat' => (float) $anomaly->latitude,
                        'lng' => (float) $anomaly->longitude,
                    ] : null,
                    'is_resolved' => $anomaly->is_reviewed,
                    'resolved_at' => $anomaly->reviewed_at?->toIso8601String(),
                    'resolved_by' => $anomaly->reviewed_by,
                    'created_at' => $anomaly->created_at->toIso8601String(),
                ];
            }
        }

        // =========================================================
        // 3. DEVICE VIOLATIONS (Pending/Revoked Teacher Devices)
        // =========================================================
        if (!$type || $type === 'device') {
            // Pending devices (new device awaiting approval)
            $pendingDevices = TeacherDevice::where('school_id', $schoolId)
                ->pending()
                ->with(['teacher:id,name,email'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            foreach ($pendingDevices as $device) {
                $alerts[] = [
                    'id' => 'td_' . $device->id,
                    'source' => 'teacher_device',
                    'type' => 'new_device_pending',
                    'category' => 'device',
                    'severity' => 'medium',
                    'title' => 'Perangkat Baru Menunggu Persetujuan',
                    'description' => "Guru {$device->teacher->name} mencoba login dari perangkat baru: {$device->device_name}",
                    'actor' => $device->teacher ? [
                        'id' => $device->teacher->id,
                        'name' => $device->teacher->name,
                        'type' => 'teacher',
                    ] : null,
                    'details' => [
                        'device_name' => $device->device_name,
                        'device_model' => $device->device_model,
                        'platform' => $device->platform,
                        'os_version' => $device->os_version,
                        'app_version' => $device->app_version,
                    ],
                    'device_id' => $device->device_id ? substr($device->device_id, 0, 8) . '...' : null,
                    'is_resolved' => false,
                    'action_required' => 'approve_or_reject',
                    'action_url' => "/admin/teacher-devices/{$device->id}/approve",
                    'created_at' => $device->created_at->toIso8601String(),
                ];
            }

            // Recently revoked devices (last 7 days)
            $revokedDevices = TeacherDevice::where('school_id', $schoolId)
                ->revoked()
                ->where('revoked_at', '>=', now()->subDays(7))
                ->with(['teacher:id,name,email', 'revoker:id,name'])
                ->orderBy('revoked_at', 'desc')
                ->limit($limit)
                ->get();

            foreach ($revokedDevices as $device) {
                $alerts[] = [
                    'id' => 'td_rev_' . $device->id,
                    'source' => 'teacher_device',
                    'type' => 'device_revoked',
                    'category' => 'device',
                    'severity' => 'high',
                    'title' => 'Akses Perangkat Dicabut',
                    'description' => "Akses perangkat {$device->device_name} untuk {$device->teacher->name} telah dicabut",
                    'actor' => $device->teacher ? [
                        'id' => $device->teacher->id,
                        'name' => $device->teacher->name,
                        'type' => 'teacher',
                    ] : null,
                    'details' => [
                        'device_name' => $device->device_name,
                        'revoke_reason' => $device->revoke_reason,
                        'revoked_by' => $device->revoker?->name,
                    ],
                    'device_id' => $device->device_id ? substr($device->device_id, 0, 8) . '...' : null,
                    'is_resolved' => true,
                    'resolved_at' => $device->revoked_at?->toIso8601String(),
                    'created_at' => $device->revoked_at?->toIso8601String(),
                ];
            }
        }

        // =========================================================
        // 4. GENERAL SECURITY ALERTS (SecurityAlert model)
        // =========================================================
        if (!$type || $type === 'system' || $type === 'security') {
            $securityAlerts = SecurityAlert::query()
                ->forSchool($schoolId)
                ->with(['relatedUser:id,name,email,role_type'])
                ->when($severity, fn($q) => $q->severity($severity))
                ->when($resolved === true, fn($q) => $q->resolved())
                ->when($resolved === false, fn($q) => $q->unresolved())
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            foreach ($securityAlerts as $alert) {
                $alerts[] = [
                    'id' => 'sa_' . $alert->id,
                    'source' => 'security_alert',
                    'type' => $alert->event_type,
                    'category' => $this->categorizeSecurityAlert($alert->event_type),
                    'severity' => $alert->severity,
                    'title' => $alert->getEventTitle(),
                    'description' => $alert->description,
                    'actor' => $alert->relatedUser ? [
                        'id' => $alert->relatedUser->id,
                        'name' => $alert->relatedUser->name,
                        'type' => $alert->relatedUser->role_type,
                    ] : null,
                    'details' => $alert->details,
                    'ip_address' => $alert->ip_address,
                    'device_id' => $alert->device_id ? substr($alert->device_id, 0, 8) . '...' : null,
                    'is_resolved' => $alert->is_resolved,
                    'resolved_at' => $alert->resolved_at?->toIso8601String(),
                    'resolved_by' => $alert->resolver?->name,
                    'notification_sent' => $alert->notification_sent,
                    'notification_sent_at' => $alert->notification_sent_at?->toIso8601String(),
                    'created_at' => $alert->created_at->toIso8601String(),
                ];
            }
        }

        // Sort all alerts by created_at desc
        usort($alerts, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        // Slice to limit
        $alerts = array_slice($alerts, 0, $limit);

        // Calculate summary counts
        $summary = $this->calculateSummary($schoolId);

        return response()->json([
            'success' => true,
            'data' => [
                'alerts' => $alerts,
                'summary' => $summary,
                'filters' => [
                    'type' => $type,
                    'severity' => $severity,
                    'resolved' => $resolved,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    /**
     * Calculate summary counts for dashboard
     */
    private function calculateSummary(int $schoolId): array
    {
        return [
            'total_unresolved' => 
                AttendanceFlag::where('school_id', $schoolId)->count() +
                TeacherAttendanceAnomaly::where('school_id', $schoolId)->where('is_reviewed', false)->count() +
                TeacherDevice::where('school_id', $schoolId)->pending()->count(),
            'by_category' => [
                'attendance' => AttendanceFlag::where('school_id', $schoolId)
                    ->whereIn('flag_type', ['impossible_travel', 'suspicious_pattern', 'rapid_scans'])
                    ->count(),
                'location' => AttendanceFlag::where('school_id', $schoolId)
                    ->whereIn('flag_type', ['impossible_travel', 'geofence_violation', 'accuracy_anomaly'])
                    ->count(),
                'device' => TeacherDevice::where('school_id', $schoolId)->pending()->count() +
                    AttendanceFlag::where('school_id', $schoolId)
                    ->whereIn('flag_type', ['device_churn', 'new_device'])
                    ->count(),
            ],
            'by_severity' => [
                'critical' => AttendanceFlag::where('school_id', $schoolId)->where('severity', 'critical')->count() +
                    TeacherAttendanceAnomaly::where('school_id', $schoolId)->where('severity', 'critical')->count(),
                'high' => AttendanceFlag::where('school_id', $schoolId)->where('severity', 'high')->count() +
                    TeacherAttendanceAnomaly::where('school_id', $schoolId)->where('severity', 'high')->count(),
                'medium' => AttendanceFlag::where('school_id', $schoolId)->where('severity', 'medium')->count() +
                    TeacherDevice::where('school_id', $schoolId)->pending()->count(),
                'low' => AttendanceFlag::where('school_id', $schoolId)->where('severity', 'low')->count(),
            ],
            'pending_device_approvals' => TeacherDevice::where('school_id', $schoolId)->pending()->count(),
        ];
    }

    /**
     * Map flag_type to human-readable type
     */
    private function mapFlagType(string $flagType): string
    {
        return match ($flagType) {
            'impossible_travel' => 'impossible_travel',
            'accuracy_anomaly' => 'location_accuracy',
            'device_churn' => 'device_change',
            'geofence_violation' => 'outside_geofence',
            'rapid_scans' => 'rapid_scan_pattern',
            default => $flagType,
        };
    }

    /**
     * Categorize flag type
     */
    private function categorizeFlag(string $flagType): string
    {
        return match ($flagType) {
            'impossible_travel', 'geofence_violation', 'accuracy_anomaly' => 'location',
            'device_churn', 'new_device' => 'device',
            default => 'attendance',
        };
    }

    /**
     * Get human-readable title for flag type
     */
    private function getFlagTitle(string $flagType): string
    {
        return match ($flagType) {
            'impossible_travel' => 'Perjalanan Tidak Mungkin',
            'accuracy_anomaly' => 'Anomali Akurasi Lokasi',
            'device_churn' => 'Pergantian Perangkat Mencurigakan',
            'geofence_violation' => 'Di Luar Area Sekolah',
            'rapid_scans' => 'Pola Scan Cepat Berulang',
            'new_device' => 'Perangkat Baru Terdeteksi',
            default => 'Aktivitas Mencurigakan',
        };
    }

    /**
     * Get description for attendance flag
     */
    private function getFlagDescription(AttendanceFlag $flag): string
    {
        $studentName = $flag->student?->name ?? 'Siswa';
        
        return match ($flag->flag_type) {
            'impossible_travel' => "{$studentName} terdeteksi melakukan absensi dari lokasi yang tidak mungkin dijangkau dalam waktu singkat",
            'accuracy_anomaly' => "{$studentName} memiliki akurasi GPS yang tidak konsisten atau mencurigakan",
            'device_churn' => "{$studentName} sering berganti perangkat dalam waktu singkat",
            'geofence_violation' => "{$studentName} melakukan absensi dari luar area sekolah",
            'rapid_scans' => "{$studentName} melakukan scan berulang dalam waktu sangat singkat",
            default => "Aktivitas mencurigakan terdeteksi untuk {$studentName}",
        };
    }

    /**
     * Categorize teacher anomaly
     */
    private function categorizeTeacherAnomaly(string $anomalyType): string
    {
        return match ($anomalyType) {
            'location_mismatch', 'geofence_violation' => 'location',
            'device_mismatch', 'new_device' => 'device',
            default => 'attendance',
        };
    }

    /**
     * Get title for teacher anomaly
     */
    private function getTeacherAnomalyTitle(string $anomalyType): string
    {
        return match ($anomalyType) {
            'location_mismatch' => 'Ketidaksesuaian Lokasi Guru',
            'geofence_violation' => 'Guru Di Luar Area Sekolah',
            'device_mismatch' => 'Perangkat Guru Tidak Cocok',
            'time_anomaly' => 'Waktu Absensi Tidak Wajar',
            default => 'Anomali Absensi Guru',
        };
    }

    /**
     * Get description for teacher anomaly
     */
    private function getTeacherAnomalyDescription(TeacherAttendanceAnomaly $anomaly): string
    {
        $teacherName = $anomaly->teacher?->name ?? 'Guru';
        
        return match ($anomaly->anomaly_type) {
            'location_mismatch' => "{$teacherName} melakukan absensi dari lokasi yang berbeda dengan biasanya",
            'geofence_violation' => "{$teacherName} melakukan absensi dari luar area sekolah",
            'device_mismatch' => "{$teacherName} menggunakan perangkat yang berbeda dari yang terdaftar",
            'time_anomaly' => "{$teacherName} melakukan absensi pada waktu yang tidak wajar",
            default => "Anomali terdeteksi pada absensi {$teacherName}",
        };
    }

    /**
     * Mark an alert as resolved
     * 
     * POST /api/v1/admin/security-alerts/{id}/resolve
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notes = $request->input('notes');

        // Parse the composite ID (e.g., 'af_123', 'ta_456')
        [$prefix, $actualId] = explode('_', $id, 2);

        $resolved = match ($prefix) {
            'ta' => $this->resolveTeacherAnomaly((int) $actualId, $user->id, $notes),
            'sa' => $this->resolveSecurityAlert((int) $actualId, $user->id),
            default => false,
        };

        if (!$resolved) {
            return response()->json([
                'success' => false,
                'message' => 'Alert tidak dapat di-resolve atau tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Alert berhasil di-resolve.',
        ]);
    }

    private function resolveTeacherAnomaly(int $id, int $userId, ?string $notes): bool
    {
        $anomaly = TeacherAttendanceAnomaly::find($id);
        if (!$anomaly) return false;

        $anomaly->update([
            'is_reviewed' => true,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return true;
    }

    private function resolveSecurityAlert(int $id, int $userId): bool
    {
        $alert = SecurityAlert::find($id);
        if (!$alert) return false;

        $alert->markAsResolved($userId);

        return true;
    }

    /**
     * Categorize security alert event type
     */
    private function categorizeSecurityAlert(string $eventType): string
    {
        return match ($eventType) {
            SecurityAlert::TYPE_GEOFENCE_VIOLATION,
            SecurityAlert::TYPE_IMPOSSIBLE_TRAVEL => 'location',
            SecurityAlert::TYPE_UNAPPROVED_DEVICE => 'device',
            SecurityAlert::TYPE_QR_REPLAY,
            SecurityAlert::TYPE_UNAUTHORIZED_SCHEDULE => 'attendance',
            SecurityAlert::TYPE_FAILED_ATTEMPT_SPIKE,
            SecurityAlert::TYPE_RACE_CONDITION_BLOCKED => 'system',
            SecurityAlert::TYPE_BEHAVIOR_ANOMALY => 'behavior',
            default => 'security',
        };
    }
}
