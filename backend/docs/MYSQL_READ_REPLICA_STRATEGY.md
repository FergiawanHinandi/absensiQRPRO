# 🗄️ MySQL Read Replica Strategy for CQRS

**Senior Database Architect**  
**Date**: 2026-02-10  
**Purpose**: Scalable read strategy with strong write consistency

---

## 🎯 Objectives

Design MySQL read replica strategy to achieve:
1. **Strong write consistency** (zero duplicates)
2. **Horizontal read scalability** (handle 10,000+ concurrent reads)
3. **Safe failover** (<2 minute RTO)
4. **Zero duplicate attendance** (even during failover)
5. **Replication lag handling** (<2 seconds acceptable)

---

## 📊 Database Topology

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          APPLICATION LAYER                                   │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │                    Laravel Application                              │    │
│  │                                                                      │    │
│  │  ┌──────────────────┐         ┌──────────────────┐                │    │
│  │  │  Write Path      │         │   Read Path      │                │    │
│  │  │  (Commands)      │         │   (Queries)      │                │    │
│  │  └────────┬─────────┘         └────────┬─────────┘                │    │
│  │           │                             │                           │    │
│  │           │ mysql                       │ mysql_read               │    │
│  │           │ connection                  │ connection               │    │
│  └───────────┼─────────────────────────────┼──────────────────────────┘    │
│              │                             │                                │
└──────────────┼─────────────────────────────┼────────────────────────────────┘
               │                             │
               ▼                             ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          DATABASE LAYER                                      │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │                    PRIMARY DATABASE                                 │    │
│  │                    (Write Master)                                   │    │
│  │                                                                      │    │
│  │  ┌──────────────────────────────────────────────────────────┐     │    │
│  │  │  MySQL 8.0 (Multi-AZ)                                     │     │    │
│  │  │  - All writes (INSERT, UPDATE, DELETE)                   │     │    │
│  │  │  - Attendance records                                     │     │    │
│  │  │  - Subscription updates                                   │     │    │
│  │  │  - Webhook processing                                     │     │    │
│  │  │  - UNIQUE constraints enforced                            │     │    │
│  │  │                                                            │     │    │
│  │  │  Unique Index:                                            │     │    │
│  │  │  UNIQUE(schedule_id, student_id, attendance_date)        │     │    │
│  │  └──────────────────────────────────────────────────────────┘     │    │
│  │                            │                                        │    │
│  │                            │ Binary Log Replication                │    │
│  │                            │ (Async)                                │    │
│  └────────────────────────────┼────────────────────────────────────────┘    │
│                               │                                              │
│                ┌──────────────┴──────────────┬──────────────┐              │
│                │                             │              │               │
│                ▼                             ▼              ▼               │
│  ┌─────────────────────┐   ┌─────────────────────┐   ┌─────────────────┐  │
│  │  READ REPLICA 1     │   │  READ REPLICA 2     │   │  READ REPLICA 3 │  │
│  │  (Dashboard)        │   │  (Reports)          │   │  (Analytics)    │  │
│  │                     │   │                     │   │                 │  │
│  │  - Dashboard query  │   │  - Report export    │   │  - Analytics    │  │
│  │  - Summary read     │   │  - Bulk export      │   │  - Aggregations │  │
│  │  - Real-time stats  │   │  - CSV generation   │   │  - BI queries   │  │
│  │                     │   │                     │   │                 │  │
│  │  Lag: <2 sec       │   │  Lag: <5 sec       │   │  Lag: <10 sec  │  │
│  └─────────────────────┘   └─────────────────────┘   └─────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Replication Mode

### Choice: Asynchronous Replication

**Why Async?**
- ✅ **Performance**: No write latency penalty
- ✅ **Availability**: Primary doesn't wait for replicas
- ✅ **Scalability**: Can add replicas without impacting writes
- ✅ **Cost-effective**: Standard MySQL replication

**Trade-offs**:
- ⚠️ **Replication lag**: Typically <1 second, max 2 seconds acceptable
- ⚠️ **Eventual consistency**: Reads may be slightly stale
- ⚠️ **Failover complexity**: Potential data loss if primary fails

**Mitigation**:
- Monitor replication lag
- Show "updating..." message if lag >2 sec
- Use unique constraints for duplicate prevention
- GTID-based replication for safe failover

---

### Replication Configuration

**Primary Database** (`my.cnf`):

```ini
[mysqld]
# Server identification
server-id = 1

# Binary logging (required for replication)
log-bin = mysql-bin
binlog_format = ROW
binlog_row_image = FULL

# GTID (Global Transaction Identifier)
gtid_mode = ON
enforce_gtid_consistency = ON

# Replication settings
sync_binlog = 1
innodb_flush_log_at_trx_commit = 1

# Performance
max_connections = 500
innodb_buffer_pool_size = 8G
innodb_log_file_size = 512M

# Monitoring
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 1
```

**Read Replica** (`my.cnf`):

```ini
[mysqld]
# Server identification (unique per replica)
server-id = 2

# Read-only mode
read_only = 1
super_read_only = 1

# GTID
gtid_mode = ON
enforce_gtid_consistency = ON

# Replication settings
relay_log = relay-bin
relay_log_recovery = 1

# Performance (optimized for reads)
max_connections = 1000
innodb_buffer_pool_size = 16G
query_cache_type = 1
query_cache_size = 256M

# Monitoring
slow_query_log = 1
long_query_time = 2
```

---

## 📝 Laravel Configuration

### Database Configuration

**File**: `config/database.php`

```php
<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        // Primary database (write)
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
            
            // Sticky connection for writes
            'sticky' => true,
        ],

        // Read replica (read-only)
        'mysql_read' => [
            'driver' => 'mysql',
            'read' => [
                'host' => [
                    env('DB_READ_HOST_1', '127.0.0.1'),
                    env('DB_READ_HOST_2', '127.0.0.1'),
                    env('DB_READ_HOST_3', '127.0.0.1'),
                ],
            ],
            'write' => [
                // Fallback to primary if write attempted
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_READ_USERNAME', 'forge'),
            'password' => env('DB_READ_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],
];
```

### Environment Configuration

**File**: `.env`

```env
# Primary Database (Write)
DB_CONNECTION=mysql
DB_HOST=primary.mysql.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=attendance
DB_USERNAME=admin
DB_PASSWORD=your-secure-password

# Read Replicas
DB_READ_HOST_1=replica1.mysql.rds.amazonaws.com
DB_READ_HOST_2=replica2.mysql.rds.amazonaws.com
DB_READ_HOST_3=replica3.mysql.rds.amazonaws.com
DB_READ_USERNAME=readonly
DB_READ_PASSWORD=your-readonly-password
```

---

## 🔀 Write Flow (CQRS Command)

### Attendance Check-In Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    WRITE PATH (Command)                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │  AttendanceController        │
        │  checkIn(Request)            │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  CheckInCommand              │
        │  (DTO)                       │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  AttendanceApplicationService│
        │  checkIn(CheckInCommand)     │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  DB::connection('mysql')     │ ← PRIMARY DB
        │  ->transaction(function() {  │
        │    Attendance::create([...]) │
        │  })                          │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  UNIQUE CONSTRAINT CHECK     │
        │  unique(schedule_id,         │
        │         student_id,          │
        │         attendance_date)     │
        └──────────┬───────────────────┘
                   │
         ┌─────────┴─────────┐
         │                   │
       SUCCESS            DUPLICATE
         │                   │
         ▼                   ▼
┌────────────────┐   ┌──────────────┐
│ Event Dispatch │   │  409 Error   │
│ (Async)        │   │  Conflict    │
└────────┬───────┘   └──────────────┘
         │
         ▼
┌────────────────────────────────────┐
│  UpdateAttendanceSummaryJob        │
│  (Queue)                           │
└────────────────────────────────────┘
```

---

## 📖 Read Flow (CQRS Query)

### Dashboard Query Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    READ PATH (Query)                         │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │  DashboardController         │
        │  index()                     │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  DashboardQueryService       │
        │  getTodaySummary()           │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  DB::connection('mysql_read')│ ← READ REPLICA
        │  ->table('attendance_daily_  │
        │    summaries')               │
        │  ->where('date', today())    │
        │  ->get()                     │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  Check Replication Lag       │
        │  (via monitoring)            │
        └──────────┬───────────────────┘
                   │
         ┌─────────┴─────────┐
         │                   │
      LAG <2s            LAG >2s
         │                   │
         ▼                   ▼
┌────────────────┐   ┌──────────────────┐
│ Return Data    │   │  Show "Updating" │
│                │   │  Fallback to     │
│                │   │  Primary DB      │
└────────────────┘   └──────────────────┘
```

---

## 🔄 Failover Strategy

### Promotion Steps

```
┌─────────────────────────────────────────────────────────────┐
│                    FAILOVER SEQUENCE                         │
│                    (Primary DB Failure)                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │  1. Detect Primary Failure   │
        │     - Health check timeout   │
        │     - Connection refused     │
        │     - Monitoring alert       │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  2. Stop All Write Operations│
        │     - Disable API endpoints  │
        │     - Stop queue workers     │
        │     - Return 503 errors      │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  3. Promote Read Replica     │
        │     - Stop replication       │
        │     - Disable read_only      │
        │     - Verify GTID position   │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  4. Update Configuration     │
        │     - Update .env            │
        │     - DB_HOST = replica1     │
        │     - Clear config cache     │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  5. Restart Application      │
        │     - Restart PHP-FPM        │
        │     - Restart queue workers  │
        │     - Verify connections     │
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  6. Verify System Health     │
        │     - Test write operation   │
        │     - Test read operation    │
        │     - Check unique constraint│
        └──────────┬───────────────────┘
                   │
                   ▼
        ┌──────────────────────────────┐
        │  7. Resume Operations        │
        │     - Enable API endpoints   │
        │     - Start queue workers    │
        │     - Monitor closely        │
        └──────────────────────────────┘
```

### Promotion Script

**File**: `scripts/promote-replica.sh`

```bash
#!/bin/bash

echo "🚨 MySQL Failover: Promoting Replica to Primary"
echo "================================================"

# Configuration
REPLICA_HOST="replica1.mysql.rds.amazonaws.com"
REPLICA_USER="admin"
REPLICA_PASS="your-password"

# 1. Stop replication
echo "1. Stopping replication..."
mysql -h $REPLICA_HOST -u $REPLICA_USER -p$REPLICA_PASS -e "STOP REPLICA;"
echo "✅ Replication stopped"

# 2. Disable read-only mode
echo "2. Disabling read-only mode..."
mysql -h $REPLICA_HOST -u $REPLICA_USER -p$REPLICA_PASS -e "SET GLOBAL read_only = 0;"
mysql -h $REPLICA_HOST -u $REPLICA_USER -p$REPLICA_PASS -e "SET GLOBAL super_read_only = 0;"
echo "✅ Read-only mode disabled"

# 3. Verify GTID position
echo "3. Verifying GTID position..."
mysql -h $REPLICA_HOST -u $REPLICA_USER -p$REPLICA_PASS -e "SHOW MASTER STATUS\G"

# 4. Update application configuration
echo "4. Updating application configuration..."
cd /var/www/attendance
sed -i "s/DB_HOST=.*/DB_HOST=$REPLICA_HOST/" .env
php artisan config:clear
echo "✅ Configuration updated"

# 5. Restart services
echo "5. Restarting services..."
sudo systemctl restart php8.2-fpm
sudo supervisorctl restart laravel-worker:*
echo "✅ Services restarted"

# 6. Verify health
echo "6. Verifying system health..."
curl -f http://localhost/health/system || exit 1
echo "✅ System healthy"

echo ""
echo "🎉 Failover complete! Replica promoted to primary."
echo "⚠️  Remember to:"
echo "   1. Fix original primary"
echo "   2. Set up new replica"
echo "   3. Update monitoring"
```

---

## ⏱️ Replication Lag Handling

### Monitoring Replication Lag

**File**: `app/Services/ReplicationLagMonitor.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReplicationLagMonitor
{
    /**
     * Get current replication lag in seconds
     * 
     * @return float
     */
    public static function getLag(): float
    {
        try {
            $result = DB::connection('mysql_read')
                ->select("SHOW SLAVE STATUS")[0] ?? null;

            if (!$result) {
                return 0;
            }

            return (float) ($result->Seconds_Behind_Master ?? 0);
        } catch (\Exception $e) {
            Log::error('Failed to get replication lag', [
                'error' => $e->getMessage(),
            ]);

            return 999; // Assume high lag on error
        }
    }

    /**
     * Check if replication lag is acceptable
     * 
     * @param float $threshold Threshold in seconds (default: 2)
     * @return bool
     */
    public static function isAcceptable(float $threshold = 2.0): bool
    {
        $lag = self::getLag();

        if ($lag > $threshold) {
            Log::warning('Replication lag exceeded threshold', [
                'lag_seconds' => $lag,
                'threshold' => $threshold,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Fallback to primary if lag too high
     * 
     * @return string Connection name to use
     */
    public static function getReadConnection(): string
    {
        if (self::isAcceptable()) {
            return 'mysql_read';
        }

        Log::info('Falling back to primary due to high replication lag');

        return 'mysql'; // Fallback to primary
    }
}
```

### Usage in Query Service

**File**: `app/Application/Services/DashboardQueryService.php`

```php
<?php

namespace App\Application\Services;

use App\ReadModels\AttendanceDailySummary;
use App\Services\ReplicationLagMonitor;
use Illuminate\Support\Collection;

class DashboardQueryService
{
    /**
     * Get today's attendance summary
     * 
     * @param int $schoolId
     * @return Collection
     */
    public function getTodaySummary(int $schoolId): Collection
    {
        // Determine which connection to use based on replication lag
        $connection = ReplicationLagMonitor::getReadConnection();

        $summary = AttendanceDailySummary::on($connection)
            ->where('school_id', $schoolId)
            ->where('attendance_date', today())
            ->first();

        // Add lag indicator to response
        $lag = ReplicationLagMonitor::getLag();
        $summary->replication_lag = $lag;
        $summary->is_updating = $lag > 2.0;

        return collect($summary);
    }
}
```

### Frontend Handling

**Response with lag indicator**:

```json
{
  "summary": {
    "total_students": 1250,
    "present": 1180,
    "absent": 70,
    "late": 45,
    "replication_lag": 1.5,
    "is_updating": false
  }
}
```

**If lag >2 seconds**:

```json
{
  "summary": {
    "total_students": 1250,
    "present": 1180,
    "absent": 70,
    "late": 45,
    "replication_lag": 3.2,
    "is_updating": true,
    "message": "Data is updating, please refresh in a moment"
  }
}
```

---

## 🛡️ Duplicate Prevention

### Ultimate Protection: Unique Constraint

**Migration**:

```php
Schema::create('attendances', function (Blueprint $table) {
    $table->id();
    $table->foreignId('school_id')->constrained();
    $table->foreignId('schedule_id')->constrained();
    $table->foreignId('student_id')->constrained();
    $table->date('attendance_date');
    $table->enum('status', ['present', 'late', 'absent']);
    $table->timestamp('check_in_time')->nullable();
    $table->timestamps();

    // ULTIMATE DUPLICATE PREVENTION
    $table->unique(
        ['schedule_id', 'student_id', 'attendance_date'],
        'unique_attendance_per_day'
    );
});
```

**Why this works even during failover**:

1. **Constraint lives in database** - Not application logic
2. **Enforced at storage engine level** - InnoDB guarantees uniqueness
3. **Survives replication** - Constraint replicated to all replicas
4. **Works after promotion** - New primary inherits constraint
5. **Atomic check** - Checked within transaction

---

## 📊 Failure Matrix

| Failure Scenario | Impact | Detection | Recovery | Data Loss | Duplicates |
|-----------------|--------|-----------|----------|-----------|------------|
| **Primary DB down** | Critical | <30 sec | Promote replica | Minimal (<1 sec) | ❌ No (unique constraint) |
| **Replica down** | Low | <30 sec | Use other replica | None | ❌ No |
| **Replication lag >10s** | Medium | Real-time | Fallback to primary | None | ❌ No |
| **Network partition** | High | <30 sec | Reconnect/failover | Minimal | ❌ No (unique constraint) |
| **Replica promotion** | Medium | Manual | <2 min | None | ❌ No (constraint preserved) |
| **Split-brain** | Critical | Monitoring | Manual intervention | Potential | ❌ No (unique constraint) |
| **Binlog corruption** | High | Monitoring | PITR | Depends on backup | ❌ No (restore includes constraint) |

---

**Status**: ✅ Ready for Implementation  
**Last Updated**: 2026-02-10  
**Version**: 1.0
