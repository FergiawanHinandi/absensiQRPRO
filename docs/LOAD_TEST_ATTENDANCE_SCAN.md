# Load Test: QR Attendance Scan Endpoint

## 📋 Overview

Performance load test for the QR attendance scanning endpoint to ensure system stability under high concurrency.

---

## 🎯 Test Profile

| Parameter | Value |
|-----------|-------|
| **Virtual Users** | 800 |
| **Ramp-up Time** | 2 minutes |
| **Test Duration** | 5 minutes |
| **Total Duration** | 8 minutes (including ramp-down) |
| **Endpoint** | `POST /api/v1/student/scan-attendance` |

---

## 📊 Pass Criteria

| Metric | Threshold |
|--------|-----------|
| **Average Response Time** | < 500ms |
| **95th Percentile** | < 500ms |
| **Error Rate** | < 1% |
| **HTTP Failures** | < 1% |
| **Duplicate Records** | 0 (zero tolerance) |

---

## 🛠️ Prerequisites

### 1. Install k6

**Windows (via Chocolatey):**
```bash
choco install k6
```

**Windows (via Scoop):**
```bash
scoop install k6
```

**macOS:**
```bash
brew install k6
```

**Linux:**
```bash
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

### 2. Prepare Environment

Ensure your Laravel application is running:
```bash
cd backend
php artisan serve
```

---

## 🚀 Running the Load Test

### Method 1: Basic Run
```bash
k6 run tests/load/attendance-scan-load-test.js
```

### Method 2: With Environment Variables
```bash
k6 run \
  -e API_URL=http://localhost:8000 \
  -e API_TOKEN=your-test-token-here \
  tests/load/attendance-scan-load-test.js
```

### Method 3: With Custom Settings
```bash
k6 run \
  --vus 800 \
  --duration 5m \
  -e API_URL=http://localhost:8000 \
  tests/load/attendance-scan-load-test.js
```

### Method 4: With HTML Report
```bash
k6 run --out json=results.json tests/load/attendance-scan-load-test.js
```

---

## 📈 Understanding Results

### Sample Output

```
     ✓ status is 200 or 201
     ✓ response has success field
     ✓ response time < 500ms
     ✓ no server errors

     checks.........................: 100.00% ✓ 24000      ✗ 0
     data_received..................: 15 MB   50 kB/s
     data_sent......................: 12 MB   40 kB/s
     errors.........................: 0.15%   ✓ 36        ✗ 23964
     http_req_blocked...............: avg=1.2ms   min=0s    med=0s    max=150ms   p(95)=5ms   
     http_req_connecting............: avg=500µs   min=0s    med=0s    max=50ms    p(95)=2ms   
     http_req_duration..............: avg=245ms   min=50ms  med=200ms max=1.2s    p(95)=450ms 
     http_req_failed................: 0.15%   ✓ 36        ✗ 23964
     http_req_receiving.............: avg=2ms     min=0s    med=1ms   max=100ms   p(95)=8ms   
     http_req_sending...............: avg=1ms     min=0s    med=0s    max=50ms    p(95)=3ms   
     http_req_tls_handshaking.......: avg=0s      min=0s    med=0s    max=0s      p(95)=0s    
     http_req_waiting...............: avg=242ms   min=48ms  med=198ms max=1.19s   p(95)=445ms 
     http_reqs......................: 24000   80/s
     iteration_duration.............: avg=2.5s    min=1s    med=2.2s  max=5s      p(95)=4s    
     iterations.....................: 24000   80/s
     response_time..................: avg=245ms   min=50ms  med=200ms max=1.2s    p(95)=450ms 
     vus............................: 800     min=1       max=800
     vus_max........................: 800     min=800     max=800
```

### Key Metrics to Watch

1. **http_req_duration (p95)** - Should be < 500ms ✅
2. **errors rate** - Should be < 1% (0.15% in example) ✅
3. **http_req_failed** - Should be < 1% ✅
4. **http_reqs** - Total requests (throughput)

---

## ✅ Verify Duplicate Prevention

After the load test completes, run the verification script:

```bash
cd backend
php ../tests/load/verify-duplicates.php
```

### Expected Output (PASS)

```
================================================================================
Duplicate Attendance Record Verification
================================================================================

Checking for duplicates in session: session-2026-02-02-class-xa

✅ PASS: No duplicate records found!
   All attendance records are unique.

Total attendance records: 24000
Unique students: 24000
Duplicate percentage: 0%

================================================================================
✅ SUCCESS: Duplicate prevention working correctly!
================================================================================
```

### Sample Output (FAIL)

```
❌ FAIL: Found 5 duplicate records:

   - Student: student-123-1
     Date: 2026-02-02
     Count: 2

   - Student: student-456-1
     Date: 2026-02-02
     Count: 3

Total attendance records: 24005
Unique students: 24000
Duplicate percentage: 0.02%

⚠️  WARNING: Duplicate prevention mechanism needs review!
```

---

## 🔍 Troubleshooting

### Issue: High Error Rate

**Symptoms:** Error rate > 1%

**Solutions:**
1. Check database connections
2. Increase `max_connections` in MySQL
3. Optimize database indexes
4. Enable query caching
5. Scale application horizontally

### Issue: Slow Response Times

**Symptoms:** p95 > 500ms

**Solutions:**
1. Enable Redis caching
2. Optimize database queries
3. Add database indexes
4. Use queue for async tasks
5. Enable opcache

### Issue: Duplicate Records Created

**Symptoms:** Duplicate percentage > 0%

**Solutions:**
1. Add unique index: `(student_id, session_id, attendance_date)`
2. Use database transactions
3. Implement optimistic locking
4. Use `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE`
5. Add application-level duplicate check

---

## 📊 Test Scenarios

### Scenario 1: Normal Load (Current)
- 800 users
- 2min ramp-up
- 5min duration

### Scenario 2: Peak Load
```bash
# Modify options in script:
stages: [
    { duration: '1m', target: 1500 },
    { duration: '10m', target: 1500 },
    { duration: '1m', target: 0 },
]
```

### Scenario 3: Stress Test
```bash
# Modify options in script:
stages: [
    { duration: '2m', target: 2000 },
    { duration: '5m', target: 2000 },
    { duration: '2m', target: 3000 },
    { duration: '5m', target: 3000 },
    { duration: '1m', target: 0 },
]
```

---

## 🎯 Performance Tuning Tips

### Database Optimizations

```sql
-- Add composite index for fast lookups
CREATE INDEX idx_attendance_student_session_date 
ON attendance_logs(student_id, session_id, attendance_date);

-- Add unique constraint to prevent duplicates
ALTER TABLE attendance_logs 
ADD UNIQUE KEY unique_attendance (student_id, session_id, attendance_date);
```

### Laravel Optimizations

```php
// In config/database.php
'connections' => [
    'mysql' => [
        'options' => [
            PDO::ATTR_PERSISTENT => true,  // Connection pooling
            PDO::ATTR_EMULATE_PREPARES => true,
        ],
    ],
],

// Enable query result caching
DB::table('attendance_logs')
    ->where('session_id', $sessionId)
    ->remember(60)
    ->get();
```

### Server Optimizations

```bash
# Increase PHP-FPM workers
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20

# Enable opcache
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
```

---

## 📝 Test Report Template

After completing the load test, document results:

```markdown
## Load Test Results - [Date]

### Test Configuration
- Virtual Users: 800
- Duration: 5 minutes
- Total Requests: [X]

### Performance Metrics
- Average Response Time: [X]ms
- 95th Percentile: [X]ms
- Error Rate: [X]%
- Throughput: [X] req/s

### Pass/Fail
- [ ] Response time < 500ms
- [ ] Error rate < 1%
- [ ] No duplicate records

### Issues Found
1. [Issue description]
2. [Issue description]

### Recommendations
1. [Recommendation]
2. [Recommendation]
```

---

## ✅ No Project Disruption

- ✅ Tests in isolated `/tests/load/` directory
- ✅ Uses test session ID (no production impact)
- ✅ Requires explicit API token
- ✅ Easy to clean up test data
- ✅ No modifications to existing code

---

## 🧹 Cleanup Test Data

After testing, clean up test records:

```sql
DELETE FROM attendance_logs 
WHERE session_id = 'session-2026-02-02-class-xa';
```

Or via Laravel:

```bash
php artisan tinker
>>> DB::table('attendance_logs')->where('session_id', 'session-2026-02-02-class-xa')->delete();
```

---

**Load test ready for execution!** 🚀
