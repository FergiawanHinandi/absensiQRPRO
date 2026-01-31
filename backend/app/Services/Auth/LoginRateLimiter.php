<?php

namespace App\Services\Auth;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CRITICAL: Advanced Login Rate Limiting
 *
 * Features:
 * - IP + Email combination limiting
 * - Progressive delay after repeated failures
 * - Account lockout after threshold
 * - Comprehensive security logging
 */
class LoginRateLimiter
{
    protected RateLimiter $limiter;

    // Rate limiting thresholds
    protected const MAX_ATTEMPTS = 5;           // Max attempts before progressive delay
    protected const LOCKOUT_ATTEMPTS = 10;      // Attempts before account lockout
    protected const LOCKOUT_DURATION = 10;      // Minutes for account lockout
    protected const DECAY_MINUTES = 10;         // Time window for counting attempts

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Check if too many login attempts have been made
     */
    public function tooManyAttempts(Request $request, string $email): bool
    {
        $key = $this->throttleKey($request, $email);

        return $this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS);
    }

    /**
     * Increment login attempts and apply progressive delay
     */
    public function hit(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);
        $attempts = $this->limiter->attempts($key);

        // Increment attempt counter
        $this->limiter->hit($key, self::DECAY_MINUTES * 60);

        // Log the failed attempt with details
        $this->logFailedAttempt($request, $email, $attempts + 1);

        // Apply progressive delay based on attempts
        $this->applyProgressiveDelay($attempts);
    }

    /**
     * Clear login attempts for successful login
     */
    public function clear(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);
        $this->limiter->clear($key);
    }

    /**
     * Get remaining seconds before retry is allowed
     */
    public function availableIn(Request $request, string $email): int
    {
        $key = $this->throttleKey($request, $email);

        return $this->limiter->availableIn($key);
    }

    /**
     * Get current attempt count
     */
    public function attempts(Request $request, string $email): int
    {
        $key = $this->throttleKey($request, $email);

        return $this->limiter->attempts($key);
    }

    /**
     * Check if account should be locked
     */
    public function shouldLockAccount(Request $request, string $email): bool
    {
        $attempts = $this->attempts($request, $email);

        return $attempts >= self::LOCKOUT_ATTEMPTS;
    }

    /**
     * Lock user account temporarily
     */
    public function lockAccount(\App\Models\User $user): void
    {
        $lockedUntil = now()->addMinutes(self::LOCKOUT_DURATION);

        $user->update([
            'locked_until' => $lockedUntil,
            'failed_login_attempts' => $user->failed_login_attempts + 1,
            'last_failed_login_at' => now(),
        ]);

        Log::warning('Account locked due to excessive failed login attempts', [
            'user_id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'locked_until' => $lockedUntil->toDateTimeString(),
            'total_failed_attempts' => $user->failed_login_attempts + 1,
        ]);
    }

    /**
     * Record failed login attempt on user model
     */
    public function recordFailedAttempt(\App\Models\User $user): void
    {
        $user->increment('failed_login_attempts');
        $user->update(['last_failed_login_at' => now()]);
    }

    /**
     * Clear failed attempts on successful login
     */
    public function clearAccountAttempts(\App\Models\User $user): void
    {
        $user->update([
            'failed_login_attempts' => 0,
            'last_failed_login_at' => null,
            'locked_until' => null,
        ]);
    }

    /**
     * Check if account is currently locked
     */
    public function isAccountLocked(\App\Models\User $user): bool
    {
        if (! $user->locked_until) {
            return false;
        }

        // Check if lock has expired
        if (now()->greaterThan($user->locked_until)) {
            $user->update(['locked_until' => null]);

            return false;
        }

        return true;
    }

    /**
     * Get lockout remaining time in seconds
     */
    public function lockoutRemainingSeconds(\App\Models\User $user): int
    {
        if (! $user->locked_until) {
            return 0;
        }

        return max(0, now()->diffInSeconds($user->locked_until, false));
    }

    /**
     * Generate throttle key (IP + Email combination)
     */
    protected function throttleKey(Request $request, string $email): string
    {
        return Str::transliterate(
            Str::lower($email).'|'.$request->ip()
        );
    }

    /**
     * Apply progressive delay based on failed attempts
     * Slows down brute force attacks
     */
    protected function applyProgressiveDelay(int $attempts): void
    {
        if ($attempts < 3) {
            return; // No delay for first 2 attempts
        }

        // Progressive delay: 2^(attempts-2) seconds
        // 3rd attempt: 2s, 4th: 4s, 5th: 8s, 6th: 16s, etc.
        $delay = min(pow(2, $attempts - 2), 60); // Cap at 60 seconds

        usleep($delay * 1000000); // Convert to microseconds
    }

    /**
     * Log failed login attempt with comprehensive details
     */
    protected function logFailedAttempt(Request $request, string $email, int $attempts): void
    {
        Log::warning('Failed login attempt', [
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'attempts' => $attempts,
            'max_attempts' => self::MAX_ATTEMPTS,
            'lockout_threshold' => self::LOCKOUT_ATTEMPTS,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Create audit log entry if user exists
        try {
            $user = \App\Models\User::where('email', $email)
                ->orWhere('username', $email)
                ->first();

            if ($user) {
                \App\Models\AuditLog::create([
                    'user_id' => $user->id,
                    'school_id' => $user->school_id,
                    'action' => 'failed_login',
                    'description' => "Failed login attempt #{$attempts}. IP: {$request->ip()}",
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create audit log for failed login', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
