<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Services\TeacherDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher Device Management Controller
 * 
 * Allows school admins to approve, revoke, and manage teacher devices.
 */
class TeacherDeviceController extends Controller
{
    public function __construct(
        private TeacherDeviceService $deviceService
    ) {}

    /**
     * List all devices for the school
     * 
     * GET /api/v1/school-admin/teacher-devices
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $status = $request->query('status'); // pending, approved, revoked, or null for all

        $devices = $this->deviceService->getSchoolDevices($schoolId, $status);

        return response()->json([
            'success' => true,
            'data' => [
                'devices' => $devices->map(fn($d) => [
                    'id' => $d->id,
                    'teacher' => [
                        'id' => $d->teacher->id,
                        'name' => $d->teacher->name,
                        'username' => $d->teacher->username,
                    ],
                    'device_id' => substr($d->device_id, 0, 8) . '...', // Truncated for security
                    'device_name' => $d->device_name,
                    'device_model' => $d->device_model,
                    'os_version' => $d->os_version,
                    'app_version' => $d->app_version,
                    'is_approved' => $d->is_approved,
                    'status' => $d->revoked_at ? 'revoked' : ($d->is_approved ? 'approved' : 'pending'),
                    'approved_at' => $d->approved_at?->toIso8601String(),
                    'approved_by' => $d->approver?->name,
                    'last_used_at' => $d->last_used_at?->toIso8601String(),
                    'last_used_ip' => $d->last_used_ip,
                    'revoked_at' => $d->revoked_at?->toIso8601String(),
                    'revoked_by' => $d->revoker?->name,
                    'revoke_reason' => $d->revoke_reason,
                    'created_at' => $d->created_at->toIso8601String(),
                ]),
                'summary' => [
                    'total' => $devices->count(),
                    'pending' => $devices->where('is_approved', false)->whereNull('revoked_at')->count(),
                    'approved' => $devices->where('is_approved', true)->whereNull('revoked_at')->count(),
                    'revoked' => $devices->whereNotNull('revoked_at')->count(),
                ],
            ],
        ]);
    }

    /**
     * Get pending devices requiring approval
     * 
     * GET /api/v1/school-admin/teacher-devices/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $devices = $this->deviceService->getPendingDevices($schoolId);

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $devices->count(),
                'devices' => $devices->map(fn($d) => [
                    'id' => $d->id,
                    'teacher' => [
                        'id' => $d->teacher->id,
                        'name' => $d->teacher->name,
                        'username' => $d->teacher->username,
                    ],
                    'device_name' => $d->device_name,
                    'device_model' => $d->device_model,
                    'registered_at' => $d->created_at->toIso8601String(),
                    'registered_at_human' => $d->created_at->diffForHumans(),
                ]),
            ],
        ]);
    }

    /**
     * Approve a device
     * 
     * POST /api/v1/school-admin/teacher-devices/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        // Verify device belongs to admin's school
        $device = \App\Models\TeacherDevice::where('id', $id)
            ->where('school_id', $admin->school_id)
            ->firstOrFail();

        $device = $this->deviceService->approveDevice($id, $admin->id);

        return response()->json([
            'success' => true,
            'message' => 'Perangkat berhasil disetujui.',
            'data' => [
                'device_id' => $device->id,
                'teacher_name' => $device->teacher->name,
                'approved_at' => $device->approved_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Revoke a device
     * 
     * POST /api/v1/school-admin/teacher-devices/{id}/revoke
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $admin = $request->user();

        // Verify device belongs to admin's school
        $device = \App\Models\TeacherDevice::where('id', $id)
            ->where('school_id', $admin->school_id)
            ->firstOrFail();

        $device = $this->deviceService->revokeDevice(
            $id, 
            $admin->id, 
            $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Perangkat berhasil dicabut aksesnya.',
            'data' => [
                'device_id' => $device->id,
                'teacher_name' => $device->teacher->name,
                'revoked_at' => $device->revoked_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Reactivate a revoked device
     * 
     * POST /api/v1/school-admin/teacher-devices/{id}/reactivate
     */
    public function reactivate(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        // Verify device belongs to admin's school
        $device = \App\Models\TeacherDevice::where('id', $id)
            ->where('school_id', $admin->school_id)
            ->firstOrFail();

        $device = $this->deviceService->reactivateDevice($id, $admin->id);

        return response()->json([
            'success' => true,
            'message' => 'Perangkat berhasil diaktifkan kembali.',
            'data' => [
                'device_id' => $device->id,
                'teacher_name' => $device->teacher->name,
                'approved_at' => $device->approved_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Bulk approve devices
     * 
     * POST /api/v1/school-admin/teacher-devices/bulk-approve
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer|exists:teacher_devices,id',
        ]);

        $admin = $request->user();

        // Filter to only devices in admin's school
        $deviceIds = \App\Models\TeacherDevice::whereIn('id', $request->device_ids)
            ->where('school_id', $admin->school_id)
            ->pluck('id')
            ->toArray();

        $count = $this->deviceService->bulkApprove($deviceIds, $admin->id);

        return response()->json([
            'success' => true,
            'message' => "{$count} perangkat berhasil disetujui.",
            'data' => [
                'approved_count' => $count,
            ],
        ]);
    }

    /**
     * Bulk revoke devices
     * 
     * POST /api/v1/school-admin/teacher-devices/bulk-revoke
     */
    public function bulkRevoke(Request $request): JsonResponse
    {
        $request->validate([
            'device_ids' => 'required|array|min:1',
            'device_ids.*' => 'integer|exists:teacher_devices,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $admin = $request->user();

        // Filter to only devices in admin's school
        $deviceIds = \App\Models\TeacherDevice::whereIn('id', $request->device_ids)
            ->where('school_id', $admin->school_id)
            ->pluck('id')
            ->toArray();

        $count = $this->deviceService->bulkRevoke(
            $deviceIds, 
            $admin->id, 
            $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} perangkat berhasil dicabut.",
            'data' => [
                'revoked_count' => $count,
            ],
        ]);
    }

    /**
     * Get devices for a specific teacher
     * 
     * GET /api/v1/school-admin/teachers/{teacherId}/devices
     */
    public function teacherDevices(Request $request, int $teacherId): JsonResponse
    {
        $admin = $request->user();

        // Verify teacher belongs to admin's school
        $teacher = \App\Models\User::where('id', $teacherId)
            ->where('school_id', $admin->school_id)
            ->where('role_type', 'teacher')
            ->firstOrFail();

        $devices = $this->deviceService->getTeacherDevices($teacherId);

        return response()->json([
            'success' => true,
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                ],
                'devices' => $devices->map(fn($d) => [
                    'id' => $d->id,
                    'device_name' => $d->device_name,
                    'device_model' => $d->device_model,
                    'status' => $d->revoked_at ? 'revoked' : ($d->is_approved ? 'approved' : 'pending'),
                    'last_used_at' => $d->last_used_at?->toIso8601String(),
                    'created_at' => $d->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }
}
