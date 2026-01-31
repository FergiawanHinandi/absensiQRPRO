# Health Check Upgrade Implementation

## Overview

Health Check endpoint telah diupgrade untuk melakukan pemeriksaan yang lebih komprehensif terhadap sistem AbsensiQR Pro. Upgrade ini mencakup pemeriksaan database, Redis, cache, storage, queue, disk space, memory, dan informasi sistem.

## Features Implemented

### 1. Comprehensive System Checks

#### Database Check
- **Connection Test**: Verifikasi koneksi database
- **Query Performance**: Mengukur response time query
- **Data Statistics**: Menampilkan jumlah users dan schools
- **Driver Information**: Menampilkan driver database yang digunakan

#### Redis Check
- **Extension Detection**: Cek apakah Redis extension tersedia
- **Connection Test**: Verifikasi koneksi Redis
- **Read/Write Test**: Test operasi Redis dengan key unik
- **Performance Metrics**: Mengukur response time operasi

#### Cache Check
- **Driver Information**: Menampilkan cache driver yang digunakan
- **Read/Write Test**: Test operasi cache dengan key unik
- **Performance Metrics**: Mengukur response time operasi

#### Storage Check
- **Write Permission**: Test kemampuan menulis file
- **Read/Write Test**: Test operasi file system
- **Path Information**: Menampilkan path storage
- **Performance Metrics**: Mengukur response time operasi

#### Queue Check
- **Connection Test**: Verifikasi koneksi queue
- **Driver Information**: Menampilkan queue driver yang digunakan
- **Job Statistics**: Menghitung pending dan failed jobs
- **Threshold Alerts**: Warning untuk >100 pending jobs, error untuk >50 failed jobs

#### Disk Space Check
- **Space Usage**: Menampilkan total, used, dan free disk space
- **Percentage Calculation**: Menghitung persentase penggunaan
- **Threshold Alerts**: Warning >80%, error >90%

#### Memory Check
- **Memory Limit**: Menampilkan PHP memory limit
- **Current Usage**: Memory yang sedang digunakan
- **Peak Usage**: Peak memory usage
- **Percentage Calculation**: Persentase penggunaan memory
- **Threshold Alerts**: Warning >80%, error >90%

### 2. System Information

#### Application Information
- **App Name**: Nama aplikasi dari config
- **Version**: Versi aplikasi dari config (`APP_VERSION`)
- **Environment**: Environment aplikasi (local, production, testing)
- **PHP Version**: Versi PHP yang digunakan
- **Laravel Version**: Versi Laravel framework

#### Uptime Tracking
- **Uptime Seconds**: Total detik aplikasi berjalan
- **Human Readable**: Format uptime yang mudah dibaca (e.g., "2d 3h 45m 12s")
- **Started At**: Timestamp kapan aplikasi dimulai

### 3. Health Status Logic

#### Status Levels
- **healthy**: Semua checks bernilai 'ok' atau 'warning'
- **unhealthy**: Ada minimal satu check bernilai 'error'

#### HTTP Status Codes
- **200**: Sistem healthy
- **503**: Sistem unhealthy (Service Unavailable)

## Configuration Changes

### 1. App Version Configuration

**File**: `backend/config/app.php`
```php
'version' => env('APP_VERSION', '1.0.0'),
```

**File**: `backend/.env` dan `backend/.env.example`
```env
APP_VERSION=1.0.0
```

### 2. Health Check Controller

**File**: `backend/app/Http/Controllers/Api/V1/HealthController.php`

Upgrade mencakup:
- 8 comprehensive checks (database, redis, cache, storage, queue, queue_jobs, disk_space, memory)
- Performance metrics untuk setiap check
- Threshold-based alerting
- Detailed error reporting
- System information collection

## API Response Structure

### Successful Response (200)
```json
{
    "status": "healthy",
    "timestamp": "2026-01-27T00:55:57.664887Z",
    "version": "1.0.0",
    "environment": "production",
    "app_name": "AbsensiQR API",
    "php_version": "8.2.12",
    "laravel_version": "12.48.1",
    "uptime": {
        "seconds": 284,
        "human": "4m 44s",
        "started_at": "2026-01-27 00:51:13"
    },
    "checks": {
        "database": {
            "status": "ok",
            "response_time_ms": 0.69,
            "connection": "pgsql",
            "driver": "pgsql",
            "stats": {
                "users": 150,
                "schools": 25
            },
            "message": "Database connection and queries successful"
        },
        "redis": {
            "status": "ok",
            "response_time_ms": 1.23,
            "connection": "127.0.0.1:6379",
            "message": "Redis connection and operations successful"
        },
        "cache": {
            "status": "ok",
            "response_time_ms": 2.45,
            "driver": "redis",
            "message": "Cache working properly"
        },
        "storage": {
            "status": "ok",
            "response_time_ms": 3.21,
            "path": "/var/www/storage/app",
            "writable": true,
            "message": "Storage read/write successful"
        },
        "queue": {
            "status": "ok",
            "connection": "redis",
            "driver": "redis",
            "message": "Queue connection available"
        },
        "queue_jobs": {
            "status": "ok",
            "connection": "redis",
            "pending_jobs": 5,
            "failed_jobs": 0,
            "message": "Queue jobs checked successfully"
        },
        "disk_space": {
            "status": "ok",
            "path": "/var/www/storage",
            "total_gb": 100.0,
            "free_gb": 75.5,
            "used_gb": 24.5,
            "used_percent": 24.5,
            "message": "Disk space is adequate"
        },
        "memory": {
            "status": "ok",
            "limit": "512M",
            "current_mb": 45.2,
            "peak_mb": 48.7,
            "used_percent": 8.8,
            "message": "Memory usage is normal"
        }
    }
}
```

### Unhealthy Response (503)
```json
{
    "status": "unhealthy",
    "timestamp": "2026-01-27T00:55:57.664887Z",
    "version": "1.0.0",
    "environment": "production",
    "app_name": "AbsensiQR API",
    "php_version": "8.2.12",
    "laravel_version": "12.48.1",
    "uptime": {
        "seconds": 284,
        "human": "4m 44s",
        "started_at": "2026-01-27 00:51:13"
    },
    "checks": {
        "database": {
            "status": "error",
            "message": "Database connection failed: SQLSTATE[HY000] [2002] Connection refused"
        },
        // ... other checks
    }
}
```

## Testing

### Test Coverage

**File**: `backend/tests/Feature/HealthCheckTest.php`

Tests implemented:
1. **Comprehensive Status Test**: Verifikasi struktur response lengkap
2. **Database Failure Test**: Test response ketika database gagal
3. **Cache Failure Test**: Test response ketika cache gagal
4. **High Queue Jobs Test**: Test warning untuk jobs yang banyak
5. **High Failed Jobs Test**: Test error untuk failed jobs yang banyak
6. **App Version Test**: Test konfigurasi versi aplikasi
7. **Redis Extension Test**: Test deteksi Redis extension

### Running Tests
```bash
# Run all health check tests
php artisan test tests/Feature/HealthCheckTest.php

# Run specific test
php artisan test --filter="health_check_endpoint_returns_comprehensive_status"
```

## Usage Examples

### Basic Health Check
```bash
curl -X GET http://localhost:8000/api/v1/health
```

### Health Check with jq (JSON parsing)
```bash
curl -s http://localhost:8000/api/v1/health | jq '.status'
```

### Monitoring Script Example
```bash
#!/bin/bash
HEALTH_URL="http://localhost:8000/api/v1/health"
STATUS=$(curl -s -o /dev/null -w "%{http_code}" $HEALTH_URL)

if [ $STATUS -eq 200 ]; then
    echo "✅ System is healthy"
else
    echo "❌ System is unhealthy (HTTP $STATUS)"
    curl -s $HEALTH_URL | jq '.checks'
fi
```

## Monitoring Integration

### Prometheus Metrics
Health check dapat diintegrasikan dengan Prometheus untuk monitoring:

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'absensi-qr-health'
    metrics_path: '/api/v1/health'
    static_configs:
      - targets: ['localhost:8000']
```

### Uptime Monitoring
Endpoint dapat digunakan dengan layanan monitoring seperti:
- Pingdom
- UptimeRobot
- StatusCake
- New Relic Synthetics

### Load Balancer Health Check
```nginx
# nginx.conf
upstream backend {
    server 127.0.0.1:8000;
    # Health check configuration
}

location /health {
    proxy_pass http://backend/api/v1/health;
}
```

## Performance Considerations

### Response Time Optimization
- Database queries dioptimalkan dengan simple SELECT 1
- Cache operations menggunakan unique keys untuk menghindari collision
- File operations menggunakan temporary files dengan timestamp
- Memory calculations menggunakan built-in PHP functions

### Resource Usage
- Health check menggunakan minimal resources
- Temporary files dibersihkan otomatis
- Redis keys menggunakan TTL untuk auto-cleanup
- Database queries tidak mempengaruhi performance aplikasi

## Security Considerations

### Access Control
- Endpoint dapat diakses tanpa authentication untuk monitoring purposes
- Sensitive information tidak ditampilkan dalam response
- Error messages tidak mengekspos internal system details

### Rate Limiting
Health check endpoint sebaiknya dikecualikan dari rate limiting untuk monitoring tools.

## Troubleshooting

### Common Issues

#### Redis Warning
```json
{
    "status": "warning",
    "message": "Redis extension not loaded"
}
```
**Solution**: Install PHP Redis extension atau gunakan cache driver lain.

#### Database Connection Error
```json
{
    "status": "error", 
    "message": "Database connection failed: SQLSTATE[HY000] [2002] Connection refused"
}
```
**Solution**: Periksa konfigurasi database dan pastikan service berjalan.

#### High Memory Usage
```json
{
    "status": "warning",
    "used_percent": 85.2,
    "message": "Memory usage is high"
}
```
**Solution**: Optimize aplikasi atau tingkatkan memory limit.

#### Disk Space Low
```json
{
    "status": "error",
    "used_percent": 92.1,
    "message": "Disk space critically low"
}
```
**Solution**: Bersihkan disk atau tambah storage capacity.

## Future Enhancements

### Planned Features
1. **Custom Health Checks**: Plugin system untuk custom checks
2. **Historical Data**: Menyimpan riwayat health check results
3. **Alerting Integration**: Integrasi dengan Slack, Discord, email
4. **Detailed Metrics**: Lebih banyak metrics untuk setiap component
5. **Health Check Dashboard**: Web interface untuk monitoring

### Configuration Options
```env
# Future configuration options
HEALTH_CHECK_ENABLED=true
HEALTH_CHECK_CACHE_TTL=60
HEALTH_CHECK_DISK_WARNING_THRESHOLD=80
HEALTH_CHECK_DISK_ERROR_THRESHOLD=90
HEALTH_CHECK_MEMORY_WARNING_THRESHOLD=80
HEALTH_CHECK_MEMORY_ERROR_THRESHOLD=90
HEALTH_CHECK_QUEUE_WARNING_THRESHOLD=100
HEALTH_CHECK_QUEUE_ERROR_THRESHOLD=50
```

## Conclusion

Health Check upgrade berhasil diimplementasikan dengan fitur-fitur komprehensif untuk monitoring sistem AbsensiQR Pro. Endpoint ini memberikan visibilitas penuh terhadap kesehatan sistem dan dapat diintegrasikan dengan berbagai tools monitoring untuk memastikan reliability dan performance aplikasi.

**Key Benefits:**
- ✅ Comprehensive system monitoring
- ✅ Real-time health status
- ✅ Performance metrics
- ✅ Threshold-based alerting
- ✅ Easy integration with monitoring tools
- ✅ Detailed error reporting
- ✅ Production-ready implementation

**Status**: ✅ **COMPLETED**