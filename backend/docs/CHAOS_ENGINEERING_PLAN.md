# Chaos Engineering Plan - Attendance SaaS System

## Overview

This document outlines chaos engineering experiments to validate system resilience under extreme conditions.

**Goals**:
- Validate resilience mechanisms (Circuit Breaker, CQRS, etc.)
- Identify bottlenecks and failure modes
- Ensure graceful degradation
- Verify automatic recovery

---

## A. Chaos Scenarios

### 1. Redis Crash

**Description**: Simulate Redis server failure during peak load

**Hypothesis**: 
- Circuit breaker opens after 5 failures
- System falls back to database locking
- No duplicate attendance records
- System recovers automatically when Redis returns

**Failure Injection**:
```bash
# Stop Redis
docker stop redis

# Or kill Redis process
redis-cli shutdown
```

**Expected Behavior**:
- Circuit breaker opens within 5 operations
- Logs show "Redis unavailable, using database lock"
- Attendance recording continues with DB fallback
- Response time increases by ~50ms
- No 500 errors

**Success Criteria**:
- ✅ Error rate < 1%
- ✅ No duplicate attendance records
- ✅ Circuit opens within 10 seconds
- ✅ System recovers within 30 seconds after Redis restart
- ✅ No database corruption

**Metrics to Track**:
- Circuit breaker state
- Response time (p50, p95, p99)
- Error rate
- Database lock wait time
- Duplicate attendance count

---

### 2. Database Slow Query (300ms Latency)

**Description**: Inject network latency to database

**Hypothesis**:
- Read model queries remain fast (<100ms)
- Write operations slow down but complete
- Queue workers timeout gracefully
- No cascading failures

**Failure Injection**:
```bash
# Linux tc (traffic control)
tc qdisc add dev eth0 root netem delay 300ms

# Or PostgreSQL config
ALTER SYSTEM SET statement_timeout = '300ms';
```

**Expected Behavior**:
- CQRS read model queries unaffected (cached)
- Write operations take 300ms+ but succeed
- Queue jobs may timeout and retry
- Dashboard remains responsive

**Success Criteria**:
- ✅ Read model response time < 100ms
- ✅ Write operations complete (even if slow)
- ✅ No transaction deadlocks
- ✅ Queue jobs retry successfully
- ✅ No data loss

**Metrics to Track**:
- Query execution time
- Transaction rollback rate
- Queue job failure rate
- Database connection pool usage

---

### 3. Queue Worker Crash

**Description**: Kill queue workers during high load

**Hypothesis**:
- Jobs remain in queue
- No jobs lost
- New workers pick up jobs
- Read model eventually consistent

**Failure Injection**:
```bash
# Kill queue workers
pkill -9 -f "queue:work"

# Or stop Horizon
php artisan horizon:terminate
```

**Expected Behavior**:
- Jobs stay in queue (not lost)
- Read model updates delayed
- New workers process backlog
- No duplicate event processing

**Success Criteria**:
- ✅ Zero job loss
- ✅ Jobs processed after worker restart
- ✅ Read model eventually consistent
- ✅ No duplicate summary updates
- ✅ Queue lag recovers within 5 minutes

**Metrics to Track**:
- Queue depth
- Job processing rate
- Failed job count
- Read model lag time

---

### 4. Disk Full

**Description**: Fill disk to 100% capacity

**Hypothesis**:
- Logs stop writing but app continues
- Database writes fail gracefully
- File uploads rejected with 507
- System alerts triggered

**Failure Injection**:
```bash
# Fill disk
dd if=/dev/zero of=/tmp/fillfile bs=1M count=10000

# Monitor
df -h
```

**Expected Behavior**:
- Log writes fail silently
- Database transactions rollback
- File uploads return 507 error
- Monitoring alerts fire

**Success Criteria**:
- ✅ No application crash
- ✅ Graceful error messages
- ✅ Database integrity maintained
- ✅ Alerts triggered
- ✅ System recovers after cleanup

**Metrics to Track**:
- Disk usage
- Failed write operations
- Error rate
- Alert trigger time

---

### 5. S3 Backup Failure

**Description**: Simulate S3 unavailability during backup

**Hypothesis**:
- Backup job fails and retries
- Local backup created as fallback
- Alerts sent to ops team
- System continues operating

**Failure Injection**:
```bash
# Block S3 access
iptables -A OUTPUT -d s3.amazonaws.com -j DROP

# Or use invalid credentials
AWS_ACCESS_KEY_ID=invalid
```

**Expected Behavior**:
- Backup job fails
- Retry scheduled
- Local backup created
- Alert email sent

**Success Criteria**:
- ✅ Backup job retries 3 times
- ✅ Local backup exists
- ✅ Alert sent within 5 minutes
- ✅ System operation unaffected
- ✅ Backup succeeds after S3 recovery

**Metrics to Track**:
- Backup success rate
- Retry count
- Alert delivery time
- Local disk usage

---

### 6. 10,000 Concurrent Scans

**Description**: Simulate morning rush with 10,000 simultaneous QR scans

**Hypothesis**:
- System handles load with <500ms p95
- No duplicate attendance
- Circuit breaker remains closed
- Database connections don't exhaust

**Failure Injection**:
```bash
# k6 load test
k6 run --vus 1000 --duration 30s chaos/concurrent-scans.js
```

**Expected Behavior**:
- Response time increases but stays <500ms
- No duplicate records
- Database connection pool scales
- Redis handles lock contention

**Success Criteria**:
- ✅ p95 response time < 500ms
- ✅ Error rate < 0.1%
- ✅ Zero duplicate attendance
- ✅ No database deadlocks
- ✅ All requests processed

**Metrics to Track**:
- Requests per second
- Response time (p50, p95, p99)
- Error rate
- Database connection count
- Redis memory usage
- CPU utilization

---

### 7. Subscription Expired Mid-Session

**Description**: Expire subscription while user is actively using system

**Hypothesis**:
- Current session completes
- New requests blocked with 403
- Graceful error message shown
- No data loss

**Failure Injection**:
```bash
# Update subscription expiry
UPDATE subscriptions 
SET expires_at = NOW() - INTERVAL '1 day'
WHERE school_id = 1;
```

**Expected Behavior**:
- Active attendance scans complete
- Next request returns 403
- User sees "Subscription expired" message
- No attendance data lost

**Success Criteria**:
- ✅ In-flight requests complete
- ✅ New requests blocked
- ✅ Clear error message
- ✅ No data corruption
- ✅ Audit log entry created

**Metrics to Track**:
- 403 error rate
- In-flight request completion
- Data integrity checks

---

### 8. Webhook Duplicate 50x

**Description**: Send same webhook 50 times to test idempotency

**Hypothesis**:
- Only first webhook processed
- Remaining 49 rejected as duplicates
- No duplicate subscription activation
- Idempotency log shows all attempts

**Failure Injection**:
```bash
# Send duplicate webhooks
for i in {1..50}; do
  curl -X POST http://localhost/api/webhooks/midtrans \
    -H "Content-Type: application/json" \
    -d @webhook-payload.json
done
```

**Expected Behavior**:
- First webhook: 200 OK, subscription activated
- Next 49: 200 OK, "Already processed"
- Only one subscription record
- All 50 logged in processed_webhooks table

**Success Criteria**:
- ✅ Only 1 subscription created
- ✅ All 50 webhooks logged
- ✅ No duplicate charges
- ✅ Idempotency working
- ✅ Response time consistent

**Metrics to Track**:
- Webhook processing time
- Duplicate detection rate
- Database write count
- Idempotency log entries

---

## B. Tooling

### Load Testing
```bash
# k6 installation
brew install k6  # macOS
sudo apt install k6  # Ubuntu

# Run load test
k6 run --vus 1000 --duration 60s load-test.js
```

### Network Latency Injection
```bash
# Linux tc (traffic control)
# Add 300ms latency
tc qdisc add dev eth0 root netem delay 300ms

# Remove latency
tc qdisc del dev eth0 root netem
```

### Container Chaos
```bash
# Docker restart
docker restart redis
docker stop postgres && sleep 30 && docker start postgres

# Docker resource limits
docker update --memory="512m" --cpus="0.5" app-container
```

### Database Monitoring
```bash
# PostgreSQL slow query log
ALTER SYSTEM SET log_min_duration_statement = 100;

# MySQL slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.1;
```

### Laravel Monitoring
```bash
# Horizon dashboard
php artisan horizon

# Telescope
php artisan telescope:prune

# Real-time logs
tail -f storage/logs/laravel.log | grep -E "ERROR|WARNING|circuit"
```

---

## C. Experiment Matrix

| # | Scenario | Hypothesis | Injection Method | Expected Behavior | Success Criteria |
|---|----------|-----------|------------------|-------------------|------------------|
| 1 | Redis Crash | Circuit opens, DB fallback works | `docker stop redis` | No errors, DB locking used | Error rate <1%, no duplicates |
| 2 | DB Slow Query | CQRS read model unaffected | `tc netem delay 300ms` | Reads fast, writes slow | Read <100ms, writes complete |
| 3 | Queue Worker Crash | Jobs queued, no loss | `pkill queue:work` | Jobs processed after restart | Zero job loss |
| 4 | Disk Full | Graceful degradation | `dd if=/dev/zero` | Errors handled, no crash | No app crash, alerts fire |
| 5 | S3 Backup Failure | Retry + local backup | Block S3 access | Retry 3x, local backup | Alert sent, local backup exists |
| 6 | 10K Concurrent Scans | System scales | k6 load test | <500ms p95, no duplicates | Error rate <0.1% |
| 7 | Subscription Expired | Graceful blocking | Update DB | 403 on new requests | No data loss, clear error |
| 8 | Webhook Duplicate 50x | Idempotency works | Send 50x same payload | Only 1 processed | 1 subscription, 50 logged |

---

## D. Metrics to Track

### Application Metrics

```yaml
Response Time:
  - p50 (median)
  - p95 (95th percentile)
  - p99 (99th percentile)
  - max

Error Rate:
  - 4xx errors
  - 5xx errors
  - Total error percentage

Throughput:
  - Requests per second
  - Successful requests
  - Failed requests
```

### Infrastructure Metrics

```yaml
Database:
  - Connection pool usage
  - Active connections
  - Lock wait time
  - Query execution time
  - Deadlock count

Redis:
  - Memory usage
  - Connected clients
  - Commands per second
  - Evicted keys
  - Circuit breaker state

Queue:
  - Queue depth
  - Job processing rate
  - Failed jobs
  - Job wait time
  - Worker count

System:
  - CPU utilization
  - Memory usage
  - Disk I/O
  - Network I/O
  - Disk space
```

### Business Metrics

```yaml
Attendance:
  - Total scans
  - Successful scans
  - Duplicate count
  - Average scan time

Data Integrity:
  - Attendance count (write model)
  - Summary count (read model)
  - Difference (should be 0)

Availability:
  - Uptime percentage
  - Mean time between failures (MTBF)
  - Mean time to recovery (MTTR)
```

---

## E. Game Day Plan

### Pre-Experiment (T-24 hours)

```markdown
□ Notify team of chaos experiment
□ Schedule during low-traffic period
□ Backup production data
□ Enable verbose logging
□ Set up monitoring dashboards
□ Prepare rollback plan
□ Test communication channels
```

### Experiment Execution (T-0)

```markdown
1. **T-0:00** - Start monitoring
   - Open Grafana/Datadog dashboard
   - Start log tailing
   - Record baseline metrics

2. **T-0:05** - Inject failure
   - Execute chaos script
   - Announce in team chat
   - Start timer

3. **T-0:05 to T-0:30** - Observe
   - Monitor metrics
   - Check logs
   - Verify expected behavior
   - Document anomalies

4. **T-0:30** - Recover
   - Remove failure injection
   - Verify automatic recovery
   - Check data integrity

5. **T-0:35** - Validate
   - Run data integrity checks
   - Verify no duplicates
   - Check read model consistency
```

### Post-Experiment (T+1 hour)

```markdown
□ Stop verbose logging
□ Export metrics
□ Run data validation queries
□ Calculate resilience score
□ Write incident report
□ Update runbooks
□ Schedule team debrief
```

### Rollback Plan

```markdown
If critical issues detected:

1. **STOP** experiment immediately
2. Restore service (restart Redis, etc.)
3. Check data integrity
4. Notify stakeholders
5. Rollback if necessary
6. Document failure mode
```

---

## F. Chaos Experiment Checklist

### Pre-Experiment

```markdown
□ Team notified 24h in advance
□ Experiment scheduled during low traffic
□ Monitoring dashboards prepared
□ Backup created
□ Rollback plan documented
□ Success criteria defined
□ Failure injection method tested
□ Communication channel ready
□ On-call engineer available
```

### During Experiment

```markdown
□ Baseline metrics recorded
□ Failure injected at scheduled time
□ Team notified of injection
□ Metrics monitored continuously
□ Logs reviewed in real-time
□ Anomalies documented
□ Screenshots captured
□ Timer running
```

### Post-Experiment

```markdown
□ Failure removed
□ System recovered
□ Data integrity verified
□ Metrics exported
□ Resilience score calculated
□ Post-mortem written
□ Runbooks updated
□ Team debriefed
□ Improvements identified
```

---

## G. Monitoring Dashboard Requirements

### Real-Time Dashboard

**Panels Required**:

1. **System Health**
   - Overall status (healthy/degraded/down)
   - Circuit breaker state
   - Active incidents

2. **Response Time**
   - p50, p95, p99 over time
   - By endpoint
   - Color-coded thresholds

3. **Error Rate**
   - 4xx vs 5xx
   - By endpoint
   - Error types

4. **Throughput**
   - Requests per second
   - Success vs failure
   - By operation type

5. **Infrastructure**
   - CPU, Memory, Disk
   - Database connections
   - Redis memory
   - Queue depth

6. **Business Metrics**
   - Attendance scans per minute
   - Duplicate detection rate
   - Read model lag

**Alert Thresholds**:

```yaml
Critical:
  - Error rate > 5%
  - p99 response time > 2s
  - Circuit breaker OPEN
  - Disk usage > 90%
  - Queue depth > 10,000

Warning:
  - Error rate > 1%
  - p95 response time > 500ms
  - Circuit breaker HALF_OPEN
  - Disk usage > 80%
  - Queue depth > 5,000
```

---

## H. Recovery Verification Plan

### Automated Checks

```bash
#!/bin/bash
# recovery-check.sh

echo "=== Recovery Verification ==="

# 1. Check services
echo "Checking services..."
redis-cli ping || echo "❌ Redis down"
pg_isready || echo "❌ PostgreSQL down"

# 2. Check circuit breaker
echo "Checking circuit breaker..."
curl -s http://localhost/api/health/circuit-breaker | jq '.redis_circuit_breaker.state'

# 3. Check data integrity
echo "Checking data integrity..."
php artisan tinker --execute="
  \$writeCount = \App\Models\Attendance::whereDate('attendance_date', today())->count();
  \$readCount = \App\ReadModels\AttendanceDailySummary::getTodaySummary(1)->total_students ?? 0;
  echo \"Write: \$writeCount, Read: \$readCount, Diff: \" . abs(\$writeCount - \$readCount);
"

# 4. Check for duplicates
echo "Checking for duplicates..."
php artisan tinker --execute="
  \$duplicates = \App\Models\Attendance::select('student_id', 'attendance_date')
    ->groupBy('student_id', 'attendance_date')
    ->havingRaw('COUNT(*) > 1')
    ->count();
  echo \"Duplicates: \$duplicates\";
"

# 5. Check queue
echo "Checking queue..."
php artisan queue:monitor

echo "=== Verification Complete ==="
```

### Manual Checks

```markdown
□ Test attendance scan (QR code)
□ Verify dashboard loads
□ Check read model data
□ Review error logs
□ Confirm no duplicates
□ Test subscription check
□ Verify webhook processing
```

---

## I. Resilience Score

### Scoring Criteria

Each experiment scored 0-100:

```yaml
Availability (40 points):
  - System remained operational: 20
  - Error rate < 1%: 10
  - No manual intervention: 10

Performance (30 points):
  - p95 < 500ms: 15
  - Throughput maintained: 15

Data Integrity (30 points):
  - No duplicates: 15
  - No data loss: 15
```

### Example Scorecard

| Scenario | Availability | Performance | Data Integrity | Total | Grade |
|----------|-------------|-------------|----------------|-------|-------|
| Redis Crash | 40/40 | 25/30 | 30/30 | 95/100 | A |
| DB Slow Query | 40/40 | 20/30 | 30/30 | 90/100 | A- |
| Queue Worker Crash | 40/40 | 30/30 | 30/30 | 100/100 | A+ |
| Disk Full | 30/40 | 15/30 | 30/30 | 75/100 | B |
| S3 Backup Failure | 40/40 | 30/30 | 30/30 | 100/100 | A+ |
| 10K Concurrent | 35/40 | 25/30 | 30/30 | 90/100 | A- |
| Subscription Expired | 40/40 | 30/30 | 30/30 | 100/100 | A+ |
| Webhook Duplicate | 40/40 | 30/30 | 30/30 | 100/100 | A+ |

**Overall Resilience Score**: 93.75/100 (A)

---

## J. Post-Mortem Template

```markdown
# Chaos Experiment Post-Mortem

## Experiment Details
- **Date**: 2026-02-09
- **Scenario**: Redis Crash
- **Duration**: 30 minutes
- **Team**: SRE Team

## Hypothesis
System will use database fallback when Redis is down, with no data loss.

## What Happened
- T+0:00: Redis stopped
- T+0:05: Circuit breaker opened
- T+0:05: System switched to DB locking
- T+0:30: Redis restarted
- T+0:32: Circuit breaker closed

## Metrics
- Error rate: 0.5%
- p95 response time: 120ms (baseline: 50ms)
- Duplicates: 0
- Data loss: 0

## What Went Well ✅
- Circuit breaker worked as expected
- Database fallback seamless
- No data loss or duplicates
- Automatic recovery

## What Went Wrong ❌
- Response time spike higher than expected
- Some users saw brief errors
- Monitoring alert delayed by 2 minutes

## Action Items
- [ ] Optimize database lock performance
- [ ] Improve monitoring alert speed
- [ ] Add user-facing status page
- [ ] Update runbook with findings

## Resilience Score
95/100 (A)
```

---

## K. Next Steps

1. **Schedule First Game Day**
   - Date: [TBD]
   - Scenario: Redis Crash
   - Team: SRE + Backend

2. **Prepare Tooling**
   - Install k6
   - Set up monitoring dashboard
   - Create chaos scripts

3. **Run Experiments**
   - Start with Redis Crash (lowest risk)
   - Progress to more complex scenarios
   - Document all findings

4. **Iterate**
   - Fix identified issues
   - Re-run failed experiments
   - Improve resilience score

---

**Document Version**: 1.0  
**Last Updated**: 2026-02-09  
**Owner**: SRE Team  
**Review Cycle**: Quarterly
