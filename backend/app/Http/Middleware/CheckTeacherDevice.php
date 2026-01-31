<?php

namespace App\Http\Middleware;

use App\Models\TeacherDevice;
use App\Services\SecurityAlertService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Teacher Device Middleware
 * 
 * Ensures teachers can only perform attendance from approved devices.
 * This middleware should be applied to teacher attendance routes.
 */
class CheckTeacherDevice
{
    public function __construct(
        protected SecurityAlertService $alertService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only check for teachers
        if (!$user || $user->role_type !== 'teacher') {
            return $next($request);
        }

        // Get device ID from request
        $deviceId = $this->getDeviceId($request);

        if (!$deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'Device ID diperlukan untuk absensi.',
                'error_code' => 'DEVICE_ID_REQUIRED',
            ], 400);
        }

        // Check device status
        $device = TeacherDevice::where('teacher_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        // Device not registered
        if (!$device) {
            // Auto-register new device (unapproved)
            $result = TeacherDevice::registerDevice(
                schoolId: $user->school_id,
                teacherId: $user->id,
                deviceId: $deviceId,
                deviceName: $request->header('X-Device-Name'),
                deviceModel: $request->header('X-Device-Model'),
                osVersion: $request->header('X-OS-Version'),
                appVersion: $request->header('X-App-Version')
            );

            Log::channel('security')->info('New teacher device registered', [
                'teacher_id' => $user->id,
                'device_id' => substr($deviceId, 0, 10) . '...',
                'ip' => $request->ip(),
            ]);

            // Alert for new device requiring approval
            $this->alertService->alertUnapprovedDevice(
                $user->id,
                $user->school_id,
                $deviceId,
                $request->header('X-Device-Model') ?? 'Unknown device',
                $request->ip()
            );

            return response()->json([
                'success' => false,
                'message' => 'Perangkat baru terdeteksi. Menunggu persetujuan admin.',
                'error_code' => 'DEVICE_PENDING_APPROVAL',
                'device_status' => 'pending',
            ], 403);
        }

        // Device revoked
        if ($device->revoked_at) {
            Log::channel('security')->warning('Revoked device access attempt', [
                'teacher_id' => $user->id,
                'device_id' => substr($deviceId, 0, 10) . '...',
                'revoked_at' => $device->revoked_at->toIso8601String(),
                'ip' => $request->ip(),
            ]);

            // High-severity alert for revoked device attempt
            $this->alertService->createAlert(
                SecurityAlertService::TYPE_UNAPPROVED_DEVICE,
                'high',
                "Revoked device attempted access",
                [
                    'teacher_id' => $user->id,
                    'teacher_name' => $user->name,
                    'device_id' => $deviceId,
                    'revoked_at' => $device->revoked_at->toIso8601String(),
                    'revoke_reason' => $device->revoke_reason,
                ],
                $user->id,
                $user->school_id,
                $request->ip(),
                $deviceId
            );

            return response()->json([
                'success' => false,
                'message' => 'Perangkat ini telah dicabut aksesnya. Hubungi admin.',
                'error_code' => 'DEVICE_REVOKED',
                'device_status' => 'revoked',
                'revoke_reason' => $device->revoke_reason,
            ], 403);
        }

        // Device not approved
        if (!$device->is_approved) {
            return response()->json([
                'success' => false,
                'message' => 'Perangkat belum disetujui. Menunggu persetujuan admin.',
                'error_code' => 'DEVICE_PENDING_APPROVAL',
                'device_status' => 'pending',
            ], 403);
        }

        // Device approved - update last used and continue
        $device->markAsUsed();

        // Attach device to request for later use
        $request->attributes->set('teacher_device', $device);

        return $next($request);
    }

    /**
     * Get device ID from request
     */
    private function getDeviceId(Request $request): ?string
    {
        // Check header first (preferred)
        $deviceId = $request->header('X-Device-ID');

        // Fallback to request body
        if (!$deviceId) {
            $deviceId = $request->input('device_id');
        }

        return $deviceId;
    }
}
