# ChangeAttendanceStatus Quick Reference

## 🚀 Quick Usage

### Basic Change

```php
use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Handlers\ChangeAttendanceStatusHandler;

$command = new ChangeAttendanceStatusCommand(
    attendanceId: 123,
    newStatus: 'late',
    userId: auth()->id(),
    reason: 'Traffic delay'
);

$attendance = app(ChangeAttendanceStatusHandler::class)->handle($command);
```

### In Controller

```php
public function updateStatus(Request $request, int $id)
{
    $command = new ChangeAttendanceStatusCommand(
        attendanceId: $id,
        newStatus: $request->input('status'),
        userId: auth()->id(),
        reason: $request->input('reason')
    );
    
    $attendance = app(ChangeAttendanceStatusHandler::class)->handle($command);
    
    return response()->json(['data' => $attendance]);
}
```

## ✅ Valid Statuses

```
present    late    absent
excused    sick    permission
```

## ❌ Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "Invalid status" | Wrong status value | Use valid status |
| "already marked as" | Same status | Change to different status |
| "older than 7 days" | Too old | Only change recent attendance |
| "Unauthorized" | Wrong school | Check user school_id |

## 🔍 Check Audit Log

```php
// Get changes for attendance
AuditLog::where('auditable_id', $attendanceId)
    ->where('action', 'attendance.status_changed')
    ->get();
```

## 🧪 Run Tests

```bash
php artisan test --filter=ChangeAttendanceStatusTest
```

## 📊 Business Rules

- ✅ Cannot change to same status
- ✅ Cannot change if > 7 days old
- ✅ Cannot access other school's data
- ✅ All changes logged in audit_logs
- ✅ Read model auto-updated via event

## 📁 Files

- Command: `app/Domain/Attendance/Commands/ChangeAttendanceStatusCommand.php`
- Handler: `app/Domain/Attendance/Handlers/ChangeAttendanceStatusHandler.php`
- Event: `app/Domain/Attendance/Events/AttendanceStatusChanged.php`
- Tests: `tests/Feature/ChangeAttendanceStatusTest.php`
- Docs: `docs/CHANGE_ATTENDANCE_STATUS.md`
