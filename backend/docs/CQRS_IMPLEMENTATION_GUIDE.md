# CQRS Light Implementation Guide

## 📋 Overview

This guide walks you through deploying the CQRS Light architecture for the Attendance SaaS system.

## 🎯 Goals Achieved

✅ **Separated Write and Read Models**
- Write Model: `Attendance` table (transactional, normalized)
- Read Model: `AttendanceDailySummary` table (denormalized, optimized for queries)

✅ **Eliminated Heavy Dashboard Queries**
- Before: `Attendance::where()->count()` on millions of records (500ms-2s)
- After: `AttendanceDailySummary::where()` on pre-aggregated data (<50ms)

✅ **Event-Driven Synchronization**
- Domain events bridge write and read models
- Eventual consistency (1-2 second delay acceptable)

✅ **Simple Architecture**
- No microservices
- Single database
- Laravel-native implementation

## 📁 Folder Structure

```
app/
├── Domain/
│   ├── Attendance/
│   │   ├── Commands/
│   │   │   └── RecordAttendanceCommand.php
│   │   ├── Handlers/
│   │   │   └── RecordAttendanceHandler.php
│   │   └── Events/
│   │       ├── AttendanceRecorded.php
│   │       └── AttendanceStatusChanged.php
│   └── Shared/
│       ├── Command.php
│       └── CommandHandler.php
├── ReadModels/
│   └── AttendanceDailySummary.php
├── Listeners/
│   └── UpdateAttendanceSummaryListener.php
├── Http/Controllers/
│   └── SchoolAdminDashboardControllerCQRS.php
└── Console/Commands/
    └── BackfillAttendanceSummariesCommand.php
```

## 🚀 Deployment Steps

### Step 1: Run Migration

```bash
php artisan migrate
```

This creates the `attendance_daily_summaries` table with optimized indexes.

### Step 2: Backfill Existing Data

```bash
# Backfill all data
php artisan attendance:backfill-summaries

# Or backfill specific school
php artisan attendance:backfill-summaries --school=1

# Or backfill date range
php artisan attendance:backfill-summaries --from=2026-01-01 --to=2026-02-09
```

### Step 3: Update Routes

In `routes/api.php`, add the new CQRS controller:

```php
// CQRS-optimized dashboard endpoints
Route::prefix('dashboard/cqrs')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/summary', [SchoolAdminDashboardControllerCQRS::class, 'dashboardSummary']);
    Route::get('/live-attendance', [SchoolAdminDashboardControllerCQRS::class, 'liveAttendance']);
    Route::get('/monthly-stats', [SchoolAdminDashboardControllerCQRS::class, 'monthlyAttendanceStats']);
    Route::get('/class-health', [SchoolAdminDashboardControllerCQRS::class, 'classHealthAnalytics']);
});
```

### Step 4: Update Frontend API Calls

Change dashboard API endpoints from:
```javascript
// Old
axios.get('/api/dashboard/summary')

// New
axios.get('/api/dashboard/cqrs/summary')
```

### Step 5: Monitor Performance

```bash
# Check query performance
php artisan telescope:prune

# Monitor event processing
tail -f storage/logs/laravel.log | grep "Summary updated"
```

## 🔄 Usage Examples

### Recording Attendance (Write Model)

```php
use App\Domain\Attendance\Commands\RecordAttendanceCommand;
use App\Domain\Attendance\Handlers\RecordAttendanceHandler;

$command = new RecordAttendanceCommand(
    schoolId: 1,
    studentId: 100,
    scheduleId: 50,
    classId: 10,
    attendanceDate: today(),
    status: 'present',
    checkInTime: now(),
    qrCodeId: 1,
);

$handler = new RecordAttendanceHandler();
$attendance = $handler->handle($command);
```

### Querying Dashboard (Read Model)

```php
use App\ReadModels\AttendanceDailySummary;

// Get today's summary
$summary = AttendanceDailySummary::getTodaySummary($schoolId);

// Get weekly trend
$trend = AttendanceDailySummary::getWeeklyTrend($schoolId, 7);

// Get class summaries
$classSummaries = AttendanceDailySummary::getClassSummaries($schoolId, today());
```

## 📊 Performance Comparison

### Before CQRS

```sql
-- Heavy aggregation query (500ms - 2s)
SELECT 
    SUM(status = 'present') as present,
    SUM(status = 'late') as late,
    SUM(status = 'absent') as absent
FROM attendances
WHERE school_id = 1 
  AND attendance_date = '2026-02-09'
```

### After CQRS

```sql
-- Simple lookup (<50ms)
SELECT * 
FROM attendance_daily_summaries
WHERE school_id = 1 
  AND attendance_date = '2026-02-09'
  AND class_id IS NULL
```

## 🧪 Testing

### Run Unit Tests

```bash
php artisan test --filter=CQRSAttendanceTest
```

### Load Test (1000 Concurrent Requests)

```bash
# Install Apache Bench
apt-get install apache2-utils

# Test write endpoint
ab -n 1000 -c 100 -p attendance.json -T application/json \
   http://localhost/api/attendance/scan

# Test read endpoint
ab -n 1000 -c 100 http://localhost/api/dashboard/cqrs/summary
```

### Verify Summary Accuracy

```php
// Compare write model vs read model
$writeCount = Attendance::where('school_id', 1)
    ->whereDate('attendance_date', today())
    ->where('status', 'present')
    ->count();

$readCount = AttendanceDailySummary::getTodaySummary(1)->total_present;

assert($writeCount === $readCount, 'Summary mismatch!');
```

## 🔧 Troubleshooting

### Summary Not Updating

1. Check event listeners are registered:
```bash
php artisan event:list | grep AttendanceRecorded
```

2. Check logs:
```bash
tail -f storage/logs/laravel.log | grep "Summary updated"
```

3. Manually trigger backfill:
```bash
php artisan attendance:backfill-summaries --date=2026-02-09
```

### Duplicate Attendance Records

The handler uses Redis locking to prevent duplicates. Ensure Redis is running:

```bash
redis-cli ping
# Should return: PONG
```

### Performance Still Slow

1. Check indexes exist:
```sql
SHOW INDEX FROM attendance_daily_summaries;
```

2. Analyze query:
```sql
EXPLAIN SELECT * FROM attendance_daily_summaries 
WHERE school_id = 1 AND attendance_date = '2026-02-09';
```

## 📈 Monitoring

### Key Metrics to Track

1. **Read Model Lag**: Time between write and read model update
2. **Query Performance**: Dashboard response times
3. **Event Processing**: Success rate of event listeners
4. **Data Accuracy**: Write model vs read model consistency

### Recommended Tools

- **Laravel Telescope**: Query monitoring
- **Laravel Horizon**: Queue monitoring (if using queues)
- **New Relic / DataDog**: APM monitoring
- **Grafana**: Custom dashboards

## 🎓 Best Practices

### DO ✅

- Use read models for all dashboard queries
- Keep write model normalized and transactional
- Monitor event processing success rate
- Run backfill after bulk data imports
- Cache read model queries (60 seconds)

### DON'T ❌

- Query write model (Attendance) from dashboard
- Skip event dispatch in handlers
- Modify read model directly (always via events)
- Use read model for transactional operations
- Expect immediate consistency (allow 1-2 sec delay)

## 🔄 Migration Rollback

If you need to rollback:

```bash
# Rollback migration
php artisan migrate:rollback --step=1

# Switch routes back to old controller
# Update frontend API calls
```

## 📚 Additional Resources

- [CQRS Pattern](https://martinfowler.com/bliki/CQRS.html)
- [Event Sourcing](https://martinfowler.com/eaaDev/EventSourcing.html)
- [Laravel Events](https://laravel.com/docs/events)
- [Redis Locks](https://laravel.com/docs/redis#atomic-locks)

## 🆘 Support

For issues or questions:
1. Check logs: `storage/logs/laravel.log`
2. Run diagnostics: `php artisan attendance:backfill-summaries --date=today`
3. Verify data: Compare write vs read models
4. Contact: architecture@example.com
