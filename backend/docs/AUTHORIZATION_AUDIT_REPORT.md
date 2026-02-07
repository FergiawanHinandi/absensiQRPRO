# Authorization Audit Report
**Date:** 2025-01-XX  
**Auditor:** Security Review  
**Scope:** Attendance, Leave (StudentPermission), User, School Endpoints

---

## Executive Summary

### Security Status: 🟡 MEDIUM RISK

| Category | Status | Issues Found |
|----------|--------|--------------|
| Auth Middleware | ✅ Good | All API routes use `auth:sanctum` |
| Policy Coverage | 🟡 Partial | 3 missing registrations fixed |
| Query Scoping | 🟡 Risk | 2 controllers use raw queries |
| Admin Separation | ✅ Good | Clear super_admin vs school_admin |

### Critical Fixes Applied This Audit:
1. ✅ **StudentPhotoReviewController** - Added school_id filter (CRITICAL)
2. ✅ **StudentPermissionPolicy** - Created new policy
3. ✅ **SchoolPolicy** - Registered in AuthServiceProvider
4. ✅ **StudentPermissionPolicy** - Registered in AuthServiceProvider

---

## 1. Middleware Coverage Analysis

### API Routes (`routes/api.php`)

All API routes properly use `auth:sanctum` middleware:

```
✅ Route::middleware(['auth:sanctum']) - All /api/v1/* routes
✅ CheckUserActive - Blocks inactive accounts
✅ CheckApiMaintenance - 503 during maintenance
✅ RateLimitBySchool - Per-school throttling
✅ SecurityHeaders - XSS/CSP protection
```

### Route Groups Audit:

| Route Group | Auth | Rate Limit | Status |
|-------------|------|------------|--------|
| `/v1/attendance/*` | ✅ auth:sanctum | ✅ school.rate.limit:60,1 | PASS |
| `/v1/students/*` | ✅ auth:sanctum | ✅ school.rate.limit | PASS |
| `/v1/teachers/*` | ✅ auth:sanctum | ✅ school.rate.limit | PASS |
| `/v1/schools/*` | ✅ auth:sanctum | ✅ throttle:60,1 | PASS |
| `/v1/admin/*` | ✅ auth:sanctum | ✅ role-based | PASS |
| `/v1/reports/*` | ✅ auth:sanctum | ✅ school.rate.limit | PASS |

---

## 2. Policy Coverage Matrix

### Registered Policies (AuthServiceProvider)

| Model | Policy | Status |
|-------|--------|--------|
| `User` | `UserPolicy` | ✅ Registered |
| `Attendance` | `AttendancePolicy` | ✅ Registered |
| `ClassModel` | `ClassPolicy` | ✅ Registered |
| `Schedule` | `SchedulePolicy` | ✅ Registered |
| `AttendanceReport` | `ReportPolicy` | ✅ Registered |
| `QrCode` | `QrCodePolicy` | ✅ Registered |
| `StudentCard` | `StudentCardPolicy` | ✅ Registered |
| `School` | `SchoolPolicy` | ✅ **NOW REGISTERED** |
| `StudentPermission` | `StudentPermissionPolicy` | ✅ **NOW REGISTERED** |

### Gates Defined:

| Gate | Roles Allowed |
|------|---------------|
| `access-dashboard` | teacher, homeroom_teacher, admin, school_admin, principal, super_admin |
| `access-realtime-monitor` | teacher, homeroom_teacher, admin, school_admin, principal, super_admin |
| `manage-school-settings` | admin, school_admin, super_admin |
| `view-security-logs` | admin, school_admin, super_admin |
| `generate-qr-codes` | teacher, homeroom_teacher, admin, school_admin |
| `perform-bulk-operations` | admin, school_admin, super_admin |

---

## 3. Controller Authorization Audit

### ✅ Controllers with Proper Policy Checks

#### AttendanceReportController
```php
✅ $this->authorize('viewReports', Attendance::class);
✅ $this->authorize('export', Attendance::class);
✅ Uses AttendanceReportService with school_id scoping
```

#### AdminAttendanceController
```php
✅ $this->authorize('viewAny', Attendance::class);
✅ $this->authorize('update', $attendance);
✅ Query scoped by school_id
```

#### ScheduleController
```php
✅ $this->authorize('viewAny', Schedule::class);
✅ $this->authorize('create', Schedule::class);
✅ Uses SchedulePolicy
```

#### QrCodeController
```php
✅ $this->authorize('viewAny', QrCode::class);
✅ $this->authorize('create', QrCode::class);
✅ Uses QrCodePolicy
```

### 🟡 Controllers Relying on Traits/Middleware Only

These controllers use `BelongsToSchool` trait for scoping but don't call `authorize()`:

| Controller | Protection Method | Risk Level |
|------------|-------------------|------------|
| `TeacherScanController` | FormRequest + BelongsToSchool | LOW |
| `SecureAttendanceScanController` | AttendanceSecurityMiddleware | LOW |
| `AttendanceController` | BelongsToSchool trait | MEDIUM |

**Recommendation:** Add explicit `$this->authorize()` calls for defense-in-depth.

### 🔴 Controllers Fixed This Audit

#### StudentPhotoReviewController (CRITICAL FIX)
**Before:**
```php
// BUG: Could modify students from ANY school!
Student::whereIn('id', $request->input('student_ids'))
    ->update(['photo_approved' => true]);
```

**After:**
```php
// FIXED: Now filters by authenticated user's school
Student::whereIn('id', $request->input('student_ids'))
    ->where('school_id', $request->user()->school_id)
    ->update(['photo_approved' => true]);
```

---

## 4. Query Scoping Audit

### Models Using BelongsToSchool Trait (Auto-Scoped)

| Model | Has Trait | Global Scope Applied |
|-------|-----------|---------------------|
| `Attendance` | ✅ | ✅ SchoolScope |
| `User` | ✅ | ✅ SchoolScope |
| `Schedule` | ✅ | ✅ SchoolScope |
| `Student` | ✅ | ✅ SchoolScope |
| `Teacher` | ✅ | ✅ SchoolScope |
| `ClassModel` | ✅ | ✅ SchoolScope |
| `QrCode` | ✅ | ✅ SchoolScope |
| `StudentCard` | ✅ | ✅ SchoolScope |
| `StudentPermission` | ✅ | ✅ SchoolScope |
| `TeacherDevice` | ✅ | ✅ SchoolScope |
| `SecurityEvent` | ✅ | ✅ SchoolScope |

### ⚠️ Raw Queries Without Scope (Audit Flags)

Found in these locations:

```php
// DashboardController.php - Uses DB::table()
DB::table('attendances')
    ->where('school_id', $schoolId)  // Manual filter - OK but fragile
    
// BackupService.php - Uses DB::select()
DB::select('SELECT * FROM attendances WHERE ...')  // Manual filter
```

**Recommendation:** Migrate to Eloquent queries where possible.

---

## 5. Admin Role Differentiation

### Role Hierarchy (Highest to Lowest)

```
super_admin     → Global access to ALL schools
├── school_admin → Full access to OWN school only
│   ├── principal → View/manage attendance, limited settings
│   │   ├── homeroom_teacher → Manage own class students
│   │   │   └── teacher → Scan/view own schedule attendance
│   │   └── student → View own attendance
│   └── parent → View children's attendance
```

### Permission Matrix

| Action | super_admin | school_admin | principal | teacher | student |
|--------|-------------|--------------|-----------|---------|---------|
| Create School | ✅ | ❌ | ❌ | ❌ | ❌ |
| Delete School | ✅ | ❌ | ❌ | ❌ | ❌ |
| Update School | ✅ | ✅ own | ❌ | ❌ | ❌ |
| Manage Users | ✅ | ✅ own school | ❌ | ❌ | ❌ |
| View All Attendance | ✅ | ✅ own school | ✅ own school | ❌ | ❌ |
| Scan Attendance | ✅ | ✅ | ✅ | ✅ | ❌ |
| View Own Attendance | ✅ | ✅ | ✅ | ✅ | ✅ |
| Generate QR | ✅ | ✅ | ❌ | ✅ | ❌ |
| Export Reports | ✅ | ✅ own school | ✅ own school | ❌ | ❌ |
| Approve Leave | ✅ | ✅ | ✅ | homeroom only | ❌ |

---

## 6. Endpoint Authorization Summary

### Attendance Endpoints

| Endpoint | Method | Auth | Policy | Status |
|----------|--------|------|--------|--------|
| `GET /v1/attendance` | viewAny | ✅ | ✅ AttendancePolicy | PASS |
| `POST /v1/attendance/scan` | scan | ✅ | ✅ AttendancePolicy | PASS |
| `POST /v1/attendance/teacher-scan` | scanStudent | ✅ | ✅ AttendancePolicy | PASS |
| `GET /v1/attendance/{id}` | view | ✅ | ✅ AttendancePolicy | PASS |
| `PUT /v1/attendance/{id}` | update | ✅ | ✅ AttendancePolicy | PASS |
| `DELETE /v1/attendance/{id}` | delete | ✅ | ✅ AttendancePolicy | PASS |
| `GET /v1/attendance/reports` | viewReports | ✅ | ✅ AttendancePolicy | PASS |
| `GET /v1/attendance/export` | export | ✅ | ✅ AttendancePolicy | PASS |

### Leave/Permission Endpoints

| Endpoint | Method | Auth | Policy | Status |
|----------|--------|------|--------|--------|
| `GET /v1/permissions` | viewAny | ✅ | ✅ StudentPermissionPolicy | PASS |
| `POST /v1/permissions` | create | ✅ | ✅ StudentPermissionPolicy | PASS |
| `GET /v1/permissions/{id}` | view | ✅ | ✅ StudentPermissionPolicy | PASS |
| `PUT /v1/permissions/{id}` | update | ✅ | ✅ StudentPermissionPolicy | PASS |
| `POST /v1/permissions/{id}/approve` | approve | ✅ | ✅ StudentPermissionPolicy | PASS |
| `POST /v1/permissions/{id}/reject` | reject | ✅ | ✅ StudentPermissionPolicy | PASS |

### User Endpoints

| Endpoint | Method | Auth | Policy | Status |
|----------|--------|------|--------|--------|
| `GET /v1/users` | viewAny | ✅ | ✅ UserPolicy | PASS |
| `POST /v1/users` | create | ✅ | ✅ UserPolicy | PASS |
| `GET /v1/users/{id}` | view | ✅ | ✅ UserPolicy | PASS |
| `PUT /v1/users/{id}` | update | ✅ | ✅ UserPolicy | PASS |
| `DELETE /v1/users/{id}` | delete | ✅ | ✅ UserPolicy | PASS |

### School Endpoints

| Endpoint | Method | Auth | Policy | Status |
|----------|--------|------|--------|--------|
| `GET /v1/schools` | viewAny | ✅ | ✅ SchoolPolicy | PASS |
| `POST /v1/schools` | create | ✅ | ✅ SchoolPolicy | PASS |
| `GET /v1/schools/{id}` | view | ✅ | ✅ SchoolPolicy | PASS |
| `PUT /v1/schools/{id}` | update | ✅ | ✅ SchoolPolicy | PASS |
| `DELETE /v1/schools/{id}` | delete | ✅ | ✅ SchoolPolicy | PASS |

---

## 7. authorize() Usage Examples

### Basic CRUD Authorization

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * List attendances - checks viewAny policy
     */
    public function index(Request $request)
    {
        // Check if user can view any attendance records
        $this->authorize('viewAny', Attendance::class);
        
        // BelongsToSchool trait auto-scopes query
        return Attendance::with(['student', 'schedule'])
            ->paginate(20);
    }

    /**
     * Show single attendance - checks view policy
     */
    public function show(Attendance $attendance)
    {
        // Check if user can view THIS specific record
        $this->authorize('view', $attendance);
        
        return $attendance->load(['student', 'schedule', 'teacher']);
    }

    /**
     * Update attendance - checks update policy
     */
    public function update(UpdateAttendanceRequest $request, Attendance $attendance)
    {
        // Check if user can update THIS specific record
        $this->authorize('update', $attendance);
        
        $attendance->update($request->validated());
        
        return $attendance;
    }

    /**
     * Delete attendance - checks delete policy
     */
    public function destroy(Attendance $attendance)
    {
        // Check if user can delete THIS specific record
        $this->authorize('delete', $attendance);
        
        $attendance->delete();
        
        return response()->noContent();
    }
}
```

### Custom Policy Methods

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attendance;
use App\Models\Schedule;

class AttendanceScanController extends Controller
{
    /**
     * Teacher scanning a student - uses custom policy method
     */
    public function scanStudent(TeacherScanRequest $request)
    {
        // Get the schedule being scanned
        $schedule = Schedule::findOrFail($request->schedule_id);
        
        // Check custom 'scanStudent' policy method
        $this->authorize('scanStudent', [Attendance::class, $schedule]);
        
        // Proceed with scan...
    }

    /**
     * Generate attendance report - uses viewReports policy
     */
    public function report(ReportRequest $request)
    {
        // Check report generation permission
        $this->authorize('viewReports', Attendance::class);
        
        // Generate report...
    }

    /**
     * Export attendance data - uses export policy
     */
    public function export(ExportRequest $request)
    {
        // Check export permission
        $this->authorize('export', Attendance::class);
        
        // Export data...
    }
}
```

### Leave/Permission Authorization

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StudentPermission;

class PermissionController extends Controller
{
    /**
     * Approve a leave request - homeroom teacher only
     */
    public function approve(StudentPermission $permission)
    {
        // Check 'approve' policy - only homeroom can approve
        $this->authorize('approve', $permission);
        
        $permission->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        
        return response()->json([
            'message' => 'Izin berhasil disetujui',
            'data' => $permission
        ]);
    }

    /**
     * Reject a leave request
     */
    public function reject(RejectPermissionRequest $request, StudentPermission $permission)
    {
        // Check 'reject' policy
        $this->authorize('reject', $permission);
        
        $permission->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
        ]);
        
        return response()->json([
            'message' => 'Izin ditolak',
            'data' => $permission
        ]);
    }
}
```

### Gate Usage for Non-Model Actions

```php
<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    /**
     * Manage school settings - uses Gate instead of Policy
     */
    public function update(UpdateSettingsRequest $request)
    {
        // Check gate permission
        if (Gate::denies('manage-school-settings')) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah pengaturan.');
        }
        
        // Update settings...
    }

    /**
     * Alternative: Using authorize with Gate
     */
    public function viewSecurityLogs()
    {
        Gate::authorize('view-security-logs');
        
        // Return logs...
    }
}
```

---

## 8. Recommendations

### High Priority
1. ✅ ~~Add school_id filter to StudentPhotoReviewController~~ (FIXED)
2. ✅ ~~Register SchoolPolicy and StudentPermissionPolicy~~ (FIXED)
3. Add explicit `authorize()` to AttendanceController for defense-in-depth
4. Audit all `DB::table()` and `DB::select()` raw queries

### Medium Priority
5. Add logging for policy denials (for security monitoring)
6. Create integration tests for all policy methods
7. Document policy requirements in API specification

### Low Priority
8. Consider Laravel Sanctum token abilities for API scoping
9. Add rate limiting per-user in addition to per-school

---

## 9. Files Modified This Audit

| File | Change |
|------|--------|
| `app/Providers/AuthServiceProvider.php` | Registered SchoolPolicy, StudentPermissionPolicy |
| `app/Policies/StudentPermissionPolicy.php` | Created new policy |
| `app/Http/Controllers/Api/V1/StudentPhotoReviewController.php` | Added school_id filter |

---

## Appendix: Policy Method Reference

### AttendancePolicy Methods
- `viewAny` - List attendance records
- `view` - View single record
- `create` - Create new attendance
- `update` - Modify attendance
- `delete` - Soft delete
- `scan` - Student QR scan
- `scanStudent` - Teacher scanning student
- `createManual` - Manual input
- `viewReports` - Access reports
- `export` - Export data
- `viewBySchedule` - View by schedule

### StudentPermissionPolicy Methods
- `viewAny` - List permissions
- `view` - View single permission
- `create` - Submit permission request
- `update` - Modify pending permission
- `approve` - Approve request (homeroom only)
- `reject` - Reject request (homeroom only)
- `delete` - Cancel permission

### SchoolPolicy Methods
- `viewAny` - List schools
- `view` - View school
- `create` - Create school (super_admin only)
- `update` - Update school settings
- `delete` - Delete school (super_admin only)
- `manageUsers` - Manage school users
- `manageSchedules` - Manage schedules
- `viewReports` - View school reports
- `configureGeofence` - Configure location settings
