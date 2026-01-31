# System Health Monitoring Feature

## 🎯 Overview

Admin-only endpoints for operational visibility into queue and security events. Monitor system health in real-time with comprehensive metrics and alerts.

**Added:** January 28, 2026  
**Status:** ✅ Production Ready

---

## ✨ Features

- ✅ **Queue Monitoring** - Track failed and pending jobs
- ✅ **Security Monitoring** - Monitor rate limits and anomalies
- ✅ **QR Security** - Detect QR code security anomalies
- ✅ **Health Status** - Overall system health (healthy/degraded/critical)
- ✅ **Smart Alerts** - Actionable alerts with severity levels
- ✅ **Caching** - Optimized with Redis/database caching
- ✅ **Authorization** - Admin-only access with ability checks

---

## 🚀 Quick Start

### 1. Grant Permission

```bash
php artisan tinker

$admin = User::where('username', 'admin')->first();
$admin->givePermissionTo('system:monitor');

exit
```

### 2. Test Endpoint

```bash
# Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# Get system health (replace TOKEN)
curl -X GET http://localhost:8000/api/v1/admin/system/health \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

---

## 📍 API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/admin/system/health` | Overall system health with all metrics |
| `GET` | `/api/v1/admin/system/health/queue` | Detailed queue health metrics |
| `GET` | `/api/v1/admin/system/health/security` | Detailed security metrics |

**Authentication:** Sanctum token required  
**Authorization:** `system:monitor` ability or `*` wildcard  
**Roles:** `admin`, `school_admin`, `super_admin`

---

## 📊 Metrics Tracked

### Queue Metrics
- `queue_failed_last_24h` - Failed jobs in last 24 hours
- `queue_pending` - Jobs waiting to be processed
- `queue_lag_seconds` - Age of oldest pending job
- `failed_jobs_by_queue` - Failed jobs grouped by queue name

### Security Metrics
- `rate_limit_blocks_last_hour` - Rate limit violations (1h)
- `rate_limit_blocks_last_24h` - Rate limit violations (24h)
- `qr_anomalies_last_24h` - QR security anomalies (24h)
- `failed_login_attempts_1h` - Failed login attempts (1h)
- `suspicious_activities_24h` - Suspicious activities (24h)

---

## 🚨 Alert Thresholds

### Queue Alerts
- **Critical:** Failed jobs ≥ 50 (24h), Pending jobs ≥ 1000
- **Warning:** Failed jobs ≥ 10 (24h), Pending jobs ≥ 500

### Security Alerts
- **Critical:** Rate blocks ≥ 100 (1h), QR anomalies ≥ 50 (24h)
- **Warning:** Rate blocks ≥ 20 (1h), QR anomalies ≥ 10 (24h)

---

## 📚 Documentation

- **📖 API Documentation:** [`docs/api/SYSTEM_HEALTH_MONITORING.md`](./docs/api/SYSTEM_HEALTH_MONITORING.md)
- **🧪 Testing Guide:** [`docs/testing/SYSTEM_HEALTH_TESTING.md`](./docs/testing/SYSTEM_HEALTH_TESTING.md)
- **📋 Implementation Summary:** [`docs/implementation/SYSTEM_HEALTH_SUMMARY.md`](./docs/implementation/SYSTEM_HEALTH_SUMMARY.md)
- **⚡ Quick Reference:** [`docs/SYSTEM_HEALTH_QUICK_REF.md`](./docs/SYSTEM_HEALTH_QUICK_REF.md)

---

## 🧪 Testing

### Run Automated Tests

```bash
php artisan test --filter=SystemHealthMonitoringTest
```

**Test Coverage:** 18 test cases covering:
- Authentication & Authorization
- Metrics calculation
- Health status determination
- Alert generation
- Edge cases & error handling

---

## 💻 Frontend Integration Example

```javascript
// Poll system health every 30 seconds
async function monitorSystemHealth() {
  const response = await fetch('/api/v1/admin/system/health', {
    headers: {
      'Authorization': `Bearer ${adminToken}`,
      'Accept': 'application/json'
    }
  });
  
  const { data } = await response.json();
  
  // Update dashboard UI
  updateHealthBadge(data.health_status);
  updateMetricsCards(data.metrics);
  
  // Show critical alerts
  data.alerts
    .filter(alert => alert.severity === 'critical')
    .forEach(alert => {
      showNotification({
        title: 'System Alert',
        message: alert.message,
        action: alert.action,
        type: 'error'
      });
    });
}

setInterval(monitorSystemHealth, 30000);
```

---

## 🔧 Configuration

### Enable Redis Caching (Recommended)

```env
# .env
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Cache Durations

| Metric | Cache Duration |
|--------|----------------|
| Failed jobs (24h) | 60 seconds |
| Pending jobs | 30 seconds |
| Rate limit blocks (1h) | 60 seconds |
| Rate limit blocks (24h) | 300 seconds |
| QR anomalies (24h) | 300 seconds |

---

## 🎨 Response Example

```json
{
  "status": "success",
  "data": {
    "metrics": {
      "queue_failed_last_24h": 3,
      "queue_pending": 12,
      "rate_limit_blocks_last_hour": 5,
      "qr_anomalies_last_24h": 2
    },
    "health_status": "healthy",
    "alerts": [
      {
        "severity": "info",
        "category": "system",
        "message": "All systems operational",
        "action": null
      }
    ],
    "timestamp": "2026-01-28T22:45:00+08:00"
  }
}
```

---

## 🔌 Integration Opportunities

### Monitoring Tools
- Grafana dashboards
- Datadog integration
- New Relic monitoring
- Prometheus metrics export

### Alerting Systems
- Slack notifications
- Email alerts
- SMS alerts (Twilio)
- PagerDuty integration

### Example: Prometheus Metrics

```php
// Add to routes/api.php
Route::get('/metrics', function () {
    $health = app(\App\Http\Controllers\Api\V1\Admin\SystemHealthController::class)->health();
    $metrics = $health->getData()->data->metrics;
    
    return response("
queue_failed_jobs_24h {$metrics->queue_failed_last_24h}
queue_pending_jobs {$metrics->queue_pending}
rate_limit_blocks_1h {$metrics->rate_limit_blocks_last_hour}
qr_anomalies_24h {$metrics->qr_anomalies_last_24h}
    ")->header('Content-Type', 'text/plain');
});
```

---

## 🐛 Troubleshooting

### All metrics return 0
**Normal behavior** if no events have occurred yet. To test:

```bash
php artisan tinker

# Create a failed job
DB::table('failed_jobs')->insert([
    'uuid' => \Illuminate\Support\Str::uuid(),
    'connection' => 'database',
    'queue' => 'default',
    'payload' => json_encode(['test' => 'data']),
    'exception' => 'Test exception',
    'failed_at' => now(),
]);

exit
```

### 403 Forbidden
User doesn't have `system:monitor` permission. Grant it:

```bash
php artisan tinker
User::find(1)->givePermissionTo('system:monitor');
exit
```

### Slow response times
Enable Redis caching for better performance:

```bash
# Install Redis
# Update .env
CACHE_STORE=redis

# Clear config cache
php artisan config:cache
```

---

## 📁 File Structure

```
backend/
├── app/Http/Controllers/Api/V1/Admin/
│   └── SystemHealthController.php          # Main controller
├── routes/
│   └── api.php                              # Routes (line ~217)
└── tests/Feature/Admin/
    └── SystemHealthMonitoringTest.php       # 18 test cases

docs/
├── api/
│   └── SYSTEM_HEALTH_MONITORING.md          # API documentation
├── testing/
│   └── SYSTEM_HEALTH_TESTING.md             # Testing guide
├── implementation/
│   └── SYSTEM_HEALTH_SUMMARY.md             # Implementation summary
└── SYSTEM_HEALTH_QUICK_REF.md               # Quick reference
```

---

## ✅ Checklist

- [x] Controller implemented with 3 endpoints
- [x] Routes registered and protected
- [x] Authorization middleware applied
- [x] Metrics calculation logic
- [x] Health status determination
- [x] Alert generation system
- [x] Caching implemented
- [x] Error handling
- [x] 18 automated tests
- [x] Comprehensive documentation
- [x] Testing guide
- [x] Integration examples

---

## 🎯 Next Steps

1. **Test the endpoints** using the testing guide
2. **Integrate with frontend** admin dashboard
3. **Set up monitoring** alerts (Slack/Email)
4. **Configure Redis** for production caching
5. **Monitor metrics** and adjust thresholds as needed

---

## 📞 Support

For issues or questions:
1. Check the [API Documentation](./docs/api/SYSTEM_HEALTH_MONITORING.md)
2. Review the [Testing Guide](./docs/testing/SYSTEM_HEALTH_TESTING.md)
3. See [Troubleshooting](#-troubleshooting) section above

---

**Version:** 1.0.0  
**Last Updated:** January 28, 2026  
**Status:** ✅ Production Ready
