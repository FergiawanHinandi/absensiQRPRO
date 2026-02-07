# Database Replication Setup (Primary + Replica)

Panduan lengkap setup PostgreSQL replication dengan Laravel read/write split.

## Arsitektur

```
┌─────────────────┐         ┌─────────────────┐
│   Application   │         │   Application   │
│    (Laravel)    │         │    (Laravel)    │
└────────┬────────┘         └────────┬────────┘
         │                           │
         │ Read/Write Split          │
         ▼                           ▼
┌─────────────────┐         ┌─────────────────┐
│  PRIMARY (Write)│────────▶│  REPLICA (Read) │
│   PostgreSQL    │ Streaming│   PostgreSQL    │
│   192.168.1.10  │ Replication  192.168.1.11 │
└─────────────────┘         └─────────────────┘
```

## 1. Konfigurasi Laravel (database.php)

Konfigurasi sudah diterapkan di `config/database.php`:

```php
'pgsql' => [
    'driver' => 'pgsql',
    
    // Read connections (replica)
    'read' => env('DB_REPLICA_ENABLED', false) ? [
        'host' => [
            env('DB_REPLICA_HOST'),
            // Tambah replica lain untuk load balancing
        ],
        'port' => env('DB_REPLICA_PORT', '5432'),
    ] : null,
    
    // Write connection (primary)
    'write' => [
        'host' => env('DB_HOST'),
        'port' => env('DB_PORT', '5432'),
    ],
    
    // Sticky: setelah write, read berikutnya pakai write connection
    'sticky' => true,
    
    // Shared config
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
],
```

## 2. Environment Variables

Tambahkan ke `.env`:

```env
# Primary Database (Write)
DB_CONNECTION=pgsql
DB_HOST=192.168.1.10
DB_PORT=5432
DB_DATABASE=absensi_qr
DB_USERNAME=absensi_app
DB_PASSWORD=secure_password_here

# Replica Database (Read)
DB_REPLICA_ENABLED=true
DB_REPLICA_HOST=192.168.1.11
DB_REPLICA_PORT=5432

# Sticky connections (recommended: true)
DB_STICKY=true

# Connection resilience
DB_TIMEOUT=5
DB_MAX_RETRIES=3
DB_RETRY_AFTER=100

# Failover tracking (auto-set during failover)
DB_FAILOVER_ACTIVE=false
DB_ORIGINAL_PRIMARY_HOST=
```

## 3. Setup PostgreSQL Streaming Replication

### 3.1 Primary Server (192.168.1.10)

```bash
# Edit postgresql.conf
sudo nano /etc/postgresql/15/main/postgresql.conf
```

```ini
# Replication settings
wal_level = replica
max_wal_senders = 3
wal_keep_size = 1GB
hot_standby = on

# Listen on all interfaces
listen_addresses = '*'
```

```bash
# Edit pg_hba.conf - allow replica to connect
sudo nano /etc/postgresql/15/main/pg_hba.conf
```

```
# Replication user
host    replication     replicator      192.168.1.11/32         scram-sha-256
```

```bash
# Create replication user
sudo -u postgres psql
CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD 'replication_password';

# Restart PostgreSQL
sudo systemctl restart postgresql
```

### 3.2 Replica Server (192.168.1.11)

```bash
# Stop PostgreSQL
sudo systemctl stop postgresql

# Remove existing data
sudo rm -rf /var/lib/postgresql/15/main/*

# Base backup from primary
sudo -u postgres pg_basebackup -h 192.168.1.10 -D /var/lib/postgresql/15/main -U replicator -P -Xs -R

# The -R flag creates standby.signal and configures replication
```

```bash
# Verify standby.signal exists
ls -la /var/lib/postgresql/15/main/standby.signal

# Check postgresql.auto.conf has connection info
cat /var/lib/postgresql/15/main/postgresql.auto.conf
# Should contain: primary_conninfo = 'host=192.168.1.10 ...'
```

```bash
# Edit postgresql.conf for hot standby
sudo nano /etc/postgresql/15/main/postgresql.conf
```

```ini
hot_standby = on
```

```bash
# Start replica
sudo systemctl start postgresql

# Verify replication status
sudo -u postgres psql -c "SELECT * FROM pg_stat_wal_receiver;"
```

### 3.3 Verify Replication

On Primary:
```sql
SELECT client_addr, state, sent_lsn, write_lsn, flush_lsn, replay_lsn
FROM pg_stat_replication;
```

On Replica:
```sql
SELECT pg_is_in_recovery();  -- Should return true
SELECT pg_last_wal_receive_lsn(), pg_last_wal_replay_lsn();
```

## 4. Laravel Commands

### Health Check

```bash
# Check both primary and replica
php artisan db:health-check

# JSON output for monitoring
php artisan db:health-check --json

# Check specific
php artisan db:health-check --primary
php artisan db:health-check --replica
```

### Failover Management

```bash
# Show current status
php artisan db:failover status

# Promote replica to primary (emergency failover)
php artisan db:failover promote

# Dry run (preview changes)
php artisan db:failover promote --dry-run

# Force without confirmation
php artisan db:failover promote --force

# Switch back to original primary (after repair)
php artisan db:failover switchback
```

## 5. Automatic Failover Plan

### 5.1 Monitoring Script

Buat cron job untuk monitoring:

```bash
# /etc/cron.d/db-health-check
*/1 * * * * www-data cd /var/www/absensiQRPro/backend && php artisan db:health-check --json >> /var/log/db-health.log 2>&1
```

### 5.2 Automated Failover (dengan supervisor)

```php
// app/Console/Commands/DatabaseMonitorDaemon.php
// Jalankan sebagai daemon yang memonitor dan auto-failover
```

### 5.3 Manual Failover Procedure

```
┌─────────────────────────────────────────────────────────────┐
│                   FAILOVER PROCEDURE                         │
├─────────────────────────────────────────────────────────────┤
│ 1. Detect primary failure                                    │
│    - Health check fails                                      │
│    - Connection timeout                                      │
│                                                              │
│ 2. Verify replica is healthy                                 │
│    $ php artisan db:health-check --replica                  │
│                                                              │
│ 3. Promote replica in PostgreSQL                            │
│    $ sudo -u postgres pg_ctl promote -D /var/lib/postgresql │
│    OR: $ sudo touch /var/lib/postgresql/15/main/promote     │
│                                                              │
│ 4. Execute Laravel failover                                  │
│    $ php artisan db:failover promote --force                │
│                                                              │
│ 5. Verify application is working                             │
│    $ php artisan db:health-check                            │
│                                                              │
│ 6. Investigate and repair original primary                   │
│                                                              │
│ 7. Setup original primary as new replica                     │
│                                                              │
│ 8. When ready, switchback                                    │
│    $ php artisan db:failover switchback                     │
└─────────────────────────────────────────────────────────────┘
```

## 6. Query Routing Behavior

Laravel otomatis route query:

```php
// SELECT queries → Replica
User::where('active', true)->get();  // Read from replica

// INSERT/UPDATE/DELETE → Primary
User::create(['name' => 'John']);    // Write to primary

// After write, reads use primary (sticky)
$user = User::create(['name' => 'John']);
$user->refresh();  // Reads from primary (sticky)

// Force specific connection
DB::connection('pgsql')->table('users')
    ->useWritePdo()  // Force primary
    ->get();
```

## 7. Troubleshooting

### Replication Lag Tinggi

```sql
-- Check lag on primary
SELECT client_addr, 
       pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS lag_bytes
FROM pg_stat_replication;

-- Solutions:
-- 1. Increase wal_keep_size
-- 2. Check network bandwidth
-- 3. Optimize heavy queries
```

### Replica Disconnected

```bash
# On replica, check status
sudo -u postgres psql -c "SELECT * FROM pg_stat_wal_receiver;"

# Restart replication
sudo systemctl restart postgresql
```

### Split-Brain Prevention

```php
// Always check if in recovery before writes on failover
$isReplica = DB::selectOne('SELECT pg_is_in_recovery() as is_replica');
if ($isReplica->is_replica) {
    throw new \Exception('Cannot write to replica!');
}
```

## 8. GitHub Actions Monitoring

```yaml
# .github/workflows/db-monitor.yml
name: Database Health Monitor

on:
  schedule:
    - cron: '*/5 * * * *'  # Every 5 minutes

jobs:
  health-check:
    runs-on: ubuntu-latest
    steps:
      - name: Check Database Health
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ secrets.SSH_HOST }}
          username: ${{ secrets.SSH_USERNAME }}
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          script: |
            cd /var/www/absensiQRPro/backend
            php artisan db:health-check --json
```

## 9. Connection String Examples

### Primary Only (Development)

```env
DB_REPLICA_ENABLED=false
DB_HOST=localhost
```

### With Replica (Production)

```env
DB_REPLICA_ENABLED=true
DB_HOST=primary.db.internal
DB_REPLICA_HOST=replica.db.internal
DB_STICKY=true
```

### Multiple Replicas (High Traffic)

```env
DB_REPLICA_ENABLED=true
DB_HOST=primary.db.internal
# Di database.php, tambahkan multiple hosts di 'read' array
```

## 10. Performance Metrics

Monitor metrik penting:

| Metric | Target | Alert |
|--------|--------|-------|
| Replication Lag | < 1s | > 30s |
| Primary Latency | < 10ms | > 100ms |
| Replica Latency | < 10ms | > 100ms |
| Connection Pool | < 80% | > 90% |

```bash
# Prometheus endpoint untuk metrics
php artisan db:health-check --json | jq '.primary.latency_ms'
```
