<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teacher Device Binding Model
 *
 * Tracks which devices teachers are allowed to use for attendance.
 * New devices must be approved by school admin before they can record attendance.
 */
class TeacherDevice extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'device_id',
        'device_name',
        'platform',
        'device_model',
        'os_version',
        'app_version',
        'is_approved',
        'approved_at',
        'approved_by',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_approved', true)->whereNull('revoked_at');
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false)->whereNull('revoked_at');
    }

    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if device can be used for attendance
     */
    public function canUseForAttendance(): bool
    {
        return $this->is_approved && is_null($this->revoked_at);
    }

    /**
     * Mark device as used
     */
    public function markAsUsed(): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_used_ip' => request()->ip(),
        ]);
    }

    /**
     * Approve the device
     */
    public function approve(int $approvedBy): void
    {
        $this->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $approvedBy,
        ]);
    }

    /**
     * Revoke the device
     */
    public function revoke(int $revokedBy, ?string $reason = null): void
    {
        $this->update([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy,
            'revoke_reason' => $reason,
        ]);
    }

    /**
     * Reactivate a revoked device
     */
    public function reactivate(int $approvedBy): void
    {
        $this->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $approvedBy,
            'revoked_at' => null,
            'revoked_by' => null,
            'revoke_reason' => null,
        ]);
    }

    // =========================================================================
    // STATIC METHODS
    // =========================================================================

    /**
     * Check if this device is approved for the given teacher
     */
    public static function isApproved(int $teacherId, string $deviceId): bool
    {
        return self::where('teacher_id', $teacherId)
            ->where('device_id', $deviceId)
            ->active()
            ->exists();
    }

    /**
     * Get approved device for teacher
     */
    public static function getApprovedDevice(int $teacherId, string $deviceId): ?self
    {
        return self::where('teacher_id', $teacherId)
            ->where('device_id', $deviceId)
            ->active()
            ->first();
    }

    /**
     * Register or get existing device for teacher
     *
     * @return array{device: TeacherDevice, is_new: bool}
     */
    public static function registerDevice(
        int $schoolId,
        int $teacherId,
        string $deviceId,
        ?string $deviceName = null,
        ?string $deviceModel = null,
        ?string $osVersion = null,
        ?string $appVersion = null
    ): array {
        $device = self::where('teacher_id', $teacherId)
            ->where('device_id', $deviceId)
            ->first();

        if ($device) {
            // Update device info if changed
            $device->update([
                'device_name' => $deviceName ?? $device->device_name,
                'device_model' => $deviceModel ?? $device->device_model,
                'os_version' => $osVersion ?? $device->os_version,
                'app_version' => $appVersion ?? $device->app_version,
            ]);

            return ['device' => $device, 'is_new' => false];
        }

        // Create new device (unapproved by default)
        $device = self::create([
            'school_id' => $schoolId,
            'teacher_id' => $teacherId,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'device_model' => $deviceModel,
            'os_version' => $osVersion,
            'app_version' => $appVersion,
            'is_approved' => false,
        ]);

        return ['device' => $device, 'is_new' => true];
    }

    /**
     * Get teacher's device status
     *
     * @return string 'approved'|'pending'|'revoked'|'unknown'
     */
    public static function getDeviceStatus(int $teacherId, string $deviceId): string
    {
        $device = self::where('teacher_id', $teacherId)
            ->where('device_id', $deviceId)
            ->first();

        if (! $device) {
            return 'unknown';
        }

        if ($device->revoked_at) {
            return 'revoked';
        }

        return $device->is_approved ? 'approved' : 'pending';
    }
}
