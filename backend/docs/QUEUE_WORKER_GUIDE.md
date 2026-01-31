# Queue Worker Configuration Guide

## Overview

AbsensiQRPro uses Laravel queues to process background jobs (logs, notifications, broadcasts) without blocking critical attendance writes. This guide covers production queue worker setup using Supervisor.

## Critical Architecture Decisions

### ✅ Synchronous Operations (Never Queued)
- Database attendance records (`Attendance::create()`)
- QR code verification
- Student validation
- Schedule lookups

### 🔄 Asynchronous Operations (Queued)
- Event broadcasting (`StudentAttended` event)
- Security audit logs
- Email notifications
- Report generation

## Queue Configuration

### Environment Setup

Ensure `.env` is configured correctly:

```env
# Use 'database' for simple setup or 'redis' for high-performance
QUEUE_CONNECTION=database

# For redis (production recommended):
# QUEUE_CONNECTION=redis
# REDIS_HOST=127.0.0.1
# REDIS_PASSWORD=null
# REDIS_PORT=6379
```

### Database Queue Tables

Required migrations (already applied):
- `jobs` - Pending jobs
- `failed_jobs` - Failed jobs for retry
- `job_batches` - Batch job tracking

## Supervisor Configuration (Linux Production)

### Installation

```bash
# Ubuntu/Debian
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor
```

### Configuration File

Create `/etc/supervisor/conf.d/absensi-qr-worker.conf`:

```ini
[program:absensi-qr-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/absensi-qr/backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --timeout=60
autostart=true
autorestart=true
stopasflimit=TERM
stopwaitsecs=3600
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/absensi-qr/backend/storage/logs/worker.log
stopasgroup=true
killasgroup=true
startsecs=10
```

### Configuration Explained

| Parameter | Value | Reason |
|-----------|-------|--------|
| `--sleep=3` | 3 seconds | Pause between job polls (reduce CPU) |
| `--tries=3` | 3 attempts | Retry failed jobs up to 3 times |
| `--max-time=3600` | 1 hour | Restart worker hourly (prevent memory leaks) |
| `--timeout=60` | 60 seconds | Job execution timeout |
| `numprocs=2` | 2 workers | Parallel processing (adjust based on load) |
| `stopwaitsecs=3600` | 1 hour | Grace period before force kill |

### Supervisor Commands

```bash
# Reload configuration
sudo supervisorctl reread
sudo supervisorctl update

# Start workers
sudo supervisorctl start absensi-qr-worker:*

# Check status
sudo supervisorctl status absensi-qr-worker:*

# View logs
sudo supervisorctl tail -f absensi-qr-worker:absensi-qr-worker_00 stdout

# Restart workers (after deployment)
sudo supervisorctl restart absensi-qr-worker:*

# Stop workers
sudo supervisorctl stop absensi-qr-worker:*
```

## Windows Development Setup

### Manual Worker

```powershell
# Run in dedicated terminal
php artisan queue:work --tries=3 --timeout=60

# With verbose output for debugging
php artisan queue:work --tries=3 --timeout=60 --verbose
```

### Restart After Code Changes

Queue workers cache application code. After changes:

```bash
# Restart worker to load new code
php artisan queue:restart
```

## Monitoring & Health Checks

### Health Endpoint

Monitor queue system health:

```bash
curl http://your-domain.com/api/v1/system/queue-status
```

**Response Structure:**

```json
{
  "status": "healthy",
  "timestamp": "2026-01-28T10:30:00+00:00",
  "metrics": {
    "pending_jobs": 5,
    "failed_jobs_24h": 0,
    "total_failed_jobs": 2,
    "queue_lag_seconds": 3
  },
  "alerts": [
    {
      "severity": "info",
      "message": "Queue system is healthy"
    }
  ]
}
```

### Alert Thresholds

| Metric | Warning | Critical | Action |
|--------|---------|----------|--------|
| `failed_jobs_24h` | >0 | >10 | Check logs, retry failed jobs |
| `queue_lag_seconds` | >300 | >900 | Scale workers, check for stuck jobs |
| `pending_jobs` | >1000 | >5000 | Increase worker count |

### Monitoring Integration

**Nagios/Zabbix:**
```bash
# Check health endpoint (exit 2 if degraded)
#!/bin/bash
RESPONSE=$(curl -s http://localhost:8000/api/v1/system/queue-status)
STATUS=$(echo $RESPONSE | jq -r '.status')

if [ "$STATUS" = "healthy" ]; then
  echo "OK - Queue system healthy"
  exit 0
else
  echo "CRITICAL - Queue system degraded"
  exit 2
fi
```

**Prometheus Metrics:**
```php
// Add to routes/api.php for Prometheus scraping
Route::get('/metrics/queue', function() {
    $pending = DB::table('jobs')->count();
    $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
    
    return response("# TYPE queue_pending_jobs gauge\nqueue_pending_jobs $pending\n# TYPE queue_failed_jobs_24h gauge\nqueue_failed_jobs_24h $failed\n")
        ->header('Content-Type', 'text/plain');
});
```

## Failed Job Management

### View Failed Jobs

```bash
# List all failed jobs
php artisan queue:failed

# Output example:
# ID  | Connection | Queue   | Class                | Failed At
# 1   | database   | default | App\Jobs\SendEmail   | 2026-01-28 10:00:00
```

### Retry Failed Jobs

```bash
# Retry specific job
php artisan queue:retry 1

# Retry all failed jobs
php artisan queue:retry all

# Retry jobs failed in last hour
php artisan queue:retry --range=1-100
```

### Delete Failed Jobs

```bash
# Delete specific job
php artisan queue:forget 1

# Flush all failed jobs
php artisan queue:flush
```

## Queue Failure Logging

All queue failures are automatically logged to `storage/logs/laravel.log` with:

```php
// Implemented in AppServiceProvider::boot()
Queue::failing(function (JobFailed $event) {
    Log::channel('security')->error('Queue Job Failed', [
        'connection' => $event->connectionName,
        'queue' => $event->job->getQueue(),
        'job_name' => $event->job->resolveName(),
        'exception_message' => $event->exception->getMessage(),
        'failed_at' => now()->toIso8601String(),
    ]);
});
```

## Deployment Checklist

Before deploying to production:

1. ✅ Verify `.env` has `QUEUE_CONNECTION=database` or `redis`
2. ✅ Run migrations: `php artisan migrate` (includes failed_jobs table)
3. ✅ Install Supervisor: `sudo apt-get install supervisor`
4. ✅ Create Supervisor config: `/etc/supervisor/conf.d/absensi-qr-worker.conf`
5. ✅ Start workers: `sudo supervisorctl start absensi-qr-worker:*`
6. ✅ Test queue: Trigger attendance scan, check worker logs
7. ✅ Setup monitoring: Add `/system/queue-status` to uptime checks
8. ✅ Configure alerts: Email/Slack when `failed_jobs_24h > 10`

## Troubleshooting

### Workers Not Processing Jobs

```bash
# 1. Check if workers are running
sudo supervisorctl status absensi-qr-worker:*

# 2. Check worker logs
tail -f /var/www/absensi-qr/backend/storage/logs/worker.log

# 3. Check pending jobs
php artisan queue:work --once --verbose

# 4. Restart workers
sudo supervisorctl restart absensi-qr-worker:*
```

### High Failed Job Rate

```bash
# 1. Check exception details
SELECT exception FROM failed_jobs ORDER BY failed_at DESC LIMIT 5;

# 2. Common causes:
# - Database connection timeout (increase timeout)
# - Memory limit (increase PHP memory_limit)
# - Third-party API down (implement retry with backoff)

# 3. Retry after fixing
php artisan queue:retry all
```

### Queue Lag Increasing

```bash
# 1. Check pending jobs count
SELECT COUNT(*) FROM jobs;

# 2. Increase worker count in supervisor config:
# numprocs=4  # Increase from 2 to 4

# 3. Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
```

## Security Considerations

1. **Never queue sensitive operations**: Attendance writes are synchronous
2. **Failed jobs contain sensitive data**: Restrict `failed_jobs` table access
3. **Worker user permissions**: Run as `www-data` with minimal permissions
4. **Log rotation**: Configure logrotate for `worker.log`

## Performance Tuning

### Optimize Queue Performance

```php
// config/queue.php - database connection
'database' => [
    'driver' => 'database',
    'table' => 'jobs',
    'queue' => 'default',
    'retry_after' => 90,  // Match worker timeout + buffer
],
```

### Redis for High Traffic

```env
# .env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_QUEUE=default
```

```ini
# supervisor config for redis
command=php /var/www/absensi-qr/backend/artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
```

## Support

For production issues:
- Check `/system/queue-status` endpoint
- Review `storage/logs/worker.log`
- Inspect `failed_jobs` table
- Contact DevOps team for Supervisor issues
