# DB::table() Usage Audit Report

**Task**: 5.1 Search DB::table() usage  
**Date**: 2026-02-10  
**Spec**: saas-hardening-30-days  
**Purpose**: Identify all DB::table() usage to ensure multi-tenant safety via global scopes

## Executive Summary

- **Total Files with DB::table()**: 30+ files
- **Critical Risk Areas**: 15+ application service files
- **Migration Usage**: 6 migration files (legitimate)
- **Test Usage**: 10+ test files (acceptable for testing)
- **Script Usage**: 5 backup/utility scripts

## Risk Classification

### 🔴 HIGH RISK - Application Code (Requires Immediate Replacement)

These files bypass Eloquent models and global scopes, creating multi-tenant data leakage risks:

#### Services Layer (Critical)

1. **app/Services/SchoolAdmin/StudentService.php** (11 instances)
   - Lines: 38, 50, 78, 90, 106, 117, 130, 138, 164, 174, 324, 327, 401, 416, 423, 428
   - Context: Student enrollment, class assignments, profile management
   - Risk: Direct table access bypasses school_id scoping
   - Tables: `user_profiles`, `class_students`, `classes`

2. **app/Services/SchoolAdmin/TeacherService.php** (4 instances)
   - Lines: 38, 70, 178, 248
   - Context: Teacher profile management
   - Risk: Profile updates without school validation
   - Tables: `user_profiles`

3. **app/Services/SecurityPolicyService.php** (6 instances)
   - Lines: 124, 136, 405, 416, 436, 470
   - Context: Security policy CRUD operations
   - Risk: Policy queries without proper scoping
   - Tables: `security_policies`

4. **app/Services/SecurityAuditService.php** (1 instance)
   - Line: 178
   - Context: Device verification
   - Risk: Cross-tenant device access
   - Tables: `teacher_devices`

5. **app/Services/SecurityMonitoringService.php** (1 instance)
   - Line: 48
   - Context: Student flagging
   - Risk: Direct student table access
   - Tables: `students`

6. **app/Services/RiskAnalysisService.php** (3 instances)
   - Lines: 33, 98, 138
   - Context: Attendance statistics and risk calculation
   - Risk: Cross-school attendance data access
   - Tables: `attendances`, `student_attendance_risk`

7. **app/Services/StudentSubjectAttendanceAnalysisService.php** (1 instance)
   - Line: 16
   - Context: Subject-based attendance analysis
   - Risk: Cross-school attendance aggregation
   - Tables: `attendances`

8. **app/Services/StudentNotificationService.php** (1 instance)
   - Line: 78
   - Context: Monthly attendance statistics
   - Risk: Attendance data without school filter
   - Tables: `attendances`

9. **app/Services/StudentArrivalConsistencyService.php** (1 instance)
   - Line: 15
   - Context: Check-in time analysis
   - Risk: Cross-school attendance patterns
   - Tables: `attendances`

10. **app/Services/ObservabilityService.php** (3 instances)
    - Lines: 268, 269, 270
    - Context: Queue metrics monitoring
    - Risk: LOW (system-level metrics, not tenant data)
    - Tables: `jobs`, `failed_jobs`

11. **app/Services/QueueWorkerScalerService.php** (6 instances)
    - Lines: 127, 183, 188, 193
    - Context: Queue monitoring and scaling
    - Risk: LOW (system-level operations)
    - Tables: `jobs`, `failed_jobs`

12. **app/Services/ProductionMonitoringService.php** (5 instances)
    - Lines: 467, 469, 470, 495, 510
    - Context: Production metrics
    - Risk: LOW (system-level monitoring)
    - Tables: `jobs`, `failed_jobs`

#### Traits

13. **app/Traits/HasSchoolLimits.php** (1 instance)
    - Line: 40
    - Context: Class count limit checking
    - Risk: MEDIUM (has school_id filter but bypasses model)
    - Tables: `classes`

### 🟡 MEDIUM RISK - Scripts (Review Required)

These scripts use DB::table() but may be acceptable for administrative tasks:

1. **scripts/backup_scheduler.php** (4 instances)
   - Context: Backup execution tracking
   - Risk: MEDIUM (system table, but should use model)
   - Tables: `backup_executions`

2. **scripts/restore_system.php** (6 instances)
   - Context: System restoration and verification
   - Risk: MEDIUM (administrative operation)
   - Tables: `users`, `attendances`, `schools`, `security_events`

3. **scripts/disaster_recovery_simulation.php** (15 instances)
   - Context: DR testing and statistics
   - Risk: MEDIUM (read-only statistics)
   - Tables: Multiple tables for counting

4. **scripts/backup_system.php** (5 instances)
   - Context: Backup operations
   - Risk: MEDIUM (administrative)
   - Tables: Multiple tables for counting

5. **scripts/advanced_backup_strategy.php** (2 instances)
   - Context: Multi-tenant backup
   - Risk: MEDIUM (has school filtering)
   - Tables: `schools`

### 🟢 LOW RISK - Migrations (Legitimate Usage)

These are acceptable uses in migrations for data transformation:

1. **2026_02_10_120000_add_session_started_at_to_refresh_tokens.php**
   - Context: Backfilling session timestamps
   - Risk: NONE (migration data transformation)

2. **2026_02_07_100000_add_state_machine_to_attendances.php**
   - Context: State migration for attendance records
   - Risk: NONE (migration data transformation)

3. **2026_01_29_200000_create_immutable_security_logs_table.php**
   - Context: Genesis block insertion
   - Risk: NONE (migration seeding)

4. **2026_01_26_130500_change_day_of_week_to_integer_in_schedules.php**
   - Context: Data type conversion
   - Risk: NONE (migration transformation)

5. **2026_01_20_074345_create_subscription_packages_table.php**
   - Context: Seeding default packages
   - Risk: NONE (migration seeding)

6. **2026_01_20_072613_create_platform_config_tables.php**
   - Context: Seeding feature flags
   - Risk: NONE (migration seeding)

### 🟢 LOW RISK - Tests (Acceptable for Testing)

Test files using DB::table() for test data setup:

1. **tests/Feature/DashboardCacheUpdateTest.php** (1 instance)
2. **tests/Feature/FinalPerformanceTest.php** (2 instances)
3. **tests/Feature/HealthCheckTest.php** (2 instances)
4. **tests/Feature/PolicyEnforcementTest.php** (1 instance)
5. **tests/Feature/PrincipalMonitoringTest.php** (4 instances)

Risk: NONE (test environment only)

## Summary Statistics

| Category | Count | Risk Level |
|----------|-------|------------|
| Application Services | 15 files, 40+ instances | 🔴 HIGH |
| Traits | 1 file, 1 instance | 🟡 MEDIUM |
| Scripts | 5 files, 32+ instances | 🟡 MEDIUM |
| Migrations | 6 files, 10+ instances | 🟢 LOW |
| Tests | 10+ files, 10+ instances | 🟢 LOW |
| **TOTAL** | **30+ files** | **Mixed** |

## Critical Tables Accessed via DB::table()

### Multi-Tenant Tables (HIGH RISK)
- `attendances` - 8+ instances in services
- `classes` - 5+ instances in services
- `class_students` - 6+ instances in services
- `user_profiles` - 4+ instances in services
- `students` - 1 instance
- `security_policies` - 6 instances
- `teacher_devices` - 1 instance
- `student_attendance_risk` - 1 instance

### System Tables (LOW RISK)
- `jobs` - Queue monitoring (acceptable)
- `failed_jobs` - Queue monitoring (acceptable)
- `backup_executions` - System operations

## Recommended Actions

### Immediate (Task 5.2)
1. Replace all HIGH RISK service layer DB::table() calls with Eloquent models
2. Priority order:
   - StudentService (11 instances)
   - SecurityPolicyService (6 instances)
   - RiskAnalysisService (3 instances)
   - TeacherService (4 instances)
   - Other services (1-2 instances each)

### Short-term
1. Review and refactor MEDIUM RISK scripts
2. Consider creating models for system tables (BackupExecution)
3. Add linting rules to prevent new DB::table() usage

### Long-term
1. Implement automated detection in CI/CD
2. Add code review guidelines
3. Create developer documentation on global scope importance

## Testing Strategy

After replacement (Task 5.3):
1. Run multi-tenant isolation tests
2. Verify global scopes are applied
3. Test cross-school data access prevention
4. Performance comparison (Eloquent vs raw queries)

## Notes

- Vendor files excluded from audit
- Some truncated results due to volume
- Focus on application code, not framework internals
- Migration usage is acceptable and expected
- Test usage is acceptable for test data setup

---

**Next Task**: 5.2 Replace DB::table() with Eloquent models in application code
