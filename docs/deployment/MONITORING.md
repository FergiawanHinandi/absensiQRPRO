# Monitoring Guide - Security Implementation

**Last Updated:** January 28, 2026  
**Version:** 1.0.0  
**For:** DevOps & System Administrators

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Security Monitoring](#security-monitoring)
3. [Performance Monitoring](#performance-monitoring)
4. [Application Monitoring](#application-monitoring)
5. [Alerting](#alerting)
6. [Dashboards](#dashboards)

---

## 🎯 Overview

### What to Monitor

```
Security Metrics:
├── Rate limit violations
├── Failed login attempts
├── QR security anomalies
├── Cross-school access attempts
└── Token refresh failures

Performance Metrics:
├── API response times
├── QR scan performance
├── Database query times
├── Cache hit rates
└── Queue processing times

Application Metrics:
├── Error rates
├── User activity
├── Attendance records
└── System health
```

### Monitoring Stack

**Recommended:**
- **Logs:** Laravel Log + Logrotate
- **Metrics:** Prometheus + Grafana
- **APM:** New Relic / DataDog (optional)
- **Uptime:** UptimeRobot / Pingdom

**Minimal:**
- Laravel Log files
- Basic shell scripts
- Email alerts

---

## 🔒 Security Monitoring

### 1. Rate Limit Violations

#### Check Violations

```bash
# All rate limit violations
grep "Rate Limit Exceeded" storage/logs/laravel.log

# Last 24 hours
grep "Rate Limit Exceeded" storage/logs/laravel.log | grep "$(date +%Y-%m-%d)"

# Count by type
grep "Rate Limit Exceeded" storage/logs/laravel.log | grep -o '"type":"[^"]*"' | sort | uniq -c
```

#### Expected Output

```json
{
  "message": "Rate Limit Exceeded",
  "context": {
    "type": "login",
    "key": "login:192.168.1.100",
    "ip": "192.168.1.100",
    "user_id": null,
    "user_agent": "Mozilla/5.0...",
    "endpoint": "api/v1/auth/login",
    "method": "POST",
    "timestamp": "2026-01-28T12:00:00+08:00"
  }
}
```

#### Alert Thresholds

| Type | Normal | Warning | Critical |
|------|--------|---------|----------|
| Global | <10/hour | 10-50/hour | >50/hour |
| Login | <5/hour | 5-20/hour | >20/hour |
| Scan | <5/hour | 5-15/hour | >15/hour |
| API | <20/hour | 20-100/hour | >100/hour |

#### Monitoring Script

```bash
#!/bin/bash
# File: /usr/local/bin/check-rate-limits.sh

LOG_FILE="/var/www/absensi/backend/storage/logs/laravel.log"
ALERT_EMAIL="admin@school.com"

# Count violations in last hour
VIOLATIONS=$(grep "Rate Limit Exceeded" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$VIOLATIONS" -gt 50 ]; then
  echo "CRITICAL: $VIOLATIONS rate limit violations in last hour" | \
    mail -s "Rate Limit Alert" "$ALERT_EMAIL"
elif [ "$VIOLATIONS" -gt 10 ]; then
  echo "WARNING: $VIOLATIONS rate limit violations in last hour" | \
    mail -s "Rate Limit Warning" "$ALERT_EMAIL"
fi
```

```bash
# Add to crontab
crontab -e

# Run every hour
0 * * * * /usr/local/bin/check-rate-limits.sh
```

---

### 2. Failed Login Attempts

#### Check Failed Logins

```bash
# All failed logins
grep "Login failed" storage/logs/laravel.log

# By IP address
grep "Login failed" storage/logs/laravel.log | grep -o '"ip":"[^"]*"' | sort | uniq -c

# Last hour
grep "Login failed" storage/logs/laravel.log | grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')"
```

#### Alert Thresholds

- **Normal:** <10 failed logins/hour
- **Warning:** 10-50 failed logins/hour
- **Critical:** >50 failed logins/hour (possible brute force attack)

#### Monitoring Script

```bash
#!/bin/bash
# File: /usr/local/bin/check-failed-logins.sh

LOG_FILE="/var/www/absensi/backend/storage/logs/laravel.log"
ALERT_EMAIL="admin@school.com"

# Count failed logins in last hour
FAILED=$(grep "Login failed" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$FAILED" -gt 50 ]; then
  echo "CRITICAL: $FAILED failed login attempts in last hour" | \
    mail -s "Security Alert: Possible Brute Force Attack" "$ALERT_EMAIL"
fi
```

---

### 3. QR Security Anomalies

#### Check Anomalies

```bash
# All QR security anomalies
grep "QR Security Anomaly" storage/logs/laravel.log

# By type
grep "QR Security Anomaly" storage/logs/laravel.log | grep -o '"reason":"[^"]*"' | sort | uniq -c

# Common anomalies:
# - inactive_student_scan
# - school_mismatch
# - device_id_mismatch
# - cross_school_scan_attempt
```

#### Alert Thresholds

- **Normal:** <5 anomalies/hour
- **Warning:** 5-20 anomalies/hour
- **Critical:** >20 anomalies/hour

#### Monitoring Script

```bash
#!/bin/bash
# File: /usr/local/bin/check-qr-anomalies.sh

LOG_FILE="/var/www/absensi/backend/storage/logs/laravel.log"
ALERT_EMAIL="admin@school.com"

# Count anomalies in last hour
ANOMALIES=$(grep "QR Security Anomaly" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$ANOMALIES" -gt 20 ]; then
  echo "CRITICAL: $ANOMALIES QR security anomalies in last hour" | \
    mail -s "QR Security Alert" "$ALERT_EMAIL"
fi
```

---

### 4. Cross-School Access Attempts

#### Check Attempts

```bash
# Policy violations
grep "Unauthorized" storage/logs/laravel.log | grep "cross.*school"

# 403 responses
grep "403" storage/logs/laravel.log
```

#### Alert Thresholds

- **Normal:** 0 attempts
- **Warning:** 1-5 attempts/day
- **Critical:** >5 attempts/day (possible attack)

---

### 5. Token Refresh Failures

#### Check Failures

```bash
# Token refresh failures
grep "Token refresh failed" storage/logs/laravel.log

# By user
grep "Token refresh failed" storage/logs/laravel.log | grep -o '"user_id":[0-9]*' | sort | uniq -c
```

#### Alert Thresholds

- **Normal:** <10 failures/hour
- **Warning:** 10-50 failures/hour
- **Critical:** >50 failures/hour

---

## ⚡ Performance Monitoring

### 1. API Response Times

#### Check Response Times

```bash
# Average response time for attendance
grep "Attendance recorded" storage/logs/laravel.log | \
  grep -o '"duration":[0-9.]*' | \
  awk -F: '{sum+=$2; count++} END {print "Average:", sum/count, "ms"}'

# Expected: ~75ms for QR scan
```

#### Alert Thresholds

| Endpoint | Normal | Warning | Critical |
|----------|--------|---------|----------|
| QR Scan | <100ms | 100-200ms | >200ms |
| API Calls | <50ms | 50-100ms | >100ms |
| Reports | <500ms | 500-1000ms | >1000ms |

#### Monitoring with Nginx

```nginx
# Add to nginx.conf
log_format timing '$remote_addr - $remote_user [$time_local] '
                  '"$request" $status $body_bytes_sent '
                  '"$http_referer" "$http_user_agent" '
                  'rt=$request_time uct="$upstream_connect_time" '
                  'uht="$upstream_header_time" urt="$upstream_response_time"';

access_log /var/log/nginx/absensi-timing.log timing;
```

```bash
# Analyze slow requests
awk '$NF > 0.5' /var/log/nginx/absensi-timing.log
```

---

### 2. Database Performance

#### Check Slow Queries

```bash
# Enable slow query log in MySQL
# Add to my.cnf:
# slow_query_log = 1
# slow_query_log_file = /var/log/mysql/slow.log
# long_query_time = 0.5

# Check slow queries
tail -f /var/log/mysql/slow.log
```

#### Monitor Query Count

```bash
php artisan tinker

# Enable query log
DB::enableQueryLog();

# Run operation
User::where('role_type', 'student')->get();

# Check queries
dd(DB::getQueryLog());
```

#### Alert Thresholds

- **Normal:** <10 slow queries/hour
- **Warning:** 10-50 slow queries/hour
- **Critical:** >50 slow queries/hour

---

### 3. Cache Performance

#### Check Cache Hit Rate

```bash
php artisan tinker

# Get cache stats (if using Redis)
Redis::info('stats');

# Expected: >80% hit rate
```

#### Monitor Cache Size

```bash
# Redis memory usage
redis-cli info memory

# Check specific keys
redis-cli --scan --pattern "laravel:*" | wc -l
```

---

### 4. Queue Performance

#### Check Queue Status

```bash
# Queue size
php artisan queue:monitor

# Failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

#### Alert Thresholds

- **Normal:** <100 jobs in queue
- **Warning:** 100-1000 jobs in queue
- **Critical:** >1000 jobs in queue

---

## 📊 Application Monitoring

### 1. Error Rates

#### Check Errors

```bash
# All errors
grep "ERROR" storage/logs/laravel.log

# Last 24 hours
grep "ERROR" storage/logs/laravel.log | grep "$(date +%Y-%m-%d)"

# Count by type
grep "ERROR" storage/logs/laravel.log | grep -o 'Exception: [^"]*' | sort | uniq -c
```

#### Alert Thresholds

- **Normal:** <10 errors/hour
- **Warning:** 10-50 errors/hour
- **Critical:** >50 errors/hour

---

### 2. User Activity

#### Check Active Users

```bash
php artisan tinker

# Active users (logged in last 24 hours)
User::where('last_login_at', '>', now()->subDay())->count();

# Attendance records today
Attendance::whereDate('created_at', today())->count();
```

#### Monitoring Script

```bash
#!/bin/bash
# File: /usr/local/bin/check-activity.sh

php artisan tinker <<EOF
echo "Active Users: " . User::where('last_login_at', '>', now()->subDay())->count();
echo "Attendance Today: " . Attendance::whereDate('created_at', today())->count();
EOF
```

---

### 3. System Health

#### Health Check Endpoint

```bash
# Check health
curl https://api.school.com/api/v1/health

# Expected: {"status":"ok","timestamp":"..."}
```

#### Comprehensive Health Check

```php
// routes/api.php
Route::get('/health', function () {
    $checks = [
        'database' => checkDatabase(),
        'cache' => checkCache(),
        'queue' => checkQueue(),
        'storage' => checkStorage(),
    ];
    
    $healthy = !in_array(false, $checks);
    
    return response()->json([
        'status' => $healthy ? 'ok' : 'error',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
});

function checkDatabase() {
    try {
        DB::connection()->getPdo();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

function checkCache() {
    try {
        Cache::put('health_check', true, 10);
        return Cache::get('health_check') === true;
    } catch (\Exception $e) {
        return false;
    }
}

function checkQueue() {
    try {
        return Queue::size() < 1000;
    } catch (\Exception $e) {
        return false;
    }
}

function checkStorage() {
    return is_writable(storage_path());
}
```

---

## 🚨 Alerting

### 1. Email Alerts

#### Configure Mail

```env
# .env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=alerts@school.com
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=alerts@school.com
MAIL_FROM_NAME="AbsensiQR Alerts"
```

#### Alert Script

```bash
#!/bin/bash
# File: /usr/local/bin/send-alert.sh

ALERT_EMAIL="admin@school.com"
SUBJECT="$1"
MESSAGE="$2"

echo "$MESSAGE" | mail -s "$SUBJECT" "$ALERT_EMAIL"
```

---

### 2. Slack Alerts (Optional)

```bash
#!/bin/bash
# File: /usr/local/bin/slack-alert.sh

WEBHOOK_URL="https://hooks.slack.com/services/YOUR/WEBHOOK/URL"
MESSAGE="$1"

curl -X POST -H 'Content-type: application/json' \
  --data "{\"text\":\"$MESSAGE\"}" \
  "$WEBHOOK_URL"
```

---

### 3. Alert Rules

```bash
#!/bin/bash
# File: /usr/local/bin/monitor-all.sh

LOG_FILE="/var/www/absensi/backend/storage/logs/laravel.log"

# Rate limit violations
RATE_LIMIT=$(grep "Rate Limit Exceeded" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$RATE_LIMIT" -gt 50 ]; then
  /usr/local/bin/send-alert.sh "CRITICAL: Rate Limit" \
    "$RATE_LIMIT violations in last hour"
fi

# Failed logins
FAILED_LOGINS=$(grep "Login failed" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$FAILED_LOGINS" -gt 50 ]; then
  /usr/local/bin/send-alert.sh "CRITICAL: Failed Logins" \
    "$FAILED_LOGINS attempts in last hour - Possible brute force attack"
fi

# QR anomalies
QR_ANOMALIES=$(grep "QR Security Anomaly" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$QR_ANOMALIES" -gt 20 ]; then
  /usr/local/bin/send-alert.sh "CRITICAL: QR Anomalies" \
    "$QR_ANOMALIES anomalies in last hour"
fi

# Errors
ERRORS=$(grep "ERROR" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)

if [ "$ERRORS" -gt 50 ]; then
  /usr/local/bin/send-alert.sh "CRITICAL: Errors" \
    "$ERRORS errors in last hour"
fi
```

```bash
# Add to crontab
crontab -e

# Run every hour
0 * * * * /usr/local/bin/monitor-all.sh
```

---

## 📈 Dashboards

### 1. Simple Dashboard (Shell Script)

```bash
#!/bin/bash
# File: /usr/local/bin/dashboard.sh

clear
echo "========================================="
echo "  AbsensiQR Security Dashboard"
echo "========================================="
echo ""

LOG_FILE="/var/www/absensi/backend/storage/logs/laravel.log"

# Rate Limit Violations (last hour)
RATE_LIMIT=$(grep "Rate Limit Exceeded" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)
echo "Rate Limit Violations (1h): $RATE_LIMIT"

# Failed Logins (last hour)
FAILED=$(grep "Login failed" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)
echo "Failed Logins (1h): $FAILED"

# QR Anomalies (last hour)
ANOMALIES=$(grep "QR Security Anomaly" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)
echo "QR Anomalies (1h): $ANOMALIES"

# Errors (last hour)
ERRORS=$(grep "ERROR" "$LOG_FILE" | \
  grep "$(date -d '1 hour ago' '+%Y-%m-%d %H')" | wc -l)
echo "Errors (1h): $ERRORS"

echo ""
echo "========================================="

# Active users
php artisan tinker <<EOF
echo "Active Users (24h): " . User::where('last_login_at', '>', now()->subDay())->count();
echo "Attendance Today: " . Attendance::whereDate('created_at', today())->count();
EOF

echo "========================================="
```

```bash
# Run dashboard
/usr/local/bin/dashboard.sh

# Or add to watch for live updates
watch -n 60 /usr/local/bin/dashboard.sh
```

---

### 2. Grafana Dashboard (Advanced)

#### Install Prometheus + Grafana

```bash
# Install Prometheus
wget https://github.com/prometheus/prometheus/releases/download/v2.40.0/prometheus-2.40.0.linux-amd64.tar.gz
tar xvfz prometheus-*.tar.gz
cd prometheus-*

# Configure prometheus.yml
# Add Laravel metrics endpoint

# Start Prometheus
./prometheus --config.file=prometheus.yml
```

#### Laravel Metrics Endpoint

```php
// routes/api.php
Route::get('/metrics', function () {
    $metrics = [
        'rate_limit_violations' => getRateLimitViolations(),
        'failed_logins' => getFailedLogins(),
        'qr_anomalies' => getQRAnomalies(),
        'active_users' => getActiveUsers(),
        'attendance_today' => getAttendanceToday(),
    ];
    
    return response($metrics)->header('Content-Type', 'text/plain');
});
```

---

## ✅ Monitoring Checklist

### Daily Checks

- [ ] Check error logs
- [ ] Review rate limit violations
- [ ] Check failed login attempts
- [ ] Review QR security anomalies
- [ ] Verify system health
- [ ] Check performance metrics

### Weekly Checks

- [ ] Review security trends
- [ ] Analyze performance trends
- [ ] Check disk space
- [ ] Review database size
- [ ] Check backup status
- [ ] Update alert thresholds

### Monthly Checks

- [ ] Security audit
- [ ] Performance optimization
- [ ] Log rotation
- [ ] Capacity planning
- [ ] Update monitoring scripts

---

## 🎉 Summary

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│  ✅ MONITORING CONFIGURED                           │
│                                                      │
│  Security Monitoring: Active ✅                     │
│  Performance Monitoring: Active ✅                  │
│  Application Monitoring: Active ✅                  │
│  Alerting: Configured ✅                            │
│  Dashboards: Available ✅                           │
│                                                      │
│  YOUR SYSTEM IS NOW MONITORED! 📊                  │
│                                                      │
└──────────────────────────────────────────────────────┘
```

---

**Last Updated:** January 28, 2026  
**Maintained by:** DevOps Team  
**For Support:** See main documentation

**Happy Monitoring! 📊**
