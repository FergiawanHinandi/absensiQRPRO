<?php

namespace App\Http\Middleware;

use App\Models\BehaviorBaseline;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Device Reverification
 * 
 * Forces teachers flagged with requires_device_reverification to re-verify
 * their device before making attendance scans.
 */
class CheckDeviceReverification
{
    /**
     * Routes that require re-verification check
     */
    protected array $protectedRoutes = [
        'v1/attendance/scan',
        'v1/attendance/generate-qr',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Only check for authenticated teachers
        if (!$user || $user->role_type !== 'teacher') {
            return $next($request);
        }

        // Only check protected routes
        if (!$this->isProtectedRoute($request->path())) {
            return $next($request);
        }

        // Check if user requires device re-verification
        $baseline = BehaviorBaseline::forUser($user->id)->first();
        
        if ($baseline && $baseline->requires_device_reverification) {
            return response()->json([
                'success' => false,
                'error' => 'DEVICE_REVERIFICATION_REQUIRED',
                'message' => 'Anda harus memverifikasi ulang perangkat sebelum dapat melakukan presensi. Silakan hubungi admin.',
                'data' => [
                    'reason' => 'behavior_anomaly_detected',
                    'flagged_at' => $baseline->flagged_at?->toIso8601String(),
                    'contact_admin' => true,
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Check if the current route requires re-verification check
     */
    protected function isProtectedRoute(string $path): bool
    {
        foreach ($this->protectedRoutes as $route) {
            if (str_contains($path, $route)) {
                return true;
            }
        }
        return false;
    }
}
