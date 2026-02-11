# Migration Rollback Decision Tree

```
┌─────────────────────────────────────────────────────────┐
│  Production Issue Detected During Migration             │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
            ┌────────────────┐
            │  What Phase?   │
            └────┬───────────┘
                 │
    ┌────────────┼────────────┬────────────┐
    │            │            │            │
    ▼            ▼            ▼            ▼
┌────────┐  ┌────────┐  ┌────────┐  ┌────────┐
│Phase 1 │  │Phase 2 │  │Phase 3 │  │Phase 4 │
│EXPAND  │  │MIGRATE │  │SWITCH  │  │CLEANUP │
└───┬────┘  └───┬────┘  └───┬────┘  └───┬────┘
    │           │           │           │
    ▼           ▼           ▼           ▼
┌────────────────────────────────────────────┐
│  Severity Assessment                       │
└────┬───────────────────────────────────────┘
     │
     ▼
┌──────────────┐
│  Critical?   │
│  (5xx > 1%)  │
└──┬───────┬───┘
   │       │
  YES     NO
   │       │
   ▼       ▼
┌──────┐ ┌──────────┐
│ROLLBACK│ │MONITOR │
└──────┘ └──────────┘
```

---

## Phase 1: EXPAND (Added New Column)

### Symptoms
- Migration failed to run
- New column doesn't exist
- Application errors referencing new column

### Rollback Actions

#### Option A: Migration Failed
```bash
# Check migration status
php artisan migrate:status

# If migration is pending, just don't run it
# No action needed - old code still works
```

#### Option B: Migration Ran, Code Deployment Failed
```bash
# Revert migration
php artisan migrate:rollback --step=1

# Verify column removed
php artisan db:show attendances
```

#### Option C: Both Deployed, Errors in Logs
```bash
# Check if errors are critical
tail -f storage/logs/laravel.log | grep ERROR

# If < 0.1% error rate: MONITOR ONLY
# If > 1% error rate: ROLLBACK
php artisan migrate:rollback --step=1
git revert HEAD
```

### Decision Matrix

| Error Rate | Action | Timeline |
|------------|--------|----------|
| < 0.1% | Monitor | 24 hours |
| 0.1% - 1% | Investigate | 4 hours |
| > 1% | Rollback | Immediate |

---

## Phase 2: MIGRATE (Backfilling Data)

### Symptoms
- Background job failing
- Data inconsistencies
- High database load

### Rollback Actions

#### Option A: Job Failing
```bash
# Stop the job
php artisan queue:clear migrations

# Check failed jobs
php artisan queue:failed

# Retry with smaller chunk size
MIGRATION_CHUNK_SIZE=500 php artisan queue:work migrations
```

#### Option B: Data Corruption Detected
```bash
# Stop migration immediately
php artisan queue:clear migrations

# Verify data integrity
SELECT COUNT(*) FROM attendances WHERE state IS NULL;
SELECT COUNT(*) FROM attendances WHERE state NOT IN ('init', 'checked_in', ...);

# If corruption < 1%: Fix manually
UPDATE attendances SET state = 'init' WHERE state IS NULL;

# If corruption > 1%: Full rollback
# Restore from backup (last night's snapshot)
mysql -u root -p absensi_db < backup_before_migration.sql
```

#### Option C: Performance Degradation
```bash
# Check database load
SHOW PROCESSLIST;

# Increase throttle delay
MIGRATION_THROTTLE_MS=500 # Slow down to 500ms between chunks

# Or pause migration
php artisan queue:pause migrations

# Resume during off-peak hours
php artisan queue:resume migrations
```

### Decision Matrix

| Issue | Severity | Action |
|-------|----------|--------|
| Job failing | Low | Retry with smaller chunks |
| Data corruption < 1% | Medium | Fix manually, continue |
| Data corruption > 1% | Critical | Stop, restore backup |
| DB CPU > 80% | High | Increase throttle delay |

---

## Phase 3: SWITCH (Reading from New Column)

### Symptoms
- Incorrect data displayed
- Null pointer exceptions
- Business logic errors

### Rollback Actions

#### Option A: Feature Flag Enabled
```bash
# INSTANT ROLLBACK (No deployment needed)
# Set feature flag to false
redis-cli SET feature:use_state_column false

# Or via environment variable
FEATURE_USE_STATE_COLUMN=false

# Verify old code path works
curl https://api.example.com/attendances | jq '.data[0].status'
```

#### Option B: Code Deployed Without Feature Flag
```bash
# Emergency hotfix deployment
git revert HEAD
git push origin main

# Deploy immediately
./deploy.sh --emergency

# Estimated downtime: 2-5 minutes
```

#### Option C: Data Mapping Issues
```bash
# If new column has wrong values
# Example: state='checked_in' but should be 'approved'

# Quick fix via SQL
UPDATE attendances 
SET state = 'approved' 
WHERE status IN ('sick', 'permit', 'excused') 
AND state = 'checked_in';

# Then flip feature flag back on
FEATURE_USE_STATE_COLUMN=true
```

### Decision Matrix

| Issue | Rollback Method | Downtime |
|-------|----------------|----------|
| Wrong data displayed | Feature flag | 0 seconds |
| Null errors | Feature flag | 0 seconds |
| Feature flag not implemented | Git revert + deploy | 2-5 minutes |
| Data mapping bug | SQL fix + feature flag | 30 seconds |

---

## Phase 4: CLEANUP (Removing Old Column)

### Symptoms
- Old code still referencing removed column
- Database errors: "Unknown column 'status'"

### Rollback Actions

#### Option A: Column Dropped, Code Still References It
```bash
# Re-add the column immediately
php artisan make:migration add_back_status_column_to_attendances

# Migration content:
Schema::table('attendances', function (Blueprint $table) {
    $table->enum('status', ['present', 'late', 'absent', 'sick', 'permit', 'excused'])
        ->nullable()
        ->after('state');
});

# Run migration
php artisan migrate

# Backfill from state column
UPDATE attendances SET status = CASE state
    WHEN 'checked_in' THEN 'present'
    WHEN 'checked_out' THEN 'present'
    WHEN 'approved' THEN 'present'
    WHEN 'init' THEN 'absent'
    WHEN 'pending_approval' THEN 'pending'
    WHEN 'rejected' THEN 'rejected'
END;
```

#### Option B: pt-osc Still Running
```bash
# Check if pt-online-schema-change is running
ps aux | grep pt-online-schema-change

# Stop it gracefully
# Find the PID and send SIGTERM
kill -TERM <PID>

# pt-osc will automatically rollback the change
# Original table remains untouched
```

### Decision Matrix

| Issue | Action | Recovery Time |
|-------|--------|---------------|
| Column dropped prematurely | Re-add + backfill | 5-10 minutes |
| pt-osc running, errors detected | Stop pt-osc | 1 minute |
| Replication lag spike | Pause pt-osc, resume later | N/A |

---

## Emergency Contacts

| Role | Name | Phone | Escalation |
|------|------|-------|------------|
| On-Call DBA | [Name] | [Phone] | Primary |
| Lead Backend Engineer | [Name] | [Phone] | Secondary |
| CTO | [Name] | [Phone] | Critical Only |

---

## Rollback Checklist

### Pre-Rollback
- [ ] Confirm issue is migration-related (not unrelated bug)
- [ ] Check error rate in monitoring dashboard
- [ ] Verify backup is available and recent (< 24h old)
- [ ] Notify team in #incidents Slack channel

### During Rollback
- [ ] Execute rollback command (see phase-specific actions above)
- [ ] Monitor error rate (should drop to < 0.1% within 5 minutes)
- [ ] Verify core functionality works (run smoke tests)
- [ ] Check database replication lag (should be < 5s)

### Post-Rollback
- [ ] Document what went wrong in incident report
- [ ] Update migration strategy to prevent recurrence
- [ ] Schedule post-mortem meeting (within 48 hours)
- [ ] Update rollback runbook with lessons learned

---

## Rollback Testing

### Staging Drill (Monthly)

```bash
# 1. Deploy migration to staging
php artisan migrate

# 2. Simulate production traffic
artillery run load-test.yml

# 3. Inject random errors
# (Manually corrupt some data)

# 4. Practice rollback
FEATURE_USE_STATE_COLUMN=false
php artisan migrate:rollback

# 5. Verify system recovery
php artisan test --filter=AttendanceTest
```

---

## Automated Rollback Triggers

```yaml
# Prometheus alert rules
groups:
  - name: migration_alerts
    rules:
      - alert: MigrationErrorRateHigh
        expr: rate(http_requests_total{status=~"5.."}[5m]) > 0.01
        for: 5m
        annotations:
          summary: "Migration causing high error rate"
          action: "Execute rollback immediately"
        
      - alert: MigrationDatabaseOverload
        expr: mysql_global_status_threads_running > 100
        for: 10m
        annotations:
          summary: "Migration overloading database"
          action: "Pause migration job, increase throttle"
```

---

**Last Updated:** 2026-02-10  
**Next Review:** After each migration
