# Chaos Testing Runbook

## Overview

This runbook documents the chaos testing procedures for AbsensiQR Pro. Chaos testing validates system resilience under failure conditions.

**⚠️ IMPORTANT: Chaos tests are ONLY for staging/testing environments. They are blocked in production.**

## Prerequisites

1. **Environment**: Must be `local`, `staging`, or `testing` (NOT `production`)
2. **Database**: Ensure you have a test database with sample data
3. **Monitoring**: Have monitoring dashboards open to observe system behavior
4. **Backup**: Ensure recent backups exist before testing

## Available Chaos Tests

### 1. Redis Failure Test (`chaos:redis-down`)

**Purpose**: Validates system behavior when Redis becomes unavailable.

**Command**:
```bash
php artisan chaos:redis-down --duration=60
```

**Options**:
- `--duration=60`: Duration in seconds (default: 60)
- `--dry-run`: Show expected behavior without executing

**Expected Behavior**:
- ✅ System enters DEGRADED mode
- ✅ Rate limiting falls back to database
- ✅ Policy service uses safe defaults
- ✅ Attendance scanning continues with stricter rules
- ✅ No system crashes or errors

**Success Criteria**:
- System continues operating with fallback mechanisms
- Fallback events are logged
- System recovers automatically after test ends
- No data loss or corruption

---

### 2. Database Slow Query Test (`chaos:db-slow`)

**Purpose**: Validates timeout handling and graceful degradation under slow database conditions.

**Command**:
```bash
php artisan chaos:db-slow --duration=60 --latency=3000
```

**Options**:
- `--duration=60`: Duration in seconds (default: 60)
- `--latency=3000`: Simulated latency in milliseconds (default: 3000)
- `--dry-run`: Show expected behavior without executing

**Expected Behavior**:
- ✅ System detects slow queries (>2000ms threshold)
- ✅ System enters DEGRADED mode if latency exceeds threshold
- ✅ Query timeouts work correctly
- ✅ No cascading failures
- ✅ Graceful error messages to users

**Success Criteria**:
- Slow queries are detected and logged
- System degrades gracefully
- No database connection exhaustion
- System recovers after test ends

---

### 3. Queue Failure Test (`chaos:queue-fail`)

**Purpose**: Validates queue backlog handling and failed job management.

**Command**:
```bash
php artisan chaos:queue-fail --duration=60 --backlog=2000 --fail-rate=100
```

**Options**:
- `--duration=60`: Duration in seconds (default: 60)
- `--backlog=2000`: Number of fake jobs to create (default: 2000)
- `--fail-rate=100`: Percentage of jobs that fail (default: 100)
- `--dry-run`: Show expected behavior without executing

**Expected Behavior**:
- ✅ System detects queue backlog (>1000 jobs threshold)
- ✅ System enters DEGRADED mode if backlog exceeds threshold
- ✅ Failed jobs are tracked
- ✅ Core functionality continues (sync operations)
- ✅ Automatic cleanup after test

**Success Criteria**:
- Queue backlog is detected
- System handles failed jobs gracefully
- No memory exhaustion
- Simulated jobs are cleaned up

---

### 4. Disk Full Test (`chaos:disk-full`)

**Purpose**: Validates graceful error handling when disk space is exhausted.

**Command**:
```bash
php artisan chaos:disk-full --duration=60 --size=100
```

**Options**:
- `--duration=60`: Duration in seconds (default: 60)
- `--size=100`: Size in MB to fill disk (default: 100)
- `--dry-run`: Show expected behavior without executing

**Expected Behavior**:
- ✅ Temporary files created to simulate disk full
- ✅ File operations fail gracefully with user-friendly errors
- ✅ System enters DEGRADED mode if disk >90% full
- ✅ Core attendance functionality continues (DB only)
- ✅ Automatic cleanup of temporary files

**Success Criteria**:
- Disk space monitoring detects high usage
- File operations fail gracefully
- No system crashes
- Temporary files are cleaned up

---

### 5. High Load Test (`chaos:high-load`)

**Purpose**: Validates rate limiting and system stability under high concurrent load.

**Command**:
```bash
php artisan chaos:high-load --duration=60 --requests=1000 --concurrency=50
```

**Options**:
- `--duration=60`: Duration in seconds (default: 60)
- `--requests=1000`: Total number of requests (default: 1000)
- `--concurrency=50`: Concurrent requests (default: 50)
- `--url=http://localhost:8000`: Base URL to test (default: localhost)
- `--dry-run`: Show expected behavior without executing

**Expected Behavior**:
- ✅ Rate limiter activates and blocks excessive requests
- ✅ Some requests receive 429 (Too Many Requests)
- ✅ System remains responsive
- ✅ No 500 errors or crashes
- ✅ Consistent response times

**Success Criteria**:
- Rate limiting works (429 responses received)
- System stays stable (error rate <5%)
- No database connection exhaustion
- Response times remain acceptable

---

## Running All Tests

**Command**:
```bash
php artisan chaos:run-all --duration=60
```

**Options**:
- `--duration=60`: Duration for each test (default: 60)
- `--skip=redis,db`: Skip specific tests (comma-separated)
- `--dry-run`: Show test plan without executing

**Output**:
- Runs all 5 chaos tests sequentially
- Generates comprehensive report
- Saves report to `storage/logs/chaos_test_report_*.txt`

---

## Interpreting Results

### Success Indicators

✅ **All tests passed**: System resilience is excellent
- Continue monitoring in production
- Run chaos tests regularly (monthly)
- Review fallback events for optimization

✅ **Rate limiting working**: 429 responses in high load test
- Proves rate limiter is protecting the system
- This is expected and correct behavior

✅ **Fallback mechanisms activated**: System entered degraded mode
- Proves fail-secure mechanisms are working
- System should recover automatically

### Failure Indicators

❌ **System crashes or hangs**: Critical issue
- Review logs immediately
- Check for resource leaks
- May need to adjust timeouts or limits

❌ **No rate limiting**: High load test shows no 429 responses
- Rate limiter may not be configured correctly
- Check middleware configuration
- Verify Redis/database rate limiting

❌ **Data corruption**: Duplicate records or data loss
- Critical data integrity issue
- Review database constraints
- Check transaction handling

---

## Monitoring During Tests

### What to Watch

1. **System Health Dashboard**
   - Component states (healthy/degraded/failed)
   - Fallback event counts
   - Error rates

2. **Database Metrics**
   - Connection pool usage
   - Query execution times
   - Slow query log

3. **Redis Metrics**
   - Memory usage
   - Connection count
   - Hit/miss ratio

4. **Application Logs**
   - Error messages
   - Fallback events
   - Performance warnings

5. **Queue Status**
   - Pending jobs count
   - Failed jobs count
   - Worker status

---

## Post-Test Actions

### 1. Review Fallback Events

```sql
SELECT * FROM fallback_events 
WHERE created_at >= NOW() - INTERVAL 1 HOUR
ORDER BY created_at DESC;
```

### 2. Check System Health

```bash
php artisan health:check
```

### 3. Verify Data Integrity

```bash
php artisan verify:data-integrity
```

### 4. Review Logs

```bash
tail -f storage/logs/laravel.log
```

### 5. Update Runbook

- Document any unexpected behavior
- Update thresholds if needed
- Add new monitoring alerts
- Share findings with team

---

## Troubleshooting

### Test Won't Run

**Error**: "Chaos testing is DISABLED in production environment"
- **Solution**: Change `APP_ENV` in `.env` to `staging` or `testing`

**Error**: "Permission denied"
- **Solution**: Ensure proper file permissions on storage directories

### Test Fails to Complete

**Issue**: Test hangs or times out
- **Solution**: Reduce duration or request count
- **Check**: Database connections, memory limits

**Issue**: Cleanup fails
- **Solution**: Manually delete temporary files in `storage/chaos_test/`
- **Check**: Disk space and permissions

### System Doesn't Recover

**Issue**: System stays in degraded mode
- **Solution**: Run `php artisan cache:clear`
- **Check**: `system_health_state` table
- **Manual fix**: Update component states to 'healthy'

---

## Scheduling Regular Tests

### Monthly Chaos Testing

Add to cron or scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Run chaos tests monthly in staging
    if (app()->environment('staging')) {
        $schedule->command('chaos:run-all --duration=30')
            ->monthlyOn(1, '02:00')
            ->emailOutputOnFailure('ops@example.com');
    }
}
```

---

## Safety Guidelines

### DO ✅

- Run tests in staging/testing environments only
- Have monitoring dashboards open
- Review test plan before executing
- Document findings and share with team
- Run tests during low-traffic periods
- Ensure backups are recent

### DON'T ❌

- Run in production environment
- Run without monitoring
- Run during peak hours
- Skip the dry-run first
- Ignore failed tests
- Run tests on live customer data

---

## Emergency Procedures

### If Chaos Test Causes Issues

1. **Stop the test**: Press Ctrl+C
2. **Check system health**: `php artisan health:check`
3. **Clear caches**: `php artisan cache:clear`
4. **Restart services**: Queue workers, Redis, etc.
5. **Review logs**: Check for errors
6. **Notify team**: Alert on-call engineer

### If System Won't Recover

1. **Check database**:
   ```sql
   UPDATE system_health_state SET state = 'healthy' WHERE component != 'chaos_test';
   DELETE FROM system_health_state WHERE component = 'chaos_test';
   ```

2. **Clear chaos config files**:
   ```bash
   rm storage/framework/chaos_*.json
   ```

3. **Restart application**:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan queue:restart
   ```

---

## Report Analysis

### Sample Report Structure

```
═══════════════════════════════════════════════════════
CHAOS ENGINEERING TEST REPORT
═══════════════════════════════════════════════════════

Environment: staging
Start Time: 2026-02-10 14:00:00
End Time: 2026-02-10 14:15:00
Total Duration: 900s

TEST RESULTS:
─────────────────────────────────────────────────────
Redis Failure: PASSED (65s)
Database Slow Query: PASSED (68s)
Queue Failure: PASSED (72s)
Disk Full: PASSED (70s)
High Load: PASSED (125s)

SUMMARY:
─────────────────────────────────────────────────────
Total Tests: 5
Passed: 5
Failed: 0
Success Rate: 100%
```

### Key Metrics to Track

- **Success Rate**: Should be 100%
- **Fallback Events**: Should be logged for each test
- **Recovery Time**: System should recover within seconds
- **Error Rate**: Should be <5% during high load test
- **Response Times**: Should remain acceptable

---

## Continuous Improvement

### After Each Test Cycle

1. **Review Results**: Analyze all test outcomes
2. **Update Thresholds**: Adjust based on findings
3. **Improve Monitoring**: Add new alerts if needed
4. **Update Documentation**: Keep runbook current
5. **Share Learnings**: Brief team on findings

### Quarterly Review

- Compare results over time
- Identify trends
- Update test scenarios
- Adjust test parameters
- Review and update runbook

---

## Contact

For questions or issues with chaos testing:
- **Team**: DevOps / SRE
- **Documentation**: This runbook
- **Logs**: `storage/logs/chaos_test_report_*.txt`
- **Monitoring**: System health dashboard

---

**Last Updated**: 2026-02-10
**Version**: 1.0
**Owner**: DevOps Team
