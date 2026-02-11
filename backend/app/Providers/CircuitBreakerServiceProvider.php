<?php

namespace App\Providers;

use App\Infrastructure\CircuitBreaker\RedisCircuitBreaker;
use Illuminate\Support\ServiceProvider;

/**
 * Circuit Breaker Service Provider
 * 
 * Registers circuit breakers as singletons
 */
class CircuitBreakerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register RedisCircuitBreaker as singleton
        $this->app->singleton(RedisCircuitBreaker::class, function ($app) {
            return new RedisCircuitBreaker(
                failureThreshold: config('circuit-breaker.redis.failure_threshold', 5),
                retryTimeoutSeconds: config('circuit-breaker.redis.retry_timeout', 30),
                successThreshold: config('circuit-breaker.redis.success_threshold', 2),
            );
        });
        
        // Alias for easier access
        $this->app->alias(RedisCircuitBreaker::class, 'redis.circuit-breaker');
        
        // Register SafeRedisService with explicit dependency injection
        $this->app->singleton(\App\Services\SafeRedisService::class, function ($app) {
            return new \App\Services\SafeRedisService(
                $app->make(RedisCircuitBreaker::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
