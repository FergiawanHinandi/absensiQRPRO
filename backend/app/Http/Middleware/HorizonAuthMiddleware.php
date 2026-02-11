<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to protect Horizon dashboard access
 * 
 * Only super_admin users can access Horizon in production
 */
class HorizonAuthMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow access in local environment
        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        // Require authentication
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        // Only super_admin can access
        $user = Auth::user();
        if (!$user || $user->role_type !== 'super_admin') {
            abort(403, 'Unauthorized. Only super admins can access Horizon.');
        }

        return $next($request);
    }
}
