# QR Anti-Replay Protection

## Overview

The QR signature verification system now includes **anti-replay protection** that ensures each QR code can only be used **ONCE**, even within its 10-second validity window.

---

## Problem Statement

Previously, a valid signed QR code could be scanned multiple times within its 10-second expiration window. This allowed:

1. **QR Sharing** - Student could take a screenshot and share with absent friend
2. **Multiple Scans** - Same QR submitted from multiple devices simultaneously
3. **Proxy Attendance** - One student scans for multiple people

---

## Solution: One-Time Use Enforcement

### How It Works

```
┌─────────────────────────────────────────────────────────────────────┐
│                     QR VERIFICATION FLOW                            │
└─────────────────────────────────────────────────────────────────────┘

  QR Scan Request
        │
        ▼
  ┌─────────────────┐
  │ Validate Fields │
  └────────┬────────┘
           │
           ▼
  ┌─────────────────┐
  │ Check Expiration│──── Expired? ──→ REJECT: QR Expired
  └────────┬────────┘
           │
           ▼
  ┌─────────────────┐
  │ Verify HMAC Sig │──── Invalid? ──→ REJECT: Tampered QR
  └────────┬────────┘
           │
           ▼
  ┌─────────────────────────────────────────────────────┐
  │ ANTI-REPLAY CHECK (NEW)                             │
  │                                                     │
  │  1. Hash the signature: SHA256(sig)                 │
  │  2. Try Cache::add() - ATOMIC operation             │
  │     - Returns TRUE if first use → proceed           │
  │     - Returns FALSE if already used → REJECT        │
  │  3. Store with TTL = QR_expiry + 5 seconds          │
  └────────┬────────────────────────────────────────────┘
           │
     First use?
     ┌────┴────┐
    YES        NO
     │          │
     ▼          ▼
  ACCEPT    REJECT
            + Log CRITICAL
            "REPLAY ATTACK"
```

---

## Technical Implementation

### Cache Key Format

```
qr_used:{sha256_hash_of_signature}
```

**Example:**
```
qr_used:a1b2c3d4e5f6...
```

### Cache Value

```json
{
  "first_used_at": "2026-01-31T12:00:00+07:00",
  "ip_address": "192.168.1.100",
  "user_agent": "AbsensiQR/2.1.0 (Android 14)"
}
```

### Cache TTL Calculation

```php
TTL = (QR_expiry_timestamp - now) + BUFFER_SECONDS
    = remaining_validity + 5 seconds
```

This ensures the cache entry exists until after the QR would naturally expire.

---

## Atomic Operation

The key to preventing race conditions is using `Cache::add()`:

```php
// Cache::add() is ATOMIC - uses Redis SETNX under the hood
$success = Cache::add($cacheKey, $usageData, $ttl);

if ($success) {
    // First use - proceed with attendance
} else {
    // Already exists - REPLAY ATTACK!
    throw new Exception('QR Code ini sudah digunakan.');
}
```

### Why Atomic?

Without atomic operations, this race condition could occur:

```
Time    Request A                Request B
────    ─────────                ─────────
0ms     Check cache (empty)      Check cache (empty)
1ms     Both see "not used"      Both see "not used"
2ms     Set cache                Set cache
3ms     BOTH SUCCEED ❌          BOTH SUCCEED ❌
```

With `Cache::add()`:

```
Time    Request A                Request B
────    ─────────                ─────────
0ms     add() returns TRUE       add() returns FALSE
1ms     PROCEED ✅               REJECT ✅
```

---

## Security Log Examples

### Successful First Scan

```json
{
  "message": "QR Verification Success",
  "context": {
    "student_id": 12345,
    "nisn": "1234****",
    "ip_address": "192.168.1.100",
    "timestamp": "2026-01-31T08:00:00+07:00"
  }
}
```

### Replay Attack Detected (CRITICAL)

```json
{
  "message": "QR REPLAY ATTACK DETECTED",
  "context": {
    "event": "security.replay_attack",
    "severity": "CRITICAL",
    "is_security_event": true,
    "student_id": 12345,
    "nisn": "1234****",
    "qr_generated_at": "2026-01-31T08:00:00+07:00",
    "signature_hash": "a1b2c3d4e5f6...",
    "first_usage": {
      "first_used_at": "2026-01-31T08:00:01+07:00",
      "ip_address": "192.168.1.100",
      "user_agent": "AbsensiQR/2.1.0 (Android 14)"
    },
    "replay_attempt": {
      "ip_address": "192.168.1.200",
      "user_agent": "AbsensiQR/2.1.0 (iPhone 15)",
      "device_id": "xyz-device-456",
      "timestamp": "2026-01-31T08:00:05+07:00"
    },
    "recommendation": "Investigate if same QR was shared or captured"
  }
}
```

---

## Usage

### Basic Verification (with anti-replay)

```php
$qrService = app(QRSignatureService::class);

try {
    $result = $qrService->verifyPayload($payload);
    // Success - first use
    return response()->json([
        'success' => true,
        'student_id' => $result['student_id'],
    ]);
} catch (\Exception $e) {
    // Could be: expired, invalid signature, or replay
    return response()->json([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
```

### Verification Without Marking (for preview/debug)

```php
// Don't mark as used - useful for debugging
$result = $qrService->verifyPayload($payload, markAsUsed: false);
```

### Check If Signature Already Used

```php
$isUsed = $qrService->isSignatureUsed($signature);
if ($isUsed) {
    $usageInfo = $qrService->getSignatureUsageInfo($signature);
    // Returns: ['first_used_at' => '...', 'ip_address' => '...']
}
```

---

## Configuration

| Setting | Value | Description |
|---------|-------|-------------|
| QR Expiration | 10 seconds | Time window for valid QR |
| Replay Buffer | 5 seconds | Extra time to keep used signature in cache |
| Cache Driver | Redis (recommended) | Use Redis for production |

### Recommended Redis Configuration

```env
CACHE_DRIVER=redis
REDIS_CLIENT=phpredis
```

### Fallback for Development

```env
CACHE_DRIVER=file
```

⚠️ **Warning**: File cache driver may have slightly weaker atomicity guarantees. Use Redis in production for guaranteed atomic operations.

---

## Testing

### Unit Test: Anti-Replay

```php
public function test_qr_can_only_be_used_once()
{
    $qrService = app(QRSignatureService::class);
    
    // Generate QR
    $qrData = $qrService->generateSignedPayload(1, 'NISN123');
    
    // First use - should succeed
    $result1 = $qrService->verifyPayload($qrData['payload']);
    $this->assertTrue($result1['valid']);
    
    // Second use - should fail
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('sudah digunakan');
    
    $qrService->verifyPayload($qrData['payload']);
}

public function test_replay_attack_is_logged_as_critical()
{
    Log::shouldReceive('channel')
        ->with('security_json')
        ->once()
        ->andReturnSelf();
    
    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            return $message === 'QR REPLAY ATTACK DETECTED'
                && $context['severity'] === 'CRITICAL';
        });
    
    // Setup and trigger replay...
}
```

### Integration Test: Concurrent Requests

```php
public function test_concurrent_scans_only_one_succeeds()
{
    $qrData = $this->generateQr();
    
    // Simulate 5 concurrent requests
    $results = Http::pool(fn (Pool $pool) => [
        $pool->post('/api/v1/attendance/scan', $qrData),
        $pool->post('/api/v1/attendance/scan', $qrData),
        $pool->post('/api/v1/attendance/scan', $qrData),
        $pool->post('/api/v1/attendance/scan', $qrData),
        $pool->post('/api/v1/attendance/scan', $qrData),
    ]);
    
    $successes = collect($results)->filter->successful()->count();
    $this->assertEquals(1, $successes, 'Only one request should succeed');
}
```

---

## Alerting

### Slack Alert for Replay Attacks

```php
// In logging configuration or event listener
if ($event === 'security.replay_attack') {
    Notification::route('slack', config('services.slack.security_webhook'))
        ->notify(new ReplayAttackNotification($context));
}
```

### Real-time Monitoring

```sql
-- Count replay attacks by hour
SELECT 
    DATE_TRUNC('hour', created_at) as hour,
    COUNT(*) as attempts
FROM security_logs
WHERE event = 'security.replay_attack'
AND created_at > NOW() - INTERVAL '24 hours'
GROUP BY 1
ORDER BY 1;
```

---

## Security Recommendations

1. **Use Redis** - Ensures true atomic operations
2. **Monitor CRITICAL logs** - Set up alerts for replay attacks
3. **Investigate patterns** - Multiple replays from same student may indicate QR sharing
4. **Consider device binding** - Combine with device ID check for stronger protection
5. **Short QR validity** - 10 seconds is good; don't increase unnecessarily
