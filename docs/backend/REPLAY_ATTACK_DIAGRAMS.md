# Diagram: Replay Attack Prevention Flow

## 1. Request Flow dengan Idempotency

```
┌─────────────┐
│   Client    │
│  (Mobile)   │
└──────┬──────┘
       │
       │ 1. Generate UUID
       │    idempotency_key = uuid()
       │
       │ 2. POST /attendance/scan
       │    Headers:
       │    - Authorization: Bearer {token}
       │    - X-Idempotency-Key: {uuid}
       │    - X-Device-ID: {device_id}
       ▼
┌──────────────────────────────────────┐
│   IdempotencyMiddleware              │
├──────────────────────────────────────┤
│                                      │
│  ┌─────────────────────────────┐    │
│  │ Validate UUID Format        │    │
│  │ (Must be UUID v4)           │    │
│  └────────┬────────────────────┘    │
│           │                          │
│           ▼                          │
│  ┌─────────────────────────────┐    │
│  │ Check Database              │    │
│  │ SELECT * FROM               │    │
│  │ idempotency_keys            │    │
│  │ WHERE key = ?               │    │
│  │   AND user_id = ?           │    │
│  │   AND endpoint = ?          │    │
│  │   AND expires_at > NOW()    │    │
│  └────────┬────────────────────┘    │
│           │                          │
│      ┌────┴────┐                     │
│      │ Found?  │                     │
│      └────┬────┘                     │
│           │                          │
│    ┌──────┴──────┐                  │
│    │             │                  │
│   YES           NO                  │
│    │             │                  │
│    ▼             ▼                  │
│ ┌─────┐    ┌──────────┐            │
│ │Cache│    │ Process  │            │
│ │Resp │    │ Request  │            │
│ └──┬──┘    └────┬─────┘            │
│    │            │                   │
└────┼────────────┼───────────────────┘
     │            │
     │            ▼
     │    ┌──────────────────────────┐
     │    │ AttendanceRateLimitMw    │
     │    ├──────────────────────────┤
     │    │ Check User Rate Limit    │
     │    │ Check Device Rate Limit  │
     │    │ Check IP Rate Limit      │
     │    └────┬─────────────────────┘
     │         │
     │         ▼
     │    ┌──────────────────────────┐
     │    │ AttendanceSecurityMw     │
     │    ├──────────────────────────┤
     │    │ Verify QR Signature      │
     │    │ Check Nonce (Replay)     │
     │    │ Validate Timestamp       │
     │    └────┬─────────────────────┘
     │         │
     │         ▼
     │    ┌──────────────────────────┐
     │    │ Controller               │
     │    ├──────────────────────────┤
     │    │ Process Attendance       │
     │    │ Use SERVER timestamp     │
     │    │ Store in DB              │
     │    └────┬─────────────────────┘
     │         │
     │         ▼
     │    ┌──────────────────────────┐
     │    │ Store Idempotency Key    │
     │    ├──────────────────────────┤
     │    │ INSERT INTO              │
     │    │ idempotency_keys         │
     │    │ (key, user_id,           │
     │    │  response_payload, ...)  │
     │    └────┬─────────────────────┘
     │         │
     ▼         ▼
┌────────────────────────────────────┐
│   Response                         │
├────────────────────────────────────┤
│ {                                  │
│   "success": true,                 │
│   "data": {...},                   │
│   "_idempotent_replay": false,     │
│   "status": 201                    │
│ }                                  │
│                                    │
│ Headers:                           │
│ - X-RateLimit-Limit: 10           │
│ - X-RateLimit-Remaining: 9        │
│ - X-RateLimit-Reset: 1234567890   │
└────────────────────────────────────┘
```

## 2. Retry Flow (Network Error)

```
┌─────────────┐
│   Client    │
└──────┬──────┘
       │
       │ 1. First Request
       │    key = "abc-123"
       ▼
   [Network Error]
       │
       │ 2. Retry with SAME key
       │    key = "abc-123"
       ▼
┌──────────────────────────┐
│ IdempotencyMiddleware    │
├──────────────────────────┤
│ Check Database           │
│ Key "abc-123" found!     │
│                          │
│ Return Cached Response:  │
│ {                        │
│   "success": true,       │
│   "data": {...},         │
│   "_idempotent_replay":  │
│      true,               │
│   "_original_timestamp": │
│      "2026-02-07..."     │
│ }                        │
└──────────────────────────┘
       │
       ▼
   [Client receives
    same response as
    original request]
```

## 3. Rate Limiting Flow

```
┌─────────────┐
│   Client    │
└──────┬──────┘
       │
       │ Requests 1-10: ✓ OK
       │
       │ Request 11:
       ▼
┌──────────────────────────────────┐
│ AttendanceRateLimitMiddleware    │
├──────────────────────────────────┤
│                                  │
│  Layer 1: User Rate Limit        │
│  ┌────────────────────────────┐  │
│  │ Key: attendance_rate:      │  │
│  │      qr-scan:user:123      │  │
│  │ Limit: 10 requests/min     │  │
│  │ Current: 11                │  │
│  │ Status: EXCEEDED ❌        │  │
│  └────────────────────────────┘  │
│                                  │
│  Layer 2: Device Rate Limit      │
│  ┌────────────────────────────┐  │
│  │ Key: attendance_rate:      │  │
│  │      qr-scan:device:xyz    │  │
│  │ Limit: 10 requests/min     │  │
│  │ Current: 11                │  │
│  │ Status: EXCEEDED ❌        │  │
│  └────────────────────────────┘  │
│                                  │
│  Layer 3: IP Rate Limit          │
│  ┌────────────────────────────┐  │
│  │ Key: attendance_rate:      │  │
│  │      qr-scan:ip:1.2.3.4    │  │
│  │ Limit: 10 requests/min     │  │
│  │ Current: 11                │  │
│  │ Status: EXCEEDED ❌        │  │
│  └────────────────────────────┘  │
│                                  │
│  ❌ ANY layer exceeded           │
│  → Return 429 Response           │
│                                  │
└──────────────────────────────────┘
       │
       ▼
┌──────────────────────────┐
│ Response 429             │
├──────────────────────────┤
│ {                        │
│   "success": false,      │
│   "message": "Terlalu    │
│     banyak permintaan",  │
│   "code":                │
│     "RATE_LIMIT_EXCEEDED"│
│   "data": {              │
│     "max_attempts": 10,  │
│     "retry_after": 60    │
│   }                      │
│ }                        │
│                          │
│ Headers:                 │
│ - Retry-After: 60        │
└──────────────────────────┘
```

## 4. Server Timestamp Authority

```
┌─────────────┐
│   Client    │
│ (Manipulated│
│   Clock)    │
└──────┬──────┘
       │
       │ QR Generated at:
       │ Client: 2026-02-08 (FUTURE!)
       │ Server: 2026-02-07 (CORRECT)
       ▼
┌────────────────────────────────────┐
│ AttendanceCheckInService           │
├────────────────────────────────────┤
│                                    │
│ validateQrTimestamp()              │
│ ┌────────────────────────────────┐ │
│ │ $serverNow = now()->timestamp  │ │
│ │ $qrTimestamp = payload['iat']  │ │
│ │                                │ │
│ │ if ($qrTimestamp >             │ │
│ │     $serverNow + 5) {          │ │
│ │   // Future timestamp!         │ │
│ │   logClockManipulation()       │ │
│ │   throw Exception()            │ │
│ │ }                              │ │
│ └────────────────────────────────┘ │
│                                    │
│ ✓ Validation Passed                │
│                                    │
│ atomicCheckIn()                    │
│ ┌────────────────────────────────┐ │
│ │ $serverNow = now()             │ │
│ │ $serverDate = now()->toDate()  │ │
│ │                                │ │
│ │ Attendance::create([           │ │
│ │   'attendance_date' =>         │ │
│ │      $serverDate, // SERVER!   │ │
│ │   'check_in_time' =>           │ │
│ │      $serverNow,  // SERVER!   │ │
│ │   ...                          │ │
│ │ ])                             │ │
│ └────────────────────────────────┘ │
│                                    │
└────────────────────────────────────┘
       │
       ▼
   ✓ Attendance recorded
     with SERVER timestamp
     (Client timestamp ignored)
```

## 5. Database Schema

```
┌─────────────────────────────────────────────────────┐
│ idempotency_keys                                    │
├─────────────────────────────────────────────────────┤
│ id                  BIGINT PK AUTO_INCREMENT        │
│ key                 VARCHAR(64) INDEX               │
│ user_id             BIGINT FK → users.id            │
│ endpoint            VARCHAR(255)                    │
│ http_method         VARCHAR(10)                     │
│ ip_address          VARCHAR(45)                     │
│ user_agent          VARCHAR(255)                    │
│ device_id           VARCHAR(255)                    │
│ response_payload    TEXT                            │
│ response_status     SMALLINT                        │
│ expires_at          TIMESTAMP INDEX                 │
│ created_at          TIMESTAMP                       │
│                                                     │
│ UNIQUE (key, user_id, endpoint)                     │
│ INDEX (expires_at, created_at) -- for cleanup       │
└─────────────────────────────────────────────────────┘
```

## 6. Cleanup Process

```
┌──────────────────────┐
│ Scheduled Job        │
│ (Every Hour)         │
└──────┬───────────────┘
       │
       │ php artisan idempotency:cleanup --force
       ▼
┌────────────────────────────────────────┐
│ CleanupExpiredIdempotencyKeys          │
├────────────────────────────────────────┤
│                                        │
│ 1. Count Expired Keys                  │
│    SELECT COUNT(*)                     │
│    FROM idempotency_keys               │
│    WHERE expires_at <= NOW()           │
│                                        │
│    Result: 5,432 keys                  │
│                                        │
│ 2. Batch Delete (1000 at a time)       │
│    ┌────────────────────────────────┐  │
│    │ Batch 1: DELETE 1000 keys      │  │
│    │ Batch 2: DELETE 1000 keys      │  │
│    │ Batch 3: DELETE 1000 keys      │  │
│    │ Batch 4: DELETE 1000 keys      │  │
│    │ Batch 5: DELETE 1000 keys      │  │
│    │ Batch 6: DELETE 432 keys       │  │
│    └────────────────────────────────┘  │
│                                        │
│ 3. Log Results                         │
│    "Deleted 5,432 expired keys"        │
│                                        │
└────────────────────────────────────────┘
       │
       ▼
   ✓ Database cleaned
     Table size optimized
```

## 7. Multi-Layer Security

```
┌─────────────────────────────────────────────────────┐
│                   Request                           │
└──────────────────┬──────────────────────────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌───────────────┐    ┌───────────────┐
│ Idempotency   │    │ Rate Limiting │
│ Protection    │    │ Protection    │
├───────────────┤    ├───────────────┤
│ ✓ UUID Valid  │    │ ✓ User Limit  │
│ ✓ Not Replay  │    │ ✓ Device Limit│
│ ✓ TTL Check   │    │ ✓ IP Limit    │
└───────┬───────┘    └───────┬───────┘
        │                     │
        └──────────┬──────────┘
                   │
                   ▼
        ┌──────────────────────┐
        │ Attendance Security  │
        ├──────────────────────┤
        │ ✓ QR Signature       │
        │ ✓ Nonce Check        │
        │ ✓ Timestamp Valid    │
        │ ✓ Geofence           │
        │ ✓ Time Window        │
        └──────────┬───────────┘
                   │
                   ▼
        ┌──────────────────────┐
        │ Server Authority     │
        ├──────────────────────┤
        │ ✓ Server Timestamp   │
        │ ✓ Server Date        │
        │ ✓ Clock Tamper Check │
        └──────────┬───────────┘
                   │
                   ▼
        ┌──────────────────────┐
        │ Database Transaction │
        ├──────────────────────┤
        │ ✓ Row Lock           │
        │ ✓ Gap Lock           │
        │ ✓ Atomic Insert      │
        └──────────┬───────────┘
                   │
                   ▼
            ✓ Attendance Recorded
              (100% Secure)
```

---

**Legend:**
- `┌─┐` = Component/Process
- `│ │` = Vertical flow
- `─ ─` = Horizontal connection
- `▼`   = Flow direction
- `✓`   = Success/Valid
- `❌`  = Failed/Invalid
- `FK`  = Foreign Key
- `PK`  = Primary Key
