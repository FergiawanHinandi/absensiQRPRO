<?php

namespace App\Services;

use App\Jobs\SendSecurityAlertNotification;
use App\Models\ImmutableSecurityLog;
use App\Models\RefreshToken;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token Hardening Service
 *
 * Implements secure token management with:
 * - Short-lived access tokens (30 min)
 * - Refresh token rotation
 * - Device/IP binding for admin roles
 * - Abuse detection and protection
 */
class TokenHardeningService
{
    /**
     * Access token lifetime in minutes
     */
    public const ACCESS_TOKEN_LIFETIME_MINUTES = 15;

    /**
     * Refresh rate limit: max attempts per minute
     */
    public const REFRESH_RATE_LIMIT = 5;

    public const REFRESH_RATE_WINDOW_MINUTES = 1;

    /**
     * Admin roles that require device/IP binding
     */
    public const BOUND_ROLES = ['super_admin', 'school_admin'];

    protected ?ImmutableSecurityLogService $immutableLogService = null;

    protected ?SecurityAlertService $alertService = null;

    /**
     * Get immutable log service (lazy loaded)
     */
    protected function getImmutableLogService(): ImmutableSecurityLogService
    {
        if ($this->immutableLogService === null) {
            $this->immutableLogService = app(ImmutableSecurityLogService::class);
        }

        return $this->immutableLogService;
    }

    /**
     * Get security alert service (lazy loaded)
     */
    protected function getAlertService(): SecurityAlertService
    {
        if ($this->alertService === null) {
            $this->alertService = app(SecurityAlertService::class);
        }

        return $this->alertService;
    }

    // =========================================================================
    // TOKEN ISSUANCE
    // =========================================================================

    /**
     * Issue new access and refresh tokens for a user (login flow).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function issueTokens(User $user, Request $request, string $tokenName = 'auth'): array
    {
        $deviceInfo = $this->extractDeviceInfo($request);
        $isAdmin = $this->isAdminRole($user);

        // Check for new device/country for admins
        if ($isAdmin) {
            $this->checkNewDeviceOrLocation($user, $deviceInfo);
        }

        return DB::transaction(function () use ($user, $deviceInfo, $tokenName, $isAdmin) {
            // Create short-lived access token
            $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_LIFETIME_MINUTES);

            $accessToken = $user->createToken($tokenName, ['*'], $expiresAt);

            // Add device binding to access token for admins
            if ($isAdmin) {
                $this->bindAccessToken($accessToken->accessToken, $deviceInfo);
            }

            // Create refresh token
            $refreshTokenData = RefreshToken::createForUser(
                $user,
                $accessToken->accessToken->id,
                $deviceInfo
            );

            return [
                'access_token' => $accessToken->plainTextToken,
                'refresh_token' => $refreshTokenData['plaintext'],
                'expires_in' => self::ACCESS_TOKEN_LIFETIME_MINUTES * 60,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    /**
     * Bind device/IP info to an access token.
     */
    protected function bindAccessToken(PersonalAccessToken $token, array $deviceInfo): void
    {
        $token->update([
            'device_fingerprint' => $deviceInfo['fingerprint'],
            'initial_ip' => $deviceInfo['ip'],
            'initial_country' => $deviceInfo['country'],
            'platform' => $deviceInfo['platform'],
            'expires_at' => now()->addMinutes(self::ACCESS_TOKEN_LIFETIME_MINUTES),
        ]);
    }

    // =========================================================================
    // TOKEN REFRESH
    // =========================================================================

    /**
     * Refresh tokens using a valid refresh token.
     * Implements rotation: old refresh token is revoked, new one issued.
     *
     * @throws \Exception If refresh token is invalid or rate limited
     */
    public function refreshTokens(string $refreshTokenPlaintext, Request $request): array
    {
        $refreshToken = RefreshToken::findByPlaintext($refreshTokenPlaintext);

        if (! $refreshToken) {
            throw new \Exception('Invalid refresh token', 401);
        }

        $user = $refreshToken->user;

        // Check rate limiting
        if ($this->isRefreshRateLimited($user->id)) {
            $this->handleRefreshAbuse($user, $refreshToken, $request);
            throw new \Exception('Too many refresh attempts. All tokens have been revoked.', 429);
        }

        // Validate token state
        if ($refreshToken->isRevoked()) {
            // Potential token reuse attack - revoke all tokens
            $this->handleTokenReuseAttack($user, $refreshToken, $request);
            throw new \Exception('Refresh token has been revoked', 401);
        }

        if ($refreshToken->isExpired()) {
            throw new \Exception('Refresh token has expired', 401);
        }

        if ($refreshToken->hasReachedRotationLimit()) {
            $refreshToken->revoke(RefreshToken::REVOKED_EXPIRED);
            throw new \Exception('Session limit reached. Please log in again.', 401);
        }

        // Validate device/IP binding for admin roles
        $deviceInfo = $this->extractDeviceInfo($request);
        $isAdmin = $this->isAdminRole($user);

        if ($isAdmin) {
            $this->validateBinding($refreshToken, $deviceInfo, $user);
        }

        // Increment rate limit counter
        $this->incrementRefreshCounter($user->id);

        return DB::transaction(function () use ($refreshToken, $user, $deviceInfo, $isAdmin) {
            // Revoke old refresh token
            $refreshToken->revoke(RefreshToken::REVOKED_ROTATION);

            // Create new access token
            $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_LIFETIME_MINUTES);
            $accessToken = $user->createToken('auth', ['*'], $expiresAt);

            if ($isAdmin) {
                $this->bindAccessToken($accessToken->accessToken, $deviceInfo);
            }

            // Create new refresh token (rotation)
            $newRefreshTokenData = RefreshToken::createForUser(
                $user,
                $accessToken->accessToken->id,
                $deviceInfo,
                $refreshToken->id,
                $refreshToken->rotation_count + 1
            );

            Log::channel('security')->info('Token refreshed', [
                'user_id' => $user->id,
                'rotation_count' => $newRefreshTokenData['token']->rotation_count,
            ]);

            return [
                'access_token' => $accessToken->plainTextToken,
                'refresh_token' => $newRefreshTokenData['plaintext'],
                'expires_in' => self::ACCESS_TOKEN_LIFETIME_MINUTES * 60,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    // =========================================================================
    // TOKEN VALIDATION
    // =========================================================================

    /**
     * Validate access token binding (called by middleware).
     *
     * @throws \Exception If binding validation fails
     */
    public function validateAccessToken(PersonalAccessToken $token, Request $request): void
    {
        $user = $token->tokenable;

        if (! $this->isAdminRole($user)) {
            return; // Non-admin tokens don't need binding validation
        }

        // Check expiration
        if ($token->expires_at && $token->expires_at->isPast()) {
            throw new \Exception('Access token has expired', 401);
        }

        // Check device fingerprint
        $currentFingerprint = $this->generateDeviceFingerprint($request);
        if ($token->device_fingerprint && $token->device_fingerprint !== $currentFingerprint) {
            $this->handleDeviceMismatch($user, $token, $request);
            throw new \Exception('Device binding validation failed', 401);
        }

        // Check IP country (only for super_admin and school_admin)
        $currentCountry = $this->getCountryFromIp($request->ip());
        if ($token->initial_country && $currentCountry && $token->initial_country !== $currentCountry) {
            $this->handleCountryChange($user, $token, $request, $currentCountry);
            throw new \Exception('Location binding validation failed', 401);
        }
    }

    /**
     * Validate refresh token binding.
     */
    protected function validateBinding(RefreshToken $token, array $deviceInfo, User $user): void
    {
        // Device fingerprint check
        if ($token->device_fingerprint && $token->device_fingerprint !== $deviceInfo['fingerprint']) {
            $this->handleDeviceMismatchRefresh($user, $token, $deviceInfo);
            $token->revoke(RefreshToken::REVOKED_DEVICE_MISMATCH);
            throw new \Exception('Device mismatch detected', 401);
        }

        // Country change check
        if (
            $token->initial_country && $deviceInfo['country'] &&
            $token->initial_country !== $deviceInfo['country']
        ) {
            $this->handleCountryChangeRefresh($user, $token, $deviceInfo);
            $token->revoke(RefreshToken::REVOKED_IP_COUNTRY_CHANGE);
            throw new \Exception('Location change detected', 401);
        }
    }

    // =========================================================================
    // DEVICE INFO EXTRACTION
    // =========================================================================

    /**
     * Extract device information from request.
     */
    public function extractDeviceInfo(Request $request): array
    {
        $userAgent = $request->userAgent() ?? '';
        $ip = $request->ip();

        return [
            'fingerprint' => $this->generateDeviceFingerprint($request),
            'device_name' => $this->parseDeviceName($userAgent),
            'user_agent' => $userAgent,
            'platform' => $this->detectPlatform($request),
            'ip' => $ip,
            'country' => $this->getCountryFromIp($ip),
        ];
    }

    /**
     * Generate device fingerprint from request.
     */
    public function generateDeviceFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent() ?? '',
            $request->header('Accept-Language', ''),
            $request->header('Accept-Encoding', ''),
            $this->detectPlatform($request),
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Detect platform from request.
     */
    protected function detectPlatform(Request $request): string
    {
        // Check custom header first (mobile apps should send this)
        $platform = $request->header('X-Platform');
        if ($platform && in_array($platform, ['web', 'android', 'ios'])) {
            return $platform;
        }

        // Detect from user agent
        $ua = strtolower($request->userAgent() ?? '');

        if (str_contains($ua, 'okhttp') || str_contains($ua, 'android')) {
            return 'android';
        }
        if (str_contains($ua, 'cfnetwork') || str_contains($ua, 'darwin') || str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) {
            return 'ios';
        }

        return 'web';
    }

    /**
     * Parse device name from user agent.
     */
    protected function parseDeviceName(string $userAgent): string
    {
        // Simplified device name extraction
        if (preg_match('/\(([^)]+)\)/', $userAgent, $matches)) {
            $info = $matches[1];

            // Truncate if too long
            return substr($info, 0, 100);
        }

        return 'Unknown Device';
    }

    /**
     * Get country code from IP using GeoIP.
     */
    protected function getCountryFromIp(?string $ip): ?string
    {
        if (! $ip || $ip === '127.0.0.1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return null; // Skip local IPs
        }

        try {
            // Use Laravel's geoip package if available, or fallback to external service
            if (function_exists('geoip')) {
                return geoip($ip)->iso_code ?? null;
            }

            // Fallback: check cache first
            $cacheKey = "geoip:country:{$ip}";

            return Cache::remember($cacheKey, 3600, function () use ($ip) {
                // Use free GeoIP service as fallback
                $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode");
                if ($response) {
                    $data = json_decode($response, true);

                    return $data['countryCode'] ?? null;
                }

                return null;
            });
        } catch (\Exception $e) {
            Log::warning('GeoIP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    /**
     * Check if refresh attempts are rate limited.
     */
    protected function isRefreshRateLimited(int $userId): bool
    {
        $key = "token:refresh:rate:{$userId}";
        $attempts = (int) Cache::get($key, 0);

        return $attempts >= self::REFRESH_RATE_LIMIT;
    }

    /**
     * Increment refresh counter.
     */
    protected function incrementRefreshCounter(int $userId): void
    {
        $key = "token:refresh:rate:{$userId}";
        $attempts = (int) Cache::get($key, 0);
        Cache::put($key, $attempts + 1, now()->addMinutes(self::REFRESH_RATE_WINDOW_MINUTES));
    }

    // =========================================================================
    // SECURITY INCIDENT HANDLERS
    // =========================================================================

    /**
     * Handle refresh rate limit abuse.
     */
    protected function handleRefreshAbuse(User $user, RefreshToken $token, Request $request): void
    {
        // Revoke ALL tokens for this user
        RefreshToken::revokeAllForUser($user->id, RefreshToken::REVOKED_ABUSE);
        $user->tokens()->delete();

        // Log immutable security event
        $this->getImmutableLogService()->write(
            ImmutableSecurityLog::TYPE_ADMIN_ACTION,
            'Token refresh abuse detected - all tokens revoked',
            $user->id,
            $user->school_id,
            [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'action' => 'refresh_abuse',
            ]
        );

        // Create security alert
        $this->getAlertService()->createAlert(
            'token_refresh_abuse',
            SecurityAlert::SEVERITY_HIGH,
            "Token refresh abuse detected for user {$user->email}. All tokens revoked.",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ],
            $user->id,
            $user->school_id,
            $request->ip(),
            null,
            true
        );

        Log::channel('security')->critical('Token refresh abuse detected', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Handle potential token reuse attack.
     */
    protected function handleTokenReuseAttack(User $user, RefreshToken $token, Request $request): void
    {
        // Revoke ALL tokens - potential session hijacking
        RefreshToken::revokeAllForUser($user->id, RefreshToken::REVOKED_ABUSE);
        $user->tokens()->delete();

        $this->getImmutableLogService()->write(
            ImmutableSecurityLog::TYPE_ADMIN_ACTION,
            'Revoked refresh token reuse detected - potential session hijacking',
            $user->id,
            $user->school_id,
            [
                'ip' => $request->ip(),
                'token_revoked_at' => $token->revoked_at?->toIso8601String(),
                'token_revoked_reason' => $token->revoked_reason,
                'action' => 'token_reuse_attack',
            ]
        );

        $this->getAlertService()->createAlert(
            'token_reuse_attack',
            SecurityAlert::SEVERITY_CRITICAL,
            "⚠️ POTENTIAL SESSION HIJACKING: Revoked refresh token reuse for {$user->email}",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'original_revoke_reason' => $token->revoked_reason,
            ],
            $user->id,
            $user->school_id,
            $request->ip(),
            null,
            true
        );

        Log::channel('security')->critical('Token reuse attack detected', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Handle device fingerprint mismatch for access token.
     */
    protected function handleDeviceMismatch(User $user, PersonalAccessToken $token, Request $request): void
    {
        // Revoke this token
        $token->delete();

        // Revoke associated refresh tokens
        RefreshToken::where('personal_access_token_id', $token->id)
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => RefreshToken::REVOKED_DEVICE_MISMATCH,
            ]);

        $this->getImmutableLogService()->write(
            ImmutableSecurityLog::TYPE_DEVICE_MISMATCH,
            'Device fingerprint mismatch - admin token revoked',
            $user->id,
            $user->school_id,
            [
                'ip' => $request->ip(),
                'expected_fingerprint' => substr($token->device_fingerprint, 0, 16) . '...',
                'actual_fingerprint' => substr($this->generateDeviceFingerprint($request), 0, 16) . '...',
            ]
        );

        $this->getAlertService()->createAlert(
            SecurityAlertService::TYPE_UNAPPROVED_DEVICE,
            SecurityAlert::SEVERITY_HIGH,
            "Device mismatch for admin {$user->email} - token revoked",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ],
            $user->id,
            $user->school_id,
            $request->ip()
        );
    }

    /**
     * Handle IP country change for access token.
     */
    protected function handleCountryChange(
        User $user,
        PersonalAccessToken $token,
        Request $request,
        string $newCountry
    ): void {
        // Revoke this token
        $token->delete();

        // Revoke associated refresh tokens
        RefreshToken::where('personal_access_token_id', $token->id)
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => RefreshToken::REVOKED_IP_COUNTRY_CHANGE,
            ]);

        $this->getImmutableLogService()->write(
            ImmutableSecurityLog::TYPE_ADMIN_ACTION,
            'IP country change detected - admin token revoked',
            $user->id,
            $user->school_id,
            [
                'ip' => $request->ip(),
                'original_country' => $token->initial_country,
                'new_country' => $newCountry,
                'action' => 'country_change_revoke',
            ]
        );

        $this->getAlertService()->createAlert(
            SecurityAlertService::TYPE_IMPOSSIBLE_TRAVEL,
            SecurityAlert::SEVERITY_HIGH,
            "Country change for admin {$user->email}: {$token->initial_country} → {$newCountry}",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'original_country' => $token->initial_country,
                'new_country' => $newCountry,
                'ip' => $request->ip(),
            ],
            $user->id,
            $user->school_id,
            $request->ip(),
            null,
            true
        );
    }

    /**
     * Handle device mismatch for refresh token.
     */
    protected function handleDeviceMismatchRefresh(User $user, RefreshToken $token, array $deviceInfo): void
    {
        $this->getImmutableLogService()->write(
            ImmutableSecurityLog::TYPE_DEVICE_MISMATCH,
            'Device mismatch on token refresh - potential token theft',
            $user->id,
            $user->school_id,
            [
                'ip' => $deviceInfo['ip'],
                'original_fingerprint' => substr($token->device_fingerprint, 0, 16) . '...',
                'current_fingerprint' => substr($deviceInfo['fingerprint'], 0, 16) . '...',
            ]
        );

        $this->getAlertService()->createAlert(
            SecurityAlertService::TYPE_UNAPPROVED_DEVICE,
            SecurityAlert::SEVERITY_HIGH,
            "Device mismatch on token refresh for {$user->email}",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $deviceInfo['ip'],
            ],
            $user->id,
            $user->school_id,
            $deviceInfo['ip'],
            null,
            true
        );
    }

    /**
     * Handle country change for refresh token.
     */
    protected function handleCountryChangeRefresh(User $user, RefreshToken $token, array $deviceInfo): void
    {
        $this->getImmutableLogService()->write(
            ImmutableSecurityLog::TYPE_ADMIN_ACTION,
            'Country change on token refresh - session terminated',
            $user->id,
            $user->school_id,
            [
                'ip' => $deviceInfo['ip'],
                'original_country' => $token->initial_country,
                'current_country' => $deviceInfo['country'],
                'action' => 'country_change_refresh',
            ]
        );

        $this->getAlertService()->createAlert(
            SecurityAlertService::TYPE_IMPOSSIBLE_TRAVEL,
            SecurityAlert::SEVERITY_HIGH,
            "Country change on refresh for {$user->email}: {$token->initial_country} → {$deviceInfo['country']}",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'original_country' => $token->initial_country,
                'new_country' => $deviceInfo['country'],
                'ip' => $deviceInfo['ip'],
            ],
            $user->id,
            $user->school_id,
            $deviceInfo['ip'],
            null,
            true
        );
    }

    /**
     * Check for new device or location on login.
     */
    protected function checkNewDeviceOrLocation(User $user, array $deviceInfo): void
    {
        // Check if this device fingerprint has been seen before
        $knownDevice = RefreshToken::forUser($user->id)
            ->byDevice($deviceInfo['fingerprint'])
            ->exists();

        // Check if this country has been seen before
        $knownCountry = $deviceInfo['country'] ? RefreshToken::forUser($user->id)
            ->where('initial_country', $deviceInfo['country'])
            ->exists() : true;

        if (! $knownDevice || ! $knownCountry) {
            $this->sendNewDeviceAlert($user, $deviceInfo, ! $knownDevice, ! $knownCountry);
        }
    }

    /**
     * Send alert for new device/country login.
     */
    protected function sendNewDeviceAlert(User $user, array $deviceInfo, bool $newDevice, bool $newCountry): void
    {
        $reasons = [];
        if ($newDevice) {
            $reasons[] = 'new device';
        }
        if ($newCountry) {
            $reasons[] = "new country ({$deviceInfo['country']})";
        }

        $this->getAlertService()->createAlert(
            'admin_new_device_login',
            SecurityAlert::SEVERITY_MEDIUM,
            'Admin login from ' . implode(' and ', $reasons) . ": {$user->email}",
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $deviceInfo['ip'],
                'country' => $deviceInfo['country'],
                'device_name' => $deviceInfo['device_name'],
                'platform' => $deviceInfo['platform'],
                'new_device' => $newDevice,
                'new_country' => $newCountry,
            ],
            $user->id,
            $user->school_id,
            $deviceInfo['ip']
        );

        // Create security alert for new device/country login
        $alert = \App\Models\SecurityAlert::createAlert([
            'school_id' => $user->school_id,
            'type' => 'new_login_detected',
            'severity' => 'medium',
            'description' => "New login detected from {$deviceInfo['device_name']} ({$deviceInfo['ip']})",
            'ip_address' => $deviceInfo['ip'],
        ]);

        // Send notification using the alert object
        if ($alert->shouldNotify()) {
            SendSecurityAlertNotification::dispatch($alert)->onQueue('notifications');
        }

        Log::channel('security')->info('New device/country login for admin', [
            'user_id' => $user->id,
            'new_device' => $newDevice,
            'new_country' => $newCountry,
            'country' => $deviceInfo['country'],
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if user has admin role that requires binding.
     */
    public function isAdminRole(User $user): bool
    {
        return in_array($user->role_type, self::BOUND_ROLES);
    }

    /**
     * Revoke all tokens for a user (used on password change).
     */
    public function revokeAllTokensForUser(User $user, string $reason = RefreshToken::REVOKED_MANUAL): void
    {
        DB::transaction(function () use ($user, $reason) {
            // Revoke all refresh tokens
            RefreshToken::revokeAllForUser($user->id, $reason);

            // Revoke all Sanctum access tokens
            $user->tokens()->delete();
        });

        Log::channel('security')->info('All tokens revoked for user', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Get active sessions for a user.
     */
    public function getActiveSessions(User $user): array
    {
        $refreshTokens = RefreshToken::forUser($user->id)
            ->valid()
            ->with('accessToken')
            ->orderByDesc('last_used_at')
            ->get();

        return $refreshTokens->map(function ($token) {
            return [
                'id' => $token->id,
                'device_name' => $token->device_name,
                'platform' => $token->platform,
                'ip' => $token->last_ip ?? $token->initial_ip,
                'country' => $token->last_country ?? $token->initial_country,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'is_current' => false, // Will be set by controller
            ];
        })->toArray();
    }

    /**
     * Revoke a specific session.
     */
    public function revokeSession(User $user, int $refreshTokenId): bool
    {
        $token = RefreshToken::forUser($user->id)
            ->where('id', $refreshTokenId)
            ->first();

        if (! $token) {
            return false;
        }

        $token->revoke(RefreshToken::REVOKED_MANUAL);

        return true;
    }
}
