# Request Correlation Logging Implementation

## Overview

AbsensiQRPro implements comprehensive request correlation logging that enables:
- **End-to-end request tracing** across all layers (API → Service → Database)
- **Error tracking per user + per date**
- **W3C Trace Context compatibility** for distributed tracing
- **Structured JSON logs** for ELK/Loki integration

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                       HTTP Request                                │
└──────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│           LogRequestContext Middleware                           │
│  • Generates/parses X-Request-ID                                 │
│  • Initializes LogContext singleton                              │
│  • Sets response headers (X-Request-ID, X-Response-Time)         │
└──────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│           TraceRequestMiddleware                                  │
│  • Parses W3C traceparent header                                 │
│  • Generates X-Trace-ID                                          │
│  • Logs critical requests (errors, slow requests)                │
│  • Records metrics to Redis                                       │
└──────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│           Service Layer (e.g., AttendanceService)                │
│  • LogContext::pushSpan('operation.name')                        │
│  • Include LogContext::getCorrelationContext() in logs           │
│  • LogContext::popSpan() on completion                           │
└──────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│           Log Channels (attendance, security, request, auth)     │
│  • JSON formatted logs with correlation IDs                      │
│  • Automatic context inclusion                                   │
└──────────────────────────────────────────────────────────────────┘
```

## Middleware Implementation

### 1. LogRequestContext Middleware

**Location:** `app/Http/Middleware/LogRequestContext.php`

```php
<?php

namespace App\Http\Middleware;

use App\Logging\LogContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogRequestContext
{
    public function handle(Request $request, \Closure $next): Response
    {
        // Initialize context from request (generates request_id if not present)
        LogContext::initFromRequest($request);
        
        $startTime = microtime(true);
        $response = $next($request);
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        // Set correlation headers for client
        $response->headers->set('X-Request-ID', LogContext::get('request_id'));
        $response->headers->set('X-Response-Time', round($responseTime, 2) . 'ms');
        
        // Set response context for logging
        LogContext::setResponse($response->getStatusCode(), $responseTime);
        
        return $response;
    }
    
    public function terminate(Request $request, Response $response): void
    {
        // Log request completion (excludes health/metrics)
        if (!$this->shouldExclude($request)) {
            Log::channel('request')->info('Request completed', LogContext::all());
        }
        
        // Clear context for next request
        LogContext::clear();
    }
}
```

### 2. TraceRequestMiddleware

**Location:** `app/Http/Middleware/TraceRequestMiddleware.php`

Handles W3C Trace Context and critical request logging:

```php
// Parses traceparent header: version-trace_id-parent_id-flags
// Example: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
$traceId = $this->parseTraceParent($request->header('traceparent'));

// Sets X-Trace-ID response header
$response->headers->set('X-Trace-ID', LogContext::get('trace_id'));

// Logs based on response status
if ($status >= 500) {
    Log::channel('request')->error('Server error', $context);
} elseif ($responseTime > 2000) {
    Log::channel('request')->warning('Slow request', $context);
}
```

## LogContext Singleton

**Location:** `app/Logging/LogContext.php`

### Correlation Hierarchy

| Field | Description | Scope |
|-------|-------------|-------|
| `request_id` | Unique per HTTP request (idempotency key) | Request |
| `trace_id` | End-to-end trace across services | Distributed |
| `span_id` | Current operation span | Operation |
| `parent_span_id` | Parent span for call hierarchy | Operation |
| `user_id` | Authenticated user ID | Request |
| `school_id` | Multi-tenant school context | Request |
| `request_date` | Date for per-date filtering | Request |

### Key Methods

```php
// Initialize context from HTTP request
LogContext::initFromRequest($request);

// Set user after authentication
LogContext::setUser($user);

// Start a new span for an operation
$spanId = LogContext::pushSpan('AttendanceService.recordScan');

// Get correlation context for logging
$context = LogContext::getCorrelationContext();
// Returns: request_id, trace_id, span_id, parent_span_id, operation, user_id, school_id

// Complete span and get duration
$span = LogContext::popSpan();
// Returns: span_id, operation, duration_ms, parent_span_id

// Get specific values
$requestDate = LogContext::get('request_date');
$userId = LogContext::get('user_id');

// Clear context at end of request
LogContext::clear();
```

## JSON Log Format

### Standard Log Entry Structure

```json
{
  "message": "Security Alert: signature_failed",
  "level": "WARNING",
  "level_name": "WARNING",
  "channel": "attendance",
  "datetime": "2025-01-15T10:30:45.123456+07:00",
  "context": {
    "request_id": "a1b2c3d4e5f6g7h8-1234",
    "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
    "span_id": "00f067aa0ba902b7",
    "parent_span_id": "b3c4d5e6f7g8h9i0",
    "operation": "AttendanceService.recordByTeacherScan",
    "user_id": 42,
    "school_id": 1,
    "request_date": "2025-01-15",
    "actor_id": 42,
    "actor_name": "Budi Santoso",
    "ip": "192.168.1.100",
    "user_agent": "Mozilla/5.0...",
    "token_snippet": "eyJhbGciOi...",
    "details": "Invalid signature",
    "timestamp": "2025-01-15T10:30:45+07:00"
  },
  "extra": {}
}
```

### Security Anomaly Log Entry

```json
{
  "message": "ANOMALY: teacher_geofence_violation",
  "level": "ALERT",
  "level_name": "ALERT",
  "channel": "security",
  "datetime": "2025-01-15T10:32:00.456789+07:00",
  "context": {
    "request_id": "x9y8z7w6v5u4t3s2-5678",
    "trace_id": "9c8d7e6f5g4h3i2j1k0l",
    "span_id": "m1n2o3p4q5r6s7t8",
    "parent_span_id": null,
    "operation": "AttendanceService.recordByTeacherScan",
    "user_id": 15,
    "school_id": 3,
    "request_date": "2025-01-15",
    "teacher_id": 15,
    "distance": 1250.45,
    "max_radius": 500,
    "lat": -6.2088,
    "lng": 106.8456,
    "geofence_policy": "attendance.teacher_geofence_radius_meters",
    "ip": "192.168.1.50",
    "user_agent": "AbsensiQRMobile/1.0.0",
    "timestamp": "2025-01-15T10:32:00+07:00"
  }
}
```

### Request Completion Log

```json
{
  "message": "Request completed",
  "level": "INFO",
  "channel": "request",
  "datetime": "2025-01-15T10:30:45.789012+07:00",
  "context": {
    "request_id": "a1b2c3d4e5f6g7h8-1234",
    "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
    "span_id": "00f067aa0ba902b7",
    "method": "POST",
    "endpoint": "/api/v1/attendance/scan",
    "url": "http://api.example.com/api/v1/attendance/scan",
    "ip": "192.168.1.100",
    "user_agent": "AbsensiQRMobile/1.0.0",
    "user_id": 42,
    "school_id": 1,
    "request_date": "2025-01-15",
    "status_code": 200,
    "response_time": 245.67
  }
}
```

## Tracking Errors Per User + Per Date

### Using `request_date` and `user_id`

Every log entry includes:
- `user_id` - The authenticated user
- `school_id` - Multi-tenant context
- `request_date` - Date string (YYYY-MM-DD)

### Query Examples (ELK/Kibana)

```kql
# All errors for a specific user on a specific date
channel:security AND user_id:42 AND request_date:"2025-01-15"

# All geofence violations for a school today
channel:security AND message:*geofence* AND school_id:3 AND request_date:"2025-01-15"

# Trace a specific request across all logs
request_id:"a1b2c3d4e5f6g7h8-1234"

# All errors in AttendanceService
operation:AttendanceService.* AND level:ERROR
```

### Query Examples (Loki/Grafana)

```logql
# Security anomalies for user 42
{channel="security"} | json | user_id=42

# All errors on a specific date
{channel="security"} | json | request_date="2025-01-15"

# Trace by request_id
{channel=~"security|attendance|request"} | json | request_id="a1b2c3d4e5f6g7h8-1234"
```

## Service Layer Integration

### Using LogContext in Services

```php
use App\Logging\LogContext;

class AttendanceService
{
    public function recordByTeacherScan(User $teacher, string $qrToken, ...): array
    {
        // Start span for this operation
        LogContext::pushSpan('AttendanceService.recordByTeacherScan');
        LogContext::setUser($teacher);
        
        try {
            // Business logic...
            
            // Include correlation in all logs
            Log::channel('attendance')->info('Attendance recorded', [
                ...LogContext::getCorrelationContext(),
                'student_id' => $student->id,
                'schedule_id' => $schedule->id,
            ]);
            
            return $result;
        } catch (AttendanceException $e) {
            Log::channel('attendance')->warning('Attendance failed', [
                ...LogContext::getCorrelationContext(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Complete span and log duration
            $span = LogContext::popSpan();
            Log::channel('attendance')->info('Span completed', [
                ...LogContext::getCorrelationContext(),
                'span_duration_ms' => $span['duration_ms'],
                'operation' => $span['operation'],
            ]);
        }
    }
}
```

### Nested Spans Example

```php
public function processComplexOperation()
{
    LogContext::pushSpan('ComplexService.process');
    
    try {
        // Step 1
        LogContext::pushSpan('ComplexService.validateInput');
        $this->validate();
        LogContext::popSpan();
        
        // Step 2
        LogContext::pushSpan('ComplexService.saveToDatabase');
        $this->save();
        LogContext::popSpan();
        
        // Step 3
        LogContext::pushSpan('ComplexService.notifyUsers');
        $this->notify();
        LogContext::popSpan();
        
    } finally {
        LogContext::popSpan();
    }
}
```

## Log Channels Configuration

**Location:** `config/logging.php`

| Channel | Purpose | Retention | Format |
|---------|---------|-----------|--------|
| `request` | HTTP request/response logs | 7 days | JSON |
| `auth` | Authentication events | 30 days | JSON |
| `security` | Security anomalies | 30 days | JSON |
| `attendance` | Attendance operations | 30 days | Plain |
| `attendance_json` | Attendance for ELK | N/A | JSON |
| `centralized` | Aggregated (ELK + daily) | Varies | JSON |

## Response Headers

The middleware automatically adds these headers to every response:

| Header | Description | Example |
|--------|-------------|---------|
| `X-Request-ID` | Unique request identifier | `a1b2c3d4e5f6g7h8-1234` |
| `X-Trace-ID` | Distributed trace ID | `4bf92f3577b34da6a...` |
| `X-Response-Time` | Request duration | `245.67ms` |

## Testing Correlation

### Manual Test

```bash
# Send request with custom trace ID
curl -X POST http://localhost:8000/api/v1/attendance/scan \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Request-ID: test-request-12345" \
  -H "traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01" \
  -H "Content-Type: application/json" \
  -d '{"qr_token": "..."}'

# Check response headers
# X-Request-ID: test-request-12345
# X-Trace-ID: 4bf92f3577b34da6a3ce929d0e0e4736
# X-Response-Time: 156.23ms
```

### Verify in Logs

```bash
# Search for the request
grep "test-request-12345" storage/logs/*.log

# Should find entries in request, attendance, and security logs
# all with the same request_id for correlation
```

## Summary

1. **X-Request-ID Middleware**: `LogRequestContext` generates/propagates unique request IDs
2. **JSON Log Format**: All channels use `JsonLogFormatter` with structured context
3. **Per User + Date Tracking**: `user_id` and `request_date` fields in every log entry
4. **AttendanceService Integration**: Uses `LogContext::pushSpan()`, `getCorrelationContext()`, `popSpan()`
5. **W3C Trace Context**: Full support for distributed tracing via `traceparent` header
