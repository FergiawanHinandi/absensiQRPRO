# Circuit Breaker Quick Reference

## 🚀 Quick Start

### 1. Use SafeRedisService Instead of Redis Facade

```php
// ❌ DON'T: Direct Redis usage (no protection)
use Illuminate\Support\Facades\Redis;

Redis::set('key', 'value');
$lock = Redis::lock('mylock', 5);

// ✅ DO: Use SafeRedisService (circuit breaker protected)
use App\Services\SafeRedisService;

public function __construct(
    private SafeRedisService $redis
) {}

$this->redis->set('key', 'value');
$lock = $this->redis->lock('mylock', 5);
```

### 2. Common Patterns

#### Pattern 1: Cache with Fallback
```php
public function getData(int $id)
{
    // Try cache first
    $cached = $this->redis->get("data:{$id}");
    
    if ($cached) {
        return json_decode($cached, true);
    }
    
    // Cache miss or Redis down - query database
    $data = DB::table('data')->find($id);
    
    // Try to cache (fails silently if Redis down)
    $this->redis->set("data:{$id}", json_encode($data), 3600);
    
    return $data;
}
```

#### Pattern 2: Lock with Fallback
```php
public function processWithLock(string $key)
{
    // Try Redis lock
    $lock = $this->redis->lock("process:{$key}", 10);
    
    if ($lock) {
        // Redis available
        try {
            $lock->block(3);
            // ... do work ...
        } finally {
            $lock->release();
        }
    } else {
        // Redis down - use database lock
        Cache::lock("process:{$key}", 10)->block(3, function() {
            // ... do work ...
        });
    }
}
```

#### Pattern 3: Custom Operation with Fallback
```php
public function incrementCounter(string $key): int
{
    return $this->redis->execute(
        operation: fn() => Redis::incr($key),
        fallback: fn() => DB::table('counters')
            ->where('key', $key)
            ->increment('value')
    );
}
```

## 📊 Monitoring

### Check Circuit Status

```php
// In code
$status = $this->redis->getCircuitStatus();

if ($status['state'] === 'open') {
    Log::warning('Redis circuit is OPEN', $status);
}
```

### Health Endpoint

```bash
# Check system health
curl http://localhost/api/health

# Check circuit breaker details
curl http://localhost/api/health/circuit-breaker
```

### Log Monitoring

```bash
# Watch for circuit events
tail -f storage/logs/laravel.log | grep -i "circuit"

# Look for:
# - "Redis circuit breaker OPENED"
# - "Redis circuit breaker CLOSED"
# - "Redis unavailable, using database lock"
```

## ⚙️ Configuration

### Environment Variables

```env
# .env
REDIS_CB_FAILURE_THRESHOLD=5    # Failures before opening
REDIS_CB_RETRY_TIMEOUT=30       # Seconds before testing recovery
REDIS_CB_SUCCESS_THRESHOLD=2    # Successes to close circuit
```

### Tuning Guide

**Production (Conservative)**:
```env
REDIS_CB_FAILURE_THRESHOLD=5
REDIS_CB_RETRY_TIMEOUT=30
REDIS_CB_SUCCESS_THRESHOLD=2
```

**High Availability (Aggressive)**:
```env
REDIS_CB_FAILURE_THRESHOLD=10
REDIS_CB_RETRY_TIMEOUT=60
REDIS_CB_SUCCESS_THRESHOLD=3
```

**Testing**:
```env
REDIS_CB_FAILURE_THRESHOLD=3
REDIS_CB_RETRY_TIMEOUT=5
REDIS_CB_SUCCESS_THRESHOLD=2
```

## 🔧 Troubleshooting

### Circuit Keeps Opening

**Symptoms**: Circuit frequently opens and closes

**Possible Causes**:
- Redis is actually unstable
- Network issues
- Threshold too low

**Solutions**:
```bash
# 1. Check Redis health
redis-cli ping

# 2. Check network latency
redis-cli --latency

# 3. Increase threshold
REDIS_CB_FAILURE_THRESHOLD=10

# 4. Check Redis logs
redis-cli info stats
```

### Circuit Won't Close

**Symptoms**: Circuit stays OPEN indefinitely

**Possible Causes**:
- Redis is still down
- Retry timeout too short

**Solutions**:
```bash
# 1. Verify Redis is running
redis-cli ping

# 2. Check circuit status
curl http://localhost/api/health/circuit-breaker

# 3. Manual reset (if Redis is healthy)
php artisan tinker
>>> app(\App\Infrastructure\CircuitBreaker\RedisCircuitBreaker::class)->reset()
```

### Performance Degradation

**Symptoms**: Slow responses when circuit is OPEN

**Possible Causes**:
- Database fallback is slower
- Too many concurrent database locks

**Solutions**:
```bash
# 1. Add database indexes
php artisan migrate

# 2. Monitor database load
# Check slow query log

# 3. Optimize fallback queries
# Use eager loading, reduce N+1 queries

# 4. Fix Redis (best solution)
redis-server
```

## 🧪 Testing

### Manual Test

```bash
# 1. Check circuit is CLOSED
curl http://localhost/api/health/circuit-breaker

# 2. Stop Redis
redis-cli shutdown

# 3. Trigger some operations
curl -X POST http://localhost/api/attendance/scan

# 4. Check circuit is OPEN
curl http://localhost/api/health/circuit-breaker

# 5. Verify fallback works (no errors)

# 6. Restart Redis
redis-server

# 7. Wait 30 seconds

# 8. Check circuit is CLOSED
curl http://localhost/api/health/circuit-breaker
```

### Automated Tests

```bash
# Run circuit breaker tests
php artisan test --filter=CircuitBreakerTest

# Run resilience tests
php artisan test --filter=RedisFailureResilienceTest
```

## 📝 Checklist

### Before Deployment

- [ ] Service provider registered in `config/app.php`
- [ ] Environment variables configured
- [ ] Health routes added
- [ ] All Redis calls use `SafeRedisService`
- [ ] Fallback strategies implemented
- [ ] Tests passing
- [ ] Monitoring configured

### After Deployment

- [ ] Health endpoint accessible
- [ ] Circuit starts in CLOSED state
- [ ] Logs show no errors
- [ ] Test Redis failure scenario
- [ ] Verify automatic recovery
- [ ] Monitor for 24 hours

## 🆘 Emergency Procedures

### Redis is Down

1. **Don't Panic**: System should continue working with fallbacks
2. **Check Health**: `curl /api/health`
3. **Verify Fallbacks**: Check logs for "using database lock"
4. **Fix Redis**: Restart Redis server
5. **Monitor Recovery**: Circuit should close automatically

### Circuit Stuck OPEN

1. **Verify Redis**: `redis-cli ping`
2. **Check Logs**: Look for connection errors
3. **Manual Reset**: Use tinker to reset circuit
4. **Investigate**: Why did Redis fail?

### Too Many Failures

1. **Check Threshold**: Maybe too sensitive
2. **Check Redis Load**: `redis-cli info stats`
3. **Check Network**: Latency issues?
4. **Increase Threshold**: Temporarily if needed

## 📞 Support

- **Documentation**: `docs/CIRCUIT_BREAKER.md`
- **Summary**: `docs/CIRCUIT_BREAKER_SUMMARY.md`
- **Health Check**: `/api/health`
- **Logs**: `storage/logs/laravel.log`
