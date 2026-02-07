# Load Test Quick Reference

## 🚀 Quick Start

```bash
# 1. Install k6
choco install k6

# 2. Run load tests
k6 run tests/load/attendance-scan-load-test.js        # Student attendance scan
k6 run tests/load/teacher-dashboard-load-test.js      # Teacher dashboard
k6 run tests/load/security-events-load-test.js        # Security events logging
k6 run tests/load/admin-report-export-load-test.js    # Admin report export

# 3. Verify results
cd backend
php ../tests/load/verify-duplicates.php               # After attendance scan
php ../tests/load/verify-security-events.php          # After security events
php ../tests/load/verify-export-jobs.php              # After report export
```

---

## 📊 Available Load Tests

### Test 1: Attendance Scan (High Concurrency)

| Metric | Value |
|--------|-------|
| Virtual Users | 800 |
| Ramp-up | 2 min |
| Duration | 5 min |
| Endpoint | POST /api/v1/student/scan-attendance |
| Pass Criteria | Response < 500ms, Error < 1%, No duplicates |

**Run:**
```bash
k6 run tests/load/attendance-scan-load-test.js
```

---

### Test 2: Teacher Dashboard (Sustained Refresh)

| Metric | Value |
|--------|-------|
| Concurrent Teachers | 50 |
| Refresh Interval | 10 seconds |
| Duration | 5 min |
| Endpoint | GET /api/v1/teacher/today-sessions |
| Pass Criteria | Response < 800ms, No timeout |

**Run:**
```bash
k6 run tests/load/teacher-dashboard-load-test.js
```

---

### Test 3: Security Events (Async Logging)

| Metric | Value |
|--------|-------|
| Total Invalid Scans | 2000 |
| Concurrent Users | 100 |
| Expected Status | 401/422/403 |
| Endpoint | POST /api/v1/student/scan-attendance |
| Pass Criteria | Response < 500ms, Events logged |

**Run:**
```bash
k6 run tests/load/security-events-load-test.js
php tests/load/verify-security-events.php
```

---

### Test 4: Admin Report Export (Job Queue)

| Metric | Value |
|--------|-------|
| Concurrent Admins | 10 |
| Report Type | Monthly PDF |
| Expected Status | 200/202 |
| Endpoint | POST /api/v1/admin/reports/export-pdf |
| Pass Criteria | Response < 1s, Jobs queued |

**Run:**
```bash
k6 run tests/load/admin-report-export-load-test.js
php tests/load/verify-export-jobs.php
```

---

### Test 5: Notification Dispatch (Queue Load)

| Metric | Value |
|--------|-------|
| Total Notifications | 1500 |
| Duration | 5 min |
| Endpoint | POST /api/v1/student/scan-attendance |
| Pass Criteria | Response < 500ms, Queue efficient |

**Run:**
```bash
k6 run tests/load/notification-dispatch-load-test.js
php tests/load/verify-notifications.php
```

---

## 📁 Files Created

1. **Attendance Scan Test**
   - `tests/load/attendance-scan-load-test.js` - k6 load test script
   - `tests/load/verify-duplicates.php` - Duplicate verification
   - `docs/LOAD_TEST_ATTENDANCE_SCAN.md` - Full documentation

2. **Teacher Dashboard Test**
   - `tests/load/teacher-dashboard-load-test.js` - k6 load test script
   - `docs/LOAD_TEST_TEACHER_DASHBOARD.md` - Full documentation

3. **Security Events Test**
   - `tests/load/security-events-load-test.js` - k6 load test script
   - `tests/load/verify-security-events.php` - Event verification
   - `docs/LOAD_TEST_SECURITY_EVENTS.md` - Full documentation

4. **Admin Report Export Test**
   - `tests/load/admin-report-export-load-test.js` - k6 load test script
   - `tests/load/verify-export-jobs.php` - Job verification
   - `docs/LOAD_TEST_ADMIN_REPORT_EXPORT.md` - Full documentation

5. **Notification Dispatch Test**
   - `tests/load/notification-dispatch-load-test.js` - k6 load test script
   - `tests/load/verify-notifications.php` - Queue verification
   - `docs/LOAD_TEST_NOTIFICATIONS.md` - Full documentation

---

## 🎯 Quick Commands

```bash
# Attendance Scan Test
k6 run tests/load/attendance-scan-load-test.js
php tests/load/verify-duplicates.php

# Teacher Dashboard Test
k6 run tests/load/teacher-dashboard-load-test.js

# Security Events Test
k6 run tests/load/security-events-load-test.js
php tests/load/verify-security-events.php

# Admin Report Export Test
k6 run tests/load/admin-report-export-load-test.js
php tests/load/verify-export-jobs.php

# Notification Dispatch Test
k6 run tests/load/notification-dispatch-load-test.js
php tests/load/verify-notifications.php

# Run ALL tests
k6 run tests/load/attendance-scan-load-test.js && \
k6 run tests/load/teacher-dashboard-load-test.js && \
k6 run tests/load/security-events-load-test.js && \
k6 run tests/load/admin-report-export-load-test.js && \
k6 run tests/load/notification-dispatch-load-test.js
```

---

## 📈 Expected Results

**Attendance Scan:**
```
✓ http_req_duration (p95): < 500ms
✓ errors rate: < 1%
✓ duplicate records: 0
✓ throughput: ~80 req/s
```

**Teacher Dashboard:**
```
✓ http_req_duration (p95): < 800ms
✓ max response: < 5000ms (no timeout)
✓ errors rate: < 5%
✓ throughput: ~5 req/s
```

**Security Events (Async):**
```
✓ response time (p95): < 500ms
✓ security_events: ~2000 logged
✓ slow_requests: < 5%
```

**Admin Report Export (Queued):**
```
✓ response time (p95): < 1000ms
✓ jobs_queued: 10
✓ memory usage: normal
```

**Notification Dispatch:**
```
✓ response time: < 500ms
✓ jobs_dispatched: ~1500
✓ error rate: < 1%
```

---

**Full Load Test Suite Ready!** 🚀
