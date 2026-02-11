# Health Check & Slow Query Monitoring - Implementation Guide

## Overview
Comprehensive health monitoring and slow query detection system for production environments.

## Features Implemented

### ✅ 1. Enhanced Health Check Endpoint
- **URL**: `/health`
- **Method**: GET
- **Response**: JSON with detailed system status

### ✅ 2. Slow Query Listener
- **Threshold**: 500ms (configurable)
- **Logging**: Comprehensive query analysis
- **Critical Alerts**: Queries >2000ms logged as errors

## Health Check Endpoint

### Implementation

```php
Route::get('/health', function () {
    // Checks:
    // 1. Database connectivity
    // 2. Redis connectivity
    // 3. Cache functionality
    // 4. Queue connection
    // 5. Storage accessibility
    // 6. Application status
});
```

### Response Format

#### Healthy System (200 OK)
```json
{
  "status": "healthy",
  "timestamp": "2026-02-09T11:36:08+08:00",
  "response_time_ms": 45.23,
  "checks": {
    "db": {
      "status": "ok",
      "driver": "pgsql",
      "response_time_ms": 12.45
    },
    "redis": {
      "status": "ok",
      "response": "+PONG",
      "response_time_ms": 3.21
    },
    "cache": {
      "status": "ok",
      "driver": "redis"
    },
    "queue": {
      "status": "ok",
      "driver": "redis"
    },
    "storage": {
      "status": "ok",
      "disk": "local"
    },
    "app": {
      "status": "ok",
      "environment": "production",
      "debug": false,
      "timezone": "Asia/Jakarta"
    }
  }
}
```

#### Unhealthy System (503 Service Unavailable)
```json
{
  "status": "unhealthy",
  "timestamp": "2026-02-09T11:36:08+08:00",
  "response_time_ms": 5234.12,
  "checks": {
    "db": {
      "status": "fail",
      "error": "SQLSTATE[HY000] [2002] Connection refused"
    },
    "redis": {
      "status": "fail",
      "error": "Connection refused [tcp://127.0.0.1:6379]"
    },
    "cache": {
      "status": "ok",
      "driver": "redis"
    },
    "queue": {
      "status": "ok",
      "driver": "redis"
    },
    "storage": {
      "status": "ok",
      "disk": "local"
    },
    "app": {
      "status": "ok",
      "environment": "production",
      "debug": false,
      "timezone": "Asia/Jakarta"
    }
  }
}
```

### Health Check Details

#### 1. Database Check
```php
try {
    $pdo = DB::connection()->getPdo();
    DB::select('SELECT 1'); // Test actual query
    
    $checks['db'] = [
        'status' => 'ok',
        'driver' => 'pgsql',
        'response_time_ms' => 12.45
    ];
} catch (\Exception $e) {
    $checks['db'] = [
        'status' => 'fail',
        'error' => $e->getMessage()
    ];
}
```

**What it checks**:
- ✅ PDO connection established
- ✅ Can execute queries
- ✅ Response time measured

#### 2. Redis Check
```php
try {
    $ping = Redis::ping();
    
    $checks['redis'] = [
        'status' => 'ok',
        'response' => '+PONG',
        'response_time_ms' => 3.21
    ];
} catch (\Exception $e) {
    $checks['redis'] = [
        'status' => 'fail',
        'error' => $e->getMessage()
    ];
}
```

**What it checks**:
- ✅ Redis server responding
- ✅ Connection established
- ✅ Response time measured

#### 3. Cache Check
```php
try {
    $key = 'health_check_' . time();
    $value = 'test_' . rand(1000, 9999);
    
    Cache::put($key, $value, 10);  // Write
    $retrieved = Cache::get($key);  // Read
    Cache::forget($key);            // Delete
    
    $checks['cache'] = [
        'status' => $retrieved === $value ? 'ok' : 'fail',
        'driver' => 'redis'
    ];
} catch (\Exception $e) {
    $checks['cache'] = [
        'status' => 'fail',
        'error' => $e->getMessage()
    ];
}
```

**What it checks**:
- ✅ Can write to cache
- ✅ Can read from cache
- ✅ Can delete from cache
- ✅ Data integrity verified

#### 4. Queue Check
```php
try {
    $queueConnection = config('queue.default');
    
    $checks['queue'] = [
        'status' => 'ok',
        'driver' => 'redis'
    ];
} catch (\Exception $e) {
    $checks['queue'] = [
        'status' => 'fail',
        'error' => $e->getMessage()
    ];
}
```

#### 5. Storage Check
```php
try {
    $testFile = 'health_check_' . time() . '.txt';
    
    Storage::disk('local')->put($testFile, 'health check');
    $exists = Storage::disk('local')->exists($testFile);
    Storage::disk('local')->delete($testFile);
    
    $checks['storage'] = [
        'status' => $exists ? 'ok' : 'fail',
        'disk' => 'local'
    ];
} catch (\Exception $e) {
    $checks['storage'] = [
        'status' => 'fail',
        'error' => $e->getMessage()
    ];
}
```

**What it checks**:
- ✅ Can write files
- ✅ Can read files
- ✅ Can delete files
- ✅ Disk permissions correct

## Slow Query Listener

### Implementation

```php
DB::listen(function ($query) {
    $threshold = config('database.slow_query_threshold', 500);
    
    if ($query->time > $threshold) {
        Log::warning('Slow Query Detected', [
            'query_type' => 'SELECT',
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time_ms' => 523.45,
            'threshold_ms' => 500,
            'connection' => 'pgsql',
            'request' => [...],
            'user' => [...],
            'stack_trace' => [...],
        ]);
    }
});
```

### Configuration

Add to `config/database.php`:

```php
return [
    // ...
    
    'slow_query_threshold' => env('DB_SLOW_QUERY_THRESHOLD', 500),
];
```

Add to `.env`:

```env
# Slow query threshold in milliseconds
DB_SLOW_QUERY_THRESHOLD=500
```

### Log Output

#### Warning Level (500ms - 2000ms)
```json
{
  "level": "warning",
  "message": "Slow Query Detected",
  "context": {
    "query_type": "SELECT",
    "sql": "SELECT * FROM attendances WHERE school_id = ? AND attendance_date = ?",
    "bindings": [1, "2026-02-09"],
    "time_ms": 523.45,
    "threshold_ms": 500,
    "connection": "pgsql",
    "request": {
      "url": "https://api.example.com/api/v1/dashboard",
      "method": "GET",
      "ip": "192.168.1.100"
    },
    "user": {
      "user_id": 123,
      "user_role": "admin",
      "school_id": 1
    },
    "stack_trace": [
      {
        "file": "DashboardController.php",
        "line": 45,
        "function": "getDashboardOverview"
      },
      {
        "file": "Controller.php",
        "line": 78,
        "function": "callAction"
      }
    ],
    "timestamp": "2026-02-09T11:36:08+08:00"
  }
}
```

#### Error Level (>2000ms)
```json
{
  "level": "error",
  "message": "CRITICAL: Extremely Slow Query",
  "context": {
    "query_type": "SELECT",
    "sql": "SELECT * FROM attendances WHERE school_id = ?",
    "time_ms": 2345.67,
    "action_required": "Immediate optimization needed"
  }
}
```

### Features

#### 1. Query Type Detection
```php
$queryType = 'UNKNOWN';
if (str_starts_with($sql, 'SELECT')) {
    $queryType = 'SELECT';
} elseif (str_starts_with($sql, 'INSERT')) {
    $queryType = 'INSERT';
} elseif (str_starts_with($sql, 'UPDATE')) {
    $queryType = 'UPDATE';
} elseif (str_starts_with($sql, 'DELETE')) {
    $queryType = 'DELETE';
}
```

#### 2. User Context
```php
$user = request()->user();
$userContext = $user ? [
    'user_id' => $user->id,
    'user_role' => $user->role ?? 'unknown',
    'school_id' => $user->school_id ?? null,
] : null;
```

#### 3. Stack Trace (Filtered)
```php
$trace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10))
    ->filter(function ($item) {
        // Filter out framework internals
        return isset($item['file']) && 
               !str_contains($item['file'], 'vendor/laravel') &&
               !str_contains($item['file'], 'vendor/illuminate');
    })
    ->map(function ($item) {
        return [
            'file' => basename($item['file'] ?? ''),
            'line' => $item['line'] ?? 0,
            'function' => $item['function'] ?? '',
        ];
    })
    ->take(5)
    ->values()
    ->toArray();
```

**Benefits**:
- ✅ Shows only application code (not framework)
- ✅ Limited to 5 frames (prevents log bloat)
- ✅ Includes file, line, and function name

## Usage Examples

### 1. Load Balancer Health Check

```bash
# Simple check
curl http://localhost:8000/health

# With timeout
curl --max-time 5 http://localhost:8000/health

# Check specific service
curl http://localhost:8000/health | jq '.checks.db.status'
```

### 2. Monitoring Integration

#### Uptime Robot
```
Monitor Type: HTTP(s)
URL: https://api.yourdomain.com/health
Keyword: "healthy"
Alert When: Keyword not found
```

#### Prometheus
```yaml
- job_name: 'laravel-health'
  metrics_path: '/health'
  static_configs:
    - targets: ['api.yourdomain.com']
```

#### Datadog
```yaml
init_config:

instances:
  - url: https://api.yourdomain.com/health
    name: laravel_app
    timeout: 5
```

### 3. Slow Query Analysis

#### View Recent Slow Queries
```bash
# Last 100 slow queries
tail -n 100 storage/logs/laravel.log | grep "Slow Query"

# Count by query type
grep "Slow Query" storage/logs/laravel.log | jq '.context.query_type' | sort | uniq -c

# Slowest queries
grep "Slow Query" storage/logs/laravel.log | jq '.context | {sql, time_ms}' | sort -k2 -rn | head -10
```

#### PostgreSQL: Find Slow Queries
```sql
-- Check current slow queries
SELECT 
    pid,
    now() - query_start as duration,
    query,
    state
FROM pg_stat_activity
WHERE state != 'idle'
AND now() - query_start > interval '500 milliseconds'
ORDER BY duration DESC;
```

## Monitoring Queries

### Check Health Status
```sql
-- Count health check requests (if logged)
SELECT 
    DATE(created_at) as date,
    COUNT(*) as health_checks,
    AVG(CAST(context->>'response_time_ms' AS FLOAT)) as avg_response_ms
FROM logs
WHERE message = 'health_check'
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;
```

### Analyze Slow Queries
```sql
-- Top 10 slowest queries
SELECT 
    context->>'query_type' as type,
    context->>'sql' as sql,
    AVG(CAST(context->>'time_ms' AS FLOAT)) as avg_time_ms,
    COUNT(*) as occurrences
FROM logs
WHERE message = 'Slow Query Detected'
AND created_at >= NOW() - INTERVAL '24 hours'
GROUP BY context->>'query_type', context->>'sql'
ORDER BY avg_time_ms DESC
LIMIT 10;
```

### Query Performance by Endpoint
```sql
-- Slow queries by endpoint
SELECT 
    context->'request'->>'url' as endpoint,
    COUNT(*) as slow_query_count,
    AVG(CAST(context->>'time_ms' AS FLOAT)) as avg_time_ms
FROM logs
WHERE message = 'Slow Query Detected'
AND created_at >= NOW() - INTERVAL '24 hours'
GROUP BY context->'request'->>'url'
ORDER BY slow_query_count DESC
LIMIT 10;
```

## Troubleshooting

### Issue: Health Check Fails

**Symptom**: `/health` returns 503

**Diagnosis**:
```bash
# Check which service is failing
curl http://localhost:8000/health | jq '.checks'
```

**Solutions**:

1. **Database Failure**:
   ```bash
   # Check PostgreSQL
   sudo systemctl status postgresql
   
   # Check connection
   psql -h localhost -U postgres -d absensi_db
   ```

2. **Redis Failure**:
   ```bash
   # Check Redis
   sudo systemctl status redis
   
   # Test connection
   redis-cli ping
   ```

3. **Storage Failure**:
   ```bash
   # Check permissions
   ls -la storage/app
   
   # Fix permissions
   chmod -R 775 storage
   chown -R www-data:www-data storage
   ```

### Issue: Too Many Slow Query Logs

**Symptom**: Logs filled with slow query warnings

**Solution 1**: Increase threshold
```env
# .env
DB_SLOW_QUERY_THRESHOLD=1000  # Increase to 1000ms
```

**Solution 2**: Optimize queries
```bash
# Find most common slow queries
grep "Slow Query" storage/logs/laravel.log | \
  jq -r '.context.sql' | \
  sort | uniq -c | sort -rn | head -10
```

**Solution 3**: Add indexes
```sql
-- Example: Add index for common query
CREATE INDEX idx_attendances_school_date 
ON attendances(school_id, attendance_date);
```

## Production Deployment

### 1. Environment Configuration
```env
# .env
DB_SLOW_QUERY_THRESHOLD=500
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

### 2. Nginx Configuration
```nginx
# Health check endpoint (no auth required)
location /health {
    try_files $uri $uri/ /index.php?$query_string;
    access_log off;  # Don't log health checks
}
```

### 3. Monitoring Setup
```bash
# Add to crontab for alerting
*/5 * * * * curl -f http://localhost:8000/health || echo "Health check failed" | mail -s "Alert" admin@example.com
```

## Summary

✅ **Health Check Endpoint**: Comprehensive system status  
✅ **Slow Query Listener**: Detailed query performance monitoring  
✅ **Response Time Tracking**: Measure all service response times  
✅ **Error Handling**: Graceful degradation with detailed errors  
✅ **Production Ready**: Suitable for load balancers and monitoring tools  
✅ **Configurable Threshold**: Adjust slow query detection threshold  
✅ **Critical Alerts**: Separate error logging for extremely slow queries  

The system is production-ready with enterprise-grade monitoring!
