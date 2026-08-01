# Query Optimization Guide

## Overview

This guide documents common slow query patterns identified in AbsensiQR Pro and provides optimization strategies for each pattern.

**Generated**: 2026-02-23  
**Spec**: SaaS Hardening 30-Day Roadmap - Week 3 Day 15

---

## Common Slow Query Patterns

### 1. N+1 Query Problem

**Pattern**: Multiple queries executed in a loop to fetch related data.

**Example**:
```php
// ❌ BAD: N+1 queries
$students = Student::where('school_id', $schoolId)->get();
foreach ($students as $student) {
    echo $student->class->name; // Triggers additional query per student
}
```

**Solution**:
```php
// ✅ GOOD: Eager loading
$students = Student::where('school_id', $schoolId)
    ->with('class')
    ->get();
foreach ($students as $student) {
    echo $student->class->name; // No additional query
}
```

**Impact**: Reduces query count from N+1 to 2 queries.

---

### 2. Missing Indexes

**Pattern**: Queries performing full table scans on large tables.

**Indicators**:
- Execution plan shows "Full Table Scan" or "Seq Scan"
- Query time increases linearly with table size
- WHERE clauses on unindexed columns

**Example**:
```sql
-- Slow query without index
SELECT * FROM attendances 
WHERE school_id = 1 
AND attendance_date >= '2026-02-01'
AND status = 'present';
```

**Solution**:
```php
// Add composite index
Schema::table('attendances', function (Blueprint $table) {
    $table->index(['school_id', 'attendance_date', 'status'], 'idx_school_date_status');
});
```

**Impact**: Reduces query time from seconds to milliseconds.

---

### 3. SELECT * Queries

**Pattern**: Fetching all columns when only a few are needed.

**Example**:
```php
// ❌ BAD: Fetches all columns
$students = Student::where('school_id', $schoolId)->get();
```

**Solution**:
```php
// ✅ GOOD: Select only needed columns
$students = Student::where('school_id', $schoolId)
    ->select('id', 'name', 'class_id')
    ->get();
```

**Impact**: Reduces data transfer and memory usage by 50-80%.

---

### 4. Subqueries in SELECT

**Pattern**: Correlated subqueries executed for each row.

**Example**:
```sql
-- ❌ BAD: Subquery executed per row
SELECT 
    s.id,
    s.name,
    (SELECT COUNT(*) FROM attendances WHERE student_id = s.id) as attendance_count
FROM students s;
```

**Solution**:
```sql
-- ✅ GOOD: Use JOIN with GROUP BY
SELECT 
    s.id,
    s.name,
    COUNT(a.id) as attendance_count
FROM students s
LEFT JOIN attendances a ON a.student_id = s.id
GROUP BY s.id, s.name;
```

**Impact**: Reduces query time by 10-100x depending on data size.

---

### 5. Unoptimized JOINs

**Pattern**: Multiple JOINs without proper indexes on join columns.

**Example**:
```sql
-- Slow without indexes on foreign keys
SELECT * FROM attendances a
JOIN students s ON a.student_id = s.id
JOIN classes c ON s.class_id = c.id
WHERE a.school_id = 1;
```

**Solution**:
```php
// Ensure foreign key indexes exist
Schema::table('attendances', function (Blueprint $table) {
    $table->index('student_id');
    $table->index('school_id');
});

Schema::table('students', function (Blueprint $table) {
    $table->index('class_id');
});
```

---

### 6. Large OFFSET Pagination

**Pattern**: Using OFFSET for deep pagination.

**Example**:
```php
// ❌ BAD: Slow for large offsets
$students = Student::where('school_id', $schoolId)
    ->offset(10000)
    ->limit(50)
    ->get();
```

**Solution**:
```php
// ✅ GOOD: Cursor-based pagination
$students = Student::where('school_id', $schoolId)
    ->where('id', '>', $lastId)
    ->limit(50)
    ->get();
```

**Impact**: Maintains constant query time regardless of offset.

---

### 7. Unnecessary Sorting

**Pattern**: Sorting large result sets without indexes.

**Example**:
```php
// ❌ BAD: Sorting without index
$students = Student::where('school_id', $schoolId)
    ->orderBy('created_at', 'desc')
    ->get();
```

**Solution**:
```php
// Add index on sort column
Schema::table('students', function (Blueprint $table) {
    $table->index(['school_id', 'created_at']);
});
```

---

### 8. Redundant Queries

**Pattern**: Executing the same query multiple times.

**Example**:
```php
// ❌ BAD: Query executed multiple times
public function getStats() {
    $total = Attendance::where('school_id', $schoolId)->count();
    $present = Attendance::where('school_id', $schoolId)->where('status', 'present')->count();
    $absent = Attendance::where('school_id', $schoolId)->where('status', 'absent')->count();
}
```

**Solution**:
```php
// ✅ GOOD: Single query with aggregation
public function getStats() {
    $stats = Attendance::where('school_id', $schoolId)
        ->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent
        ')
        ->first();
}
```

---

## Optimization Strategies

### 1. Use Query Caching

Cache frequently accessed, rarely changing data:

```php
$stats = Cache::remember("dashboard_stats:{$schoolId}", 300, function () use ($schoolId) {
    return $this->calculateStats($schoolId);
});
```

### 2. Use Database Views

For complex, frequently used queries:

```sql
CREATE VIEW attendance_summary AS
SELECT 
    school_id,
    attendance_date,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
FROM attendances
GROUP BY school_id, attendance_date;
```

### 3. Use Summary Tables

Pre-aggregate data for dashboards:

```php
// Update summary on attendance change
AttendanceSummary::updateOrCreate(
    [
        'school_id' => $schoolId,
        'date' => $date,
    ],
    [
        'present_count' => $presentCount,
        'absent_count' => $absentCount,
    ]
);
```

### 4. Use Chunking for Large Datasets

Process large datasets in chunks:

```php
// ✅ GOOD: Process in chunks
Attendance::where('school_id', $schoolId)
    ->chunk(1000, function ($attendances) {
        foreach ($attendances as $attendance) {
            // Process each record
        }
    });
```

### 5. Use Raw Queries for Complex Operations

When Eloquent generates inefficient queries:

```php
DB::select('
    SELECT 
        s.id,
        s.name,
        COUNT(a.id) as attendance_count
    FROM students s
    LEFT JOIN attendances a ON a.student_id = s.id
    WHERE s.school_id = ?
    GROUP BY s.id, s.name
', [$schoolId]);
```

---

## Query Profiling Tools

### 1. Enable Query Logging

```env
QUERY_PROFILING_ENABLED=true
QUERY_SLOW_THRESHOLD=100
QUERY_LOG_EXECUTION_PLANS=true
```

### 2. Analyze Slow Queries

```bash
# Analyze queries from last 7 days
php artisan queries:analyze --days=7

# Show top 50 slow queries
php artisan queries:analyze --days=7 --limit=50
```

### 3. View Query Dashboard

Access the query profiling dashboard:
```
GET /api/v1/admin/query-profiling/dashboard?days=7
```

### 4. Get Optimization Suggestions

```
GET /api/v1/admin/query-profiling/suggestions?days=7
```

---

## Index Strategy

### Primary Indexes

1. **Foreign Keys**: Always index foreign key columns
2. **WHERE Clauses**: Index columns frequently used in WHERE
3. **JOIN Columns**: Index columns used in JOIN conditions
4. **ORDER BY**: Index columns used for sorting

### Composite Indexes

Order columns by selectivity (most selective first):

```php
// ✅ GOOD: Most selective column first
$table->index(['school_id', 'attendance_date', 'status']);

// ❌ BAD: Least selective column first
$table->index(['status', 'attendance_date', 'school_id']);
```

### Index Maintenance

```bash
# Analyze table statistics (PostgreSQL)
ANALYZE attendances;

# Rebuild indexes (PostgreSQL)
REINDEX TABLE attendances;
```

---

## Monitoring and Alerts

### Alert Thresholds

- **Warning**: Query > 500ms
- **Critical**: Query > 1000ms

### Metrics to Track

1. Average query execution time
2. Query count per endpoint
3. Queries without indexes
4. N+1 query occurrences
5. Cache hit rate

---

## Best Practices

1. ✅ Always use eager loading for relationships
2. ✅ Add indexes on foreign keys and WHERE columns
3. ✅ Select only needed columns
4. ✅ Use query caching for frequently accessed data
5. ✅ Profile queries in staging before production
6. ✅ Monitor slow query logs regularly
7. ✅ Use chunking for large datasets
8. ✅ Avoid SELECT * in production code
9. ✅ Use composite indexes for multi-column queries
10. ✅ Test query performance with production-like data

---

## Resources

- Laravel Query Optimization: https://laravel.com/docs/queries
- PostgreSQL Performance Tips: https://wiki.postgresql.org/wiki/Performance_Optimization
- Query Profiling Dashboard: `/api/v1/admin/query-profiling/dashboard`
- Slow Query Analysis: `php artisan queries:analyze`

---

**Last Updated**: 2026-02-23  
**Maintained By**: Backend Team
