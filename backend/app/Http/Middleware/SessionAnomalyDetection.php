<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionAnomalyDetection
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
        // Only check authenticated users with tokens
        if (! $request->user() || ! $request->user()->currentAccessToken()) {
            return $next($request);
        }

        $user = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->id : "transient_{$user->id}";
        $currentIp = $request->ip();
        $currentDeviceId = $request->header('X-Device-ID') ?? $request->input('device_id');

        $cacheKey = "session_monitor:{$tokenId}";
        $lastSession = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if ($lastSession) {
            $lastIp = $lastSession['ip'];
            $lastDeviceId = $lastSession['device_id'];
            $lastTime = $lastSession['time']; // Timestamp

            // Check 1: Device ID Change Mid-Session
            // If device ID was previously recorded and now changes (and is not null)
            if ($lastDeviceId && $currentDeviceId && $lastDeviceId !== $currentDeviceId) {
                $this->handleAnomaly($request, 'device_mismatch', "Device ID changed from {$lastDeviceId} to {$currentDeviceId}");
            }

            // Check 2: IP Range Change within 5 minutes
            if (now()->diffInMinutes($lastTime) <= 5) {
                if ($this->isDifferentIpRange($lastIp, $currentIp)) {
                    $this->handleAnomaly($request, 'ip_anomaly', "IP jumped from {$lastIp} to {$currentIp} within short duration");
                }
            }
        }

        // Update Session Monitor
        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'ip' => $currentIp,
            'device_id' => $currentDeviceId ?? ($lastSession['device_id'] ?? null), // Preserve known device ID if missing in current req
            'time' => now(),
        ], now()->addMinutes(30));

        return $next($request);
    }

    private function handleAnomaly(Request $request, string $type, string $message)
    {
        $user = $request->user();

        // Log Security Event
        \Illuminate\Support\Facades\Log::channel('security')->critical("Session Anomaly Detected: {$type}", [
            'user_id' => $user->id,
            'reason' => $message,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Invalidate Token
        $token = $user->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        // Remove Cache
        $tokenId = $token instanceof \Laravel\Sanctum\PersonalAccessToken ? $token->id : "transient_{$user->id}";
        \Illuminate\Support\Facades\Cache::forget("session_monitor:{$tokenId}");

        // Force Re-login
        abort(response()->json([
            'message' => 'Sesi anda telah dihentikan karena terdeteksi aktivitas mencurigakan (Perubahan IP/Perangkat). Silakan login kembali.',
            'code' => 'SESSION_ANOMALY',
        ], 401));
    }

    /**
     * Check if IPs are in different /24 subnets (IPv4)
     */
    private function isDifferentIpRange($ip1, $ip2)
    {
        if ($ip1 === $ip2) {
            return false;
        }

        // Skip check for Localhost
        if ($ip1 === '127.0.0.1' || $ip2 === '127.0.0.1') {
            return false;
        }

        // Simple IPv4 /24 subnet check (First 3 octets)
        if (filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

            $subnet1 = substr($ip1, 0, strrpos($ip1, '.'));
            $subnet2 = substr($ip2, 0, strrpos($ip2, '.'));

            return $subnet1 !== $subnet2;
        }

        // For IPv6, just check strict equality for now as ranges are complex
        return $ip1 !== $ip2;
    }
}
