# Auto-Remediation Decision Tree & Failure Matrix

## Remediation Decision Tree

```
                    ┌─────────────────────┐
                    │  FAILURE DETECTED   │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │ Classify Failure    │
                    │ (FailureClassifier) │
                    └──────────┬──────────┘
                               │
        ┌──────────────────────┼──────────────────────┐
        │                      │                      │
        ▼                      ▼                      ▼
┌───────────────┐    ┌───────────────┐    ┌───────────────┐
│  TRANSIENT    │    │   RESOURCE    │    │  DEPENDENCY   │
│   FAILURE     │    │  EXHAUSTION   │    │    FAILURE    │
└───────┬───────┘    └───────┬───────┘    └───────┬───────┘
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐    ┌───────────────┐    ┌───────────────┐
│ Retry with    │    │ Check Resource│    │ Open Circuit  │
│ Exponential   │    │ Type          │    │ Breaker       │
│ Backoff       │    └───────┬───────┘    └───────┬───────┘
└───────┬───────┘            │                    │
        │            ┌───────┴────────┐           │
        │            ▼                ▼           ▼
        │    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
        │    │ Queue Lag    │ │ Redis Memory │ │ Use Fallback │
        │    │ → Scale      │ │ → Flush/Scale│ │ Strategy     │
        │    │ Workers      │ │              │ │              │
        │    └──────┬───────┘ └──────┬───────┘ └──────┬───────┘
        │           │                │                │
        │           ▼                ▼                ▼
        │    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
        │    │ DB Conn Pool │ │ Disk Full    │ │ Wait Timeout │
        │    │ → Enable     │ │ → Emergency  │ │ → Retry      │
        │    │ Read Replica │ │ Cleanup      │ │              │
        │    └──────────────┘ └──────────────┘ └──────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────┐
│                    SUCCESS CHECK                          │
│  ┌─────────────────┐              ┌─────────────────┐    │
│  │ Success?        │──── YES ───→ │ Log & Return    │    │
│  └────────┬────────┘              └─────────────────┘    │
│           │                                               │
│          NO                                               │
│           │                                               │
│           ▼                                               │
│  ┌─────────────────┐              ┌─────────────────┐    │
│  │ Max Retries?    │──── YES ───→ │ Manual          │    │
│  │                 │              │ Escalation      │    │
│  └────────┬────────┘              └─────────────────┘    │
│           │                                               │
│          NO                                               │
│           │                                               │
│           └──────────────────────────────────────────────┐│
│                                                           ││
└───────────────────────────────────────────────────────────┘│
                                                             │
                    ┌────────────────────────────────────────┘
                    │
                    ▼
            ┌───────────────┐
            │ DATA          │
            │ CORRUPTION    │
            └───────┬───────┘
                    │
                    ▼
            ┌───────────────┐
            │ FREEZE WRITES │
            │ + ALERT       │
            │ CRITICAL      │
            └───────┬───────┘
                    │
                    ▼
            ┌───────────────┐
            │ Manual Review │
            │ Required      │
            └───────────────┘
```

---

## Failure Classification Matrix

| **Error Pattern** | **Failure Type** | **Max Retries** | **Retry Delay** | **Auto Action** | **Manual Escalation** |
|-------------------|------------------|-----------------|-----------------|-----------------|----------------------|
| **Database Errors** |
| `SQLSTATE[40001]` (Deadlock) | Transient | 5 | 100ms + exp backoff | Retry with jitter | After 5 failures |
| `SQLSTATE[1205]` (Lock timeout) | Transient | 5 | 100ms + exp backoff | Retry with jitter | After 5 failures |
| `Too many connections` | Resource Exhaustion | 0 | N/A | Enable read replica | If persists > 5 min |
| `Server has gone away` | Dependency Failure | 3 | 1s + exp backoff | Circuit breaker | After 3 failures |
| `Connection refused` | Dependency Failure | 3 | 1s + exp backoff | Circuit breaker | After 3 failures |
| Slow query (P95 > 500ms) | Resource Exhaustion | 0 | N/A | Degraded mode | If persists > 5 min |
| **Redis Errors** |
| Connection timeout | Transient | 3 | 100ms + exp backoff | Retry | After 3 failures |
| `Connection refused` | Dependency Failure | 3 | 1s + exp backoff | Circuit breaker + DB fallback | After 3 failures |
| `OOM command not allowed` | Resource Exhaustion | 0 | N/A | Flush non-critical cache | Immediately |
| Memory > 95% | Resource Exhaustion | 0 | N/A | Emergency flush | Immediately |
| Memory > 85% | Resource Exhaustion | 0 | N/A | Scale node | If scaling fails |
| **Queue Errors** |
| Worker exit code 137 (OOM) | Resource Exhaustion | 0 | N/A | Increase worker memory | After 3 crashes |
| Worker exit code 1 (error) | Transient | 5 | 100ms + exp backoff | Restart worker | After 5 restarts |
| Queue lag > 2000 | Resource Exhaustion | 0 | N/A | Scale workers +2 | If max workers reached |
| Queue lag > 500 | Resource Exhaustion | 0 | N/A | Scale workers +1 | If max workers reached |
| Failed jobs > 50 in 5 min | Unknown | 0 | N/A | Pause queue + alert | Immediately |
| **System Errors** |
| `Allowed memory size` | Resource Exhaustion | 0 | N/A | Graceful restart | After 3 restarts |
| `No space left on device` | Resource Exhaustion | 0 | N/A | Emergency cleanup | Immediately |
| Disk usage > 85% | Resource Exhaustion | 0 | N/A | Log rotation + cleanup | If cleanup fails |
| **Security Errors** |
| Duplicate QR nonce | Data Corruption | 0 | N/A | Reject + log security event | Immediately |
| Tenant isolation breach | Data Corruption | 0 | N/A | Freeze writes + critical alert | Immediately |
| **Network Errors** |
| `Connection reset` | Transient | 3 | 100ms + exp backoff | Retry | After 3 failures |
| `Network unreachable` | Dependency Failure | 3 | 1s + exp backoff | Circuit breaker | After 3 failures |
| Timeout (< 5s) | Transient | 3 | 100ms + exp backoff | Retry | After 3 failures |
| Timeout (> 5s) | Dependency Failure | 3 | 1s + exp backoff | Circuit breaker | After 3 failures |

---

## Auto-Scaling Decision Matrix

| **Metric** | **Warning** | **Critical** | **Scale Action** | **Cooldown** | **Max Scale** |
|------------|-------------|--------------|------------------|--------------|---------------|
| **Queue Workers** |
| Queue lag | > 500 jobs | > 2000 jobs | +1 worker (warning)<br>+2 workers (critical) | 5 min | 10 workers |
| CPU usage | > 70% | > 90% | +50% pods | 5 min | 10 workers |
| Memory usage | > 80% | > 95% | +50% pods | 5 min | 10 workers |
| **Web Application** |
| RPS per pod | > 50 | > 100 | +100% pods or +4 | 10 min | 20 pods |
| CPU usage | > 60% | > 85% | +100% pods | 10 min | 20 pods |
| Memory usage | > 75% | > 90% | +100% pods | 10 min | 20 pods |
| **Redis** |
| Memory usage | > 85% | > 95% | Flush cache (warning)<br>Emergency flush (critical) | 10 min | 3 nodes |
| Evicted keys | > 100/min | > 1000/min | Scale node | 15 min | 3 nodes |
| **Database** |
| Connection pool | > 80% | > 90% | Enable read replica | 3 min | 3 replicas |
| Replication lag | > 5s | > 10s | Disable replica routing | 2 min | N/A |
| Slow queries | P95 > 500ms | P95 > 1000ms | Degraded mode | 5 min | N/A |

---

## Circuit Breaker State Transitions

```
┌──────────────────────────────────────────────────────────────┐
│                         CLOSED                               │
│  • Normal operation                                          │
│  • All requests pass through                                 │
│  • Failures counted                                          │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     │ Failures >= Threshold (e.g., 3)
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│                          OPEN                                │
│  • All requests rejected immediately                         │
│  • Return fallback response                                  │
│  • Wait for timeout (e.g., 10s)                             │
└────────────────────┬─────────────────────────────────────────┘
                     │
                     │ Timeout elapsed
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│                       HALF_OPEN                              │
│  • Allow limited requests through                            │
│  • Test if service recovered                                 │
│  • Count successes                                           │
└────────┬────────────────────────────────────────────┬────────┘
         │                                            │
         │ Successes >= Threshold (e.g., 2)          │ Any failure
         │                                            │
         ▼                                            ▼
    ┌─────────┐                                  ┌─────────┐
    │ CLOSED  │                                  │  OPEN   │
    └─────────┘                                  └─────────┘
```

---

## Degraded Mode Behavior

When system enters degraded mode:

| **Feature** | **Normal Mode** | **Degraded Mode** |
|-------------|-----------------|-------------------|
| Attendance Scan | Real-time processing | Real-time (priority) |
| Dashboard Aggregations | Real-time queries | Cached (5 min stale) |
| Admin Reports | On-demand generation | Disabled |
| Bulk Operations | Enabled | Disabled |
| Heavy Analytics | Enabled | Disabled |
| API Rate Limits | Standard | Reduced |
| Background Jobs | All queues | Critical only |

**Trigger Conditions:**
- DB slow queries (P95 > 500ms for > 1 min)
- Redis memory > 85%
- Queue lag > 500 jobs
- System load > 80%

**Auto-Recovery:**
- Monitor metrics every 30 seconds
- Exit degraded mode when all metrics return to normal for 2 minutes
- Maximum degraded mode duration: 10 minutes (then manual review)

---

## Remediation Success Criteria

| **Scenario** | **Success Criteria** | **Validation** |
|--------------|---------------------|----------------|
| Redis Failure | • Circuit breaker opens within 10s<br>• Fallback to DB cache works<br>• No attendance writes fail<br>• Circuit closes within 30s of recovery | Check logs for circuit breaker events<br>Verify no 500 errors<br>Check attendance records created |
| DB Failover | • Failover completes < 30s<br>• All writes succeed after failover<br>• No duplicate records<br>• Replication lag < 1s | Check DB primary status<br>Verify data integrity<br>Check replication lag |
| Queue Worker Crash | • Queue lag doesn't exceed 500<br>• New workers start < 90s<br>• All jobs complete<br>• No failed jobs | Check HPA status<br>Monitor queue metrics<br>Verify job completion |
| Network Partition | • Circuit breaker opens < 10s<br>• No writes fail (fallback used)<br>• Circuit recovers < 30s<br>• No duplicate records | Check circuit breaker logs<br>Verify attendance integrity |

---

## Manual Escalation Triggers

Auto-remediation will escalate to manual intervention when:

1. **Max retries exceeded** (transient failures persist)
2. **Auto-scaling at maximum** (cannot scale further)
3. **Circuit breaker stuck open** (dependency not recovering)
4. **Data corruption detected** (immediate freeze)
5. **Remediation fails 3 times** (remediation loop)
6. **Unknown failure type** (cannot classify)
7. **Critical resource exhaustion** (disk full, OOM)

**Escalation Actions:**
- PagerDuty alert sent
- Slack critical notification
- Email to on-call engineer
- System enters safe mode (minimal operations)
