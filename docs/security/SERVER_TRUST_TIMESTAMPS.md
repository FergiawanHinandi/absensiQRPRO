# Server-Trust Timestamp Validation

## Principle

**Never trust client-provided timestamps for business logic.**

All attendance records use server time exclusively. Client timestamps are only used for validation purposes (to check if a QR has expired) and optionally stored for audit/debugging.

---

## Implementation

### What Gets Stored

| Field | Source | Description |
|-------|--------|-------------|
| `attendance_date` | **SERVER** (`now()->toDateString()`) | Official date of attendance |
| `check_in_time` | **SERVER** (`now()`) | Official check-in timestamp |
| `client_scanned_at` | CLIENT (optional) | For debugging only, never used in logic |

### Validation Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    QR TIMESTAMP VALIDATION                              │
└─────────────────────────────────────────────────────────────────────────┘

  QR Payload received
        │
        ▼
  ┌─────────────────────┐
  │ Extract timestamps  │
  │ • generated_at      │
  │ • exp               │
  └──────────┬──────────┘
             │
             ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ RULE 1: Future Timestamp Detection (Clock Tampering)       │
  │                                                             │
  │ if (generated_at > server_time + 5 seconds)                │
  │   → REJECT + Log "future_timestamp" (SECURITY EVENT)       │
  │   → "Client clock is ahead of server"                      │
  └──────────┬──────────────────────────────────────────────────┘
             │
             ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ RULE 2: Expiration Check (Based on Server Time)            │
  │                                                             │
  │ if (server_time > exp)                                     │
  │   → REJECT + Log "expired_qr"                              │
  │   → "QR sudah kadaluarsa"                                  │
  └──────────┬──────────────────────────────────────────────────┘
             │
             ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ RULE 3: Stale QR Detection (Replay from Different Day)     │
  │                                                             │
  │ if (server_time - generated_at > 3600 seconds)             │
  │   → REJECT + Log "stale_qr" (SECURITY EVENT)               │
  │   → "QR unusually old"                                     │
  └──────────┬──────────────────────────────────────────────────┘
             │
             ▼
         PROCEED
```

---

## Code Examples

### Validation Logic

```php
private function validateQrTimestamp(array $payload, ?Request $request = null): void
{
    $serverNow = now()->timestamp;
    $qrTimestamp = $payload['generated_at'] ?? null;
    $expTimestamp = $payload['exp'] ?? null;
    
    // RULE 1: Detect future timestamps (clock tampering)
    $clockSkewTolerance = 5; // 5 seconds allowed for network delay
    
    if ($qrTimestamp && $qrTimestamp > ($serverNow + $clockSkewTolerance)) {
        $this->logClockManipulation($request, 'future_timestamp', [
            'qr_generated_at' => $qrTimestamp,
            'server_time' => $serverNow,
            'difference_seconds' => $qrTimestamp - $serverNow,
        ]);
        
        throw AttendanceException::custom(
            'Waktu QR tidak valid. Pastikan waktu perangkat Anda sudah benar.'
        );
    }
    
    // RULE 2: Check expiration based on SERVER time
    if ($expTimestamp && $serverNow > $expTimestamp) {
        throw AttendanceException::custom('Kode QR sudah kadaluarsa.');
    }
    
    // RULE 3: Detect stale QR (max 1 hour old)
    if ($qrTimestamp && ($serverNow - $qrTimestamp) > 3600) {
        $this->logClockManipulation($request, 'stale_qr', [...]);
        throw AttendanceException::custom('Kode QR terlalu lama.');
    }
}
```

### Attendance Record Creation

```php
// SERVER TIME ONLY - Client timestamps are never used
$serverNow = now();
$serverDate = $serverNow->toDateString();

$attendance = Attendance::create([
    // SERVER DATE - Never trust client
    'attendance_date' => $serverDate,
    
    // SERVER TIME - Never trust client
    'check_in_time' => $serverNow,
    
    // Client timestamp stored for audit only (NEVER used in logic)
    'client_scanned_at' => $data['scanned_at'] ?? null,
    
    // Other fields...
]);
```

---

## Security Log Examples

### Future Timestamp (Clock Tampering)

```json
{
  "message": "Clock Manipulation Detected",
  "context": {
    "event": "security.clock_manipulation",
    "manipulation_type": "future_timestamp",
    "severity": "HIGH",
    "is_security_event": true,
    "qr_generated_at": 1706698800,
    "server_time": 1706695200,
    "difference_seconds": 3600,
    "student_id": 12345,
    "ip_address": "192.168.1.100",
    "device_id": "abc-device-123",
    "recommendation": "Client clock is ahead of server - possible tampering",
    "timestamp": "2026-01-31T12:00:00+07:00"
  }
}
```

### Stale QR (Possible Cross-Day Replay)

```json
{
  "message": "Clock Manipulation Detected",
  "context": {
    "event": "security.clock_manipulation",
    "manipulation_type": "stale_qr",
    "severity": "HIGH",
    "is_security_event": true,
    "qr_generated_at": 1706608800,
    "server_time": 1706695200,
    "age_seconds": 86400,
    "max_age_seconds": 3600,
    "recommendation": "QR is unusually old - possible replay from different session",
    "timestamp": "2026-01-31T12:00:00+07:00"
  }
}
```

---

## Validation Rules Summary

| Rule | Threshold | Action | Severity |
|------|-----------|--------|----------|
| Future Timestamp | > server_time + 5s | Reject + Log | HIGH |
| Expired QR | server_time > exp | Reject + Log | LOW |
| Stale QR | age > 3600s (1 hour) | Reject + Log | HIGH |

---

## Why Server-Trust Matters

### Attack Vectors Prevented

1. **Clock Manipulation**
   - Student sets device clock to future to scan QR early
   - Student sets device clock to past to avoid "late" status
   
2. **Timestamp Injection**
   - Attacker modifies `scanned_at` field to claim earlier check-in
   - Attacker forges `generated_at` to extend QR validity

3. **Cross-Day Replay**
   - QR captured one day, replayed the next morning
   - Expired QR with manipulated client clock

### Why 5-Second Tolerance?

Network delays and processing time can cause slight discrepancies:

```
Timeline:
  T+0:    QR generated on teacher's device
  T+0.5:  QR displayed
  T+2:    Student scans (network latency)
  T+3:    Request reaches server
```

5 seconds covers realistic network/processing delays without allowing manipulation.

---

## Configuration

```php
// config/qr.php
return [
    // QR expiration in seconds
    'signature_expiration_seconds' => 10,
    
    // Maximum age for QR (prevents cross-day replay)
    'max_qr_age_seconds' => 3600,
    
    // Clock skew tolerance (for network delay)
    'clock_skew_tolerance_seconds' => 5,
];
```

---

## Testing

### Unit Test: Future Timestamp Rejection

```php
public function test_rejects_future_timestamp()
{
    $payload = [
        'generated_at' => now()->addMinutes(10)->timestamp,
        'student_id' => 1,
    ];
    
    $this->expectException(AttendanceException::class);
    $this->expectExceptionMessage('Waktu QR tidak valid');
    
    $service = app(AttendanceCheckInService::class);
    $service->checkIn($student, ['qr_token' => $this->encodePayload($payload)]);
}

public function test_logs_clock_manipulation()
{
    Log::shouldReceive('channel')
        ->with('security_json')
        ->once()
        ->andReturnSelf();
    
    Log::shouldReceive('warning')
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Clock Manipulation')
                && $context['manipulation_type'] === 'future_timestamp';
        });
    
    // Trigger future timestamp scenario...
}
```

### Integration Test: Server Time Used

```php
public function test_attendance_uses_server_time()
{
    // Freeze time
    Carbon::setTestNow('2026-01-31 08:30:00');
    
    // Client claims 08:00 (30 mins earlier)
    $data = [
        'qr_token' => $this->validQrToken,
        'scanned_at' => '2026-01-31 08:00:00',
    ];
    
    $result = $service->checkIn($student, $data, $request);
    
    // Verify SERVER time is used, not client
    $this->assertEquals('08:30:00', $result->attendance->check_in_time->format('H:i:s'));
    $this->assertEquals('2026-01-31', $result->attendance->attendance_date);
}
```
