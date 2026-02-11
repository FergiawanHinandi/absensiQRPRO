# 📊 Monitoring Integration Guide

**DevOps Lead**  
**Date**: 2026-02-10  
**Purpose**: Production monitoring setup for Laravel CQRS SaaS

---

## 🎯 Monitoring Stack

```
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                         │
│                                                              │
│  Laravel App → Health Endpoints → Metrics Collector         │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    Monitoring Tools                          │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │   Horizon    │  │  Prometheus  │  │   Grafana    │     │
│  │  Dashboard   │  │   Metrics    │  │  Dashboard   │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
└─────────────────────────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                    Alerting Layer                            │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │    Slack     │  │  PagerDuty   │  │     SMS      │     │
│  │   Alerts     │  │  Escalation  │  │   Alerts     │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
└─────────────────────────────────────────────────────────────┘
```

---

## 🏥 Health Endpoints

### 1. Main Health Check

**Endpoint**: `GET /api/health`

**Response**:
```json
{
  "status": "healthy",
  "timestamp": "2026-02-10T10:00:00+08:00",
  "checks": {
    "database": {
      "status": "ok",
      "response_time_ms": 12.5
    },
    "redis": {
      "status": "ok",
      "response_time_ms": 5.2
    },
    "queue": {
      "status": "ok",
      "queue_depth": 150
    }
  },
  "version": "1.0.0",
  "environment": "production"
}
```

**Usage**:
```bash
# Check overall health
curl https://absensiqr.com/api/health

# Use in load balancer health check
# Configure nginx upstream health check
```

---

### 2. Database Health

**Endpoint**: `GET /api/health/database`

**Response**:
```json
{
  "status": "ok",
  "message": "Database operational",
  "response_time_ms": 12.5,
  "connection": "mysql"
}
```

**Monitoring**:
```bash
# Monitor database health every 30 seconds
watch -n 30 'curl -s https://absensiqr.com/api/health/database | jq'
```

---

### 3. Redis Health

**Endpoint**: `GET /api/health/redis`

**Response**:
```json
{
  "status": "ok",
  "message": "Redis operational",
  "response_time_ms": 5.2,
  "timeout_duration": 0
}
```

**Alert Trigger**:
```bash
# Alert if Redis timeout > 2 minutes
TIMEOUT=$(curl -s https://absensiqr.com/api/health/redis | jq -r '.timeout_duration')
if [ $TIMEOUT -gt 120 ]; then
  echo "ALERT: Redis timeout exceeded!"
fi
```

---

### 4. Queue Health

**Endpoint**: `GET /api/health/queue`

**Response**:
```json
{
  "status": "ok",
  "message": "Queue operational",
  "queue_depth": 150,
  "failed_jobs": 5,
  "workers_running": true
}
```

**Monitoring**:
```bash
# Monitor queue depth
DEPTH=$(curl -s https://absensiqr.com/api/health/queue | jq -r '.queue_depth')
if [ $DEPTH -gt 5000 ]; then
  echo "WARNING: Queue depth high!"
fi
```

---

### 5. Attendance Test (Critical Feature)

**Endpoint**: `GET /api/health/attendance-test`

**Response**:
```json
{
  "status": "ok",
  "message": "Attendance system operational",
  "data": {
    "today_count": 1250,
    "summary_exists": true,
    "response_time_ms": 15.3
  }
}
```

**Usage in Deployment**:
```bash
# Test critical feature before switching traffic
curl -f https://blue.absensiqr.com/api/health/attendance-test || exit 1
```

---

### 6. Metrics Endpoint

**Endpoint**: `GET /api/metrics`

**Response**:
```json
{
  "error_rate": 0.5,
  "response_time": 150.2,
  "queue_depth": 150,
  "db_failures": 0,
  "memory_usage": 65.5,
  "cpu_usage": 45.2,
  "timestamp": "2026-02-10T10:00:00+08:00"
}
```

---

### 7. Error Rate Metric

**Endpoint**: `GET /api/metrics/error-rate`

**Response**:
```json
{
  "rate": 0.5,
  "threshold": 5.0,
  "status": "ok",
  "timestamp": "2026-02-10T10:00:00+08:00"
}
```

**Alert Trigger**:
```bash
# Alert if error rate > 5%
ERROR_RATE=$(curl -s https://absensiqr.com/api/metrics/error-rate | jq -r '.rate')
if (( $(echo "$ERROR_RATE > 5" | bc -l) )); then
  echo "CRITICAL: Error rate exceeded 5%!"
  # Trigger rollback
fi
```

---

## 📊 Laravel Horizon Dashboard

### Setup

```bash
# Install Horizon
composer require laravel/horizon

# Publish config
php artisan horizon:install

# Migrate
php artisan migrate
```

### Configuration

**File**: `config/horizon.php`

```php
<?php

return [
    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'middleware' => ['web', 'auth'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'failed' => 10080,
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'processes' => 10,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
    ],
];
```

### Access

**URL**: `https://absensiqr.com/horizon`

**Metrics Available**:
- Jobs per minute
- Failed jobs
- Recent jobs
- Queue wait times
- Memory usage

---

## 📈 Prometheus Integration

### Setup

**File**: `config/prometheus.php`

```php
<?php

return [
    'namespace' => 'app',

    'metrics' => [
        'http_requests_total' => [
            'type' => 'counter',
            'help' => 'Total HTTP requests',
            'labels' => ['method', 'route', 'status'],
        ],
        'http_request_duration_seconds' => [
            'type' => 'histogram',
            'help' => 'HTTP request duration',
            'labels' => ['method', 'route'],
            'buckets' => [0.1, 0.5, 1, 2, 5],
        ],
        'queue_jobs_total' => [
            'type' => 'counter',
            'help' => 'Total queue jobs',
            'labels' => ['queue', 'status'],
        ],
    ],
];
```

### Metrics Endpoint

**Endpoint**: `GET /metrics`

**Response** (Prometheus format):
```
# HELP app_http_requests_total Total HTTP requests
# TYPE app_http_requests_total counter
app_http_requests_total{method="GET",route="/api/attendance",status="200"} 1250

# HELP app_http_request_duration_seconds HTTP request duration
# TYPE app_http_request_duration_seconds histogram
app_http_request_duration_seconds_bucket{method="GET",route="/api/attendance",le="0.1"} 850
app_http_request_duration_seconds_bucket{method="GET",route="/api/attendance",le="0.5"} 1200
app_http_request_duration_seconds_sum{method="GET",route="/api/attendance"} 187.5
app_http_request_duration_seconds_count{method="GET",route="/api/attendance"} 1250
```

### Prometheus Configuration

**File**: `prometheus.yml`

```yaml
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  - job_name: 'laravel-app'
    static_configs:
      - targets: ['absensiqr.com:9090']
    metrics_path: '/metrics'
    scheme: 'https'
```

---

## 📊 Grafana Dashboard

### Setup

```bash
# Install Grafana
docker run -d -p 3000:3000 grafana/grafana

# Access: http://localhost:3000
# Default: admin/admin
```

### Dashboard Configuration

**Import Dashboard**: Use ID `12708` (Laravel Metrics)

**Custom Panels**:

1. **Error Rate**
   - Query: `rate(app_http_requests_total{status=~"5.."}[5m])`
   - Threshold: 5%
   - Alert: Critical

2. **Response Time**
   - Query: `histogram_quantile(0.95, app_http_request_duration_seconds)`
   - Threshold: 500ms
   - Alert: Warning

3. **Queue Depth**
   - Query: `app_queue_jobs_pending`
   - Threshold: 5000
   - Alert: Warning

4. **Database Connections**
   - Query: `app_db_connections_active`
   - Threshold: 80% of max
   - Alert: Warning

---

## 🔔 Alert Configuration

### Slack Alerts

**File**: `config/logging.php`

```php
'channels' => [
    'slack' => [
        'driver' => 'slack',
        'url' => env('LOG_SLACK_WEBHOOK_URL'),
        'username' => 'Laravel Log',
        'emoji' => ':boom:',
        'level' => 'critical',
    ],
],
```

**Usage**:
```php
// Log critical errors to Slack
Log::channel('slack')->critical('Deployment rollback triggered', [
    'reason' => 'Error rate exceeded 5%',
    'error_rate' => 7.5,
]);
```

---

### PagerDuty Integration

**File**: `.env`

```env
PAGERDUTY_INTEGRATION_KEY=your_key_here
```

**Alert Script**:
```bash
#!/bin/bash

# pagerduty_alert.sh

INTEGRATION_KEY="$PAGERDUTY_INTEGRATION_KEY"
EVENT_ACTION="trigger"
SEVERITY="critical"
SUMMARY="$1"
DETAILS="$2"

curl -X POST https://events.pagerduty.com/v2/enqueue \
  -H 'Content-Type: application/json' \
  -d "{
    \"routing_key\": \"$INTEGRATION_KEY\",
    \"event_action\": \"$EVENT_ACTION\",
    \"payload\": {
      \"summary\": \"$SUMMARY\",
      \"severity\": \"$SEVERITY\",
      \"source\": \"absensiqr.com\",
      \"custom_details\": {
        \"details\": \"$DETAILS\"
      }
    }
  }"
```

---

## 📝 Slow Query Log

### MySQL Configuration

**File**: `/etc/mysql/my.cnf`

```ini
[mysqld]
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 1
log_queries_not_using_indexes = 1
```

### Laravel Query Logging

**File**: `app/Providers/AppServiceProvider.php`

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

public function boot()
{
    // Log slow queries (>1000ms)
    DB::listen(function ($query) {
        if ($query->time > 1000) {
            Log::warning('Slow query detected', [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
            ]);
        }
    });
}
```

---

## 🎯 Monitoring Checklist

### Daily Checks
- [ ] Check Horizon dashboard
- [ ] Review error logs
- [ ] Check slow query log
- [ ] Verify backup completion
- [ ] Review queue depth

### Weekly Checks
- [ ] Review Grafana dashboards
- [ ] Analyze performance trends
- [ ] Check disk usage
- [ ] Review failed jobs
- [ ] Update monitoring alerts

### Monthly Checks
- [ ] Review alert thresholds
- [ ] Update monitoring dashboards
- [ ] Optimize slow queries
- [ ] Review incident reports
- [ ] Update runbooks

---

**Status**: ✅ Ready for Production  
**Last Updated**: 2026-02-10  
**Version**: 1.0
