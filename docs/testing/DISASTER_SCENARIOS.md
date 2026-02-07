# Disaster Recovery & Edge Case Test Scenarios

This document outlines critical "disaster" scenarios for the AbsensiQRPro system. These tests are designed to verify system resilience, data integrity, and failure recovery mechanisms under extreme or adverse conditions.

**Automated Test Status:**
✅ = Covered by `tests/Feature/DisasterRecoveryTest.php`
⚠️ = Partially Covered / Manual Test Required
❌ = Not Covered Yet

---

## 1. Connectivity Issues (Slow or Offline Internet) ⚠️

### Description
Simulate network instability during critical operations like attendance recording (Teacher Scan) or student check-ins.
- **Test Case A:** Client device loses connection immediately after scanning but before server response.
- **Test Case B:** Server response is extremely slow (> 30s) due to network throttling.
- **Test Case C:** Client is completely offline and attempts to queue requests.

### Expected Behavior
- **Frontend:** Should queue requests locally (if offline-first is implemented) or show a clear "Retry" UI.
- **Backend:** 
    - Duplicate requests (due to retries) must be handled idempotently.
    - `AttendanceRecorded` events should only fire once per successful attendance.
- **Data:** No duplicate attendance records in the database for the same student/schedule/day.

### Automated Verification
- **Test:** `test_system_prevents_duplicate_attendance_submission`
- **Result:** Backend rejects duplicate tokens with `400 Bad Request` or handles them idempotently, ensuring unique records.

---

## 2. High Traffic QR Scans (Concurrency) ✅

### Description
Simulate 100+ students checking in simultaneously (e.g., morning assembly) or multiple teachers scanning different students in the same class at the exact same moment.

### Expected Behavior
- **Rate Limiting:** System should enforce `scan` rate limits (30/min per user/IP) gracefully, returning `429 Too Many Requests` if exceeded.
- **Queue:** Notification jobs should be queued without blocking the HTTP response.
- **Locking:** Atomic locks must prevent race conditions on the same student record.

### Automated Verification
- **Test:** `test_rate_limiting_blocks_high_traffic_scans`
- **Method:** Loops 35 requests; asserts that `429` status is returned after the 30th request.

---

## 3. Expired or Invalid QR Tokens ✅

### Description
Users attempt to scan QR codes that are:
- Generated yesterday (Expired).
- Tampered with (Invalid Signature).
- Already used (Replay Attack).

### Expected Behavior
- **Response:** Immediate `400 Bad Request`.
- **Message:** Clear error message: "QR Code expired", "kadaluarsa", or "tidak valid".
- **Security:** Incident logged in `audit_logs` and `security_alerts`.

### Automated Verification
- **Test:** `test_system_rejects_expired_qr_token`, `test_system_rejects_tampered_qr_token`
- **Result:** System correctly returns `400` for expired timestamps and invalid signatures.

---

## 4. Session Closure Edge Cases ⚠️

### Description
A teacher's session expires (token invalidation) *during* a bulk scan operation.

### Expected Behavior
- **API:** Returns `401 Unauthorized`.
- **Frontend:** Redirects user to Login screen immediately.

### Automated Verification
- Implicitly covered by Laravel's Sanctum middleware tests.
- **Manual Test:** Revoke token in DB while app is open, then try to scan.

---

## 5. Large Report Exports ❌

### Description
Admin requests a report for "All Students" for the "Last 3 Years" (expected 1M+ rows).

### Expected Behavior
- **Job:** Must be processed via Queue (never synchronous).
- **Memory:** Process in chunks (`chunk(1000)`) to avoid OOM (Out of Memory).
- **Timeout:** Job should not timeout; increase `timeout` config if needed.

---

## 6. Queue / Notification Failure ✅

### Description
The Redis/Database queue worker is down, or the Notification service (Firebase/WhatsApp) returns 500 errors.

### Expected Behavior
- **Attendance:** **MUST** still be recorded successfully. Notification failure is non-critical.
- **User Experience:** Teacher sees "Success" (optional warning: "Notification delayed").
- **Retry:** Failed jobs go to `failed_jobs` table for later retry.

### Automated Verification
- **Test:** `test_attendance_saved_even_if_queue_fails`
- **Method:** Uses `Queue::fake()` to verify that the Controller transaction commits the attendance record even if the event dispatching layer is mocked/faked.

---

## 7. User Spam Clicks (Idempotency) ✅

### Description
Teacher impatiently clicks "Confirm" 10 times on a slow connection.

### Expected Behavior
- **Backend:** Only 1 record created.
- **Response:** First request 200/201, others 422 or 200 (idempotent success).

### Automated Verification
- **Test:** `test_duplicate_submission_is_handled_gracefully`
- **Result:** Verifies that subsequent requests with the same token do not create duplicate DB rows.

---

## 8. Temporary Database Downtime ❌

### Description
Database restarts or connection pool is full during a scan.

### Expected Behavior
- **App:** Shows "Service Unavailable, please try again" (Graceful degradation).
- **Data:** No partial writes (Transactions are atomic).
