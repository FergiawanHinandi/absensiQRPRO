# System Health Monitoring API

## Overview

Provides operational visibility for queue and security events. Admin-only endpoints for monitoring system health.

**Base URL:** `/api/v1/admin/system`

**Authentication:** Required (Sanctum)

**Authorization:** `ability:system:monitor` or wildcard `*`

**Roles Allowed:** `admin`, `school_admin`, `super_admin`

---

## Endpoints

### 1. Get System Health

**GET** `/api/v1/admin/system/health`

Returns comprehensive system health metrics including queue status, security events, and QR anomalies.

#### Request

```bash
curl -X GET "http://localhost:8000/api/v1/admin/system/health" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

#### Response (200 OK)

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

#### Health Status Values

- `healthy` - All metrics within normal thresholds
- `degraded` - Some metrics elevated but not critical
- `critical` - One or more metrics exceed critical thresholds

#### Alert Severity Levels

- `info` - Informational message
- `warning` - Elevated metrics, monitor closely
- `critical` - Immediate action required

---

### 2. Get Queue Health Details

**GET** `/api/v1/admin/system/health/queue`

Returns detailed queue health metrics.

#### Request

```bash
curl -X GET "http://localhost:8000/api/v1/admin/system/health/queue" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

#### Response (200 OK)

```json
{
  "status": "success",
  "data": {
    "pending_jobs": 12,
    "failed_jobs_24h": 3,
    "failed_jobs_total": 15,
    "queue_lag_seconds": 45,
    "failed_jobs_by_queue": {
      "default": 2,
      "emails": 1
    }
  }
}
```

#### Metrics Explanation

| Metric | Description | Healthy Threshold |
|--------|-------------|-------------------|
| `pending_jobs` | Jobs waiting to be processed | < 500 |
| `failed_jobs_24h` | Failed jobs in last 24 hours | < 10 |
| `failed_jobs_total` | Total failed jobs (all time) | N/A |
| `queue_lag_seconds` | Age of oldest pending job | < 300 (5 min) |
| `failed_jobs_by_queue` | Failed jobs grouped by queue | N/A |

---

### 3. Get Security Health Details

**GET** `/api/v1/admin/system/health/security`

Returns detailed security metrics including rate limiting and anomalies.

#### Request

```bash
curl -X GET "http://localhost:8000/api/v1/admin/system/health/security" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

#### Response (200 OK)

```json
{
  "status": "success",
  "data": {
    "rate_limit_blocks_1h": 5,
    "rate_limit_blocks_24h": 23,
    "qr_anomalies_24h": 2,
    "failed_login_attempts_1h": 3,
    "suspicious_activities_24h": 1
  }
}
```

#### Security Metrics Explanation

| Metric | Description | Healthy Threshold |
|--------|-------------|-------------------|
| `rate_limit_blocks_1h` | Rate limit violations in last hour | < 20 |
| `rate_limit_blocks_24h` | Rate limit violations in last 24h | < 100 |
| `qr_anomalies_24h` | QR security anomalies detected | < 10 |
| `failed_login_attempts_1h` | Failed login attempts | < 50 |
| `suspicious_activities_24h` | Suspicious activities detected | < 20 |

#### QR Anomalies Include

- Invalid HMAC signatures
- Expired QR codes
- Cross-school QR scan attempts
- Inactive student scans
- Duplicate scan attempts

---

## Error Responses

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden

```json
{
  "status": "error",
  "message": "This action is unauthorized."
}
```

### 500 Internal Server Error

```json
{
  "status": "error",
  "message": "Failed to retrieve system health metrics",
  "error": "Database connection failed" // Only in local environment
}
```

---

## Alert Thresholds

### Queue Alerts

**Critical:**
- Failed jobs (24h) >= 50
- Pending jobs >= 1000

**Warning:**
- Failed jobs (24h) >= 10
- Pending jobs >= 500

### Security Alerts

**Critical:**
- Rate limit blocks (1h) >= 100
- QR anomalies (24h) >= 50

**Warning:**
- Rate limit blocks (1h) >= 20
- QR anomalies (24h) >= 10

---

## Caching

All metrics are cached to reduce database load:

| Metric | Cache Duration |
|--------|----------------|
| Failed jobs (24h) | 60 seconds |
| Pending jobs | 30 seconds |
| Rate limit blocks (1h) | 60 seconds |
| Rate limit blocks (24h) | 300 seconds (5 min) |
| QR anomalies (24h) | 300 seconds (5 min) |

---

## Usage Examples

### Monitoring Dashboard Integration

```javascript
// Fetch system health every 30 seconds
setInterval(async () => {
  const response = await fetch('/api/v1/admin/system/health', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  const data = await response.json();
  
  // Update dashboard UI
  updateHealthStatus(data.data.health_status);
  updateMetrics(data.data.metrics);
  updateAlerts(data.data.alerts);
}, 30000);
```

### Alert Notification System

```javascript
async function checkSystemHealth() {
  const response = await fetch('/api/v1/admin/system/health', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  const { data } = await response.json();
  
  // Send notifications for critical alerts
  data.alerts
    .filter(alert => alert.severity === 'critical')
    .forEach(alert => {
      sendNotification({
        title: 'System Alert',
        message: alert.message,
        action: alert.action
      });
    });
}
```

### CLI Monitoring Script

```bash
#!/bin/bash
# monitor-health.sh

TOKEN="your_admin_token"
API_URL="http://localhost:8000/api/v1/admin/system/health"

while true; do
  response=$(curl -s -H "Authorization: Bearer $TOKEN" "$API_URL")
  
  status=$(echo $response | jq -r '.data.health_status')
  
  if [ "$status" == "critical" ]; then
    echo "⚠️  CRITICAL: System health is critical!"
    echo $response | jq '.data.alerts'
    # Send alert to Slack, email, etc.
  elif [ "$status" == "degraded" ]; then
    echo "⚠️  WARNING: System health is degraded"
  else
    echo "✅ System is healthy"
  fi
  
  sleep 60
done
```

---

## Permissions Setup

To grant system monitoring access to a user:

```php
// Grant ability to specific user
$user->givePermissionTo('system:monitor');

// Or grant to role
$role = Role::findByName('admin');
$role->givePermissionTo('system:monitor');
```

---

## Troubleshooting

### No Data Returned

**Issue:** All metrics return 0

**Possible Causes:**
1. Activity log table doesn't exist
2. No events logged yet
3. Database connection issues

**Solution:**
```bash
# Check if activity_log table exists
php artisan tinker
>>> DB::getSchemaBuilder()->hasTable('activity_log');

# Run migrations if needed
php artisan migrate
```

### High Queue Failures

**Issue:** `queue_failed_last_24h` is high

**Actions:**
1. Check Laravel logs: `storage/logs/laravel.log`
2. Review failed jobs table:
   ```sql
   SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT 10;
   ```
3. Retry failed jobs:
   ```bash
   php artisan queue:retry all
   ```

### High Rate Limit Blocks

**Issue:** `rate_limit_blocks_last_hour` is high

**Actions:**
1. Check activity logs for patterns
2. Identify source IPs
3. Consider adjusting rate limits or blocking IPs

---

## Performance Considerations

- All queries are cached to minimize database load
- Use Redis cache for better performance in production
- Consider setting up monitoring alerts based on these metrics
- Integrate with external monitoring tools (Datadog, New Relic, etc.)

---

## Related Documentation

- [Queue System Documentation](./QUEUE.md)
- [Security Documentation](./SECURITY.md)
- [Rate Limiting Documentation](./RATE_LIMITING.md)
- [QR Code Security](./QR_SECURITY.md)
