# 🏥 System Health Monitoring - Quick Reference

## 🚀 Quick Start (30 seconds)

```bash
# 1. Login as admin
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}' \
  | jq -r '.data.token'

# 2. Check system health (replace TOKEN)
curl -X GET http://localhost:8000/api/v1/admin/system/health \
  -H "Authorization: Bearer TOKEN" | jq
```

---

## 📍 Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/admin/system/health` | Overall system health |
| `GET` | `/api/v1/admin/system/health/queue` | Queue metrics |
| `GET` | `/api/v1/admin/system/health/security` | Security metrics |

**Auth:** Sanctum token required  
**Roles:** `admin`, `school_admin`, `super_admin`  
**Ability:** `system:monitor` or `*`

---

## 📊 Response Format

```json
{
  "status": "success",
  "data": {
    "metrics": {
      "queue_failed_last_24h": 0,
      "queue_pending": 0,
      "rate_limit_blocks_last_hour": 0,
      "qr_anomalies_last_24h": 0
    },
    "health_status": "healthy",  // healthy | degraded | critical
    "alerts": [
      {
        "severity": "info",      // info | warning | critical
        "category": "system",    // system | queue | security
        "message": "All systems operational",
        "action": null
      }
    ],
    "timestamp": "2026-01-28T22:45:00+08:00"
  }
}
```

---

## 🎯 Metrics Thresholds

### Queue
- **Healthy:** Failed < 10, Pending < 500
- **Degraded:** Failed 10-49, Pending 500-999
- **Critical:** Failed ≥ 50, Pending ≥ 1000

### Security
- **Healthy:** Rate blocks < 20, QR anomalies < 10
- **Degraded:** Rate blocks 20-99, QR anomalies 10-49
- **Critical:** Rate blocks ≥ 100, QR anomalies ≥ 50

---

## 🔧 Grant Permission

```bash
php artisan tinker

# To user
User::find(1)->givePermissionTo('system:monitor');

# To role
Role::findByName('admin')->givePermissionTo('system:monitor');

exit
```

---

## 🧪 Test Locally

```bash
# Run tests
php artisan test --filter=SystemHealthMonitoringTest

# Check routes
php artisan route:list --path=admin/system
```

---

## 📁 Files Location

- **Controller:** `app/Http/Controllers/Api/V1/Admin/SystemHealthController.php`
- **Routes:** `routes/api.php` (line ~217)
- **Tests:** `tests/Feature/Admin/SystemHealthMonitoringTest.php`
- **Docs:** `docs/api/SYSTEM_HEALTH_MONITORING.md`

---

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| 401 Unauthorized | Check token validity |
| 403 Forbidden | Grant `system:monitor` permission |
| All metrics = 0 | Normal if no events yet |
| Slow response | Enable Redis caching |

---

## 💡 Integration Example

```javascript
// Frontend polling
setInterval(async () => {
  const res = await fetch('/api/v1/admin/system/health', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  const { data } = await res.json();
  
  updateDashboard(data.health_status, data.metrics);
  
  if (data.health_status === 'critical') {
    showAlert(data.alerts);
  }
}, 30000); // Every 30 seconds
```

---

**📚 Full Documentation:** `docs/api/SYSTEM_HEALTH_MONITORING.md`  
**🧪 Testing Guide:** `docs/testing/SYSTEM_HEALTH_TESTING.md`  
**📋 Summary:** `docs/implementation/SYSTEM_HEALTH_SUMMARY.md`
