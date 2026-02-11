# Observability & Monitoring Strategy
**AbsensiQRPro - Production Monitoring Guide**  
**Date:** February 7, 2026

---

## Executive Summary

This document describes the observability architecture for AbsensiQRPro, including:
- **Global Request ID** for end-to-end request tracing
- **Correlation Chain** from API → Service → Database
- **Log Levels** (info, warning, critical) with proper categorization
- **Per-User/Per-Date Tracing** for error investigation
- **Production Monitoring Strategy** with alerting rules

---

## 1. Correlation Architecture

### 1.1 Request ID Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     Request Correlation Flow                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Mobile App / Frontend                                                   │
│      │                                                                   │
│      │ X-Request-ID: abc123 (optional, or generated)                    │
│      │ X-Trace-ID: xyz789 (optional, for distributed tracing)           │
│      ▼                                                                   │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  TraceRequestMiddleware / LogRequestContext                       │   │
│  │  ─────────────────────────────────────────────────────────────── │   │
│  │  • Generates request_id if not provided                          │   │
│  │  • Generates trace_id (W3C traceparent compatible)               │   │
│  │  • Creates root span_id                                          │   │
│  │  • Stores in LogContext singleton                                │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│      │                                                                   │
│      │ LogContext: {request_id, trace_id, span_id, user_id, ...}        │
│      ▼                                                                   │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Controller                                                       │   │
│  │  ─────────────────────────────────────────────────────────────── │   │
│  │  All logs auto-include correlation context                       │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│      │                                                                   │
│      │ LogContext::pushSpan('AttendanceService.recordScan')             │
│      ▼                                                                   │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Service (with LogsWithCorrelation trait)                        │   │
│  │  ─────────────────────────────────────────────────────────────── │   │
│  │  • New span_id created, parent_span_id preserved                 │   │
│  │  • All logs include full correlation chain                       │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│      │                                                                   │
│      │ DB::listen() captures query with correlation                     │
│      ▼                                                                   │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Database Query (ObservabilityServiceProvider)                   │   │
│  │  ─────────────────────────────────────────────────────────────── │   │
│  │  • Logs slow queries with full context                           │   │
│  │  • Includes request_id, trace_id, span_id, user_id               │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│      │                                                                   │
│      ▼                                                                   │
│  Response with X-Request-ID, X-Trace-ID headers                         │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Key Identifiers

| Field | Purpose | Scope | Example |
|-------|---------|-------|---------|
| `request_id` | Unique per HTTP request | Single request | `abc123def456-7890` |
| `trace_id` | End-to-end trace across services | Distributed systems | `4bf92f3577b34da6` |
| `span_id` | Current operation span | Layer/method | `a3ce929d0e0e4736` |
| `parent_span_id` | Parent span for hierarchy | Call stack | `00f067aa0ba902b7` |

---

## 2. JSON Log Format

### 2.1 Standard Log Structure

```json
{
    "@timestamp": "2026-02-07T10:30:45.123+07:00",
    "@version": "1",
    
    "app": "absensi",
    "environment": "production",
    "service": "api",
    
    "level": "warning",
    "level_value": 300,
    "message": "Slow Database Query",
    "channel": "system",
    
    "request": {
        "id": "abc123def456-7890",
        "method": "POST",
        "endpoint": "/api/v1/attendance/scan",
        "url": "https://api.absensi.com/api/v1/attendance/scan",
        "ip": "103.28.12.45",
        "user_agent": "AbsensiQRMobile/1.0.0",
        "response_time_ms": 1250.45,
        "status_code": 200,
        "trace_id": "4bf92f3577b34da6"
    },
    
    "user": {
        "id": 456,
        "school_id": 12,
        "role": "teacher",
        "email": "teacher@school.edu"
    },
    
    "context": {
        "sql": "SELECT * FROM attendances WHERE...",
        "duration_ms": 1050.23,
        "connection": "pgsql"
    },
    
    "server": {
        "hostname": "api-server-01",
        "instance_id": "i-abc123",
        "php_version": "8.2.15",
        "memory_usage": 52428800
    },
    
    "request_id": "abc123def456-7890",
    "trace_id": "4bf92f3577b34da6",
    "span_id": "a3ce929d0e0e4736",
    "parent_span_id": "00f067aa0ba902b7",
    "user_id": 456,
    "school_id": 12,
    "request_date": "2026-02-07"
}
```

### 2.2 Log Categories

| Category | Channel | File | Retention | Purpose |
|----------|---------|------|-----------|---------|
| `attendance` | attendance | `logs/attendance.log` | 30 days | Scan events, QR generation |
| `security` | security | `logs/security.log` | 90 days | Invalid QR, suspicious activity |
| `auth` | auth | `logs/auth.log` | 30 days | Login, logout, password changes |
| `system` | system | `logs/system.log` | 14 days | Errors, slow queries, job failures |
| `request` | request | `logs/requests.log` | 7 days | HTTP request/response logs |

---

## 3. Log Levels Guide

### 3.1 Level Definitions

| Level | When to Use | Example | Alert? |
|-------|-------------|---------|--------|
| `debug` | Development only | Query execution details | No |
| `info` | Normal operations | Attendance scan success | No |
| `warning` | Recoverable issues | Slow query, rate limit | Monitor |
| `error` | Failures needing attention | Scan failure, auth error | Yes |
| `critical` | System-level failures | DB down, service unavailable | Page on-call |

### 3.2 Automatic Level Assignment

```php
// In LogRequestContext middleware
protected function determineLogLevel(int $statusCode, float $responseTimeMs): string
{
    if ($statusCode >= 500) return 'error';      // Server errors
    if ($statusCode >= 400) return 'warning';    // Client errors
    if ($responseTimeMs > 1000) return 'warning'; // Slow requests
    return 'info';                                // Normal
}

// In ObservabilityServiceProvider for DB queries
if ($duration > 5000) return 'critical';  // Very slow (>5s)
if ($duration > 1000) return 'warning';   // Slow (>1s)
return 'debug';                            // Normal
```

---

## 4. Per-User & Per-Date Tracing

### 4.1 Filtering in Kibana/Loki

```
# Find all errors for a specific user today
user_id:456 AND level:error AND request_date:"2026-02-07"

# Trace a specific request through the stack
request_id:"abc123def456-7890"

# Find all slow queries for a school
school_id:12 AND message:"Slow Database Query"

# Track a distributed trace
trace_id:"4bf92f3577b34da6"
```

### 4.2 Error Investigation Workflow

```
1. User reports: "Absensi gagal jam 10:30"

2. Find the error:
   Kibana: user_id:456 AND level:(error OR warning) AND @timestamp:[10:25 TO 10:35]

3. Get the request_id from the error log

4. Trace full request:
   Kibana: request_id:"abc123def456-7890"
   → See: Controller → Service → DB queries → External calls

5. Check related spans:
   Kibana: trace_id:"4bf92f3577b34da6" | sort @timestamp

6. Identify root cause:
   - Slow DB query? Check query and indexes
   - External API timeout? Check external service health
   - Business logic error? Check context data
```

---

## 5. Production Monitoring Strategy

### 5.1 Recommended Stack

```
┌─────────────────────────────────────────────────────────────────────┐
│                     Observability Stack                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  OPTION A: ELK Stack (Self-hosted)                                  │
│  ────────────────────────────────────────────────────────────────── │
│  Elasticsearch → Logstash → Kibana                                  │
│  + Elastalert for alerting                                          │
│                                                                      │
│  OPTION B: Grafana Stack (Lightweight)                              │
│  ────────────────────────────────────────────────────────────────── │
│  Promtail → Loki → Grafana                                          │
│  + Grafana Alerting                                                  │
│                                                                      │
│  OPTION C: Cloud Services                                           │
│  ────────────────────────────────────────────────────────────────── │
│  Datadog / New Relic / Sentry                                       │
│  + Built-in APM and alerting                                        │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### 5.2 Environment Configuration

```env
# .env for Production

# Centralized Logging
LOG_CHANNEL=centralized
LOG_LEVEL=info
LOG_STDERR_FORMATTER=

# ELK Stack
CENTRALIZED_LOG_DRIVER=elk
LOGSTASH_HOST=logstash.internal
LOGSTASH_PORT=5044
LOGSTASH_PROTOCOL=tcp

# OR Grafana Loki
# CENTRALIZED_LOG_DRIVER=loki
# LOKI_HOST=loki.internal
# LOKI_PORT=3100

# Debug Settings (disable in production)
LOG_ALL_QUERIES=false

# Sentry (complementary for errors)
SENTRY_ENABLED=true
SENTRY_DSN=https://xxx@sentry.io/123
SENTRY_TRACES_SAMPLE_RATE=0.1
```

### 5.3 Alerting Rules

#### Critical Alerts (Page On-Call)

| Metric | Threshold | Action |
|--------|-----------|--------|
| Error Rate | > 5% in 5 min | Page |
| Response Time P99 | > 5s | Page |
| Database Connection | Failed | Page |
| Queue Failure Rate | > 10% | Page |
| Disk Usage | > 90% | Page |

#### Warning Alerts (Slack/Email)

| Metric | Threshold | Action |
|--------|-----------|--------|
| Error Rate | > 1% in 5 min | Slack |
| Response Time P95 | > 2s | Slack |
| Slow Queries | > 10/min | Slack |
| Rate Limit Hits | > 100/min | Slack |
| Job Queue Depth | > 1000 | Slack |

### 5.4 Grafana Dashboard Panels

```
┌─────────────────────────────────────────────────────────────────────┐
│  AbsensiQRPro - Production Dashboard                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐        │
│  │ Request Rate    │ │ Error Rate      │ │ P99 Latency     │        │
│  │ 1,234 req/min   │ │ 0.3%            │ │ 450ms           │        │
│  └─────────────────┘ └─────────────────┘ └─────────────────┘        │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Requests by Endpoint (Top 10)                                │    │
│  │ ─────────────────────────────────────────────────────────── │    │
│  │ POST /api/v1/attendance/scan     ██████████████████ 45%     │    │
│  │ GET  /api/v1/schedules           ████████████ 30%           │    │
│  │ POST /api/v1/auth/login          ███████ 15%                │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Response Time Histogram                                      │    │
│  │ ─────────────────────────────────────────────────────────── │    │
│  │     P50: 120ms | P95: 350ms | P99: 450ms                    │    │
│  │ ▂▃▅█▇▅▃▂▁                                                    │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌────────────────────────────┐ ┌────────────────────────────┐      │
│  │ Errors by Type (Last 1h)   │ │ Slow Queries (>1s)         │      │
│  │ ─────────────────────────  │ │ ────────────────────────── │      │
│  │ 422 Validation    12       │ │ Total: 23                  │      │
│  │ 401 Unauthorized  5        │ │ Max: 2.3s                  │      │
│  │ 500 Server Error  2        │ │ Avg: 1.4s                  │      │
│  └────────────────────────────┘ └────────────────────────────┘      │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ Recent Errors (Live Feed)                                    │    │
│  │ ─────────────────────────────────────────────────────────── │    │
│  │ 10:30:45 | user:456 | POST /scan | QR expired               │    │
│  │ 10:28:12 | user:789 | POST /login | Invalid credentials     │    │
│  │ 10:25:33 | user:123 | POST /scan | Outside geofence         │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 6. Implementation Examples

### 6.1 Middleware Logging (Already Implemented)

```php
// app/Http/Middleware/LogRequestContext.php

public function handle(Request $request, Closure $next): Response
{
    $startTime = microtime(true);

    // Initialize log context from request
    LogContext::initFromRequest($request);
    
    // Process request
    $response = $next($request);

    // Calculate response time
    $responseTimeMs = (microtime(true) - $startTime) * 1000;
    LogContext::setResponse($response->getStatusCode(), $responseTimeMs);

    // Set user context if authenticated
    if ($user = $request->user()) {
        LogContext::setUser($user);
    }

    // Add request ID to response headers
    $response->headers->set('X-Request-ID', LogContext::get('request_id'));
    $response->headers->set('X-Trace-ID', LogContext::get('trace_id'));

    // Log request completion
    $this->logRequestCompletion($request, $response, $responseTimeMs);

    return $response;
}
```

### 6.2 Service Logging with Correlation Trait

```php
// app/Services/AttendanceService.php

class AttendanceService
{
    use LogsWithCorrelation;

    public function recordScan(User $teacher, array $data): Attendance
    {
        return $this->withServiceSpan('recordScan', function () use ($teacher, $data) {
            $this->logInfo('Processing attendance scan', [
                'teacher_id' => $teacher->id,
                'qr_token_hash' => hash('sha256', $data['qr_token']),
            ]);

            // Validate QR (creates nested span)
            $qrData = $this->withServiceSpan('validateQr', function () use ($data) {
                return $this->qrService->validate($data['qr_token']);
            });

            // Validate location (creates nested span)
            $this->withServiceSpan('validateLocation', function () use ($data, $qrData) {
                if (!$this->isWithinRadius($data, $qrData)) {
                    $this->logWarning('Location outside geofence', [
                        'student_lat' => $data['lat'],
                        'student_lng' => $data['lng'],
                        'expected_lat' => $qrData['lat'],
                        'expected_lng' => $qrData['lng'],
                    ]);
                    throw new OutsideGeofenceException();
                }
            });

            // Create attendance record
            $attendance = Attendance::create([...]);

            $this->logSuccess('Attendance recorded', [
                'attendance_id' => $attendance->id,
                'student_id' => $attendance->student_id,
                'status' => $attendance->status,
            ]);

            return $attendance;
        });
    }
}
```

### 6.3 Database Query Logging (Automatic)

```php
// app/Providers/ObservabilityServiceProvider.php

DB::listen(function (QueryExecuted $query) {
    $context = [
        'sql' => $this->sanitizeSql($query->sql),
        'duration_ms' => round($query->time, 2),
        'connection' => $query->connectionName,
        // Full correlation chain
        'request_id' => LogContext::get('request_id'),
        'trace_id' => LogContext::get('trace_id'),
        'span_id' => LogContext::get('span_id'),
        'parent_span_id' => LogContext::get('parent_span_id'),
        'operation' => LogContext::get('current_operation'),
        'user_id' => LogContext::get('user_id'),
        'school_id' => LogContext::get('school_id'),
        'request_date' => LogContext::get('request_date'),
    ];

    if ($query->time > 5000) {
        Log::channel('system')->critical('Critical: Very Slow Query', $context);
    } elseif ($query->time > 1000) {
        Log::channel('system')->warning('Slow Database Query', $context);
    }
});
```

---

## 7. Tracing Example

### Complete Request Trace

```
Request: POST /api/v1/attendance/scan
request_id: "req-abc123"
trace_id: "trace-xyz789"

─────────────────────────────────────────────────────────────────────────

[10:30:45.000] INFO http.post
  span_id: span-001
  message: "HTTP Request Started"
  endpoint: /api/v1/attendance/scan

    [10:30:45.010] INFO AttendanceService.recordScan
      span_id: span-002
      parent_span_id: span-001
      message: "Processing attendance scan"

        [10:30:45.015] DEBUG AttendanceService.validateQr
          span_id: span-003
          parent_span_id: span-002
          message: "Validating QR token"

        [10:30:45.025] DEBUG DB Query
          span_id: span-003
          sql: "SELECT * FROM schedules WHERE id = ?"
          duration_ms: 5.2

        [10:30:45.030] INFO AttendanceService.validateQr completed
          span_id: span-003
          duration_ms: 15

        [10:30:45.035] DEBUG AttendanceService.validateLocation
          span_id: span-004
          parent_span_id: span-002
          message: "Validating location"

        [10:30:45.040] DEBUG DB Query
          span_id: span-004
          sql: "SELECT lat, lng FROM schools WHERE id = ?"
          duration_ms: 3.1

        [10:30:45.045] INFO AttendanceService.validateLocation completed
          span_id: span-004
          duration_ms: 10

        [10:30:45.050] DEBUG DB Query
          span_id: span-002
          sql: "INSERT INTO attendances (...) VALUES (...)"
          duration_ms: 8.5

    [10:30:45.060] INFO AttendanceService.recordScan completed
      span_id: span-002
      duration_ms: 50
      message: "Attendance recorded"
      attendance_id: 12345

[10:30:45.065] INFO http.post completed
  span_id: span-001
  duration_ms: 65
  status_code: 200
```

---

## 8. Files Modified/Created

| File | Type | Purpose |
|------|------|---------|
| [LogContext.php](../app/Logging/LogContext.php) | Modified | Added span stack, correlation methods |
| [CorrelatedLogger.php](../app/Logging/CorrelatedLogger.php) | Created | Typed logging with auto-correlation |
| [LogsWithCorrelation.php](../app/Traits/LogsWithCorrelation.php) | Created | Service trait for correlated logging |
| [ObservabilityServiceProvider.php](../app/Providers/ObservabilityServiceProvider.php) | Modified | Enhanced DB/Queue/HTTP tracing |

---

## 9. Quick Reference

### Using CorrelatedLogger

```php
$logger = app(CorrelatedLogger::class);

// Basic logging
$logger->info('attendance', 'Scan completed', ['student_id' => 123]);
$logger->warning('security', 'Rate limit approaching', ['current' => 45, 'max' => 60]);
$logger->error('system', 'Database connection failed', ['host' => 'db.internal']);

// With span (auto-timing)
$result = $logger->withSpan('validate_qr', function () use ($token) {
    return $this->qrService->validate($token);
}, 'attendance');

// Exception logging
try {
    // ...
} catch (\Throwable $e) {
    $logger->exception('system', $e, ['context' => 'data']);
}
```

### Using LogsWithCorrelation Trait

```php
class MyService
{
    use LogsWithCorrelation;

    public function doSomething(): void
    {
        return $this->withServiceSpan('doSomething', function () {
            $this->logInfo('Starting operation');
            // ... logic ...
            $this->logSuccess('doSomething');
        });
    }
}
```

### Manual Span Management

```php
$spanId = LogContext::pushSpan('my_operation');
try {
    // ... do work ...
} finally {
    $spanInfo = LogContext::popSpan();
    // $spanInfo['duration_ms'] contains timing
}
```
