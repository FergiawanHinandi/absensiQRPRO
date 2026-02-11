# Webhook Idempotency Implementation

## 🎯 Purpose
Prevent duplicate payment webhook processing that could cause:
- ❌ Double subscription extension
- ❌ Incorrect revenue tracking
- ❌ Duplicate email notifications
- ❌ Data inconsistency

## ⚠️ The Problem

### Scenario: Midtrans Webhook Retry
Midtrans retries webhooks on failures or timeouts. Without idempotency:

```
Timeline:
10:00:00 - Webhook 1 arrives (order_id: ORDER123, status: settlement)
10:00:00 - Process: Extend subscription from Feb 1 → March 1
10:00:02 - Network timeout before response sent
10:00:05 - Midtrans retries (same order_id: ORDER123)
10:00:05 - Process: Extend subscription from March 1 → April 1 ❌
```

**Result:** School gets 2 months subscription for 1 payment!

### Race Condition: Concurrent Webhooks
```
Request A                          Request B (duplicate)
|                                   |
|-- Extract order_id: ORDER123      |-- Extract order_id: ORDER123
|                                   |
|-- Check isProcessed? → FALSE      |-- Check isProcessed? → FALSE
|                                   |
|-- Start processing                |-- Start processing
|                                   |
|-- ApplyPackage() → +30 days       |-- ApplyPackage() → +30 days ❌
|                                   |
|-- MarkAsProcessed()               |-- MarkAsProcessed()
```

**Result:** 60 days added instead of 30 days!

## 🛡️ Multi-Layer Defense

### Layer 1: Redis Lock (PRIMARY)
```php
$lockKey = "webhook_lock:{$orderId}";
$lock = Cache::lock($lockKey, 30); // 30-second lock

if (!$lock->block(10)) {
    // Another request is processing
    return response()->json(['retry_after' => 10], 429);
}

try {
    // Only ONE request can reach here
    if (ProcessedWebhook::isAlreadyProcessed($orderId)) {
        return ['idempotent' => true];
    }
    
    // Process webhook
    // ...
} finally {
    $lock->release(); // Always release
}
```

**Protection:**
- ✅ Atomic lock acquisition (Redis SET NX)
- ✅ First request acquires lock, others wait
- ✅ Auto-expiry after 30 seconds (prevents deadlock)
- ✅ Graceful 429 response if lock timeout

### Layer 2: Database Unique Constraint (FALLBACK)
```sql
CREATE TABLE processed_webhooks (
    id BIGSERIAL PRIMARY KEY,
    order_id VARCHAR UNIQUE,        -- ← Unique constraint
    transaction_id VARCHAR UNIQUE,  -- ← Unique constraint
    processed_at TIMESTAMP,
    -- ...
);
```

**Protection:**
- ✅ Database-level guarantee (even if Redis fails)
- ✅ Second webhook insert fails with UNIQUE VIOLATION
- ✅ Prevents duplicates across app restarts

### Layer 3: Application Logic (DEFENSE-IN-DEPTH)
```php
// Check if already processed INSIDE the lock
if (ProcessedWebhook::isAlreadyProcessed($orderId)) {
    return ['idempotent' => true];
}

// Apply package only once
if ($internalStatus === 'paid' && !$payment->payment_date) {
    $payment->payment_date = now();  // ← NULL check
    if ($payment->package_id) {
        $this->applyPackageToSchool($payment->school_id, $payment->package_id);
    }
}
```

**Protection:**
- ✅ Idempotency check inside lock (eliminates race)
- ✅ NULL check on `payment_date` (apply package only once)
- ✅ Immutable after first success

## 📊 Flow Diagram

```
┌─────────────────────────────────────────────────────┐
│ Midtrans Webhook: POST /api/v1/webhooks/payments   │
└─────────────────────────────────────────────────────┘
                       │
                       ▼
         ┌─────────────────────────────┐
         │ Extract order_id            │
         └─────────────────────────────┘
                       │
                       ▼
         ┌─────────────────────────────┐
         │ Acquire Redis Lock          │
         │ Key: webhook_lock:{orderId} │
         │ Timeout: 30s                │
         │ Block: 10s                  │
         └─────────────────────────────┘
                       │
        ┌──────────────┴──────────────┐
        │                             │
        ▼                             ▼
   Lock Timeout                  Lock Acquired
   (10 seconds)                  (Success)
        │                             │
        ▼                             ▼
   Return 429              Check isAlreadyProcessed()
   "retry_after: 10"                 │
                          ┌───────────┴───────────┐
                          │                       │
                          ▼                       ▼
                       TRUE                    FALSE
                          │                       │
                          ▼                       ▼
                  Return 200              DB Transaction
                  "idempotent: true"              │
                                                  ▼
                                    ┌─────────────────────────┐
                                    │ 1. Verify signature     │
                                    │ 2. Find payment record  │
                                    │ 3. Update status        │
                                    │ 4. Apply package (once) │
                                    │ 5. MarkAsProcessed()    │
                                    └─────────────────────────┘
                                                  │
                                        ┌─────────┴─────────┐
                                        │                   │
                                        ▼                   ▼
                                    Success             Exception
                                        │                   │
                                        ▼                   ▼
                                  Return 200         Rollback + Log
                                                            │
                                                            ▼
                                                      Return 500
```

## 🔧 Configuration

### Redis Settings (Critical!)
```bash
# backend/.env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0  # Use dedicated DB for locks
```

### Lock Tuning
```php
// backend/config/cache.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'predis'),
    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
    ],
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
        'timeout' => 5.0,  // Connection timeout
    ],
],
```

## 🧪 Testing Scenarios

### Test 1: Duplicate Webhook (Same order_id)
```bash
# Send webhook twice with same order_id
curl -X POST http://localhost:8000/api/v1/webhooks/payments \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": "ORDER_TEST_001",
    "transaction_id": "TXN_TEST_001",
    "status_code": "200",
    "gross_amount": "100000",
    "transaction_status": "settlement",
    "signature_key": "valid_signature_hash"
  }'

# Second request within 1 second
curl -X POST http://localhost:8000/api/v1/webhooks/payments \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": "ORDER_TEST_001",
    "transaction_id": "TXN_TEST_001",
    "status_code": "200",
    "gross_amount": "100000",
    "transaction_status": "settlement",
    "signature_key": "valid_signature_hash"
  }'
```

**Expected:**
- First request: 200 OK, processes payment
- Second request: 200 OK, `idempotent: true` (no processing)

### Test 2: Concurrent Webhooks (Race Condition)
```bash
# Use Apache Bench to send 10 concurrent requests
ab -n 10 -c 10 -p webhook_payload.json -T application/json \
  http://localhost:8000/api/v1/webhooks/payments
```

**Expected:**
- Only 1 request processes payment
- 9 requests return `idempotent: true`
- Database shows only 1 `processed_webhooks` record

### Test 3: Lock Timeout
```bash
# Simulate slow webhook processing
# Add sleep(15) in controller to exceed 10s block timeout

# Send 2 concurrent requests
# Request A: Takes 15 seconds to process
# Request B: Waits 10s for lock, then times out with 429
```

**Expected:**
- Request A: 200 OK after 15 seconds
- Request B: 429 TOO MANY REQUESTS, `retry_after: 10`

## 📈 Monitoring

### Key Metrics
```php
// Log all idempotency hits
Log::info('Webhook idempotency hit', [
    'order_id' => $orderId,
    'ip' => $request->ip(),
    'first_processed' => $webhook->processed_at,
    'retry_after' => $webhook->processed_at->diffForHumans(),
]);
```

### Dashboard Queries
```sql
-- Count duplicate webhook attempts (last 24 hours)
SELECT COUNT(*) as total_duplicates
FROM processed_webhooks
WHERE created_at >= NOW() - INTERVAL '24 hours'
GROUP BY order_id
HAVING COUNT(*) > 1;

-- Most retried orders
SELECT order_id, COUNT(*) as retry_count
FROM processed_webhooks
GROUP BY order_id
ORDER BY retry_count DESC
LIMIT 10;

-- Failed webhooks (requires retry)
SELECT *
FROM processed_webhooks
WHERE status = 'failed'
AND created_at >= NOW() - INTERVAL '1 hour';
```

### Alerts
Set up monitoring for:
- ⚠️ Lock timeout rate > 5% → Redis performance issue
- ⚠️ Unique constraint violations > 10/hour → Clock skew or logic bug
- ⚠️ Failed webhooks > 1% → Payment gateway issues

## 🐛 Troubleshooting

### Issue: 429 Too Many Requests
**Cause:** Webhook processing taking > 10 seconds

**Solutions:**
1. Check slow database queries (missing indexes)
2. Increase lock block timeout:
   ```php
   $lock->block(20); // Increase to 20 seconds
   ```
3. Move heavy operations to queue:
   ```php
   dispatch(new ApplyPackageJob($payment));
   ```

### Issue: Duplicate Subscriptions Despite Fix
**Cause:** Redis cache failure or cluster split-brain

**Investigation:**
```bash
# Check Redis connectivity
redis-cli PING
# Should return: PONG

# Check lock keys
redis-cli KEYS "webhook_lock:*"

# Check lock TTL
redis-cli TTL "webhook_lock:ORDER123"
```

**Verification:**
```sql
-- Check for double package application
SELECT school_id, package_updated_at, package_type
FROM schools
WHERE package_updated_at >= NOW() - INTERVAL '1 hour'
ORDER BY package_updated_at DESC;
```

### Issue: ProcessedWebhook Table Growing Large
**Solution:** Implement retention policy

```php
// Artisan command: php artisan webhooks:cleanup
ProcessedWebhook::where('processed_at', '<', now()->subMonths(3))
    ->where('status', 'success')
    ->delete();
```

## ✅ Security Audit Compliance

- **Issue:** Webhook idempotency (HIGH priority)
- **Status:** ✅ FIXED via Redis locks + unique constraints + NULL checks
- **Coverage:**
  - ✅ Race condition prevention (Redis atomic locks)
  - ✅ Database-level duplicate prevention (UNIQUE constraints)
  - ✅ Application-level double-application prevention (NULL checks)
- **Testing:** Manual + concurrent testing required
- **Monitoring:** Log metrics, set up alerts for lock timeouts

## 📖 References
- Laravel Locks: https://laravel.com/docs/cache#atomic-locks
- Redis SET NX: https://redis.io/commands/setnx
- Midtrans Webhook: https://docs.midtrans.com/en/after-payment/http-notification
- Idempotency Patterns: https://brandur.org/idempotency-keys
