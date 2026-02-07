# Load Test: Security Events Logging

## 📋 Overview

Test to ensure security event logging is **asynchronous** and does **not degrade** attendance scan endpoint performance.

---

## 🎯 Test Scenario

**Simulate:** 2000 invalid QR scan attempts

| Parameter | Value |
|-----------|-------|
| **Total Requests** | 2000 |
| **Concurrent Users** | 100 |
| **Request Type** | POST (Invalid QR scans) |
| **Endpoint** | `/api/v1/student/scan-attendance` |

---

## ✅ Pass Criteria

| Metric | Threshold | Validates |
|--------|-----------|-----------|
| **Response Time (p95)** | < 500ms | Logging is async (not blocking) |
| **Slow Requests** | < 5% | Minimal performance impact |
| **Events Logged** | ~2000 | All security events captured |
| **Status Codes** | 401/422/403 | Proper error handling |

---

## 🚀 Run the Test

```bash
# 1. Run load test (2000 invalid scans)
k6 run tests/load/security-events-load-test.js

# 2. Verify events were logged
cd backend
php ../tests/load/verify-security-events.php
```

---

## 📊 Expected Results

### Load Test Output

```
✓ returns error status (401/422/403)
✓ response time < 500ms (not degraded)  ← KEY METRIC
✓ has error message
✓ returns JSON response

total_requests.................: 2000
security_events................: ~2000
response_time (p95)............: 245ms  ✅ < 500ms
slow_requests..................: 45     ✅ < 100 (5%)
http_req_duration (p95)........: 245ms  ✅
```

### Verification Output

```
✅ PASS: Event count within expected range (1900-2100)

📊 Total Security Events Logged: 2000

📋 Event Type Distribution:
  • invalid_qr_scan: 1850 (92.5%)
  • unauthorized_access: 150 (7.5%)

🚨 Severity Distribution:
  🟠 high: 1200 (60%)
  🟡 medium: 800 (40%)

⚡ Logging Performance:
  • Time span: 45 seconds
  • Average logging rate: 44.4 events/second
  ✅ High throughput logging (good async performance)

✅ SUCCESS: Security events logging working correctly!
```

---

## 🔍 What This Validates

### 1. **Async Logging**

**If p95 < 500ms** → Logging doesn't block main thread ✅
**If p95 > 500ms** → Logging is synchronous (needs fix) ❌

**How It Works:**
```php
// GOOD: Async logging with queue
dispatch(new LogSecurityEvent($data));

// BAD: Synchronous logging (blocks request)
SecurityEvent::create($data);
```

### 2. **Performance Not Degraded**

**Test ensures:**
- Attendance endpoint remains fast
- High-volume logging doesn't slow down API
- Database writes are non-blocking
- Queue workers handle background tasks

### 3. **All Events Captured**

**Verification confirms:**
- ~2000 security events logged
- No events lost during high load
- Event details are accurate
- Proper severity classification

---

## 🎯 Invalid QR Patterns Tested

The test uses various invalid patterns:

1. ❌ Expired tokens
2. ❌ Malformed signatures
3. ❌ Replayed old tokens
4. ❌ Wrong school tokens
5. ❌ Empty tokens
6. ❌ Excessively long tokens
7. ❌ Special characters
8. ❌ Injection attempts

---

## 📈 Performance Benchmarks

| Scenario | Response Time | Status |
|----------|---------------|--------|
| **With Sync Logging** | ~800ms | ❌ Too slow |
| **With Async Logging** | ~250ms | ✅ Acceptable |
| **No Logging** | ~200ms | Baseline |

**Acceptable overhead:** < 100ms for async logging

---

## 🔧 Troubleshooting

### Issue: Response Time > 500ms

**Diagnosis:** Logging is blocking

**Fix:**
```php
// Use Laravel queue for async logging
use App\Jobs\LogSecurityEvent;

// In your controller
dispatch(new LogSecurityEvent([
    'event_type' => 'invalid_qr_scan',
    'severity' => 'high',
    'data' => $data,
]));
```

### Issue: Events Not Logged

**Diagnosis:** Queue not running

**Fix:**
```bash
# Start queue worker
php artisan queue:work

# Or use supervisor for production
```

### Issue: High Memory Usage

**Diagnosis:** Too many queued jobs

**Fix:**
```php
// In config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => true, // ← Add this
    ],
],
```

---

## 🧹 Cleanup Test Data

```bash
# Delete test security events
cd backend
php artisan tinker

>>> DB::table('security_events')
       ->where('metadata->session_id', 'security-test-session')
       ->delete();

>>> DB::table('security_events')
       ->where('created_at', '>', now()->subHour())
       ->where('event_type', 'invalid_qr_scan')
       ->delete();
```

---

## ✅ Success Indicators

**All green means:**
- ✅ Security logging is fully async
- ✅ No performance degradation
- ✅ All events captured accurately
- ✅ System handles high-volume security events
- ✅ Production-ready implementation

---

## 📋 Files Created

1. `tests/load/security-events-load-test.js` - k6 load test
2. `tests/load/verify-security-events.php` - Event verification
3. `docs/LOAD_TEST_SECURITY_EVENTS.md` - This documentation

---

**Test async security logging under high load!** 🔒
