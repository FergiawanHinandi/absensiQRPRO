<?php

namespace App\Providers;

use App\Services\Redis\RedisConnectionPool;
use App\Services\Redis\ResilientRedisConnection;
use Illuminate\Support\ServiceProvider;

/**
 * Redis Service Provider
 * 
 * Registers Redis-related services including the resilient connection wrapper
 */
class RedisServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Register connection pool as singleton
        $this->app->singleton(RedisConnectionPool::class, function ($app) {
            return new RedisConnectionPool();
        });

        // Register resilient Redis connections as singletons
        $this->app->singleton('redis.resilient.default', function ($app) {
            return new ResilientRedisConnection('default', config('database.redis.max_retries', 3));
        });

        $this->app->singleton('redis.resilient.cache', function ($app) {
            return new ResilientRedisConnection('cache', config('database.redis.max_retries', 3));
        });

        $this->app->singleton('redis.resilient.session', function ($app) {
            return new ResilientRedisConnection('session', config('database.redis.max_retries', 3));
        });

        $this->app->singleton('redis.resilient.queue', function ($app) {
            return new ResilientRedisConnection('queue', config('database.redis.max_retries', 3));
        });
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        //
    }
}
