# SaaS Hardening - Task List

## WEEK 1: DATA INTEGRITY & TENANT SAFETY

### Day 1: Timezone Consistency
- [x] 1.1 Audit all date() and time() usage
- [x] 1.2 Create TimezoneHelper utility class
- [x] 1.3 Replace date() with TimezoneHelper::now()
- [x] 1.4 Add timezone to school settings
- [x] 1.5 Write timezone tests (10 tests)

### Day 2: Unique Attendance Constraint
- [x] 2.1 Query existing duplicates
- [x] 2.2 Create cleanup script
- [x] 2.3 Create migration with unique constraint
- [x] 2.4 Update to firstOrCreate()
- [x] 2.5 Write duplicate tests (8 tests)

### Day 3: Queue Job Tenant Context
- [x] 3.1 Audit all queue jobs
- [x] 3.2 Create TenantAwareJob base class
- [x] 3.3 Update ExportAttendanceReport
- [x] 3.4 Update all dispatchers
- [x] 3.5 Write tenant tests (12 tests)

### Day 4: State Machine Enforcement
- [x] 4.1 Block direct status modification
- [x] 4.2 Update seeders/factories
- [x] 4.3 Add audit logging
- [x] 4.4 Write state tests (15 tests)

### Day 5: DB::table() Elimination
- [x] 5.1 Search DB::table() usage
- [x] 5.2 Replace with Eloquent
- [x] 5.3 Add PHPStan rule
- [x] 5.4 Write scope tests (6 tests)

## WEEK 2: CONCURRENCY & WEBHOOK

### Day 6: Deadlock Retry
- [x] 6.1 Create DeadlockRetryMiddleware
- [x] 6.2 Add exponential backoff
- [x] 6.3 Write deadlock tests (8 tests)

### Day 7: Redis Health Guard
- [x] 7.1 Configure failover cache
- [x] 7.2 Create health check endpoint
- [x] 7.3 Write Redis tests (10 tests)

### Day 8: Webhook Idempotency
- [x] 8.1 Increase lock timeout to 300s
- [x] 8.2 Add markAsProcessing()
- [x] 8.3 Write webhook tests (12 tests)

### Day 9: Cache Stampede
- [-] 9.1 Implement cache lock pattern
- [ ] 9.2 Write stampede tests (6 tests)

### Day 10: Subscription Cache Fix
- [ ] 10.1 Reduce TTL to 60s
- [ ] 10.2 Add expires_at validation
- [ ] 10.3 Write cache tests (8 tests)

## WEEK 3: PERFORMANCE

### Day 11: Database Indexes
- [ ] 11.1 Run slow query analysis
- [ ] 11.2 Create index migration
- [ ] 11.3 Write index tests (8 tests)

### Day 12: N+1 Elimination
- [ ] 12.1 Audit controllers
- [ ] 12.2 Add eager loading
- [ ] 12.3 Write query tests (12 tests)

### Day 13: Summary Table
- [ ] 13.1 Create summary table
- [ ] 13.2 Add observer
- [ ] 13.3 Write summary tests (10 tests)

### Day 14: Export Chunking
- [ ] 14.1 Use cursor() in exports
- [ ] 14.2 Write chunking tests (6 tests)

### Day 15: Query Profiling
- [ ] 15.1 Enable query logging
- [ ] 15.2 Optimize top 10 queries
- [ ] 15.3 Write performance tests (8 tests)

## WEEK 4: OBSERVABILITY

### Day 16-17: Health Checks
- [ ] 16.1 Create HealthCheckController
- [ ] 16.2 Implement all endpoints
- [ ] 16.3 Write health tests (15 tests)

### Day 18: Queue Monitoring
- [ ] 18.1 Create QueueMonitorCommand
- [ ] 18.2 Configure alerts
- [ ] 18.3 Write monitor tests (8 tests)

### Day 19: Redis Monitoring
- [ ] 19.1 Create RedisMonitorCommand
- [ ] 19.2 Write Redis tests (6 tests)

### Day 20: Disk Monitoring
- [ ] 20.1 Create DiskMonitorCommand
- [ ] 20.2 Write disk tests (5 tests)

### Day 21-22: Backup Testing
- [ ] 21.1 Configure backups
- [ ] 21.2 Test restore
- [ ] 21.3 Write backup tests (8 tests)

### Day 23: Chaos Testing
- [ ] 23.1 Test failure scenarios
- [ ] 23.2 Document runbooks

**Total**: 181 tasks, 200+ tests, 130 hours

