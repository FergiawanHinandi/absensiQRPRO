# Load Test: Notification Dispatch

## 📋 Overview

Test to ensure the system can handle high-volume notification dispatching (1500 generated in 5 minutes) without message loss and with proper queue handling.

---

## 🎯 Test Scenario

**Simulate:** 1500 Attendance Events → triggering Notifications

| Parameter | Value |
|-----------|-------|
| **Total Notifications** | 1500 |
| **Duration** | 5 minutes |
| **Rate** | 5 requests/sec |
| **Endpoint** | `/api/v1/student/scan-attendance` |

---

## ✅ Pass Criteria

| Metric | Threshold | Validates |
|--------|-----------|-----------|
| **Response Time** | < 500ms | Async dispatching |
| **Queue Backlog** | Low | Worker efficiency |
| **Data Loss** | None | Reliability |
| **Retry Config** | Active | Fault tolerance |

---

## 🚀 Run the Test

```bash
# 1. Start Queue Worker (Important!)
php artisan queue:work --tries=3 &

# 2. Run Load Test
k6 run tests/load/notification-dispatch-load-test.js

# 3. Verify Results
cd backend
php ../tests/load/verify-notifications.php
```

---

## 📊 Expected Results

**Verification Output:**
```
✅ Queue Health: GOOD (Worker is handling load)
✅ Data Integrity: GOOD (~1500 events triggered)
✅ Retry configuration detected in queue settings.
✅ RESULT: PASSED
```

---

## 🔧 Queue Failures & Retries

To manually test retries, you can temporarily break the mail/notification service (e.g., allow exceptions) and observe `failed_jobs` incrementing within the verification script. The system should automatically retry based on `--tries=3`.

---

**Ready for Notification Load Testing!**
