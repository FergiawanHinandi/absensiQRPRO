# Change Attendance Status - Implementation Guide

## Overview

Fitur ini memungkinkan perubahan status attendance dengan validasi state transition, audit logging, dan integrasi CQRS yang aman.

**Key Features**:
- ✅ State machine validation
- ✅ Database transaction
- ✅ Comprehensive audit logging
- ✅ CQRS event dispatch
- ✅ School isolation
- ✅ Non-breaking changes

---

## Architecture

### Command Pattern

```
ChangeAttendanceStatusCommand
    ↓
ChangeAttendanceStatusHandler
    ↓
├─ Validate state transition
├─ Update attendance (in transaction)
├─ Create audit log
└─ Dispatch AttendanceStatusChanged event
    ↓
UpdateAttendanceSummaryListener
    ↓
Update read model (AttendanceDailySummary)
```

---

## Usage

### Basic Usage

```php
use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Handlers\ChangeAttendanceStatusHandler;

// Create command
$command = new ChangeAttendanceStatusCommand(
    attendanceId: 123,
    newStatus: 'late',
    userId: auth()->id(),
    reason: 'Student arrived late due to traffic'
);

// Execute command
$handler = app(ChangeAttendanceStatusHandler::class);
$attendance = $handler->handle($command);

// Result: Attendance status changed, audit logged, read model updated
```

### In Controller (Non-breaking Integration)

```php
// OLD CODE (still works):
$attendance->update(['status' => 'late']);

// NEW CODE (recommended):
use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Handlers\ChangeAttendanceStatusHandler;

$command = new ChangeAttendanceStatusCommand(
    attendanceId: $attendance->id,
    newStatus: $request->input('status'),
    userId: auth()->id(),
    reason: $request->input('reason')
);

$attendance = app(ChangeAttendanceStatusHandler::class)->handle($command);

// Response format remains the same
return response()->json([
    'success' => true,
    'data' => $attendance,
]);
```

---

## Validation Rules

### Valid Status Transitions

```php
Valid statuses:
- present
- late
- absent
- excused
- sick
- permission
```

### Business Rules

1. **Cannot change to same status**
   ```php
   // ❌ Will throw exception
   present → present
   ```

2. **Cannot change old attendance (> 7 days)**
   ```php
   // ❌ Will throw exception
   Attendance from 8 days ago
   ```

3. **Cannot access other school's attendance**
   ```php
   // ❌ Will throw exception
   Teacher from School A trying to change School B's attendance
   ```

4. **State machine validation** (if implemented)
   ```php
   // Example: Cannot go from absent to present directly
   // Must go through excused first
   ```

---

## Audit Logging

### Audit Log Structure

```json
{
  "user_id": 123,
  "action": "attendance.status_changed",
  "auditable_type": "App\\Models\\Attendance",
  "auditable_id": 456,
  "old_values": {
    "status": "present"
  },
  "new_values": {
    "status": "late"
  },
  "metadata": {
    "student_id": 789,
    "attendance_date": "2026-02-09",
    "reason": "Student arrived late due to traffic"
  },
  "ip_address": "192.168.1.1",
  "user_agent": "Mozilla/5.0..."
}
```

### Querying Audit Logs

```php
// Get all status changes for an attendance
$logs = AuditLog::where('auditable_type', Attendance::class)
    ->where('auditable_id', $attendanceId)
    ->where('action', 'attendance.status_changed')
    ->orderBy('created_at', 'desc')
    ->get();

// Get all changes by a specific user
$logs = AuditLog::where('user_id', $userId)
    ->where('action', 'attendance.status_changed')
    ->get();

// Get changes within date range
$logs = AuditLog::where('action', 'attendance.status_changed')
    ->whereBetween('created_at', [$startDate, $endDate])
    ->get();
```

---

## CQRS Integration

### Event Flow

```
1. ChangeAttendanceStatusHandler executes
   ↓
2. Attendance status updated in write model
   ↓
3. AttendanceStatusChanged event dispatched
   ↓
4. UpdateAttendanceSummaryListener handles event
   ↓
5. Read model (AttendanceDailySummary) updated
   - Decrement old status count
   - Increment new status count
   - Recalculate attendance rate
```

### Eventual Consistency

- **Write model**: Updated immediately (strong consistency)
- **Read model**: Updated asynchronously (1-2 second delay acceptable)
- **Dashboard**: Shows updated data after event processing

---

## Authorization

### Role-based Access

```php
// Principal: Can change any attendance in their school
if (auth()->user()->role === 'principal') {
    // ✅ Allowed
}

// Teacher: Can change attendance in their school
if (auth()->user()->role === 'teacher') {
    // ✅ Allowed (with school scope)
}

// Teacher: Cannot change other school's attendance
if (auth()->user()->school_id !== $attendance->school_id) {
    // ❌ Throws exception: "Unauthorized: Cannot access attendance from another school"
}
```

### Policy Example

```php
// app/Policies/AttendancePolicy.php

public function update(User $user, Attendance $attendance): bool
{
    // Super admin can update any
    if ($user->role === 'super_admin') {
        return true;
    }
    
    // Must be same school
    if ($user->school_id !== $attendance->school_id) {
        return false;
    }
    
    // Principal and teacher can update
    return in_array($user->role, ['principal', 'teacher']);
}
```

---

## Testing

### Run Tests

```bash
# Run all change status tests
php artisan test --filter=ChangeAttendanceStatusTest

# Run specific test
php artisan test --filter=it_changes_attendance_status_successfully
```

### Test Coverage

✅ **Positive Cases**:
- Status change successful
- Audit log created
- Event dispatched
- Read model updated

✅ **Validation**:
- Invalid status rejected
- Same status rejected
- Old attendance rejected
- Cross-school access blocked

✅ **Authorization**:
- Principal can change
- Teacher can change (same school)
- Teacher cannot change (other school)

✅ **Data Integrity**:
- Transaction rollback on error
- Multiple changes tracked
- Audit trail complete

---

## Error Handling

### Common Errors

```php
// 1. Invalid status
try {
    $command = new ChangeAttendanceStatusCommand(
        attendanceId: 123,
        newStatus: 'invalid_status',
        userId: 1
    );
} catch (\InvalidArgumentException $e) {
    // "Invalid status. Must be one of: present, late, absent, excused, sick, permission"
}

// 2. Same status
try {
    $handler->handle($command);
} catch (\Exception $e) {
    // "Attendance is already marked as 'present'"
}

// 3. Too old
try {
    $handler->handle($command);
} catch (\Exception $e) {
    // "Cannot change attendance status older than 7 days"
}

// 4. Unauthorized
try {
    $handler->handle($command);
} catch (\Exception $e) {
    // "Unauthorized: Cannot access attendance from another school"
}
```

### Error Response Format

```json
{
  "success": false,
  "error": "Cannot change attendance status older than 7 days",
  "code": "ATTENDANCE_TOO_OLD"
}
```

---

## Migration from Old Code

### Step 1: Identify Old Code

```php
// OLD: Direct model update
$attendance = Attendance::find($id);
$attendance->status = 'late';
$attendance->save();
```

### Step 2: Replace with Command

```php
// NEW: Using command pattern
use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Handlers\ChangeAttendanceStatusHandler;

$command = new ChangeAttendanceStatusCommand(
    attendanceId: $id,
    newStatus: 'late',
    userId: auth()->id(),
    reason: $request->input('reason')
);

$attendance = app(ChangeAttendanceStatusHandler::class)->handle($command);
```

### Step 3: Maintain Response Format

```php
// Ensure response format doesn't change
return response()->json([
    'success' => true,
    'data' => $attendance,
    'message' => 'Attendance status updated successfully'
]);
```

---

## Performance Considerations

### Database Queries

```
Per status change:
1. SELECT (find attendance)
2. UPDATE (change status)
3. INSERT (audit log)
4. SELECT/INSERT (find or create summary)
5. UPDATE (increment/decrement counters)

Total: ~5 queries (all in transaction)
```

### Optimization Tips

1. **Use eager loading** when changing multiple attendances
   ```php
   $attendances = Attendance::with('student', 'class')->get();
   ```

2. **Batch changes** if possible
   ```php
   // Instead of 100 individual changes
   // Consider bulk update with single audit entry
   ```

3. **Queue event processing** for large datasets
   ```php
   // Dispatch event to queue
   AttendanceStatusChanged::dispatch(...)->onQueue('attendance');
   ```

---

## Monitoring

### Metrics to Track

```php
// Status change frequency
SELECT COUNT(*) 
FROM audit_logs 
WHERE action = 'attendance.status_changed'
AND created_at >= NOW() - INTERVAL '1 day';

// Most changed statuses
SELECT 
    JSON_EXTRACT(new_values, '$.status') as new_status,
    COUNT(*) as count
FROM audit_logs
WHERE action = 'attendance.status_changed'
GROUP BY new_status
ORDER BY count DESC;

// Users making most changes
SELECT 
    user_id,
    COUNT(*) as changes
FROM audit_logs
WHERE action = 'attendance.status_changed'
GROUP BY user_id
ORDER BY changes DESC
LIMIT 10;
```

### Alerts

```yaml
Critical:
  - Status change failure rate > 5%
  - Audit log creation failure
  - Read model sync delay > 10s

Warning:
  - Status change rate > 1000/hour
  - Old attendance changes (> 7 days)
  - Cross-school access attempts
```

---

## Troubleshooting

### Issue: Status not changing

**Check**:
1. Validation errors in logs
2. State machine rules
3. Business rule violations
4. Authorization failures

**Solution**:
```bash
# Check logs
tail -f storage/logs/laravel.log | grep "attendance.status"

# Verify user permissions
php artisan tinker
>>> $user = User::find(123);
>>> $user->can('update', $attendance);
```

### Issue: Audit log missing

**Check**:
1. Transaction rollback
2. AuditLog model issues
3. Database constraints

**Solution**:
```bash
# Check audit logs table
php artisan tinker
>>> AuditLog::where('action', 'attendance.status_changed')->count();

# Verify table structure
php artisan migrate:status
```

### Issue: Read model not updating

**Check**:
1. Event listener registered
2. Queue worker running
3. Event dispatch successful

**Solution**:
```bash
# Check event registration
php artisan event:list | grep AttendanceStatusChanged

# Process queue
php artisan queue:work

# Check read model
php artisan tinker
>>> AttendanceDailySummary::where('attendance_date', today())->get();
```

---

## Next Steps

1. **Implement State Machine** (optional)
   - Define valid transitions
   - Add transition guards
   - Custom transition logic

2. **Add Notifications**
   - Notify parent when status changed
   - Notify teacher on bulk changes
   - Alert on suspicious patterns

3. **Bulk Operations**
   - Change multiple attendances at once
   - Bulk audit logging
   - Optimized read model updates

4. **Reporting**
   - Status change history report
   - Audit trail export
   - Compliance reports

---

**Version**: 1.0.0  
**Last Updated**: 2026-02-09  
**Status**: ✅ Production Ready
