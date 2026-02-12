# Queue Jobs Tenant Context Audit Report

**Date**: February 10, 2026  
**Task**: 3.1 Audit all queue jobs  
**Spec**: SaaS Hardening 30-Day Roadmap  
**Auditor**: System Analysis

---

## Executive Summary

**Total Jobs Audited**: 12  
**Critical Issues Found**: 5 jobs with tenant context vulnerabilities  
**Medium Issues Found**: 3 jobs with partial tenant context  
**Safe Jobs**: 4 jobs with proper tenant isolation

### Risk Assessment

🔴 **CRITICAL RISK** (5 jobs):
- CalculateAttendanceRisk
- CalculateAttendanceSummary
- RefreshAttendanceSummaries
- UpdateDailyAttendanceSummary
- SendAttendanceNotification

🟠 **MEDIUM RISK** (3 jobs):
- BulkGenerateStudentCards
- ExportTeacherReport
- GenerateSecurityReportJob

✅ **LOW RISK** (4 jobs):
- ExportAttendanceReport
- GenerateReportExport
- SendSecurityAlertNotification
- QueueWorkerHeartbeat

---

## Detailed Audit Results

### 🔴 CRITICAL: CalculateAttendanceRisk

**File**: `backend/app/Jobs/CalculateAttendanceRisk.php`

**Issues**:
1. ❌ No `school_id` parameter in constructor
2. ❌ Processes ALL active students across ALL schools
3. ❌ No tenant isolation in queries
4. ❌ Global scope bypass risk

**Current Code**:
```php
public function handle(): void
{
    // PROBLEM: Fetches ALL students from ALL schools
    $students = User::where('role_type', 'student')
        ->where('is_active', true)
        ->with(['attendances' => function ($query) {
            $query->where('attendance_date', '>=', Carbon::now()->subDays(60));
        }])
        ->get();
}
```

**Risk**: Cross-tenant data processing, potential data leak

**Recommendation**: 
- Add `school_id` to constructor
- Filter students by `school_id`
- Dispatch separate jobs per school

---

### 🔴 CRITICAL: CalculateAttendanceSummary

**File**: `backend/app/Jobs/CalculateAttendanceSummary.php`

**Issues**:
1. ❌ No `school_id` parameter in constructor
2. ❌ Processes ALL schools in single job
3. ❌ Uses chunking but no tenant context guarantee

**Current Code**:
```php
public function handle(): void
{
    // PROBLEM: Processes ALL schools
    School::where('is_active', true)->chunk(10, function ($schools) use ($year, $month) {
        foreach ($schools as $school) {
            $this->processSchool($school, $year, $month);
        }
    });
}
```

**Risk**: Single job failure affects all schools, no tenant isolation

**Recommendation**:
- Accept `school_id` in constructor
- Process one school per job
- Dispatch multiple jobs for multiple schools

---

### 🔴 CRITICAL: RefreshAttendanceSummaries

**File**: `backend/app/Jobs/RefreshAttendanceSummaries.php`

**Issues**:
1. ⚠️ Optional `school_id` parameter (nullable)
2. ❌ When `school_id` is null, processes ALL schools
3. ❌ Full rebuild mode processes all schools without tenant context

**Current Code**:
```php
public function __construct(
    private ?int $schoolId = null,  // PROBLEM: Nullable
    private ?string $date = null,
    private bool $fullRebuild = false
) {}

private function refreshToday(): void
{
    // PROBLEM: Processes ALL schools when schoolId is null
    $schools = DB::table('schools')
        ->where('is_active', true)
        ->pluck('id');
}
```

**Risk**: Tenant context lost when dispatched without `school_id`

**Recommendation**:
- Make `school_id` required (non-nullable)
- Remove global processing modes
- Dispatch separate jobs per school

---

### 🔴 CRITICAL: UpdateDailyAttendanceSummary

**File**: `backend/app/Jobs/UpdateDailyAttendanceSummary.php`

**Issues**:
1. ✅ Receives `AttendanceRecorded` event (has school_id)
2. ✅ Uses `school_id` from attendance
3. ⚠️ BUT: No explicit validation that school_id is set
4. ⚠️ Relies on event data integrity

**Current Code**:
```php
public function handle(): void
{
    $attendance = $this->event->attendance;
    $schoolId = $attendance->school_id;  // Assumes this exists
    
    // Uses schoolId in query - GOOD
    DB::statement("
        INSERT INTO daily_attendance_summaries (school_id, date, ...)
        VALUES (?, ?, ...)
    ", [$schoolId, $date]);
}
```

**Risk**: Medium - depends on event data integrity

**Recommendation**:
- Add explicit validation: `if (!$schoolId) throw exception`
- Add school_id to constructor explicitly

---

### 🔴 CRITICAL: SendAttendanceNotification

**File**: `backend/app/Jobs/SendAttendanceNotification.php`

**Issues**:
1. ✅ Receives `Attendance` model (has school_id)
2. ✅ Loads relationships with school
3. ⚠️ BUT: No explicit school_id filtering in queries
4. ⚠️ Relies on model relationships

**Current Code**:
```php
public function __construct(
    protected Attendance $attendance
) {}

public function handle(): void
{
    $this->attendance->load(['student.parents', 'school']);
    $student = $this->attendance->student;
    $parents = $student->parents;  // No explicit school_id filter
}
```

**Risk**: Low-Medium - relationships should enforce tenant context

**Recommendation**:
- Add explicit school_id validation
- Store school_id in constructor
- Add school_id to audit logs

---

### 🟠 MEDIUM: BulkGenerateStudentCards

**File**: `backend/app/Jobs/BulkGenerateStudentCards.php`

**Issues**:
1. ✅ Receives `adminId` (has school_id via user)
2. ✅ Receives `studentIds` array
3. ⚠️ BUT: No explicit school_id in constructor
4. ⚠️ Relies on admin's school_id

**Current Code**:
```php
public function __construct(
    array $studentIds, 
    int $adminId,  // Has school_id via relationship
    array $filters, 
    bool $forceRegenerate
) {}

public function handle(StudentCardService $service)
{
    $admin = User::findOrFail($this->adminId);
    $students = User::whereIn('id', $this->studentIds)->get();
    // No explicit school_id filter on students query
}
```

**Risk**: Students from different schools could be processed

**Recommendation**:
- Add explicit `school_id` to constructor
- Filter students by school_id: `whereIn('id', $ids)->where('school_id', $schoolId)`

---

### 🟠 MEDIUM: ExportTeacherReport

**File**: `backend/app/Jobs/ExportTeacherReport.php`

**Issues**:
1. ✅ Has `school_id` in constructor
2. ✅ Uses `school_id` in queries
3. ✅ Proper tenant isolation
4. ⚠️ BUT: No validation that teacher belongs to school

**Current Code**:
```php
public function __construct(
    int $teacherId, 
    int $schoolId,  // GOOD: Explicit school_id
    string $month, 
    string $format = 'xlsx'
) {}

public function handle(): void
{
    $teacher = User::findOrFail($this->teacherId);
    // PROBLEM: No check that teacher->school_id === $this->schoolId
    
    $attendances = Attendance::select([...])
        ->whereHas('schedule', function ($query) {
            $query->where('teacher_id', $this->teacherId)
                  ->where('school_id', $this->schoolId);  // GOOD
        })
        ->where('school_id', $this->schoolId)  // GOOD
        ->get();
}
```

**Risk**: Low - queries are properly scoped, but no validation

**Recommendation**:
- Add validation: `if ($teacher->school_id !== $this->schoolId) throw exception`

---

### 🟠 MEDIUM: GenerateSecurityReportJob

**File**: `backend/app/Jobs/GenerateSecurityReportJob.php`

**Issues**:
1. ✅ Has `teacherId` in constructor
2. ⚠️ BUT: No explicit `school_id` in constructor
3. ⚠️ Fetches teacher and uses their school_id
4. ⚠️ Relies on teacher model integrity

**Current Code**:
```php
public function __construct(
    int $teacherId,
    string $range = '7d',
    string $triggerReason = 'critical_behavior_detected'
) {}

public function handle(TeacherSecurityReportService $reportService): void
{
    $teacher = User::with('school')->find($this->teacherId);
    // Uses teacher->school_id implicitly
}
```

**Risk**: Low-Medium - depends on teacher model

**Recommendation**:
- Add explicit `school_id` to constructor
- Validate teacher belongs to school

---

### ✅ LOW RISK: ExportAttendanceReport

**File**: `backend/app/Jobs/ExportAttendanceReport.php`

**Status**: ✅ **GOOD** - Proper tenant context

**Implementation**:
```php
public function __construct(
    int $userId, 
    string $reportType, 
    array $params  // Contains filters including school_id
) {}

public function handle(): void
{
    $user = User::findOrFail($this->userId);
    // Uses user->school_id for tenant context
    
    $export = new AttendanceReportExport($this->reportType, $this->params);
    // Export class handles tenant filtering
}
```

**Why Safe**: 
- User model provides school_id
- Export class handles tenant filtering
- Audit logs include school_id

**Recommendation**: ✅ No changes needed

---

### ✅ LOW RISK: GenerateReportExport

**File**: `backend/app/Jobs/GenerateReportExport.php`

**Status**: ✅ **GOOD** - Proper tenant context

**Implementation**:
```php
public function __construct(
    public ReportExport $reportExport  // Has school_id
) {}

private function generateExcelReport(
    ReportExport $export, 
    array $params, 
    int $schoolId  // GOOD: Explicit school_id
): array {
    $excelExport = new AttendanceExport(
        $schoolId,  // GOOD: Passed to export
        $params['start_date'],
        $params['end_date'],
        $params['class_id'] ?? null
    );
}
```

**Why Safe**:
- ReportExport model has school_id
- Explicit school_id passed to exports
- All queries scoped by school_id

**Recommendation**: ✅ No changes needed

---

### ✅ LOW RISK: SendSecurityAlertNotification

**File**: `backend/app/Jobs/SendSecurityAlertNotification.php`

**Status**: ✅ **GOOD** - Proper tenant context

**Implementation**:
```php
public function __construct(
    public SecurityAlert $alert  // Has school_id
) {}

private function formatMessage(): array
{
    $alert = $this->alert;
    $school = $alert->school;  // Uses relationship
    $user = $alert->relatedUser;
}
```

**Why Safe**:
- SecurityAlert model has school_id
- Uses model relationships
- No cross-tenant queries

**Recommendation**: ✅ No changes needed

---

### ✅ LOW RISK: QueueWorkerHeartbeat

**File**: `backend/app/Jobs/QueueWorkerHeartbeat.php`

**Status**: ✅ **SAFE** - No tenant context needed

**Implementation**:
```php
public function handle(ObservabilityService $observability): void
{
    $observability->recordWorkerHeartbeat();
}
```

**Why Safe**:
- System-level job
- No tenant data access
- No school_id needed

**Recommendation**: ✅ No changes needed

---

## Summary of Required Changes

### Priority 1: Critical Fixes (Must Fix)

1. **CalculateAttendanceRisk**
   - Add `school_id` to constructor
   - Filter students by school_id
   - Dispatch per school

2. **CalculateAttendanceSummary**
   - Add `school_id` to constructor
   - Process one school per job
   - Remove multi-school processing

3. **RefreshAttendanceSummaries**
   - Make `school_id` required (non-nullable)
   - Remove global processing modes
   - Dispatch per school

4. **UpdateDailyAttendanceSummary**
   - Add explicit school_id validation
   - Store school_id in constructor

5. **SendAttendanceNotification**
   - Add explicit school_id to constructor
   - Add school_id validation

### Priority 2: Medium Fixes (Should Fix)

1. **BulkGenerateStudentCards**
   - Add explicit school_id to constructor
   - Filter students by school_id

2. **ExportTeacherReport**
   - Add teacher-school validation

3. **GenerateSecurityReportJob**
   - Add explicit school_id to constructor
   - Add teacher-school validation

---

## Recommended Pattern: TenantAwareJob Base Class

Create a base class for all tenant-aware jobs:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $schoolId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $schoolId)
    {
        $this->schoolId = $schoolId;
        
        // Validate school exists
        if (!DB::table('schools')->where('id', $schoolId)->exists()) {
            throw new \InvalidArgumentException("School ID {$schoolId} does not exist");
        }
    }

    /**
     * Get the school ID for this job
     */
    protected function getSchoolId(): int
    {
        return $this->schoolId;
    }

    /**
     * Ensure a model belongs to this job's school
     */
    protected function ensureTenantContext($model): void
    {
        if (!isset($model->school_id)) {
            throw new \RuntimeException(get_class($model) . ' does not have school_id');
        }

        if ($model->school_id !== $this->schoolId) {
            throw new \RuntimeException(
                'Tenant context violation: Model belongs to school ' . 
                $model->school_id . ' but job is for school ' . $this->schoolId
            );
        }
    }
}
```

---

## Dispatcher Audit Required

**Next Step**: Audit all job dispatchers to ensure they pass `school_id`:

```bash
# Find all job dispatches
grep -r "::dispatch(" app/Http/Controllers/ app/Services/ app/Listeners/
grep -r "dispatch(new" app/Http/Controllers/ app/Services/ app/Listeners/
```

**Common Dispatcher Locations**:
- Controllers: `app/Http/Controllers/`
- Services: `app/Services/`
- Event Listeners: `app/Listeners/`
- Console Commands: `app/Console/Commands/`

---

## Testing Requirements

After implementing fixes, create tests:

1. **Tenant Isolation Tests**
   - Verify jobs only access their school's data
   - Test cross-tenant access attempts fail

2. **Job Constructor Tests**
   - Verify school_id is required
   - Test invalid school_id throws exception

3. **Integration Tests**
   - Test job dispatch with school_id
   - Verify audit logs include school_id

---

## Audit Completion

**Status**: ✅ Audit Complete  
**Date**: February 10, 2026  
**Next Task**: 3.2 Create TenantAwareJob base class

**Audited By**: System Analysis  
**Reviewed By**: Pending

---

## Appendix: Job Dispatch Examples

### ❌ BAD: No school_id
```php
CalculateAttendanceRisk::dispatch();
```

### ✅ GOOD: With school_id
```php
CalculateAttendanceRisk::dispatch($schoolId);
```

### ❌ BAD: Nullable school_id
```php
RefreshAttendanceSummaries::dispatch(null, $date);
```

### ✅ GOOD: Required school_id
```php
RefreshAttendanceSummaries::dispatch($schoolId, $date);
```
