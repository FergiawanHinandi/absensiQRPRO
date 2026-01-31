<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiMaintenance
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if application is in maintenance mode
        if (app()->isDownForMaintenance()) {

            // Allow login and other public auth routes explicitly (just in case)
            if ($request->is('api/v1/auth/*')) {
                return $next($request);
            }

            // Check if user is authenticated and has super_admin role
            $user = $request->user();

            if ($user && ($user->hasRole('super_admin') || $user->email === 'super@admin.com')) {
                return $next($request);
            }

            // Return 503 if not allowed
            return response()->json([
                'message' => 'Service Unavailable',
                'maintenance' => true,
            ], 503);
        }

        return $next($request);
    }
}
