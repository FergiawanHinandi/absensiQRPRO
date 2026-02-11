# Enterprise Observability Layer - Implementation Summary

## Overview
This document describes the enterprise observability layer added to AbsensiQRPro for monitoring, alerting, and operational visibility.

## Components Created

### 1. ObservabilityService (`app/Services/ObservabilityService.php`)
Central service for collecting and managing metrics:
- **Response Time Tracking**: Records request duration, alerts if >2 seconds
- **Error Rate Monitoring**: Tracks 4xx/5xx responses, alerts if >5% in 5-minute window
- **Slow Query Aggregation**: Stores slow queries (>1000ms) for analysis
- **Queue Health Monitoring**: Checks pending/failed jobs, worker status

### 2. Events (`app/Events/`)
- **SlowResponseDetected**: Dispatched when response time exceeds threshold
- **HighErrorRateDetected**: Dispatched when error rate exceeds 5%

Both events include `toAlertPayload()` for integration with external systems.

### 3. ObservabilityMiddleware (`app/Http/Middleware/ObservabilityMiddleware.php`)
Global middleware (prepended to API stack) that:
- Measures request duration
- Records errors for error rate calculation
- Triggers alerts automatically
- Adds `X-Response-Time` header in debug mode

### 4. SendMonitoringAlert Listener (`app/Listeners/SendMonitoringAlert.php`)
Event subscriber that forwards alerts to:
- **Slack** (via webhook)
- **PagerDuty** (via Events API v2)

Configure in `config/services.php`:
```php
'monitoring' => [
    'slack_webhook' => env('MONITORING_SLACK_WEBHOOK'),
    'pagerduty_key' => env('MONITORING_PAGERDUTY_KEY'),
],
```

### 5. Health Controller Enhancement
Added `/api/v1/metrics` endpoint for observability metrics:
```
GET /api/v1/metrics
Authorization: Bearer {token}
Requires: super_admin or admin role
```

Response:
```json
{
  "status": "ok|warning|alert",
  "timestamp": "2026-02-07T12:00:00Z",
  "environment": "production",
  "metrics": {
    "error_rate": {
      "current_percent": 0.5,
      "threshold_percent": 5.0,
      "window_seconds": 300,
      "status": "ok"
    },
    "response_time": {
      "count": 1000,
      "avg_ms": 150.5,
      "min_ms": 10.2,
      "max_ms": 2500.0,
      "p95_ms": 800.0,
      "p99_ms": 1200.0,
      "slow_count": 5,
      "slow_threshold_ms": 2000
    },
    "slow_queries": {
      "count": 3,
      "last_hour": [...]
    },
    "queue_health": {
      "connection": "database",
      "pending_jobs": 10,
      "failed_jobs": 2,
      "workers_running": true,
      "status": "ok"
    }
  }
}
```

### 6. QueueWorkerHeartbeat Job (`app/Jobs/QueueWorkerHeartbeat.php`)
Job dispatched every minute by scheduler to verify queue workers are running.

### 7. RotateLogsCommand (`app/Console/Commands/RotateLogsCommand.php`)
Log rotation command for maintenance:
```bash
php artisan logs:rotate --days=30 --max-size=100 --dry-run
```
- Deletes logs older than retention period
- Rotates logs exceeding max size
- Compresses rotated logs (Linux only)

### 8. Supervisor Configuration (`supervisor.conf`)
Production-ready supervisor config for:
- **Queue Workers** (4 processes, auto-restart)
- **Scheduler** (runs `schedule:run` every minute)
- **Reverb WebSocket** server

Copy to `/etc/supervisor/conf.d/absensi-worker.conf` and run:
```bash
sudo supervisorctl reread
sudo supervisorctl update
```

### 9. Horizon Configuration (`config/horizon.php`)
Redis queue management with:
- Multi-queue support (high, default, attendance, notifications, low)
- Auto-scaling workers (production: 2-10 processes)
- Dedicated high-priority supervisor
- 7-day failed job retention

Install Horizon if using Redis:
```bash
composer require laravel/horizon
php artisan horizon:install
```

### 10. HorizonAuthMiddleware (`app/Http/Middleware/HorizonAuthMiddleware.php`)
Restricts Horizon dashboard access to `super_admin` users in production.

## Scheduled Tasks Added

In `routes/console.php`:
```php
// Queue Worker Heartbeat - verify workers every minute
Schedule::job(new QueueWorkerHeartbeat)->everyMinute()->withoutOverlapping();

// Log Rotation - cleanup daily at 04:00 AM
Schedule::command('logs:rotate --days=30 --max-size=100')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();
```

## Integration with Existing Infrastructure

### ObservabilityServiceProvider Updates
- Registered `ObservabilityService` as singleton
- Integrated slow query recording into DB::listen()

### EventServiceProvider Updates
- Added SlowResponseDetected listener
- Added HighErrorRateDetected listener
- Registered SendMonitoringAlert subscriber

### Bootstrap/app.php Updates
- Added ObservabilityMiddleware to API middleware stack (prepended for accurate timing)

## Environment Variables

Add to `.env`:
```env
# Monitoring integrations (optional)
MONITORING_SLACK_WEBHOOK=https://hooks.slack.com/services/xxx
MONITORING_PAGERDUTY_KEY=your-pagerduty-routing-key

# Horizon (if using Redis)
HORIZON_PREFIX=absensi-horizon:
```

## Alert Thresholds

| Metric | Warning | Critical | Action |
|--------|---------|----------|--------|
| Response Time | >2000ms | >5000ms | SlowResponseDetected event |
| Error Rate | >5% | >10% | HighErrorRateDetected event |
| Slow Query | >1000ms | >5000ms | Logged to system channel |
| Queue Workers | Not running | - | Logged, visible in /metrics |

## Usage Examples

### Check Current Metrics
```bash
curl -H "Authorization: Bearer $TOKEN" https://api.example.com/api/v1/metrics
```

### Manual Log Rotation
```bash
# Preview what would be deleted
php artisan logs:rotate --dry-run

# Rotate with 14-day retention
php artisan logs:rotate --days=14
```

### Check Queue Worker Status
```bash
# Via supervisor
sudo supervisorctl status absensi-worker:*

# Via API
curl -H "Authorization: Bearer $TOKEN" https://api.example.com/api/v1/metrics | jq '.metrics.queue_health'
```

## Files Created/Modified

**Created:**
- `app/Services/ObservabilityService.php`
- `app/Events/SlowResponseDetected.php`
- `app/Events/HighErrorRateDetected.php`
- `app/Http/Middleware/ObservabilityMiddleware.php`
- `app/Http/Middleware/HorizonAuthMiddleware.php`
- `app/Listeners/SendMonitoringAlert.php`
- `app/Jobs/QueueWorkerHeartbeat.php`
- `app/Console/Commands/RotateLogsCommand.php`
- `config/horizon.php`
- `supervisor.conf`

**Modified:**
- `app/Http/Controllers/Api/V1/HealthController.php` - Added metrics() method
- `app/Providers/ObservabilityServiceProvider.php` - Integrated service
- `app/Providers/EventServiceProvider.php` - Added listeners
- `bootstrap/app.php` - Added middleware
- `routes/console.php` - Added scheduled tasks
- `routes/api/v1/common.php` - Added metrics route
