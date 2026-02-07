<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\TokenHardeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ActiveSessionsController
 *
 * Provides endpoints for viewing and managing active sessions.
 * Allows users to see all their active sessions and revoke specific ones.
 * Super admins can view and manage sessions for any user.
 */
class ActiveSessionsController extends Controller
{
    public function __construct(
        private readonly TokenHardeningService $tokenService,
        private readonly AdminAuditService $auditService
    ) {}

    /**
     * List all active sessions for the authenticated user.
     *
     * GET /api/v1/auth/sessions
     *
     * Returns list of active refresh tokens with device info and location.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $sessions = $this->getSessionsForUser($user, $request);

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $sessions,
                'total' => count($sessions),
                'current_session_id' => $this->getCurrentSessionId($request),
            ],
        ]);
    }

    /**
     * List all active sessions for a specific user (super_admin only).
     *
     * GET /api/v1/admin/security-dashboard/users/{userId}/sessions
     */
    public function indexForUser(Request $request, int $userId): JsonResponse
    {
        $authUser = Auth::user();

        // Only super_admin can view other users' sessions
        if ($authUser->role_type !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view other users\' sessions',
            ], 403);
        }

        $targetUser = User::find($userId);

        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $sessions = $this->getSessionsForUser($targetUser, $request);

        // Log admin viewing user sessions
        $this->auditService->log(
            action: 'view_user_sessions',
            adminId: $authUser->id,
            targetType: 'user',
            targetId: $userId,
            changes: ['session_count' => count($sessions)],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'role_type' => $targetUser->role_type,
                ],
                'sessions' => $sessions,
                'total' => count($sessions),
            ],
        ]);
    }

    /**
     * Revoke a specific session.
     *
     * DELETE /api/v1/auth/sessions/{sessionId}
     */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $user = Auth::user();

        $refreshToken = RefreshToken::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->first();

        if (! $refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found',
            ], 404);
        }

        // Check if trying to revoke current session
        $currentSessionId = $this->getCurrentSessionId($request);
        if ($sessionId === $currentSessionId) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revoke current session. Use logout instead.',
                'code' => 'CANNOT_REVOKE_CURRENT',
            ], 400);
        }

        // Revoke the refresh token
        $refreshToken->revoke(RefreshToken::REASON_USER_REVOKED);

        // Also revoke associated access tokens with matching device fingerprint
        $user->tokens()
            ->where('device_fingerprint', $refreshToken->device_fingerprint)
            ->delete();

        Log::channel('security')->info('User revoked session', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'device_fingerprint' => $refreshToken->device_fingerprint,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session revoked successfully',
        ]);
    }

    /**
     * Revoke a specific session for any user (super_admin only).
     *
     * DELETE /api/v1/admin/security-dashboard/sessions/{sessionId}
     */
    public function adminDestroy(Request $request, string $sessionId): JsonResponse
    {
        $authUser = Auth::user();

        if ($authUser->role_type !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $refreshToken = RefreshToken::with('user')->find($sessionId);

        if (! $refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found',
            ], 404);
        }

        $targetUser = $refreshToken->user;

        // Revoke the refresh token
        $refreshToken->revoke(RefreshToken::REASON_ADMIN_REVOKED);

        // Also revoke associated access tokens
        $targetUser->tokens()
            ->where('device_fingerprint', $refreshToken->device_fingerprint)
            ->delete();

        // Log admin action
        $this->auditService->log(
            action: 'revoke_user_session',
            adminId: $authUser->id,
            targetType: 'session',
            targetId: $sessionId,
            changes: [
                'user_id' => $targetUser->id,
                'user_email' => $targetUser->email,
                'device_fingerprint' => $refreshToken->device_fingerprint,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        Log::channel('security')->warning('Admin revoked user session', [
            'admin_id' => $authUser->id,
            'target_user_id' => $targetUser->id,
            'session_id' => $sessionId,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session revoked successfully',
        ]);
    }

    /**
     * Revoke all sessions except current (for authenticated user).
     *
     * POST /api/v1/auth/sessions/revoke-others
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $user = Auth::user();
        $currentRefreshToken = $this->getCurrentRefreshToken($request);

        // Count sessions to revoke
        $sessionsToRevoke = RefreshToken::where('user_id', $user->id)
            ->active()
            ->when($currentRefreshToken, function ($query) use ($currentRefreshToken) {
                $query->where('id', '!=', $currentRefreshToken->id);
            })
            ->count();

        // Revoke all other refresh tokens
        RefreshToken::where('user_id', $user->id)
            ->active()
            ->when($currentRefreshToken, function ($query) use ($currentRefreshToken) {
                $query->where('id', '!=', $currentRefreshToken->id);
            })
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => RefreshToken::REASON_USER_REVOKED,
            ]);

        // Revoke all access tokens except current
        $currentToken = $request->user()->currentAccessToken();
        if ($currentToken) {
            $user->tokens()
                ->where('id', '!=', $currentToken->id)
                ->delete();
        }

        Log::channel('security')->info('User revoked all other sessions', [
            'user_id' => $user->id,
            'sessions_revoked' => $sessionsToRevoke,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Revoked {$sessionsToRevoke} other session(s)",
            'data' => [
                'revoked_count' => $sessionsToRevoke,
            ],
        ]);
    }

    /**
     * Revoke all sessions for a specific user (super_admin only).
     *
     * POST /api/v1/admin/security-dashboard/users/{userId}/sessions/revoke-all
     */
    public function adminRevokeAll(Request $request, int $userId): JsonResponse
    {
        $authUser = Auth::user();

        if ($authUser->role_type !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $targetUser = User::find($userId);

        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Count sessions to revoke
        $sessionCount = RefreshToken::where('user_id', $userId)
            ->active()
            ->count();

        // Revoke all refresh tokens
        RefreshToken::revokeAllForUser($userId, RefreshToken::REASON_ADMIN_REVOKED);

        // Revoke all access tokens
        $targetUser->tokens()->delete();

        // Log admin action
        $this->auditService->log(
            action: 'revoke_all_user_sessions',
            adminId: $authUser->id,
            targetType: 'user',
            targetId: $userId,
            changes: [
                'user_email' => $targetUser->email,
                'sessions_revoked' => $sessionCount,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        Log::channel('security')->warning('Admin revoked all sessions for user', [
            'admin_id' => $authUser->id,
            'target_user_id' => $userId,
            'sessions_revoked' => $sessionCount,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Revoked {$sessionCount} session(s) for user",
            'data' => [
                'revoked_count' => $sessionCount,
            ],
        ]);
    }

    /**
     * Get session security overview for super_admin dashboard.
     *
     * GET /api/v1/admin/security-dashboard/sessions/overview
     */
    public function overview(Request $request): JsonResponse
    {
        $authUser = Auth::user();

        if ($authUser->role_type !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $schoolId = $request->query('school_id');

        // Get active sessions count
        $activeSessionsQuery = RefreshToken::active()
            ->with('user:id,school_id,role_type');

        if ($schoolId) {
            $activeSessionsQuery->whereHas('user', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            });
        }

        $activeSessions = $activeSessionsQuery->get();

        // Group by user role
        $byRole = $activeSessions->groupBy(fn ($s) => $s->user?->role_type ?? 'unknown')
            ->map->count();

        // Get unique countries
        $countries = $activeSessions->pluck('initial_country')
            ->filter()
            ->unique()
            ->values();

        // Get sessions with suspicious activity (multiple countries)
        $userSessions = $activeSessions->groupBy('user_id');
        $suspiciousUsers = $userSessions->filter(function ($sessions) {
            return $sessions->pluck('initial_country')
                ->filter()
                ->unique()
                ->count() > 1;
        })->count();

        // Get recently rotated tokens (potential replay attacks)
        $highRotationCount = RefreshToken::active()
            ->where('rotation_count', '>', 10)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_active_sessions' => $activeSessions->count(),
                'sessions_by_role' => $byRole,
                'unique_countries' => $countries,
                'suspicious_multi_country_users' => $suspiciousUsers,
                'high_rotation_sessions' => $highRotationCount,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get sessions for a user with enriched data.
     */
    private function getSessionsForUser(User $user, Request $request): array
    {
        $refreshTokens = RefreshToken::where('user_id', $user->id)
            ->active()
            ->orderBy('last_used_at', 'desc')
            ->get();

        $currentSessionId = $this->getCurrentSessionId($request);

        return $refreshTokens->map(function ($token) use ($currentSessionId) {
            $location = $this->getLocationFromIp($token->initial_ip);

            return [
                'id' => $token->id,
                'device' => $this->parseDeviceInfo($token->device_fingerprint),
                'platform' => $token->platform,
                'ip_address' => $this->maskIpAddress($token->initial_ip),
                'location' => $location,
                'country' => $token->initial_country,
                'created_at' => $token->created_at->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at->toIso8601String(),
                'rotation_count' => $token->rotation_count,
                'is_current' => $token->id === $currentSessionId,
            ];
        })->toArray();
    }

    /**
     * Get the current session ID from the request.
     */
    private function getCurrentSessionId(Request $request): ?string
    {
        $refreshToken = $this->getCurrentRefreshToken($request);

        return $refreshToken?->id;
    }

    /**
     * Get the current refresh token from request.
     */
    private function getCurrentRefreshToken(Request $request): ?RefreshToken
    {
        // Try to get from cookie first (web)
        $tokenValue = $request->cookie('refresh_token');

        // Fall back to header (mobile)
        if (! $tokenValue) {
            $tokenValue = $request->header('X-Refresh-Token');
        }

        if (! $tokenValue) {
            return null;
        }

        return RefreshToken::findByPlaintext($tokenValue);
    }

    /**
     * Get location info from IP address.
     */
    private function getLocationFromIp(?string $ip): ?array
    {
        if (! $ip || $ip === '127.0.0.1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return [
                'city' => 'Local Network',
                'region' => null,
                'country' => 'Local',
            ];
        }

        $cacheKey = "geoip_location_{$ip}";

        return Cache::remember($cacheKey, 86400, function () use ($ip) {
            try {
                $response = Http::timeout(3)
                    ->get("http://ip-api.com/json/{$ip}", [
                        'fields' => 'status,city,regionName,country',
                    ]);

                if ($response->successful() && $response->json('status') === 'success') {
                    return [
                        'city' => $response->json('city'),
                        'region' => $response->json('regionName'),
                        'country' => $response->json('country'),
                    ];
                }
            } catch (\Exception $e) {
                Log::debug('GeoIP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            }

            return null;
        });
    }

    /**
     * Parse device info from fingerprint (for display purposes).
     */
    private function parseDeviceInfo(?string $fingerprint): array
    {
        // The fingerprint is a hash, so we can't decode it.
        // We could store UA separately, but for now return placeholder.
        // In production, consider storing parsed UA data alongside fingerprint.

        return [
            'fingerprint_short' => $fingerprint ? substr($fingerprint, 0, 12).'...' : 'Unknown',
            'type' => 'unknown', // Would need to store UA separately
        ];
    }

    /**
     * Mask IP address for privacy (show only first parts).
     */
    private function maskIpAddress(?string $ip): string
    {
        if (! $ip) {
            return 'Unknown';
        }

        // For IPv4: show first two octets
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.xxx.xxx';
        }

        // For IPv6: show first segment
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);

            return $parts[0].':xxxx:xxxx:xxxx';
        }

        return 'Unknown';
    }
}
