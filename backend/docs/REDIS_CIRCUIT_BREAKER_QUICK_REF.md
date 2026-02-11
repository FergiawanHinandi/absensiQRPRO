# Redis Circuit Breaker - Quick Reference

## 🚀 Quick Check

### Circuit Status

```bash
# Check status
curl http://localhost/api/health | jq '.redis_status'

# Expected values:
# - CLOSED: Redis healthy
# - OPEN: Redis down
# - HALF_OPEN: Testing recovery
```

## 📊 States

| State | Meaning | Behavior |
|-------|---------|----------|
| **CLOSED** | Redis healthy | Normal operation |
| **OPEN** | Redis down | Fail fast, use fallbacks |
| **HALF_OPEN** | Testing | Testing recovery |

## 🔄 State Transitions

```
CLOSED → OPEN: After 5 failures
OPEN → HALF_OPEN: After 30 seconds
HALF_OPEN → CLOSED: After 2 successes
HALF_OPEN → OPEN: If test fails
```

## 🎯 Fail Strategies

| Component | Strategy | Redis Down |
|-----------|----------|------------|
| Attendance | Fail Hard | Return 503 |
| Summary | Fail Open | Skip lock, continue |

## 📝 Logging

```bash
# Circuit opened
grep "redis_circuit_opened" storage/logs/laravel.log

# Circuit closed
grep "redis_circuit_closed" storage/logs/laravel.log
```

## 🔧 Configuration

```env
REDIS_CB_FAILURE_THRESHOLD=5
REDIS_CB_RETRY_TIMEOUT=30
REDIS_CB_SUCCESS_THRESHOLD=2
```

## 🧪 Test Failover

```bash
# Stop Redis
redis-cli shutdown

# Check status
curl http://localhost/api/health | jq '.redis_status'
# Expected: "OPEN"

# Start Redis
redis-cli

# Wait 30s, check again
# Expected: "HALF_OPEN" → "CLOSED"
```

## 📞 Endpoints

- Health: `/api/health`
- Circuit Details: `/api/health/circuit-breaker`

## 📁 Files

- Integration: `docs/REDIS_CIRCUIT_BREAKER_INTEGRATION.md`
- Circuit Breaker: `app/Infrastructure/CircuitBreaker/RedisCircuitBreaker.php`
- Health Controller: `app/Http/Controllers/Api/V1/HealthController.php`
