# 🔥 Chaos Engineering Test Plan

**Site Reliability Engineer**  
**Date**: 2026-02-10  
**Purpose**: Validate CQRS system resilience under chaos conditions

---

## 🎯 Objectives

Validate that the CQRS system can:
1. **Handle extreme load** (10,000+ concurrent requests)
2. **Survive component failures** (Redis, DB, Queue)
3. **Recover automatically** (<60 seconds)
4. **Maintain data integrity** (zero duplicates, zero corruption)
5. **Degrade gracefully** (no cascading failures)

---

## 📊 Chaos Experiment Table

| Phase | Experiment | Duration | Impact | Success Criteria |
|-------|-----------|----------|--------|------------------|
| **1. Load Chaos** | 10,000 concurrent scans | 10 sec | High | Error rate <1%, No duplicates |
| | 50 webhook burst | 5 sec | Medium | Idempotency maintained |
| | 10 teachers generate QR | 30 sec | Low | No race conditions |
| **2. Failure Injection** | Redis crash | 2 min | Critical | Fallback to DB, No data loss |
| | DB latency 500ms | 5 min | High | Queue buffers, Timeout handling |
| | Queue kill | 3 min | Medium | Jobs retry, Eventual consistency |
| | Disk 95% full | 10 min | High | Graceful degradation, Alerts |
| **3. Recovery Test** | Auto-recovery | 60 sec | - | System operational, Data consistent |

---

## 🔥 Phase 1: Load Chaos

### Experiment 1.1: 10,000 Concurrent Scans

**Hypothesis**: System can handle 10,000 concurrent attendance scans without duplicates or deadlocks.

**Setup**:
```bash
# Use k6 for load testing
k6 run --vus 1000 --duration 10s chaos-scan-load.js
```

**Metrics to Track**:
- ✅ Error rate <1%
- ✅ P95 latency <500ms
- ✅ Database lock wait time <100ms
- ✅ Zero duplicate attendance records
- ✅ CPU usage <80%
- ✅ Memory usage <85%

**Expected Behavior**:
- Unique constraint prevents duplicates
- Database locks handled gracefully
- Queue buffers excess load
- Read models eventually consistent

**Failure Modes**:
- ❌ Duplicate attendance records
- ❌ Database deadlocks
- ❌ Connection pool exhaustion
- ❌ OOM errors

---

### Experiment 1.2: 50 Webhook Burst

**Hypothesis**: Webhook idempotency prevents duplicate subscription updates.

**Setup**:
```bash
# Send 50 duplicate webhooks simultaneously
k6 run --vus 50 --iterations 50 chaos-webhook-burst.js
```

**Metrics to Track**:
- ✅ Only 1 subscription update processed
- ✅ 49 webhooks deduplicated
- ✅ Idempotency key enforcement
- ✅ No database constraint violations

**Expected Behavior**:
- First webhook processed
- Subsequent webhooks return cached response
- No duplicate database writes

**Failure Modes**:
- ❌ Multiple subscription updates
- ❌ Race condition in idempotency check
- ❌ Database constraint violation

---

### Experiment 1.3: 10 Teachers Generate QR Simultaneously

**Hypothesis**: Concurrent QR generation doesn't cause nonce collisions.

**Setup**:
```bash
# 10 teachers generate QR codes simultaneously
k6 run --vus 10 --iterations 10 chaos-qr-generation.js
```

**Metrics to Track**:
- ✅ All QR codes unique
- ✅ No nonce collisions
- ✅ All QR codes valid
- ✅ Response time <200ms

**Expected Behavior**:
- Unique nonces generated
- No race conditions
- All QR codes scannable

**Failure Modes**:
- ❌ Duplicate nonces
- ❌ Invalid QR codes
- ❌ Race condition in nonce generation

---

## 💥 Phase 2: Failure Injection

### Experiment 2.1: Redis Crash

**Hypothesis**: System falls back to database when Redis crashes.

**Setup**:
```bash
# Stop Redis
docker stop redis

# Wait 2 minutes

# Restart Redis
docker start redis
```

**Metrics to Track**:
- ✅ Cache fallback to database
- ✅ Session handling continues
- ✅ No data loss
- ✅ Auto-reconnect when Redis returns
- ✅ Error rate spike <5%

**Expected Behavior**:
- Cache queries hit database
- Sessions use file driver
- Performance degraded but functional
- Auto-reconnect on Redis recovery

**Failure Modes**:
- ❌ Application crash
- ❌ Session loss
- ❌ Data corruption
- ❌ No auto-reconnect

---

### Experiment 2.2: Database Latency Injection (500ms)

**Hypothesis**: System handles slow database queries gracefully.

**Setup**:
```bash
# Inject 500ms latency to all DB queries
docker exec mysql tc qdisc add dev eth0 root netem delay 500ms

# Wait 5 minutes

# Remove latency
docker exec mysql tc qdisc del dev eth0 root
```

**Metrics to Track**:
- ✅ Query timeout handling
- ✅ Connection pool management
- ✅ Queue buffers requests
- ✅ Read models serve cached data
- ✅ No cascading failures

**Expected Behavior**:
- Slow queries timeout gracefully
- Queue buffers write operations
- Read models serve from cache
- User sees degraded performance message

**Failure Modes**:
- ❌ Connection pool exhaustion
- ❌ Cascading timeouts
- ❌ Application hang
- ❌ Data loss

---

### Experiment 2.3: Queue Kill

**Hypothesis**: Jobs retry automatically when queue workers restart.

**Setup**:
```bash
# Kill all queue workers
sudo supervisorctl stop laravel-worker:*

# Wait 3 minutes

# Restart queue workers
sudo supervisorctl start laravel-worker:*
```

**Metrics to Track**:
- ✅ Jobs queued during downtime
- ✅ Jobs processed after restart
- ✅ No job loss
- ✅ Eventual consistency maintained
- ✅ Read models update after recovery

**Expected Behavior**:
- Jobs accumulate in queue
- Workers process backlog on restart
- Read models eventually consistent
- No manual intervention required

**Failure Modes**:
- ❌ Job loss
- ❌ Read models out of sync
- ❌ Manual data fix required
- ❌ Jobs stuck in failed state

---

### Experiment 2.4: Disk Almost Full (95%)

**Hypothesis**: System handles disk pressure gracefully.

**Setup**:
```bash
# Fill disk to 95%
dd if=/dev/zero of=/tmp/fillfile bs=1M count=10000

# Wait 10 minutes

# Remove file
rm /tmp/fillfile
```

**Metrics to Track**:
- ✅ Disk usage alerts triggered
- ✅ Log rotation activated
- ✅ Cache cleanup triggered
- ✅ Application continues running
- ✅ No data corruption

**Expected Behavior**:
- Alerts sent to DevOps
- Old logs rotated
- Cache cleared
- Application degrades gracefully

**Failure Modes**:
- ❌ Application crash
- ❌ Database corruption
- ❌ Log write failures
- ❌ No alerts sent

---

## 🔄 Phase 3: Recovery Test

### Experiment 3.1: Auto-Recovery

**Hypothesis**: System recovers automatically within 60 seconds.

**Test Sequence**:
1. Inject failure (Redis crash)
2. Wait for detection (5-10 sec)
3. Observe auto-recovery
4. Verify system health

**Metrics to Track**:
- ✅ Detection time <10 sec
- ✅ Recovery time <60 sec
- ✅ No manual intervention
- ✅ All services healthy
- ✅ Data consistency verified

**Expected Behavior**:
- Monitoring detects failure
- Auto-restart triggered
- Services reconnect
- Health checks pass
- System fully operational

**Failure Modes**:
- ❌ Manual intervention required
- ❌ Recovery time >60 sec
- ❌ Partial recovery
- ❌ Data inconsistency

---

### Experiment 3.2: Data Consistency Check

**Hypothesis**: Read models are consistent with write models after recovery.

**Verification**:
```sql
-- Check attendance count matches summary
SELECT 
  COUNT(*) as write_count,
  (SELECT SUM(total_students) FROM attendance_daily_summaries 
   WHERE attendance_date = CURDATE()) as read_count;

-- Should be equal
```

**Metrics to Track**:
- ✅ Write model count = Read model count
- ✅ No orphaned records
- ✅ No missing summaries
- ✅ All aggregates correct

**Expected Behavior**:
- Counts match exactly
- No data loss
- No duplicate records
- Summaries accurate

**Failure Modes**:
- ❌ Count mismatch
- ❌ Missing summaries
- ❌ Duplicate records
- ❌ Incorrect aggregates

---

## 📊 Metrics Dashboard

### Real-Time Metrics

| Metric | Normal | Warning | Critical | Current |
|--------|--------|---------|----------|---------|
| Error Rate | <0.1% | 0.1-1% | >1% | - |
| P95 Latency | <200ms | 200-500ms | >500ms | - |
| DB Lock Wait | <50ms | 50-100ms | >100ms | - |
| Redis Memory | <70% | 70-85% | >85% | - |
| Queue Lag | <100 | 100-1000 | >1000 | - |
| Duplicate Count | 0 | 0 | >0 | - |
| CPU Usage | <60% | 60-80% | >80% | - |
| Memory Usage | <70% | 70-85% | >85% | - |

---

## ✅ Success Criteria

### Overall System

- ✅ Error rate <1% during chaos
- ✅ Zero data corruption
- ✅ Zero duplicate records
- ✅ Recovery time <60 seconds
- ✅ No manual intervention required
- ✅ No tenant data leakage

### Load Chaos

- ✅ Handle 10,000 concurrent requests
- ✅ P95 latency <500ms
- ✅ No database deadlocks
- ✅ CPU usage <80%

### Failure Injection

- ✅ Redis crash: Fallback to DB
- ✅ DB latency: Graceful degradation
- ✅ Queue kill: Jobs retry automatically
- ✅ Disk full: Alerts and cleanup

### Recovery

- ✅ Auto-recovery <60 seconds
- ✅ Data consistency maintained
- ✅ All services healthy

---

## 🚨 Failure Scenarios

### Critical Failures (Immediate Alert)

1. **Data Corruption**
   - Duplicate attendance records
   - Mismatched read/write models
   - Lost transactions

2. **Cascading Failures**
   - Redis crash → DB overload → System down
   - Queue backup → Memory exhaustion → OOM

3. **Security Breaches**
   - Tenant data leakage
   - Cross-tenant access
   - Unauthorized data modification

### Degraded Performance (Warning)

1. **High Latency**
   - P95 >500ms
   - Database slow queries
   - Queue lag >1000

2. **Resource Exhaustion**
   - CPU >80%
   - Memory >85%
   - Disk >90%

---

## 📝 Experiment Execution Plan

### Week 1: Preparation
- [ ] Set up chaos testing environment
- [ ] Install k6 and dependencies
- [ ] Configure monitoring dashboards
- [ ] Prepare rollback procedures

### Week 2: Load Chaos
- [ ] Run 10,000 concurrent scans test
- [ ] Run 50 webhook burst test
- [ ] Run 10 QR generation test
- [ ] Analyze results

### Week 3: Failure Injection
- [ ] Redis crash test
- [ ] DB latency injection test
- [ ] Queue kill test
- [ ] Disk full test
- [ ] Analyze results

### Week 4: Recovery Testing
- [ ] Auto-recovery test
- [ ] Data consistency verification
- [ ] End-to-end validation
- [ ] Document findings

---

## 📚 Documentation

See detailed implementation in:
1. **[CHAOS_K6_SCRIPTS.md](./CHAOS_K6_SCRIPTS.md)** - k6 load test scripts
2. **[CHAOS_FAILURE_SCRIPTS.md](./CHAOS_FAILURE_SCRIPTS.md)** - Docker failure injection
3. **[CHAOS_POST_MORTEM_TEMPLATE.md](./CHAOS_POST_MORTEM_TEMPLATE.md)** - Post-mortem template

---

**Status**: ✅ Ready for Execution  
**Last Updated**: 2026-02-10  
**Version**: 1.0
