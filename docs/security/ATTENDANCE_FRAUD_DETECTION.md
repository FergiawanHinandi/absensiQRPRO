# Attendance Fraud Detection

## Overview

The `AttendanceFraudService` implements rule-based detection to flag suspicious attendance activities such as "Jokit" (proxy attendance), location spoofing, and automated bots.

---

## Detection Rules

### 1. Device Sharing (Anti-Joki)

**Rule:** A single device is used by >3 different students in a 24-hour period.
**Identifier:** Uses `DeviceFingerprint` (preferred) or `device_id`.
**Severity:** `HIGH`
**Prevention:**
- Flags "Joki" attempts where one student brings multiple friends' phones or logs in for them.

### 2. Impossible Travel (Teleportation)

**Rule:** A student checks in from two locations with a distance > 1000m within 10 minutes.
**Severity:** `MEDIUM`
**Prevention:**
- Detects location spoofing or account sharing across distances.

### 3. Bot Timing Pattern

**Rule:** A student checks in at the **exact same second** (e.g., `07:00:01`) for >= 3 previous records.
**Severity:** `LOW`
**Prevention:**
- Detects automated scripts/bots used to auto-check-in.

### 4. Brute Force History

**Rule:** A high number (>5) of recent failed QR token validation attempts.
**Severity:** `MEDIUM`
**Prevention:**
- Detects attempts to guess valid QR tokens.

---

## Implementation

### Service Integration

Hook this service into the `AttendanceCheckInService` **AFTER** a successful check-in. This ensures we don't block legitimate users instantly but flag them for review (Zero False Positive Impact).

```php
// In AttendanceCheckInService
public function checkIn(...)
{
    // ... successful checkin ...
    $attendance = Attendance::create([...]);
    
    // Async/Post-process Fraud Check
    dispatch(function () use ($attendance, $request) {
        app(AttendanceFraudService::class)->analyze(
            $attendance,
            $request->attributes->get('device_fingerprint')?->fingerprint
        );
    })->afterResponse();
    
    return $result;
}
```

---

## Logging Example

### Suspected Joki Event

```json
{
  "level": "CRITICAL",
  "message": "Attendance Fraud Detected",
  "context": {
    "event": "fraud.attendance_flagged",
    "attendance_id": 10542,
    "student_id": 442,
    "risk_level": "HIGH",
    "anomalies": [
      {
        "type": "joki_device_sharing",
        "severity": "HIGH",
        "details": "Device used by 5 distinct students today.",
        "user_count": 5,
        "identifier": "a1b2c3d4..."
      }
    ],
    "timestamp": "2026-01-31T12:45:00+07:00"
  }
}
```

### Impossible Travel Event

```json
{
  "level": "WARNING",
  "message": "Attendance Fraud Detected",
  "context": {
    "anomalies": [
      {
        "type": "impossible_travel",
        "severity": "MEDIUM",
        "details": "Moved 5000.00 meters in 60 seconds (Speed: 83.33 m/s).",
        "distance_meters": 5000
      }
    ]
  }
}
```

---

## Database Impact

Flags are stored in `attendance_logs` table (metadata column) to avoid cluttering the main `attendances` table schema.

```sql
SELECT * FROM attendance_logs 
WHERE action_type = 'fraud_flag'
ORDER BY created_at DESC;
```
