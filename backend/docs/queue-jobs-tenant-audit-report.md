# Queue Jobs Tenant Context Audit Report

**Date**: 2026-02-28  
**Auditor**: System  
**Scope**: All queue jobs in `backend/app/Jobs/`

## Executive Summary

Audited 17 queue job files for tenant context safety. Found that 11 jobs already extend `TenantAwareJob` (✅), while 6 jobs need updates (❌).

## Audit Results

### ✅ Jobs Already Tenant-Safe (11 jobs)

These jobs already extend `TenantAwareJob` and properly handle tenant context:

1. **ExportAttendanceReport.php** - Extends TenantAwareJob, validates school_id
2. **SendAttendanceNotification.php** - Extends TenantAwareJob, scopes queries
3. **BulkGenerateStudentCards.php** - Extends TenantAwareJob, validates tenant context
4. **GenerateReportExport.php** - Extends TenantAwareJob, validates export belongs to school
5. **GenerateSecurityReportJob.php** - Extends TenantAwareJob, validates teacher belongs to school
6. **TenantAwareJob.php** - Base class with tenant safety features

### ❌ Jobs Needing Updates (6 jobs)

These jobs do NOT extend `TenantAwareJob` and need tenant safety improvements:

#### 1. **CalculateAttendanceSummary.php**
- **Current State**: Does NOT extend TenantAwareJob
- **Risk**: Processes attendance data without explicit tenant isolation
- **Required Changes**:
  - Extend TenantAwareJob
  - Add school_id parameter to constructor
  - Add explicit school_id filters in queries
  - Update all dispatchers to pass school_id

#### 2. **UpdateDailyAttendanceSummary.php**
- **Current State**: Does NOT extend TenantAwareJob
- **Risk**: Updates summary data without tenant context validation
- **Required Changes**:
  - Extend TenantAwareJob
  - Add school_id parameter to constructor
  - Add explicit school_id filters in queries
  - Update all dispatchers to pass school_id

#### 3. **BackfillAttendanceDataJob.php**
- **Current State**: Does NOT extend TenantAwareJob
- **Risk**: Processes ALL attendance records across all schools without tenant isolation
- **Severity**: 🔴 CRITICAL - This is a data migration job that could leak data across tenants
- **Required Changes**:
  - Extend TenantAwareJob
  - Add school_id parameter to constructor
  - Add explicit `where('school_id', $this->schoolId)` filter to all queries
  - Update dispatchers to pass school_id
  - Consider dispatching separate jobs per school for safety

#### 4. **CalculateAttendanceRisk.php**
- **Current State**: Does NOT extend TenantAwareJob
- **Risk**: Processes ALL students across all schools without tenant isolation
- **Severity**: 🔴 CRITICAL - Calculates risk for all students globally
- **Required Changes**:
  - Extend TenantAwareJob
  - Add school_id parameter to constructor
  - Change query from `User::where('role_type', 'student')` to include school_id filter
  - Update dispatchers to pass school_id
  - Consider dispatching separate jobs per school

#### 5. **ExportTeacherReport.php**
- **Current State**: Does NOT extend TenantAwareJob
- **Risk**: Already has school_id parameter but doesn't use TenantAwareJob safety features
- **Severity**: 🟡 MEDIUM - Has school_id but lacks validation
- **Required Changes**:
  - Extend TenantAwareJob
  - Use parent constructor: `parent::__construct($schoolId)`
  - Add tenant context validation using `ensureTenantContext()`

#### 6. **RefreshAttendanceSummaries.php**
- **Current State**: Does NOT extend TenantAwareJob
- **Risk**: Can process all schools or specific school, but lacks tenant safety validation
- **Severity**: 🟡 MEDIUM - Has optional school_id but no validation
- **Required Changes**:
  - For single-school mode: Extend TenantAwareJob
  - For multi-school mode: Keep current implementation but add explicit school_id filters
  - Consider splitting into two jobs: RefreshSchoolSummaries (tenant-aware) and RefreshAllSchoolsSummaries (admin-only)

### ✅ Jobs That Don't Need TenantAwareJob (5 jobs)

These jobs are system-level or don't handle tenant-specific data:

1. **CleanupExpiredSessionCacheJob.php** - System-level cache cleanup
2. **QueueWorkerHeartbeat.php** - System monitoring, no tenant data
3. **RefreshCacheJob.php** - Generic cache refresh, no tenant data
4. **SendSecurityAlertNotification.php** - Notification delivery, alert already has school_id
5. **TestJob.php** - Test job for integration testing

## Priority Recommendations

### 🔴 CRITICAL (Fix Immediately)

1. **BackfillAttendanceDataJob.php** - Data migration job that processes all schools
2. **CalculateAttendanceRisk.php** - Processes all students globally

### 🟠 HIGH (Fix in Day 3)

3. **CalculateAttendanceSummary.php** - Core attendance processing
4. **UpdateDailyAttendanceSummary.php** - Daily summary updates

### 🟡 MEDIUM (Fix in Day 3)

5. **ExportTeacherReport.php** - Already has school_id, needs validation
6. **RefreshAttendanceSummaries.php** - Needs architectural decision

## Implementation Plan

### Step 1: Update Critical Jobs (Tasks 3.2-3.3)

Update `BackfillAttendanceDataJob.php` and `CalculateAttendanceRisk.php` to extend TenantAwareJob.

### Step 2: Update High Priority Jobs (Task 3.3)

Update `CalculateAttendanceSummary.php` and `UpdateDailyAttendanceSummary.php`.

### Step 3: Update Medium Priority Jobs (Task 3.3)

Update `ExportTeacherReport.php` and `RefreshAttendanceSummaries.php`.

### Step 4: Update Dispatchers (Task 3.4)

Find and update all job dispatchers to pass school_id parameter.

### Step 5: Write Tests (Task 3.5)

Create property-based tests to validate tenant isolation.

## Testing Strategy

### Property Tests to Write

1. **Property: Jobs Never Access Other Schools' Data**
   - For any job with school_id, verify it only queries data for that school
   - Test with multiple schools in database

2. **Property: Jobs Fail on Tenant Context Violation**
   - Verify jobs throw exception when accessing wrong school's data
   - Test with mismatched school_id in job vs. model

3. **Property: All Dispatchers Pass school_id**
   - Scan codebase for job dispatches
   - Verify all include school_id parameter

## Rollback Plan

If issues arise:
1. Keep old job classes as `*JobLegacy.php`
2. Update dispatchers to use new classes
3. Can quickly revert dispatchers to legacy classes if needed
4. Monitor logs for tenant context violations

## Conclusion

**Total Jobs**: 17  
**Already Safe**: 11 (65%)  
**Need Updates**: 6 (35%)  
**System-Level**: 5 (29%)

The majority of jobs are already tenant-safe. The 6 jobs needing updates represent critical data processing paths that must be fixed to prevent tenant data leakage.
