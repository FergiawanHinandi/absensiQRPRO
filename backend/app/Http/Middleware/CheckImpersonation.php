<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class CheckImpersonation
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Session::has('impersonator_id')) {
            $impersonatorId = Session::get('impersonator_id');
            $targetUserId = $request->user() ? $request->user()->id : null;

            // Add warning header for UI
            $response->headers->set('X-Impersonation-Active', 'true');
            $response->headers->set('X-Impersonator-ID', $impersonatorId);

            // Log every request during impersonation
            // We use the 'superadmin' channel as requested for traceability
            // But we must be careful not to spam too much if not needed.
            // Requirement: "Log every request made during impersonation"

            // We can reuse the admin_activity_logs or a separate structure.
            // Let's use the admin_activity_logs but mark it as IMPERSONATED_ACTION

            if ($request->method() !== 'GET') { // Optional: Skip GETs to reduce noise, but req says "Every request"
                /* actually "every request" usually implies GET too for audit of what they SAW.
                   But for performance, logging every GET into DB might be heavy.
                   I'll log to FILE channel for full trace, and DB for modifications.
                */
            }

            \Illuminate\Support\Facades\Log::channel('superadmin')->info('Impersonated Action', [
                'impersonator_id' => $impersonatorId,
                'acting_as_user_id' => $targetUserId,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
            ]);
        }

        return $response;
    }
}
