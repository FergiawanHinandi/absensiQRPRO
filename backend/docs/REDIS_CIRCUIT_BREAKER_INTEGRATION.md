# Redis Circuit Breaker Integration Guide

## Overview

`RedisCircuitBreaker` telah terintegrasi ke seluruh sistem untuk mencegah cascading failures ketika Redis unavailable.

**Key Features**:
- ✅ Automatic failure detection
- ✅ Fail-fast when Redis down
- ✅ Automatic recovery testing
- ✅ Different fail strategies per component
- ✅ Comprehensive monitoring

---

## Architecture

### Circuit Breaker States

```
┌─────────────┐
│   CLOSED    │ ← Normal operation
│ (Healthy)   │
└──────┬──────┘
       │ 5 failures
       ↓
┌─────────────┐
│    OPEN     │ ← Redis down, fail fast
│  (Failing)  │
└──────┬──────┘
       │ After 30s
       ↓
┌─────────────┐
│ HALF_OPEN   │ ← Testing recovery
│  (Testing)  │
└──────┬──────┘
       │ 2 successes
       ↓
    CLOSED
```

**State Transitions**:
- **CLOSED → OPEN**: After 5 consecutive failures
- **OPEN → HALF_OPEN**: After 30 seconds timeout
- **HALF_OPEN → CLOSED**: After 2 successful operations
- **HALF_OPEN → OPEN**: If operation fails during testing

---

## Integration Points

### STEP 1 — Wrapper Pattern

**Before** (Direct Redis access):
```php
// ❌ No protection
Redis::set($key, $value, 'EX', 5, 'NX');
```

**After** (Circuit breaker protected):
```php
// ✅ Protected with circuit breaker
$this->redis->execute(
    operation: fn() => Redis::set($key, $value, 'EX', 5, 'NX'),
    fallback: fn() => true  // Fallback strategy
);
```

**Implementation**:
```php
// In UpdateAttendanceSummaryListener
public function __construct(
    private SafeRedisService $redis  // ← Injected
) {}

private function acquireLock(string $lockKey): bool
{
    return $this->redis->execute(
        operation: fn() => Redis::set($lockKey, 1, 'EX', 5, 'NX'),
        fallback: fn() => true  // If Redis down, allow processing
    );
}
```

**Benefits**:
- ✅ No signature changes in handlers
- ✅ Automatic circuit breaker protection
- ✅ Configurable fallback strategies

---

### STEP 2 — Fail Strategies

Different components have different failure strategies:

#### Strategy 1: Attendance Recording (Critical)

**Component**: `RecordAttendanceHandler`

**Strategy**: **Fail hard** if Redis unavailable

```php
try {
    $lock = $this->redis->lock($lockKey, 5);
    
    if (!$lock) {
        // Redis down, cannot guarantee idempotency
        throw new ServiceUnavailableException('Redis unavailable');
    }
    
    // Process attendance
    
} catch (CircuitBreakerOpenException $e) {
    // Return 503 Service Unavailable
    return response()->json([
        'error' => 'Service temporarily unavailable',
        'message' => 'Please try again in a moment',
    ], 503);
}
```

**Rationale**:
- Attendance recording requires **strong idempotency**
- Cannot risk duplicate attendance records
- Better to fail temporarily than create bad data

---

#### Strategy 2: Summary Updates (Non-Critical)

**Component**: `UpdateAttendanceSummaryListener`

**Strategy**: **Fail open** if Redis unavailable

```php
private function acquireLock(string $lockKey): bool
{
    return $this->redis->execute(
        operation: fn() => Redis::set($lockKey, 1, 'EX', 5, 'NX'),
        fallback: fn() => true  // ← Allow processing without lock
    );
}
```

**Rationale**:
- Summary updates are **eventually consistent**
- Duplicate updates are acceptable (atomic operations)
- Better to have slightly stale data than no data

**Trade-off**:
- ✅ System remains operational
- ⚠️ Possible duplicate updates (rare)
- ✅ Self-correcting via backfill

---

### Comparison

| Component | Strategy | Redis Down Behavior | Rationale |
|-----------|----------|---------------------|-----------|
| Attendance Recording | **Fail Hard** | Return 503 | Strong consistency required |
| Summary Updates | **Fail Open** | Skip lock, continue | Eventual consistency acceptable |
| Cache | **Fail Open** | Skip cache, query DB | Degraded performance acceptable |

---

### STEP 3 — Health Endpoint

**Endpoint**: `GET /api/health`

**Response**:
```json
{
  "status": "healthy",
  "redis_status": "CLOSED",
  "timestamp": "2026-02-09T23:25:39+08:00",
  "services": {
    "database": {
      "status": "up",
      "connection": "mysql"
    },
    "redis": {
      "status": "up",
      "circuit_breaker": {
        "state": "closed",
        "failure_count": 0,
        "failure_threshold": 5,
        "last_failure_time": null,
        "time_since_last_failure": null,
        "retry_timeout": 30,
        "is_available": true
      }
    }
  }
}
```

**When Redis Down**:
```json
{
  "status": "degraded",
  "redis_status": "OPEN",
  "message": "Redis is unavailable, using fallback mechanisms",
  "services": {
    "redis": {
      "status": "down",
      "circuit_breaker": {
        "state": "open",
        "failure_count": 5,
        "failure_threshold": 5,
        "last_failure_time": "2026-02-09 23:20:00",
        "time_since_last_failure": 339,
        "retry_timeout": 30,
        "is_available": false
      }
    }
  }
}
```

**Detailed Circuit Breaker Status**:

**Endpoint**: `GET /api/health/circuit-breaker`

```json
{
  "redis_circuit_breaker": {
    "state": "open",
    "failure_count": 5,
    "failure_threshold": 5,
    "last_failure_time": "2026-02-09 23:20:00",
    "time_since_last_failure": 339,
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

---

### STEP 4 — Logging

**Log Events**:

#### 1. Circuit Opened (ERROR)

```json
{
  "level": "error",
  "message": "redis_circuit_opened",
  "context": {
    "failure_count": 5,
    "threshold": 5,
    "retry_timeout": 30
  }
}
```

**When**: After 5 consecutive Redis failures

**Action**: 
- Alert operations team
- Check Redis server health
- Review recent changes

---

#### 2. Circuit Closed (INFO)

```json
{
  "level": "info",
  "message": "redis_circuit_closed",
  "context": {
    "previous_failures": 0
  }
}
```

**When**: After successful recovery (2 successful operations in HALF_OPEN state)

**Action**:
- Confirm Redis is healthy
- Review incident timeline
- Update runbook if needed

---

#### 3. Half-Open Transition (INFO)

```json
{
  "level": "info",
  "message": "Redis circuit breaker transitioned to HALF_OPEN",
  "context": {
    "time_since_last_failure": 30
  }
}
```

**When**: 30 seconds after circuit opened

**Action**:
- Monitor next operations
- Prepare for potential re-opening

---

#### 4. Operation Failure (WARNING)

```json
{
  "level": "warning",
  "message": "Redis operation failed",
  "context": {
    "error": "Connection refused",
    "failure_count": 3,
    "threshold": 5
  }
}
```

**When**: Each Redis operation failure

**Action**:
- Monitor failure count
- Investigate if approaching threshold

---

## Monitoring

### Log Queries

```bash
# Check for circuit opened events
grep "redis_circuit_opened" storage/logs/laravel.log

# Check for circuit closed events
grep "redis_circuit_closed" storage/logs/laravel.log

# Count Redis failures
grep "Redis operation failed" storage/logs/laravel.log | wc -l

# Monitor circuit state transitions
grep "circuit breaker" storage/logs/laravel.log | tail -20
```

### Health Check Monitoring

```bash
# Check current status
curl http://localhost/api/health | jq '.redis_status'

# Full circuit breaker status
curl http://localhost/api/health/circuit-breaker | jq

# Monitor in real-time
watch -n 1 'curl -s http://localhost/api/health | jq ".redis_status"'
```

### Metrics to Track

```yaml
Circuit Breaker:
  - State: CLOSED/OPEN/HALF_OPEN
  - Failure count: Current failures
  - Time in OPEN state: Duration
  - Recovery attempts: Count

Redis:
  - Connection status: up/down
  - Operation latency: ms
  - Error rate: %

Application:
  - Attendance recording: Success rate
  - Summary updates: Processing rate
  - Fallback usage: Count
```

---

## Configuration

### Environment Variables

```env
# Circuit breaker settings
REDIS_CB_FAILURE_THRESHOLD=5      # Open after 5 failures
REDIS_CB_RETRY_TIMEOUT=30         # Retry after 30 seconds
REDIS_CB_SUCCESS_THRESHOLD=2      # Close after 2 successes
```

### Service Provider

**File**: `app/Providers/CircuitBreakerServiceProvider.php`

```php
public function register(): void
{
    $this->app->singleton(RedisCircuitBreaker::class, function ($app) {
        return new RedisCircuitBreaker(
            failureThreshold: config('circuit-breaker.redis.failure_threshold', 5),
            retryTimeoutSeconds: config('circuit-breaker.redis.retry_timeout', 30),
            successThreshold: config('circuit-breaker.redis.success_threshold', 2),
        );
    });
}
```

---

## Testing

### Simulate Redis Failure

```bash
# Stop Redis
redis-cli shutdown

# Or block Redis port
sudo iptables -A INPUT -p tcp --dport 6379 -j DROP
```

### Expected Behavior

1. **First 5 operations fail**:
   - Log: "Redis operation failed" (WARNING)
   - Circuit: Still CLOSED

2. **5th failure**:
   - Log: "redis_circuit_opened" (ERROR)
   - Circuit: OPEN
   - Event: `RedisCircuitBreakerOpened` dispatched

3. **Subsequent operations**:
   - Fail fast (no Redis connection attempt)
   - Use fallback strategies
   - Attendance: Return 503
   - Summary: Skip lock, continue

4. **After 30 seconds**:
   - Log: "transitioned to HALF_OPEN" (INFO)
   - Circuit: HALF_OPEN
   - Next operation: Test Redis

5. **If Redis recovered**:
   - 2 successful operations
   - Log: "redis_circuit_closed" (INFO)
   - Circuit: CLOSED
   - Event: `RedisCircuitBreakerClosed` dispatched

6. **If Redis still down**:
   - Operation fails
   - Circuit: OPEN again
   - Wait another 30 seconds

---

## Troubleshooting

### Issue: Circuit stuck in OPEN state

**Check**:
```bash
# Is Redis actually running?
redis-cli ping

# Check circuit status
curl http://localhost/api/health/circuit-breaker
```

**Solution**:
```bash
# Restart Redis
sudo systemctl restart redis

# Wait 30 seconds for auto-recovery
# Or manually reset circuit
php artisan tinker
>>> app(RedisCircuitBreaker::class)->reset()
```

---

### Issue: Too many false positives

**Symptom**: Circuit opens frequently due to transient errors

**Solution**: Increase failure threshold

```env
# Increase from 5 to 10
REDIS_CB_FAILURE_THRESHOLD=10
```

---

### Issue: Slow recovery

**Symptom**: Circuit takes too long to recover

**Solution**: Decrease retry timeout

```env
# Decrease from 30s to 10s
REDIS_CB_RETRY_TIMEOUT=10
```

---

## Best Practices

### DO ✅

- Monitor circuit breaker state
- Set up alerts for OPEN state
- Use appropriate fail strategies
- Test failover scenarios
- Log all state transitions

### DON'T ❌

- Don't disable circuit breaker in production
- Don't ignore OPEN state alerts
- Don't use same fail strategy for all components
- Don't manually reset without investigating

---

## Alerting

### Critical Alerts

```yaml
redis_circuit_opened:
  severity: critical
  action: Page on-call engineer
  message: "Redis circuit breaker OPEN - immediate attention required"

redis_down_5min:
  severity: critical
  action: Page on-call engineer
  message: "Redis unavailable for 5+ minutes"
```

### Warning Alerts

```yaml
redis_failures_increasing:
  severity: warning
  action: Notify team
  message: "Redis failure count increasing (3/5)"

circuit_half_open:
  severity: warning
  action: Monitor
  message: "Circuit testing Redis recovery"
```

---

**Version**: 1.0.0  
**Last Updated**: 2026-02-09  
**Status**: ✅ Production Ready  
**Breaking Changes**: None
