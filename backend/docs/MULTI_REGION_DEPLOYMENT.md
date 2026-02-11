# 🌍 Multi-Region Deployment Strategy

**Cloud Architect Tier-1**  
**Date**: 2026-02-10  
**Purpose**: High-availability SaaS deployment for Laravel Attendance System

---

## 🎯 Objectives

Design multi-region deployment strategy to achieve:
1. **99.99% uptime** (52 minutes downtime/year)
2. **<100ms latency** for geo-distributed users
3. **Automatic failover** (<2 minutes)
4. **Zero data loss** (RPO = 0)
5. **Scalable to 1M+ users**

---

## 📊 Current State

```
┌─────────────────────────────────────────────────────────────┐
│                    SINGLE REGION (Current)                   │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │  Laravel API │  │    Redis     │  │    MySQL     │     │
│  │   (App)      │  │   (Cache)    │  │  (Primary)   │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐                        │
│  │Queue Worker  │  │   Storage    │                        │
│  │  (Horizon)   │  │     (S3)     │                        │
│  └──────────────┘  └──────────────┘                        │
│                                                              │
│  Limitations:                                                │
│  - Single point of failure                                   │
│  - No disaster recovery                                      │
│  - High latency for distant users                           │
└─────────────────────────────────────────────────────────────┘
```

**Risks**:
- ❌ Region outage = Complete downtime
- ❌ Database failure = Data loss
- ❌ No geographic redundancy

---

## 🚀 Target Architecture

### Phase 1: Active-Passive (Safe Evolution)
**Timeline**: Month 1-3  
**Goal**: Disaster recovery capability

### Phase 2: Active-Active (Geo Scalable)
**Timeline**: Month 4-6  
**Goal**: Global scalability and low latency

---

## 📍 Phase 1: Active-Passive Deployment

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          GLOBAL LAYER                                        │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │                    Route 53 (DNS)                                   │    │
│  │  - Health checks every 30 sec                                       │    │
│  │  - Automatic failover to Region B if Region A fails                │    │
│  └────────────────────────┬───────────────────────────────────────────┘    │
│                            │                                                 │
│              ┌─────────────┴─────────────┐                                  │
│              │                           │                                   │
│              ▼                           ▼                                   │
│  ┌───────────────────────┐   ┌───────────────────────┐                     │
│  │   REGION A (Primary)  │   │  REGION B (Secondary) │                     │
│  │   us-east-1           │   │   ap-southeast-1      │                     │
│  │   ✅ ACTIVE           │   │   💤 STANDBY          │                     │
│  └───────────────────────┘   └───────────────────────┘                     │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                        REGION A (PRIMARY)                                    │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │                    CloudFront CDN                                   │    │
│  │  - Static assets                                                    │    │
│  │  - API caching                                                      │    │
│  └────────────────────────┬───────────────────────────────────────────┘    │
│                            │                                                 │
│                            ▼                                                 │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │              Application Load Balancer (ALB)                        │    │
│  │  - SSL termination                                                  │    │
│  │  - Health checks                                                    │    │
│  └────────────────────────┬───────────────────────────────────────────┘    │
│                            │                                                 │
│              ┌─────────────┴─────────────┐                                  │
│              │                           │                                   │
│              ▼                           ▼                                   │
│  ┌───────────────────────┐   ┌───────────────────────┐                     │
│  │   Laravel App (ECS)   │   │   Laravel App (ECS)   │                     │
│  │   - Auto-scaling      │   │   - Auto-scaling      │                     │
│  │   - Min: 2, Max: 10   │   │   - Min: 2, Max: 10   │                     │
│  └───────────┬───────────┘   └───────────┬───────────┘                     │
│              │                           │                                   │
│              └─────────────┬─────────────┘                                  │
│                            │                                                 │
│              ┌─────────────┴─────────────┬─────────────┐                   │
│              │                           │             │                     │
│              ▼                           ▼             ▼                     │
│  ┌───────────────────┐   ┌───────────────────┐   ┌─────────────┐          │
│  │  RDS MySQL        │   │  ElastiCache      │   │  SQS Queue  │          │
│  │  (Primary)        │   │  Redis            │   │  + Workers  │          │
│  │  - Multi-AZ       │   │  (Primary)        │   │             │          │
│  │  - Auto backup    │   │  - Cluster mode   │   │             │          │
│  │  ├─ Replication ──┼───┼─> Region B       │   │             │          │
│  └───────────────────┘   └───────────────────┘   └─────────────┘          │
│              │                                                               │
│              ▼                                                               │
│  ┌───────────────────────────────────────────────────────────────────┐     │
│  │                    S3 (Primary Storage)                            │     │
│  │  - Versioning enabled                                              │     │
│  │  - Cross-region replication to Region B                           │     │
│  └───────────────────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                        REGION B (SECONDARY)                                  │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │              Application Load Balancer (ALB)                        │    │
│  │  - Standby (receives traffic only on failover)                     │    │
│  └────────────────────────┬───────────────────────────────────────────┘    │
│                            │                                                 │
│                            ▼                                                 │
│  ┌───────────────────────────────────────────────────────────────────┐     │
│  │   Laravel App (ECS) - STANDBY                                      │     │
│  │   - Min: 1 (cost optimization)                                     │     │
│  │   - Scales up on failover                                          │     │
│  └───────────┬───────────────────────────────────────────────────────┘     │
│              │                                                               │
│              ┌─────────────┴─────────────┬─────────────┐                   │
│              │                           │             │                     │
│              ▼                           ▼             ▼                     │
│  ┌───────────────────┐   ┌───────────────────┐   ┌─────────────┐          │
│  │  RDS MySQL        │   │  ElastiCache      │   │  SQS Queue  │          │
│  │  (Read Replica)   │   │  Redis            │   │  (Standby)  │          │
│  │  - Read-only      │   │  (Replica)        │   │             │          │
│  │  - Async repl.    │   │  - Read-only      │   │             │          │
│  │  - Can promote    │   │  - Can promote    │   │             │          │
│  └───────────────────┘   └───────────────────┘   └─────────────┘          │
│                                                                              │
│  ┌───────────────────────────────────────────────────────────────────┐     │
│  │                    S3 (Replica Storage)                            │     │
│  │  - Receives replicated data from Region A                         │     │
│  └───────────────────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Failover Logic

### Automatic Failover Triggers

```
┌─────────────────────────────────────────────────────────────┐
│                    HEALTH CHECK MONITOR                      │
│                    (Every 30 seconds)                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │  Health Check Failed?        │
        │  - DB unreachable            │
        │  - Redis unavailable         │
        │  - App unresponsive >30s     │
        └──────────┬───────────────────┘
                   │
         ┌─────────┴─────────┐
         │                   │
        YES                 NO
         │                   │
         ▼                   ▼
┌────────────────┐   ┌──────────────┐
│ TRIGGER        │   │  CONTINUE    │
│ FAILOVER       │   │  MONITORING  │
└────────┬───────┘   └──────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────┐
│                    FAILOVER SEQUENCE                         │
│                                                              │
│  1. Alert DevOps team                                       │
│  2. Promote MySQL replica to primary (Region B)             │
│  3. Promote Redis replica to primary (Region B)             │
│  4. Update DNS (Route 53) to Region B                       │
│  5. Scale up ECS tasks in Region B                          │
│  6. Start queue workers in Region B                         │
│  7. Verify health checks                                    │
│  8. Confirm failover complete                               │
│                                                              │
│  Total Time: <2 minutes                                     │
└─────────────────────────────────────────────────────────────┘
```

### Failover Decision Matrix

| Condition | Duration | Action | RTO |
|-----------|----------|--------|-----|
| **App unresponsive** | >30 sec | Failover | <2 min |
| **DB unreachable** | >30 sec | Failover | <2 min |
| **Redis unavailable** | >30 sec | Failover | <2 min |
| **High error rate** | >5% for 2 min | Failover | <2 min |
| **Region outage** | Immediate | Failover | <2 min |

---

## 🏥 Health Check System

### Enhanced Health Endpoint

**Endpoint**: `GET /health/system`

**Implementation**:

```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class SystemHealthController extends Controller
{
    /**
     * Comprehensive system health check
     * 
     * Used by Route 53 for failover decisions
     */
    public function index(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'disk' => $this->checkDisk(),
        ];

        $allHealthy = collect($checks)->every(fn($status) => $status === 'ok');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'region' => config('app.region', 'unknown'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            // Test read
            DB::connection()->getPdo();
            
            // Test write
            DB::table('health_checks')->updateOrInsert(
                ['key' => 'health_check'],
                ['value' => now()->toDateTimeString(), 'updated_at' => now()]
            );

            return 'ok';
        } catch (\Exception $e) {
            \Log::error('Database health check failed', ['error' => $e->getMessage()]);
            return 'error';
        }
    }

    private function checkRedis(): string
    {
        try {
            // Test connection
            Redis::ping();
            
            // Test write
            Cache::put('health_check', now()->toDateTimeString(), 60);
            
            // Test read
            $value = Cache::get('health_check');

            return $value ? 'ok' : 'error';
        } catch (\Exception $e) {
            \Log::error('Redis health check failed', ['error' => $e->getMessage()]);
            return 'error';
        }
    }

    private function checkQueue(): string
    {
        try {
            // Test queue push
            Queue::push(new \App\Jobs\HealthCheckJob());

            return 'ok';
        } catch (\Exception $e) {
            \Log::error('Queue health check failed', ['error' => $e->getMessage()]);
            return 'error';
        }
    }

    private function checkStorage(): string
    {
        try {
            // Test S3 write
            Storage::put('health_check.txt', now()->toDateTimeString());
            
            // Test S3 read
            $content = Storage::get('health_check.txt');

            return $content ? 'ok' : 'error';
        } catch (\Exception $e) {
            \Log::error('Storage health check failed', ['error' => $e->getMessage()]);
            return 'error';
        }
    }

    private function checkDisk(): string
    {
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            $diskUsagePercent = (1 - ($diskFree / $diskTotal)) * 100;

            // Alert if disk >90% full
            if ($diskUsagePercent > 90) {
                \Log::warning('Disk usage critical', ['usage' => $diskUsagePercent]);
                return 'warning';
            }

            return 'ok';
        } catch (\Exception $e) {
            \Log::error('Disk health check failed', ['error' => $e->getMessage()]);
            return 'error';
        }
    }
}
```

**Response Example**:

```json
{
  "status": "healthy",
  "region": "us-east-1",
  "checks": {
    "db": "ok",
    "redis": "ok",
    "queue": "ok",
    "storage": "ok",
    "disk": "ok"
  },
  "timestamp": "2026-02-10T11:00:00+08:00"
}
```

---

## 📍 Phase 2: Active-Active Deployment

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          GLOBAL LAYER                                        │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │              AWS Global Accelerator / CloudFront                    │    │
│  │  - Geo-routing based on user location                               │    │
│  │  - Sticky sessions for write operations                             │    │
│  │  - Automatic failover                                               │    │
│  └────────────────────────┬───────────────────────────────────────────┘    │
│                            │                                                 │
│              ┌─────────────┴─────────────┐                                  │
│              │                           │                                   │
│              ▼                           ▼                                   │
│  ┌───────────────────────┐   ┌───────────────────────┐                     │
│  │   REGION A (Active)   │   │   REGION B (Active)   │                     │
│  │   us-east-1           │   │   ap-southeast-1      │                     │
│  │   ✅ LIVE             │   │   ✅ LIVE             │                     │
│  │   - Serves US/EU      │   │   - Serves APAC       │                     │
│  └───────────────────────┘   └───────────────────────┘                     │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                        DATABASE LAYER                                        │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │              MySQL Group Replication (Multi-Master)                 │    │
│  │                                                                      │    │
│  │  ┌──────────────────┐         ┌──────────────────┐                │    │
│  │  │  Region A        │◄───────►│  Region B        │                │    │
│  │  │  MySQL Primary   │  Sync   │  MySQL Primary   │                │    │
│  │  │  (Write/Read)    │  Repl.  │  (Write/Read)    │                │    │
│  │  └──────────────────┘         └──────────────────┘                │    │
│  │          │                              │                           │    │
│  │          ▼                              ▼                           │    │
│  │  ┌──────────────────┐         ┌──────────────────┐                │    │
│  │  │  Read Replica 1  │         │  Read Replica 1  │                │    │
│  │  │  (Dashboard)     │         │  (Dashboard)     │                │    │
│  │  └──────────────────┘         └──────────────────┘                │    │
│  │                                                                      │    │
│  │  Write Strategy: Single Master (Region A)                          │    │
│  │  Read Strategy: Local replica per region                           │    │
│  └────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 📊 Data Consistency Policy

### Consistency Requirements

| Operation | Consistency Level | Strategy |
|-----------|------------------|----------|
| **Attendance Write** | Strong | Write to master only |
| **Dashboard Read** | Eventual | Read from local replica |
| **Subscription Validation** | Strong | Read from master |
| **QR Generation** | Strong | Write to master |
| **Webhook Processing** | Strong | Write to master with idempotency |
| **Report Generation** | Eventual | Read from replica |

### Write Routing Strategy

```
┌─────────────────────────────────────────────────────────────┐
│                    WRITE REQUEST                             │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │  Determine Operation Type     │
        └──────────┬───────────────────┘
                   │
         ┌─────────┴─────────┐
         │                   │
    ATTENDANCE          DASHBOARD
      WRITE               READ
         │                   │
         ▼                   ▼
┌────────────────┐   ┌──────────────┐
│ Route to       │   │  Route to    │
│ MASTER         │   │  LOCAL       │
│ (Region A)     │   │  REPLICA     │
│                │   │  (Any Region)│
│ - Sticky       │   │              │
│   session      │   │  - Fast      │
│ - Consistent   │   │  - Eventual  │
└────────────────┘   └──────────────┘
```

### Configuration

**File**: `config/database.php`

```php
'connections' => [
    // Write connection (always master)
    'mysql_write' => [
        'driver' => 'mysql',
        'host' => env('DB_WRITE_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'forge'),
        'username' => env('DB_USERNAME', 'forge'),
        'password' => env('DB_PASSWORD', ''),
        'sticky' => true, // Sticky connection for writes
    ],

    // Read connection (local replica)
    'mysql_read' => [
        'driver' => 'mysql',
        'read' => [
            'host' => [
                env('DB_READ_HOST_1', '127.0.0.1'),
                env('DB_READ_HOST_2', '127.0.0.1'),
            ],
        ],
        'write' => [
            'host' => [env('DB_WRITE_HOST', '127.0.0.1')],
        ],
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'forge'),
        'username' => env('DB_USERNAME', 'forge'),
        'password' => env('DB_PASSWORD', ''),
    ],
],
```

---

## ⚠️ Risk Analysis

### Risk Table

| Risk | Probability | Impact | Mitigation | RTO | RPO |
|------|------------|--------|------------|-----|-----|
| **Region A outage** | Low | Critical | Auto-failover to Region B | <2 min | 0 |
| **Database corruption** | Very Low | Critical | Point-in-time recovery | <15 min | <5 min |
| **Network partition** | Low | High | Multi-region deployment | <2 min | 0 |
| **DDoS attack** | Medium | High | CloudFront + WAF | <1 min | 0 |
| **Data center failure** | Very Low | Critical | Multi-AZ deployment | <1 min | 0 |
| **Replication lag** | Medium | Medium | Monitor lag, alert if >10s | N/A | <10 sec |
| **Split-brain scenario** | Very Low | Critical | Consensus-based failover | <5 min | 0 |
| **DNS propagation delay** | Low | Medium | Low TTL (60s) | <2 min | 0 |

---

## 🔄 Rollback Strategy

### Failback to Region A

```
┌─────────────────────────────────────────────────────────────┐
│                    FAILBACK SEQUENCE                         │
│                                                              │
│  1. Verify Region A fully recovered                         │
│  2. Sync data from Region B to Region A                     │
│  3. Verify data consistency                                 │
│  4. Update DNS to Region A (gradual)                        │
│  5. Monitor for 30 minutes                                  │
│  6. Demote Region B to standby                              │
│  7. Confirm failback complete                               │
│                                                              │
│  Total Time: ~30 minutes (gradual)                          │
└─────────────────────────────────────────────────────────────┘
```

### Rollback Decision Matrix

| Scenario | Action | Timeline |
|----------|--------|----------|
| **Failover caused issues** | Immediate rollback | <5 min |
| **Data inconsistency detected** | Pause, investigate | <15 min |
| **Performance degradation** | Gradual rollback | <30 min |
| **Region A recovered** | Planned failback | <60 min |

---

**Status**: ✅ Ready for Implementation  
**Last Updated**: 2026-02-10  
**Version**: 1.0
