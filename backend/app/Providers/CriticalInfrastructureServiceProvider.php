<?php

namespace App\Providers;

use App\Core\Services\RateLimit\AdminBypassService;
use App\Core\Services\RateLimit\RateLimitLogger;
use App\Core\Services\RateLimit\RateLimiterService;
use App\Core\Services\RateLimit\SlidingWindowCounter;
use App\Core\Services\RateLimit\TenantKeyBuilder;
use App\Services\DR\AlertManager;
use App\Services\DR\AuditTrailSystem;
use App\Services\DR\BackupEncryption;
use App\Services\DR\TenantSecurityValidator;
use App\Services\Redis\CacheWarmingService;
use Illuminate\Support\ServiceProvider;

/**
 * Critical Infrastructure Service Provider
 *
 * Registers all components from:
 * - critical-rate-limiting spec  (app/Core/Services/RateLimit/)
 * - disaster-recovery spec       (app/Services/DR/)
 * - redis-high-availability spec (app/Services/Redis/)
 */
class CriticalInfrastructureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Rate Limiting (app/Core/Services/RateLimit/) ──────────────────

        $this->app->singleton(SlidingWindowCounter::class, function ($app) {
            return new SlidingWindowCounter(
                config('rate-limiting.redis_connection', 'default')
            );
        });

        $this->app->singleton(TenantKeyBuilder::class, fn () => new TenantKeyBuilder());

        $this->app->singleton(AdminBypassService::class, fn () => new AdminBypassService());

        $this->app->singleton(RateLimitLogger::class, fn () => new RateLimitLogger());

        $this->app->singleton(RateLimiterService::class, function ($app) {
            return new RateLimiterService(
                $app->make(SlidingWindowCounter::class),
                $app->make(TenantKeyBuilder::class),
                $app->make(AdminBypassService::class),
                $app->make(RateLimitLogger::class),
            );
        });

        // ── Disaster Recovery (app/Services/DR/) ─────────────────────────

        $this->app->singleton(AlertManager::class, fn () => new AlertManager());

        $this->app->singleton(AuditTrailSystem::class, fn () => new AuditTrailSystem());

        $this->app->singleton(BackupEncryption::class, fn () => new BackupEncryption());

        $this->app->singleton(TenantSecurityValidator::class, function ($app) {
            return new TenantSecurityValidator($app->make(AlertManager::class));
        });

        // ── Redis HA (app/Services/Redis/) ────────────────────────────────

        $this->app->singleton(CacheWarmingService::class, fn () => new CacheWarmingService());
    }

    public function boot(): void
    {
        $this->app->alias(RateLimiterService::class, 'rate-limiter');

        $this->mergeConfigFrom(
            base_path('config/rate-limiting.php'),
            'rate-limiting'
        );

        $this->mergeConfigFrom(
            base_path('config/disaster_recovery.php'),
            'disaster_recovery'
        );
    }
}
