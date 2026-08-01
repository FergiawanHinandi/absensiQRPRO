<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
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

        // ========================================
        // DEPRECATED: Route loading moved to bootstrap/app.php (Laravel 11)
        // This provider is NOT registered in bootstrap/providers.php.
        // Route loading is handled by withRouting() in bootstrap/app.php.
        // Only rate limiting configuration below is used (inherited by parent).
        // ========================================

        // $this->routes(function () { ... }); // REMOVED - causes duplication
    }

    /**
     * Configure the rate limiters for the application.
     *
     * NOTE: Rate limiters are defined in AppServiceProvider to avoid duplication.
     * This method is kept for backward compatibility but does not register
     * duplicate rate limiters.
     */
    protected function configureRateLimiting(): void
    {
        // Rate limiters are now centralized in AppServiceProvider::boot()
        // to prevent duplication and ensure consistent configuration.
        // See: app/Providers/AppServiceProvider.php
    }
}
