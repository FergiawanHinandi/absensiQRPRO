# Auto Failover Strategy

Panduan lengkap strategi failover otomatis untuk High Availability AbsensiQRPro.

## Overview Arsitektur HA

```
                    ┌─────────────────────────────────────────────┐
                    │              LOAD BALANCER                  │
                    │         (HAProxy / Nginx / AWS ALB)         │
                    └────────────────┬────────────────────────────┘
                                     │
              ┌──────────────────────┼──────────────────────┐
              │                      │                      │
              ▼                      ▼                      ▼
     ┌────────────────┐    ┌────────────────┐    ┌────────────────┐
     │   App Server 1  │    │   App Server 2  │    │   App Server N  │
     │    (Primary)    │    │   (Secondary)   │    │    (Standby)    │
     └────────┬───────┘    └────────┬───────┘    └────────┬───────┘
              │                      │                      │
              └──────────────────────┼──────────────────────┘
                                     │
     ┌───────────────────────────────┼───────────────────────────────┐
     │                               │                               │
     ▼                               ▼                               ▼
┌─────────────┐              ┌─────────────┐              ┌─────────────┐
│  PostgreSQL │──Streaming──▶│  PostgreSQL │              │    Redis    │
│   Primary   │  Replication │   Replica   │              │   Sentinel  │
└─────────────┘              └─────────────┘              └─────────────┘
```

## Failover Matrix

| Komponen | Jika Mati | Sistem Bertindak | Dampak User |
|----------|-----------|------------------|-------------|
| App Server | LB redirect ke server lain | Otomatis | Tidak terasa |
| Primary DB | Promote replica | Write pause < 1 menit | Minimal |
| Redis Node | Sentinel switch | Otomatis | Session aman |
| Storage Node | Cloud redundancy | Otomatis | Tidak terasa |

## 1. App Server Failover

### Konfigurasi Load Balancer (Nginx)

```nginx
upstream backend {
    least_conn;
    
    server 192.168.1.10:8000 weight=5 max_fails=3 fail_timeout=30s;
    server 192.168.1.11:8000 weight=5 max_fails=3 fail_timeout=30s backup;
    server 192.168.1.12:8000 weight=3 max_fails=3 fail_timeout=30s backup;
    
    # Health check
    health_check interval=5s fails=3 passes=2;
}

server {
    location / {
        proxy_pass http://backend;
        proxy_connect_timeout 5s;
        proxy_read_timeout 30s;
        proxy_next_upstream error timeout http_500 http_502 http_503;
        proxy_next_upstream_tries 3;
    }
    
    # Health endpoint
    location /health {
        proxy_pass http://backend/api/health;
        proxy_connect_timeout 2s;
    }
}
```

### Health Endpoint Laravel

```php
// routes/api.php
Route::get('/health', function () {
    $checks = [
        'database' => DB::connection()->getPdo() ? 'ok' : 'fail',
        'cache' => Cache::has('health-check') || Cache::put('health-check', true, 60) ? 'ok' : 'fail',
    ];
    
    $allOk = !in_array('fail', $checks);
    
    return response()->json([
        'status' => $allOk ? 'healthy' : 'unhealthy',
        'checks' => $checks,
    ], $allOk ? 200 : 503);
});
```

## 2. Database Failover

### Auto-Failover dengan HA Monitor

```bash
# Jalankan dengan auto-failover enabled
php artisan ha:monitor --daemon --auto-failover --alert

# Atau manual failover
php artisan db:failover promote --force
```

### PostgreSQL Streaming Replication

Primary (`/etc/postgresql/15/main/postgresql.conf`):
```ini
wal_level = replica
max_wal_senders = 3
wal_keep_size = 1GB
synchronous_commit = on
synchronous_standby_names = 'replica1'
```

### Failover Procedure

```
┌─────────────────────────────────────────────────────────────────┐
│                    DATABASE FAILOVER FLOW                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Primary Failure Detected                                     │
│     └─▶ HA Monitor detects 3 consecutive failures               │
│                                                                  │
│  2. Verify Replica Health                                        │
│     └─▶ php artisan db:health-check --replica                   │
│                                                                  │
│  3. Promote Replica (PostgreSQL)                                │
│     └─▶ pg_ctl promote -D /var/lib/postgresql                   │
│                                                                  │
│  4. Update Laravel Configuration                                 │
│     └─▶ php artisan db:failover promote --force                 │
│                                                                  │
│  5. Verify Application                                           │
│     └─▶ php artisan ha:monitor --component=database             │
│                                                                  │
│  Timeline: < 60 seconds                                          │
└─────────────────────────────────────────────────────────────────┘
```

## 3. Redis Sentinel Failover

### Sentinel Configuration

```conf
# /etc/redis/sentinel.conf
sentinel monitor mymaster 192.168.1.20 6379 2
sentinel down-after-milliseconds mymaster 5000
sentinel failover-timeout mymaster 60000
sentinel parallel-syncs mymaster 1
sentinel auth-pass mymaster your_password
```

### Laravel Sentinel Config

```php
// config/database.php (sudah dikonfigurasi)
'redis' => [
    'client' => 'predis',
    'options' => [
        'replication' => 'sentinel',
        'service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
    ],
    'default' => [
        env('REDIS_SENTINEL_1', 'tcp://192.168.1.30:26379'),
        env('REDIS_SENTINEL_2', 'tcp://192.168.1.31:26379'),
        env('REDIS_SENTINEL_3', 'tcp://192.168.1.32:26379'),
    ],
],
```

### Sentinel Failover Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    REDIS SENTINEL FAILOVER                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Master Failure Detected                                      │
│     └─▶ Sentinel detects master down (5 seconds)                │
│                                                                  │
│  2. Quorum Agreement                                             │
│     └─▶ 2 of 3 sentinels agree master is down                   │
│                                                                  │
│  3. Elect New Master                                             │
│     └─▶ Sentinel promotes best slave to master                  │
│                                                                  │
│  4. Reconfigure Slaves                                           │
│     └─▶ Other slaves point to new master                        │
│                                                                  │
│  5. Laravel Auto-Reconnect                                       │
│     └─▶ Predis reconnects to new master via Sentinel            │
│                                                                  │
│  Timeline: < 30 seconds (automatic)                              │
└─────────────────────────────────────────────────────────────────┘
```

## 4. Storage Failover

### S3 Cross-Region Replication

```php
// config/filesystems.php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
        'bucket' => env('AWS_BUCKET'),
        // Automatic failover to different region
        'endpoint' => env('AWS_ENDPOINT'),
    ],
    
    // Backup storage
    's3_backup' => [
        'driver' => 's3',
        'region' => 'ap-southeast-2', // Different region
        'bucket' => env('AWS_BACKUP_BUCKET'),
    ],
],
```

## 5. Monitoring Commands

### HA Monitor

```bash
# Single check
php artisan ha:monitor

# Continuous daemon mode
php artisan ha:monitor --daemon --interval=30

# With auto-failover
php artisan ha:monitor --daemon --auto-failover --alert

# JSON output for CI/CD
php artisan ha:monitor --json

# Check specific component
php artisan ha:monitor --component=database
php artisan ha:monitor --component=redis
php artisan ha:monitor --component=queue
```

### Database Commands

```bash
# Health check
php artisan db:health-check

# Failover status
php artisan db:failover status

# Manual failover
php artisan db:failover promote
php artisan db:failover switchback
```

## 6. Monitoring Thresholds

Configure in `.env`:

```env
# Database
HA_DB_LAG_WARNING=10           # seconds
HA_DB_LAG_CRITICAL=60          # seconds
HA_DB_LATENCY_WARNING=100      # ms
HA_DB_LATENCY_CRITICAL=500     # ms

# Redis
HA_REDIS_MEM_WARNING=80        # percentage
HA_REDIS_MEM_CRITICAL=95       # percentage

# Queue
HA_QUEUE_BACKLOG_WARNING=100   # jobs
HA_QUEUE_BACKLOG_CRITICAL=1000 # jobs
HA_QUEUE_FAILED_WARNING=10     # per hour
HA_QUEUE_FAILED_CRITICAL=50    # per hour

# App Server
HA_DISK_WARNING=80             # percentage
HA_DISK_CRITICAL=90            # percentage

# Auto-failover
HA_AUTO_FAILOVER=false         # Enable with caution!
HA_FAILOVER_THRESHOLD=3        # Consecutive failures
```

## 7. Alert Configuration

```env
# Slack alerts
SLACK_HA_WEBHOOK=https://hooks.slack.com/services/...
HA_ALERT_SLACK=true

# PagerDuty integration
PAGERDUTY_INTEGRATION_KEY=your-key
HA_ALERT_PAGERDUTY=true

# Alert cooldown (prevent flooding)
HA_ALERT_COOLDOWN=5           # minutes
```

## 8. Supervisor Configuration

```bash
# Install supervisor configs
sudo cp deployment/supervisor/*.conf /etc/supervisor/conf.d/

# Reload
sudo supervisorctl reread
sudo supervisorctl update

# Monitor
sudo supervisorctl status ha-monitor
```

## 9. GitHub Actions Integration

The workflow `.github/workflows/ha-monitor.yml` runs every 5 minutes:

- ✅ Checks all component health
- ✅ Sends Slack alerts on critical
- ✅ Creates GitHub issue on persistent failures
- ✅ Generates weekly reports

## 10. Runbook: Emergency Failover

### Database Emergency Failover

```bash
# 1. Confirm primary is down
ssh primary-db "systemctl status postgresql"

# 2. Verify replica status
ssh replica-db "sudo -u postgres psql -c 'SELECT pg_is_in_recovery();'"

# 3. Promote replica
ssh replica-db "sudo -u postgres pg_ctl promote -D /var/lib/postgresql/15/main"

# 4. Update Laravel
ssh app-server "cd /var/www/absensiQRPro/backend && php artisan db:failover promote --force"

# 5. Verify
ssh app-server "cd /var/www/absensiQRPro/backend && php artisan ha:monitor --component=database"
```

### Redis Emergency Failover

```bash
# Sentinel handles this automatically, but to force:
redis-cli -p 26379 SENTINEL FAILOVER mymaster
```

### Full System Recovery Checklist

- [ ] Identify failing component
- [ ] Check HA monitor status: `php artisan ha:monitor --json`
- [ ] Execute component-specific failover
- [ ] Verify application health
- [ ] Update DNS/Load Balancer if needed
- [ ] Notify stakeholders
- [ ] Create incident report
- [ ] Schedule post-mortem
