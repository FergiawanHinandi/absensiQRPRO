<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * EnsureDeviceMatch Middleware
 * 
 * Enforces Strict Device Binding:
 * 1. Checks if the token is bound to a specific device_id
 * 2. Compares with X-Device-Id header
 * 3. Prevents token theft/replay from different devices
 */
class EnsureDeviceMatch
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();

        // If not authenticated or token is not a PersonalAccessToken (e.g. session), skip
        if (!$token || !($token instanceof PersonalAccessToken)) {
            return $next($request);
        }

        // Retrieve bound device_id from token metadata
        // Note: 'device_id' column added via migration
        $boundDeviceId = $token->device_id ?? null;

        // If token has no binding, we might allow it (legacy tokens) 
        // OR enforce strict policy. Here we enforcing strict if token has it.
        if (!$boundDeviceId) {
            return $next($request);
        }

        // Get ID from request
        $requestDeviceId = $request->header('X-Device-Id') ?? $request->input('device_id');

        if (!$requestDeviceId) {
            return response()->json([
                'status' => false,
                'code' => 400,
                'message' => 'Missing Device ID (X-Device-Id header required)'
            ], 400);
        }

        // Strict Comparison
        if ($boundDeviceId !== $requestDeviceId) {
            \Illuminate\Support\Facades\Log::warning('Security Alert: Device Mismatch', [
                'user_id' => $request->user()->id,
                'token_device' => $boundDeviceId,
                'request_device' => $requestDeviceId,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'status' => false,
                'code' => 401,
                'message' => 'Device mismatch. Token is bound to another device.'
            ], 401);
        }

        return $next($request);
    }
}
