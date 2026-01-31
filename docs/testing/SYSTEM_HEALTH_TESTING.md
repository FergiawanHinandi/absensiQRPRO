# System Health Monitoring - Quick Test Guide

## Prerequisites

1. **Start the Laravel server:**
   ```bash
   cd backend
   php artisan serve
   ```

2. **Get an admin token:**
   ```bash
   # Login as admin
   curl -X POST http://localhost:8000/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{
       "username": "admin",
       "password": "password"
     }'
   
   # Copy the "token" from response
   ```

---

## Test Endpoints

### 1. Test System Health Overview

```bash
# Replace YOUR_TOKEN with actual token
curl -X GET "http://localhost:8000/api/v1/admin/system/health" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" | json_pp
```

**Expected Response:**
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

### 2. Test Queue Health Details

```bash
curl -X GET "http://localhost:8000/api/v1/admin/system/health/queue" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" | json_pp
```

**Expected Response:**
```json
{
  "status": "success",
  "data": {
    "pending_jobs": 0,
    "failed_jobs_24h": 0,
    "failed_jobs_total": 0,
    "queue_lag_seconds": 0,
    "failed_jobs_by_queue": {}
  }
}
```

---

### 3. Test Security Health Details

```bash
curl -X GET "http://localhost:8000/api/v1/admin/system/health/security" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" | json_pp
```

**Expected Response:**
```json
{
  "status": "success",
  "data": {
    "rate_limit_blocks_1h": 0,
    "rate_limit_blocks_24h": 0,
    "qr_anomalies_24h": 0,
    "failed_login_attempts_1h": 0,
    "suspicious_activities_24h": 0
  }
}
```

---

## Test Authorization

### Test Unauthorized Access (No Token)

```bash
curl -X GET "http://localhost:8000/api/v1/admin/system/health" \
  -H "Accept: application/json"
```

**Expected:** `401 Unauthorized`

---

### Test Forbidden Access (Teacher Role)

```bash
# Login as teacher first
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "teacher",
    "password": "password"
  }'

# Use teacher token
curl -X GET "http://localhost:8000/api/v1/admin/system/health" \
  -H "Authorization: Bearer TEACHER_TOKEN" \
  -H "Accept: application/json"
```

**Expected:** `403 Forbidden`

---

## Simulate Metrics for Testing

### Create Failed Jobs

```bash
# Access tinker
php artisan tinker

# Insert failed job
DB::table('failed_jobs')->insert([
    'uuid' => \Illuminate\Support\Str::uuid(),
    'connection' => 'database',
    'queue' => 'default',
    'payload' => json_encode(['test' => 'data']),
    'exception' => 'Test exception for monitoring',
    'failed_at' => now(),
]);

# Exit tinker
exit
```

Now test again - `queue_failed_last_24h` should be 1.

---

### Create Pending Jobs

```bash
php artisan tinker

DB::table('jobs')->insert([
    'queue' => 'default',
    'payload' => json_encode(['test' => 'data']),
    'attempts' => 0,
    'reserved_at' => null,
    'available_at' => now()->timestamp,
    'created_at' => now()->timestamp,
]);

exit
```

Now test again - `queue_pending` should be 1.

---

### Create Activity Logs (if table exists)

```bash
php artisan tinker

# Check if table exists
DB::getSchemaBuilder()->hasTable('activity_log');

# If true, insert test data
activity()
    ->causedBy(auth()->user())
    ->withProperties(['test' => 'rate limit block'])
    ->log('Rate limit exceeded for IP 192.168.1.1');

exit
```

---

## Postman Collection

Import this JSON into Postman:

```json
{
  "info": {
    "name": "System Health Monitoring",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "Login (Get Token)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"username\": \"admin\",\n  \"password\": \"password\"\n}"
        },
        "url": {
          "raw": "http://localhost:8000/api/v1/auth/login",
          "protocol": "http",
          "host": ["localhost"],
          "port": "8000",
          "path": ["api", "v1", "auth", "login"]
        }
      }
    },
    {
      "name": "System Health",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          },
          {
            "key": "Accept",
            "value": "application/json"
          }
        ],
        "url": {
          "raw": "http://localhost:8000/api/v1/admin/system/health",
          "protocol": "http",
          "host": ["localhost"],
          "port": "8000",
          "path": ["api", "v1", "admin", "system", "health"]
        }
      }
    },
    {
      "name": "Queue Health",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          },
          {
            "key": "Accept",
            "value": "application/json"
          }
        ],
        "url": {
          "raw": "http://localhost:8000/api/v1/admin/system/health/queue",
          "protocol": "http",
          "host": ["localhost"],
          "port": "8000",
          "path": ["api", "v1", "admin", "system", "health", "queue"]
        }
      }
    },
    {
      "name": "Security Health",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          },
          {
            "key": "Accept",
            "value": "application/json"
          }
        ],
        "url": {
          "raw": "http://localhost:8000/api/v1/admin/system/health/security",
          "protocol": "http",
          "host": ["localhost"],
          "port": "8000",
          "path": ["api", "v1", "admin", "system", "health", "security"]
        }
      }
    }
  ],
  "variable": [
    {
      "key": "token",
      "value": "YOUR_TOKEN_HERE"
    }
  ]
}
```

---

## PowerShell Test Script (Windows)

```powershell
# test-health-monitoring.ps1

# Configuration
$BASE_URL = "http://localhost:8000/api/v1"
$USERNAME = "admin"
$PASSWORD = "password"

# Login and get token
Write-Host "🔐 Logging in..." -ForegroundColor Cyan
$loginBody = @{
    username = $USERNAME
    password = $PASSWORD
} | ConvertTo-Json

$loginResponse = Invoke-RestMethod -Uri "$BASE_URL/auth/login" `
    -Method Post `
    -Body $loginBody `
    -ContentType "application/json"

$token = $loginResponse.data.token
Write-Host "✅ Token obtained: $($token.Substring(0, 20))..." -ForegroundColor Green

# Test System Health
Write-Host "`n📊 Testing System Health..." -ForegroundColor Cyan
$headers = @{
    "Authorization" = "Bearer $token"
    "Accept" = "application/json"
}

$healthResponse = Invoke-RestMethod -Uri "$BASE_URL/admin/system/health" `
    -Method Get `
    -Headers $headers

Write-Host "Health Status: $($healthResponse.data.health_status)" -ForegroundColor Yellow
Write-Host "Metrics:" -ForegroundColor Yellow
$healthResponse.data.metrics | Format-Table

# Test Queue Health
Write-Host "`n⚙️  Testing Queue Health..." -ForegroundColor Cyan
$queueResponse = Invoke-RestMethod -Uri "$BASE_URL/admin/system/health/queue" `
    -Method Get `
    -Headers $headers

$queueResponse.data | Format-Table

# Test Security Health
Write-Host "`n🔒 Testing Security Health..." -ForegroundColor Cyan
$securityResponse = Invoke-RestMethod -Uri "$BASE_URL/admin/system/health/security" `
    -Method Get `
    -Headers $headers

$securityResponse.data | Format-Table

Write-Host "`n✅ All tests completed!" -ForegroundColor Green
```

Run with:
```powershell
.\test-health-monitoring.ps1
```

---

## Troubleshooting

### Issue: 401 Unauthorized

**Solution:** Check if token is valid and not expired (120 min default)

### Issue: 403 Forbidden

**Solution:** Ensure user has `system:monitor` permission or wildcard `*`

```bash
php artisan tinker

$user = User::where('username', 'admin')->first();
$user->givePermissionTo('system:monitor');
# or
$user->givePermissionTo('*');

exit
```

### Issue: All metrics return 0

**Solution:** This is normal if no events have occurred. Simulate some data (see above).

---

## Integration with Monitoring Tools

### Prometheus Metrics Export

```php
// Add to routes/api.php for Prometheus scraping
Route::get('/metrics', function () {
    $health = app(\App\Http\Controllers\Api\V1\Admin\SystemHealthController::class)->health();
    $data = $health->getData()->data->metrics;
    
    return response("
# HELP queue_failed_jobs_24h Failed jobs in last 24 hours
# TYPE queue_failed_jobs_24h gauge
queue_failed_jobs_24h {$data->queue_failed_last_24h}

# HELP queue_pending_jobs Pending jobs in queue
# TYPE queue_pending_jobs gauge
queue_pending_jobs {$data->queue_pending}

# HELP rate_limit_blocks_1h Rate limit blocks in last hour
# TYPE rate_limit_blocks_1h gauge
rate_limit_blocks_1h {$data->rate_limit_blocks_last_hour}

# HELP qr_anomalies_24h QR anomalies in last 24 hours
# TYPE qr_anomalies_24h gauge
qr_anomalies_24h {$data->qr_anomalies_last_24h}
    ")->header('Content-Type', 'text/plain');
});
```

---

## Next Steps

1. ✅ Test all endpoints manually
2. ✅ Run automated tests: `php artisan test --filter=SystemHealthMonitoringTest`
3. ✅ Integrate with frontend dashboard
4. ✅ Set up alerting based on thresholds
5. ✅ Configure monitoring tools (Datadog, New Relic, etc.)
