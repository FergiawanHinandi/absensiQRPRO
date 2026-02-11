# Redis Failover Cache Configuration

## Overview

The failover cache driver provides automatic fallback when Redis becomes unavailable, ensuring the application continues to function without crashes.

## Configuration

### Cache Stores Hierarchy

The failover cache is configured with three stores in priority order:

```php
'failover' => [
    'driver' => 'failover',
    'stores' => [
        'redis',      // Primary: Fast in-memory cache
        'database',   // Secondary: Persistent fallback
        'array',      // Tertiary: Request-scoped fallback
    ],
],
```

### How It Works

1. **Redis (Primary)**: Fast in-memory cache for optimal performance
   - Used when Redis is healthy and available
   - Provides sub-millisecond response times
   - Shared across all application instances

2. **Database (Secondary)**: Persistent fallback cache
   - Automatically used when Redis fails
   - Stores cache in `cache` table
   - Slower than Redis but reliable
   - Shared across all application instances

3. **Array (Tertiary)**: Request-scoped fallback
   - Used when both Redis and database fail
   - Only persists for the current request
   - Prevents application crashes
   - Not shared between requests

## Environment Configuration

### Using Failover Cache (Recommended for Production)

```env
# Use failover cache for automatic Redis fallback
CACHE_STORE=failover

# Redis configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Database cache table (already exists via migration)
DB_CACHE_TABLE=cache
```

### Using Redis Only (Not Recommended)

```env
# Direct Redis usage without fallback
CACHE_STORE=redis
```

**Warning**: Using `redis` directly will cause application crashes if Redis becomes unavailable.

### Using Database Only (Development)

```env
# Database-only cache (no Redis required)
CACHE_STORE=database
```

## Benefits

### 1. High Availability
- Application continues to function even when Redis fails
- No downtime due to cache failures
- Graceful degradation of performance

### 2. Automatic Recovery
- When Redis comes back online, it automatically becomes the primary cache again
- No manual intervention required
- Seamless transition between cache stores

### 3. Zero Configuration
- Works out of the box with default settings
- No code changes required
- Transparent to application code

## Monitoring

### Health Check Endpoint

Check Redis health status:

```bash
curl http://localhost:8000/api/health/redis
```

Response when healthy:
```json
{
  "status": "healthy",
  "redis": "connected"
}
```

Response when unhealthy:
```json
{
  "status": "unhealthy",
  "redis": "disconnected",
  "error": "Connection refused"
}
```

### Logs

Redis failures are automatically logged:

```
[2026-02-10 10:30:45] production.WARNING: Redis connection failed, falling back to database cache
[2026-02-10 10:30:45] production.INFO: Cache store switched from redis to database
```

## Testing Failover

### Simulate Redis Failure

```bash
# Stop Redis
sudo systemctl stop redis

# Test application (should still work)
curl http://localhost:8000/api/v1/dashboard

# Check logs for fallback messages
tail -f storage/logs/laravel.log
```

### Verify Fallback Behavior

```php
// In tinker
php artisan tinker

// Test cache operations
Cache::put('test_key', 'test_value', 60);
Cache::get('test_key'); // Should work even if Redis is down
```

## Performance Impact

### With Redis (Normal Operation)
- Cache read: < 1ms
- Cache write: < 1ms
- Throughput: 10,000+ ops/sec

### With Database Fallback
- Cache read: 5-10ms
- Cache write: 10-20ms
- Throughput: 500-1000 ops/sec

### With Array Fallback
- Cache read: < 0.1ms
- Cache write: < 0.1ms
- Throughput: 100,000+ ops/sec
- **Limitation**: Not shared between requests

## Best Practices

### 1. Use Failover in Production

Always use `CACHE_STORE=failover` in production to ensure high availability.

### 2. Monitor Redis Health

Set up alerts for Redis failures:
- Monitor `/api/health/redis` endpoint
- Alert when Redis is down for > 5 minutes
- Track failover events in logs

### 3. Cache TTL Strategy

Use appropriate TTL values:
```php
// Short-lived data (use Redis speed)
Cache::put('session_data', $data, 300); // 5 minutes

// Long-lived data (survives Redis restart)
Cache::put('school_settings', $settings, 3600); // 1 hour
```

### 4. Critical Data

For critical data that must survive cache failures:
```php
// Always store in database as source of truth
$school->update(['settings' => $settings]);

// Then cache for performance
Cache::put("school_settings:{$schoolId}", $settings, 3600);
```

## Troubleshooting

### Issue: Cache Not Working

**Check cache configuration:**
```bash
php artisan config:cache
php artisan cache:clear
```

### Issue: Redis Connection Errors

**Verify Redis is running:**
```bash
redis-cli ping
# Should return: PONG
```

**Check Redis configuration:**
```bash
# In .env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Issue: Database Cache Table Missing

**Run migrations:**
```bash
php artisan migrate
```

The `cache` table is created by Laravel's default cache migration.

## Related Documentation

- [Laravel Cache Documentation](https://laravel.com/docs/12.x/cache)
- [Redis Health Guard](./REDIS_HEALTH_GUARD.md) (Task 7.2)
- [Cache Stampede Protection](./CACHE_STAMPEDE_PROTECTION.md) (Task 9.1)

## Task Information

- **Task**: 7.1 Configure failover cache
- **Spec**: saas-hardening-30-days
- **Week**: 2 - Concurrency & Webhook Hardening
- **Risk Reduction**: 🔴 HIGH (9/10)
- **Effort**: 6 hours
