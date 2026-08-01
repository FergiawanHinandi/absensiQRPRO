# Test Migration Guide - Unique Attendance Constraint

## Overview

With the unique constraint now active on the `attendances` table, test files that use `Attendance::create()` directly may encounter constraint violations. This guide shows how to update tests to work with the constraint.

**Constraint**: `unique_attendance_per_day` on `(student_id, schedule_id, attendance_date, school_id)`

---

## Quick Start

### Option 1: Use the Helper Trait (Recommended)

```php
<?php

namespace Tests\Feature;

use Tests\Helpers\CreatesUniqueAttendance;
use Tests\TestCase;

class MyTest extends TestCase
{
    use CreatesUniqueAttendance;

    public function test_something()
    {
        // Creates attendance with unique combination
        $attendance = $this->createUniqueAttendance();

        // Create with specific values
        $attendance = $this->createUniqueAttendance([
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
            'status' => 'late',
        ]);

        // Create multiple records
        $attendances = $this->createMultipleUniqueAttendances(10, [
            'student_id' => $this->student->id,
        ]);
    }
}
```

### Option 2: Ensure Unique Combinations Manually

```php
// Use different dates for each record
for ($i = 0; $i < 10; $i++) {
    Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'attendance_date' => now()->addDays($i)->toDateString(), // ✅ Unique
        'school_id' => $school->id,
        'status' => 'present',
        'check_in_time' => '07:00:00',
    ]);
}
```

### Option 3: Use firstOrCreate

```php
// Use firstOrCreate like production code
$attendance = Attendance::firstOrCreate(
    [
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'attendance_date' => now()->toDateString(),
        'school_id' => $school->id,
    ],
    [
        'status' => 'present',
        'check_in_time' => '07:00:00',
    ]
);
```

---

## Common Test Scenarios

### Scenario 1: Testing Student Dashboard (Multiple Records)

**Before:**
```php
// ❌ May create duplicates
for ($i = 0; $i < 25; $i++) {
    Attendance::create([
        'student_id' => $this->student->id,
        'schedule_id' => $this->schedule->id,
        'attendance_date' => now()->subDays($i)->toDateString(),
        'school_id' => $this->school->id,
        'status' => 'present',
    ]);
}
```

**After (Option A - Helper Trait):**
```php
// ✅ Using helper trait
use Tests\Helpers\CreatesUniqueAttendance;

$dates = collect(range(0, 24))->map(fn($i) => now()->subDays($i));
$attendances = $this->createAttendancesForStudent(
    $this->student,
    $dates->toArray(),
    ['status' => 'present']
);
```

**After (Option B - Manual):**
```php
// ✅ Ensure unique dates
for ($i = 0; $i < 25; $i++) {
    Attendance::create([
        'student_id' => $this->student->id,
        'schedule_id' => $this->schedule->id,
        'attendance_date' => now()->subDays($i)->toDateString(), // Already unique
        'school_id' => $this->school->id,
        'status' => 'present',
        'check_in_time' => '07:00:00',
    ]);
}
```

### Scenario 2: Testing Eager Loading (Multiple Students)

**Before:**
```php
// ❌ May create duplicates if students share schedule/date
foreach ($students as $student) {
    Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $this->schedule->id,
        'attendance_date' => now()->toDateString(),
        'school_id' => $this->school->id,
    ]);
}
```

**After:**
```php
// ✅ Each student gets unique record (different student_id)
foreach ($students as $student) {
    Attendance::create([
        'student_id' => $student->id, // ✅ Different student = unique
        'schedule_id' => $this->schedule->id,
        'attendance_date' => now()->toDateString(),
        'school_id' => $this->school->id,
        'status' => 'present',
        'check_in_time' => '07:00:00',
    ]);
}
```

### Scenario 3: Testing State Machine (Needs Unguard)

**Before:**
```php
// ❌ Direct status assignment blocked by state machine
$attendance = Attendance::create([
    'status' => 'present',
    'state' => 'checked_in',
]);
```

**After:**
```php
// ✅ Unguard for testing internal state
Attendance::unguard();
try {
    $attendance = Attendance::create([
        'student_id' => $this->student->id,
        'schedule_id' => $this->schedule->id,
        'attendance_date' => now()->toDateString(),
        'school_id' => $this->school->id,
        'status' => 'present',
        'state' => 'checked_in',
    ]);
} finally {
    Attendance::reguard();
}
```

### Scenario 4: Testing Constraint Enforcement

**Testing that duplicates are prevented:**
```php
use Tests\Helpers\CreatesUniqueAttendance;

public function test_unique_constraint_prevents_duplicates()
{
    // Create first attendance
    $attendance = $this->createUniqueAttendance([
        'student_id' => $this->student->id,
        'schedule_id' => $this->schedule->id,
        'attendance_date' => '2026-03-04',
        'school_id' => $this->school->id,
    ]);

    // Attempt to create duplicate
    Attendance::unguard();
    try {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->createDuplicateAttendance($attendance);
    } finally {
        Attendance::reguard();
    }
}
```

### Scenario 5: Testing Race Conditions

**Testing concurrent scan prevention:**
```php
public function test_concurrent_scans_prevented()
{
    // First scan succeeds
    $attendance1 = Attendance::firstOrCreate(
        [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->toDateString(),
            'school_id' => $this->school->id,
        ],
        ['status' => 'present', 'check_in_time' => '07:00:00']
    );

    $this->assertTrue($attendance1->wasRecentlyCreated);

    // Second scan returns existing record
    $attendance2 = Attendance::firstOrCreate(
        [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->toDateString(),
            'school_id' => $this->school->id,
        ],
        ['status' => 'present', 'check_in_time' => '07:05:00']
    );

    $this->assertFalse($attendance2->wasRecentlyCreated);
    $this->assertEquals($attendance1->id, $attendance2->id);
}
```

---

## Helper Trait Methods

### createUniqueAttendance(array $overrides = [])

Creates a single attendance record with guaranteed unique combination.

```php
$attendance = $this->createUniqueAttendance([
    'student_id' => $student->id,
    'status' => 'late',
]);
```

### createMultipleUniqueAttendances(int $count, array $overrides = [])

Creates multiple attendance records with unique dates.

```php
$attendances = $this->createMultipleUniqueAttendances(10, [
    'student_id' => $student->id,
    'school_id' => $school->id,
]);
```

### createAttendanceOnDate($date, array $overrides = [])

Creates attendance for a specific date.

```php
$attendance = $this->createAttendanceOnDate('2026-03-04', [
    'student_id' => $student->id,
]);
```

### createAttendancesForStudent(User $student, array $dates, array $overrides = [])

Creates multiple attendance records for one student across different dates.

```php
$dates = [
    now()->subDays(1),
    now()->subDays(2),
    now()->subDays(3),
];

$attendances = $this->createAttendancesForStudent($student, $dates);
```

### createDuplicateAttendance(Attendance $existing)

Intentionally creates a duplicate (for testing constraint enforcement).

```php
Attendance::unguard();
try {
    $this->expectException(\Illuminate\Database\QueryException::class);
    $this->createDuplicateAttendance($existingAttendance);
} finally {
    Attendance::reguard();
}
```

---

## Files Requiring Updates

### High Priority (Direct create() calls)

1. `tests/Unit/Services/AttendanceStateMachineTest.php` - Use unguard
2. `tests/Feature/Api/V1/Student/StudentDashboardTest.php` - Use unique dates
3. `tests/Feature/EagerLoadingTest.php` - Use unique combinations
4. `tests/Feature/CriticalAttendanceFlowTest.php` - Use unique dates
5. `tests/Feature/ChangeAttendanceStatusTest.php` - Use unique dates
6. `tests/Feature/DatabaseIndexTest.php` - Use unique dates
7. `tests/Feature/DashboardCacheUpdateTest.php` - Use unique dates

### Already Correct ✅

1. `tests/Feature/UniqueAttendanceConstraintTest.php` - Uses unguard
2. `tests/Feature/FinalPerformanceTest.php` - Tests constraint
3. All production service files - Use firstOrCreate

---

## Testing Checklist

After updating tests:

- [ ] Run full test suite: `php artisan test`
- [ ] Check for constraint violations: Look for `unique_attendance_per_day` errors
- [ ] Verify test data isolation: Each test should clean up properly
- [ ] Test constraint enforcement: Add tests for duplicate prevention
- [ ] Test firstOrCreate behavior: Verify `wasRecentlyCreated` flag

---

## Common Errors and Solutions

### Error: "Integrity constraint violation: unique_attendance_per_day"

**Cause**: Test is creating duplicate attendance records.

**Solution**: Use unique dates or different students/schedules.

```php
// ❌ Bad
Attendance::create(['attendance_date' => '2026-03-04', ...]);
Attendance::create(['attendance_date' => '2026-03-04', ...]); // Duplicate!

// ✅ Good
Attendance::create(['attendance_date' => '2026-03-04', ...]);
Attendance::create(['attendance_date' => '2026-03-05', ...]); // Unique date
```

### Error: "Cannot assign to property status"

**Cause**: State machine blocks direct status assignment.

**Solution**: Use `Attendance::unguard()` for tests.

```php
Attendance::unguard();
try {
    $attendance = Attendance::create(['status' => 'present', ...]);
} finally {
    Attendance::reguard();
}
```

---

## Best Practices

1. **Use the helper trait** for new tests
2. **Ensure unique combinations** when using create() directly
3. **Use unguard sparingly** - only for state machine tests
4. **Test constraint enforcement** - add tests that verify duplicates are prevented
5. **Clean up test data** - use RefreshDatabase trait
6. **Document test intent** - add comments explaining why unguard is used

---

## Migration Timeline

**Phase 1** (Immediate): Update failing tests
**Phase 2** (This week): Migrate all tests to use helper trait
**Phase 3** (Next sprint): Add comprehensive constraint tests

---

## Support

If you encounter issues:

1. Check `backend/docs/attendance-creation-audit.md` for production code status
2. Review `tests/Feature/UniqueAttendanceConstraintTest.php` for examples
3. Use the helper trait: `Tests\Helpers\CreatesUniqueAttendance`
4. Ask in #engineering-support channel
