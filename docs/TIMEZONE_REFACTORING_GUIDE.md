# Timezone Refactoring Guide

## Overview
This guide provides a comprehensive plan to refactor the entire project to use timezone-aware datetime operations, ensuring accurate attendance tracking across different school timezones.

## Problem Statement

### Current Issues
1. ❌ `now()` and `Carbon::now()` used without timezone context
2. ❌ Time comparisons may be incorrect for schools in different timezones
3. ❌ Schedule validation doesn't account for timezone differences
4. ❌ Inconsistent use of inclusive/exclusive time ranges
5. ❌ Date boundaries may be wrong (e.g., "today" in UTC vs school timezone)

### Impact
- **Attendance records** may have wrong dates/times
- **QR code expiration** may be calculated incorrectly
- **Schedule validation** may fail for schools in different timezones
- **Reports** may show data from wrong date ranges

## Solution

### New Helper Functions

All helpers are defined in `app/Helpers/TimezoneHelpers.php`:

```php
// Get current time in school's timezone
school_now($school)

// Get today's date in school's timezone
school_today($school)

// Create time from string in school's timezone
school_time_from_string($timeString, $school, $date = null)

// Parse datetime in school's timezone
school_parse_datetime($datetime, $school)

// Check if time is between range (inclusive)
is_time_between_inclusive($current, $start, $end)
```

## Refactoring Rules

### Rule 1: Replace `now()` with `school_now($school)`

**BEFORE**:
```php
$now = now();
$today = now()->toDateString();
```

**AFTER**:
```php
$now = school_now($school);
$today = school_today($school);
```

### Rule 2: Replace `Carbon::now()` with `school_now($school)`

**BEFORE**:
```php
$now = Carbon::now();
$endDate = Carbon::now()->endOfDay();
```

**AFTER**:
```php
$now = school_now($school);
$endDate = school_now($school)->endOfDay();
```

### Rule 3: Use `school_time_from_string()` for Schedule Times

**BEFORE**:
```php
$startTime = Carbon::parse("{$today} {$schedule->start_time}");
$endTime = Carbon::createFromFormat('H:i:s', $schedule->end_time);
```

**AFTER**:
```php
$startTime = school_time_from_string($schedule->start_time, $school);
$endTime = school_time_from_string($schedule->end_time, $school);
```

### Rule 4: Use Inclusive Comparisons

**BEFORE**:
```php
if ($now->between($start, $end)) { // Not inclusive
if ($now->lessThan($start)) {
if ($now->greaterThan($end)) {
```

**AFTER**:
```php
if (is_time_between_inclusive($now, $start, $end)) { // Inclusive
if ($now->lessThanOrEqualTo($start)) {
if ($now->greaterThanOrEqualTo($end)) {
```

### Rule 5: Keep UTC for Security Tokens

**EXCEPTION**: For security tokens, authentication, and API tokens, continue using UTC:

```php
// For tokens - use UTC explicitly
$nowUtc = now('UTC');
$expiresAt = now('UTC')->addMinutes(60);
```

## Files to Refactor

### Priority 1: Critical Attendance Files (MUST FIX)

#### 1. `app/Services/AttendanceCheckInService.php`
**Lines to change**: ~50 occurrences
**Key methods**:
- `checkIn()` - Line 77
- `validateTimeWindow()` - Line 668
- `atomicCheckIn()` - Line 709
- `validateQrTimestamp()` - Line 443

**Changes**:
```php
// BEFORE
$serverNow = now();
$serverDate = $serverNow->toDateString();

// AFTER
$serverNow = school_now($student->school);
$serverDate = school_today($student->school);
```

#### 2. `app/Services/AttendanceService.php`
**Lines to change**: ~30 occurrences
**Key methods**:
- `scan()` - Line 39
- `generateQR()` - Line 81

**Changes**:
```php
// BEFORE
$now = Carbon::now($schoolTimezone);

// AFTER
$now = school_now($teacher->school);
```

#### 3. `app/Http/Controllers/Api/V1/AttendanceController.php`
**Lines to change**: ~10 occurrences
**Key methods**:
- `scan()` - Line 45
- `checkIn()` - Line 120

### Priority 2: Schedule & Validation Files

#### 4. `app/Services/TeacherScheduleService.php`
**Lines to change**: ~15 occurrences
**Key methods**:
- `getWeekStart()` - Line 198
- `getWeekRange()` - Line 206

#### 5. `app/Services/ScheduleService.php`
**Lines to change**: ~20 occurrences

#### 6. `app/Http/Controllers/Api/V1/ScheduleController.php`
**Lines to change**: ~10 occurrences

### Priority 3: Reporting & Analytics Files

#### 7. `app/Services/StudentRiskAnalysisService.php`
**Lines to change**: ~25 occurrences
**Key methods**:
- `getOverview()` - Line 39
- `getHighRiskStudents()` - Line 140
- `getDetailedAnalysis()` - Line 360

#### 8. `app/Services/TeacherSecurityReportService.php`
**Lines to change**: ~10 occurrences

#### 9. `app/Services/TeacherHeatmapService.php`
**Lines to change**: ~5 occurrences

### Priority 4: Notification & Card Services

#### 10. `app/Services/StudentNotificationService.php`
**Lines to change**: ~5 occurrences

#### 11. `app/Services/StudentCardService.php`
**Lines to change**: ~15 occurrences

### Priority 5: QR & Security Services

#### 12. `app/Services/StudentQrService.php`
**Lines to change**: ~10 occurrences
**Note**: Keep UTC for token timestamps

#### 13. `app/Services/QRSignatureService.php`
**Lines to change**: ~5 occurrences

### Priority 6: Token & Auth Services (Keep UTC)

#### 14. `app/Services/TokenHardeningService.php`
**Lines to change**: 0 (Keep UTC for tokens)
**Reason**: Security tokens should use UTC

#### 15. `app/Services/TenantScopeBypassAuditService.php`
**Lines to change**: 0 (Keep UTC for audit logs)
**Reason**: Audit logs should use UTC

### Priority 7: Testing Services

#### 16. `app/Services/Testing/AttendanceStressTestService.php`
**Lines to change**: ~10 occurrences

## Complete File List

### Files Requiring Changes (Total: ~50 files)

```
app/Services/
├── AttendanceCheckInService.php          ⚠️ CRITICAL (50 changes)
├── AttendanceService.php                 ⚠️ CRITICAL (30 changes)
├── AttendanceLockService.php             (5 changes)
├── AttendanceIdempotencyService.php      (5 changes)
├── ScheduleService.php                   (20 changes)
├── TeacherScheduleService.php            (15 changes)
├── StudentRiskAnalysisService.php        (25 changes)
├── TeacherSecurityReportService.php      (10 changes)
├── TeacherHeatmapService.php             (5 changes)
├── StudentNotificationService.php        (5 changes)
├── StudentCardService.php                (15 changes)
├── StudentQrService.php                  (10 changes - keep UTC for tokens)
├── QRSignatureService.php                (5 changes)
├── GamificationService.php               (10 changes)
├── SecurityAlertService.php              (5 changes)
└── Testing/
    └── AttendanceStressTestService.php   (10 changes)

app/Http/Controllers/Api/V1/
├── AttendanceController.php              ⚠️ CRITICAL (10 changes)
├── ScheduleController.php                (10 changes)
├── TeacherController.php                 (5 changes)
├── StudentController.php                 (5 changes)
└── ReportController.php                  (5 changes)

app/Models/
├── Attendance.php                        (5 changes in scopes)
├── Schedule.php                          (5 changes in scopes)
└── School.php                            (ensure timezone field exists)

app/Console/Commands/
├── BackupDatabase.php                    (keep UTC)
├── CleanupExpiredTokens.php              (keep UTC)
└── GenerateAttendanceReport.php          (10 changes)
```

## Migration Plan

### Phase 1: Setup (Day 1)

1. ✅ Create `app/Helpers/TimezoneHelpers.php`
2. ✅ Register helpers in `composer.json`:
   ```json
   "autoload": {
       "files": [
           "app/Helpers/TimezoneHelpers.php"
       ]
   }
   ```
3. Run `composer dump-autoload`
4. Verify helpers are loaded: `php artisan tinker` → `school_now(null)`

### Phase 2: Critical Files (Day 2-3)

1. Refactor `AttendanceCheckInService.php`
2. Refactor `AttendanceService.php`
3. Refactor `AttendanceController.php`
4. Test attendance flow end-to-end

### Phase 3: Schedule Files (Day 4)

1. Refactor `ScheduleService.php`
2. Refactor `TeacherScheduleService.php`
3. Refactor `ScheduleController.php`
4. Test schedule validation

### Phase 4: Reporting Files (Day 5)

1. Refactor `StudentRiskAnalysisService.php`
2. Refactor `TeacherSecurityReportService.php`
3. Refactor `TeacherHeatmapService.php`
4. Test reports with different timezones

### Phase 5: Remaining Files (Day 6-7)

1. Refactor notification services
2. Refactor card services
3. Refactor remaining controllers
4. Full regression testing

## Testing Strategy

### Unit Tests

```php
public function test_school_now_respects_timezone()
{
    $school = School::factory()->create(['timezone' => 'Asia/Tokyo']);
    
    $now = school_now($school);
    
    $this->assertEquals('Asia/Tokyo', $now->timezone->getName());
}

public function test_school_time_from_string_creates_correct_time()
{
    $school = School::factory()->create(['timezone' => 'America/New_York']);
    
    $time = school_time_from_string('08:00:00', $school);
    
    $this->assertEquals('08:00:00', $time->format('H:i:s'));
    $this->assertEquals('America/New_York', $time->timezone->getName());
}

public function test_time_validation_is_inclusive()
{
    $school = School::factory()->create(['timezone' => 'Asia/Jakarta']);
    
    $start = school_time_from_string('08:00:00', $school);
    $end = school_time_from_string('10:00:00', $school);
    $exact = school_time_from_string('08:00:00', $school);
    
    $this->assertTrue(is_time_between_inclusive($exact, $start, $end));
}
```

### Integration Tests

```php
public function test_attendance_scan_uses_school_timezone()
{
    $school = School::factory()->create(['timezone' => 'Asia/Singapore']);
    $student = User::factory()->student()->create(['school_id' => $school->id]);
    $schedule = Schedule::factory()->create([
        'school_id' => $school->id,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);
    
    // Mock time to 08:30 Singapore time
    Carbon::setTestNow(Carbon::parse('2026-02-09 08:30:00', 'Asia/Singapore'));
    
    $attendance = $this->attendanceService->scan($student, [
        'schedule_id' => $schedule->id,
        'qr_token' => '...',
    ], request());
    
    $this->assertEquals('2026-02-09', $attendance->attendance_date);
    $this->assertEquals('present', $attendance->status);
}
```

## Verification Checklist

After refactoring each file:

- [ ] All `now()` calls replaced with `school_now($school)`
- [ ] All `Carbon::now()` calls replaced with `school_now($school)`
- [ ] Schedule time parsing uses `school_time_from_string()`
- [ ] Time comparisons use inclusive methods
- [ ] Tests pass for multiple timezones
- [ ] No timezone-related bugs in staging

## Common Pitfalls

### ❌ Pitfall 1: Forgetting to Pass School Object

```php
// WRONG
$now = school_now(); // Falls back to app timezone

// CORRECT
$now = school_now($student->school);
```

### ❌ Pitfall 2: Using Non-Inclusive Comparisons

```php
// WRONG
if ($now->between($start, $end)) { // Exclusive

// CORRECT
if (is_time_between_inclusive($now, $start, $end)) { // Inclusive
```

### ❌ Pitfall 3: Mixing Timezones

```php
// WRONG
$start = school_time_from_string('08:00:00', $school);
$now = now(); // Different timezone!

// CORRECT
$start = school_time_from_string('08:00:00', $school);
$now = school_now($school); // Same timezone
```

### ❌ Pitfall 4: Using School Timezone for Tokens

```php
// WRONG
$expiresAt = school_now($school)->addMinutes(60); // For auth tokens

// CORRECT
$expiresAt = now('UTC')->addMinutes(60); // Tokens should use UTC
```

## Rollback Plan

If issues occur:

1. **Identify problematic file** from error logs
2. **Revert specific file** using git:
   ```bash
   git checkout HEAD -- app/Services/AttendanceService.php
   ```
3. **Test in isolation** with different timezones
4. **Fix and redeploy** specific file

## Monitoring

After deployment, monitor:

1. **Attendance records** - Check `attendance_date` matches school's local date
2. **QR expiration** - Verify QR codes expire at correct time
3. **Schedule validation** - Ensure time windows work correctly
4. **Error logs** - Watch for timezone-related errors

## Conclusion

This refactoring will:
- ✅ Ensure accurate attendance tracking across timezones
- ✅ Fix schedule validation for international schools
- ✅ Improve date/time consistency
- ✅ Reduce timezone-related bugs
- ✅ Make codebase more maintainable

**Estimated Effort**: 7 days  
**Risk Level**: Medium (requires thorough testing)  
**Impact**: High (affects core attendance functionality)
