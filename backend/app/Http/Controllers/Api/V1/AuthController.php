<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\Auth\LoginRateLimiter;
use App\Services\TokenHardeningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected LoginRateLimiter $rateLimiter;
    protected TokenHardeningService $tokenService;
    protected AdminAuditService $auditService;

    public function __construct(
        LoginRateLimiter $rateLimiter,
        TokenHardeningService $tokenService,
        AdminAuditService $auditService
    ) {
        $this->rateLimiter = $rateLimiter;
        $this->tokenService = $tokenService;
        $this->auditService = $auditService;
    }

    /**
     * Login - Generate Sanctum token with advanced brute force protection
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        // CRITICAL: Check rate limiting BEFORE database query
        $this->ensureIsNotRateLimited($request);

        $user = User::where(function ($query) use ($request) {
            $query->where('username', $request->username)
                ->orWhere('email', $request->username);
        })
            ->where('is_active', true)
            ->with('school') // Load school relation
            ->first();

        // CRITICAL: Check if account is locked
        if ($user && $this->rateLimiter->isAccountLocked($user)) {
            $remainingSeconds = $this->rateLimiter->lockoutRemainingSeconds($user);
            $remainingMinutes = ceil($remainingSeconds / 60);

            throw ValidationException::withMessages([
                'email' => [
                    "Akun Anda telah dikunci karena terlalu banyak percobaan login yang gagal. "
                    ."Silakan coba lagi dalam {$remainingMinutes} menit.",
                ],
            ]);
        }

        // Verify credentials
        if (! $user || ! Hash::check($request->password, $user->password)) {
            // CRITICAL: Record failed attempt ONLY if user exists
            if ($user) {
                $this->rateLimiter->recordFailedAttempt($user);

                // Log to security channel for aggregation
                \Illuminate\Support\Facades\Log::channel('security')->warning('Failed Login Attempt', [
                    'username' => $request->username,
                    'ip' => $request->ip(),
                    'reason' => 'Invalid Password'
                ]);

                // Check if should lock account (after recording attempt)
                $this->rateLimiter->hit($request, $request->username);
                
                if ($this->rateLimiter->shouldLockAccount($request, $request->username)) {
                    $this->rateLimiter->lockAccount($user);
                }
            } else {
                // User doesn't exist, just increment rate limiter
                $this->rateLimiter->hit($request, $request->username);

                // Log to security channel for aggregation
                \Illuminate\Support\Facades\Log::channel('security')->warning('Failed Login Attempt', [
                    'username' => $request->username,
                    'ip' => $request->ip(),
                    'reason' => 'User Not Found'
                ]);
            }

            // CRITICAL: Generic error message (don't reveal which field is wrong)
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang Anda masukkan tidak valid. Silakan periksa kembali.'],
            ]);
        }

        // Check if user's school is active (except for super_admin)
        if ($user->school_id && $user->school) {
            if (! $user->school->is_active) {
                \Illuminate\Support\Facades\Log::warning('Failed login attempt: School inactive', [
                    'user_id' => $user->id,
                    'school_id' => $user->school_id,
                    'ip' => $request->ip(),
                ]);

                throw ValidationException::withMessages([
                    'email' => [
                        'Sekolah Anda sedang tidak aktif. '.
                        'Silakan hubungi administrator platform untuk informasi lebih lanjut. '.
                        'Email: amhyer21091993@gmail.com atau Telepon: 082352538105',
                    ],
                ]);
            }
        }

        // CRITICAL: Clear failed attempts on successful login
        $this->rateLimiter->clear($request, $request->username);
        $this->rateLimiter->clearAccountAttempts($user);

        // Revoke all previous tokens (single device policy for non-mobile)
        // Mobile apps can have multiple sessions, web should be single session
        $platform = $this->tokenService->extractDeviceInfo($request)['platform'];
        if ($platform === 'web') {
            $user->tokens()->delete();
            RefreshToken::revokeAllForUser($user->id, RefreshToken::REVOKED_LOGOUT);
        }

        // Issue hardened tokens (short-lived access + refresh token)
        $tokens = $this->tokenService->issueTokens(
            $user,
            $request,
            $request->device_name ?? 'mobile'
        );

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Log admin login
        if (in_array($user->role_type, ['super_admin', 'school_admin', 'admin'])) {
            $this->auditService->logLogin($user, [
                'ip' => $request->ip(),
                'device' => $this->getDeviceName($request->userAgent()),
                'platform' => $platform,
            ]);
        }

        // --- MANAGED TRUSTED DEVICES ---
        if ($request->filled('device_id')) {
            $deviceId = $request->device_id;
            $deviceName = $request->device_name ?? $this->getDeviceName($request->userAgent());
            $platform = $request->platform ?? 'unknown';

            $trustedDevice = \App\Models\TrustedDevice::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->first();

            if (! $trustedDevice) {
                // NEW DEVICE DETECTED
                \Illuminate\Support\Facades\Log::channel('security')->warning('New Device Login Detected', [
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'device_name' => $deviceName,
                    'ip' => $request->ip(),
                ]);

                // Register new device
                \App\Models\TrustedDevice::create([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'device_name' => $deviceName,
                    'platform' => $platform,
                    'last_used_at' => now(),
                    'is_trusted' => true,
                ]);

                // Limit max 3 active devices (Prune oldest)
                $deviceCount = \App\Models\TrustedDevice::where('user_id', $user->id)->count();
                if ($deviceCount > 3) {
                    \App\Models\TrustedDevice::where('user_id', $user->id)
                        ->orderBy('last_used_at', 'asc')
                        ->first()
                        ->delete();
                }
            } else {
                // KNOWN DEVICE - Update usage
                $trustedDevice->update([
                    'last_used_at' => now(),
                    'device_name' => $deviceName, // Update name if changed
                ]);
            }
        }

        // Strict Device Binding for Teachers (Legacy Logic - Keep for attendance security)
        if ($request->filled('device_id') && $user->role_type === 'teacher') {
            if (! $user->device_id) {
                // First time login: Bind attendance device
                $user->update(['device_id' => $request->device_id]);
            } elseif ($user->device_id !== $request->device_id) {
                // Mismatch: Deny login
                throw ValidationException::withMessages([
                    'device_id' => ['Akun ini sudah terdaftar di perangkat lain. Hubungi admin untuk reset.'],
                ]);
            }
        } elseif ($request->filled('device_id') && empty($user->device_id)) {
             // For students, bind the first device they use as their 'primary' attendance device (optional but good practice)
             $user->update(['device_id' => $request->device_id]);
        }


        // --- SECURITY LOGGING ---
        try {
            $ip = $request->ip();
            $userAgent = $request->userAgent();
            $deviceInfo = $this->getDeviceName($userAgent);

            // Attempt get location (with timeout to prevent login lag)
            $location = $this->getLocation($ip);

            // Check for suspicious logic (New IP)
            $lastLogin = \App\Models\AuditLog::where('user_id', $user->id)
                ->where('action', 'login')
                ->latest()
                ->first();

            $isNewIp = $lastLogin && $lastLogin->ip_address !== $ip;
            $suspiciousFlag = $isNewIp ? '[NEW IP]' : '';

            $description = "Login success via {$deviceInfo}. Location: {$location}. {$suspiciousFlag}";

            \App\Models\AuditLog::create([
                'user_id' => $user->id,
                'school_id' => $user->school_id,
                'action' => 'login',
                'description' => $description,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

        } catch (\Exception $e) {
            // Logging failure should not block login
            \Illuminate\Support\Facades\Log::error('Login logging failed: '.$e->getMessage());
        }
        // ------------------------

        // Build response with tokens
        $response = response()->json([
            'success' => true,
            'data' => [
                'access_token' => $tokens['access_token'],
                'token_type' => $tokens['token_type'],
                'expires_in' => $tokens['expires_in'],
                'expires_at' => $tokens['expires_at'],
                // Refresh token sent in response for mobile, cookie for web
                'refresh_token' => $platform !== 'web' ? $tokens['refresh_token'] : null,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role_type' => $user->role_type,
                    'school_id' => $user->school_id,
                    'school' => $user->school ? [
                        'id' => $user->school->id,
                        'name' => $user->school->name,
                        'school_level' => $user->school->school_level,
                    ] : null,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ],
            ],
        ]);

        // For web platform: Set refresh token in HttpOnly secure cookie
        if ($platform === 'web') {
            $response->withCookie($this->createRefreshTokenCookie($tokens['refresh_token']));
        }

        return $response;
    }

    /**
     * Logout - Revoke current token and refresh token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Log admin logout
        if (in_array($user->role_type, ['super_admin', 'school_admin', 'admin'])) {
            $this->auditService->logLogout($user);
        }

        // Revoke current access token
        $request->user()->currentAccessToken()->delete();

        // Revoke refresh token from cookie or request
        $refreshToken = $request->cookie('refresh_token') ?? $request->input('refresh_token');
        if ($refreshToken) {
            $token = RefreshToken::findByPlaintext($refreshToken);
            if ($token && $token->user_id === $user->id) {
                $token->revoke(RefreshToken::REVOKED_LOGOUT);
            }
        }

        // Clear refresh token cookie for web
        $response = response()->json([
            'success' => true,
            'message' => 'Logout berhasil',
        ]);

        return $response->withCookie(Cookie::forget('refresh_token'));
    }

    /**
     * Get current authenticated user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('school', 'profile', 'roles', 'permissions');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role_type' => $user->role_type,
                'school_id' => $user->school_id,
                'school' => $user->school,
                'profile' => $user->profile,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }

    /**
     * Refresh token using refresh token rotation.
     * 
     * Old refresh token is revoked, new one issued.
     * For web: reads refresh token from HttpOnly cookie.
     * For mobile: reads from request body.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request)
    {
        // Get refresh token from cookie (web) or request body (mobile)
        $refreshTokenPlaintext = $request->cookie('refresh_token') 
            ?? $request->input('refresh_token');

        if (!$refreshTokenPlaintext) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token required',
                'error' => 'missing_refresh_token',
            ], 400);
        }

        try {
            $tokens = $this->tokenService->refreshTokens($refreshTokenPlaintext, $request);
            $platform = $this->tokenService->extractDeviceInfo($request)['platform'];

            $response = response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $tokens['access_token'],
                    'token_type' => $tokens['token_type'],
                    'expires_in' => $tokens['expires_in'],
                    'expires_at' => $tokens['expires_at'],
                    'refresh_token' => $platform !== 'web' ? $tokens['refresh_token'] : null,
                ],
            ]);

            // Update refresh token cookie for web
            if ($platform === 'web') {
                $response->withCookie($this->createRefreshTokenCookie($tokens['refresh_token']));
            }

            return $response;

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 401;
            
            $response = response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'refresh_failed',
                'code' => $statusCode === 429 ? 'RATE_LIMITED' : 'REAUTH_REQUIRED',
            ], $statusCode);

            // Clear invalid refresh token cookie
            return $response->withCookie(Cookie::forget('refresh_token'));
        }
    }

    /**
     * Revoke all other sessions (keep current).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function revokeOtherSessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->id;

        // Find the refresh token for current session
        $currentRefresh = RefreshToken::where('personal_access_token_id', $currentTokenId)->first();
        $currentRefreshId = $currentRefresh?->id;

        // Revoke all other access tokens
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        // Revoke all other refresh tokens
        if ($currentRefreshId) {
            RefreshToken::revokeAllExcept($user->id, $currentRefreshId, RefreshToken::REVOKED_MANUAL);
        }

        return response()->json([
            'success' => true,
            'message' => 'All other sessions have been revoked',
        ]);
    }

    /**
     * Create secure HttpOnly cookie for refresh token.
     */
    protected function createRefreshTokenCookie(string $refreshToken): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(
            'refresh_token',
            $refreshToken,
            RefreshToken::EXPIRATION_DAYS * 24 * 60, // minutes
            '/',
            config('session.domain'),
            config('app.env') === 'production', // Secure in production
            true, // HttpOnly
            false, // Raw
            'Strict' // SameSite
        );
    }

    /**
     * Get abilities for a specific role type
     *
     * @param  string  $roleType
     * @return array
     */
    private function getAbilitiesForRole(string $roleType): array
    {
        // Get abilities from config
        $abilities = config("abilities.role_abilities.{$roleType}", []);

        // If no specific abilities defined, return empty array (no access)
        // Exception: super_admin gets ['*']
        if (empty($abilities)) {
            \Illuminate\Support\Facades\Log::warning("No abilities defined for role: {$roleType}");

            return [];
        }

        return $abilities;
    }

    /**
     * Helper to get simpler device name
     */
    private function getDeviceName($userAgent)
    {
        $os = 'Unknown OS';
        if (preg_match('/windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $os = 'Mac OS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            $os = 'iOS';
        }

        $browser = 'Unknown Browser';
        if (preg_match('/firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/chrome/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/safari/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/edge/i', $userAgent)) {
            $browser = 'Edge';
        }

        return "{$os} - {$browser}";
    }

    /**
     * Helper to get Location from IP
     */
    private function getLocation($ip)
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'Localhost';
        }

        try {
            // Short timeout to avoid lag
            $response = \Illuminate\Support\Facades\Http::timeout(2)->get("http://ip-api.com/json/{$ip}");

            if ($response->successful()) {
                $data = $response->json();
                if (($data['status'] ?? 'fail') === 'success') {
                    return "{$data['city']}, {$data['country']}";
                }
            }
        } catch (\Exception $e) {
            return 'Unknown Location';
        }

        return 'Unknown Location';
    }

    /**
     * Ensure the login request is not rate limited
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function ensureIsNotRateLimited(LoginRequest $request): void
    {
        if (! $this->rateLimiter->tooManyAttempts($request, $request->username)) {
            return;
        }

        $seconds = $this->rateLimiter->availableIn($request, $request->username);
        $minutes = ceil($seconds / 60);

        throw ValidationException::withMessages([
            'email' => [
                "Terlalu banyak percobaan login. Silakan coba lagi dalam {$minutes} menit.",
            ],
        ]);
    }
}
