# Attendance Summary Table Design

## Overview

This document describes the design of the `attendance_summaries` table to optimize dashboard queries by pre-aggregating attendance data.

## Current Problem

Dashboard queries in `AdminDashboardService` perform multiple complex joins and aggregations:
- Join attendances → schedules → classes → users
- Group by class_id, status, student_id
- Count distinct students
- Calculate alpha (no-show) students

These queries can take 500ms-2s on large datasets (1000+ students, 50+ classes).

## Solution: Pre-Aggregated Summary Table

Create a summary table that stores daily attendance counts per class, updated in real-time via Eloquent observers.

## Schema Design

### Table: `attendance_summaries`

```sql
CREATE TABLE attendance_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    
    -- Student counts by status
    total_students INT UNSIGNED NOT NULL DEFAULT 0,
    present_count INT UNSIGNED NOT NULL DEFAULT 0,
    late_count INT UNSIGNED NOT NULL DEFAULT 0,
    absent_count INT UNSIGNED NOT NULL DEFAULT 0,
    sick_count INT UNSIGNED NOT NULL DEFAULT 0,
    permit_count INT UNSIGNED NOT NULL DEFAULT 0,
    excused_count INT UNSIGNED NOT NULL DEFAULT 0,
    alpha_count INT UNSIGNED NOT NULL DEFAULT 0,  -- No attendance record
    
    -- Metadata
    last_updated_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    -- Indexes
    UNIQUE KEY unique_summary_per_day (school_id, class_id, attendance_date),
    INDEX idx_school_date (school_id, attendance_date),
    INDEX idx_class_date (class_id, attendance_date),
    
    -- Foreign keys
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);
```

### Field Descriptions

- `school_id`: Multi-tenant isolation
- `class_id`: Class identifier
- `attendance_date`: Date of attendance (not datetime)
- `total_students`: Total active students in class on this date
- `present_count`: Students marked present (state: checked_in, checked_out, approved)
- `late_count`: Students marked late
- `absent_count`: Students marked absent (explicit absence)
- `sick_count`: Students marked sick
- `permit_count`: Students with permission/permit
- `excused_count`: Students excused
- `alpha_count`: Students with no attendance record (calculated: total_students - sum of all statuses)
- `last_updated_at`: Timestamp of last summary update

## Update Strategy

### Real-time Updates via Observer

```php
// AttendanceObserver
public function created(Attendance $attendance): void
{
    AttendanceSummaryService::updateSummary(
        $attendance->school_id,
        $attendance->class_id,
        $attendance->attendance_date
    );
}

public function updated(Attendance $attendance): void
{
    AttendanceSummaryService::updateSummary(
        $attendance->school_id,
        $attendance->class_id,
        $attendance->attendance_date
    );
}

public function deleted(Attendance $attendance): void
{
    AttendanceSummaryService::updateSummary(
        $attendance->school_id,
        $attendance->class_id,
        $attendance->attendance_date
    );
}
```

### Batch Recalculation

For data integrity, provide a command to recalculate all summaries:

```bash
php artisan attendance:recalculate-summaries --date=2026-02-16
php artisan attendance:recalculate-summaries --school=1 --from=2026-02-01 --to=2026-02-28
```

## Query Optimization

### Before (Current)

```php
// 5 separate queries with joins
$classes = DB::table('classes')->where('school_id', $schoolId)->get();
$totalStudents = DB::table('class_students')->join('classes')->groupBy()->get();
$attendanceByStatus = DB::table('attendances')->join('schedules')->groupBy()->get();
$attendedStudents = DB::table('attendances')->join('schedules')->groupBy()->get();
// ... then merge in PHP
```

**Performance**: ~500-2000ms for 50 classes, 1000 students

### After (With Summary Table)

```php
// 1 simple query, no joins
$summaries = AttendanceSummary::where('school_id', $schoolId)
    ->where('attendance_date', $date)
    ->with('class:id,name,grade_level')
    ->get();
```

**Performance**: ~10-50ms (95%+ improvement)

## Data Consistency

### Consistency Guarantees

1. **Observer-based updates**: Summaries updated immediately after attendance changes
2. **Transaction safety**: Updates wrapped in database transactions
3. **Idempotent recalculation**: Can safely recalculate without duplicates
4. **Validation**: Periodic validation job to detect drift

### Validation Strategy

```php
// Daily validation job
php artisan schedule:run
// Runs: AttendanceSummaryValidationJob

// Compares summary counts with actual attendance counts
// Logs discrepancies
// Auto-fixes if difference < 5%
// Alerts if difference > 5%
```

## Migration Strategy

### Phase 1: Create Table (Day 13.1-13.2)
- Create migration
- Add indexes
- Deploy to staging

### Phase 2: Populate Historical Data (Day 13.3)
- Create service class
- Backfill last 30 days
- Validate accuracy

### Phase 3: Add Observer (Day 13.4)
- Create observer
- Register in AppServiceProvider
- Test real-time updates

### Phase 4: Update Dashboard (Day 13.5)
- Modify AdminDashboardService
- Update queries to use summary table
- Benchmark performance
- Deploy to production

## Rollback Plan

If issues arise:

```bash
# Disable observer
# In AppServiceProvider::boot()
// Attendance::observe(AttendanceObserver::class); // COMMENTED

# Revert dashboard queries
git revert <commit-hash>

# Drop table (if needed)
php artisan migrate:rollback --step=1
```

## Performance Targets

- Dashboard response time: < 50ms (from 500-2000ms)
- Summary update time: < 10ms per attendance change
- Batch recalculation: < 1 minute per 1000 students
- Storage overhead: ~1KB per class per day (~365KB per class per year)

## Testing Strategy

1. **Unit Tests**: AttendanceSummaryService calculation logic
2. **Integration Tests**: Observer triggers and updates
3. **Performance Tests**: Dashboard query benchmarks
4. **Accuracy Tests**: Compare summary vs raw data
5. **Load Tests**: 1000+ concurrent attendance updates

## Monitoring

- Track summary update latency
- Monitor summary vs raw data drift
- Alert on validation failures
- Dashboard query performance metrics
