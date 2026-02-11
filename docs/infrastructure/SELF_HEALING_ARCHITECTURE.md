# Autonomous Self-Healing Infrastructure

## Executive Summary

This document defines the autonomous self-healing infrastructure for the Laravel multi-tenant attendance system. The system automatically detects, classifies, and remediates common failures without manual intervention.

**Recovery SLA Targets:**
- Transient failures: < 10 seconds
- Resource exhaustion: < 60 seconds
- Dependency failures: < 30 seconds
- Data corruption: Immediate freeze + manual review

---

## LAYER 1 – HEALTH CHECK AUTOMATION

### Deep Health Check Endpoint

**Endpoint:** `GET /health/deep`

**Authorization:** Internal only (IP whitelist + secret token)

**Response Schema:**
```json
{
  "status": "healthy|degraded|critical",
  "timestamp": "2026-02-11T09:04:37+08:00",
  "checks": {
    "database": {
      "status": "healthy",
      "read_latency_ms": 12,
      "write_latency_ms": 18,
      "connection_pool": {
        "active": 5,
        "idle": 15,
        "max": 20
      },
      "replication_lag_seconds": 0.3
    },
    "redis": {
      "status": "healthy",
      "memory_used_mb": 450,
      "memory_max_mb": 1024,
      "memory_percent": 43.9,
      "connected_clients": 12,
      "evicted_keys": 0,
      "lock_test": "success"
    },
    "queue": {
      "status": "healthy",
      "pending_jobs": 45,
      "failed_jobs": 2,
      "workers_active": 4,
      "oldest_job_age_seconds": 12,
      "push_pop_test": "success"
    },
    "disk": {
      "status": "healthy",
      "used_percent": 62,
      "available_gb": 120,
      "threshold_percent": 85
    },
    "memory": {
      "status": "healthy",
      "used_percent": 58,
      "available_mb": 2048,
      "threshold_percent": 90
    }
  },
  "tenant_isolation": {
    "status": "healthy",
    "test_query_isolated": true
  }
}
```

**Health Status Classification:**
- `healthy`: All checks pass
- `degraded`: 1-2 non-critical checks fail
- `critical`: Critical check fails (DB write, Redis lock, Queue push)

---

## LAYER 2 – AUTO REMEDIATION RULES

### Remediation Decision Tree

```
┌─────────────────────────────────────────────────────────────┐
│                    FAILURE DETECTED                          │
└─────────────────────┬───────────────────────────────────────┘
                      │
                      ▼
              ┌───────────────┐
              │  Classify     │
              │  Failure Type │
              └───────┬───────┘
                      │
        ┌─────────────┼─────────────┬─────────────┐
        │             │             │             │
        ▼             ▼             ▼             ▼
   Transient    Resource      Dependency     Data
   Failure      Exhaustion    Failure        Corruption
        │             │             │             │
        ▼             ▼             ▼             ▼
   Retry with    Auto Scale    Circuit        Freeze Write
   Backoff       Resources     Breaker        + Alert
        │             │             │             │
        ▼             ▼             ▼             ▼
   Success?      Monitor       Fallback       Manual
   Yes → Log     Metrics       Mode           Review
   No → Escalate
```

### Auto Remediation Rule Table

| **Condition** | **Threshold** | **Action** | **Cooldown** | **Max Attempts** |
|---------------|---------------|------------|--------------|------------------|
| Queue lag high | > 500 jobs pending for > 2 min | Scale worker +1 instance | 5 min | 3 |
| Queue lag critical | > 2000 jobs pending | Scale worker +2 instances | 5 min | 2 |
| Redis memory high | > 85% used | Flush non-critical cache (sessions preserved) | 10 min | 1 |
| Redis memory critical | > 95% used | Scale Redis node (if cloud) OR emergency flush | 15 min | 1 |
| DB connection spike | Active connections > 80% of max | Enable read-replica routing | 3 min | Unlimited |
| DB slow queries | P95 latency > 500ms for > 1 min | Switch to degraded mode (disable heavy queries) | 5 min | Unlimited |
| App container crash | Exit code != 0 | Restart container | 30 sec | 5 |
| App memory leak | Memory growth > 20% in 5 min | Graceful restart | 10 min | 3 |
| Disk usage high | > 85% used | Trigger log rotation + temp file cleanup | 1 hour | 1 |
| Replication lag high | > 10 seconds | Alert + disable read replica routing | 2 min | Unlimited |
| Failed jobs spike | > 50 failed jobs in 5 min | Pause queue + alert | Manual | 1 |

---

## LAYER 3 – FAILURE CLASSIFICATION

### Failure Matrix

| **Failure Type** | **Symptoms** | **Classification** | **Auto Action** | **Manual Escalation** |
|------------------|--------------|--------------------|-----------------|-----------------------|
| Redis timeout | Connection refused, timeout | Transient | Retry 3x with exponential backoff | After 3 failures |
| Redis OOM | Memory > 95%, eviction spike | Resource exhaustion | Scale node OR flush cache | If scaling fails |
| DB deadlock | `SQLSTATE[40001]` | Transient | Retry with jitter | After 5 retries |
| DB connection pool exhausted | Max connections reached | Resource exhaustion | Enable read replica routing | If persists > 5 min |
| DB primary down | Connection refused | Dependency failure | Promote replica (if auto-failover enabled) | Immediately |
| Queue worker crash | Worker exit code 137 (OOM) | Resource exhaustion | Increase worker memory limit | If crashes > 3x |
| Queue worker crash | Worker exit code 1 (error) | Transient | Restart worker | After 5 restarts |
| Duplicate attendance | Same `qr_nonce` used twice | Data corruption | Reject + log security event | Immediately |
| Tenant isolation breach | Query returns cross-tenant data | Data corruption | Freeze write + emergency alert | Immediately |
| Disk full | Write fails with ENOSPC | Resource exhaustion | Emergency cleanup + alert | Immediately |
| Network partition | Timeout to external service | Dependency failure | Circuit breaker open | After 3 failures |

### Classification Logic

```php
class FailureClassifier
{
    public function classify(Throwable $e, array $context): FailureType
    {
        // Transient failures
        if ($this->isTransient($e)) {
            return FailureType::TRANSIENT;
        }
        
        // Resource exhaustion
        if ($this->isResourceExhaustion($e, $context)) {
            return FailureType::RESOURCE_EXHAUSTION;
        }
        
        // Dependency failures
        if ($this->isDependencyFailure($e)) {
            return FailureType::DEPENDENCY_FAILURE;
        }
        
        // Data corruption
        if ($this->isDataCorruption($e, $context)) {
            return FailureType::DATA_CORRUPTION;
        }
        
        return FailureType::UNKNOWN;
    }
    
    private function isTransient(Throwable $e): bool
    {
        return $e instanceof DeadlockException
            || $e instanceof LockTimeoutException
            || $e instanceof ConnectionException && str_contains($e->getMessage(), 'timeout');
    }
    
    private function isResourceExhaustion(Throwable $e, array $context): bool
    {
        return $e instanceof OutOfMemoryException
            || ($context['redis_memory_percent'] ?? 0) > 95
            || ($context['db_connection_percent'] ?? 0) > 90
            || ($context['disk_usage_percent'] ?? 0) > 95;
    }
    
    private function isDependencyFailure(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || $e instanceof ServiceUnavailableException;
    }
    
    private function isDataCorruption(Throwable $e, array $context): bool
    {
        return $context['duplicate_nonce'] ?? false
            || $context['tenant_isolation_breach'] ?? false;
    }
}
```

---

## LAYER 4 – CIRCUIT BREAKER POLICY

### Redis Circuit Breaker

**Configuration:**
```php
// config/circuit-breaker.php
return [
    'redis' => [
        'failure_threshold' => 3,        // Open after 3 failures
        'success_threshold' => 2,        // Close after 2 successes
        'timeout' => 10,                 // Retry after 10 seconds
        'fallback' => 'database_cache',  // Use DB cache as fallback
    ],
];
```

**State Machine:**
```
CLOSED (normal) 
    ↓ (3 failures)
OPEN (reject all)
    ↓ (10 sec timeout)
HALF_OPEN (test 1 request)
    ↓ (2 successes)     ↓ (1 failure)
CLOSED                OPEN
```

**Implementation:**
```php
class RedisCircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';
    
    public function call(callable $operation, array $context = [])
    {
        $state = $this->getState();
        
        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->setState(self::STATE_HALF_OPEN);
            } else {
                $this->recordRejection($context);
                throw new CircuitBreakerOpenException('Redis circuit breaker is OPEN');
            }
        }
        
        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($e, $context);
            throw $e;
        }
    }
    
    private function recordFailure(Throwable $e, array $context): void
    {
        $failures = Cache::increment('circuit_breaker:redis:failures');
        
        if ($failures >= config('circuit-breaker.redis.failure_threshold')) {
            $this->setState(self::STATE_OPEN);
            Cache::put('circuit_breaker:redis:opened_at', now(), 3600);
            
            // Log event
            Log::critical('Redis circuit breaker OPENED', [
                'failures' => $failures,
                'last_error' => $e->getMessage(),
                'context' => $context,
            ]);
            
            // Send alert
            event(new CircuitBreakerOpened('redis', $e, $context));
        }
    }
    
    private function recordSuccess(): void
    {
        $state = $this->getState();
        
        if ($state === self::STATE_HALF_OPEN) {
            $successes = Cache::increment('circuit_breaker:redis:successes');
            
            if ($successes >= config('circuit-breaker.redis.success_threshold')) {
                $this->setState(self::STATE_CLOSED);
                Cache::forget('circuit_breaker:redis:failures');
                Cache::forget('circuit_breaker:redis:successes');
                
                Log::info('Redis circuit breaker CLOSED (recovered)');
                event(new CircuitBreakerClosed('redis'));
            }
        } else {
            Cache::forget('circuit_breaker:redis:failures');
        }
    }
}
```

### Database Circuit Breaker

**Slow Query Detection:**
```php
// If DB query P95 latency > 500ms for > 1 min
if ($this->dbLatencyP95 > 500 && $this->dbSlowDuration > 60) {
    $this->enableDegradedMode();
}

private function enableDegradedMode(): void
{
    Cache::put('system:degraded_mode', true, 600); // 10 min
    
    // Disable heavy queries
    config(['dashboard.disable_heavy_aggregations' => true]);
    
    Log::warning('System entered DEGRADED MODE due to slow DB queries');
    event(new SystemDegradedModeEnabled('slow_db_queries'));
}
```

**Degraded Mode Behavior:**
- Disable real-time dashboard aggregations
- Serve cached dashboard data (up to 5 min stale)
- Disable admin report exports
- Disable bulk operations
- Show degraded mode banner to users

---

## LAYER 5 – AUTO SCALING

### Horizontal Pod Autoscaler (Kubernetes)

**Queue Worker Autoscaling:**
```yaml
# k8s/hpa-queue-worker.yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: attendance-queue-worker
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: attendance-queue-worker
  minReplicas: 2
  maxReplicas: 10
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
  - type: Pods
    pods:
      metric:
        name: queue_lag_jobs
      target:
        type: AverageValue
        averageValue: "100"  # Scale if avg queue lag > 100 jobs per worker
  behavior:
    scaleUp:
      stabilizationWindowSeconds: 60
      policies:
      - type: Percent
        value: 50
        periodSeconds: 60
      - type: Pods
        value: 2
        periodSeconds: 60
      selectPolicy: Max
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
      - type: Percent
        value: 10
        periodSeconds: 60
```

**Web Application Autoscaling:**
```yaml
# k8s/hpa-web-app.yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: attendance-web-app
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: attendance-web-app
  minReplicas: 3
  maxReplicas: 20
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 60
  - type: Pods
    pods:
      metric:
        name: http_requests_per_second
      target:
        type: AverageValue
        averageValue: "50"  # Scale if avg RPS > 50 per pod
  behavior:
    scaleUp:
      stabilizationWindowSeconds: 30
      policies:
      - type: Percent
        value: 100
        periodSeconds: 30
      selectPolicy: Max
    scaleDown:
      stabilizationWindowSeconds: 600  # 10 min cooldown before scale down
      policies:
      - type: Percent
        value: 10
        periodSeconds: 60
```

### Auto Scaling Rule Table

| **Component** | **Scale Trigger** | **Min** | **Max** | **Scale Up Policy** | **Scale Down Policy** |
|---------------|-------------------|---------|---------|---------------------|-----------------------|
| Queue Worker | Queue lag > 100 jobs/worker OR CPU > 70% | 2 | 10 | +50% or +2 pods (max), 60s stabilization | -10%, 300s stabilization |
| Web App | RPS > 50/pod OR CPU > 60% | 3 | 20 | +100%, 30s stabilization | -10%, 600s stabilization |
| Redis | Memory > 85% | 1 | 3 | +1 node, manual approval | Manual only |
| DB Read Replica | Replication lag > 5s OR read RPS > 1000 | 1 | 3 | +1 replica, 120s stabilization | Manual only |

### Custom Metrics Exporter

**Queue Lag Metric:**
```php
// app/Services/MetricsExporter.php
class MetricsExporter
{
    public function exportQueueLag(): void
    {
        $pendingJobs = DB::table('jobs')->count();
        $activeWorkers = $this->getActiveWorkerCount();
        
        $lagPerWorker = $activeWorkers > 0 ? $pendingJobs / $activeWorkers : $pendingJobs;
        
        // Export to Prometheus
        Prometheus::gauge('queue_lag_jobs', $lagPerWorker, [
            'queue' => 'default',
        ]);
    }
    
    public function exportHttpRps(): void
    {
        $rps = Cache::get('metrics:http_rps', 0);
        
        Prometheus::gauge('http_requests_per_second', $rps, [
            'app' => 'attendance',
        ]);
    }
}
```

---

## LAYER 6 – CHAOS TESTING

### Chaos Test Scenarios

#### Test 1: Kill Redis Node
**Objective:** Verify circuit breaker opens and system falls back to DB cache

**Steps:**
1. Simulate Redis crash: `docker stop redis-primary`
2. Trigger attendance scan
3. Verify circuit breaker opens after 3 failures
4. Verify attendance write falls back to DB cache
5. Verify no data loss
6. Restore Redis: `docker start redis-primary`
7. Verify circuit breaker closes after 10 sec + 2 successes

**Success Criteria:**
- Circuit breaker opens within 10 seconds
- All attendance writes succeed (using fallback)
- No duplicate attendance records
- Circuit breaker auto-recovers within 30 seconds of Redis restoration

#### Test 2: Kill DB Primary
**Objective:** Verify auto-failover to replica (if configured)

**Steps:**
1. Simulate DB primary crash: `docker stop mysql-primary`
2. Trigger attendance scan
3. Verify auto-failover to replica (if using ProxySQL/Orchestrator)
4. Verify attendance write succeeds on new primary
5. Verify no data loss

**Success Criteria:**
- Failover completes within 30 seconds
- All writes succeed after failover
- No duplicate attendance records
- Replication lag < 1 second after failover

#### Test 3: Kill 2 Worker Nodes
**Objective:** Verify queue jobs are redistributed and autoscaler responds

**Steps:**
1. Kill 2 worker pods: `kubectl delete pod worker-1 worker-2`
2. Monitor queue lag
3. Verify autoscaler adds new workers
4. Verify jobs are processed without loss

**Success Criteria:**
- Queue lag does not exceed 500 jobs
- Autoscaler adds 2 new workers within 90 seconds
- All jobs complete successfully
- No failed jobs due to worker crash

#### Test 4: Network Partition (10 sec)
**Objective:** Verify circuit breaker handles network issues

**Steps:**
1. Simulate network partition: `iptables -A OUTPUT -d <redis-ip> -j DROP`
2. Trigger attendance scan
3. Verify circuit breaker opens
4. Restore network: `iptables -D OUTPUT -d <redis-ip> -j DROP`
5. Verify circuit breaker auto-recovers

**Success Criteria:**
- Circuit breaker opens within 10 seconds
- No attendance writes fail (fallback used)
- Circuit breaker recovers within 30 seconds of network restoration
- No duplicate attendance records

### Chaos Test Automation

**Chaos Test Schedule:**
```yaml
# k8s/chaos-test-schedule.yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: chaos-test-redis
spec:
  schedule: "0 2 * * 0"  # Every Sunday at 2 AM
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: chaos-test
            image: attendance/chaos-test:latest
            command:
            - /bin/sh
            - -c
            - |
              echo "Starting Redis chaos test"
              kubectl delete pod -l app=redis
              sleep 60
              kubectl wait --for=condition=ready pod -l app=redis --timeout=120s
              echo "Redis chaos test complete"
          restartPolicy: OnFailure
```

---

## RECOVERY SLA

### Service Level Objectives

| **Failure Scenario** | **Detection Time** | **Remediation Time** | **Total Recovery Time** | **Data Loss Tolerance** |
|----------------------|--------------------|-----------------------|-------------------------|-------------------------|
| Redis timeout | < 5 sec | < 5 sec (retry) | < 10 sec | 0 records |
| Redis OOM | < 10 sec | < 50 sec (flush/scale) | < 60 sec | 0 records |
| DB deadlock | < 1 sec | < 5 sec (retry) | < 6 sec | 0 records |
| DB connection exhaustion | < 10 sec | < 20 sec (enable replica) | < 30 sec | 0 records |
| DB primary down | < 15 sec | < 30 sec (failover) | < 45 sec | 0 records |
| Queue worker crash | < 5 sec | < 30 sec (restart) | < 35 sec | 0 jobs |
| Network partition | < 10 sec | < 20 sec (circuit breaker) | < 30 sec | 0 records |
| Disk full | < 5 sec | < 60 sec (cleanup) | < 65 sec | 0 records |
| Tenant isolation breach | < 1 sec | Immediate freeze | Manual review | 0 records |
| Duplicate attendance | < 1 sec | Immediate reject | Immediate | 0 records |

### Monitoring & Alerting

**Critical Alerts (PagerDuty):**
- Circuit breaker opened
- Auto-failover triggered
- Tenant isolation breach detected
- Data corruption detected
- Auto-remediation failed after max attempts

**Warning Alerts (Slack):**
- Auto-scaling triggered
- Degraded mode enabled
- Replication lag > 5 seconds
- Failed jobs > 50 in 5 min

---

## Implementation Checklist

- [ ] Implement `/health/deep` endpoint
- [ ] Create `FailureClassifier` service
- [ ] Implement Redis circuit breaker
- [ ] Implement DB circuit breaker
- [ ] Configure Kubernetes HPA for workers
- [ ] Configure Kubernetes HPA for web app
- [ ] Export custom metrics (queue lag, RPS)
- [ ] Create auto-remediation service
- [ ] Implement degraded mode logic
- [ ] Set up chaos testing framework
- [ ] Configure PagerDuty integration
- [ ] Document runbooks for manual escalation
- [ ] Train team on self-healing behavior
- [ ] Schedule monthly chaos tests

---

## Next Steps

1. **Phase 1 (Week 1-2):** Implement health checks and failure classification
2. **Phase 2 (Week 3-4):** Implement circuit breakers and auto-remediation
3. **Phase 3 (Week 5-6):** Configure auto-scaling and custom metrics
4. **Phase 4 (Week 7-8):** Chaos testing and validation
5. **Phase 5 (Week 9):** Production rollout with monitoring
