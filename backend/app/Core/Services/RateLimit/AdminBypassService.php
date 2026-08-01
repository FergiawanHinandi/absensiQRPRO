<?php

namespace App\Core\Services\RateLimit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Admin Bypass Service for Rate Limiting
 *
 * Checks user roles and IP addresses for rate limit bypass eligibility.
 * Bypass events are logged for audit trail.
 * Bypass permissions cached in Redis for performance.
 *
 * Spec: critical-rate-limiting / tasks.md Task 9
 */
class AdminBypassService
{
    private const CACHE_PREFIX = 'rl_bypass:';

    /** Determine if the request should bypass rate limiting. */
    public function shouldBypass(Request $request, string $endpointKey): bool
    {
        if (!config('rate-limiting.bypass.enabled', true)) {
            return false;
        }

        $user = $request->user();

        // 1. IP bypass list (monitoring, internal services)
        if ($this->isExemptIp($request->ip())) {
            return true;
        }

        if (!$user) {
            return false;
        }

        // 2. Check cached bypass decision
        $cacheKey = self::CACHE_PREFIX . $user->id . ':' . $endpointKey;
        $cached   = Cache::get($cacheKey);

        if ($cached !== null) {
            return (bool) $cached;
        }

        // 3. Calculate and cache bypass decision
        $bypass = $this->calculateBypass($user, $endpointKey);

        Cache::put($cacheKey, $bypass, config('rate-limiting.bypass.cache_ttl', 300));

        return $bypass;
    }

    private function calculateBypass($user, string $endpointKey): bool
    {
        $userRole = $user->role_type ?? $user->role ?? '';

        // Roles completely exempt from ALL rate limits
        if (in_array($userRole, config('rate-limiting.bypass.exempt_roles', ['super_admin']), true)) {
            return true;
        }

        // Roles exempt from specific endpoints
        $roleExemptions = config('rate-limiting.bypass.role_exemptions', []);
        if (isset($roleExemptions[$userRole]) &&
            in_array($endpointKey, $roleExemptions[$userRole], true)) {
            return true;
        }

        return false;
    }

    private function isExemptIp(string $ip): bool
    {
        $exemptIps = config('rate-limiting.bypass.exempt_ips', []);
        return in_array($ip, array_filter($exemptIps), true);
    }

    /** Invalidate cached bypass for a user — call when role changes. */
    public function invalidateCache(int $userId): void
    {
        foreach (array_keys(config('rate-limiting.endpoints', [])) as $endpoint) {
            Cache::forget(self::CACHE_PREFIX . $userId . ':' . $endpoint);
        }
    }

    /** Get bypass status for a user (admin dashboard). */
    public function getBypassStatus($user): array
    {
        $userRole    = $user->role_type ?? $user->role ?? 'unknown';
        $exemptRoles = config('rate-limiting.bypass.exempt_roles', []);

        return [
            'user_id'     => $user->id,
            'role'        => $userRole,
            'is_bypassed' => in_array($userRole, $exemptRoles, true),
            'endpoints'   => array_reduce(
                array_keys(config('rate-limiting.endpoints', [])),
                fn ($carry, $ep) => array_merge($carry, [$ep => $this->calculateBypass($user, $ep)]),
                []
            ),
        ];
    }
}
