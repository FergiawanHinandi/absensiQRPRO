# Duplicate Attendance Query - Task 2.1

## Overview

This document describes the implementation of Task 2.1: Query existing duplicates in the attendance records. This is part of the SaaS Hardening 30-Day Roadmap, specifically Day 2 of Week 1 focused on Data Integrity & Tenant Safety.

## Purpose

Before implementing a unique constraint on the `attendances` table, we need to identify any existing duplicate records based on the combination:
- `student_id`
- `schedule_id`
- `attendance_date`
- `school_id`

## Implementation

### 1. Artisan Command (Recommended)

The primary tool for querying duplicates is the Laravel Artisan command:

```bash
php artisan attendance:query-duplicates
```

#### Options

- `--export` : Export results to a JSON file in `storage/app/logs/`
- `--detailed` : Show detailed information about each duplicate record

#### Examples

```bash
# Basic query
php artisan attendance:query-duplicates

# With detailed information
php artisan attendance:query-duplicates --detailed

# Export to JSON
php artisan attendance:query-duplicates --export

# Both detailed and export
php artisan attendance:query-duplicates --detailed --export
```

### 2. PHP Script

For environments where Artisan is not available:

```bash
php database/scripts/query_duplicate_attendances.php
```

This script:
- Identifies all duplicate sets
- Shows detailed information about each duplicate
- Exports results to JSON
- Provides summary statistics

### 3. SQL Script

For direct database queries:

```bash
# MySQL
mysql -u username -p database_name < database/scripts/query_duplicates.sql

# PostgreSQL
psql -U username -d database_name -f database/scripts/query_duplicates.sql
```

## Output Format

### Summary Table

```
┌────────┬───────────┬────────────┬─────────────┬────────────┬───────┬───────────┐
│ Set #  │ School ID │ Student ID │ Schedule ID │ Date       │ Count │ To Remove │
├────────┼───────────┼────────────┼─────────────┼────────────┼───────┼───────────┤
│ 1      │ 1         │ 123        │ 456         │ 2026-02-01 │ 3     │ 2         │
│ 2      │ 1         │ 124        │ 456         │ 2026-02-01 │ 2     │ 1         │
└────────┴───────────┴────────────┴─────────────┴────────────┴───────┴───────────┘
```

### Summary Statistics

```
Total duplicate sets: 2
Total records to be removed: 3

Duplicates by School:
  School ID 1: 3 duplicate(s)
```

### Detailed Information (--detailed flag)

For each duplicate set, shows:
- ID
- Status (present, late, absent, etc.)
- Check-in time
- Check-out time
- Manual entry flag
- Recorded by (user ID)
- Created timestamp
- Updated timestamp

## Database Compatibility

The implementation supports both MySQL and PostgreSQL:

- **MySQL**: Uses `GROUP_CONCAT()` function
- **PostgreSQL**: Uses `STRING_AGG()` function

The command automatically detects the database driver and uses the appropriate function.

## Current Status

As of the latest execution:

```
✅ No duplicate attendance records found!
The database is clean and ready for the unique constraint.
```

This means:
1. The database has no duplicate records
2. The existing unique constraint `unique_attendance_per_schedule` on `['schedule_id', 'student_id', 'attendance_date']` is working correctly
3. We can proceed to Task 2.2 (Create cleanup script) and Task 2.3 (Create migration with unique constraint)

## Next Steps

1. ✅ **Task 2.1 Complete**: Query existing duplicates - No duplicates found
2. **Task 2.2**: Create cleanup script (can be skipped if no duplicates exist)
3. **Task 2.3**: Create migration to add/update unique constraint to include `school_id`
4. **Task 2.4**: Update application code to use `firstOrCreate()`
5. **Task 2.5**: Write duplicate prevention tests

## Files Created

1. `app/Console/Commands/QueryDuplicateAttendances.php` - Artisan command
2. `database/scripts/query_duplicate_attendances.php` - Standalone PHP script
3. `database/scripts/query_duplicates.sql` - SQL queries
4. `docs/DUPLICATE_ATTENDANCE_QUERY.md` - This documentation

## Technical Notes

### Existing Constraint

The `attendances` table already has a unique constraint:

```php
$table->unique(
    ['schedule_id', 'student_id', 'attendance_date'],
    'unique_attendance_per_schedule'
);
```

### Proposed Constraint

According to the requirements, we need to add `school_id` to the constraint:

```php
$table->unique(
    ['student_id', 'schedule_id', 'attendance_date', 'school_id'],
    'unique_attendance_per_day'
);
```

This provides additional safety for multi-tenant data integrity.

## Risk Assessment

- **Risk Level**: 🔴 CRITICAL (10/10)
- **Impact**: Prevents duplicate attendance records at database level
- **Effort**: 4 hours (estimated)
- **Rollback**: Drop constraint if needed

## Testing

After implementing the unique constraint, verify with:

```bash
# Query again to confirm no duplicates
php artisan attendance:query-duplicates

# Run tests
php artisan test --filter=AttendanceTest
```

## References

- Spec: `.kiro/specs/saas-hardening-30-days/requirements.md`
- Design: `.kiro/specs/saas-hardening-30-days/design.md`
- Tasks: `.kiro/specs/saas-hardening-30-days/tasks.md`
