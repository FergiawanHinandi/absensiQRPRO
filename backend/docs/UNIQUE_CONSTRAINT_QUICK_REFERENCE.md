# Unique Attendance Constraint - Quick Reference

## Task 2.3: Create Migration with Unique Constraint

### Quick Commands

```bash
# 1. Check for duplicates (prerequisite)
php artisan attendance:query-duplicates

# 2. Clean up duplicates if found (prerequisite)
php artisan attendance:cleanup-duplicates

# 3. Run the migration
php artisan migrate

# 4. Verify constraint exists
php artisan tinker
>>> DB::select("SHOW INDEX FROM attendances WHERE Key_name = 'unique_attendance_per_day'");

# 5. Run tests
php artisan test --filter=UniqueAttendanceConstraintMigrationTest
```

### Constraint Details

**Name**: `unique_attendance_per_day`

**Columns**: `(student_id, schedule_id, attendance_date, school_id)`

**Purpose**: Prevent duplicate attendance records with multi-tenant isolation

### Migration File

```
backend/database/migrations/2026_02_10_000001_add_unique_attendance_constraint_with_school_id.php
```

### What It Does

1. ✅ Checks for duplicate records (fails if found)
2. ✅ Drops old constraints (without school_id)
3. ✅ Adds new constraint (with school_id)
4. ✅ Verifies constraint creation
5. ✅ Logs all operations

### Error Handling

**Error**: "Cannot add unique constraint with duplicate data"
```bash
# Solution:
php artisan attendance:cleanup-duplicates
php artisan migrate
```

**Error**: "Duplicate key name"
```bash
# Solution: Constraint already exists, check status
php artisan migrate:status
```

### Rollback

```bash
# Rollback the migration
php artisan migrate:rollback --step=1

# This will:
# - Drop new constraint (unique_attendance_per_day)
# - Restore old constraint (unique_attendance_per_schedule)
```

### Testing

```bash
# Run all constraint tests
php artisan test --filter=UniqueAttendanceConstraintMigrationTest

# Run specific test
php artisan test --filter=test_prevents_duplicate_attendance
```

### Manual Verification

```php
// In Tinker
php artisan tinker

// Test constraint
$student = User::where('role', 'student')->first();
$schedule = Schedule::first();

// First attendance (should work)
$a1 = Attendance::create([
    'student_id' => $student->id,
    'schedule_id' => $schedule->id,
    'attendance_date' => today(),
    'school_id' => $student->school_id,
    'status' => 'present',
]);

// Duplicate (should fail)
$a2 = Attendance::create([
    'student_id' => $student->id,
    'schedule_id' => $schedule->id,
    'attendance_date' => today(),
    'school_id' => $student->school_id,
    'status' => 'late',
]);
// Expected: QueryException with code 23000
```

### Application Code Pattern

```php
// ✅ CORRECT: Use firstOrCreate
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
    ]
);

// ❌ WRONG: Direct create (will fail on duplicate)
$attendance = Attendance::create([
    'student_id' => $studentId,
    'schedule_id' => $scheduleId,
    'attendance_date' => $date,
    'school_id' => $schoolId,
    'status' => 'present',
]);
```

### Next Steps

- [ ] Task 2.4: Update application code to use `firstOrCreate()`
- [ ] Task 2.5: Write duplicate prevention tests

### Documentation

- Full docs: `backend/docs/UNIQUE_ATTENDANCE_CONSTRAINT.md`
- Migration: `backend/database/migrations/2026_02_10_000001_add_unique_attendance_constraint_with_school_id.php`
- Tests: `backend/tests/Feature/UniqueAttendanceConstraintMigrationTest.php`

### Risk Level

🔴 **CRITICAL (10/10)** - Prevents all future duplicate attendance records

### Rollback Plan

```bash
php artisan migrate:rollback --step=1
```

Safe to rollback within 24 hours of deployment.
