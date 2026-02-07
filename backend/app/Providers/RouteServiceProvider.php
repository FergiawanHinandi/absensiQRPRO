<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * RouteServiceProvider - Versioned API Route Configuration
 *
 * ROUTE STRUCTURE:
 * ├── routes/
 * │   ├── api.php              - Legacy routes (backward compatibility)
 * │   ├── api/
 * │   │   ├── v1.php           - V1 route aggregator
 * │   │   └── v1/
 * │   │       ├── auth.php     - Authentication routes
 * │   │       ├── attendance.php - Attendance management
 * │   │       ├── teacher.php  - Teacher routes
 * │   │       ├── admin.php    - School admin routes
 * │   │       └── ...
 * │   │   └── v2/              - Future v2 routes (prepared)
 * │   └── web.php
 *
 * VERSIONING STRATEGY:
 * - All API routes prefixed with /api/v1/...
 * - New features can be added to v2 without breaking v1
 * - Deprecated routes remain functional during transition
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // ========================================
            // API ROUTES (Versioned)
            // ========================================

            // V1 API Routes
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api/v1.php'));

            // V2 API Routes (Future - currently disabled)
            // Uncomment when v2 is ready
            // if (file_exists(base_path('routes/api/v2.php'))) {
            //     Route::middleware('api')
            //         ->prefix('api/v2')
            //         ->group(base_path('routes/api/v2.php'));
            // }

            // ========================================
            // LEGACY API ROUTES (Backward Compatibility)
            // ========================================
            // Keep this for backward compatibility during transition
            // TODO: Deprecate and remove after all clients migrate to v1
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // ========================================
            // WEB ROUTES
            // ========================================
            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Default API rate limit
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Login rate limit (brute force protection)
        RateLimiter::for('login', function (Request $request) {
            $key = $request->input('username', $request->ip());

            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam 1 menit.',
                ], 429);
            });
        });

        // Scan rate limit (per user + IP)
        RateLimiter::for('scan', function (Request $request) {
            $key = ($request->user()?->id ?? 'guest').'|'.$request->ip();

            return Limit::perMinute(30)->by($key);
        });

        // Global rate limit (for public endpoints)
        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        // Export rate limit (heavy operations)
        RateLimiter::for('export', function (Request $request) {
            return Limit::perHour(10)->by($request->user()?->id ?? $request->ip());
        });

        // School-scoped rate limit
        RateLimiter::for('school', function (Request $request) {
            $schoolId = $request->user()?->school_id ?? 0;

            return Limit::perMinute(100)->by('school:'.$schoolId);
        });
    }
}
