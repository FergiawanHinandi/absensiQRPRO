<?php

namespace App\Services;

use App\Models\AdminActivityLog;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Admin Audit Service
 *
 * Centralizes all admin action logging for the multi-tenant attendance platform.
 * Provides dual-write capability: writes to both admin_activity_logs table and
 * the immutable security log chain for tamper-proof audit trails.
 *
 * Usage:
 *   $auditService->log('teacher_device_reset', 'Teacher', $teacherId, 'Reset device for Teacher X');
 */
class AdminAuditService
{
    protected ?ImmutableSecurityLogService $immutableLogService = null;

    protected ?SecurityAlertService $securityAlertService = null;

    /**
     * Get the immutable log service (lazy loaded)
     */
    protected function getImmutableLogService(): ImmutableSecurityLogService
    {
        if ($this->immutableLogService === null) {
            $this->immutableLogService = app(ImmutableSecurityLogService::class);
        }

        return $this->immutableLogService;
    }

    /**
     * Get the security alert service (lazy loaded)
     */
    protected function getSecurityAlertService(): SecurityAlertService
    {
        if ($this->securityAlertService === null) {
            $this->securityAlertService = app(SecurityAlertService::class);
        }

        return $this->securityAlertService;
    }

    /**
     * Log an admin action.
     *
     * This method:
     * 1. Creates an AdminActivityLog record
     * 2. Writes to the immutable security log chain
     * 3. Triggers alerts for high-risk actions
     *
     * @param  string  $actionType  One of AdminActivityLog::ACTION_* constants
     * @param  string|null  $targetType  Entity type being acted upon (e.g., 'Teacher', 'Student')
     * @param  int|null  $targetId  Entity ID being acted upon
     * @param  string  $description  Human-readable description
     * @param  array  $metadata  Additional structured data about the action
     * @param  User|null  $admin  Override the admin user (defaults to authenticated user)
     * @return AdminActivityLog|null The created log entry, or null if logging fails
     */
    public function log(
        string $actionType,
        ?string $targetType = null,
        ?int $targetId = null,
        string $description = '',
        array $metadata = [],
        ?User $admin = null
    ): ?AdminActivityLog {
        $admin = $admin ?? Auth::user();

        if (! $admin) {
            Log::channel('security')->warning('AdminAuditService::log called without authenticated user', [
                'action_type' => $actionType,
                'description' => $description,
            ]);

            return null;
        }

        try {
            // Create the activity log record
            $activityLog = AdminActivityLog::create([
                'admin_user_id' => $admin->id,
                'role' => $admin->role_type ?? 'unknown',
                'school_id' => $this->resolveSchoolId($admin),
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'description' => $description ?: $this->generateDescription($actionType, $targetType, $targetId),
                'metadata' => $metadata,
                'ip_address' => Request::ip(),
                'user_agent' => $this->truncateUserAgent(Request::userAgent()),
                'route_name' => Request::route()?->getName(),
                'http_method' => Request::method(),
            ]);

            // Log to the immutable security chain as well
            $this->writeToImmutableLog($activityLog, $admin, $metadata);

            // Check if this is a high-risk action that needs alerting
            if ($activityLog->isHighRisk()) {
                $this->triggerHighRiskAlert($activityLog, $admin, $metadata);
            }

            Log::channel('security')->info('Admin action logged', [
                'log_id' => $activityLog->id,
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'action_type' => $actionType,
                'target' => "{$targetType}#{$targetId}",
                'high_risk' => $activityLog->isHighRisk(),
            ]);

            return $activityLog;

        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to log admin action', [
                'action_type' => $actionType,
                'admin_id' => $admin->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Log an admin login action.
     */
    public function logLogin(User $admin, array $metadata = []): ?AdminActivityLog
    {
        return $this->log(
            AdminActivityLog::ACTION_LOGIN,
            'User',
            $admin->id,
            "Admin logged in: {$admin->email}",
            $metadata,
            $admin
        );
    }

    /**
     * Log an admin logout action.
     */
    public function logLogout(User $admin, array $metadata = []): ?AdminActivityLog
    {
        return $this->log(
            AdminActivityLog::ACTION_LOGOUT,
            'User',
            $admin->id,
            "Admin logged out: {$admin->email}",
            $metadata,
            $admin
        );
    }

    /**
     * Log a failed login attempt.
     */
    public function logLoginFailed(string $email, array $metadata = []): void
    {
        // Create a minimal log without an authenticated user
        try {
            AdminActivityLog::create([
                'admin_user_id' => $this->findUserIdByEmail($email) ?? 0,
                'role' => 'unknown',
                'school_id' => null,
                'action_type' => AdminActivityLog::ACTION_LOGIN_FAILED,
                'target_type' => null,
                'target_id' => null,
                'description' => "Failed login attempt for: {$email}",
                'metadata' => array_merge($metadata, ['attempted_email' => $email]),
                'ip_address' => Request::ip(),
                'user_agent' => $this->truncateUserAgent(Request::userAgent()),
                'route_name' => Request::route()?->getName(),
                'http_method' => Request::method(),
            ]);
        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to log login failure', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log a teacher device reset action (high-risk).
     */
    public function logDeviceReset(
        int $teacherId,
        string $teacherName,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_TEACHER_DEVICE_RESET,
            'Teacher',
            $teacherId,
            "Device reset performed for teacher: {$teacherName}",
            array_merge($metadata, ['teacher_name' => $teacherName])
        );
    }

    /**
     * Log an attendance override action (high-risk).
     */
    public function logAttendanceOverride(
        int $attendanceId,
        int $studentId,
        string $oldStatus,
        string $newStatus,
        string $reason,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_ATTENDANCE_OVERRIDE,
            'Attendance',
            $attendanceId,
            "Attendance override: {$oldStatus} → {$newStatus}. Reason: {$reason}",
            array_merge($metadata, [
                'student_id' => $studentId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
            ])
        );
    }

    /**
     * Log a geofence configuration change (high-risk).
     */
    public function logGeofenceChange(
        int $schoolId,
        array $oldConfig,
        array $newConfig,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_GEOFENCE_UPDATE,
            'School',
            $schoolId,
            "Geofence configuration updated for school #{$schoolId}",
            array_merge($metadata, [
                'old_config' => $oldConfig,
                'new_config' => $newConfig,
            ])
        );
    }

    /**
     * Log user creation.
     */
    public function logUserCreate(
        int $newUserId,
        string $userEmail,
        string $roleType,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_USER_CREATE,
            'User',
            $newUserId,
            "Created {$roleType} user: {$userEmail}",
            array_merge($metadata, [
                'new_user_email' => $userEmail,
                'new_user_role' => $roleType,
            ])
        );
    }

    /**
     * Log user deletion (high-risk).
     */
    public function logUserDelete(
        int $userId,
        string $userEmail,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_USER_DELETE,
            'User',
            $userId,
            "Deleted user: {$userEmail}",
            array_merge($metadata, ['deleted_user_email' => $userEmail])
        );
    }

    /**
     * Log permission change (high-risk).
     */
    public function logPermissionChange(
        int $targetUserId,
        string $targetUserEmail,
        string $oldRole,
        string $newRole,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_PERMISSION_CHANGE,
            'User',
            $targetUserId,
            "Permission changed for {$targetUserEmail}: {$oldRole} → {$newRole}",
            array_merge($metadata, [
                'old_role' => $oldRole,
                'new_role' => $newRole,
            ])
        );
    }

    /**
     * Log backup creation.
     */
    public function logBackupCreate(
        string $backupName,
        int $sizeBytes,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_BACKUP_CREATE,
            'System',
            null,
            "System backup created: {$backupName}",
            array_merge($metadata, [
                'backup_name' => $backupName,
                'size_bytes' => $sizeBytes,
            ])
        );
    }

    /**
     * Log backup restore (high-risk).
     */
    public function logBackupRestore(
        string $backupName,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_BACKUP_RESTORE,
            'System',
            null,
            "System backup restored: {$backupName}",
            array_merge($metadata, ['backup_name' => $backupName])
        );
    }

    /**
     * Log maintenance mode toggle.
     */
    public function logMaintenanceToggle(
        bool $enabled,
        array $metadata = []
    ): ?AdminActivityLog {
        $action = $enabled
            ? AdminActivityLog::ACTION_MAINTENANCE_ENABLE
            : AdminActivityLog::ACTION_MAINTENANCE_DISABLE;

        return $this->log(
            $action,
            'System',
            null,
            'Maintenance mode '.($enabled ? 'enabled' : 'disabled'),
            $metadata
        );
    }

    /**
     * Log data export.
     */
    public function logDataExport(
        string $exportType,
        array $filters,
        int $recordCount,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_DATA_EXPORT,
            'Export',
            null,
            "Data export performed: {$exportType} ({$recordCount} records)",
            array_merge($metadata, [
                'export_type' => $exportType,
                'filters' => $filters,
                'record_count' => $recordCount,
            ])
        );
    }

    /**
     * Log student import.
     */
    public function logStudentImport(
        int $successCount,
        int $failedCount,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_STUDENT_IMPORT,
            'Student',
            null,
            "Student bulk import completed: {$successCount} success, {$failedCount} failed",
            array_merge($metadata, [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
            ])
        );
    }

    /**
     * Log API key generation (high-risk).
     */
    public function logApiKeyGenerate(
        string $keyName,
        int $targetUserId,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_API_KEY_GENERATE,
            'ApiKey',
            $targetUserId,
            "API key generated: {$keyName}",
            array_merge($metadata, ['key_name' => $keyName])
        );
    }

    /**
     * Log API key revocation (high-risk).
     */
    public function logApiKeyRevoke(
        string $keyName,
        int $targetUserId,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_API_KEY_REVOKE,
            'ApiKey',
            $targetUserId,
            "API key revoked: {$keyName}",
            array_merge($metadata, ['key_name' => $keyName])
        );
    }

    /**
     * Log audit log view (for tracking who views sensitive logs).
     */
    public function logAuditLogView(
        string $logType,
        array $filters,
        array $metadata = []
    ): ?AdminActivityLog {
        return $this->log(
            AdminActivityLog::ACTION_AUDIT_LOG_VIEW,
            'AuditLog',
            null,
            "Viewed {$logType} audit logs",
            array_merge($metadata, [
                'log_type' => $logType,
                'filters' => $filters,
            ])
        );
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Write to the immutable security log chain.
     */
    protected function writeToImmutableLog(
        AdminActivityLog $activityLog,
        User $admin,
        array $metadata
    ): void {
        try {
            $this->getImmutableLogService()->logAdminAction(
                $admin->id,
                $activityLog->school_id,
                $activityLog->action_type,
                $activityLog->description,
                array_merge($metadata, [
                    'activity_log_id' => $activityLog->id,
                    'target_type' => $activityLog->target_type,
                    'target_id' => $activityLog->target_id,
                    'admin_role' => $activityLog->role,
                ])
            );
        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to write admin action to immutable log', [
                'activity_log_id' => $activityLog->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Trigger alerts for high-risk admin actions.
     */
    protected function triggerHighRiskAlert(
        AdminActivityLog $activityLog,
        User $admin,
        array $metadata
    ): void {
        try {
            $severity = $this->determineSeverity($activityLog->action_type);

            $alertMessage = $this->formatAlertMessage($activityLog, $admin);

            $this->getSecurityAlertService()->createAlert(
                'admin_action_'.$activityLog->action_type,
                $severity,
                $alertMessage,
                array_merge($metadata, [
                    'activity_log_id' => $activityLog->id,
                    'admin_id' => $admin->id,
                    'admin_email' => $admin->email,
                    'admin_role' => $activityLog->role,
                    'action_type' => $activityLog->action_type,
                    'target_type' => $activityLog->target_type,
                    'target_id' => $activityLog->target_id,
                ]),
                $admin->id,
                $activityLog->school_id,
                $activityLog->ip_address,
                null,
                $severity === SecurityAlert::SEVERITY_CRITICAL // Force notify for critical
            );

            Log::channel('security')->warning('High-risk admin action alert triggered', [
                'action_type' => $activityLog->action_type,
                'admin_id' => $admin->id,
                'severity' => $severity,
            ]);

        } catch (\Exception $e) {
            Log::channel('security')->error('Failed to trigger high-risk action alert', [
                'activity_log_id' => $activityLog->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine the severity level for a high-risk action.
     */
    protected function determineSeverity(string $actionType): string
    {
        $criticalActions = [
            AdminActivityLog::ACTION_BACKUP_RESTORE,
            AdminActivityLog::ACTION_USER_DELETE,
            AdminActivityLog::ACTION_SCHOOL_DELETE,
            AdminActivityLog::ACTION_ATTENDANCE_DELETE,
        ];

        $highActions = [
            AdminActivityLog::ACTION_TEACHER_DEVICE_RESET,
            AdminActivityLog::ACTION_ATTENDANCE_OVERRIDE,
            AdminActivityLog::ACTION_GEOFENCE_UPDATE,
            AdminActivityLog::ACTION_PERMISSION_CHANGE,
            AdminActivityLog::ACTION_API_KEY_GENERATE,
            AdminActivityLog::ACTION_API_KEY_REVOKE,
            AdminActivityLog::ACTION_MAINTENANCE_ENABLE,
        ];

        if (in_array($actionType, $criticalActions)) {
            return SecurityAlert::SEVERITY_CRITICAL;
        }

        if (in_array($actionType, $highActions)) {
            return SecurityAlert::SEVERITY_HIGH;
        }

        return SecurityAlert::SEVERITY_MEDIUM;
    }

    /**
     * Format the alert message for notifications.
     */
    protected function formatAlertMessage(AdminActivityLog $activityLog, User $admin): string
    {
        $emoji = $this->determineSeverity($activityLog->action_type) === SecurityAlert::SEVERITY_CRITICAL
            ? '🚨'
            : '⚠️';

        return sprintf(
            "%s HIGH-RISK ADMIN ACTION\n\nAdmin: %s (%s)\nAction: %s\nTarget: %s #%s\nDescription: %s\nIP: %s\nTime: %s",
            $emoji,
            $admin->name ?? $admin->email,
            $activityLog->role,
            $activityLog->getActionDisplayName(),
            $activityLog->target_type ?? 'N/A',
            $activityLog->target_id ?? 'N/A',
            $activityLog->description,
            $activityLog->ip_address ?? 'Unknown',
            now()->format('Y-m-d H:i:s T')
        );
    }

    /**
     * Resolve the school_id for the admin based on their role.
     */
    protected function resolveSchoolId(User $admin): ?int
    {
        // Super admins may not have a school_id
        if ($admin->role_type === 'super_admin') {
            return request()->input('school_id') ?? null;
        }

        return $admin->school_id ?? null;
    }

    /**
     * Truncate user agent to fit database column.
     */
    protected function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return substr($userAgent, 0, 500);
    }

    /**
     * Generate a default description based on action type.
     */
    protected function generateDescription(
        string $actionType,
        ?string $targetType,
        ?int $targetId
    ): string {
        $actionDisplay = ucwords(str_replace('_', ' ', $actionType));

        if ($targetType && $targetId) {
            return "{$actionDisplay} for {$targetType} #{$targetId}";
        }

        if ($targetType) {
            return "{$actionDisplay} for {$targetType}";
        }

        return $actionDisplay;
    }

    /**
     * Try to find user ID by email for failed login logging.
     */
    protected function findUserIdByEmail(string $email): ?int
    {
        $user = User::where('email', $email)->first(['id']);

        return $user?->id;
    }

    // =========================================================================
    // QUERY HELPERS FOR REPORTING
    // =========================================================================

    /**
     * Get activity summary for a specific admin.
     */
    public function getAdminActivitySummary(int $adminUserId, int $days = 30): array
    {
        $since = now()->subDays($days);

        return [
            'total_actions' => AdminActivityLog::forAdmin($adminUserId)
                ->where('created_at', '>=', $since)
                ->count(),
            'high_risk_actions' => AdminActivityLog::forAdmin($adminUserId)
                ->highRisk()
                ->where('created_at', '>=', $since)
                ->count(),
            'actions_by_type' => AdminActivityLog::forAdmin($adminUserId)
                ->where('created_at', '>=', $since)
                ->selectRaw('action_type, COUNT(*) as count')
                ->groupBy('action_type')
                ->pluck('count', 'action_type')
                ->toArray(),
            'period_days' => $days,
        ];
    }

    /**
     * Get high-risk action summary for a school.
     */
    public function getSchoolHighRiskSummary(int $schoolId, int $days = 7): array
    {
        $since = now()->subDays($days);

        return AdminActivityLog::forSchool($schoolId)
            ->highRisk()
            ->where('created_at', '>=', $since)
            ->with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->toArray();
    }
}
