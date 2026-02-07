<?php

namespace App\Http\Middleware;

use App\Services\TokenHardeningService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate Token Binding Middleware
 *
 * Validates device fingerprint and IP country binding for admin tokens.
 * For super_admin and school_admin roles:
 * - If device fingerprint changes → revoke token, force re-login
 * - If IP country changes → revoke token, force re-login
 * - Logs security events to immutable audit trail
 */
class ValidateTokenBinding
{
    public function __construct(
        protected TokenHardeningService $tokenService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Get the current access token
        $token = $user->currentAccessToken();

        if (! $token || ! ($token instanceof PersonalAccessToken)) {
            return $next($request);
        }

        try {
            // Validate token binding (handles admin checks internally)
            $this->tokenService->validateAccessToken($token, $request);
        } catch (\Exception $e) {
            // Token validation failed - return 401
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'token_binding_failed',
                'code' => 'REAUTH_REQUIRED',
            ], 401);
        }

        // Update last used timestamp
        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
