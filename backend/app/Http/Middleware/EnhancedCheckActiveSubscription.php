<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced Active Subscription Middleware
 * 
 * Revenue Protection: Ensures only schools with active subscriptions can access API.
 * 
 * FEATURES:
 * - Validates subscription is active and not expired
 * - 5-minute cache to reduce database queries
 * - Timezone-aware expiry checking
 * - Comprehensive audit logging
 * - Grace period support (3 days)
 * - Expiry warning headers (7 days before)
 * 
 * USAGE:
 * Route::middleware(['auth:sanctum', 'subscription.active'])->group(...)
 * 
 * @author SaaS Revenue Protection Engineer
 * @version 2.0.0 (Enhanced)
 */
class EnhancedCheckActiveSubscription
{
    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Grace period in days (set to 0 to disable)
     */
    private const GRACE_PERIOD_DAYS = 3;

    /**
     * Warning threshold in days
     */
    private const WARNING_THRESHOLD_DAYS = 7;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ============================================================
        // STEP 1: GET AUTHENTICATED USER
        // ============================================================
        $user = $request->user();

        if (!$user) {
            return $this->unauthorizedResponse('User not authenticated');
        }

        // ============================================================
        // STEP 2: GET SCHOOL FROM USER
        // ============================================================
        $school = $user->school;

        if (!$school) {
            Log::channel('security')->warning('subscription_check_no_school', [
                'user_id' => $user->id,
                'user_role' => $user->role_type,
            ]);

            return $this->subscriptionRequiredResponse('User not associated with any school');
        }

        // ============================================================
        // STEP 3: CHECK SUBSCRIPTION (WITH 5-MINUTE CACHE + LOCK)
        // ============================================================
        // ✅ SECURITY AUDIT FIX: Prevent Thundering Herd
        // Use Cache::lock to ensure only 1 process queries DB when cache expires
        $cacheKey = "school_sub_{$school->id}";

        try {
            // Try to get from cache first
            $subscription = Cache::get($cacheKey);

            // If not in cache, use lock to prevent thundering herd
            if ($subscription === null) {
                $lock = Cache::lock("school_sub_lock_{$school->id}", 10);

                try {
                    // Block until lock is acquired (max 5 seconds)
                    $lock->block(5);

                    // Double-check cache (maybe another process just populated it)
                    $subscription = Cache::get($cacheKey);

                    if ($subscription === null) {
                        // Query database (only this process does this)
                        $subscription = Subscription::where('school_id', $school->id)
                            ->where('is_active', true)
                            ->orderBy('expires_at', 'desc')
                            ->first();

                        // Cache the result
                        Cache::put($cacheKey, $subscription, self::CACHE_TTL);
                    }

                } finally {
                    // Always release the lock
                    optional($lock)->release();
                }
            }

        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Lock acquisition timed out, fallback to direct query
            Log::warning('subscription_lock_timeout', [
                'school_id' => $school->id,
                'message' => 'Lock acquisition timed out, querying database',
            ]);

            $subscription = Subscription::where('school_id', $school->id)
                ->where('is_active', true)
                ->orderBy('expires_at', 'desc')
                ->first();

        } catch (\Exception $e) {
            Log::error('subscription_cache_failed', [
                'school_id' => $school->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback to direct query if cache fails
            $subscription = Subscription::where('school_id', $school->id)
                ->where('is_active', true)
                ->orderBy('expires_at', 'desc')
                ->first();
        }

        // ============================================================
        // STEP 4: VALIDATE SUBSCRIPTION EXISTS
        // ============================================================
        if (!$subscription) {
            Log::channel('audit')->warning('subscription_not_found', [
                'school_id' => $school->id,
                'school_name' => $school->name,
                'user_id' => $user->id,
                'endpoint' => $request->path(),
            ]);

            return $this->subscriptionRequiredResponse('No active subscription found');
        }

        // ============================================================
        // STEP 5: VALIDATE SUBSCRIPTION NOT EXPIRED (TIMEZONE-AWARE)
        // ============================================================
        $timezone = $school->timezone ?? 'Asia/Jakarta';
        $now = now($timezone);
        $expiresAt = \Carbon\Carbon::parse($subscription->expires_at)->setTimezone($timezone);

        // Check if subscription is expired
        if ($now->isAfter($expiresAt)) {
            $daysSinceExpiry = $now->diffInDays($expiresAt);

            // Check if within grace period
            if (self::GRACE_PERIOD_DAYS > 0 && $daysSinceExpiry <= self::GRACE_PERIOD_DAYS) {
                // Within grace period - allow access but log warning
                Log::channel('audit')->warning('subscription_grace_period', [
                    'school_id' => $school->id,
                    'subscription_id' => $subscription->id,
                    'expired_at' => $expiresAt->toIso8601String(),
                    'days_since_expiry' => $daysSinceExpiry,
                    'grace_period_remaining' => self::GRACE_PERIOD_DAYS - $daysSinceExpiry,
                    'user_id' => $user->id,
                    'endpoint' => $request->path(),
                ]);

                // Add warning header
                $response = $next($request);
                $response->headers->set('X-Subscription-Status', 'grace-period');
                $response->headers->set('X-Grace-Period-Days-Remaining', self::GRACE_PERIOD_DAYS - $daysSinceExpiry);

                return $response;
            }

            // Expired and beyond grace period - BLOCK ACCESS
            Log::channel('audit')->warning('subscription_expired_access_denied', [
                'school_id' => $school->id,
                'school_name' => $school->name,
                'subscription_id' => $subscription->id,
                'expired_at' => $expiresAt->toIso8601String(),
                'days_since_expiry' => $daysSinceExpiry,
                'user_id' => $user->id,
                'user_role' => $user->role_type,
                'endpoint' => $request->path(),
                'ip' => $request->ip(),
            ]);

            // Clear cache to ensure fresh check on next request
            Cache::forget($cacheKey);

            return $this->subscriptionExpiredResponse(
                'Subscription has expired',
                $expiresAt,
                $subscription
            );
        }

        // ============================================================
        // STEP 6: CHECK EXPIRY WARNING (7 days before expiry)
        // ============================================================
        $daysUntilExpiry = $now->diffInDays($expiresAt, false);

        if ($daysUntilExpiry <= self::WARNING_THRESHOLD_DAYS && $daysUntilExpiry > 0) {
            Log::channel('audit')->info('subscription_expiring_soon', [
                'school_id' => $school->id,
                'subscription_id' => $subscription->id,
                'expires_at' => $expiresAt->toIso8601String(),
                'days_until_expiry' => $daysUntilExpiry,
            ]);

            // Add warning header
            $response = $next($request);
            $response->headers->set('X-Subscription-Status', 'expiring-soon');
            $response->headers->set('X-Days-Until-Expiry', (string) $daysUntilExpiry);

            return $response;
        }

        // ============================================================
        // STEP 7: SUBSCRIPTION VALID - ALLOW ACCESS
        // ============================================================
        Log::channel('audit')->debug('subscription_check_passed', [
            'school_id' => $school->id,
            'subscription_id' => $subscription->id,
            'expires_at' => $expiresAt->toIso8601String(),
            'user_id' => $user->id,
            'endpoint' => $request->path(),
        ]);

        // Attach subscription to request for use in controllers
        $request->attributes->set('subscription', $subscription);
        $request->attributes->set('school', $school);

        return $next($request);
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'UNAUTHORIZED',
        ], 401);
    }

    /**
     * Return subscription required response
     */
    private function subscriptionRequiredResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SUBSCRIPTION_REQUIRED',
            'action_required' => 'Please contact your school administrator to activate a subscription.',
            'contact' => [
                'email' => config('app.support_email', 'support@absensi.com'),
                'phone' => config('app.support_phone', '+62-xxx-xxxx-xxxx'),
            ],
        ], 402); // 402 Payment Required
    }

    /**
     * Return subscription expired response
     */
    private function subscriptionExpiredResponse(
        string $message,
        \Carbon\Carbon $expiresAt,
        Subscription $subscription
    ): Response {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SUBSCRIPTION_EXPIRED',
            'expired_at' => $expiresAt->toIso8601String(),
            'expired_days_ago' => now()->diffInDays($expiresAt),
            'subscription_type' => $subscription->package_type ?? 'unknown',
            'action_required' => 'Please renew your subscription to continue using the service.',
            'renewal_url' => config('app.url') . '/subscription/renew',
            'contact' => [
                'email' => config('app.support_email', 'support@absensi.com'),
                'phone' => config('app.support_phone', '+62-xxx-xxxx-xxxx'),
                'whatsapp' => config('app.support_whatsapp', '+62-xxx-xxxx-xxxx'),
            ],
        ], 402); // 402 Payment Required
    }
}
