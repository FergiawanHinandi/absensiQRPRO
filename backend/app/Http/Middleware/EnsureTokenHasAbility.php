<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure Sanctum Token Has Required Ability
 *
 * This middleware enforces ability checks on Sanctum API tokens.
 * It is critical for multi-tenant security and role-based access control.
 *
 * Usage:
 *   Route::get('/endpoint', ...)->middleware(['auth:sanctum', 'ability:report:export']);
 *
 * Super admin bypass:
 *   Tokens with '*' ability bypass all checks (super_admin role).
 */
class EnsureTokenHasAbility
{
    /**
     * Handle an incoming request.
     *
     * @param  string  ...$abilities  One or more required abilities (OR logic)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        // Get the authenticated user
        $user = $request->user();

        // If user is not authenticated, return 401
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Get the current access token
        $token = $user->currentAccessToken();

        // If no token (session-based auth), reject
        if (! $token) {
            return response()->json([
                'message' => 'Token required for this endpoint',
            ], 401);
        }

        // Super admin bypass: if token has '*' ability, allow all
        if ($token->can('*')) {
            return $next($request);
        }

        // Check if token has ANY of the required abilities (OR logic)
        foreach ($abilities as $ability) {
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        // Token does not have any of the required abilities
        return response()->json([
            'message' => 'Token does not have required permission',
            'required_abilities' => $abilities,
        ], 403);
    }
}
