# DB::table() Quick Reference - Replacement Guide

**Quick lookup for Task 5.2 implementation**

## Files Requiring Immediate Replacement (Priority Order)

### Priority 1: Student Management
- `app/Services/SchoolAdmin/StudentService.php` (11 instances)
  - Replace: `user_profiles`, `class_students`, `classes` tables
  - Models: UserProfile, ClassStudent, SchoolClass

### Priority 2: Security Services
- `app/Services/SecurityPolicyService.php` (6 instances)
  - Replace: `security_policies` table
  - Model: SecurityPolicy (needs creation)

- `app/Services/SecurityAuditService.php` (1 instance)
  - Replace: `teacher_devices` table
  - Model: TeacherDevice (needs creation)

- `app/Services/SecurityMonitoringService.php` (1 instance)
  - Replace: `students` table
  - Model: Student

### Priority 3: Teacher Management
- `app/Services/SchoolAdmin/TeacherService.php` (4 instances)
  - Replace: `user_profiles` table
  - Model: UserProfile

### Priority 4: Analytics Services
- `app/Services/RiskAnalysisService.php` (3 instances)
  - Replace: `attendances`, `student_attendance_risk` tables
  - Models: Attendance, StudentAttendanceRisk

- `app/Services/StudentSubjectAttendanceAnalysisService.php` (1 instance)
  - Replace: `attendances` table
  - Model: Attendance

- `app/Services/StudentNotificationService.php` (1 instance)
  - Replace: `attendances` table
  - Model: Attendance

- `app/Services/StudentArrivalConsistencyService.php` (1 instance)
  - Replace: `attendances` table
  - Model: Attendance

### Priority 5: Limits
- `app/Traits/HasSchoolLimits.php` (1 instance)
  - Replace: `classes` table
  - Model: SchoolClass

## Models to Create

These models don't exist yet and need to be created:

1. **SecurityPolicy** - for `security_policies` table
2. **TeacherDevice** - for `teacher_devices` table
3. **UserProfile** - for `user_profiles` table (if not exists)
4. **ClassStudent** - for `class_students` pivot table
5. **StudentAttendanceRisk** - for `student_attendance_risk` table
6. **BackupExecution** - for `backup_executions` table (optional)

## Common Replacement Patterns

### Pattern 1: Simple Query
```php
// BEFORE
DB::table('classes')->where('school_id', $schoolId)->count()

// AFTER
SchoolClass::where('school_id', $schoolId)->count()
// OR (if global scope exists)
SchoolClass::count()
```

### Pattern 2: Insert
```php
// BEFORE
DB::table('user_profiles')->insert([...])

// AFTER
UserProfile::create([...])
```

### Pattern 3: Update or Insert
```php
// BEFORE
DB::table('user_profiles')->updateOrInsert(['user_id' => $id], [...])

// AFTER
UserProfile::updateOrCreate(['user_id' => $id], [...])
```

### Pattern 4: Join Query
```php
// BEFORE
DB::table('class_students')
    ->join('classes', 'class_students.class_id', '=', 'classes.id')
    ->where('class_students.student_id', $studentId)
    ->where('classes.school_id', $schoolId)

// AFTER
ClassStudent::whereHas('class', function($q) use ($schoolId) {
    $q->where('school_id', $schoolId);
})->where('student_id', $studentId)
```

### Pattern 5: Aggregation
```php
// BEFORE
DB::table('attendances')
    ->where('student_id', $studentId)
    ->whereBetween('attendance_date', [$start, $end])
    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present')
    ->first()

// AFTER
Attendance::where('student_id', $studentId)
    ->whereBetween('attendance_date', [$start, $end])
    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present')
    ->first()
```

## Global Scopes to Verify

Ensure these models have proper global scopes:

- ✅ Attendance - has BelongsToSchool trait
- ✅ SchoolClass - has BelongsToSchool trait
- ❓ UserProfile - needs verification
- ❓ ClassStudent - needs verification
- ❓ SecurityPolicy - needs creation with scope
- ❓ TeacherDevice - needs creation with scope
- ❓ StudentAttendanceRisk - needs verification

## Testing Checklist

After each replacement:
- [ ] Run existing feature tests
- [ ] Verify multi-tenant isolation
- [ ] Check query performance
- [ ] Test with multiple schools
- [ ] Verify global scope application

## Notes

- System tables (jobs, failed_jobs) can remain as DB::table()
- Migration files should NOT be changed
- Test files can remain as DB::table() for test data setup
- Scripts may need case-by-case evaluation
