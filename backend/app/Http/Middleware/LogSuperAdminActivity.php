<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LogSuperAdminActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log modifying actions (POST, PUT, PATCH, DELETE)
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $response;
        }

        $user = $request->user();

        // Ensure user is authenticated and is a Super Admin
        // Assuming current Spatie role check or role_type attribute
        if ($user && ($user->hasRole('super_admin') || $user->role_type === 'super_admin')) {
            
            $actionType = $this->determineActionType($request);
            $targetType = $this->determineTargetType($request);
            $targetId = $this->determineTargetId($request);

            // 1. Log to File Channel
            Log::channel('superadmin')->info('Super Admin Action', [
                'super_admin_id' => $user->id,
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'payload' => $request->except(['password', 'password_confirmation', 'secret']),
            ]);

            // 2. Log to Database (Immutable Table)
            // Using DB directly to ensure we catch it even if Models act up, 
            // but we can also use valid Models if available. 
            // The table is 'admin_activity_logs'.
            try {
                DB::table('admin_activity_logs')->insert([
                    'admin_user_id' => $user->id,
                    'school_id' => null, // Super admin actions are often global
                    'role' => 'super_admin',
                    'action_type' => $actionType,
                    'target_type' => $targetType,
                    'target_id' => $targetId ?? 0, // 0 or null if generic
                    'description' => "Super Admin performed $actionType on $targetType",
                    'metadata' => json_encode([
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'payload' => $request->except(['password', 'password_confirmation', 'credit_card', 'token']),
                        'response_status' => $response->getStatusCode(),
                    ]),
                    'ip_address' => $request->ip(),
                    'user_agent' => substr($request->userAgent(), 0, 500),
                    'route_name' => $request->route() ? $request->route()->getName() : null,
                    'http_method' => $request->method(),
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) {
                // Do not fail the request if DB logging fails, but log the error
                Log::channel('superadmin')->error('Failed to write to DB audit log: ' . $e->getMessage());
            }
        }

        return $response;
    }

    private function determineActionType(Request $request): string
    {
        // Simple heuristic - can be enhanced
        if ($request->isMethod('POST')) return 'CREATE';
        if ($request->isMethod('PUT') || $request->isMethod('PATCH')) return 'UPDATE';
        if ($request->isMethod('DELETE')) return 'DELETE';
        return 'ACTION';
    }

    private function determineTargetType(Request $request): string
    {
        // Try to guess from URL segments
        $segments = $request->segments();
        // e.g. api/v1/superadmin/schools/1 -> School
        // e.g. api/v1/superadmin/users/1 -> User
        
        // Return the second to last segment if it's an ID, else the last segment
        if (count($segments) > 0) {
            $last = end($segments);
            if (is_numeric($last)) {
                // If last is ID, return the one before it (singularized if possible)
                $type = prev($segments);
                return ucfirst(rtrim($type, 's')); 
            }
            return ucfirst(rtrim($last, 's'));
        }
        return 'Unknown';
    }

    private function determineTargetId(Request $request): ?int
    {
        // Try to find an ID in the route parameters
        $route = $request->route();
        if (!$route) return null;
        
        foreach ($route->parameters() as $key => $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
            // If the parameter is a model, get its ID
            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                return $value->getKey();
            }
        }
        return null;
    }
}
