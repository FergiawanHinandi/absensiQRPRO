# Attendance Scan Rate Limiting

## Overview

The attendance scan endpoint is protected by a specialized rate limiter that:
- Limits to **5 requests per 10 seconds** per device/user
- **Blocks IP temporarily** after 3 violations
- Tracks both user and device for comprehensive protection

---

## Rate Limit Rules

| Parameter | Value | Description |
|-----------|-------|-------------|
| Max Requests | 5 | Maximum scan requests allowed |
| Time Window | 10 seconds | Rolling window for rate limit |
| Violations Before Block | 3 | Number of rate limit hits before IP block |
| Block Duration | 15 minutes | How long IP stays blocked |
| Violation Reset | 30 minutes | When violation counter resets |

---

## How It Works

```
┌─────────────────────────────────────────────────────────────────────┐
│                     REQUEST FLOW                                     │
└─────────────────────────────────────────────────────────────────────┘

  Request → Is IP Blocked? ─── Yes ──→ 429 + "IP Blocked" Response
              │
              No
              ▼
        Build Rate Key: user:{id}:device:{device_id}:ip:{ip}
              │
              ▼
        Check: Attempts > 5 in last 10s?
              │
        ┌─────┴─────┐
       Yes          No
        │            │
        ▼            ▼
  Record Violation   Hit Counter
        │            │
        ▼            ▼
  Violations >= 3?   Process Request
        │            │
   ┌────┴────┐       │
  Yes        No      │
   │          │      │
   ▼          ▼      │
 Block IP   Return   │
 for 15min  429      │
                     ▼
              Add Rate Headers
              Return Response
```

---

## Rate Limit Key Structure

```
attn_scan:{user_id}:{device_id}:{ip}
```

**Example:**
```
attn_scan:12345:abc-device-123:192.168.1.100
```

This ensures:
- Same user can't scan from multiple devices rapidly
- Same device can't be used by multiple users rapidly
- IP spoofing is caught by including IP in key

---

## Response Examples

### Normal Response (Rate Limit OK)

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 4
X-RateLimit-Reset: 1706666410
X-RateLimit-Window: 10

{
  "success": true,
  "message": "Absensi berhasil dicatat.",
  "data": { ... }
}
```

### Rate Limit Exceeded (429)

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 7
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1706666417

{
  "success": false,
  "message": "Terlalu banyak permintaan scan. Tunggu beberapa detik.",
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "type": "attendance_scan",
    "retry_after": 7,
    "limit": 5,
    "window_seconds": 10
  }
}
```

### IP Blocked (429)

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 892
X-Block-Reason: rate_limit_violations

{
  "success": false,
  "message": "IP Anda diblokir sementara karena aktivitas mencurigakan.",
  "error": {
    "code": "IP_BLOCKED",
    "type": "temporary_block",
    "retry_after": 892,
    "blocked_until": "2026-01-31T12:30:00+08:00"
  }
}
```

---

## Middleware Usage

### Registration (bootstrap/app.php)

```php
$middleware->alias([
    'attendance.throttle' => \App\Http\Middleware\AttendanceScanThrottle::class,
]);
```

### Route Application

```php
// Student scan
Route::post('/scan', [AttendanceController::class, 'scan'])
    ->middleware(['attendance.throttle']);

// Teacher scan
Route::post('/scan', [TeacherScanController::class, 'scan'])
    ->middleware(['teacher.device', 'attendance.throttle']);

// Secure scan
Route::post('/secure/scan', [SecureAttendanceScanController::class, 'scan'])
    ->middleware(['teacher.device', 'attendance.throttle']);
```

---

## Admin Management

### Check IP Status

```php
use App\Http\Middleware\AttendanceScanThrottle;

$status = AttendanceScanThrottle::getIpStatus('192.168.1.100');
// Returns:
// [
//   'ip' => '192.168.1.100',
//   'is_blocked' => false,
//   'block_data' => null,
//   'violation_count' => 1,
//   'remaining_before_block' => 2,
// ]
```

### Manually Unblock IP

```php
AttendanceScanThrottle::unblockIp('192.168.1.100');
// Returns: true
```

### Artisan Command (Optional)

```bash
# View blocked IPs
php artisan attendance:blocked-ips

# Unblock specific IP
php artisan attendance:unblock 192.168.1.100
```

---

## Logging

All rate limit violations are logged to `storage/logs/laravel.log`:

### Rate Limit Exceeded Log

```json
{
  "message": "Attendance Scan Rate Limit Exceeded",
  "context": {
    "key": "attn_scan:12345:abc-device:192.168.1.100",
    "ip": "192.168.1.100",
    "user_id": 12345,
    "school_id": 1,
    "device_id": "abc-device",
    "user_agent": "AbsensiQR/1.0 (Android 14)",
    "violation_count": 2,
    "remaining_before_block": 1,
    "endpoint": "api/v1/attendance/scan",
    "timestamp": "2026-01-31T12:00:00+08:00"
  }
}
```

### IP Blocked Log

```json
{
  "message": "IP Blocked for Attendance Scan Abuse",
  "context": {
    "ip": "192.168.1.100",
    "user_id": 12345,
    "school_id": 1,
    "device_id": "abc-device",
    "user_agent": "AbsensiQR/1.0 (Android 14)",
    "duration_minutes": 15,
    "timestamp": "2026-01-31T12:00:00+08:00"
  }
}
```

---

## Security Considerations

### Why 5 requests per 10 seconds?
- Normal scan pattern: 1 scan per class session (1-2 per hour)
- Legitimate retry scenario: Device may retry 2-3 times on timeout
- Buffer for network issues: Extra 1-2 attempts

### Why IP blocking?
- Prevents scripted attacks that can cycle user/device IDs
- Adds additional penalty for persistent abuse
- 15-minute block is short enough for legitimate users

### Device ID Fallback
If `X-Device-ID` header is missing, a fingerprint is generated from:
- User-Agent
- Accept-Language
- Accept-Encoding

---

## Testing

### Unit Test Example

```php
public function test_rate_limit_blocks_after_five_requests()
{
    $user = User::factory()->create();
    
    // Make 5 successful requests
    for ($i = 0; $i < 5; $i++) {
        $response = $this->actingAs($user)
            ->postJson('/api/v1/attendance/scan', [...]);
        $response->assertStatus(200);
    }
    
    // 6th request should be blocked
    $response = $this->actingAs($user)
        ->postJson('/api/v1/attendance/scan', [...]);
    
    $response->assertStatus(429)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
            ]
        ]);
}

public function test_ip_blocked_after_three_violations()
{
    $user = User::factory()->create();
    
    // Trigger 3 rate limit violations
    for ($v = 0; $v < 3; $v++) {
        // Exhaust rate limit
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->postJson('/api/v1/attendance/scan', [...]);
        }
        sleep(11); // Wait for window reset
    }
    
    // Next request should show IP blocked
    $response = $this->actingAs($user)
        ->postJson('/api/v1/attendance/scan', [...]);
    
    $response->assertStatus(429)
        ->assertJson([
            'error' => [
                'code' => 'IP_BLOCKED',
            ]
        ]);
}
```
