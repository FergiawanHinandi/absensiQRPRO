# TenantAwareJob Base Class - Quick Reference

**Date**: February 10, 2026  
**Task**: 3.2 Create TenantAwareJob base class  
**Spec**: SaaS Hardening 30-Day Roadmap  
**Status**: ✅ Complete

---

## Overview

The `TenantAwareJob` base class ensures all queue jobs maintain proper tenant (school) context to prevent cross-tenant data leaks and ensure data integrity in the multi-tenant system.

---

## Implementation

### File Location
```
backend/app/Jobs/TenantAwareJob.php
```

### Key Features

1. **Enforces school_id requirement** - All jobs extending this class MUST pass a valid school_id
2. **Validates school existence** - Throws exception if school_id doesn't exist in database
3. **Provides helper methods** - `getSchoolId()` and `ensureTenantContext()` for validation
4. **Automatic tagging** - Adds tenant-aware tags for monitoring

---

## Usage

### Basic Usage

```php
<?php

namespace App\Jobs;

class MyJob extends TenantAwareJob
{
    private int $userId;
    private array $params;
    
    public function __construct(int $schoolId, int $userId, array $params)
    {
        // MUST call parent constructor with school_id
        parent::__construct($schoolId);
        
        $this->userId = $userId;
        $this->params = $params;
    }
    
    public function handle(): void
    {
        // Get school_id for queries
        $schoolId = $this->getSchoolId();
        
        // Use school_id in all queries
        $students = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->get();
        
        // Process data...
    }
}
```

### Validating Model Tenant Context

```php
public function handle(): void
{
    $student = User::find($this->studentId);
    
    // Validate student belongs to this job's school
    $this->ensureTenantContext($student);
    
    // Now safe to use $student
    // ...
}
```

### Dispatching Jobs

```php
// ✅ CORRECT: Pass school_id explicitly
MyJob::dispatch($user->school_id, $userId, $params);

// ❌ WRONG: No school_id
MyJob::dispatch($userId, $params);
```

---

## Protected Methods

### `getSchoolId(): int`

Returns the school_id for this job.

```php
$schoolId = $this->getSchoolId();
```

### `ensureTenantContext($model): void`

Validates that a model belongs to this job's school. Throws `RuntimeException` if:
- Model doesn't have `school_id` property
- Model's `school_id` doesn't match job's `school_id`

```php
$this->ensureTenantContext($student);
```

---

## Validation Rules

### Constructor Validation

The base class constructor validates:

1. **Positive school_id**: Must be > 0
   ```php
   // ❌ Throws InvalidArgumentException
   new MyJob(0, $userId, $params);
   new MyJob(-1, $userId, $params);
   ```

2. **School exists**: School must exist in database
   ```php
   // ❌ Throws InvalidArgumentException if school 99999 doesn't exist
   new MyJob(99999, $userId, $params);
   ```

### Model Validation

The `ensureTenantContext()` method validates:

1. **Model has school_id**: Model must have `school_id` property
2. **School_id matches**: Model's `school_id` must match job's `school_id`

---

## Error Messages

### Invalid school_id (zero or negative)
```
Invalid school_id: 0. School ID must be a positive integer.
```

### Non-existent school
```
School with ID 99999 does not exist. Cannot create tenant-aware job.
```

### Model without school_id
```
App\Models\SomeModel does not have school_id property. Cannot validate tenant context.
```

### Tenant context violation
```
Tenant context violation: App\Models\User belongs to school 2 but job is for school 1. Cross-tenant access is not allowed.
```

---

## Testing

### Test File Location
```
backend/tests/Unit/Jobs/TenantAwareJobTest.php
```

### Test Coverage

✅ Job creation with valid school_id  
✅ Exception with zero school_id  
✅ Exception with negative school_id  
✅ Exception with non-existent school_id  
✅ Tenant context validation passes with correct school  
✅ Tenant context validation fails with wrong school  
✅ Tenant context validation fails with model without school_id  
✅ Tags include tenant-aware and school_id  
✅ Multiple jobs with different schools are isolated

### Running Tests

```bash
# Run all TenantAwareJob tests
php artisan test --filter=TenantAwareJobTest

# Run specific test
php artisan test --filter=TenantAwareJobTest::test_job_can_be_created_with_valid_school_id
```

---

## Migration Guide

### Converting Existing Jobs

**Before:**
```php
class ExportAttendanceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $userId;
    protected $reportType;
    protected $params;
    
    public function __construct(int $userId, string $reportType, array $params)
    {
        $this->userId = $userId;
        $this->reportType = $reportType;
        $this->params = $params;
    }
    
    public function handle(): void
    {
        $user = User::findOrFail($this->userId);
        // Uses user->school_id implicitly
    }
}
```

**After:**
```php
class ExportAttendanceReport extends TenantAwareJob
{
    protected $userId;
    protected $reportType;
    protected $params;
    
    public function __construct(int $schoolId, int $userId, string $reportType, array $params)
    {
        parent::__construct($schoolId);  // ✅ Add this
        $this->userId = $userId;
        $this->reportType = $reportType;
        $this->params = $params;
    }
    
    public function handle(): void
    {
        $schoolId = $this->getSchoolId();  // ✅ Use explicit school_id
        
        // ✅ Force school_id in params
        $this->params['school_id'] = $schoolId;
        
        $export = new AttendanceReportExport($this->reportType, $this->params);
        // ...
    }
}
```

**Update Dispatchers:**
```php
// Before
ExportAttendanceReport::dispatch($user->id, $reportType, $params);

// After
ExportAttendanceReport::dispatch($user->school_id, $user->id, $reportType, $params);
```

---

## Jobs to Update

Based on the audit in `backend/docs/QUEUE_JOBS_TENANT_AUDIT.md`, the following jobs need to be updated:

### 🔴 Priority 1: Critical (Must Fix)

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

### 🟠 Priority 2: Medium (Should Fix)

1. **BulkGenerateStudentCards**
   - Add explicit school_id to constructor
   - Filter students by school_id

2. **ExportTeacherReport**
   - Add teacher-school validation

3. **GenerateSecurityReportJob**
   - Add explicit school_id to constructor
   - Add teacher-school validation

---

## Best Practices

### ✅ DO

- Always pass `school_id` explicitly to job constructor
- Use `getSchoolId()` to get school_id in handle method
- Use `ensureTenantContext()` to validate models
- Filter all queries by school_id
- Dispatch separate jobs per school for batch operations

### ❌ DON'T

- Don't make `school_id` nullable or optional
- Don't rely on implicit school_id from relationships
- Don't process multiple schools in a single job
- Don't bypass tenant context validation
- Don't use global scopes without explicit school_id

---

## Monitoring & Debugging

### Job Tags

All tenant-aware jobs automatically include tags:
- `tenant-aware` - Identifies job as tenant-aware
- `school:{school_id}` - Identifies which school

### Logging

```php
Log::info('Processing job for school', [
    'job' => get_class($this),
    'school_id' => $this->getSchoolId(),
]);
```

### Queue Monitoring

Filter jobs by school in queue monitoring:
```bash
# View jobs for specific school
php artisan queue:monitor --tag=school:1
```

---

## Related Documentation

- **Audit Report**: `backend/docs/QUEUE_JOBS_TENANT_AUDIT.md`
- **Design Document**: `.kiro/specs/saas-hardening-30-days/design.md`
- **Requirements**: `.kiro/specs/saas-hardening-30-days/requirements.md`
- **Tasks**: `.kiro/specs/saas-hardening-30-days/tasks.md`

---

## Next Steps

1. ✅ Task 3.2: Create TenantAwareJob base class (COMPLETE)
2. ⏭️ Task 3.3: Update ExportAttendanceReport
3. ⏭️ Task 3.4: Update all dispatchers
4. ⏭️ Task 3.5: Write tenant tests (12 tests)

---

## Summary

The `TenantAwareJob` base class provides a robust foundation for ensuring tenant isolation in queue jobs. By enforcing explicit school_id requirements and providing validation helpers, it prevents cross-tenant data leaks and ensures data integrity in the multi-tenant system.

**Key Benefits:**
- ✅ Prevents cross-tenant data leaks
- ✅ Enforces explicit school_id requirement
- ✅ Validates school existence at job creation
- ✅ Provides helper methods for tenant validation
- ✅ Automatic tagging for monitoring
- ✅ Comprehensive test coverage

**Status**: ✅ Implementation Complete | Tests Passing (9/9)
