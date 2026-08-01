<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Device Token Controller
 *
 * Handles FCM device token registration for push notifications.
 * Mobile apps should call this endpoint when:
 * - App starts up (get FCM token)
 * - Token is refreshed by Firebase
 * - User logs in (register device)
 */
class DeviceTokenController extends Controller
{
    /**
     * Update device token for push notifications
     *
     * POST /api/v1/device-token
     *
     * @bodyParam device_token string required The FCM device token
     * @bodyParam device_platform string Optional platform: android, ios
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'device_token' => ['required', 'string', 'max:500'],
            'device_platform' => ['nullable', 'string', 'in:android,ios'],
        ]);

        $user = $request->user();

        $user->device_token = $request->device_token;
        $user->save();

        Log::info('Device token updated', [
            'user_id' => $user->id,
            'platform' => $request->device_platform ?? 'unknown',
            'token_prefix' => substr($request->device_token, 0, 8).'...',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Device token berhasil diperbarui.',
        ]);
    }

    /**
     * Remove device token (logout)
     *
     * DELETE /api/v1/device-token
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->device_token = null;
        $user->save();

        Log::info('Device token removed', [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Device token berhasil dihapus.',
        ]);
    }
}
