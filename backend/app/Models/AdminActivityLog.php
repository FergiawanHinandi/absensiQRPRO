<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToSchool;

/**
 * AdminActivityLog Model
 *
 * Tracks all sensitive admin actions for audit purposes.
 * This model is immutable - updates and deletes are prevented at both
 * the application and database levels.
 *
 * @property int $id
 * @property int $admin_user_id
 * @property string $role
 * @property int|null $school_id
 * @property string $action_type
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string $description
 * @property array|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $route_name
 * @property string|null $http_method
 * @property \Carbon\Carbon $created_at
 */
class AdminActivityLog extends Model
{
    use HasFactory;
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    /**
     * Indicates if the model should be timestamped.
     * We only use created_at, managed by the database.
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'metadata' => 'array',
        'admin_user_id' => 'integer',
        'school_id' => 'integer',
        'target_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'admin_user_id',
        'role',
        'school_id',
        'action_type',
        'target_type',
        'target_id',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
        'route_name',
        'http_method',
    ];

    // =========================================================================
    // ACTION TYPE CONSTANTS
    // =========================================================================

    // User Management
    public const ACTION_USER_CREATE = 'user_create';

    public const ACTION_USER_UPDATE = 'user_update';

    public const ACTION_USER_DELETE = 'user_delete';

    public const ACTION_USER_DEACTIVATE = 'user_deactivate';

    public const ACTION_USER_ACTIVATE = 'user_activate';

    public const ACTION_USER_PASSWORD_RESET = 'user_password_reset';

    // Teacher Management
    public const ACTION_TEACHER_CREATE = 'teacher_create';

    public const ACTION_TEACHER_UPDATE = 'teacher_update';

    public const ACTION_TEACHER_DELETE = 'teacher_delete';

    public const ACTION_TEACHER_DEVICE_RESET = 'teacher_device_reset';

    public const ACTION_TEACHER_SCHEDULE_ASSIGN = 'teacher_schedule_assign';

    // Student Management
    public const ACTION_STUDENT_CREATE = 'student_create';

    public const ACTION_STUDENT_UPDATE = 'student_update';

    public const ACTION_STUDENT_DELETE = 'student_delete';

    public const ACTION_STUDENT_IMPORT = 'student_import';

    public const ACTION_STUDENT_QR_REGENERATE = 'student_qr_regenerate';

    // Class/Subject Management
    public const ACTION_CLASS_CREATE = 'class_create';

    public const ACTION_CLASS_UPDATE = 'class_update';

    public const ACTION_CLASS_DELETE = 'class_delete';

    public const ACTION_SUBJECT_CREATE = 'subject_create';

    public const ACTION_SUBJECT_UPDATE = 'subject_update';

    public const ACTION_SUBJECT_DELETE = 'subject_delete';

    // Schedule Management
    public const ACTION_SCHEDULE_CREATE = 'schedule_create';

    public const ACTION_SCHEDULE_UPDATE = 'schedule_update';

    public const ACTION_SCHEDULE_DELETE = 'schedule_delete';

    public const ACTION_SCHEDULE_BULK_CREATE = 'schedule_bulk_create';

    // Attendance Management
    public const ACTION_ATTENDANCE_OVERRIDE = 'attendance_override';

    public const ACTION_ATTENDANCE_MANUAL_ENTRY = 'attendance_manual_entry';

    public const ACTION_ATTENDANCE_DELETE = 'attendance_delete';

    public const ACTION_ATTENDANCE_EXPORT = 'attendance_export';

    // School Configuration
    public const ACTION_SCHOOL_CREATE = 'school_create';

    public const ACTION_SCHOOL_UPDATE = 'school_update';

    public const ACTION_SCHOOL_DELETE = 'school_delete';

    public const ACTION_SCHOOL_SETTINGS_UPDATE = 'school_settings_update';

    public const ACTION_GEOFENCE_UPDATE = 'geofence_update';

    // Security Actions
    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGOUT = 'logout';

    public const ACTION_LOGIN_FAILED = 'login_failed';

    public const ACTION_API_KEY_GENERATE = 'api_key_generate';

    public const ACTION_API_KEY_REVOKE = 'api_key_revoke';

    public const ACTION_PERMISSION_CHANGE = 'permission_change';

    // System Actions
    public const ACTION_BACKUP_CREATE = 'backup_create';

    public const ACTION_BACKUP_RESTORE = 'backup_restore';

    public const ACTION_MAINTENANCE_ENABLE = 'maintenance_enable';

    public const ACTION_MAINTENANCE_DISABLE = 'maintenance_disable';

    public const ACTION_AUDIT_LOG_VIEW = 'audit_log_view';

    // Data Export/Import
    public const ACTION_DATA_EXPORT = 'data_export';

    public const ACTION_DATA_IMPORT = 'data_import';

    public const ACTION_REPORT_GENERATE = 'report_generate';

    /**
     * High-risk actions that should trigger alerts
     */
    public const HIGH_RISK_ACTIONS = [
        self::ACTION_TEACHER_DEVICE_RESET,
        self::ACTION_ATTENDANCE_OVERRIDE,
        self::ACTION_ATTENDANCE_DELETE,
        self::ACTION_GEOFENCE_UPDATE,
        self::ACTION_USER_DELETE,
        self::ACTION_SCHOOL_DELETE,
        self::ACTION_PERMISSION_CHANGE,
        self::ACTION_BACKUP_RESTORE,
        self::ACTION_API_KEY_GENERATE,
        self::ACTION_API_KEY_REVOKE,
        self::ACTION_MAINTENANCE_ENABLE,
    ];

    // =========================================================================
    // IMMUTABILITY PROTECTION
    // =========================================================================

    /**
     * Override the update method to prevent modifications
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException(
            'AdminActivityLog records are immutable and cannot be updated. '.
            'Record ID: '.($this->id ?? 'new')
        );
    }

    /**
     * Override the delete method to prevent deletions
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException(
            'AdminActivityLog records are immutable and cannot be deleted. '.
            'Record ID: '.($this->id ?? 'new')
        );
    }

    /**
     * Override forceDelete to prevent any deletion
     */
    public function forceDelete(): ?bool
    {
        throw new \RuntimeException(
            'AdminActivityLog records are immutable and cannot be force deleted. '.
            'Record ID: '.($this->id ?? 'new')
        );
    }

    /**
     * Disable mass updates
     */
    public static function query(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::query()->where(function ($query) {
            // This allows normal queries but we'll override update/delete operations
        });
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Admin user who performed the action
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * School context for the action
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    /**
     * Filter by admin user
     */
    public function scopeForAdmin($query, int $adminUserId)
    {
        return $query->where('admin_user_id', $adminUserId);
    }

    /**
     * Filter by school
     */
    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Filter by action type
     */
    public function scopeOfType($query, string $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    /**
     * Filter by multiple action types
     */
    public function scopeOfTypes($query, array $actionTypes)
    {
        return $query->whereIn('action_type', $actionTypes);
    }

    /**
     * Filter high-risk actions
     */
    public function scopeHighRisk($query)
    {
        return $query->whereIn('action_type', self::HIGH_RISK_ACTIONS);
    }

    /**
     * Filter by target entity
     */
    public function scopeForTarget($query, string $targetType, ?int $targetId = null)
    {
        $query->where('target_type', $targetType);

        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        }

        return $query;
    }

    /**
     * Filter by date range
     */
    public function scopeDateRange($query, string $from, ?string $to = null)
    {
        $query->where('created_at', '>=', $from);

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Recent logs (last N hours)
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Search in description
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where('description', 'ilike', "%{$term}%");
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if this action is high-risk
     */
    public function isHighRisk(): bool
    {
        return in_array($this->action_type, self::HIGH_RISK_ACTIONS);
    }

    /**
     * Get the display name for the action type
     */
    public function getActionDisplayName(): string
    {
        $names = [
            self::ACTION_USER_CREATE => 'User Created',
            self::ACTION_USER_UPDATE => 'User Updated',
            self::ACTION_USER_DELETE => 'User Deleted',
            self::ACTION_USER_DEACTIVATE => 'User Deactivated',
            self::ACTION_USER_ACTIVATE => 'User Activated',
            self::ACTION_USER_PASSWORD_RESET => 'Password Reset',
            self::ACTION_TEACHER_CREATE => 'Teacher Created',
            self::ACTION_TEACHER_UPDATE => 'Teacher Updated',
            self::ACTION_TEACHER_DELETE => 'Teacher Deleted',
            self::ACTION_TEACHER_DEVICE_RESET => 'Device Reset',
            self::ACTION_TEACHER_SCHEDULE_ASSIGN => 'Schedule Assigned',
            self::ACTION_STUDENT_CREATE => 'Student Created',
            self::ACTION_STUDENT_UPDATE => 'Student Updated',
            self::ACTION_STUDENT_DELETE => 'Student Deleted',
            self::ACTION_STUDENT_IMPORT => 'Students Imported',
            self::ACTION_STUDENT_QR_REGENERATE => 'QR Code Regenerated',
            self::ACTION_ATTENDANCE_OVERRIDE => 'Attendance Override',
            self::ACTION_ATTENDANCE_MANUAL_ENTRY => 'Manual Attendance Entry',
            self::ACTION_ATTENDANCE_DELETE => 'Attendance Deleted',
            self::ACTION_GEOFENCE_UPDATE => 'Geofence Updated',
            self::ACTION_SCHOOL_SETTINGS_UPDATE => 'School Settings Updated',
            self::ACTION_PERMISSION_CHANGE => 'Permission Changed',
            self::ACTION_BACKUP_CREATE => 'Backup Created',
            self::ACTION_BACKUP_RESTORE => 'Backup Restored',
            self::ACTION_MAINTENANCE_ENABLE => 'Maintenance Enabled',
            self::ACTION_MAINTENANCE_DISABLE => 'Maintenance Disabled',
        ];

        return $names[$this->action_type] ?? ucwords(str_replace('_', ' ', $this->action_type));
    }

    /**
     * Get all action type constants
     */
    public static function getAllActionTypes(): array
    {
        $reflection = new \ReflectionClass(self::class);
        $constants = $reflection->getConstants();

        return array_filter($constants, function ($key) {
            return str_starts_with($key, 'ACTION_');
        }, ARRAY_FILTER_USE_KEY);
    }
}
