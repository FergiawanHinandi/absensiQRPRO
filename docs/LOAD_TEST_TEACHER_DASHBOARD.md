# Load Test: Teacher Dashboard - Today Sessions

## 📋 Overview

Load test simulating 50 teachers refreshing their dashboard every 10 seconds.

---

## 🎯 Test Profile

| Parameter | Value |
|-----------|-------|
| **Concurrent Teachers** | 50 |
| **Refresh Interval** | 10 seconds |
| **Test Duration** | 5 minutes |
| **Request Type** | GET |
| **Endpoint** | `/api/v1/teacher/today-sessions` |

---

## ✅ Pass Criteria

| Metric | Threshold |
|--------|-----------|
| **Response Time (p95)** | < 800ms |
| **Max Response Time** | < 5000ms (no timeout) |
| **Error Rate** | < 5% |
| **Timeout Rate** | < 1% |

---

## 🚀 Quick Start

```bash
# Run the load test
k6 run tests/load/teacher-dashboard-load-test.js

# With custom URL
k6 run -e API_URL=http://localhost:8000 tests/load/teacher-dashboard-load-test.js

# With API token
k6 run -e API_TOKEN=your-token tests/load/teacher-dashboard-load-test.js
```

---

## 📊 Expected Results

```
✓ http_req_duration (p95): < 800ms
✓ http_req_duration (max): < 5000ms
✓ errors rate: < 5%
✓ timeouts rate: < 1%
✓ Total requests: ~150 (50 teachers × 30 refreshes)
✓ Throughput: ~5 req/s
```

---

## 🔍 What Gets Tested

**Realistic Teacher Behavior:**
- 50 teachers logged in simultaneously
- Each refreshes dashboard every 10s
- Simulates real classroom monitoring
- Tests sustained load over 5 minutes

**System Components:**
- Database query performance
- Caching effectiveness
- API response consistency
- Session management
- Connection pooling

---

## 📈 Sample Output

```
     ✓ status is 200
     ✓ response has success field
     ✓ response has data field
     ✓ response time < 800ms
     ✓ no timeout
     ✓ no server errors

     checks.........................: 100.00% ✓ 150       ✗ 0
     data_received..................: 450 kB  1.5 kB/s
     data_sent......................: 75 kB   250 B/s
     errors.........................: 0.00%   ✓ 0         ✗ 150
     http_req_blocked...............: avg=500µs  min=0s   med=0s   max=20ms   p(95)=2ms   
     http_req_duration..............: avg=245ms  min=50ms med=200ms max=650ms  p(95)=450ms ✅
     http_req_failed................: 0.00%   ✓ 0         ✗ 150
     http_reqs......................: 150     0.5/s
     iterations.....................: 150     0.5/s
     response_time..................: avg=245ms  min=50ms med=200ms max=650ms  p(95)=450ms ✅
     timeouts.......................: 0.00%   ✓ 0         ✗ 150 ✅
     vus............................: 50      min=0       max=50
```

---

## ⚡ Performance Tips

### Database Optimization

```sql
-- Index for fast session lookups
CREATE INDEX idx_sessions_today 
ON sessions(teacher_id, date, status);

-- Cache frequently accessed data
-- (sessions for current day)
```

### Laravel Optimization

```php
// Cache today's sessions for each teacher
Cache::remember("teacher:{$teacherId}:today-sessions", 60, function() {
    return Session::where('teacher_id', $teacherId)
        ->whereDate('date', today())
        ->with(['class', 'subject'])
        ->get();
});
```

### Expected Response Structure

```json
{
  "success": true,
  "data": {
    "sessions": [
      {
        "id": 1,
        "class_name": "X-A",
        "subject_name": "Mathematics",
        "start_time": "08:00:00",
        "end_time": "09:00:00",
        "attendance_count": 28,
        "total_students": 30
      }
    ],
    "teacher_info": {
      "id": 1,
      "name": "Pak Budi"
    },
    "date": "2026-02-02"
  }
}
```

---

## 🎯 Why This Test Matters

**Real-World Scenario:**
- Teachers actively monitor attendance during class hours
- Multiple teachers checking simultaneously (morning peak)
- Dashboard must remain responsive
- No degradation over time

**Performance Goals:**
- Fast response = Better UX
- No timeouts = Reliable system
- Consistent performance = Production-ready

---

## ✅ No Project Disruption

- ✅ Read-only GET requests
- ✅ No data modification
- ✅ Isolated test script
- ✅ No cleanup needed
- ✅ Safe to run anytime

---

**Ready to test teacher dashboard performance!** 🚀
