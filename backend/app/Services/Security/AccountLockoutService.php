<?php

namespace App\Services\Security;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AccountLockoutService
 *
 * Provides progressive account lockout based on failed login attempts:
 * - 5 failures: 1 minute lockout
 * - 10 failures: 5 minute lockout
 * - 15 failures: 15 minute lockout
 * - 20+ failures: 1 hour lockout (requires admin reset)
 *
 * Complements Laravel's built-in rate limiting by locking the account
 * itself rather than just throttling the IP.
 */
class AccountLockoutService
{
    /**
     * Lockout tiers: [failures => lockout_minutes]
     */
    private const LOCKOUT_TIERS = [
        5 => 1,
        10 => 5,
        15 => 15,
        20 => 60,
    ];

    /**
     * Cache prefix for lockout keys.
     */
    private const CACHE_PREFIX = 'account_lockout:';

    /**
     * Maximum failed attempts before permanent action is suggested.
     */
    private const MAX_ATTEMPTS_BEFORE_ALERT = 20;

    /**
     * Record a failed login attempt for the given user/identifier.
     *
     * @param string $identifier Email or username
     * @param int|null $userId The user's ID if known
     * @return array{locked: bool, remaining_attempts: int, lockout_minutes: int}
     */
    public function recordFailedAttempt(string $identifier, ?int $userId = null): array
    {
        $attemptsKey = self::CACHE_PREFIX . 'attempts:' . $identifier;
        $lockoutKey = self::CACHE_PREFIX . 'locked:' . $identifier;

        // Check if already locked out
        $lockedUntil = Cache::get($lockoutKey);
        if ($lockedUntil && Carbon::now()->lessThan($lockedUntil)) {
            $remainingMinutes = Carbon::now()->diffInMinutes($lockedUntil, true) + 1;
            return [
                'locked' => true,
                'remaining_attempts' => 0,
                'lockout_minutes' => (int) ceil($remainingMinutes),
            ];
        }

        // Increment failed attempts
        $attempts = (int) Cache::get($attemptsKey, 0) + 1;
        Cache::put($attemptsKey, $attempts, now()->addHours(2));

        // Check lockout tiers
        $lockoutMinutes = 0;
        foreach (self::LOCKOUT_TIERS as $threshold => $minutes) {
            if ($attempts >= $threshold) {
                $lockoutMinutes = $minutes;
            }
        }

        $locked = $lockoutMinutes > 0;

        if ($locked) {
            Cache::put($lockoutKey, now()->addMinutes($lockoutMinutes), now()->addMinutes($lockoutMinutes));

            Log::channel('security')->warning('Account locked due to failed attempts', [
                'identifier' => $identifier,
                'user_id' => $userId,
                'failed_attempts' => $attempts,
                'lockout_minutes' => $lockoutMinutes,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ]);

            // Critical alert for excessive attempts
            if ($attempts >= self::MAX_ATTEMPTS_BEFORE_ALERT) {
                Log::channel('security')->critical('Excessive failed login attempts detected', [
                    'identifier' => $identifier,
                    'user_id' => $userId,
                    'failed_attempts' => $attempts,
                    'action_required' => 'Review account activity. Consider forcing password reset.',
                    'ip_address' => request()->ip(),
                    'timestamp' => now()->toIso8601String(),
                ]);

                // Broadcast security event if in production
                if (app()->environment('production')) {
                    try {
                        $eventData = [
                            'type' => 'brute_force_attack',
                            'identifier' => $identifier,
                            'user_id' => $userId,
                            'attempts' => $attempts,
                            'ip' => request()->ip(),
                            'severity' => 'critical',
                        ];
                        \App\Events\SecurityEventDetected::dispatch($eventData);
                    } catch (\Exception $e) {
                        // Don't let event dispatch failure affect login flow
                        Log::error('Failed to dispatch security event', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        $remainingAttempts = $this->getRemainingAttempts($attempts);

        return [
            'locked' => $locked,
            'remaining_attempts' => $remainingAttempts,
            'lockout_minutes' => $lockoutMinutes,
            'failed_attempts' => $attempts,
        ];
    }

    /**
     * Get remaining attempts before next lockout tier.
     */
    private function getRemainingAttempts(int $currentAttempts): int
    {
        foreach (self::LOCKOUT_TIERS as $threshold => $minutes) {
            if ($currentAttempts < $threshold) {
                return $threshold - $currentAttempts;
            }
        }
        return 0; // At max tier
    }

    /**
     * Clear failed attempts record (on successful login).
     *
     * @param string $identifier
     * @return void
     */
    public function clearFailedAttempts(string $identifier): void
    {
        $attemptsKey = self::CACHE_PREFIX . 'attempts:' . $identifier;
        $lockoutKey = self::CACHE_PREFIX . 'locked:' . $identifier;

        Cache::forget($attemptsKey);
        Cache::forget($lockoutKey);
    }

    /**
     * Check if an identifier is currently locked out.
     *
     * @param string $identifier
     * @return array{locked: bool, remaining_minutes: int}
     */
    public function isLockedOut(string $identifier): array
    {
        $lockoutKey = self::CACHE_PREFIX . 'locked:' . $identifier;
        $lockedUntil = Cache::get($lockoutKey);

        if (!$lockedUntil) {
            return ['locked' => false, 'remaining_minutes' => 0];
        }

        if (Carbon::now()->greaterThanOrEqualTo($lockedUntil)) {
            Cache::forget($lockoutKey);
            return ['locked' => false, 'remaining_minutes' => 0];
        }

        return [
            'locked' => true,
            'remaining_minutes' => (int) ceil(Carbon::now()->diffInMinutes($lockedUntil, true)),
        ];
    }

    /**
     * Manually unlock an account (admin function).
     *
     * @param string $identifier
     * @param int $adminId
     * @return bool
     */
    public function adminUnlock(string $identifier, int $adminId): bool
    {
        $this->clearFailedAttempts($identifier);

        Log::channel('audit')->info('Account manually unlocked by admin', [
            'identifier' => $identifier,
            'admin_id' => $adminId,
            'ip_address' => request()->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);

        return true;
    }
}
