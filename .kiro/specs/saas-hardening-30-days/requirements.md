# SaaS Hardening 30-Day Roadmap - Requirements

## 📋 Overview

**Project**: AbsensiQR Pro - Production Hardening Sprint  
**Duration**: 30 days (4 weeks)  
**Goal**: Eliminate critical bugs, ensure data integrity, improve reliability  
**Approach**: Risk-first, backward compatible, no production disruption

---

## 🎯 Success Criteria

### Week 1: Data Integrity & Tenant Safety
- ✅ Zero tenant data leak risk
- ✅ Zero duplicate attendance possible
- ✅ All timezone operations consistent
- ✅ Queue jobs maintain tenant context
- ✅ State machine enforced everywhere

### Week 2: Concurrency & Webhook Hardening
- ✅ 1000 concurrent scans without failure
- ✅ Zero double subscription processing
- ✅ Redis failure handled gracefully
- ✅ Cache stampede prevented
- ✅ Webhook idempotency guaranteed

### Week 3: Performance & Dashboard Optimization
- ✅ Dashboard response < 50ms
- ✅ Zero full table scans
- ✅ Export handles 100K+ rows
- ✅ All queries use proper indexes
- ✅ N+1 queries eliminated

### Week 4: Observability & Failure Resilience
- ✅ Production monitoring active
- ✅ Health checks on all critical services
- ✅ Automated alerts configured
- ✅ Backup/restore tested and verified
- ✅ Chaos testing passed

---

## 📅 WEEK 1: DATA INTEGRITY & TENANT SAFETY

### Day 1: Timezone Consistency Audit & Fix

**User Story**: As a system, I need consistent timezone handling to prevent date mismatch bugs

**Acceptance Criteria**:
1. All `date()` calls replaced with `now($timezone)`
2. All `Carbon::now()` uses school timezone
3. All `whereDate()` uses timezone-aware dates
4. Config `app.timezone` documented
5. Tests verify timezone consistency

**Tasks**:
- [-] Audit all files for `date()`, `time()`, `strtotime()`
- [-] Create `TimezoneHelper` utility class
- [-] Replace all raw date functions
- [-] Add timezone to school settings
- [-] Write timezone consistency tests
- [x] Document timezone best practices

**Risk Reduction**: 🔴 HIGH (8/10) - Prevents date mismatch bugs  
**Effort**: 6 hours  
**Rollback**: Revert helper class, restore old date calls

---

### Day 2: Unique Attendance Constraint

**User Story**: As a system, I need to prevent duplicate attendance records at database level

**Acceptance Criteria**:
1. Unique constraint on `(student_id, schedule_id, attendance_date, school_id)`
2. Migration handles existing duplicates
3. Application code uses `firstOrCreate` consistently
4. Tests verify constraint enforcement
5. Error messages user-friendly

**Tasks**:
- [-] Identify existing duplicate records
- [-] Create cleanup script for duplicates
- [x] Create migration with unique constraint
- [x] Update all attendance creation code
- [x] Add constraint violation handling
- [ ] Write duplicate prevention tests

**Risk Reduction**: 🔴 CRITICAL (10/10) - Eliminates duplicate attendance  
**Effort**: 4 hours  
**Rollback**: Drop constraint, restore old code

---

### Day 3: Queue Job Tenant Context Fix

**User Story**: As a queue job, I need to maintain school_id context to prevent data leaks

**Acceptance Criteria**:
1. All queue jobs store `school_id` in constructor
2. All queries in jobs explicitly filter by `school_id`
3. Global scope bypass documented and justified
4. Tests verify tenant isolation in jobs
5. Audit log for cross-tenant access attempts

**Tasks**:
- [ ] Audit all queue jobs for tenant context
- [ ] Add `school_id` to job constructors
- [ ] Update job dispatchers to pass `school_id`
- [ ] Add explicit `school_id` filters in queries
- [ ] Create `TenantAwareJob` base class
- [ ] Write tenant isolation tests

**Risk Reduction**: 🔴 CRITICAL (10/10) - Prevents data leak  
**Effort**: 8 hours  
**Rollback**: Revert job changes, restore old dispatchers

---

### Day 4: State Machine Enforcement

**User Story**: As an attendance record, I need state changes to go through state machine only

**Acceptance Criteria**:
1. Direct `status` modification blocked in all cases
2. All seeders/factories use state machine
3. State transitions logged in audit trail
4. Invalid transitions throw exceptions
5. Tests verify state machine enforcement

**Tasks**:
- [ ] Block direct status modification completely
- [ ] Update all seeders to use state machine
- [ ] Update all factories to use state machine
- [ ] Add state transition audit logging
- [ ] Create state machine violation tests
- [ ] Document state machine usage

**Risk Reduction**: 🔴 HIGH (9/10) - Ensures data integrity  
**Effort**: 6 hours  
**Rollback**: Allow status modification during creation

---

### Day 5: DB::table() Audit & Elimination

**User Story**: As a multi-tenant system, I need all queries to respect global scopes

**Acceptance Criteria**:
1. Zero `DB::table('attendances')` in application code
2. Zero `DB::table('schedules')` in application code
3. All raw queries documented and justified
4. Migration queries excluded from audit
5. Tests verify no scope bypass

**Tasks**:
- [ ] Search all files for `DB::table(` patterns
- [ ] Replace with Eloquent queries
- [ ] Document legitimate raw query usage
- [ ] Add PHPStan rule to prevent `DB::table()`
- [ ] Create scope bypass detection tests
- [ ] Update coding standards

**Risk Reduction**: 🔴 HIGH (8/10) - Prevents tenant bypass  
**Effort**: 4 hours  
**Rollback**: Restore raw queries if needed

---

### Week 1 Deliverables

**Code Changes**:
- ✅ `TimezoneHelper` utility class
- ✅ Unique constraint migration
- ✅ `TenantAwareJob` base class
- ✅ State machine enforcement
- ✅ PHPStan rules for tenant safety

**Tests Added**:
- ✅ Timezone consistency tests (10 tests)
- ✅ Duplicate prevention tests (8 tests)
- ✅ Queue tenant isolation tests (12 tests)
- ✅ State machine enforcement tests (15 tests)
- ✅ Scope bypass detection tests (6 tests)

**Documentation**:
- ✅ Timezone best practices guide
- ✅ Queue job tenant context guide
- ✅ State machine usage guide
- ✅ Coding standards update

**Risk Reduction Score**: 🔴 45/50 (90%) - Critical data integrity issues resolved

---

## 📅 WEEK 2: CONCURRENCY & WEBHOOK HARDENING

### Day 6: Deadlock Detection & Retry

**User Story**: As a concurrent request, I need automatic retry on deadlock to ensure reliability

**Acceptance Criteria**:
1. Deadlock exceptions caught and retried (max 3 times)
2. Exponential backoff between retries
3. Deadlock events logged for monitoring
4. Tests simulate deadlock scenarios
5. Retry count tracked in metrics

**Tasks**:
- [ ] Create `DeadlockRetryMiddleware`
- [ ] Add exponential backoff logic
- [ ] Implement deadlock detection
- [ ] Add retry metrics tracking
- [ ] Write deadlock simulation tests
- [ ] Document retry behavior

**Risk Reduction**: 🟠 MEDIUM (6/10) - Improves reliability  
**Effort**: 5 hours  
**Rollback**: Remove middleware

---

### Day 7: Redis Health Guard

**User Story**: As a system, I need to handle Redis failures gracefully without crashing

**Acceptance Criteria**:
1. Redis connection failures caught
2. Automatic fallback to database cache
3. Redis health check endpoint
4. Alert triggered on Redis failure
5. Tests verify fallback behavior

**Tasks**:
- [ ] Implement failover cache driver
- [ ] Add Redis health check
- [ ] Create cache fallback logic
- [ ] Add Redis monitoring alerts
- [ ] Write Redis failure tests
- [ ] Document cache strategy

**Risk Reduction**: 🔴 HIGH (9/10) - Prevents system crash  
**Effort**: 6 hours  
**Rollback**: Revert to Redis-only cache

---

### Day 8: Webhook Idempotency Lock Enhancement

**User Story**: As a webhook, I need guaranteed idempotency to prevent double processing

**Acceptance Criteria**:
1. Lock timeout increased to 300 seconds
2. Processing status tracked in database
3. Duplicate webhooks return cached response
4. Lock acquisition logged
5. Tests verify idempotency under load

**Tasks**:
- [ ] Increase webhook lock timeout
- [ ] Add `ProcessedWebhook::markAsProcessing()`
- [ ] Implement processing status check
- [ ] Add webhook lock monitoring
- [ ] Write webhook concurrency tests
- [ ] Document webhook flow

**Risk Reduction**: 🔴 CRITICAL (10/10) - Prevents double subscription  
**Effort**: 4 hours  
**Rollback**: Restore old lock timeout

---

### Day 9: Cache Stampede Protection

**User Story**: As a cache, I need to prevent stampede when cache expires under load

**Acceptance Criteria**:
1. Cache locks prevent concurrent regeneration
2. Stale cache served while regenerating
3. Lock timeout prevents deadlock
4. Cache regeneration logged
5. Tests verify stampede prevention

**Tasks**:
- [ ] Implement cache lock pattern
- [ ] Add stale-while-revalidate logic
- [ ] Create cache regeneration monitoring
- [ ] Add cache stampede metrics
- [ ] Write cache stampede tests
- [ ] Document cache strategy

**Risk Reduction**: 🟠 MEDIUM (7/10) - Prevents database overload  
**Effort**: 5 hours  
**Rollback**: Remove cache locks

---

### Day 10: Subscription Cache Fix

**User Story**: As a subscription check, I need to validate expires_at after cache hit

**Acceptance Criteria**:
1. Cache TTL reduced to 60 seconds
2. Expires_at validated after cache hit
3. Cache cleared on subscription update
4. Webhook clears subscription cache
5. Tests verify cache invalidation

**Tasks**:
- [ ] Reduce subscription cache TTL
- [ ] Add expires_at validation
- [ ] Clear cache in webhook handler
- [ ] Add cache invalidation to subscription updates
- [ ] Write subscription cache tests
- [ ] Document cache invalidation

**Risk Reduction**: 🔴 CRITICAL (10/10) - Prevents revenue leak  
**Effort**: 3 hours  
**Rollback**: Restore old TTL

---

### Week 2 Deliverables

**Code Changes**:
- ✅ `DeadlockRetryMiddleware`
- ✅ Failover cache driver configuration
- ✅ Enhanced webhook idempotency
- ✅ Cache stampede protection
- ✅ Subscription cache fix

**Tests Added**:
- ✅ Deadlock retry tests (8 tests)
- ✅ Redis fallback tests (10 tests)
- ✅ Webhook concurrency tests (12 tests)
- ✅ Cache stampede tests (6 tests)
- ✅ Subscription cache tests (8 tests)

**Monitoring**:
- ✅ Redis health check endpoint
- ✅ Webhook processing metrics
- ✅ Cache regeneration metrics
- ✅ Deadlock retry metrics

**Risk Reduction Score**: 🔴 42/50 (84%) - Concurrency issues resolved

---

## 📅 WEEK 3: PERFORMANCE & DASHBOARD OPTIMIZATION

### Day 11: Database Index Review

**User Story**: As a query, I need proper indexes to execute in < 50ms

**Acceptance Criteria**:
1. All slow queries identified (> 100ms)
2. Missing indexes added
3. Unused indexes removed
4. Composite indexes optimized
5. Query execution plans verified

**Tasks**:
- [ ] Run slow query log analysis
- [ ] Identify missing indexes
- [ ] Create index migration
- [ ] Verify query plans with EXPLAIN
- [ ] Benchmark before/after
- [ ] Document index strategy

**Risk Reduction**: 🟠 MEDIUM (7/10) - Improves performance  
**Effort**: 6 hours  
**Rollback**: Drop new indexes

---

### Day 12: N+1 Query Elimination

**User Story**: As a controller, I need eager loading to prevent N+1 queries

**Acceptance Criteria**:
1. All N+1 queries identified
2. Eager loading added to all queries
3. Query count reduced by 80%+
4. Tests verify eager loading
5. Performance benchmarks documented

**Tasks**:
- [ ] Install Laravel Debugbar
- [ ] Audit all controllers for N+1
- [ ] Add eager loading to queries
- [ ] Create query count tests
- [ ] Benchmark query performance
- [ ] Document eager loading patterns

**Risk Reduction**: 🟠 MEDIUM (6/10) - Improves performance  
**Effort**: 8 hours  
**Rollback**: Remove eager loading

---

### Day 13: Dashboard Summary Table

**User Story**: As a dashboard, I need pre-aggregated data for fast response times

**Acceptance Criteria**:
1. `attendance_summaries` table created
2. Summary updated on attendance create/update
3. Dashboard queries use summary table
4. Response time < 50ms
5. Summary accuracy verified

**Tasks**:
- [ ] Design summary table schema
- [ ] Create migration for summary table
- [ ] Create `AttendanceSummaryService`
- [ ] Add observer to update summaries
- [ ] Update dashboard queries
- [ ] Write summary accuracy tests

**Risk Reduction**: 🟠 MEDIUM (7/10) - Improves UX  
**Effort**: 8 hours  
**Rollback**: Drop summary table, restore old queries

---

### Day 14: Export Chunking

**User Story**: As an export, I need to process large datasets without memory exhaustion

**Acceptance Criteria**:
1. Export uses cursor() for memory efficiency
2. Chunk size configurable (default 1000)
3. Progress tracking implemented
4. Memory usage < 100MB for 100K rows
5. Tests verify chunking behavior

**Tasks**:
- [ ] Replace `get()` with `cursor()` in exports
- [ ] Add chunk size configuration
- [ ] Implement progress tracking
- [ ] Add memory usage monitoring
- [ ] Write export chunking tests
- [ ] Document export best practices

**Risk Reduction**: 🟠 MEDIUM (6/10) - Prevents crashes  
**Effort**: 4 hours  
**Rollback**: Restore old export code

---

### Day 15: Query Profiling & Optimization

**User Story**: As a developer, I need query profiling to identify bottlenecks

**Acceptance Criteria**:
1. Query profiling enabled in staging
2. Slow queries logged automatically
3. Query execution time tracked
4. Top 10 slow queries identified
5. Optimization plan documented

**Tasks**:
- [ ] Enable query logging in staging
- [ ] Create query profiling dashboard
- [ ] Analyze slow query patterns
- [ ] Optimize top 10 slow queries
- [ ] Add query performance tests
- [ ] Document optimization results

**Risk Reduction**: 🟢 LOW (4/10) - Continuous improvement  
**Effort**: 6 hours  
**Rollback**: Disable query logging

---

### Week 3 Deliverables

**Code Changes**:
- ✅ Database indexes optimized
- ✅ N+1 queries eliminated
- ✅ `attendance_summaries` table
- ✅ Export chunking implemented
- ✅ Query profiling enabled

**Performance Improvements**:
- ✅ Dashboard: 2.3s → 45ms (95% faster)
- ✅ Export: 350MB → 50MB memory (86% reduction)
- ✅ Query count: 150 → 4 per request (97% reduction)
- ✅ Database CPU: 80% → 20% (75% reduction)

**Tests Added**:
- ✅ Index usage tests (8 tests)
- ✅ Eager loading tests (12 tests)
- ✅ Summary accuracy tests (10 tests)
- ✅ Export chunking tests (6 tests)
- ✅ Query performance tests (8 tests)

**Risk Reduction Score**: 🟠 30/50 (60%) - Performance optimized

---

## 📅 WEEK 4: OBSERVABILITY & FAILURE RESILIENCE

### Day 16-17: Health Check Endpoints

**User Story**: As monitoring, I need health checks for all critical services

**Acceptance Criteria**:
1. `/health` endpoint returns overall status
2. `/health/database` checks DB connection
3. `/health/redis` checks Redis connection
4. `/health/storage` checks S3/disk
5. `/health/queue` checks queue status
6. Response time < 100ms
7. Tests verify all health checks

**Tasks**:
- [ ] Create `HealthCheckController`
- [ ] Implement database health check
- [ ] Implement Redis health check
- [ ] Implement storage health check
- [ ] Implement queue health check
- [ ] Add health check tests
- [ ] Configure monitoring alerts
- [ ] Document health check API

**Risk Reduction**: 🟠 MEDIUM (7/10) - Enables monitoring  
**Effort**: 12 hours  
**Rollback**: Remove health endpoints

---

### Day 18: Queue Monitoring

**User Story**: As operations, I need to monitor queue health and alert on issues

**Acceptance Criteria**:
1. Queue size monitored every minute
2. Alert triggered if queue > 1000 jobs
3. Failed jobs monitored hourly
4. Alert triggered if failed jobs > 10
5. Queue worker status tracked
6. Dashboard shows queue metrics

**Tasks**:
- [ ] Create `QueueMonitorCommand`
- [ ] Add queue size monitoring
- [ ] Add failed jobs monitoring
- [ ] Configure Slack/email alerts
- [ ] Create queue metrics dashboard
- [ ] Write queue monitoring tests
- [ ] Document alerting thresholds

**Risk Reduction**: 🟠 MEDIUM (6/10) - Prevents silent failures  
**Effort**: 6 hours  
**Rollback**: Remove monitoring command

---

### Day 19: Redis Monitoring

**User Story**: As operations, I need to monitor Redis health and memory usage

**Acceptance Criteria**:
1. Redis memory usage tracked
2. Alert triggered if memory > 80%
3. Redis connection errors logged
4. Failover events tracked
5. Redis metrics dashboard created

**Tasks**:
- [ ] Create `RedisMonitorCommand`
- [ ] Add memory usage monitoring
- [ ] Add connection error tracking
- [ ] Configure memory alerts
- [ ] Create Redis metrics dashboard
- [ ] Write Redis monitoring tests
- [ ] Document Redis thresholds

**Risk Reduction**: 🟠 MEDIUM (6/10) - Prevents Redis issues  
**Effort**: 5 hours  
**Rollback**: Remove monitoring command

---

### Day 20: Disk Space Monitoring

**User Story**: As operations, I need to monitor disk space and prevent full disk

**Acceptance Criteria**:
1. Disk space checked hourly
2. Alert triggered if disk > 90% full
3. Old exports cleaned automatically
4. Disk usage dashboard created
5. Tests verify cleanup logic

**Tasks**:
- [ ] Create `DiskMonitorCommand`
- [ ] Add disk space monitoring
- [ ] Implement old file cleanup
- [ ] Configure disk alerts
- [ ] Create disk usage dashboard
- [ ] Write disk monitoring tests
- [ ] Document cleanup policy

**Risk Reduction**: 🟠 MEDIUM (6/10) - Prevents disk full  
**Effort**: 4 hours  
**Rollback**: Remove monitoring command

---

### Day 21-22: Backup & Restore Testing

**User Story**: As operations, I need verified backup/restore procedures

**Acceptance Criteria**:
1. Database backup runs daily
2. Backup stored in S3 with encryption
3. Restore procedure documented
4. Restore tested successfully
5. Backup retention policy configured
6. RTO < 1 hour, RPO < 24 hours

**Tasks**:
- [ ] Configure automated database backups
- [ ] Set up S3 backup storage
- [ ] Document restore procedure
- [ ] Test restore on staging
- [ ] Configure backup retention
- [ ] Create backup monitoring
- [ ] Write backup verification tests
- [ ] Document disaster recovery plan

**Risk Reduction**: 🔴 HIGH (9/10) - Ensures data recovery  
**Effort**: 12 hours  
**Rollback**: N/A (backup only)

---

### Day 23: Chaos Testing Dry Run

**User Story**: As operations, I need to verify system resilience under failure

**Acceptance Criteria**:
1. Redis failure tested (system continues)
2. Database slow query tested (timeout works)
3. Disk full tested (graceful error)
4. High load tested (rate limiting works)
5. All tests documented

**Tasks**:
- [ ] Create chaos testing scenarios
- [ ] Test Redis failure scenario
- [ ] Test database failure scenario
- [ ] Test disk full scenario
- [ ] Test high load scenario
- [ ] Document test results
- [ ] Create runbook for failures
- [ ] Update monitoring based on tests

**Risk Reduction**: 🟠 MEDIUM (7/10) - Validates resilience  
**Effort**: 8 hours  
**Rollback**: N/A (testing only)

---

### Week 4 Deliverables

**Monitoring**:
- ✅ Health check endpoints (5 endpoints)
- ✅ Queue monitoring with alerts
- ✅ Redis monitoring with alerts
- ✅ Disk space monitoring with alerts
- ✅ Backup monitoring

**Documentation**:
- ✅ Health check API documentation
- ✅ Monitoring runbook
- ✅ Disaster recovery plan
- ✅ Chaos testing results
- ✅ Alerting thresholds guide

**Tests Added**:
- ✅ Health check tests (15 tests)
- ✅ Queue monitoring tests (8 tests)
- ✅ Redis monitoring tests (6 tests)
- ✅ Disk monitoring tests (5 tests)
- ✅ Backup verification tests (8 tests)

**Risk Reduction Score**: 🟠 35/50 (70%) - Production ready

---

## 📊 Overall 30-Day Summary

### Total Risk Reduction

**Week 1**: 45/50 (90%) - Data Integrity ✅  
**Week 2**: 42/50 (84%) - Concurrency ✅  
**Week 3**: 30/50 (60%) - Performance ✅  
**Week 4**: 35/50 (70%) - Observability ✅

**Total**: 152/200 (76%) - Production Hardened

### Total Effort

**Week 1**: 28 hours  
**Week 2**: 23 hours  
**Week 3**: 32 hours  
**Week 4**: 47 hours

**Total**: 130 hours (3.25 weeks of full-time work)

### Tests Added

**Total**: 200+ tests across all categories

### Documentation

- ✅ 15+ technical guides
- ✅ 4 runbooks
- ✅ 1 disaster recovery plan
- ✅ Updated coding standards

---

## 🔄 Rollback Plans

### Week 1 Rollback
```bash
# Revert timezone changes
git revert <timezone-commits>

# Drop unique constraint
php artisan migrate:rollback --step=1

# Restore old queue jobs
git revert <queue-commits>
```

### Week 2 Rollback
```bash
# Remove middleware
git revert <middleware-commits>

# Restore cache config
git checkout HEAD~5 config/cache.php

# Revert webhook changes
git revert <webhook-commits>
```

### Week 3 Rollback
```bash
# Drop indexes
php artisan migrate:rollback --step=1

# Drop summary table
php artisan migrate:rollback --step=1

# Restore old queries
git revert <query-commits>
```

### Week 4 Rollback
```bash
# Remove monitoring (safe, no rollback needed)
# Monitoring is additive only
```

---

## ✅ Definition of Done

Each task is considered done when:
1. ✅ Code implemented and reviewed
2. ✅ Tests written and passing
3. ✅ Documentation updated
4. ✅ Deployed to staging
5. ✅ Verified in staging
6. ✅ Rollback plan documented
7. ✅ Team trained (if needed)

---

## 🚀 Deployment Strategy

**Principle**: Deploy small, deploy often, deploy safely

**Week 1**: Deploy daily to staging, Friday to production  
**Week 2**: Deploy daily to staging, Friday to production  
**Week 3**: Deploy daily to staging, Friday to production  
**Week 4**: Deploy monitoring only (no code changes)

**Rollback Window**: 24 hours for each deployment

---

## 📞 Stakeholder Communication

**Daily**: Standup with engineering team  
**Weekly**: Sprint review with CTO  
**End of Month**: Executive summary with metrics

**Metrics to Track**:
- Bug count reduction
- System uptime
- Response time improvements
- Revenue leak prevention
- Customer satisfaction

