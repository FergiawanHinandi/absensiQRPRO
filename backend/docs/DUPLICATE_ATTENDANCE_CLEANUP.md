# Duplicate Attendance Cleanup - Task 2.2

## Overview

This document describes the implementation of Task 2.2: Create cleanup script for duplicate attendance records. This is part of the SaaS Hardening 30-Day Roadmap, specifically Day 2 of Week 1 focused on Data Integrity & Tenant Safety.

## Purpose

After identifying duplicate attendance records (Task 2.1), we need a safe and auditable way to clean them up before applying the unique constraint. The cleanup script:

1. **Identifies duplicates** based on `(student_id, schedule_id, attendance_date, school_id)`
2. **Keeps the oldest record** (lowest ID) as the source of truth
3. **Soft deletes duplicates** to maintain audit trail
4. **Logs all actions** for compliance and debugging
5. **Provides dry-run mode** for safe preview

## Cleanup Strategy

### Decision: Keep Oldest Record

**Rationale**: The oldest record (lowest ID) is most likely the original, legitimate attendance entry. Later duplicates are likely caused by:
- Race conditions during concurrent scans
- Retry logic creating duplicate entries
- Manual entry after QR scan
- System bugs or network issues

### Soft Delete vs Hard Delete

**Decision**: Use soft delete (`deleted_at` timestamp)

**Benefits**:
- Maintains complete audit trail
- Allows recovery if needed
- Preserves data for forensic analysis
- Complies with data retention policies

## Implementation

### 1. Artisan Command (Recommended)

The primary tool for cleanup is the Laravel Artisan command:

```bash
php artisan attendance:cleanup-duplicates
```

#### Options

- `--dry-run` : Preview changes without executing (safe mode)
- `--export` : Export cleanup report to JSON file
- `--force` : Skip confirmation prompt (for automation)

#### Examples

```bash
# Preview changes (safe)
php artisan attendance:cleanup-duplicates --dry-run

# Preview and export report
php artisan attendance:cleanup-duplicates --dry-run --export

# Execute cleanup with confirmation
php artisan attendance:cleanup-duplicates

# Execute cleanup without confirmation (automation)
php artisan attendance:cleanup-duplicates --force

# Execute and export report
php artisan attendance:cleanup-duplicates --export
```

### 2. Standalone PHP Script

For environments where Artisan is not available:

```bash
php database/scripts/cleanup_duplicate_attendances.php [--dry-run] [--export]
```

This script:
- Works without Laravel Artisan
- Requires Laravel bootstrap
- Supports same options as Artisan command
- Interactive confirmation prompt

### 3. Manual SQL (Not Recommended)

For emergency situations only:

```sql
-- WARNING: This permanently deletes data!
-- Use the Artisan command or PHP script instead

-- Soft delete duplicates (keeps oldest)
UPDATE attendances a1
INNER JOIN (
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        MIN(id) as keep_id
    FROM attendances
    WHERE deleted_at IS NULL
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
) a2 ON a1.student_id = a2.student_id
    AND a1.schedule_id = a2.schedule_id
    AND a1.attendance_date = a2.attendance_date
    AND a1.school_id = a2.school_id
    AND a1.id > a2.keep_id
SET a1.deleted_at = NOW(),
    a1.updated_at = NOW();
```

## Output Format

### Summary Table

```
┌────────┬───────────┬────────────┬─────────────┬────────────┬───────┬─────────┬───────────┐
│ Set #  │ School ID │ Student ID │ Schedule ID │ Date       │ Count │ Keep ID │ To Remove │
├────────┼───────────┼────────────┼─────────────┼────────────┼───────┼─────────┼───────────┤
│ 1      │ 1         │ 123        │ 456         │ 2026-02-01 │ 3     │ 1001    │ 2         │
│ 2      │ 1         │ 124        │ 456         │ 2026-02-01 │ 2     │ 1005    │ 1         │
└────────┴───────────┴────────────┴─────────────┴────────────┴───────┴─────────┴───────────┘
```

### Cleanup Results

```
===========================================
Cleanup Results:
===========================================
✅ Successfully removed: 3 records
✅ Kept: 2 records

Cleanup by School:
  School ID 1: 3 record(s) removed
```

### Export Report (JSON)

When using `--export`, a detailed JSON report is saved to `storage/app/logs/`:

```json
{
    "removed_count": 3,
    "kept_count": 2,
    "by_school": {
        "1": 3
    },
    "removed_ids": [1002, 1003, 1006],
    "kept_ids": [1001, 1005],
    "timestamp": "2026-02-09 15:30:45"
}
```

## Safety Features

### 1. Dry Run Mode

Always test with `--dry-run` first:

```bash
# Safe preview
php artisan attendance:cleanup-duplicates --dry-run
```

Output shows what WOULD be removed without making changes.

### 2. Confirmation Prompt

Without `--force`, the command asks for confirmation:

```
⚠️  WARNING: This will soft delete duplicate records!
Strategy: Keep oldest record (lowest ID), soft delete others

Do you want to proceed with cleanup? (yes/no):
```

### 3. Progress Indicator

Shows progress during cleanup:

```
Step 2: Cleaning up duplicates...
....................
```

### 4. Audit Logging

All cleanup operations are logged to Laravel log:

```php
Log::info('Duplicate attendance cleanup completed', [
    'removed_count' => 3,
    'kept_count' => 2,
    'by_school' => ['1' => 3],
    'timestamp' => '2026-02-09 15:30:45',
]);
```

## Database Compatibility

The implementation supports both MySQL and PostgreSQL:

- **MySQL**: Uses `GROUP_CONCAT()` function
- **PostgreSQL**: Uses `STRING_AGG()` function

The command automatically detects the database driver and uses the appropriate function.

## Workflow

### Step-by-Step Process

1. **Query duplicates** (Task 2.1)
   ```bash
   php artisan attendance:query-duplicates
   ```

2. **Preview cleanup** (Task 2.2 - dry run)
   ```bash
   php artisan attendance:cleanup-duplicates --dry-run --export
   ```

3. **Review export report**
   ```bash
   cat storage/app/logs/dry_run_duplicate_cleanup_*.json
   ```

4. **Execute cleanup** (Task 2.2)
   ```bash
   php artisan attendance:cleanup-duplicates --export
   ```

5. **Verify cleanup**
   ```bash
   php artisan attendance:query-duplicates
   # Should show: "No duplicate attendance records found!"
   ```

6. **Apply unique constraint** (Task 2.3)
   ```bash
   php artisan migrate
   ```

## Recovery Procedure

If cleanup needs to be reversed:

```sql
-- Restore soft-deleted records
UPDATE attendances
SET deleted_at = NULL,
    updated_at = NOW()
WHERE id IN (1002, 1003, 1006);  -- IDs from export report
```

Or using Eloquent:

```php
use App\Models\Attendance;

// Restore specific IDs
Attendance::withTrashed()
    ->whereIn('id', [1002, 1003, 1006])
    ->restore();

// Restore all recently deleted (last hour)
Attendance::onlyTrashed()
    ->where('deleted_at', '>', now()->subHour())
    ->restore();
```

## Testing

### Manual Testing

```bash
# 1. Create test duplicates (development only)
php artisan tinker
>>> $student = User::where('role', 'student')->first();
>>> $schedule = Schedule::first();
>>> Attendance::factory()->count(3)->create([
...     'student_id' => $student->id,
...     'schedule_id' => $schedule->id,
...     'attendance_date' => today(),
...     'school_id' => $student->school_id,
... ]);

# 2. Query duplicates
php artisan attendance:query-duplicates

# 3. Dry run cleanup
php artisan attendance:cleanup-duplicates --dry-run

# 4. Execute cleanup
php artisan attendance:cleanup-duplicates

# 5. Verify
php artisan attendance:query-duplicates
```

### Automated Testing

Tests will be written in Task 2.5:

```php
// tests/Feature/DuplicateAttendanceCleanupTest.php
test('cleanup keeps oldest record and soft deletes duplicates')
test('cleanup respects school_id boundaries')
test('cleanup logs all actions')
test('dry run mode does not modify database')
```

## Performance Considerations

### Large Datasets

For databases with many duplicates (>1000 sets):

1. **Use dry-run first** to estimate time
2. **Run during off-peak hours** to minimize impact
3. **Monitor database load** during cleanup
4. **Consider batching** for very large datasets (>10,000 duplicates)

### Optimization

The cleanup script:
- Uses indexed columns for queries
- Processes in single transaction per duplicate set
- Shows progress indicator for long operations
- Minimal memory footprint (processes one set at a time)

## Security Considerations

### Multi-Tenant Safety

The script respects `school_id` boundaries:
- Duplicates are identified per school
- Cleanup is isolated by school
- No cross-tenant data access

### Audit Trail

All cleanup actions are:
- Logged to application log
- Exportable to JSON for compliance
- Reversible via soft delete restore
- Timestamped for forensics

## Next Steps

After successful cleanup:

1. ✅ **Task 2.2 Complete**: Cleanup script created and tested
2. **Task 2.3**: Create migration with unique constraint
3. **Task 2.4**: Update application code to use `firstOrCreate()`
4. **Task 2.5**: Write duplicate prevention tests

## Files Created

1. `app/Console/Commands/CleanupDuplicateAttendances.php` - Artisan command
2. `database/scripts/cleanup_duplicate_attendances.php` - Standalone PHP script
3. `docs/DUPLICATE_ATTENDANCE_CLEANUP.md` - This documentation

## Risk Assessment

- **Risk Level**: 🔴 CRITICAL (10/10)
- **Impact**: Removes duplicate data (soft delete, reversible)
- **Effort**: 2 hours (estimated)
- **Rollback**: Restore soft-deleted records from export report

## References

- Spec: `.kiro/specs/saas-hardening-30-days/requirements.md`
- Design: `.kiro/specs/saas-hardening-30-days/design.md`
- Tasks: `.kiro/specs/saas-hardening-30-days/tasks.md`
- Task 2.1: `docs/DUPLICATE_ATTENDANCE_QUERY.md`

## Troubleshooting

### Issue: "No duplicates found" but constraint fails

**Solution**: Check for soft-deleted duplicates:

```sql
SELECT student_id, schedule_id, attendance_date, school_id, COUNT(*)
FROM attendances
-- Don't filter by deleted_at
GROUP BY student_id, schedule_id, attendance_date, school_id
HAVING COUNT(*) > 1;
```

### Issue: Cleanup takes too long

**Solution**: Run in batches or during maintenance window:

```bash
# Process in smaller batches (modify script if needed)
php artisan attendance:cleanup-duplicates --force
```

### Issue: Need to restore deleted records

**Solution**: Use export report to identify and restore:

```php
$report = json_decode(file_get_contents('storage/app/logs/duplicate_cleanup_*.json'));
Attendance::withTrashed()->whereIn('id', $report->removed_ids)->restore();
```
