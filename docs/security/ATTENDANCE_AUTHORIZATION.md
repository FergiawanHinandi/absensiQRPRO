# Attendance Authorization System

## Overview

This document describes the Laravel Policy-based authorization system for the attendance module. The system enforces:

1. **School Isolation**: Users can ONLY access data from their own school
2. **Role-Based Access**: Different permissions per role
3. **Super Admin Bypass**: Full access to all data across all schools

---

## Role Hierarchy

```
┌─────────────────────────────────────────────────────────────────┐
│                        SUPER ADMIN                               │
│  • Full access to ALL schools                                    │
│  • Can bypass all restrictions                                   │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                     SCHOOL-LEVEL ADMIN                           │
│  school_admin, admin                                             │
│  • Full access to own school                                     │
│  • Can CRUD all attendance records                               │
│  • Can export data                                               │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                        PRINCIPAL                                 │
│  • View all attendance in school                                 │
│  • View reports                                                  │
│  • Cannot modify records                                         │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                   HOMEROOM TEACHER                               │
│  • View/manage attendance for assigned classes                   │
│  • Create manual attendance                                      │
│  • View class reports                                            │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                        TEACHER                                   │
│  • View/manage attendance for schedules they teach               │
│  • Create manual attendance for their classes                    │
│  • Scan student QR codes                                         │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                        STUDENT                                   │
│  • View ONLY their own attendance                                │
│  • Scan QR to check-in                                           │
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│                         PARENT                                   │
│  • View ONLY linked children's attendance                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## Policy Methods Reference

### AttendancePolicy

| Method | Description | Allowed Roles |
|--------|-------------|---------------|
| `viewAny` | View attendance list | All roles |
| `view` | View specific attendance | Own data + higher roles |
| `create` | Create attendance record | Management roles + student (scan) |
| `createManual` | Create manual attendance | Teacher, Admin |
| `update` | Update attendance (manual only) | Teacher (own), Admin |
| `delete` | Soft delete attendance | Admin only |
| `restore` | Restore deleted attendance | Admin only |
| `forceDelete` | Permanent delete | Denied for all |
| `scan` | Student QR scan | Student only |
| `scanStudent` | Teacher scan student QR | Teacher only |
| `viewReports` | View attendance reports | Admin, Principal, Homeroom |
| `export` | Export data | Admin, Principal |
| `viewBySchedule` | View by schedule | Owner or Admin |

---

## Using Authorization in Controllers

### Method 1: Using `$this->authorize()`

```php
<?php

class AttendanceController extends Controller
{
    /**
     * View list of attendance records
     */
    public function index(Request $request)
    {
        // Check if user can view any attendance
        // Throws AuthorizationException if denied
        $this->authorize('viewAny', Attendance::class);

        // User is authorized, proceed...
        return Attendance::where('school_id', $request->user()->school_id)->get();
    }

    /**
     * View specific attendance record
     */
    public function show(int $id)
    {
        $attendance = Attendance::findOrFail($id);

        // Check if user can view THIS specific attendance
        // Policy will check school_id, role, etc.
        $this->authorize('view', $attendance);

        return $attendance;
    }

    /**
     * Update attendance record
     */
    public function update(Request $request, int $id)
    {
        $attendance = Attendance::findOrFail($id);

        // Authorize BEFORE modifying
        $this->authorize('update', $attendance);

        $attendance->update($request->validated());
        return $attendance;
    }
}
```

### Method 2: Using Gate Facade

```php
use Illuminate\Support\Facades\Gate;

public function export(Request $request)
{
    // Check using Gate facade
    if (Gate::denies('export', Attendance::class)) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    // Proceed with export...
}

// Or with response
public function export(Request $request)
{
    $response = Gate::inspect('export', Attendance::class);

    if ($response->denied()) {
        return response()->json([
            'success' => false,
            'message' => $response->message(), // Custom denial message
        ], 403);
    }
}
```

### Method 3: Using `can()` on User

```php
public function index(Request $request)
{
    $user = $request->user();

    // Check using user method
    if ($user->cannot('viewAny', Attendance::class)) {
        abort(403, 'Unauthorized');
    }

    // Or get boolean result
    $canView = $user->can('view', $attendance);
    $canEdit = $user->can('update', $attendance);
    $canDelete = $user->can('delete', $attendance);

    return view('attendance.index', compact('attendance', 'canEdit', 'canDelete'));
}
```

### Method 4: In Blade Templates

```blade
@can('update', $attendance)
    <button>Edit</button>
@endcan

@cannot('delete', $attendance)
    <span class="text-gray-400">Delete disabled</span>
@endcannot

@canany(['update', 'delete'], $attendance)
    <div class="actions">
        @can('update', $attendance)
            <button>Edit</button>
        @endcan
        @can('delete', $attendance)
            <button>Delete</button>
        @endcan
    </div>
@endcanany
```

---

## Eager Loading Best Practices

### ❌ N+1 Problem

```php
// BAD: N+1 queries when checking policy
$attendances = Attendance::all();
foreach ($attendances as $attendance) {
    if ($user->can('view', $attendance)) { // Loads schedule each time
        // ...
    }
}
```

### ✅ Eager Loading Solution

```php
// GOOD: Eager load relationships used in policy
$attendances = Attendance::with([
    'schedule:id,teacher_id,class_id',  // Policy checks teacher ownership
    'student:id,school_id',              // Policy might check school
])
->where('school_id', $user->school_id)   // Pre-filter by school
->get();

foreach ($attendances as $attendance) {
    // No additional queries - relationships already loaded
    if ($user->can('view', $attendance)) {
        // ...
    }
}
```

### Selecting Only Needed Columns

```php
// OPTIMAL: Select only what's needed for display AND policy
$attendances = Attendance::query()
    ->where('school_id', $user->school_id)
    ->select([
        'id',
        'student_id',
        'schedule_id',
        'school_id',      // Needed for policy
        'class_id',       // Needed for homeroom check
        'attendance_date',
        'status',
        'check_in_time',
        'is_manual',      // Needed for update policy
    ])
    ->with([
        'student:id,name,username',
        'schedule:id,subject_id,teacher_id',  // teacher_id for policy
        'schedule.subject:id,name',
    ])
    ->paginate(20);
```

---

## Custom Gates

Defined in `AuthServiceProvider`:

```php
// Usage examples

// In controller
if (Gate::allows('access-dashboard')) {
    // Show dashboard
}

// With authorize helper
$this->authorize('manage-school-settings');

// In middleware
Route::middleware('can:view-security-logs')->group(function () {
    Route::get('/security-logs', ...);
});
```

| Gate | Description | Roles |
|------|-------------|-------|
| `access-dashboard` | Access school dashboard | Teacher, Admin, Principal |
| `access-realtime-monitor` | Real-time monitoring | Teacher, Admin, Principal |
| `manage-school-settings` | School settings | Admin only |
| `view-security-logs` | Security audit logs | Admin only |
| `generate-qr-codes` | Generate QR codes | Teacher, Admin |
| `perform-bulk-operations` | Bulk import/export | Admin only |

---

## Security Layers

The authorization system provides **defense in depth**:

```
Layer 1: Route Middleware
├── auth:sanctum (must be authenticated)
├── role middleware (basic role check)
│
Layer 2: Controller
├── $this->authorize() calls
├── Gate checks
│
Layer 3: Policy (LAST LINE OF DEFENSE)
├── School isolation check
├── Role-specific permissions
├── Resource ownership check
│
Layer 4: Global Scopes
├── SchoolScope (auto-filters by school_id)
└── Prevents cross-school data access
```

---

## Testing Authorization

```php
<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function student_can_only_view_own_attendance()
    {
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => 1]);
        $ownAttendance = Attendance::factory()->create(['student_id' => $student->id, 'school_id' => 1]);
        $otherAttendance = Attendance::factory()->create(['student_id' => 999, 'school_id' => 1]);

        // Can view own
        $this->assertTrue($student->can('view', $ownAttendance));

        // Cannot view others
        $this->assertFalse($student->can('view', $otherAttendance));
    }

    /** @test */
    public function user_cannot_access_other_school_data()
    {
        $user = User::factory()->create(['role_type' => 'admin', 'school_id' => 1]);
        $otherSchoolAttendance = Attendance::factory()->create(['school_id' => 2]);

        // Cannot view other school's data
        $this->assertFalse($user->can('view', $otherSchoolAttendance));
    }

    /** @test */
    public function super_admin_can_access_all_schools()
    {
        $superAdmin = User::factory()->create(['role_type' => 'super_admin']);
        $attendance = Attendance::factory()->create(['school_id' => 999]);

        // Can view any school
        $this->assertTrue($superAdmin->can('view', $attendance));
    }

    /** @test */
    public function only_manual_attendance_can_be_updated()
    {
        $admin = User::factory()->create(['role_type' => 'admin', 'school_id' => 1]);
        $autoAttendance = Attendance::factory()->create(['is_manual' => false, 'school_id' => 1]);
        $manualAttendance = Attendance::factory()->create(['is_manual' => true, 'school_id' => 1]);

        // Cannot update auto attendance
        $this->assertFalse($admin->can('update', $autoAttendance));

        // Can update manual attendance
        $this->assertTrue($admin->can('update', $manualAttendance));
    }
}
```

---

## Error Messages

The policy returns Indonesian error messages:

| Scenario | Message |
|----------|---------|
| Cross-school access | "Anda tidak dapat mengakses data dari sekolah lain." |
| Student viewing others | "Anda hanya dapat melihat absensi Anda sendiri." |
| Teacher wrong class | "Anda hanya dapat mengubah absensi kelas yang Anda ajar." |
| Update auto attendance | "Absensi otomatis (via QR) tidak dapat diubah." |
| Delete without permission | "Hanya administrator yang dapat menghapus data absensi." |
| Student role required | "Hanya siswa yang dapat melakukan scan QR." |
| Inactive account | "Akun Anda tidak aktif." |
