# QR Replay Prevention System

This document describes the QR code replay prevention mechanisms implemented to prevent QR token sharing and duplicate attendance attempts.

## Overview

The QR replay prevention system protects against:
1. **QR Token Sharing**: Students sharing their QR code with classmates
2. **Duplicate Scanning**: Students attempting to scan multiple times
3. **Replay Attacks**: Attackers capturing and replaying QR tokens

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      QR Scan Request                            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  1. NONCE REPLAY CHECK (Cache)                                  │
│     Key: qr_nonce:school_{id}:{nonce}                          │
│     TTL: 5 minutes                                              │
│     Purpose: Prevent same QR from being scanned twice           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  2. STUDENT+SCHEDULE DUPLICATE CHECK (Cache)                    │
│     Key: attendance_scan:school_{id}:student_{id}:schedule_{id}│
│     TTL: Until end of day                                       │
│     Purpose: Prevent multiple scans per schedule                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  3. DATABASE VERIFICATION (Authoritative)                       │
│     Query: Attendance::where(...)->exists()                     │
│     Purpose: Final source of truth                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  4. RECORD ATTENDANCE + UPDATE CACHE                            │
│     - Create Attendance record                                  │
│     - Mark nonce as used                                        │
│     - Mark student+schedule as scanned                          │
└─────────────────────────────────────────────────────────────────┘
```

## Cache Keys

All cache keys include `school_id` for multi-tenant isolation.

### Nonce Tracking
```
qr_nonce:school_{school_id}:{nonce}
```
- **TTL**: 5 minutes (longer than QR validity to catch delayed replays)
- **Value**: `{student_id, schedule_id, used_at, ip}`

### Attendance Tracking
```
attendance_scan:school_{school_id}:student_{student_id}:schedule_{schedule_id}:{date}
```
- **TTL**: Until end of day
- **Value**: `{attendance_id, scanned_at, ip}`

### Attempt Counter
```
qr_attempts:school_{school_id}:student_{student_id}:schedule_{schedule_id}:{date}
```
- **TTL**: 1 hour
- **Value**: Integer count of attempts

## Security Logging

All replay attempts are logged to the `security` channel:

```php
Log::channel('security')->warning('QR Replay Attempt Detected', [
    'type' => 'qr_replay_attempt',
    'reason' => 'nonce_replay_detected',
    'student_id' => 123,
    'schedule_id' => 456,
    'school_id' => 1,
    'nonce' => 'abc1...',
    'attempt_count' => 3,
    'ip_address' => '192.168.1.100',
    'user_agent' => 'Mozilla/5.0...',
    'timestamp' => '2026-01-29T09:30:00+08:00',
]);
```

### Alert on Excessive Attempts

When a student makes 5+ replay attempts, an alert is generated:

```php
Log::channel('security')->alert('Excessive QR Replay Attempts', [
    'type' => 'excessive_qr_attempts',
    'student_id' => 123,
    'total_attempts' => 5,
    ...
]);
```

## Log File Location

Security logs are stored at:
```
storage/logs/security-YYYY-MM-DD.log
```

Logs are kept for 30 days.

## Error Messages

| Scenario | Indonesian Message |
|----------|-------------------|
| Nonce already used | "QR Code sudah digunakan. Setiap QR hanya dapat digunakan satu kali." |
| Already scanned today | "Absen sudah tercatat. Anda telah melakukan absensi untuk jadwal ini." |
| DB duplicate found | "Anda sudah melakukan absensi untuk jadwal ini." |

## Service Class

The replay prevention logic is centralized in:

```
app/Core/Services/Attendance/QrReplayPreventionService.php
```

### Key Methods

| Method | Purpose |
|--------|---------|
| `isNonceUsed(nonce, schoolId)` | Check if QR nonce was already scanned |
| `markNonceUsed(...)` | Mark nonce as used after successful scan |
| `hasStudentScanned(studentId, scheduleId, schoolId)` | Check if student already scanned for schedule |
| `markStudentScanned(...)` | Mark student as scanned after success |
| `logRepeatedAttempt(...)` | Log anomaly for security monitoring |
| `getAttemptCount(...)` | Get number of attempts for monitoring |

## Integration Points

### Core AttendanceService (Student Scan)
```php
// app/Core/Services/Attendance/AttendanceService.php
public function processScan(User $student, array $data)
{
    // 1. Check nonce replay
    if ($this->replayPreventionService->checkNonceReplay($nonce, $schoolId)) {
        throw new Exception('QR Code sudah digunakan...');
    }
    
    // 2. Check student+schedule duplicate
    if ($this->replayPreventionService->hasStudentScanned(...)) {
        throw new Exception('Absen sudah tercatat...');
    }
    
    // ... record attendance ...
    
    // 3. Mark as used
    $this->replayPreventionService->markNonceUsed(...);
    $this->replayPreventionService->markStudentScanned(...);
}
```

### App AttendanceService (Teacher Scan)
```php
// app/Services/AttendanceService.php
public function recordByTeacherScan(...)
{
    // Same pattern as above
}
```

## Testing

### Manual Testing

1. **Test Nonce Replay**:
   - Capture a valid QR token
   - Submit it once (should succeed)
   - Submit it again (should fail with "QR Code sudah digunakan")

2. **Test Duplicate Scan**:
   - Scan with a fresh QR (should succeed)
   - Scan with another fresh QR for same schedule (should fail with "Absen sudah tercatat")

3. **Check Logs**:
   ```bash
   tail -f storage/logs/security-*.log
   ```

### Unit Testing

```php
public function test_nonce_replay_is_rejected()
{
    $service = app(QrReplayPreventionService::class);
    
    // Mark nonce as used
    $service->markNonceUsed('test-nonce', 1, 100, 200);
    
    // Check should return true
    $this->assertTrue($service->isNonceUsed('test-nonce', 1));
}

public function test_student_scan_duplicate_is_rejected()
{
    $service = app(QrReplayPreventionService::class);
    
    // Mark as scanned
    $service->markStudentScanned(100, 200, 1, 999);
    
    // Check should return true
    $this->assertTrue($service->hasStudentScanned(100, 200, 1));
}
```

## Cache Driver Considerations

### Redis (Recommended)
- Best performance for high-traffic schools
- Atomic operations
- Supports TTL natively

### File Cache
- Works for development/small deployments
- May have race conditions under high load

### Database Cache
- Not recommended for this use case
- Adds latency to every scan

## Monitoring Dashboard

Consider creating an admin dashboard that shows:
- Replay attempts per school
- Students with excessive attempts
- Geographic patterns (IP analysis)
- Time-based patterns

## Future Enhancements

1. **Rate Limiting**: Implement per-student rate limiting
2. **Device Fingerprinting**: Track device characteristics
3. **Machine Learning**: Detect anomalous scanning patterns
4. **Push Notifications**: Alert admins on suspicious activity
5. **Automatic Flagging**: Auto-flag students with repeated violations

---

*Last updated: 2026-01-29*
