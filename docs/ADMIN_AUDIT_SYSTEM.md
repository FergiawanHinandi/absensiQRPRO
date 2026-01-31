# Admin Action Audit System

Comprehensive audit logging system for tracking all sensitive admin actions in the multi-tenant school attendance platform.

## Overview

The Admin Action Audit system provides:

1. **Tamper-proof logging** - Dual-write to both `admin_activity_logs` and immutable security log chain
2. **Automatic capture** - Middleware auto-captures POST/PUT/PATCH/DELETE operations
3. **High-risk alerting** - Automatic alerts for sensitive actions (device resets, attendance overrides, etc.)
4. **Role-based visibility** - Super admins see all, school admins see their school only

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      Admin Action Flow                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  [Admin Request] → [LogAdminAction Middleware] → [Controller]  │
│                            ↓                                    │
│                  [AdminAuditService.log()]                      │
│                      ↓              ↓                           │
│     [admin_activity_logs]    [ImmutableSecurityLog]            │
│                      ↓                                          │
│           [If High-Risk: SecurityAlertService]                  │
│                      ↓                                          │
│            [Telegram/Slack Notification]                        │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## Components

### 1. Database Table

**Table:** `admin_activity_logs`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| admin_user_id | bigint | FK to users table |
| role | varchar(50) | Role at time of action |
| school_id | bigint (nullable) | School context |
| action_type | varchar(100) | Action type constant |
| target_type | varchar(100) | Entity type (Teacher, Student, etc.) |
| target_id | bigint (nullable) | Target entity ID |
| description | text | Human-readable description |
| metadata | jsonb | Structured additional data |
| ip_address | varchar(45) | Client IP |
| user_agent | varchar(500) | Browser/client info |
| route_name | varchar(200) | Laravel route name |
| http_method | varchar(10) | HTTP method |
| created_at | timestamp | Creation time |

**Protection:** Database triggers prevent UPDATE and DELETE operations.

### 2. Model

**File:** `app/Models/AdminActivityLog.php`

Features:
- Application-level update/delete protection
- Action type constants (e.g., `ACTION_TEACHER_DEVICE_RESET`)
- High-risk action list
- Query scopes for filtering
- Display name helpers

### 3. Service

**File:** `app/Services/AdminAuditService.php`

Primary method:
```php
$auditService->log(
    AdminActivityLog::ACTION_TEACHER_DEVICE_RESET,
    'Teacher',          // Target type
    $teacherId,         // Target ID
    'Reset device...',  // Description
    ['metadata' => 'value']  // Additional data
);
```

Convenience methods:
- `logLogin($admin)`
- `logLogout($admin)`
- `logLoginFailed($email)`
- `logDeviceReset($teacherId, $teacherName)`
- `logAttendanceOverride($attendanceId, ...)`
- `logGeofenceChange($schoolId, $oldConfig, $newConfig)`
- `logUserCreate($userId, $email, $role)`
- `logUserDelete($userId, $email)`
- `logPermissionChange($targetUserId, $oldRole, $newRole)`
- `logBackupCreate($backupName, $sizeBytes)`
- `logBackupRestore($backupName)`
- `logMaintenanceToggle($enabled)`
- `logDataExport($exportType, $filters, $recordCount)`
- `logStudentImport($successCount, $failedCount)`
- `logApiKeyGenerate($keyName, $targetUserId)`
- `logApiKeyRevoke($keyName, $targetUserId)`

### 4. Middleware

**File:** `app/Http/Middleware/LogAdminAction.php`

Automatic capture of admin actions based on:
- Route name patterns
- HTTP method
- Request path

Apply to route groups:
```php
Route::middleware('log.admin.action')->group(function () {
    // Admin routes...
});
```

## API Endpoints

### List Activity Logs

```http
GET /api/v1/admin/system/admin-activity
```

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| admin_user_id | int | Filter by admin |
| action_type | string | Filter by action type |
| action_types | string/array | Filter by multiple types |
| target_type | string | Filter by target entity |
| target_id | int | Filter by specific target |
| high_risk | bool | Show only high-risk actions |
| date_from | string | Start date (Y-m-d) |
| date_to | string | End date (Y-m-d) |
| search | string | Search description |
| per_page | int | Pagination (max: 100) |

### Get Single Log Entry

```http
GET /api/v1/admin/system/admin-activity/{id}
```

### Get Summary Statistics

```http
GET /api/v1/admin/system/admin-activity/summary?days=7
```

Returns:
- Total actions count
- High-risk actions count
- Actions by type
- Most active admins
- Recent high-risk actions
- Daily trend data

### Get Available Action Types

```http
GET /api/v1/admin/system/admin-activity/action-types
```

### Get Activity for Specific Target

```http
GET /api/v1/admin/system/admin-activity/target/{type}/{id}
```

Example: `/api/v1/admin/system/admin-activity/target/teacher/123`

### Get My Activity

```http
GET /api/v1/admin/system/admin-activity/my-activity
```

## High-Risk Actions

These actions automatically trigger security alerts:

| Action | Alert Severity |
|--------|----------------|
| `backup_restore` | CRITICAL |
| `user_delete` | CRITICAL |
| `school_delete` | CRITICAL |
| `attendance_delete` | CRITICAL |
| `teacher_device_reset` | HIGH |
| `attendance_override` | HIGH |
| `geofence_update` | HIGH |
| `permission_change` | HIGH |
| `api_key_generate` | HIGH |
| `api_key_revoke` | HIGH |
| `maintenance_enable` | HIGH |

## Usage Examples

### In Controllers

```php
use App\Services\AdminAuditService;
use App\Models\AdminActivityLog;

class TeacherController extends Controller
{
    public function __construct(
        private AdminAuditService $auditService
    ) {}
    
    public function resetDevice(Teacher $teacher)
    {
        // Perform device reset...
        $teacher->update(['device_id' => null]);
        
        // Log the action with full details
        $this->auditService->logDeviceReset(
            $teacher->id,
            $teacher->name,
            [
                'previous_device_id' => $oldDeviceId,
                'reason' => $request->input('reason'),
            ]
        );
        
        return response()->json(['success' => true]);
    }
}
```

### Querying Logs

```php
use App\Models\AdminActivityLog;

// Get all high-risk actions in last 24 hours
$logs = AdminActivityLog::highRisk()
    ->recent(24)
    ->with('adminUser')
    ->get();

// Get all actions for a specific teacher
$logs = AdminActivityLog::forTarget('Teacher', $teacherId)
    ->orderByDesc('created_at')
    ->paginate(25);

// Get actions by specific admin
$logs = AdminActivityLog::forAdmin($adminId)
    ->dateRange('2025-01-01', '2025-01-31')
    ->get();
```

## Database Triggers

The migration creates PostgreSQL/MySQL triggers that prevent modification:

```sql
-- PostgreSQL
CREATE TRIGGER prevent_update_admin_activity_logs
BEFORE UPDATE ON admin_activity_logs
FOR EACH ROW
EXECUTE FUNCTION prevent_admin_activity_log_modification();

CREATE TRIGGER prevent_delete_admin_activity_logs
BEFORE DELETE ON admin_activity_logs
FOR EACH ROW
EXECUTE FUNCTION prevent_admin_activity_log_modification();
```

## Testing

Run the migration:
```bash
php artisan migrate
```

Test the endpoints:
```bash
# Get activity logs
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/system/admin-activity"

# Get summary
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/system/admin-activity/summary?days=7"

# Get action types
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/system/admin-activity/action-types"
```

## Security Considerations

1. **Immutability**: Logs are protected at both application and database levels
2. **Dual-write**: Every action is written to both regular table AND immutable chain
3. **Access control**: School admins can only see their school's activity
4. **Alert integration**: High-risk actions automatically notify security team
5. **Audit trail for viewing**: Even viewing audit logs is logged

## Integration with Existing Systems

The Admin Action Audit system integrates with:

1. **ImmutableSecurityLogService**: Uses existing hash-chained log for tamper detection
2. **SecurityAlertService**: Uses existing alert system for notifications
3. **Telegram/Slack notifications**: Via existing SendSecurityAlertNotification job

## Files Created

- `database/migrations/2026_01_29_210000_create_admin_activity_logs_table.php`
- `app/Models/AdminActivityLog.php`
- `app/Services/AdminAuditService.php`
- `app/Http/Middleware/LogAdminAction.php`
- `app/Http/Controllers/Api/V1/Admin/AdminActivityController.php`
- `docs/ADMIN_AUDIT_SYSTEM.md` (this file)

## Files Modified

- `bootstrap/app.php` - Added middleware alias
- `routes/api.php` - Added activity endpoints and middleware to admin routes
