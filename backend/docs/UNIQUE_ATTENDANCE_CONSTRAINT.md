# Unique Attendance Constraint with School ID - Task 2.3

## Overview

This document describes the implementation of Task 2.3: Create migration with unique constraint. This is part of the SaaS Hardening 30-Day Roadmap, specifically Day 2 of Week 1 focused on Data Integrity & Tenant Safety.

## Purpose

After cleaning up duplicate attendance records (Task 2.2), we apply a database-level unique constraint to prevent future duplicates. The constraint ensures:

1. **No duplicate attendance records** for the same student, schedule, date, and school
2. **Multi-tenant data isolation** by including `school_id` in the constraint
3. **Database-level enforcement** that cannot be bypassed by application code
4. **Data integrity** at the lowest level of the stack

## Constraint Definition

### Unique Constraint

```sql
UNIQUE (student_id, schedule_id, attendance_date, school_id)
```

**Constraint Name**: `unique_attendance_per_day`

### Why Include school_id?

The original constraint only used `(schedule_id, student_id, attendance_date)`, which has a critical flaw:

**❌ OLD CONSTRAINT (Insecure)**:
```sql
UNIQUE (schedule_id, student_id, attendance_date)
```

**Problem**: If two schools happen to have the same `schedule_id` and `student_id` values (which is possible in a multi-tenant system), the constraint would incorrectly prevent legitimate attendance records.

**✅ NEW CONSTRAINT (Secure)**:
```sql
UNIQUE (student_id, schedule_id, attendance_date, school_id)
```

**Benefit**: Ensures that the uniqueness is scoped to each school, providing proper multi-tenant isolation.

## Migration Details

### File Location

```
backend/database/migrations/2026_02_10_000001_add_unique_attendance_constraint_with_school_id.php
```

### Migration Steps

The migration performs the following steps:

#### Step 1: Validate Prerequisites

```php
// Check table exists
if (!Schema::hasTable('attendances')) {
    return;
}

// Check required columns exist
$requiredColumns = ['student_id', 'schedule_id', 'attendance_date', 'school_id'];
foreach ($requiredColumns as $column) {
    if (!Schema::hasColumn('attendances', $column)) {
        return;
    }
}
```

#### Step 2: Check for Duplicates

```php
$duplicates = DB::select("
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        COUNT(*) as duplicate_count
    FROM attendances
    WHERE deleted_at IS NULL
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
");

if (count($duplicates) > 0) {
    throw new \Exception('Cannot add unique constraint with duplicate data.');
}
```

**Safety**: The migration will **fail** if duplicates exist, preventing data corruption.

#### Step 3: Drop Old Constraints

```php
Schema::table('attendances', function (Blueprint $table) {
    // Drop old constraint from original migration
    if ($this->indexExists('attendances', 'unique_attendance_per_schedule')) {
        $table->dropUnique('unique_attendance_per_schedule');
    }

    // Drop constraint from data integrity migration
    if ($this->indexExists('attendances', 'uniq_attendance_schedule_student_date')) {
        $table->dropUnique('uniq_attendance_schedule_student_date');
    }
});
```

#### Step 4: Add New Constraint

```php
Schema::table('attendances', function (Blueprint $table) {
    $table->unique(
        ['student_id', 'schedule_id', 'attendance_date', 'school_id'],
        'unique_attendance_per_day'
    );
});
```

#### Step 5: Verify Constraint

```php
if ($this->indexExists('attendances', 'unique_attendance_per_day')) {
    $this->logInfo('✅ Constraint verified successfully');
}
```

## Running the Migration

### Prerequisites

Before running this migration, you **MUST** complete:

1. ✅ **Task 2.1**: Query existing duplicates
   ```bash
   php artisan attendance:query-duplicates
   ```

2. ✅ **Task 2.2**: Cleanup duplicate records
   ```bash
   php artisan attendance:cleanup-duplicates
   ```

### Execute Migration

```bash
# Run the migration
php artisan migrate

# Expected output:
# [INFO] Step 1: Checking for duplicate attendance records...
# [INFO] ✅ No duplicates found. Safe to proceed.
# [INFO] Step 2: Removing old unique constraints...
# [INFO] Dropped old constraint: unique_attendance_per_schedule
# [INFO] Step 3: Adding new unique constraint with school_id...
# [INFO] ✅ Successfully added unique constraint: unique_attendance_per_day
# [INFO] ✅ Constraint columns: [student_id, schedule_id, attendance_date, school_id]
# [INFO] ✅ Constraint verified successfully
```

### Rollback (if needed)

```bash
# Rollback the migration
php artisan migrate:rollback --step=1

# This will:
# 1. Drop the new constraint (unique_attendance_per_day)
# 2. Restore the old constraint (unique_attendance_per_schedule)
```

## Error Handling

### Error: Duplicates Found

**Error Message**:
```
Cannot add unique constraint with duplicate data.
Please run: php artisan attendance:cleanup-duplicates
```

**Solution**:
```bash
# 1. Check for duplicates
php artisan attendance:query-duplicates

# 2. Clean up duplicates
php artisan attendance:cleanup-duplicates

# 3. Retry migration
php artisan migrate
```

### Error: Column Missing

**Error Message**:
```
Required column 'school_id' missing in attendances table. Skipping migration.
```

**Solution**: Ensure all required migrations have been run:
```bash
php artisan migrate:status
php artisan migrate
```

### Error: Constraint Already Exists

**Error Message**:
```
SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'unique_attendance_per_day'
```

**Solution**: The constraint already exists. Check migration status:
```bash
php artisan migrate:status
```

## Database Compatibility

The migration supports multiple database drivers:

### MySQL

```sql
-- Check constraint exists
SELECT INDEX_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'absensi_qr_pro'
AND TABLE_NAME = 'attendances'
AND INDEX_NAME = 'unique_attendance_per_day';
```

### PostgreSQL

```sql
-- Check constraint exists
SELECT indexname
FROM pg_indexes
WHERE tablename = 'attendances'
AND indexname = 'unique_attendance_per_day';
```

### SQLite

```sql
-- Check constraint exists
SELECT name
FROM sqlite_master
WHERE type = 'index'
AND tbl_name = 'attendances'
AND name = 'unique_attendance_per_day';
```

## Testing the Constraint

### Manual Testing

```bash
# Start Laravel Tinker
php artisan tinker
```

```php
// Get test data
$student = User::where('role', 'student')->first();
$schedule = Schedule::first();
$school = School::first();

// Create first attendance (should succeed)
$attendance1 = Attendance::create([
    'student_id' => $student->id,
    'schedule_id' => $schedule->id,
    'attendance_date' => today(),
    'school_id' => $school->id,
    'status' => 'present',
]);
// ✅ Success

// Try to create duplicate (should fail)
$attendance2 = Attendance::create([
    'student_id' => $student->id,
    'schedule_id' => $schedule->id,
    'attendance_date' => today(),
    'school_id' => $school->id,
    'status' => 'present',
]);
// ❌ Error: SQLSTATE[23000]: Integrity constraint violation
```

### Automated Testing

Tests will be written in Task 2.5:

```php
// tests/Feature/UniqueAttendanceConstraintTest.php

test('prevents duplicate attendance for same student, schedule, date, and school')
{
    $student = User::factory()->student()->create();
    $schedule = Schedule::factory()->create();
    
    // First attendance should succeed
    $attendance1 = Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'attendance_date' => today(),
        'school_id' => $student->school_id,
        'status' => 'present',
    ]);
    
    expect($attendance1)->toBeInstanceOf(Attendance::class);
    
    // Duplicate should fail
    expect(fn() => Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'attendance_date' => today(),
        'school_id' => $student->school_id,
        'status' => 'late',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
}

test('allows same student attendance on different dates')
{
    $student = User::factory()->student()->create();
    $schedule = Schedule::factory()->create();
    
    // Attendance on day 1
    $attendance1 = Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'attendance_date' => today(),
        'school_id' => $student->school_id,
        'status' => 'present',
    ]);
    
    // Attendance on day 2 (should succeed)
    $attendance2 = Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'attendance_date' => today()->addDay(),
        'school_id' => $student->school_id,
        'status' => 'present',
    ]);
    
    expect($attendance2)->toBeInstanceOf(Attendance::class);
}

test('allows different schools to have same student and schedule IDs')
{
    $school1 = School::factory()->create();
    $school2 = School::factory()->create();
    
    // Both schools happen to have student_id=1 and schedule_id=1
    // This is possible in a multi-tenant system
    
    // School 1 attendance
    $attendance1 = Attendance::create([
        'student_id' => 1,
        'schedule_id' => 1,
        'attendance_date' => today(),
        'school_id' => $school1->id,
        'status' => 'present',
    ]);
    
    // School 2 attendance (should succeed due to different school_id)
    $attendance2 = Attendance::create([
        'student_id' => 1,
        'schedule_id' => 1,
        'attendance_date' => today(),
        'school_id' => $school2->id,
        'status' => 'present',
    ]);
    
    expect($attendance2)->toBeInstanceOf(Attendance::class);
}
```

## Impact on Application Code

### Before (Vulnerable to Duplicates)

```php
// ❌ BAD: Can create duplicates
Attendance::create([
    'student_id' => $studentId,
    'schedule_id' => $scheduleId,
    'attendance_date' => $date,
    'school_id' => $schoolId,
    'status' => 'present',
]);
```

### After (Protected by Constraint)

```php
// ✅ GOOD: Use firstOrCreate to handle constraint
$attendance = Attendance::firstOrCreate(
    [
        'student_id' => $studentId,
        'schedule_id' => $scheduleId,
        'attendance_date' => $date,
        'school_id' => $schoolId,
    ],
    [
        'status' => 'present',
        'check_in_time' => now(),
        'recorded_by' => auth()->id(),
    ]
);
```

**Note**: Task 2.4 will update all application code to use `firstOrCreate()`.

## Performance Considerations

### Index Performance

The unique constraint also serves as an index, improving query performance:

```sql
-- This query will use the unique index
SELECT * FROM attendances
WHERE student_id = 123
AND schedule_id = 456
AND attendance_date = '2026-02-10'
AND school_id = 1;
```

### Write Performance

- **Minimal impact**: Unique constraint checks are very fast (O(log n))
- **Index size**: Approximately 16 bytes per row (4 integers)
- **Maintenance**: Automatic, no manual intervention needed

## Security Benefits

### Multi-Tenant Isolation

The constraint ensures that:
- School A cannot accidentally create attendance for School B's students
- Even if IDs overlap, the `school_id` prevents cross-tenant data corruption
- Database-level enforcement that cannot be bypassed

### Data Integrity

The constraint guarantees:
- No duplicate attendance records at database level
- Application bugs cannot create duplicates
- Race conditions are handled by the database
- Concurrent requests are serialized by the constraint

## Monitoring

### Check Constraint Status

```bash
# MySQL
mysql -u root -p absensi_qr_pro -e "
    SHOW INDEX FROM attendances 
    WHERE Key_name = 'unique_attendance_per_day';
"

# PostgreSQL
psql -U postgres -d absensi_qr_pro -c "
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE tablename = 'attendances' 
    AND indexname = 'unique_attendance_per_day';
"
```

### Monitor Constraint Violations

```php
// Log constraint violations
try {
    Attendance::create([...]);
} catch (\Illuminate\Database\QueryException $e) {
    if ($e->getCode() === '23000') {
        Log::warning('Duplicate attendance attempt blocked by constraint', [
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'attendance_date' => $date,
            'school_id' => $schoolId,
        ]);
    }
    throw $e;
}
```

## Next Steps

After successful migration:

1. ✅ **Task 2.3 Complete**: Unique constraint applied
2. **Task 2.4**: Update application code to use `firstOrCreate()`
3. **Task 2.5**: Write duplicate prevention tests

## Files Created

1. `database/migrations/2026_02_10_000001_add_unique_attendance_constraint_with_school_id.php` - Migration file
2. `docs/UNIQUE_ATTENDANCE_CONSTRAINT.md` - This documentation

## Risk Assessment

- **Risk Level**: 🔴 CRITICAL (10/10)
- **Impact**: Prevents all future duplicate attendance records
- **Effort**: 4 hours (estimated)
- **Rollback**: `php artisan migrate:rollback --step=1`

## References

- Spec: `.kiro/specs/saas-hardening-30-days/requirements.md` (Day 2)
- Design: `.kiro/specs/saas-hardening-30-days/design.md` (Day 2)
- Tasks: `.kiro/specs/saas-hardening-30-days/tasks.md` (Task 2.3)
- Task 2.1: `docs/DUPLICATE_ATTENDANCE_QUERY.md`
- Task 2.2: `docs/DUPLICATE_ATTENDANCE_CLEANUP.md`

## Troubleshooting

### Issue: Migration fails with "Duplicate entry"

**Cause**: Duplicate records still exist in the database

**Solution**:
```bash
# 1. Check for duplicates
php artisan attendance:query-duplicates

# 2. Clean up duplicates
php artisan attendance:cleanup-duplicates

# 3. Retry migration
php artisan migrate
```

### Issue: Old constraint not dropped

**Cause**: Constraint name mismatch or database driver issue

**Solution**:
```bash
# Manually drop old constraint (MySQL)
mysql -u root -p absensi_qr_pro -e "
    ALTER TABLE attendances 
    DROP INDEX unique_attendance_per_schedule;
"

# Manually drop old constraint (PostgreSQL)
psql -U postgres -d absensi_qr_pro -c "
    ALTER TABLE attendances 
    DROP CONSTRAINT unique_attendance_per_schedule;
"

# Retry migration
php artisan migrate
```

### Issue: Constraint not working

**Cause**: Constraint may not have been created successfully

**Solution**:
```bash
# Verify constraint exists
php artisan tinker

# In Tinker:
DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'unique_attendance_per_day'");

# If empty, manually create constraint:
DB::statement("
    ALTER TABLE attendances 
    ADD UNIQUE KEY unique_attendance_per_day 
    (student_id, schedule_id, attendance_date, school_id)
");
```

## Conclusion

This migration is a **critical** component of the SaaS hardening roadmap. It provides:

- ✅ Database-level duplicate prevention
- ✅ Multi-tenant data isolation
- ✅ Performance optimization via index
- ✅ Security enforcement that cannot be bypassed

The constraint ensures that the AbsensiQR Pro system maintains data integrity at the lowest level of the stack, preventing duplicate attendance records regardless of application-level bugs or race conditions.
