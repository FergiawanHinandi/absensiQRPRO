# Circuit Breaker Pattern - Redis Implementation

## Overview

The Circuit Breaker pattern prevents cascading failures when Redis becomes unavailable. Instead of repeatedly trying to connect to a failing service, the circuit breaker "opens" after a threshold of failures, failing fast and using fallback strategies.

## Architecture

### States

```
┌─────────┐
│ CLOSED  │ ← Normal operation
└────┬────┘
     │ Failures >= threshold
     ▼
┌─────────┐
│  OPEN   │ ← Fail fast, use fallback
└────┬────┘
     │ After retry timeout
     ▼
┌──────────┐
│HALF_OPEN │ ← Testing recovery
└────┬─────┘
     │
     ├─ Success × threshold → CLOSED
     └─ Failure → OPEN
```

### Configuration

```php
// config/circuit-breaker.php
'redis' => [
    'failure_threshold' => 5,      // Failures before opening
    'retry_timeout' => 30,         // Seconds before testing recovery
    'success_threshold' => 2,      // Successes to close circuit
],
```

## Usage Examples

### 1. Basic Usage with SafeRedisService

```php
use App\Services\SafeRedisService;

class MyService
{
    public function __construct(
        private SafeRedisService $redis
    ) {}
    
    public function cacheData(string $key, $value): void
    {
        // Automatically protected by circuit breaker
        $this->redis->set($key, $value, 3600);
        
        // If Redis is down, this returns false instead of throwing
    }
    
    public function getData(string $key)
    {
        // Returns null if Redis is unavailable
        return $this->redis->get($key, default: null);
    }
}
```

### 2. Custom Operations with Fallback

```php
use App\Services\SafeRedisService;

public function getSubscriptionStatus(int $userId): array
{
    return $this->redis->execute(
        operation: function() use ($userId) {
            // Try Redis first
            $cached = Redis::get("subscription:{$userId}");
            if ($cached) {
                return json_decode($cached, true);
            }
            return null;
        },
        fallback: function() use ($userId) {
            // Fallback to database
            return Subscription::where('user_id', $userId)
                ->first()
                ?->toArray();
        }
    );
}
```

### 3. Attendance Lock with Fallback

```php
// Already implemented in RecordAttendanceHandler

public function handle(Command $command): Attendance
{
    $lockKey = "attendance:lock:{$studentId}:{$date}";
    
    // Try Redis lock (with circuit breaker)
    $lock = $this->redis->lock($lockKey, 5);
    
    if ($lock) {
        // Redis available - use Redis lock
        return $this->executeWithRedisLock($command, $lock);
    }
    
    // Redis down - fallback to database lock
    return $this->executeWithDatabaseLock($command, $lockKey);
}
```

### 4. Preventing Thundering Herd

```php
use App\Services\SafeRedisService;
use Illuminate\Support\Facades\Cache;

public function getExpensiveData(string $key): array
{
    // Try Redis cache first
    $cached = $this->redis->get($key);
    if ($cached) {
        return json_decode($cached, true);
    }
    
    // Redis down or cache miss
    // Use database lock to prevent thundering herd
    return Cache::lock("compute:{$key}", 10)->block(5, function() use ($key) {
        // Double-check cache
        $cached = $this->redis->get($key);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        // Compute expensive data
        $data = $this->computeExpensiveData();
        
        // Try to cache (fails silently if Redis is down)
        $this->redis->set($key, json_encode($data), 3600);
        
        return $data;
    });
}
```

## Monitoring

### Health Check Endpoint

```bash
# Check overall system health
GET /api/health

# Response when Redis is down:
{
  "status": "degraded",
  "timestamp": "2026-02-09T22:55:32+08:00",
  "services": {
    "database": {
      "status": "up",
      "connection": "pgsql"
    },
    "redis": {
      "status": "down",
      "circuit_breaker": {
        "state": "open",
        "failure_count": 5,
        "failure_threshold": 5,
        "last_failure_time": "2026-02-09 22:54:00",
        "time_since_last_failure": 92,
        "retry_timeout": 30,
        "is_available": false
      }
    }
  },
  "message": "Redis is unavailable, using fallback mechanisms"
}
```

### Circuit Breaker Status

```bash
# Get detailed circuit breaker status
GET /api/health/circuit-breaker

# Response:
{
  "redis_circuit_breaker": {
    "state": "open",
    "failure_count": 5,
    "failure_threshold": 5,
    "last_failure_time": "2026-02-09 22:54:00",
    "time_since_last_failure": 92,
    "retry_timeout": 30,
    "is_available": false
  },
  "recommendations": [
    "Redis circuit is OPEN. Check Redis server health.",
    "System is using database fallback for locking.",
    "Circuit will attempt recovery in 0 seconds."
  ]
}
```

### Log Monitoring

```bash
# Monitor circuit breaker events
tail -f storage/logs/laravel.log | grep -i circuit

# Example logs:
[2026-02-09 22:54:00] production.WARNING: Redis operation failed {"error":"Connection refused","failure_count":5,"threshold":5}
[2026-02-09 22:54:00] production.ERROR: Redis circuit breaker OPENED {"failure_count":5,"threshold":5,"retry_timeout":30}
[2026-02-09 22:54:30] production.INFO: Redis circuit breaker transitioned to HALF_OPEN {"time_since_last_failure":30}
[2026-02-09 22:54:32] production.INFO: Redis circuit breaker CLOSED (recovered) {"previous_failures":5}
```

## Testing

### Unit Tests

```bash
# Run circuit breaker tests
php artisan test --filter=CircuitBreakerTest

# Expected output:
✓ it starts in closed state
✓ it executes operation successfully in closed state
✓ it opens circuit after threshold failures
✓ it fails fast when circuit is open
✓ it uses fallback when circuit is open
✓ it transitions to half open after timeout
✓ it closes circuit after successful half open tests
✓ it reopens circuit if half open test fails
✓ it resets failure count on success
✓ it can be manually reset
```

### Load Test Scenario

```bash
# Simulate Redis failure during high load

# 1. Start load test
ab -n 10000 -c 100 http://localhost/api/attendance/scan

# 2. Stop Redis (in another terminal)
redis-cli shutdown

# 3. Monitor logs
tail -f storage/logs/laravel.log

# Expected behavior:
# - Circuit opens after 5 failures
# - Subsequent requests use database fallback
# - No 500 errors
# - No database overload
# - Attendance still recorded successfully

# 4. Restart Redis
redis-server

# 5. Circuit should auto-recover within 30 seconds
```

### Acceptance Criteria

✅ **No cascading failures**
- System remains operational when Redis is down
- Database is not overloaded

✅ **Automatic recovery**
- Circuit transitions to HALF_OPEN after timeout
- Circuit closes after successful operations

✅ **Data integrity**
- No duplicate attendance records
- All operations complete successfully

✅ **Performance**
- Fail-fast when circuit is open (<1ms)
- Minimal overhead when circuit is closed (<5ms)

## Fallback Strategies

### 1. Attendance Recording

**Redis Available**: Use Redis lock
**Redis Down**: Use database lock (Cache::lock)

```php
// Automatic fallback in RecordAttendanceHandler
if ($redisLock) {
    return $this->executeWithRedisLock($command, $redisLock);
}
return $this->executeWithDatabaseLock($command, $lockKey);
```

### 2. Caching

**Redis Available**: Use Redis cache
**Redis Down**: Query database directly with lock

```php
$cached = $this->redis->get($key);
if (!$cached) {
    return Cache::lock("query:{$key}")->block(5, function() {
        return DB::table(...)->get();
    });
}
```

### 3. Rate Limiting

**Redis Available**: Use Redis-based rate limiting
**Redis Down**: Allow all requests (fail open for availability)

```php
if (!$this->redis->isAvailable()) {
    // Skip rate limiting when Redis is down
    return true;
}
```

## Best Practices

### DO ✅

- Use `SafeRedisService` for all Redis operations
- Implement fallback strategies for critical operations
- Monitor circuit breaker state via health endpoint
- Log circuit state transitions
- Test fallback paths regularly

### DON'T ❌

- Use `Redis::` facade directly (bypasses circuit breaker)
- Throw exceptions when circuit is open (use fallback)
- Ignore circuit breaker warnings in logs
- Set failure threshold too low (causes flapping)
- Forget to test with Redis down

## Troubleshooting

### Circuit Keeps Opening

**Possible causes:**
- Redis server is actually down
- Network issues between app and Redis
- Redis is overloaded

**Solutions:**
1. Check Redis health: `redis-cli ping`
2. Check network: `telnet redis-host 6379`
3. Check Redis metrics: `redis-cli info stats`
4. Increase failure threshold if false positives

### Circuit Won't Close

**Possible causes:**
- Redis is still down
- Retry timeout too short
- Success threshold too high

**Solutions:**
1. Verify Redis is healthy
2. Increase retry timeout
3. Manually reset: `$circuitBreaker->reset()`

### Performance Degradation

**Possible causes:**
- Database fallback is slower than Redis
- Too many concurrent database locks

**Solutions:**
1. Optimize database queries
2. Add database indexes
3. Increase Redis availability (clustering, replication)

## Configuration Tuning

### Conservative (Production)

```env
REDIS_CB_FAILURE_THRESHOLD=5
REDIS_CB_RETRY_TIMEOUT=30
REDIS_CB_SUCCESS_THRESHOLD=2
```

### Aggressive (High Availability)

```env
REDIS_CB_FAILURE_THRESHOLD=10
REDIS_CB_RETRY_TIMEOUT=60
REDIS_CB_SUCCESS_THRESHOLD=3
```

### Testing

```env
REDIS_CB_FAILURE_THRESHOLD=3
REDIS_CB_RETRY_TIMEOUT=5
REDIS_CB_SUCCESS_THRESHOLD=2
```

## Integration Checklist

- [x] Install circuit breaker classes
- [x] Register service provider
- [x] Configure thresholds
- [x] Replace `Redis::` with `SafeRedisService`
- [x] Implement fallback strategies
- [x] Add health check endpoint
- [x] Configure monitoring/alerting
- [x] Run tests
- [x] Load test with Redis failure
- [x] Document runbooks

## Support

For issues:
1. Check `/api/health` endpoint
2. Review `storage/logs/laravel.log`
3. Verify Redis connectivity
4. Check circuit breaker state
5. Contact: ops@example.com
