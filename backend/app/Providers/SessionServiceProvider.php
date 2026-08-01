<?php

namespace App\Providers;

use App\Services\Session\DatabaseSessionHandler;
use App\Services\Session\TenantAwareSessionHandler;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

/**
 * Session Service Provider
 * 
 * Registers custom session handlers for multi-tenant Redis sessions with database fallback.
 * 
 * Features:
 * - Tenant-aware Redis session handler
 * - Automatic fallback to database when Redis is unavailable
 * - Configurable TTL and key prefixing
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class SessionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register SessionFallbackManager as singleton
        $this->app->singleton(\App\Services\Session\SessionFallbackManager::class, function ($app) {
            return new \App\Services\Session\SessionFallbackManager(
                $app['config']['session.connection'] ?? 'session',
                $app['config']['database.redis.health_check_interval'] ?? 30
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register tenant-aware Redis session driver
        Session::extend('tenant_redis', function ($app) {
            // Get session configuration
            $lifetime = $app['config']['session.lifetime'] * 60; // Convert minutes to seconds
            $connection = $app['config']['session.connection'] ?? 'session';
            
            // Get Redis connection
            $redis = Redis::connection($connection)->client();
            
            // Create database fallback handler
            $fallbackHandler = new DatabaseSessionHandler(
                $app['config']['session.table'] ?? 'sessions',
                $lifetime
            );
            
            // Create tenant-aware session handler with fallback
            return new TenantAwareSessionHandler(
                $redis,
                $app['config']['session.prefix'] ?? 'session',
                $lifetime,
                $fallbackHandler
            );
        });
    }
}
