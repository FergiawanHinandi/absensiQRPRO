<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for accessing resilient Redis connections
 * 
 * @method static mixed execute(string $command, array $args = [])
 * @method static bool isHealthy()
 * @method static array getStats()
 * @method static self setMaxRetries(int $maxRetries)
 * @method static self setHealthCheckInterval(int $seconds)
 * @method static bool forceHealthCheck()
 * 
 * @see \App\Services\Redis\ResilientRedisConnection
 */
class ResilientRedis extends Facade
{
    /**
     * Get the registered name of the component
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'redis.resilient.default';
    }

    /**
     * Get the cache connection
     *
     * @return \App\Services\Redis\ResilientRedisConnection
     */
    public static function cache()
    {
        return app('redis.resilient.cache');
    }

    /**
     * Get the session connection
     *
     * @return \App\Services\Redis\ResilientRedisConnection
     */
    public static function session()
    {
        return app('redis.resilient.session');
    }

    /**
     * Get the queue connection
     *
     * @return \App\Services\Redis\ResilientRedisConnection
     */
    public static function queue()
    {
        return app('redis.resilient.queue');
    }
}
