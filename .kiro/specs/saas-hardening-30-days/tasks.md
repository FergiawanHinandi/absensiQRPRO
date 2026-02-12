# Implementation Plan: SaaS Hardening 30-Day Roadmap

## Overview

This implementation plan addresses critical production issues in AbsensiQR Pro through a risk-first approach. The plan is organized into 4 weekly sprints focusing on: (1) Data Integrity & Tenant Safety, (2) Concurrency & Webhook Hardening, (3) Performance Optimization, and (4) Observability & Resilience. All changes are backward compatible with rollback plans for each deployment.

## Tasks

### WEEK 1: DATA INTEGRITY & TENANT SAFETY

- [x] 1. Day 1: Timezone Consistency Audit & Fix
  - [x] 1.1 Audit all files for date(), time(), strtotime() usage
    - Search codebase for raw PHP date functions
    - Identify patterns in controllers, services, and reports
    - Document all occurrences for replacement
    - _Requirements: Week 1 Day 1, Acceptance Criteria 1_
  
  - [x] 1.2 Create TimezoneHelper utility class
    - Implement TimezoneHelper::now() with timezone parameter
    - Implement TimezoneHelper::schoolNow() for school-specific timezone
    - Implement TimezoneHelper::parse() for date parsing
    - Place in app/Helpers/TimezoneHelper.php
    - _Requirements: Week 1 Day 1, Acceptance Criteria 2_
  
  - [x] 1.3 Replace all raw date functions with TimezoneHelper
    - Update app/Services/SecureAttendanceService.php
    - Update app/Http/Controllers/Api/V1/Teacher/QRGeneratorController.php
    - Update app/Http/Controllers/Api/V1/Admin/AttendanceReportController.php
    - Update app/Jobs/ExportAttendanceReport.php
    - Replace date() with TimezoneHelper::now()->toDateString()
    - Replace Carbon::now() with TimezoneHelper::schoolNow($school)
    - _Requirements: Week 1 Day 1, Acceptance Criteria 1, 3_
  
  - [x] 1.4 Add timezone field to school settings
    - Add timezone column to schools table if not exists
    - Update School model with timezone attribute
    - Set default timezone in config/app.php
    - _Requirements: Week 1 Day 1, Acceptance Criteria 4_
  
  - [-] 1.5 Write timezone consistency tests
    - Test TimezoneHelper::now() returns correct timezone
    - Test TimezoneHelper::schoolNow() uses school timezone
    - Test whereDate() queries use timezone-aware dates
    - Test timezone fallback to config default
    - Target: 10 tests in tests/Unit/TimezoneHelperTest.php
    - _Requirements: Week 1 Day 1, Acceptance Criteria 5_

- [x] 2. Day 2: Unique Attendance Constraint
  - [x] 2.1 Identify and analyze existing duplicate records
    - Run SQL query to find duplicates by (student_id, schedule_id, attendance_date, school_id)
    - Document duplicate count and patterns
    - Determine cleanup strategy (keep oldest, soft delete rest)
    - _Requirements: Week 1 Day 2, Acceptance Criteria 2_
  
  - [x] 2.2 Create cleanup script for existing duplicates
    - Write migration to identify duplicates
    - Keep oldest record per unique combination
    - Soft delete or hard delete duplicates based on data integrity
    - Log cleanup actions for audit trail
    - _Requirements: Week 1 Day 2, Acceptance Criteria 2_
  
  - [x] 2.3 Create migration with unique constraint
    - Create migration: add_unique_attendance_constraint
    - Add unique index on (student_id, schedule_id, attendance_date, school_id)
    - Name constraint: unique_attendance_per_day
    - Include rollback method to drop constraint
    - _Requirements: Week 1 Day 2, Acceptance Criteria 1_
  
  - [x] 2.4 Update all attendance creation code to use firstOrCreate
    - Update SecureAttendanceService to use firstOrCreate
    - Update QR scan attendance creation
    - Update manual attendance entry
    - Add constraint violation error handling
    - Return user-friendly error messages
    - _Requirements: Week 1 Day 2, Acceptance Criteria 3, 5_
  
  - [x]* 2.5 Write duplicate prevention tests
    - Test unique constraint prevents duplicates at database level
    - Test firstOrCreate returns existing record
    - Test constraint violation handling
    - Test error message clarity
    - Target: 8 tests in tests/Feature/AttendanceDuplicateTest.php
    - _Requirements: Week 1 Day 2, Acceptance Criteria 4_

- [x] 3. Day 3: Queue Job Tenant Context Fix
  - [x] 3.1 Audit all queue jobs for tenant context
    - Find all jobs implementing ShouldQueue
    - Check each job for school_id usage
    - Identify jobs using DB::table() or bypassing scopes
    - Document jobs needing updates
    - _Requirements: Week 1 Day 3, Acceptance Criteria 2_
  
  - [x] 3.2 Create TenantAwareJob base class
    - Create abstract class in app/Jobs/TenantAwareJob.php
    - Add protected $schoolId property
    - Add constructor requiring school_id
    - Add forSchool() helper method for queries
    - Include SerializesModels trait
    - _Requirements: Week 1 Day 3, Acceptance Criteria 1_
  
  - [x] 3.3 Update ExportAttendanceReport job
    - Extend TenantAwareJob base class
    - Pass school_id to constructor
    - Force school_id filter in all queries
    - Update handle() method to use $this->schoolId
    - _Requirements: Week 1 Day 3, Acceptance Criteria 1, 2_
  
  - [x] 3.4 Update all job dispatchers to pass school_id
    - Update controllers dispatching ExportAttendanceReport
    - Update SendAttendanceNotification dispatchers
    - Update GenerateMonthlyReport dispatchers
    - Ensure all dispatchers pass user's school_id
    - _Requirements: Week 1 Day 3, Acceptance Criteria 1_
  
  - [x]* 3.5 Write tenant isolation tests for queue jobs
    - Test jobs maintain school_id context
    - Test jobs filter queries by school_id
    - Test cross-tenant access is prevented
    - Test audit log for scope bypass attempts
    - Target: 12 tests in tests/Feature/QueueTenantIsolationTest.php
    - _Requirements: Week 1 Day 3, Acceptance Criteria 4, 5_

- [x] 4. Day 4: State Machine Enforcement
  - [x] 4.1 Block direct status modification in Attendance model
    - Add setStatusAttribute() method that throws exception
    - Add setStateAttribute() with internal flag check
    - Throw StateViolationException with helpful message
    - Document state machine methods in exception
    - _Requirements: Week 1 Day 4, Acceptance Criteria 1_
  
  - [x] 4.2 Update all seeders and factories to use state machine
    - Update database/seeders/AttendanceSeeder.php
    - Update database/factories/AttendanceFactory.php
    - Replace direct status assignment with checkIn(), checkOut()
    - Update all test files creating attendance records
    - _Requirements: Week 1 Day 4, Acceptance Criteria 2_
  
  - [x] 4.3 Add state transition audit logging
    - Log all state transitions in activity log
    - Include previous state, new state, actor, timestamp
    - Use spatie/laravel-activitylog for tracking
    - _Requirements: Week 1 Day 4, Acceptance Criteria 3_
  
  - [x]* 4.4 Write state machine enforcement tests
    - Test direct status modification throws exception
    - Test state transitions through methods work
    - Test invalid transitions are rejected
    - Test audit log records transitions
    - Target: 15 tests in tests/Feature/AttendanceStateMachineTest.php
    - _Requirements: Week 1 Day 4, Acceptance Criteria 4, 5_

- [x] 5. Day 5: DB::table() Audit & Elimination
  - [x] 5.1 Search all files for DB::table() patterns
    - Use grep to find all DB::table() usage
    - Exclude migrations directory
    - Document each occurrence with context
    - Categorize as: needs replacement, legitimate raw query, migration
    - _Requirements: Week 1 Day 5, Acceptance Criteria 1, 2_
  
  - [x] 5.2 Replace DB::table() with Eloquent queries
    - Replace DB::table('attendances') with Attendance::query()
    - Replace DB::table('schedules') with Schedule::query()
    - Ensure all queries respect global scopes
    - Document any legitimate raw query usage with justification
    - _Requirements: Week 1 Day 5, Acceptance Criteria 1, 2, 3_
  
  - [x] 5.3 Add PHPStan rule to prevent DB::table() usage
    - Create or update phpstan.neon configuration
    - Add rule to flag DB::table() calls
    - Exclude migrations directory from rule
    - Update coding standards documentation
    - _Requirements: Week 1 Day 5, Acceptance Criteria 4_
  
  - [x]* 5.4 Write scope bypass detection tests
    - Test Eloquent queries respect global scopes
    - Test tenant isolation is maintained
    - Test no cross-tenant data access
    - Target: 6 tests in tests/Feature/ScopeBypassTest.php
    - _Requirements: Week 1 Day 5, Acceptance Criteria 5_

- [ ] 6. Week 1 Checkpoint: Verify data integrity fixes
  - Ensure all Week 1 tests pass
  - Verify timezone consistency across application
  - Confirm no duplicate attendance possible
  - Validate tenant isolation in queue jobs
  - Check state machine enforcement
  - Ask user if questions arise before proceeding to Week 2

### WEEK 2: CONCURRENCY & WEBHOOK HARDENING

- [x] 7. Day 6: Deadlock Detection & Retry
  - [x] 7.1 Create DeadlockRetryMiddleware
    - Create middleware in app/Http/Middleware/DeadlockRetryMiddleware.php
    - Implement retry logic with max 3 attempts
    - Add exponential backoff (100ms, 200ms, 400ms)
    - Catch Dea