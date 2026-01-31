# Structured Attendance Logging

## Overview

The attendance system uses structured JSON logging for comprehensive observability and audit trails.

---

## Log Channels

### Configuration (`config/logging.php`)

```php
'channels' => [
    // Human-readable daily logs
    'attendance' => [
        'driver' => 'daily',
        'path' => storage_path('logs/attendance.log'),
        'level' => 'debug',
        'days' => 30,
    ],

    // JSON-formatted logs for ELK/Datadog/CloudWatch
    'attendance_json' => [
        'driver' => 'monolog',
        'handler' => StreamHandler::class,
        'handler_with' => [
            'stream' => storage_path('logs/attendance-json.log'),
        ],
        'formatter' => Monolog\Formatter\JsonFormatter::class,
        'level' => 'debug',
    ],

    // Combined (file + JSON)
    'attendance_combined' => [
        'driver' => 'stack',
        'channels' => ['attendance', 'attendance_json'],
    ],
],
```

---

## Log Events

| Event | Level | Description |
|-------|-------|-------------|
| `check_in.success` | INFO | Successful attendance check-in |
| `check_in.failed` | WARNING | Failed validation during check-in |
| `check_in.duplicate` | WARNING | Duplicate attendance attempt blocked |
| `check_in.expired_qr` | WARNING | Expired QR code presented |
| `check_in.invalid_qr` | ERROR | Invalid/tampered QR code |
| `check_in.late` | INFO | Late check-in recorded |
| `check_out.success` | INFO | Successful check-out |
| `manual.created` | INFO | Manual attendance entry created |
| `manual.updated` | INFO | Attendance record updated |
| `security.anomaly` | ERROR | Security anomaly detected |
| `security.rate_limit` | WARNING | Rate limit exceeded |

---

## JSON Log Format

### Successful Check-In

```json
{
  "message": "Attendance check-in successful",
  "context": {
    "event": "check_in.success",
    "service": "attendance",
    "environment": "production",
    "attendance_id": 12345,
    "student_id": 100,
    "student_name": "Budi Santoso",
    "schedule_id": 50,
    "school_id": 1,
    "status": "present",
    "check_in_time": "08:05:23",
    "attendance_date": "2026-01-31",
    "is_late": false,
    "scan_method": "qr_scan",
    "location": {
      "latitude": -6.2088,
      "longitude": 106.8456,
      "accuracy": 10.5
    },
    "device": {
      "device_id": "abc-device-123",
      "platform": "android",
      "app_version": "2.1.0",
      "user_agent": "AbsensiQR/2.1.0 (Android 14)"
    },
    "request": {
      "ip": "192.168.1.100",
      "request_id": "550e8400-e29b-41d4-a716-446655440000",
      "endpoint": "api/v1/attendance/scan",
      "method": "POST",
      "timestamp": "2026-01-31T08:05:23+07:00",
      "timestamp_unix": 1706666723
    }
  },
  "datetime": "2026-01-31T08:05:23+07:00",
  "level_name": "INFO"
}
```

### Failed Validation

```json
{
  "message": "Attendance check-in failed",
  "context": {
    "event": "check_in.failed",
    "service": "attendance",
    "reason": "schedule_not_found",
    "student_id": 100,
    "student_name": "Budi Santoso",
    "school_id": 1,
    "schedule_id": 999,
    "day_of_week": "friday",
    "device": { ... },
    "request": { ... }
  }
}
```

### Duplicate Attempt

```json
{
  "message": "Duplicate attendance attempt blocked",
  "context": {
    "event": "check_in.duplicate",
    "student_id": 100,
    "existing_attendance_id": 12340,
    "schedule_id": 50,
    "existing_check_in_time": "08:00:15",
    "device": { ... },
    "request": { ... }
  }
}
```

### Expired QR Code

```json
{
  "message": "Expired QR code presented",
  "context": {
    "event": "check_in.expired_qr",
    "student_id": 100,
    "schedule_id": 50,
    "qr_expired_at": 1706600000,
    "seconds_expired": 3600,
    "device": { ... },
    "request": { ... }
  }
}
```

### Security Anomaly

```json
{
  "message": "Security anomaly detected",
  "context": {
    "event": "security.anomaly",
    "anomaly_type": "device_mismatch",
    "description": "Student attempt with different device (potential joki)",
    "student_id": 100,
    "is_security_event": true,
    "severity": "high",
    "registered_device": "abc12345...",
    "incoming_device": "xyz98765...",
    "device": { ... },
    "request": { ... }
  }
}
```

---

## Usage in Code

### Using AttendanceLogger Service

```php
use App\Services\Logging\AttendanceLogger;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceLogger $logger
    ) {}

    public function scan(Request $request)
    {
        try {
            $result = $this->attendanceService->checkIn(
                $request->user(),
                $request->validated(),
                $request // Pass request for logging context
            );
            
            // Logger is called internally in service
            
        } catch (AttendanceException $e) {
            // Already logged in service
            return $this->errorResponse($e);
        }
    }
}
```

### Direct Usage

```php
use App\Services\Logging\AttendanceLogger;

$logger = app(AttendanceLogger::class);

// Log successful check-in
$logger->checkInSuccess($attendance, $request, [
    'scan_method' => 'qr_scan',
]);

// Log failed validation
$logger->checkInFailed($request, 'invalid_schedule', [
    'schedule_id' => 999,
]);

// Log duplicate attempt
$logger->checkInDuplicate($request, $existingId, [
    'schedule_id' => 50,
]);

// Log expired QR
$logger->expiredQr($request, [
    'schedule_id' => 50,
    'expires_at' => 1706600000,
    'seconds_expired' => 3600,
]);

// Log invalid QR (security event)
$logger->invalidQr($request, 'signature_mismatch');

// Log late check-in
$logger->lateCheckIn($attendance, $request, 15, [
    'scheduled_start' => '08:00:00',
]);

// Log security anomaly
$logger->securityAnomaly($request, 'device_mismatch', 'Potential joki detected', [
    'severity' => 'high',
]);

// Log manual attendance
$logger->manualCreated($attendance, $recorder, $request, 'Siswa sakit');

// Log update
$logger->updated($attendance, $updater, ['status' => 'present'], $request);
```

---

## Log Storage

### File Locations

| Log | Path | Retention |
|-----|------|-----------|
| Human-readable | `storage/logs/attendance.log` | 30 days |
| JSON format | `storage/logs/attendance-json.log` | 30 days |
| Security events | `storage/logs/security-json.log` | 30 days |

### Logrotate Configuration

```
/path/to/app/storage/logs/attendance*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

---

## ELK Stack Integration

### Filebeat Configuration

```yaml
filebeat.inputs:
  - type: log
    enabled: true
    paths:
      - /var/www/app/storage/logs/attendance-json.log
    json.keys_under_root: true
    json.add_error_key: true
    fields:
      app: absensi-qr
      type: attendance
    fields_under_root: true

output.elasticsearch:
  hosts: ["elasticsearch:9200"]
  index: "attendance-logs-%{+yyyy.MM.dd}"
```

### Kibana Dashboard

Create visualizations for:
- Check-ins per hour/day
- Late arrivals trend
- Failed attempts by reason
- Security anomalies by type
- Top students with duplicates
- Device platform distribution

---

## Querying Logs

### Command Line

```bash
# View all check-in successes today
cat storage/logs/attendance-json.log | jq 'select(.context.event == "check_in.success")'

# Count failures by reason
cat storage/logs/attendance-json.log | \
  jq -r '.context | select(.event == "check_in.failed") | .reason' | \
  sort | uniq -c | sort -rn

# Find security anomalies
cat storage/logs/attendance-json.log | \
  jq 'select(.context.is_security_event == true)'

# Find specific student's activity
cat storage/logs/attendance-json.log | \
  jq 'select(.context.student_id == 100)'
```

### Database (if using Log-to-DB driver)

```sql
SELECT 
    DATE(created_at) as date,
    JSON_EXTRACT(context, '$.event') as event,
    COUNT(*) as count
FROM activity_logs
WHERE channel = 'attendance_json'
GROUP BY date, event
ORDER BY date DESC, count DESC;
```

---

## Testing

### Unit Test Example

```php
public function test_successful_checkin_is_logged()
{
    // Arrange
    Log::shouldReceive('channel')
        ->with('attendance_json')
        ->once()
        ->andReturnSelf();
    
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return $context['event'] === 'check_in.success'
                && isset($context['attendance_id'])
                && isset($context['student_id']);
        });
    
    // Act
    $logger = new AttendanceLogger();
    $logger->checkInSuccess($attendance, $request);
    
    // Assert - Log::shouldReceive handles verification
}
```

---

## Alerting

### Example: Slack Alert on High Severity Anomaly

```php
// In AttendanceLogger::securityAnomaly()
if ($context['severity'] === 'high') {
    Log::channel('slack')->error('Security Anomaly', $context);
}
```

### Example: Daily Digest

```php
// In scheduled command
$failures = AttendanceLog::where('event', 'check_in.failed')
    ->whereDate('created_at', today())
    ->count();

if ($failures > 100) {
    Notification::route('slack', config('logging.slack.url'))
        ->notify(new HighFailureRateNotification($failures));
}
```
