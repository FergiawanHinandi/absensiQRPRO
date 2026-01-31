<?php

namespace App\Services;

use App\Models\TeacherDevice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Teacher Device Service
 * 
 * Handles device registration, approval, and management logic.
 */
class TeacherDeviceService
{
    /**
     * Register device during login
     * 
     * @return array{device: TeacherDevice, is_new: bool, can_proceed: bool, message: string}
     */
    public function registerOnLogin(
        User $teacher,
        string $deviceId,
        ?string $deviceName = null,
        ?string $deviceModel = null,
        ?string $osVersion = null,
        ?string $appVersion = null
    ): array {
        $result = TeacherDevice::registerDevice(
            schoolId: $teacher->school_id,
            teacherId: $teacher->id,
            deviceId: $deviceId,
            deviceName: $deviceName,
            deviceModel: $deviceModel,
            osVersion: $osVersion,
            appVersion: $appVersion
        );

        $device = $result['device'];
        $isNew = $result['is_new'];

        // Log new device registration
        if ($isNew) {
            Log::channel('security')->info('New teacher device registered on login', [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->name,
                'school_id' => $teacher->school_id,
                'device_id' => substr($deviceId, 0, 10) . '...',
                'device_name' => $deviceName,
                'ip' => request()->ip(),
            ]);
        }

        // Determine if teacher can proceed with attendance
        $canProceed = $device->canUseForAttendance();
        
        $message = match (true) {
            $device->revoked_at !== null => 'Perangkat ini telah dicabut aksesnya.',
            !$device->is_approved => 'Perangkat baru. Menunggu persetujuan admin untuk absensi.',
            default => 'Perangkat terverifikasi.',
        };

        return [
            'device' => $device,
            'is_new' => $isNew,
            'can_proceed' => $canProceed,
            'message' => $message,
            'status' => TeacherDevice::getDeviceStatus($teacher->id, $deviceId),
        ];
    }

    /**
     * Check device status for teacher
     */
    public function checkDeviceStatus(int $teacherId, string $deviceId): array
    {
        $device = TeacherDevice::where('teacher_id', $teacherId)
            ->where('device_id', $deviceId)
            ->first();

        if (!$device) {
            return [
                'registered' => false,
                'status' => 'unknown',
                'can_use' => false,
                'message' => 'Perangkat belum terdaftar.',
            ];
        }

        return [
            'registered' => true,
            'status' => TeacherDevice::getDeviceStatus($teacherId, $deviceId),
            'can_use' => $device->canUseForAttendance(),
            'message' => $device->canUseForAttendance() 
                ? 'Perangkat terverifikasi.' 
                : ($device->revoked_at ? 'Perangkat dicabut.' : 'Menunggu persetujuan.'),
            'device' => [
                'id' => $device->id,
                'name' => $device->device_name,
                'model' => $device->device_model,
                'approved_at' => $device->approved_at?->toIso8601String(),
                'last_used_at' => $device->last_used_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Get all devices for a teacher
     */
    public function getTeacherDevices(int $teacherId): Collection
    {
        return TeacherDevice::where('teacher_id', $teacherId)
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * Get pending devices for a school (admin view)
     */
    public function getPendingDevices(int $schoolId): Collection
    {
        return TeacherDevice::where('school_id', $schoolId)
            ->pending()
            ->with('teacher:id,name,username,email')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get all devices for a school (admin view)
     */
    public function getSchoolDevices(int $schoolId, ?string $status = null): Collection
    {
        $query = TeacherDevice::where('school_id', $schoolId)
            ->with(['teacher:id,name,username,email', 'approver:id,name', 'revoker:id,name']);

        if ($status === 'pending') {
            $query->pending();
        } elseif ($status === 'approved') {
            $query->active();
        } elseif ($status === 'revoked') {
            $query->revoked();
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Approve a device
     */
    public function approveDevice(int $deviceId, int $approvedBy): TeacherDevice
    {
        $device = TeacherDevice::findOrFail($deviceId);
        $device->approve($approvedBy);

        Log::channel('security')->info('Teacher device approved', [
            'device_id' => $device->id,
            'teacher_id' => $device->teacher_id,
            'approved_by' => $approvedBy,
            'ip' => request()->ip(),
        ]);

        return $device->fresh();
    }

    /**
     * Revoke a device
     */
    public function revokeDevice(int $deviceId, int $revokedBy, ?string $reason = null): TeacherDevice
    {
        $device = TeacherDevice::findOrFail($deviceId);
        $device->revoke($revokedBy, $reason);

        Log::channel('security')->warning('Teacher device revoked', [
            'device_id' => $device->id,
            'teacher_id' => $device->teacher_id,
            'revoked_by' => $revokedBy,
            'reason' => $reason,
            'ip' => request()->ip(),
        ]);

        return $device->fresh();
    }

    /**
     * Reactivate a revoked device
     */
    public function reactivateDevice(int $deviceId, int $approvedBy): TeacherDevice
    {
        $device = TeacherDevice::findOrFail($deviceId);
        $device->reactivate($approvedBy);

        Log::channel('security')->info('Teacher device reactivated', [
            'device_id' => $device->id,
            'teacher_id' => $device->teacher_id,
            'approved_by' => $approvedBy,
            'ip' => request()->ip(),
        ]);

        return $device->fresh();
    }

    /**
     * Bulk approve devices
     */
    public function bulkApprove(array $deviceIds, int $approvedBy): int
    {
        $count = 0;
        foreach ($deviceIds as $deviceId) {
            try {
                $this->approveDevice($deviceId, $approvedBy);
                $count++;
            } catch (\Exception $e) {
                Log::error('Failed to approve device', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * Bulk revoke devices
     */
    public function bulkRevoke(array $deviceIds, int $revokedBy, ?string $reason = null): int
    {
        $count = 0;
        foreach ($deviceIds as $deviceId) {
            try {
                $this->revokeDevice($deviceId, $revokedBy, $reason);
                $count++;
            } catch (\Exception $e) {
                Log::error('Failed to revoke device', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }
}
