# Redis Sentinel Integration Guide

## Overview

The Laravel Redis Sentinel integration provides automatic failover capabilities with resilient connection handling. This implementation includes connection pooling, automatic retry logic with exponential backoff, and health check mechanisms.

## Configuration

### Environment Variables

Add the following to your `.env` file:

```env
# Redis Client (use predis for Sentinel support)
REDIS_CLIENT=predis

# Standard Redis Configuration (for standalone mode)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Connection Resilience Settings
REDIS_MAX_RETRIES=3
REDIS_HEALTH_CHECK_INTERVAL=30

# Redis Sentinel High Availability Configuration
REDIS_SENTINELS=false  # Set to true to enable Sentinel mode
REDIS_SENTINEL_SERVICE=mymaster
REDIS_SENTINEL_1=tcp://sentinel-1:26379?timeout=0.1
REDIS_SENTINEL_2=tcp://sentinel-2:26379?timeout=0.1
REDIS_SENTINEL_3=tcp://sentinel-3:26379?timeout=0.1
```

### Database Allocation

The configuration uses separate Redis databases for different purposes:

- **Database 0**: Default/General purpose
- **Database 1**: Cache data
- **Database 2**: Session data
- **Database 3**: Queue data

## Usage

### Using the Resilient Connection Wrapper

#### Via Facade

```php
use App\Facades\ResilientRedis;

// Execute Redis commands with automatic retry
$result = ResilientRedis::execute('get', ['key']);
$result = ResilientRedis::execute('set', ['key', 'value']);

// Check connection health
$isHealthy = ResilientRedis::isHealthy();

// Get connection statistics
$stats = ResilientRedis::getStats();

// Force a health check
$healthy = ResilientRedis::forceHealthCheck();

// Access specific connections
$cacheConnection = ResilientRedis::cache();
$sessionConnection = ResilientRedis::session();
$queueConnection = ResilientRedis::queue();
```

#### Via Dependency Injection

```php
use App\Services\Redis\ResilientRedisConnection;

class MyService
{
    public function __construct(
        private ResilientRedisConnection $redis
    ) {}

    public function doSomething()
    {
        $value = $this->redis->execute('get', ['mykey']);
    }
}
```

#### Via Connection Pool

```php
use App\Services\Redis\RedisConnectionPool;

$pool = app(RedisConnectionPool::class);

// Get a specific connection
$cacheConnection = $pool->connection('cache');
$result = $cacheConnection->execute('get', ['key']);

// Check health of all connections
$health = $pool->getPoolHealth();

// Get statistics for all connections
$stats = $pool->getPoolStats();

// Force health check on all connections
$results = $pool->forceHealthCheckAll();
```

### Using Standard Laravel Redis

The standard Laravel Redis facade continues to work as before:

```php
use Illuminate\Support\Facades\Redis;

// Standard Redis usage (automatically uses Sentinel when configured)
Redis::set('key', 'value');
$value = Redis::get('key');

// Specific connections
Redis::connection('cache')->set('key', 'value');
Redis::connection('session')->get('key');
```

## Features

### Automatic Retry Logic

The resilient connection wrapper automatically retries failed operations:

- **Maximum retries**: 3 attempts (configurable via `REDIS_MAX_RETRIES`)
- **Exponential backoff**: 100ms × attempt number (100ms, 200ms, 300ms)
- **Automatic connection invalidation**: Failed connections are marked unhealthy

### Health Checks

Connections are automatically health-checked:

- **Interval**: 30 seconds (configurable via `REDIS_HEALTH_CHECK_INTERVAL`)
- **Method**: PING command to verify connectivity
- **Automatic**: Performed before command execution if interval elapsed

### Error Handling

```php
use App\Services\Redis\RedisUnavailableException;

try {
    $result = ResilientRedis::execute('get', ['key']);
} catch (RedisUnavailableException $e) {
    // Redis is unavailable after all retry attempts
    Log::error('Redis unavailable', ['error' => $e->getMessage()]);
    
    // Implement fallback logic here
}
```

## Monitoring

### Health Check Endpoint

Create a health check endpoint to monitor Redis status:

```php
Route::get('/health/redis', function () {
    $pool = app(\App\Services\Redis\RedisConnectionPool::class);
    
    return response()->json([
        'status' => 'ok',
        'redis' => $pool->getPoolHealth(),
    ]);
});
```

### Logging

All connection failures and health check issues are automatically logged:

```php
// Connection failure logs
Log::warning('Redis connection failed', [
    'connection' => 'default',
    'attempt' => 1,
    'max_retries' => 3,
    'error' => 'Connection refused',
]);

// Health check failure logs
Log::warning('Redis health check failed', [
    'connection' => 'cache',
    'response' => null,
]);
```

## Switching Between Standalone and Sentinel Mode

### Development (Standalone Redis)

```env
REDIS_SENTINELS=false
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Production (Redis Sentinel)

```env
REDIS_SENTINELS=true
REDIS_SENTINEL_SERVICE=mymaster
REDIS_SENTINEL_1=tcp://sentinel-1:26379?timeout=0.1
REDIS_SENTINEL_2=tcp://sentinel-2:26379?timeout=0.1
REDIS_SENTINEL_3=tcp://sentinel-3:26379?timeout=0.1
```

No code changes required - the configuration automatically handles both modes.

## Best Practices

1. **Use resilient connections for critical operations**: Wrap important Redis operations with the resilient connection wrapper
2. **Implement fallback logic**: Always have a fallback strategy when Redis is unavailable
3. **Monitor health status**: Regularly check connection health in production
4. **Configure appropriate timeouts**: Set Sentinel timeout values based on your network latency
5. **Use separate databases**: Keep different data types isolated in separate databases
6. **Enable authentication**: Always use `REDIS_PASSWORD` in production
7. **Monitor logs**: Watch for connection failures and health check issues

## Troubleshooting

### Connection Failures

If you see repeated connection failures:

1. Check Redis/Sentinel service status
2. Verify network connectivity
3. Check authentication credentials
4. Review Sentinel configuration
5. Check firewall rules

### Health Check Failures

If health checks fail:

1. Verify Redis is responding to PING commands
2. Check network latency
3. Review health check interval settings
4. Check Redis server load

### Sentinel Discovery Issues

If Sentinel discovery fails:

1. Verify Sentinel service name matches configuration
2. Check Sentinel endpoints are accessible
3. Verify Sentinel quorum configuration
4. Review Sentinel logs for errors

## Performance Considerations

- **Connection pooling**: Connections are reused via singleton pattern
- **Health check caching**: Health checks are cached for the configured interval
- **Exponential backoff**: Prevents overwhelming Redis during failures
- **Separate databases**: Reduces key collision and improves performance
- **Timeout configuration**: 0.1s timeout for Sentinel discovery prevents long waits

## Security

- Always use strong passwords for Redis authentication
- Use TLS for production deployments (configure in Sentinel)
- Restrict network access to Redis/Sentinel ports
- Regularly rotate Redis passwords
- Monitor for unauthorized access attempts
