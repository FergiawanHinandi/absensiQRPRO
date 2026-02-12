<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Active Subscription Middleware
 * 
 * Ensures that the authenticated user's school has an active subscription
 * before allowing access to protected routes.
 * 
 * CRITICAL: Prevents revenue leakage by blocking expired schools
 * 
 * Usage:
 * Route::middleware(['auth:sanctum', 'subscription.active'])->group(...)
 */
class CheckActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Get authenticated user
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'UNAUTHENTICATED',
                'message' => 'Anda harus login terlebih dahulu.',
            ], 401);
        }

        // 2. Get school from user
        $school = $user->school;

        if (!$school) {
            Log::channel('security')->warning('User without school attempted API access', [
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role_type,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'NO_SCHOOL',
                'message' => 'Akun Anda tidak terdaftar di sekolah manapun.',
            ], 403);
        }

        // 3. Check subscription status (with cache)
        // Cache key format: school_sub_{school_id}
        // TTL: 60 seconds (1 minute)
        $cacheKey = "school_sub_{$school->id}";
        
        $subscriptionStatus = Cache::remember($cacheKey, 60, function () use ($school) {
            // Query: is_active = true AND expires_at >= now()
            $subscription = $school->subscriptions()
                ->where('is_active', true)
                ->where('expires_at', '>=', now())
                ->orderBy('expires_at', 'desc')
                ->first();
            
            if (!$subscription) {
                return [
                    'active' => false,
                    'reason' => 'no_active_subscription',
                ];
            }
            
            return [
                'active' => true,
                'subscription_id' => $subscription->id,
                'plan_name' => $subscription->plan_name ?? $subscription->plan_type,
                'expires_at' => $subscription->expires_at->toIso8601String(),
                'days_remaining' => now()->diffInDays($subscription->expires_at, false),
            ];
        });

        // 4. CRITICAL: Validate expires_at after cache hit to prevent stale cache issue
        if ($subscriptionStatus['active'] && isset($subscriptionStatus['expires_at'])) {
            $expiresAt = \Carbon\Carbon::parse($subscriptionStatus['expires_at']);
            
            if (now()->greaterThan($expiresAt)) {
                // Cache has stale data, clear it and deny access
                self::clearCache($school->id);
                
                Log::channel('audit')->warning('Subscription expired (detected via cache validation)', [
                    'school_id' => $school->id,
                    'school_name' => $school->name,
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'role' => $user->role_type,
                    'cached_expires_at' => $subscriptionStatus['expires_at'],
                    'current_time' => now()->toIso8601String(),
                    'ip' => $request->ip(),
                    'endpoint' => $request->path(),
                    'method' => $request->method(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'SUBSCRIPTION_EXPIRED',
                    'message' => 'Langganan sekolah Anda telah berakhir. Silakan perpanjang langganan untuk melanjutkan.',
                    'data' => [
                        'school_name' => $school->name,
                        'contact_admin' => true,
                        'expired_at' => $expiresAt->toDateString(),
                    ],
                    'contact' => [
                        'email' => 'support@absensi.com',
                        'phone' => '+62 812-3456-7890',
                        'whatsapp' => 'https://wa.me/6281234567890',
                    ],
                ], 402); // 402 Payment Required
            }
        }
        
        // 5. If no active subscription, deny access with 402 Payment Required
        if (!$subscriptionStatus['active']) {
            // Log the blocked attempt
            Log::channel('audit')->warning('Blocked API access due to inactive subscription', [
                'school_id' => $school->id,
                'school_name' => $school->name,
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role_type,
                'reason' => $subscriptionStatus['reason'],
                'ip' => $request->ip(),
                'endpoint' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'SUBSCRIPTION_EXPIRED',
                'message' => 'Langganan sekolah Anda telah berakhir. Silakan perpanjang langganan untuk melanjutkan.',
                'data' => [
                    'school_name' => $school->name,
                    'contact_admin' => true,
                ],
                'contact' => [
                    'email' => 'support@absensi.com',
                    'phone' => '+62 812-3456-7890',
                    'whatsapp' => 'https://wa.me/6281234567890',
                ],
            ], 402); // 402 Payment Required
        }

        // 6. Subscription is active, allow request
        return $next($request);
    }

    /**
     * Clear subscription cache for a school
     * 
     * Call this after subscription updates
     */
    public static function clearCache(int $schoolId): void
    {
        $cacheKey = "school_sub_{$schoolId}";
        Cache::forget($cacheKey);
        
        Log::info('Subscription cache cleared', [
            'school_id' => $schoolId,
            'cache_key' => $cacheKey,
        ]);
    }
}
