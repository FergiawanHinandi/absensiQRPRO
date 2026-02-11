# Attendance Unique Constraint Audit & Fix

## Overview

This document describes the audit and fix for attendance unique constraints to properly handle soft deletes while preventing double check-ins.

## Problem Statement

### Original Issue
The previous unique constraint `unique_attendance_per_schedule` was a regular (non-partial) unique index:
```sql
UNIQUE (schedule_id, student_id, attendance_date)
```

**Problems:**
1. Soft-deleted records still occupy the unique key space
2. Re-inserting after soft delete fails with "duplicate key violation"
3. Correction workflow broken (cannot re-record after deleting erroneous entry)

### Business Rules

1. **Active Duplicate Prevention**: A student can have ONLY ONE active check-in per schedule per day
2. **Soft Delete Compatibility**: Deleted records should NOT block new inserts
3. **Correction History**: Multiple soft-deleted records must be allowed for audit trail

## Solution

### PostgreSQL (Recommended)

**Partial Unique Index** - Only enforces uniqueness on active records:

```sql
CREATE UNIQUE INDEX uk_attendance_active_unique
ON attendances (student_id, schedule_id, attendance_date, attendance_type)
WHERE deleted_at IS NULL;
```

**Behavior:**
- ✅ Active duplicate insert → FAILS (unique violation)
- ✅ Insert after soft delete → SUCCEEDS
- ✅ Multiple soft-deleted records → ALLOWED

### MySQL 8.0.13+

**Generated Column Approach** - MySQL doesn't support partial indexes:

```sql
-- Add generated column
ALTER TABLE attendances ADD COLUMN unique_active_key VARCHAR(150) 
GENERATED ALWAYS AS (
    CASE 
        WHEN deleted_at IS NULL 
        THEN CONCAT(student_id, '-', COALESCE(schedule_id, 0), '-', attendance_date, '-', COALESCE(attendance_type, 'in'))
        ELSE NULL 
    END
) STORED;

-- Create unique index (NULL values excluded from uniqueness check)
CREATE UNIQUE INDEX uk_attendance_active_unique ON attendances (unique_active_key);
```

**Behavior:**
- Active records get computed key: `"42-15-2026-02-07-in"`
- Soft-deleted records get `NULL` (excluded from unique check)

### SQLite (Testing)

Same as PostgreSQL - partial index support since SQLite 3.8.0:

```sql
CREATE UNIQUE INDEX uk_attendance_active_unique
ON attendances (student_id, schedule_id, attendance_date, attendance_type)
WHERE deleted_at IS NULL;
```

## Migration Details

**File:** `database/migrations/2026_02_07_200000_audit_attendance_unique_constraints.php`

### Step 1: Drop Conflicting Constraints

```php
$constraintsToDrop = [
    'unique_attendance_per_schedule',
    'unique_attendance_with_type',
    'uk_attendance_student_schedule_date',
    'uk_attendance_soft_delete_safe',
    // ... other variants
];
```

### Step 2: Create Soft-Delete-Safe Constraint

Automatically detects database driver and applies appropriate solution.

### Step 3: Add Performance Indexes

| Index Name | Columns | Purpose |
|------------|---------|---------|
| `idx_attendance_school_date_v2` | `(school_id, attendance_date)` | Admin reports |
| `idx_attendance_student_date_v2` | `(student_id, attendance_date)` | Student history |
| `idx_attendance_deleted_at` | `(deleted_at)` | Soft delete queries |
| `idx_attendance_duplicate_check_v2` | `(student_id, schedule_id, attendance_date, attendance_type, deleted_at)` | Duplicate prevention |

## SQL Raw Final

### PostgreSQL

```sql
-- Drop old constraints
DROP INDEX IF EXISTS unique_attendance_per_schedule;
DROP INDEX IF EXISTS unique_attendance_with_type;
DROP INDEX IF EXISTS uk_attendance_student_schedule_date;
DROP INDEX IF EXISTS uk_attendance_soft_delete_safe;
DROP INDEX IF EXISTS uk_attendance_type_soft_delete_safe;

-- Create soft-delete-safe unique constraint
CREATE UNIQUE INDEX uk_attendance_active_unique
ON attendances (student_id, schedule_id, attendance_date, attendance_type)
WHERE deleted_at IS NULL;

-- Add comment for documentation
COMMENT ON INDEX uk_attendance_active_unique IS 
'Prevents double check-in/out. Allows re-insert after soft delete. Only active records are checked.';

-- Performance indexes
CREATE INDEX IF NOT EXISTS idx_attendance_school_date_v2 
ON attendances (school_id, attendance_date);

CREATE INDEX IF NOT EXISTS idx_attendance_student_date_v2 
ON attendances (student_id, attendance_date);

CREATE INDEX IF NOT EXISTS idx_attendance_deleted_at 
ON attendances (deleted_at);

CREATE INDEX IF NOT EXISTS idx_attendance_duplicate_check_v2 
ON attendances (student_id, schedule_id, attendance_date, attendance_type, deleted_at);
```

### MySQL

```sql
-- Drop old constraints
ALTER TABLE attendances DROP INDEX IF EXISTS unique_attendance_per_schedule;
ALTER TABLE attendances DROP INDEX IF EXISTS unique_attendance_with_type;
ALTER TABLE attendances DROP INDEX IF EXISTS uk_attendance_soft_delete_safe;

-- Add generated column for soft-delete-safe unique
ALTER TABLE attendances ADD COLUMN unique_active_key VARCHAR(150) 
GENERATED ALWAYS AS (
    CASE 
        WHEN deleted_at IS NULL 
        THEN CONCAT(
            student_id, 
            '-', 
            COALESCE(schedule_id, 0), 
            '-', 
            attendance_date, 
            '-', 
            COALESCE(attendance_type, 'in')
        )
        ELSE NULL 
    END
) STORED AFTER deleted_at;

-- Create unique index
CREATE UNIQUE INDEX uk_attendance_active_unique ON attendances (unique_active_key);

-- Performance indexes
CREATE INDEX idx_attendance_school_date_v2 ON attendances (school_id, attendance_date);
CREATE INDEX idx_attendance_student_date_v2 ON attendances (student_id, attendance_date);
CREATE INDEX idx_attendance_deleted_at ON attendances (deleted_at);
CREATE INDEX idx_attendance_duplicate_check_v2 
ON attendances (student_id, schedule_id, attendance_date, attendance_type, deleted_at);
```

## Impact on Existing Queries

### Queries That Benefit

1. **Duplicate Check (High Impact)**
   ```php
   // This query uses the new unique index directly
   Attendance::where('student_id', $studentId)
       ->where('schedule_id', $scheduleId)
       ->whereDate('attendance_date', today())
       ->where('attendance_type', 'in')
       ->exists();
   ```

2. **Admin Reports (Medium Impact)**
   ```php
   // Uses idx_attendance_school_date_v2
   Attendance::where('school_id', $schoolId)
       ->whereDate('attendance_date', today())
       ->get();
   ```

3. **Student History (Medium Impact)**
   ```php
   // Uses idx_attendance_student_date_v2
   Attendance::where('student_id', $studentId)
       ->orderBy('attendance_date', 'desc')
       ->paginate();
   ```

### Queries That Need Attention

1. **Queries Including Soft-Deleted Records**
   ```php
   // May be slower without deleted_at index - now optimized
   Attendance::withTrashed()
       ->where('student_id', $studentId)
       ->get();
   ```

2. **Force Delete Queries**
   ```php
   // No change - but ensure audit trail is preserved
   Attendance::where('id', $id)->forceDelete();
   ```

### No Breaking Changes

All existing queries continue to work. The only change is:
- **Before**: Duplicate insert after soft delete → ERROR
- **After**: Duplicate insert after soft delete → SUCCESS

## Testing Strategy

### Unit Tests

**File:** `tests/Feature/AttendanceUniqueConstraintTest.php`

| Test | Description | Expected |
|------|-------------|----------|
| `it_prevents_duplicate_active_check_in` | Insert same student+schedule+date+type twice | QueryException |
| `it_prevents_duplicate_active_check_out` | Insert two check-outs same day | QueryException |
| `it_allows_insert_after_soft_delete` | Soft delete then re-insert | Success |
| `it_allows_multiple_soft_deleted_records` | Multiple delete/insert cycles | All succeed |
| `it_allows_different_attendance_types_same_day` | Check-in + check-out same day | Both succeed |
| `it_allows_different_schedules_same_day` | Same student, different schedules | Both succeed |
| `it_has_soft_delete_safe_unique_index` | Verify index exists | Index found |
| `it_prevents_concurrent_duplicate_inserts` | Race condition simulation | One fails |

### Manual Testing

```bash
# Run migration
php artisan migrate

# Run tests
php artisan test --filter=AttendanceUniqueConstraintTest

# Verify index (PostgreSQL)
psql -d your_database -c "\d attendances"

# Verify index (MySQL)
mysql -u root -p your_database -e "SHOW INDEX FROM attendances"
```

## Rollback Strategy

### Safe Rollback (Recommended)

```bash
php artisan migrate:rollback --step=1
```

**What happens:**
1. Drops `uk_attendance_active_unique` index
2. Drops performance indexes (`idx_attendance_*_v2`)
3. Drops MySQL `unique_active_key` column (if exists)
4. Attempts to restore original `unique_attendance_with_type` constraint

### ⚠️ Rollback Warning

If soft-deleted duplicates exist, restoring the original constraint will FAIL:

```
SQLSTATE[23505]: Unique violation: 7 ERROR: could not create unique index...
Key (student_id, schedule_id, attendance_date, attendance_type)=(42, 15, 2026-02-07, in) is duplicated.
```

**Resolution:**
1. Identify duplicates: 
   ```sql
   SELECT student_id, schedule_id, attendance_date, attendance_type, COUNT(*) 
   FROM attendances 
   WHERE deleted_at IS NOT NULL
   GROUP BY student_id, schedule_id, attendance_date, attendance_type 
   HAVING COUNT(*) > 1;
   ```
2. Force delete older soft-deleted records (preserving latest)
3. Retry rollback

### Emergency Rollback (PostgreSQL)

```sql
-- Remove new constraint
DROP INDEX IF EXISTS uk_attendance_active_unique;

-- Restore old constraint (will fail if duplicates exist)
CREATE UNIQUE INDEX unique_attendance_with_type 
ON attendances (schedule_id, student_id, attendance_date, attendance_type);
```

### Emergency Rollback (MySQL)

```sql
-- Remove generated column and constraint
ALTER TABLE attendances DROP INDEX uk_attendance_active_unique;
ALTER TABLE attendances DROP COLUMN unique_active_key;

-- Restore old constraint
CREATE UNIQUE INDEX unique_attendance_with_type 
ON attendances (schedule_id, student_id, attendance_date, attendance_type);
```

## Monitoring

### After Migration

1. **Check index usage (PostgreSQL)**:
   ```sql
   SELECT indexrelname, idx_scan, idx_tup_read 
   FROM pg_stat_user_indexes 
   WHERE relname = 'attendances';
   ```

2. **Monitor for duplicate attempts**:
   ```bash
   grep "unique.*violation\|duplicate" storage/logs/laravel.log
   ```

3. **Verify constraint working**:
   ```php
   // Should throw QueryException
   Attendance::create([...same data twice...]);
   ```

## Summary

| Aspect | Before | After |
|--------|--------|-------|
| Double check-in prevention | ✅ Works | ✅ Works |
| Insert after soft delete | ❌ Fails | ✅ Works |
| Multiple corrections | ❌ Fails | ✅ Works |
| Audit trail | ❌ Lost | ✅ Preserved |
| Query performance | Adequate | Optimized |

## Files Changed

1. **New Migration**: `database/migrations/2026_02_07_200000_audit_attendance_unique_constraints.php`
2. **New Test**: `tests/Feature/AttendanceUniqueConstraintTest.php`
3. **Documentation**: `docs/16_ATTENDANCE_UNIQUE_CONSTRAINT_AUDIT.md` (this file)
