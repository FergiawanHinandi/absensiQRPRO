# Red Team Simulation Plan - AbsensiQRPro SaaS Attendance System

**Classification:** CONFIDENTIAL - Internal Security Use Only
**Version:** 1.0
**Date:** 10 Februari 2026
**Author:** Offensive Security Lead
**Target:** AbsensiQRPro Production Environment

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Attack Matrix](#2-attack-matrix)
3. [Attack Scenarios (10 Vectors)](#3-attack-scenarios)
4. [Concurrency Stress Tests](#4-concurrency-stress-tests)
5. [Defense Validation Table](#5-defense-validation-table)
6. [Post-Attack Report Template](#6-post-attack-report-template)
7. [Incident Response Scripts](#7-incident-response-scripts)
8. [Remediation Checklist](#8-remediation-checklist)

---

## 1. Executive Summary

This plan defines a structured Red Team engagement against the AbsensiQRPro multi-tenant SaaS attendance system. The system uses Laravel 12 (backend), React 19 (frontend-web), and React Native (mobile). Critical assets include attendance records, tenant data isolation, subscription billing, and QR-based authentication tokens.

### Architecture Overview (Attack Surface)

```
Mobile App (React Native)
    |-- SSL Pinning (react-native-ssl-pinning)
    |-- Secure Client (secureClient.ts)
    |-- Offline Queue (encrypted)
    |
    v
API Gateway (Laravel 12 + Sanctum)
    |-- SecurityHeaders Middleware (CSP, HSTS, X-Frame-Options)
    |-- AttendanceRateLimitMiddleware (10 scan/min)
    |-- IdempotencyMiddleware (X-Idempotency-Key UUID v4)
    |-- TenantContextMiddleware (school_id binding)
    |-- ValidateTokenBinding (device fingerprint + IP country)
    |
    v
Service Layer
    |-- QrService (HMAC-SHA256, nonce, expiry)
    |-- AttendanceCheckInService (4-layer idempotency)
    |-- AttendanceLockService (Redis distributed locks)
    |-- TokenHardeningService (15-min access, 30-day session max)
    |
    v
Data Layer
    |-- PostgreSQL (SchoolScope global scope, unique constraints)
    |-- Redis (locks, nonces, rate limits, circuit breaker)
    |-- Queue (TenantAwareJob, school_id validation)
```

### Threat Model

| Threat Actor        | Motivation                     | Capability |
|---------------------|--------------------------------|------------|
| **Student**         | Fake attendance, skip class    | Low        |
| **Colluding Group** | Share QR via WhatsApp          | Medium     |
| **Rogue Teacher**   | Manipulate records             | Medium     |
| **External Hacker** | Data theft, ransomware         | High       |
| **Competitor**      | Service disruption, data leak  | High       |
| **Insider Threat**  | Cross-tenant data exfiltration | High       |

---

## 2. Attack Matrix

| # | Attack Vector | Target Component | CVSS | Difficulty | Existing Defense | Gap Found |
|---|---------------|------------------|------|------------|------------------|-----------|
| 1 | QR Replay Attack | QrService, nonce cache | 7.5 | Medium | Nonce one-time use + HMAC + expiry (10s) | Redis failure fallback |
| 2 | WhatsApp QR Sharing | QR expiry, device binding | 6.8 | Low | 10-second QR expiry + device fingerprint | No geo-fencing on scan |
| 3 | Race Condition Spam | AttendanceLockService | 8.1 | High | Redis lock + DB unique constraint + idempotency | Circuit breaker fallback |
| 4 | Cross-Tenant Injection | SchoolScope, BelongsToSchool | 9.1 | High | Global scope + policy + trait | Console/queue bypass |
| 5 | Subscription Bypass | CheckActiveSubscription | 7.2 | Medium | Cache + DB check + lock | 3-day grace period |
| 6 | Redis Poisoning | SafeRedisService, CircuitBreaker | 8.4 | High | Circuit breaker + file-based state | Lock fallback to unprotected query |
| 7 | API Brute Force | LoginRateLimiter, rate limiting | 5.3 | Low | 5 login/min + account lockout + timing mitigation | Rate limit per IP only on some endpoints |
| 8 | JWT/Token Manipulation | TokenHardeningService, Sanctum | 7.8 | High | 15-min expiry + refresh rotation + device binding | Device fingerprint spoofable |
| 9 | IDOR | AttendancePolicy, SchoolScope | 8.6 | Medium | Policy checks + global scope + ownership validation | Super admin audit completeness |
| 10 | Queue Tampering | TenantAwareJob, queue workers | 6.5 | High | School validation at dispatch + ensureTenantContext | Developer compliance required |

---

## 3. Attack Scenarios

---

### ATTACK 1: QR Replay Attack

**Objective:** Re-use a captured QR token to fraudulently record attendance for another student.

#### Attack Vector
```
1. Attacker intercepts QR token via:
   a. Screen recording (screenshot of QR code displayed by teacher)
   b. Network MITM (if SSL pinning bypassed)
   c. Shoulder surfing (camera capture)
2. Attacker submits the captured token to POST /api/v1/attendance/scan
3. If nonce not yet consumed, attendance is recorded fraudulently
```

#### Exploit Attempt
```bash
# Step 1: Capture QR token (simulated)
QR_TOKEN="eyJzaWQiOjEsInFpZCI6Mywid..."

# Step 2: First scan (legitimate)
curl -X POST https://api.target.com/api/v1/attendance/scan \
  -H "Authorization: Bearer $STUDENT_TOKEN" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Device-ID: device-abc-123" \
  -d '{"qr_token": "'$QR_TOKEN'", "lat": -7.123, "lng": 112.456}'

# Step 3: Replay same token (attack)
curl -X POST https://api.target.com/api/v1/attendance/scan \
  -H "Authorization: Bearer $ATTACKER_TOKEN" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Device-ID: device-xyz-999" \
  -d '{"qr_token": "'$QR_TOKEN'", "lat": -7.123, "lng": 112.456}'
```

#### Expected System Behavior
| Step | Expected Response | HTTP Code |
|------|-------------------|-----------|
| First scan | `{"success": true, "message": "Absensi berhasil"}` | 200 |
| Replay scan | `{"error": "QR code already used (replay attack detected)"}` | 400 |

#### Defense Layers Activated
| Layer | Mechanism | File | Triggered? |
|-------|-----------|------|------------|
| Nonce | `Cache::has('qr_nonce:{nonce}')` | `QrService.php:112` | YES |
| HMAC | `hash_equals()` signature check | `QrService.php:92` | YES (pass) |
| Expiry | `exp < now()->timestamp` | `QrService.php:104` | Depends on timing |
| Redis idempotency | `tryAcquire()` SET NX | `AttendanceCheckInService.php:110` | YES |
| DB unique constraint | `(school_id, student_id, schedule_id, date)` | Migration | YES |

#### Log Generated?
**YES** - Security channel:
```json
{
  "channel": "attendance_security",
  "level": "warning",
  "message": "QR nonce replay detected",
  "context": {
    "nonce": "aB3cD4eF5gH6iJ7k",
    "student_id": 42,
    "ip": "103.28.x.x",
    "device_id": "device-xyz-999"
  }
}
```

#### Alert Triggered?
**YES** - `AttendanceRateLimitMiddleware` triggers SecurityAlert if multiple failed scans from same device.

#### Data Integrity Preserved?
**YES** - No duplicate attendance record created.

#### Vulnerability Assessment
- **Current Risk: LOW** - 7-layer defense prevents replay
- **Edge Case: MEDIUM** - If Redis is unavailable, nonce cache fails and replay may succeed before DB unique constraint kicks in (race window ~50ms)

---

### ATTACK 2: WhatsApp QR Sharing

**Objective:** Students share QR screenshot via WhatsApp so absent friends can scan remotely.

#### Attack Vector
```
1. Present student screenshots QR code from teacher's device
2. Shares image via WhatsApp group to absent student
3. Absent student decodes QR image and submits token from remote location
4. Time elapsed: 15-60 seconds (WhatsApp delivery delay)
```

#### Exploit Attempt
```bash
# Student B (absent) attempts scan with shared QR
curl -X POST https://api.target.com/api/v1/attendance/scan \
  -H "Authorization: Bearer $STUDENT_B_TOKEN" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -d '{
    "qr_token": "'$SHARED_QR_TOKEN'",
    "lat": -7.999,
    "lng": 113.999
  }'
```

#### Expected System Behavior
| Defense | Check | Result |
|---------|-------|--------|
| QR Expiry (10s) | `exp < now()` | **BLOCKED** if >10s elapsed |
| QR Expiry (configurable) | School policy up to 60s | May PASS if within window |
| Nonce one-time use | Nonce already consumed by Student A | **BLOCKED** if A scanned first |
| GPS validation | Server-side distance check | **Depends** on implementation |
| Device fingerprint | Different device_id | Logged but **not blocking** |

#### Defense Gap
- **10-second QR window** is effective but tight. If QR refresh is >10s, sharing is viable.
- **GPS validation is server-sided** but not strictly enforced for all scan types.
- **No geo-fence radius check** against school coordinates in current implementation.

#### Recommended Additional Defense
```php
// Add to AttendanceCheckInService::validateLocation()
$schoolLocation = $schedule->class->school->getLocation(); // lat, lng
$studentLocation = new Location($data['lat'], $data['lng']);
$distance = $schoolLocation->distanceTo($studentLocation);

if ($distance > config('attendance.max_scan_radius_meters', 500)) {
    throw AttendanceException::outsideRange($distance);
}
```

#### Log Generated?
**PARTIAL** - Device mismatch logged, but no specific "QR sharing" detection.

#### Alert Triggered?
**NO** - No specific alert for QR sharing pattern.

---

### ATTACK 3: Race Condition Spam

**Objective:** Send thousands of concurrent scan requests to create duplicate attendance records or cause system instability.

#### Attack Vector
```
1. Attacker scripts 5000 parallel POST /api/v1/attendance/scan
2. All requests use the same QR token but different idempotency keys
3. Goal: Overwhelm Redis locks, bypass unique constraints, or cause deadlocks
```

#### Exploit Attempt
```bash
# artillery-race-condition.yml
config:
  target: "https://api.target.com"
  phases:
    - duration: 5
      arrivalRate: 1000  # 5000 total requests in 5 seconds
  defaults:
    headers:
      Authorization: "Bearer {{token}}"
scenarios:
  - flow:
    - post:
        url: "/api/v1/attendance/scan"
        headers:
          X-Idempotency-Key: "{{ $randomUUID() }}"
        json:
          qr_token: "{{qr_token}}"
          lat: -7.123
          lng: 112.456
```

#### Expected System Behavior
| Request # | Defense Layer Hit | Result |
|-----------|-------------------|--------|
| 1 | Rate limiter (10/min) | PASS |
| 2-10 | Rate limiter | PASS |
| 11+ | Rate limiter | **429 Too Many Requests** |
| 1 (first through) | Redis lock acquired | Processing |
| 2 (concurrent) | Redis lock WAIT (8s max) | Queued |
| 2 (after lock release) | Nonce already consumed | **400 Already Used** |
| 2 (after lock release) | Redis idempotency SET NX | **BLOCKED** |
| 2 (after lock release) | DB unique constraint | **BLOCKED (failsafe)** |

#### Defense Stack (ordered by speed)
```
Layer 0:  Rate Limit          →  429 (blocks 4990/5000 requests)
Layer 1:  Redis Lock          →  Lock timeout (blocks concurrent)
Layer 2:  Nonce Cache         →  Replay detected (blocks reuse)
Layer 3:  Redis Idempotency   →  SET NX fails (blocks duplicate)
Layer 4:  DB Unique           →  Constraint violation (final safety)
```

#### Log Generated?
**YES** - Multiple channels:
- `attendance_security`: Rate limit violations
- `attendance`: Lock timeouts, idempotency blocks
- `security`: SecurityAlert created

#### Alert Triggered?
**YES** - `AttendanceRateLimitMiddleware` creates SecurityAlert on threshold breach.

#### Data Integrity Preserved?
**YES** - Only 1 attendance record created despite 5000 attempts.

---

### ATTACK 4: Cross-Tenant Data Injection

**Objective:** Access or modify attendance data belonging to a different school (tenant).

#### Attack Vector
```
1. Authenticated user from School A attempts to:
   a. Read attendance records from School B
   b. Create attendance records with School B's school_id
   c. Update a student's attendance from School B
   d. Access School B via API parameter tampering
```

#### Exploit Attempt
```bash
# Attack 4a: Direct ID reference to School B's attendance
curl -X GET https://api.target.com/api/v1/attendance/99999 \
  -H "Authorization: Bearer $SCHOOL_A_TOKEN"

# Attack 4b: Inject school_id in POST body
curl -X POST https://api.target.com/api/v1/attendance/manual \
  -H "Authorization: Bearer $SCHOOL_A_TOKEN" \
  -d '{
    "school_id": 2,
    "student_id": 500,
    "schedule_id": 300,
    "status": "present"
  }'

# Attack 4c: Super admin header injection
curl -X GET https://api.target.com/api/v1/attendance \
  -H "Authorization: Bearer $SCHOOL_A_TOKEN" \
  -H "X-School-Id: 2"
```

#### Expected System Behavior
| Attack | Defense | Result |
|--------|---------|--------|
| 4a: Read School B record | `SchoolScope` WHERE clause | **404** (record invisible) |
| 4b: Inject school_id | `BelongsToSchool` auto-fill from auth user | **school_id ignored**, uses auth user's school |
| 4b: Inject school_id | `validateSchoolOwnershipById()` in controller | **403** Unauthorized |
| 4c: X-School-Id header | `TenantContextMiddleware` checks role_type | **Ignored** (not super_admin) |

#### Defense Layers
```
SchoolScope (Global)     → WHERE school_id = $user->school_id (automatic)
BelongsToSchool (Create) → $model->school_id = $user->school_id (forced)
BelongsToSchool (Update) → isDirty('school_id') → throw Exception
AttendancePolicy         → $user->school_id !== $attendance->school_id → false
Controller               → validateSchoolOwnership() explicit check
```

#### Log Generated?
**YES** - SchoolScope logs bypass attempts with full context.

#### Alert Triggered?
**YES** - Policy denials logged to security channel.

#### Data Integrity Preserved?
**YES** - Multi-layer defense prevents any cross-tenant data access.

#### Edge Case: Console/Queue Context
```php
// SchoolScope.php line 14-16:
if (app()->runningInConsole() && !app()->runningUnitTests()) {
    return; // SCOPE DISABLED IN CONSOLE
}
```
- **Risk:** Artisan commands and queue workers bypass SchoolScope
- **Mitigation:** `TenantAwareJob` enforces school_id manually, but developer compliance required

---

### ATTACK 5: Subscription Bypass

**Objective:** Continue using the platform after subscription expires without paying.

#### Attack Vector
```
1. School's subscription expires
2. Exploit 3-day grace period in EnhancedCheckActiveSubscription
3. Repeatedly clear browser storage to reset client-side warnings
4. Attempt to bypass middleware by accessing unprotected endpoints
```

#### Exploit Attempt
```bash
# Attempt 1: Normal access during grace period (SUCCEEDS for 3 days)
curl -X GET https://api.target.com/api/v1/attendance/today \
  -H "Authorization: Bearer $EXPIRED_SCHOOL_TOKEN"

# Attempt 2: Access endpoint not behind subscription middleware
curl -X GET https://api.target.com/api/v1/user/me \
  -H "Authorization: Bearer $EXPIRED_SCHOOL_TOKEN"

# Attempt 3: Webhook replay to extend subscription
curl -X POST https://api.target.com/api/v1/webhooks/midtrans \
  -d '{"order_id": "old_order_123", "transaction_status": "settlement"}'
```

#### Expected System Behavior
| Attack | Defense | Result |
|--------|---------|--------|
| Grace period access | `GRACE_PERIOD_DAYS = 3` | **PASS** (by design, 3 days) |
| After grace period | `checkSubscription()` | **402 Payment Required** |
| Unprotected endpoint | No middleware applied | **PASS** (auth-only endpoints work) |
| Webhook replay | `hash_equals()` + `ProcessedWebhook` | **BLOCKED** (signature invalid or already processed) |

#### Defense Gap
- **Grace period exploitation:** Schools can operate for 3 free days after expiry
- **Cache timing:** 5-minute TTL means subscription status check delayed by up to 5 minutes
- **Partial access:** Some endpoints lack subscription middleware

#### Log Generated?
**YES** - Subscription expiry events logged.

#### Alert Triggered?
**PARTIAL** - Warning header `X-Subscription-Status: expiring-soon` but no admin alert.

---

### ATTACK 6: Redis Poisoning

**Objective:** Corrupt or disable Redis to cause system-wide security degradation.

#### Attack Vector
```
1. If Redis is externally accessible (misconfigured bind/firewall):
   a. FLUSHALL to clear all locks, nonces, rate limits
   b. SET specific keys to bypass security checks
   c. DEL nonce keys to enable QR replay
2. If Redis is internal, cause OOM via large key insertion
3. Exploit circuit breaker fallback to unprotected code paths
```

#### Exploit Attempt
```bash
# If Redis exposed (6379 with no AUTH)
redis-cli -h target-redis.internal FLUSHALL

# Targeted key deletion (enable QR replay)
redis-cli -h target-redis.internal DEL "qr_nonce:aB3cD4eF5gH6iJ7k"

# Rate limit bypass
redis-cli -h target-redis.internal DEL "attendance_rate:qr-scan:user:42"

# Lock bypass
redis-cli -h target-redis.internal DEL "attendance_lock:schedule:1:student:42:2026-02-10"
```

#### Expected System Behavior (with circuit breaker)
| Attack | Defense | Result |
|--------|---------|--------|
| FLUSHALL | Circuit breaker detects failures | **Degrades** to file cache |
| Nonce DEL | Cache miss = nonce not found | **QR REPLAY POSSIBLE** |
| Rate limit DEL | Counter reset | **Rate limit bypassed temporarily** |
| Lock DEL | Lock appears available | **Race condition possible** |
| Connection down | `SafeRedisService` circuit opens | **Fallback to non-atomic operations** |

#### Defense Gap - CRITICAL
```php
// SafeRedisService.php - lock() returns null on failure
public function lock(string $key, int $seconds = 5)
{
    try {
        return $this->circuitBreaker->execute(...);
    } catch (CircuitBreakerOpenException $e) {
        return null; // NULL = no lock protection
    }
}

// Calling code may not check for null:
$lock = $safeRedis->lock('key', 10);
if ($lock->block(5, function() { ... })) { // NULL->block() = CRASH
```

#### Log Generated?
**YES** - Circuit breaker state changes logged, Redis connection failures logged.

#### Alert Triggered?
**YES** - `RedisCircuitBreakerOpened` event dispatched.

#### Data Integrity Risk
**HIGH** - Redis failure removes nonce protection, lock protection, and rate limiting simultaneously. Database unique constraints are the last line of defense.

---

### ATTACK 7: API Brute Force

**Objective:** Enumerate valid usernames and crack passwords via automated login attempts.

#### Attack Vector
```
1. Username enumeration via timing differences
2. Password spraying across known usernames
3. Rate limit bypass via IP rotation
4. Credential stuffing from leaked databases
```

#### Exploit Attempt
```bash
# Password spraying (5 attempts per username per minute)
for user in teacher1 teacher2 admin1; do
  curl -X POST https://api.target.com/api/v1/auth/login \
    -d '{"username": "'$user'", "password": "Password123!"}'
done

# IP rotation via proxy list
for proxy in $(cat proxies.txt); do
  curl --proxy $proxy -X POST https://api.target.com/api/v1/auth/login \
    -d '{"username": "admin1", "password": "test123"}'
done
```

#### Expected System Behavior
| Attack | Defense | Result |
|--------|---------|--------|
| 6th attempt (same IP+user) | `RateLimiter::for('login')` | **429** (5/min limit) |
| Timing analysis | Dummy hash + `enforceMinimumResponseTime()` | **BLOCKED** (constant 100ms+) |
| IP rotation | Per-username rate limit | **Slowed** but not fully blocked |
| Account lockout | `shouldLockUser()` after N failures | **Account locked** |
| Error message | Generic "Kredensial tidak valid" | **No enumeration** |

#### Defense Layers
```
Layer 1: Global rate limit      → 60 req/min per IP (AppServiceProvider)
Layer 2: Login rate limit       → 5 req/min per (username|IP) (AppServiceProvider)
Layer 3: Timing mitigation      → 100ms minimum + random jitter (AuthController)
Layer 4: Dummy hash check       → Hash::check against dummy if user not found
Layer 5: Account lockout        → DB-based failure counter + lock
Layer 6: Generic error message  → Same message for all failure types
```

#### Log Generated?
**YES** - Every failed login attempt logged to `security` channel with IP, username, reason.

#### Alert Triggered?
**YES** - Account lockout triggers security alert.

---

### ATTACK 8: JWT/Token Manipulation

**Objective:** Forge, extend, or escalate privileges via token manipulation.

#### Attack Vector
```
1. Modify Sanctum token payload to change user_id or role
2. Use expired access token after expiry window
3. Reuse revoked refresh token
4. Bypass device fingerprint binding
```

#### Exploit Attempt
```bash
# Attempt 1: Use expired access token
curl -X GET https://api.target.com/api/v1/user/me \
  -H "Authorization: Bearer 42|expired_token_string_here"

# Attempt 2: Reuse revoked refresh token
curl -X POST https://api.target.com/api/v1/auth/refresh \
  -d '{"refresh_token": "previously_revoked_token"}'

# Attempt 3: Forge device fingerprint
curl -X POST https://api.target.com/api/v1/auth/refresh \
  -H "User-Agent: Mozilla/5.0 (exact copy of victim)" \
  -H "Accept-Language: id-ID" \
  -H "Accept-Encoding: gzip, deflate, br" \
  -d '{"refresh_token": "stolen_valid_refresh_token"}'
```

#### Expected System Behavior
| Attack | Defense | Result |
|--------|---------|--------|
| Expired token | Sanctum `expires_at` check | **401** Unauthenticated |
| Revoked refresh | `isRevoked()` check | **ALL tokens revoked** (reuse attack detection) |
| Device fingerprint forge | `hash_equals(fingerprint)` | **PASS** if headers match exactly |
| Country change | `getCountryFromIp()` | **401** if different country (admins only) |
| Rapid refresh (>5/min) | `isRefreshRateLimited()` | **429** + all tokens revoked |
| Session > 30 days | `hasExceededSessionLifetime()` | **401** forced re-login |

#### Defense Gap
- **Device fingerprint is spoofable:** Only uses `User-Agent + Accept-Language + Accept-Encoding + Platform`. An attacker who captures these headers (e.g., via XSS) can forge the fingerprint.
- **Country check uses free GeoIP:** `ip-api.com` may be inaccurate or rate-limited.

#### Log Generated?
**YES** - Token reuse attacks logged as CRITICAL to immutable security log.

#### Alert Triggered?
**YES** - SecurityAlert CRITICAL created, notification dispatched.

---

### ATTACK 9: IDOR (Insecure Direct Object Reference)

**Objective:** Access or modify attendance records belonging to other users within the same school.

#### Attack Vector
```
1. Teacher A tries to view/modify Teacher B's class attendance
2. Student tries to modify own attendance status
3. Parent tries to access non-child student data
4. Parameter tampering on student_id, schedule_id, attendance_id
```

#### Exploit Attempt
```bash
# Teacher A accesses Teacher B's schedule
curl -X GET https://api.target.com/api/v1/attendance/class/999 \
  -H "Authorization: Bearer $TEACHER_A_TOKEN"

# Student tries to mark self as present
curl -X PUT https://api.target.com/api/v1/attendance/12345 \
  -H "Authorization: Bearer $STUDENT_TOKEN" \
  -d '{"status": "present"}'

# Parent accesses non-child student
curl -X GET https://api.target.com/api/v1/student/888/attendance \
  -H "Authorization: Bearer $PARENT_TOKEN"
```

#### Expected System Behavior
| Attack | Defense | Result |
|--------|---------|--------|
| Teacher→wrong schedule | `isTeacherOfSchedule()` | **403** Unauthorized |
| Student→own status | `AttendancePolicy::update()` role check | **403** Students cannot update |
| Parent→wrong child | `isParentOfStudent()` + school check | **403** Not parent of student |
| ID enumeration | `SchoolScope` filters invisibly | **404** Record not found |

#### Defense Layers (per operation)
```
READ:   SchoolScope (auto) → AttendancePolicy::view() → ownership check
CREATE: BelongsToSchool (auto school_id) → Gate::authorize() → validateSchoolOwnership()
UPDATE: SchoolScope (auto) → AttendancePolicy::update() → isTeacherOfSchedule()
DELETE: SchoolScope (auto) → AttendancePolicy::delete() → admin-only check
```

#### Log Generated?
**YES** - `logUnauthorizedAccess()` in AttendancePolicy.

#### Alert Triggered?
**YES** - Repeated IDOR attempts trigger SecurityAlert.

---

### ATTACK 10: Queue Tampering

**Objective:** Manipulate queued jobs to execute with wrong tenant context or malicious payload.

#### Attack Vector
```
1. Inject malicious job payload into queue (if queue backend exposed)
2. Exploit deserialization vulnerability in job class
3. Craft job that executes with global scope (no tenant filter)
4. Exploit TenantAwareJob missing school validation
```

#### Exploit Attempt
```bash
# If using database queue driver (accessible via SQL injection):
INSERT INTO jobs (queue, payload, attempts) VALUES (
  'default',
  '{"displayName":"App\\Jobs\\UpdateDailyAttendanceSummary",
    "job":"Illuminate\\Queue\\CallQueuedHandler@call",
    "data":{"command":"O:...serialized_malicious_payload..."}}',
  0
);

# If Redis queue exposed:
redis-cli LPUSH queues:default '{"job":"malicious_class","data":{}}'
```

#### Expected System Behavior
| Attack | Defense | Result |
|--------|---------|--------|
| Direct queue injection | Queue encryption (if enabled) | **Depends** on config |
| Wrong school_id | `TenantAwareJob` validates school exists | **InvalidArgumentException** |
| Cross-tenant in job | `ensureTenantContext()` check | **RuntimeException** |
| SchoolScope bypass | Console context disables scope | **RISK**: Job queries unscoped |
| Deserialization attack | Laravel's `SerializesModels` | **Mitigated** by framework |

#### Defense Gap
- `SchoolScope` is **disabled in console context** (queues run as console)
- `TenantAwareJob` requires developers to use `getSchoolId()` in queries
- Jobs not extending `TenantAwareJob` have NO tenant protection

#### Log Generated?
**YES** - Queue failures logged to security channel via `AppServiceProvider::boot()`.

#### Alert Triggered?
**PARTIAL** - Job failures logged but no specific "tenant violation" alert from queue.

---

## 4. Concurrency Stress Tests

### Test A: 5000 Parallel QR Scans

```yaml
# k6-5000-scans.js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    spike: {
      executor: 'shared-iterations',
      vus: 500,
      iterations: 5000,
      maxDuration: '30s',
    },
  },
};

export default function () {
  const res = http.post(
    'https://api.target.com/api/v1/attendance/scan',
    JSON.stringify({
      qr_token: QR_TOKEN,
      lat: -7.123,
      lng: 112.456,
    }),
    {
      headers: {
        'Authorization': `Bearer ${TOKEN}`,
        'Content-Type': 'application/json',
        'X-Idempotency-Key': crypto.randomUUID(),
        'X-Device-ID': `device-${__VU}-${__ITER}`,
      },
    }
  );

  check(res, {
    'status is 200 or 429 or 400': (r) => [200, 429, 400, 409].includes(r.status),
    'no 500 errors': (r) => r.status !== 500,
  });
}
```

#### Expected Results
| Metric | Expected Value | Pass Criteria |
|--------|---------------|---------------|
| Total requests | 5000 | All processed |
| HTTP 200 (success) | 1 | Exactly 1 attendance created |
| HTTP 429 (rate limited) | ~4980 | Majority blocked by rate limiter |
| HTTP 400 (replay/duplicate) | ~9 | Remaining blocked by nonce/idempotency |
| HTTP 409 (idempotent duplicate) | ~10 | Blocked by idempotency key |
| HTTP 500 (server error) | 0 | **MUST BE ZERO** |
| Database records created | 1 | **MUST BE EXACTLY 1** |
| Redis lock timeouts | <50 | Acceptable under load |
| p95 response time | <2000ms | Under 2 seconds |
| p99 response time | <5000ms | Under 5 seconds |

---

### Test B: 200 Concurrent QR Generate

```yaml
# k6-200-generate.js
export const options = {
  scenarios: {
    generate_spike: {
      executor: 'shared-iterations',
      vus: 50,
      iterations: 200,
      maxDuration: '10s',
    },
  },
};

export default function () {
  const res = http.post(
    'https://api.target.com/api/v1/teacher/qr/generate',
    JSON.stringify({ schedule_id: SCHEDULE_ID }),
    {
      headers: {
        'Authorization': `Bearer ${TEACHER_TOKEN}`,
        'Content-Type': 'application/json',
      },
    }
  );

  check(res, {
    'status is 200 or 429': (r) => [200, 429].includes(r.status),
    'has qr_token': (r) => r.status === 429 || r.json('data.qr_token') !== undefined,
  });
}
```

#### Expected Results
| Metric | Expected | Pass Criteria |
|--------|----------|---------------|
| HTTP 200 | ~20 (rate limited to 20/min) | Within rate limit |
| HTTP 429 | ~180 | Blocked by rate limiter |
| All generated QRs unique | YES | Each has unique nonce |
| No duplicate nonces | YES | Nonces are Str::random(16) |

---

### Test C: 100 Duplicate Webhooks

```bash
#!/bin/bash
# test-100-duplicate-webhooks.sh

ORDER_ID="ORDER-$(date +%s)"
SIGNATURE=$(compute_midtrans_signature $ORDER_ID "200" "150000.00" $SERVER_KEY)

for i in $(seq 1 100); do
  curl -s -X POST https://api.target.com/api/v1/webhooks/midtrans \
    -H "Content-Type: application/json" \
    -d '{
      "order_id": "'$ORDER_ID'",
      "status_code": "200",
      "gross_amount": "150000.00",
      "signature_key": "'$SIGNATURE'",
      "transaction_status": "settlement",
      "payment_type": "bank_transfer"
    }' &
done
wait
```

#### Expected Results
| Metric | Expected | Pass Criteria |
|--------|----------|---------------|
| HTTP 200 (first processed) | 1 | Exactly 1 |
| HTTP 200 (idempotent return) | 99 | Return cached result |
| Subscription activated | 1 time | No double activation |
| `processed_webhooks` rows | 1 | Single record |
| Redis lock acquired | 1 | Only one wins lock |
| Redis lock waited | ~99 | Others block/retry |

---

## 5. Defense Validation Table

| # | Attack | Defense Mechanism | Implementation File | Expected Response | Log? | Alert? | Data Safe? | Status |
|---|--------|-------------------|---------------------|-------------------|------|--------|------------|--------|
| 1 | QR Replay | Nonce cache + HMAC + expiry | `QrService.php:92-120` | 400 | YES | YES | YES | STRONG |
| 2 | QR Sharing | 10s expiry + nonce one-time | `QrService.php:104` | 400 | PARTIAL | NO | YES | MODERATE |
| 3 | Race Condition | Redis lock + DB unique + idempotency | `AttendanceLockService.php:167` | 429/400 | YES | YES | YES | STRONG |
| 4 | Cross-Tenant | SchoolScope + BelongsToSchool + Policy | `SchoolScope.php:14` | 404/403 | YES | YES | YES | STRONG |
| 5 | Subscription Bypass | Middleware + cache + DB check | `CheckActiveSubscription.php` | 402 | YES | PARTIAL | YES | MODERATE |
| 6 | Redis Poisoning | Circuit breaker + file state | `SafeRedisService.php` | Degraded | YES | YES | AT RISK | WEAK |
| 7 | Brute Force | Rate limit + lockout + timing pad | `AuthController.php:48-111` | 429/422 | YES | YES | YES | STRONG |
| 8 | Token Manipulation | Refresh rotation + device bind + 30d session | `TokenHardeningService.php:152-195` | 401 | YES | YES | YES | STRONG |
| 9 | IDOR | Policy + SchoolScope + ownership | `AttendancePolicy.php` | 403/404 | YES | YES | YES | STRONG |
| 10 | Queue Tampering | TenantAwareJob + school validation | `TenantAwareJob.php` | Exception | YES | PARTIAL | MODERATE | MODERATE |

### Defense Rating Summary

| Rating | Count | Attacks |
|--------|-------|---------|
| STRONG | 6 | #1 QR Replay, #3 Race Condition, #4 Cross-Tenant, #7 Brute Force, #8 Token, #9 IDOR |
| MODERATE | 3 | #2 QR Sharing, #5 Subscription, #10 Queue |
| WEAK | 1 | #6 Redis Poisoning |

---

## 6. Post-Attack Report Template

### For Each Attack Scenario:

```
============================================================
POST-ATTACK REPORT: [Attack Name]
============================================================

Date:           [YYYY-MM-DD HH:mm]
Tester:         [Name]
Environment:    [staging/production-mirror]

1. SYSTEM COMPROMISED?
   [ ] YES - Describe breach
   [x] NO  - Defense held

2. ALERT TRIGGERED?
   [ ] SecurityAlert created in DB
   [ ] Log entry in security channel
   [ ] Telegram/Slack notification sent
   [ ] Email notification sent
   [ ] No alert generated (GAP)

3. AUTO-RECOVERY TRIGGERED?
   [ ] Circuit breaker opened/closed
   [ ] Rate limiter activated
   [ ] Token revocation completed
   [ ] Account lockout activated
   [ ] No auto-recovery (GAP)

4. AUDIT LOG COMPLETE?
   [ ] Request logged with full context
   [ ] User ID recorded
   [ ] IP address recorded
   [ ] Device info recorded
   [ ] Timestamp accurate
   [ ] Correlation ID present (X-Request-ID)
   [ ] Immutable log entry created

5. DATA INTEGRITY CHECK
   [ ] No unauthorized records created
   [ ] No unauthorized records modified
   [ ] No unauthorized records deleted
   [ ] Unique constraints held
   [ ] Foreign key integrity maintained

6. PERFORMANCE IMPACT
   - p50 response time: ___ms
   - p95 response time: ___ms
   - p99 response time: ___ms
   - Error rate: ___%
   - CPU peak: ___%
   - Memory peak: ___MB

7. FINDINGS
   - Severity: [CRITICAL/HIGH/MEDIUM/LOW/INFO]
   - Description: ___
   - Evidence: ___
   - Recommendation: ___

============================================================
```

---

## 7. Incident Response Scripts

### Script 1: Emergency Token Revocation

```bash
#!/bin/bash
# emergency-revoke-tokens.sh
# Usage: ./emergency-revoke-tokens.sh <user_id|school_id|all>

set -euo pipefail

TARGET=$1
ARTISAN="php artisan"

case $TARGET in
  all)
    echo "[CRITICAL] Revoking ALL tokens system-wide..."
    $ARTISAN tinker --execute="
      \App\Models\RefreshToken::query()->update([
        'revoked_at' => now(),
        'revoked_reason' => 'emergency_revocation'
      ]);
      \Laravel\Sanctum\PersonalAccessToken::query()->delete();
      echo 'All tokens revoked.';
    "
    ;;
  school:*)
    SCHOOL_ID=${TARGET#school:}
    echo "[HIGH] Revoking all tokens for school $SCHOOL_ID..."
    $ARTISAN tinker --execute="
      \$userIds = \App\Models\User::where('school_id', $SCHOOL_ID)->pluck('id');
      \App\Models\RefreshToken::whereIn('user_id', \$userIds)->update([
        'revoked_at' => now(),
        'revoked_reason' => 'emergency_school_revocation'
      ]);
      \Laravel\Sanctum\PersonalAccessToken::whereIn('tokenable_id', \$userIds)->delete();
      echo 'School tokens revoked. Users: ' . \$userIds->count();
    "
    ;;
  *)
    USER_ID=$TARGET
    echo "[MEDIUM] Revoking all tokens for user $USER_ID..."
    $ARTISAN tinker --execute="
      \$user = \App\Models\User::findOrFail($USER_ID);
      \App\Models\RefreshToken::revokeAllForUser(\$user->id, 'emergency_revocation');
      \$user->tokens()->delete();
      echo 'User tokens revoked: ' . \$user->email;
    "
    ;;
esac

echo "[INFO] Token revocation complete at $(date -u +%Y-%m-%dT%H:%M:%SZ)"
```

### Script 2: Redis Lock Emergency Cleanup

```bash
#!/bin/bash
# emergency-redis-cleanup.sh
# Clears stale locks, rate limits, and forces circuit breaker reset

set -euo pipefail

REDIS_CLI="redis-cli"

echo "[1/4] Clearing stale attendance locks..."
$REDIS_CLI KEYS "attendance_lock:*" | xargs -r $REDIS_CLI DEL
echo "    Done."

echo "[2/4] Resetting rate limiters..."
$REDIS_CLI KEYS "attendance_rate:*" | xargs -r $REDIS_CLI DEL
$REDIS_CLI KEYS "token:refresh:rate:*" | xargs -r $REDIS_CLI DEL
echo "    Done."

echo "[3/4] Resetting circuit breaker state..."
# Circuit breaker uses file cache, not Redis
rm -f storage/framework/cache/data/circuit_breaker* 2>/dev/null || true
echo "    Done."

echo "[4/4] Clearing subscription cache..."
$REDIS_CLI KEYS "school_sub_*" | xargs -r $REDIS_CLI DEL
echo "    Done."

echo "[INFO] Redis cleanup complete at $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "[WARNING] QR nonces were NOT cleared (intentional - prevents replay)"
```

### Script 3: Tenant Isolation Audit

```bash
#!/bin/bash
# audit-tenant-isolation.sh
# Checks for cross-tenant data leaks in database

set -euo pipefail

PSQL="psql -h localhost -U postgres -d absensi_qr"

echo "=== TENANT ISOLATION AUDIT ==="
echo ""

echo "[1] Checking attendances with mismatched school_id..."
$PSQL -c "
  SELECT a.id, a.school_id as attendance_school,
         u.school_id as student_school, u.id as student_id
  FROM attendances a
  JOIN users u ON a.student_id = u.id
  WHERE a.school_id != u.school_id
  AND a.deleted_at IS NULL
  LIMIT 20;
"

echo "[2] Checking schedules with mismatched school_id..."
$PSQL -c "
  SELECT s.id, s.school_id as schedule_school,
         u.school_id as teacher_school, u.id as teacher_id
  FROM schedules s
  JOIN users u ON s.teacher_id = u.id
  WHERE s.school_id != u.school_id
  AND s.deleted_at IS NULL
  LIMIT 20;
"

echo "[3] Checking users without school_id (non-super_admin)..."
$PSQL -c "
  SELECT id, username, role_type, school_id
  FROM users
  WHERE school_id IS NULL
  AND role_type != 'super_admin'
  AND deleted_at IS NULL;
"

echo "[4] Checking for orphaned records..."
$PSQL -c "
  SELECT 'attendances' as table_name, COUNT(*) as orphaned
  FROM attendances a
  WHERE NOT EXISTS (SELECT 1 FROM schools s WHERE s.id = a.school_id)
  UNION ALL
  SELECT 'schedules', COUNT(*)
  FROM schedules sc
  WHERE NOT EXISTS (SELECT 1 FROM schools s WHERE s.id = sc.school_id);
"

echo ""
echo "=== AUDIT COMPLETE ==="
```

### Script 4: Security Event Investigation

```bash
#!/bin/bash
# investigate-security-event.sh <request_id>
# Traces a security event across all log channels

REQUEST_ID=$1
LOG_DIR="storage/logs"

echo "=== INVESTIGATING REQUEST: $REQUEST_ID ==="
echo ""

echo "[1] Application logs..."
grep -r "$REQUEST_ID" $LOG_DIR/laravel*.log 2>/dev/null | tail -20

echo ""
echo "[2] Security logs..."
grep -r "$REQUEST_ID" $LOG_DIR/security*.log 2>/dev/null | tail -20

echo ""
echo "[3] Attendance logs..."
grep -r "$REQUEST_ID" $LOG_DIR/attendance*.log 2>/dev/null | tail -20

echo ""
echo "[4] Database: SecurityAlert records..."
php artisan tinker --execute="
  \$alerts = \App\Models\SecurityAlert::where('metadata->request_id', '$REQUEST_ID')
    ->orWhere('description', 'like', '%$REQUEST_ID%')
    ->get(['id', 'type', 'severity', 'description', 'created_at']);
  echo \$alerts->toJson(JSON_PRETTY_PRINT);
"

echo ""
echo "[5] Database: ImmutableSecurityLog records..."
php artisan tinker --execute="
  \$logs = \App\Models\ImmutableSecurityLog::where('metadata->request_id', '$REQUEST_ID')
    ->get(['id', 'event_type', 'description', 'user_id', 'created_at']);
  echo \$logs->toJson(JSON_PRETTY_PRINT);
"
```

---

## 8. Remediation Checklist

### Priority 1: CRITICAL (Fix before production)

| # | Finding | Remediation | File | Effort |
|---|---------|-------------|------|--------|
| R1 | Redis failure removes nonce protection | Add database-backed nonce fallback when Redis unavailable | `QrService.php` | Medium |
| R2 | SafeRedisService lock() returns null | Add null check and fail-closed behavior (reject request if lock unavailable) | `SafeRedisService.php` | Low |
| R3 | SchoolScope disabled in console | Add opt-in scope activation for queue workers | `SchoolScope.php` | Medium |
| R4 | ProcessedWebhook missing static methods | Implement `isAlreadyProcessed()` and `markAsProcessed()` | `ProcessedWebhook.php` | Low |

### Priority 2: HIGH (Fix within sprint)

| # | Finding | Remediation | File | Effort |
|---|---------|-------------|------|--------|
| R5 | No geo-fence for QR scans | Add school coordinates and max radius check | `AttendanceCheckInService.php` | Medium |
| R6 | 3-day grace period enables free access | Reduce to 0 days or implement read-only mode during grace | `EnhancedCheckActiveSubscription.php` | Low |
| R7 | Device fingerprint is easily spoofable | Add client-generated device ID binding (X-Device-Id) as primary factor | `TokenHardeningService.php` | Medium |
| R8 | No "QR sharing" detection pattern | Add alert when same nonce scanned from different device/IP | `QrService.php` | Medium |

### Priority 3: MEDIUM (Plan for next cycle)

| # | Finding | Remediation | File | Effort |
|---|---------|-------------|------|--------|
| R9 | Queue jobs can bypass tenant scope | Create `TenantScopeMiddleware` for queue worker process | `SchoolScope.php` | High |
| R10 | GeoIP uses free unreliable service | Migrate to MaxMind GeoLite2 local database | `TokenHardeningService.php` | Medium |
| R11 | No subscription webhook cache invalidation | Add Subscription model observer to clear cache on update | `Subscription.php` | Low |
| R12 | Rate limiting per IP only on some endpoints | Add per-user rate limiting to all authenticated endpoints | `AppServiceProvider.php` | Medium |

### Priority 4: LOW (Hardening)

| # | Finding | Remediation | File | Effort |
|---|---------|-------------|------|--------|
| R13 | Super admin bypass not comprehensively audited | Add immutable audit log for all super admin scope bypasses | `SchoolScope.php` | Low |
| R14 | No honeypot endpoints | Add decoy admin endpoints that trigger immediate alerts | `routes/api.php` | Low |
| R15 | No canary tokens in data | Plant detectable fake records that trigger alert on access | Custom service | Medium |

---

## Appendix A: Test Environment Requirements

```
- Isolated staging environment (NOT production)
- Database snapshot before testing
- Redis snapshot before testing
- Monitoring dashboards active during tests
- Security team on standby
- Rollback procedure documented and tested
- Load testing tools: k6, Artillery
- Network tools: curl, mitmproxy (for SSL testing)
- Log aggregation: tail -f on all channels
```

## Appendix B: Rules of Engagement

```
1. All tests conducted on staging/mirror environment ONLY
2. No destructive actions on production data
3. No denial-of-service against production infrastructure
4. All findings reported within 24 hours
5. Critical findings reported immediately via secure channel
6. Test credentials rotated after engagement
7. All test artifacts cleaned up post-engagement
8. Final report delivered within 72 hours
```

---

**Document End**
**Classification:** CONFIDENTIAL
**Distribution:** Security Team, CTO, Development Lead
