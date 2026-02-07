# Centralized Logging Configuration

## Overview

AbsensiQRPro implements structured JSON logging with centralized log aggregation support for:
- **ELK Stack** (Elasticsearch + Logstash + Kibana)
- **Grafana Loki** (with Promtail)
- **Graylog**
- **Fluentd**

All logs include consistent context:
- `request_id` - Unique identifier for request tracing
- `user_id` - Authenticated user ID
- `school_id` - Multi-tenant school identifier
- `endpoint` - Normalized API endpoint
- `response_time` - Request duration in milliseconds

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Laravel App    │────▶│   Log Shipper   │────▶│   Log Storage   │
│                 │     │                 │     │                 │
│  JsonFormatter  │     │  - Promtail     │     │  - Loki         │
│  LogContext     │     │  - Logstash     │     │  - Elasticsearch│
│  EventLogger    │     │  - Filebeat     │     │  - Graylog      │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                                                        │
                                                        ▼
                                               ┌─────────────────┐
                                               │   Visualization │
                                               │                 │
                                               │  - Grafana      │
                                               │  - Kibana       │
                                               └─────────────────┘
```

## Configuration

### Environment Variables

```env
# Logging Channel (use 'centralized' for production)
LOG_CHANNEL=centralized
LOG_LEVEL=info

# ELK Stack
LOGSTASH_HOST=logstash.example.com
LOGSTASH_PORT=5044
LOGSTASH_PROTOCOL=tcp  # tcp or udp

# Grafana Loki
LOKI_HOST=loki.example.com
LOKI_PORT=3100

# Graylog
GRAYLOG_HOST=graylog.example.com
GRAYLOG_PORT=12201

# Fluentd
FLUENTD_HOST=fluentd.example.com
FLUENTD_PORT=24224
```

### Log Channels

| Channel | Purpose | Retention |
|---------|---------|-----------|
| `centralized` | Main channel for production (ships to ELK/Loki) | Per ILM |
| `elk` | Direct Logstash TCP/UDP output | Per ILM |
| `loki` | Stdout for Promtail collection | Per Loki config |
| `request` | HTTP request logs with timing | 7 days |
| `auth` | Authentication events | 30 days |
| `security` | Security events (QR invalid, unauthorized) | 90 days |
| `attendance` | Attendance scan events | 14 days |
| `system` | System errors, job failures | 14 days |

### Switching Log Channels

```bash
# Development (local file)
LOG_CHANNEL=stack

# Production with ELK
LOG_CHANNEL=centralized
CENTRALIZED_LOG_DRIVER=elk

# Production with Loki
LOG_CHANNEL=centralized
CENTRALIZED_LOG_DRIVER=loki
```

## Log Format

All logs are structured JSON with the following schema:

```json
{
  "@timestamp": "2024-01-15T10:30:45.123456+07:00",
  "@version": "1",
  "app": "absensi",
  "environment": "production",
  "service": "api",
  "level": "INFO",
  "level_value": 200,
  "message": "Attendance scan successful",
  "channel": "attendance",
  
  "request_id": "a1b2c3d4e5f6-1234",
  "user_id": 42,
  "school_id": 5,
  "endpoint": "/api/v1/attendance/scan",
  "response_time_ms": 125.45,
  "ip": "192.168.1.100",
  
  "request": {
    "id": "a1b2c3d4e5f6-1234",
    "method": "POST",
    "endpoint": "/api/v1/attendance/scan",
    "url": "https://api.example.com/api/v1/attendance/scan",
    "ip": "192.168.1.100",
    "user_agent": "AbsensiMobile/1.0.0",
    "response_time_ms": 125.45,
    "status_code": 200,
    "trace_id": "a1b2c3d4e5f6-1234"
  },
  
  "user": {
    "id": 42,
    "school_id": 5,
    "role": "teacher",
    "email": "teacher@school.com"
  },
  
  "context": {
    "event_type": "attendance.scan.success",
    "attendance_id": 12345,
    "student_id": 789,
    "schedule_id": 101,
    "status": "present"
  },
  
  "server": {
    "hostname": "api-server-1",
    "instance_id": "i-0123456789abcdef",
    "php_version": "8.2.12",
    "memory_usage": 52428800,
    "peak_memory": 67108864
  }
}
```

## Event Types

### Attendance Events

| Event Type | Description |
|------------|-------------|
| `attendance.scan.success` | Successful attendance scan |
| `attendance.scan.failure` | Failed attendance scan |
| `attendance.qr.generated` | QR code generated for schedule |

### Security Events

| Event Type | Description |
|------------|-------------|
| `security.qr.invalid` | Invalid/tampered QR code |
| `security.suspicious` | Suspicious activity detected |
| `security.rate_limit` | Rate limit exceeded |
| `security.unauthorized` | Unauthorized access attempt |
| `security.device_binding` | Device bound/unbound |

### Auth Events

| Event Type | Description |
|------------|-------------|
| `auth.login.success` | Successful login |
| `auth.login.failure` | Failed login attempt |
| `auth.logout` | User logout |
| `auth.password.changed` | Password changed |
| `auth.token.refreshed` | Token refreshed |

### System Events

| Event Type | Description |
|------------|-------------|
| `system.error` | Unhandled exception |
| `system.job.failed` | Queue job failed |
| `system.db.slow_query` | Slow database query |
| `system.cache.miss` | Cache miss |
| `system.external.call` | External service call |

## Usage

### Using EventLoggerService

```php
use App\Services\EventLoggerService;

class AttendanceController
{
    public function __construct(
        private EventLoggerService $eventLogger
    ) {}

    public function scan(Request $request)
    {
        // On success
        $this->eventLogger->attendanceSuccess(
            attendanceId: $attendance->id,
            studentId: $student->id,
            scheduleId: $schedule->id,
            status: 'present',
            extra: ['method' => 'qr_scan']
        );

        // On failure
        $this->eventLogger->attendanceFailure(
            reason: 'qr_expired',
            studentId: $request->student_id,
            extra: ['qr_age_seconds' => 120]
        );
    }
}
```

### Security Events

```php
// Invalid QR
$this->eventLogger->invalidQr(
    reason: 'tampered_signature',
    qrToken: $token
);

// Suspicious activity
$this->eventLogger->suspiciousActivity(
    type: 'multiple_device_login',
    description: 'User logged in from 3 different devices in 5 minutes',
    severity: 'medium'
);

// Rate limit
$this->eventLogger->rateLimitExceeded(
    limiter: 'api.attendance.scan',
    maxAttempts: 10
);
```

### Auth Events

```php
// Login success
$this->eventLogger->loginSuccess(
    userId: $user->id,
    method: 'password'
);

// Login failure
$this->eventLogger->loginFailure(
    identifier: $request->email,
    reason: 'invalid_credentials'
);
```

### Direct Logging with Context

```php
use Illuminate\Support\Facades\Log;

// Context is automatically included from LogContext
Log::channel('attendance')->info('Custom attendance event', [
    'custom_field' => 'value',
    'event_type' => 'attendance.custom',
]);
```

## Infrastructure Setup

### ELK Stack

1. **Deploy Elasticsearch**
```bash
docker-compose -f infrastructure/elk/docker-compose.yml up -d
```

2. **Apply Index Template**
```bash
curl -X PUT "elasticsearch:9200/_index_template/absensi-logs" \
  -H "Content-Type: application/json" \
  -d @infrastructure/elk/elasticsearch/index-template.json
```

3. **Configure Logstash**
```bash
cp infrastructure/elk/logstash/pipeline/absensi.conf /etc/logstash/conf.d/
systemctl restart logstash
```

### Grafana Loki

1. **Deploy Loki**
```bash
docker run -d --name loki \
  -v $(pwd)/infrastructure/loki/loki-config.yaml:/etc/loki/local-config.yaml \
  -p 3100:3100 \
  grafana/loki:latest
```

2. **Deploy Promtail**
```bash
docker run -d --name promtail \
  -v $(pwd)/infrastructure/loki/promtail-config.yaml:/etc/promtail/config.yml \
  -v /var/log/absensi:/var/log/absensi:ro \
  grafana/promtail:latest
```

3. **Import Grafana Dashboard**
- Import `infrastructure/grafana/dashboards/log-analytics.json`

## Querying Logs

### Loki/LogQL Examples

```logql
# All errors in last hour
{app="absensi", level=~"ERROR|CRITICAL"}

# Attendance events for specific school
{app="absensi", channel="attendance", school_id="5"}

# Trace single request
{app="absensi"} |= "a1b2c3d4e5f6-1234"

# Failed logins from specific IP
{app="absensi", channel="auth"} |= "login.failure" | json | ip="192.168.1.100"

# Slow requests (> 1s)
{app="absensi", channel="request"} | json | response_time_ms > 1000
```

### Elasticsearch/Kibana Examples

```json
# All errors
{
  "query": {
    "bool": {
      "must": [
        { "match": { "app": "absensi" }},
        { "terms": { "level": ["ERROR", "CRITICAL"] }}
      ]
    }
  }
}

# Security events by school
{
  "query": {
    "bool": {
      "must": [
        { "match": { "channel": "security" }},
        { "match": { "school_id": "5" }}
      ]
    }
  },
  "aggs": {
    "by_event_type": {
      "terms": { "field": "context.event_type" }
    }
  }
}
```

## Alerting

### Loki Alerts

See `infrastructure/loki/rules/alerts.yaml` for alert rules:
- High error rate (> 5%)
- Multiple failed logins (> 10 in 5 min)
- Invalid QR attempts
- Suspicious activity
- No attendance scans during school hours

### Elasticsearch Watcher

```json
{
  "trigger": {
    "schedule": { "interval": "1m" }
  },
  "input": {
    "search": {
      "request": {
        "indices": ["absensi-*"],
        "body": {
          "query": {
            "bool": {
              "must": [
                { "range": { "@timestamp": { "gte": "now-5m" }}},
                { "match": { "level": "CRITICAL" }}
              ]
            }
          }
        }
      }
    }
  },
  "condition": {
    "compare": { "ctx.payload.hits.total.value": { "gt": 0 }}
  },
  "actions": {
    "notify_slack": {
      "webhook": {
        "url": "{{SLACK_WEBHOOK_URL}}",
        "body": "Critical error detected: {{ctx.payload.hits.total.value}} events"
      }
    }
  }
}
```

## Middleware Registration

Add to `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api([
        \App\Http\Middleware\LogRequestContext::class,
        // ... other middleware
    ]);
})
```

## Files Reference

| File | Purpose |
|------|---------|
| `app/Logging/JsonLogFormatter.php` | Custom JSON formatter with context |
| `app/Logging/LogContext.php` | Request-scoped context storage |
| `app/Logging/CentralizedLoggerFactory.php` | Custom logger factory |
| `app/Http/Middleware/LogRequestContext.php` | Middleware to capture context |
| `app/Services/EventLoggerService.php` | Typed event logging |
| `config/logging.php` | Channel configuration |
| `infrastructure/elk/` | ELK Stack config |
| `infrastructure/loki/` | Loki/Promtail config |
| `infrastructure/grafana/dashboards/log-analytics.json` | Grafana dashboard |
