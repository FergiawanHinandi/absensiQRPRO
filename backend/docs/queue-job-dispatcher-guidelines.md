# Queue Job Dispatcher Guidelines

**Date**: 2026-02-28  
**Purpose**: Guidelines for dispatching tenant-aware queue jobs safely

## Overview

All queue jobs that handle tenant-specific data MUST extend `TenantAwareJob` and receive `school_id` as the first parameter. This ensures tenant isolation and prevents data leakage across schools.

## Dispatcher Pattern

### ✅ CORRECT Pattern

Always pass `school_id` as the FIRST parameter when dispatching tenant-aware jobs:

```php
// Pattern: JobName::dispatch($schoolId, ...otherParams)

// Example 1: From authenticated user
ExportAttendanceReport::dispatch(
    $user->school_id,  // ✅ First parameter
    $user->id,
    'monthly',
    $params
);

// Example 2: From model
SendAttendanceNotification::dispatch(
    $attendance->school_id,  // ✅ First parameter
    $attendance->id
);

// Example 3: From request
GenerateReportExport::dispatch(
    $request->user()->school_id,  // ✅ First parameter
    $export->id
);
```

### ❌ INCORRECT Patterns

```php
// ❌ WRONG: Missing school_id
ExportAttendanceReport::dispatch($user->id, 'monthly', $params);

// ❌ WRONG: school_id not first parameter
ExportAttendanceReport::dispatch($user->id, $user->school_id, 'monthly');

// ❌ WRONG: Using wrong school_id
ExportAttendanceReport::dispatch(1, $user->id, 'monthly'); // Hardcoded!
```

## Updated Jobs Requiring school_id

### Critical Jobs (Updated in Day 3)

1. **BackfillAttendanceDataJob**
   ```php
   BackfillAttendanceDataJob::dispatch($schoolId, 'state', 'mapStatusToState');
   ```

2. **CalculateAttendanceRisk**
   ```php
   CalculateAttendanceRisk::dispatch($schoolId);
   ```

3. **CalculateAttendanceSummary**
   ```php
   CalculateAttendanceSummary::dispatch($schoolId, $date);
   ```

4. **UpdateDailyAttendanceSummary**
   ```php
   UpdateDailyAttendanceSummary::dispatch($schoolId, $event);
   ```

5. **ExportTeacherReport**
   ```php
   ExportTeacherReport::dispatch($schoolId, $teacherId, $month, $format);
   ```

### Already Tenant-Safe Jobs

These jobs already follow the correct pattern:

1. **ExportAttendanceReport**
   ```php
   ExportAttendanceReport::dispatch($user->school_id, $user->id, $type, $params);
   ```

2. **SendAttendanceNotification**
   ```php
   SendAttendanceNotification::dispatch($attendance->school_id, $attendance->id);
   ```

3. **GenerateReportExport**
   ```php
   GenerateReportExport::dispatch($user->school_id, $export->id);
   ```

4. **GenerateSecurityReportJob**
   ```php
   GenerateSecurityReportJob::dispatch($teacherId, $schoolId, $range, $reason);
   ```

5. **BulkGenerateStudentCards**
   ```php
   BulkGenerateStudentCards::dispatch($studentIds, $adminId, $schoolId, $filters, $forceRegenerate);
   ```

## Common Dispatcher Locations

### Controllers

Most job dispatchers are in API controllers:

```php
// app/Http/Controllers/Api/V1/Admin/AttendanceReportController.php
public function export(Request $request)
{
    $user = $request->user();
    
    // ✅ Always use authenticated user's school_id
    ExportAttendanceReport::dispatch(
        $user->school_id,
        $user->id,
        $request->input('type'),
        $request->input('params')
    );
}
```

### Event Listeners

Jobs dispatched from event listeners:

```php
// app/Listeners/UpdateAttendanceSummaryListener.php
public function handle(AttendanceRecorded $event)
{
    $attendance = $event->attendance;
    
    // ✅ Use model's school_id
    UpdateDailyAttendanceSummary::dispatch(
        $attendance->school_id,
        $event
    );
}
```

### Console Commands

Jobs dispatched from artisan commands:

```php
// app/Console/Commands/CalculateRiskScores.php
public function handle()
{
    $schools = School::where('is_active', true)->get();
    
    foreach ($schools as $school) {
        // ✅ Dispatch separate job per school
        CalculateAttendanceRisk::dispatch($school->id);
    }
}
```

### Scheduled Tasks

Jobs dispatched from scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // ✅ Dispatch per school for tenant safety
    $schedule->call(function () {
        School::where('is_active', true)->each(function ($school) {
            CalculateAttendanceSummary::dispatch($school->id, now());
        });
    })->daily();
}
```

## Validation Checklist

Before dispatching a tenant-aware job, verify:

- [ ] Job extends `TenantAwareJob`
- [ ] `school_id` is passed as first parameter
- [ ] `school_id` comes from authenticated user or validated model
- [ ] Never use hardcoded school_id values
- [ ] Job constructor signature matches dispatcher call

## Testing Dispatchers

Always test that dispatchers pass correct school_id:

```php
public function test_export_dispatches_with_correct_school_id()
{
    Queue::fake();
    
    $user = User::factory()->create(['school_id' => 123]);
    
    $this->actingAs($user)
        ->post('/api/v1/reports/export', ['type' => 'monthly']);
    
    Queue::assertPushed(ExportAttendanceReport::class, function ($job) use ($user) {
        return $job->schoolId === $user->school_id;
    });
}
```

## Migration Guide

If you find a job dispatcher that doesn't pass school_id:

1. **Identify the source of school_id**:
   - From authenticated user: `$request->user()->school_id`
   - From model: `$model->school_id`
   - From relationship: `$model->schedule->school_id`

2. **Update the dispatcher call**:
   ```php
   // Before
   OldJob::dispatch($param1, $param2);
   
   // After
   OldJob::dispatch($schoolId, $param1, $param2);
   ```

3. **Update the job constructor**:
   ```php
   // Before
   public function __construct($param1, $param2)
   {
       $this->param1 = $param1;
       $this->param2 = $param2;
   }
   
   // After
   public function __construct(int $schoolId, $param1, $param2)
   {
       parent::__construct($schoolId);
       $this->param1 = $param1;
       $this->param2 = $param2;
   }
   ```

4. **Test the changes**:
   - Unit test the job with school_id
   - Integration test the dispatcher
   - Verify tenant isolation

## Security Considerations

### Never Trust Client Input for school_id

```php
// ❌ DANGEROUS: Client can manipulate school_id
$schoolId = $request->input('school_id');
ExportAttendanceReport::dispatch($schoolId, ...);

// ✅ SAFE: Always use authenticated user's school_id
$schoolId = $request->user()->school_id;
ExportAttendanceReport::dispatch($schoolId, ...);
```

### Validate Model Ownership

```php
// ✅ SAFE: Validate model belongs to user's school
$attendance = Attendance::where('school_id', $user->school_id)
    ->findOrFail($id);

SendAttendanceNotification::dispatch($attendance->school_id, $attendance->id);
```

### Multi-School Operations

For super admins operating across schools:

```php
// ✅ SAFE: Dispatch separate jobs per school
if ($user->isSuperAdmin()) {
    $schools = School::whereIn('id', $request->input('school_ids'))->get();
    
    foreach ($schools as $school) {
        ExportAttendanceReport::dispatch($school->id, $user->id, 'monthly', $params);
    }
}
```

## Monitoring

Track job dispatches with proper school_id context:

```php
Log::channel('audit')->info('job_dispatched', [
    'job' => ExportAttendanceReport::class,
    'school_id' => $schoolId,
    'user_id' => $user->id,
    'timestamp' => now(),
]);
```

## Rollback Plan

If a job update causes issues:

1. Revert job class to previous version
2. Revert dispatcher calls to previous signature
3. Monitor logs for tenant context violations
4. Fix issues and redeploy

## Summary

- Always pass `school_id` as first parameter
- Never hardcode school_id values
- Validate model ownership before dispatching
- Test tenant isolation thoroughly
- Monitor job dispatches in production

For questions or issues, refer to:
- `backend/app/Jobs/TenantAwareJob.php` - Base class implementation
- `backend/docs/queue-jobs-tenant-audit-report.md` - Audit results
- `backend/tests/Feature/QueueTenantIsolationTest.php` - Test examples
