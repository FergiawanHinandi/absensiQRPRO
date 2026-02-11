# Timezone Refactoring Plan

**Date**: 2026-02-09  
**Goal**: Replace all `now()` and `Carbon::now()` with timezone-aware helpers  
**Status**: IN PROGRESS

---

## ✅ COMPLETED

### 1. Helper Functions Created
- ✅ `app/helpers.php` created with:
  - `school_now($school)` - Get current time in school timezone
  - `school_today($school)` - Get today's date in school timezone
  - `school_parse($time, $school)` - Parse time in school timezone
  - `school_create_from_format($format, $time, $school)` - Create from format
  - `school_timezone($school)` - Get timezone string

### 2. Autoload Configuration
- ✅ Added `app/helpers.php` to `composer.json` autoload files
- ✅ Ran `composer dump-autoload`

---

## 📋 FILES TO REFACTOR (Priority Order)

### 🔴 P0 - CRITICAL (Core Business Logic)

#### 1. ✅ `app/Services/AttendanceService.php` 
**Status**: ALREADY CORRECT (uses timezone properly)
- Line 151-152: Already uses `Carbon::now($schoolTimezone)`
- Line 182-184: Already uses timezone in `createFromFormat`
- **Action**: No changes needed ✅

#### 2. `app/Services/AttendanceCheckInService.php`
**Occurrences**: Need to check
**Priority**: P0 (handles check-in logic)
**Action**: Review and refactor

#### 3. `app/Traits/HasAttendanceStateMachine.php`
**Occurrences**: 7 instances of `now()`
- Line 155: `'check_in_time' => now()`
- Line 188: `'check_out_time' => now()`
- Line 209: `$this->correction_requested_at = now()`
- Line 229: `$this->approved_at = now()`
- Line 246: `$this->rejected_at = now()`
- Line 307: `'created_at' => now()`
- Line 319: `'created_at' => now()`
**Priority**: P0 (state machine critical)
**Action**: Replace with `school_now($this->schedule->school)`

#### 4. `app/Services/StudentQrService.php`
**Occurrences**: 4 instances
- Line 64: `$payload['exp'] < now()->timestamp`
- Line 70: `$payload['iat'] < (now()->timestamp - $maxAge)`
- Line 220: `'timestamp' => now()->toIso8601String()`
- Line 252: `'iat' => now()->timestamp`
**Priority**: P0 (QR validation)
**Action**: Replace with `school_now($student->school)`

---

### 🟡 P1 - HIGH (Services)

#### 5. `app/Services/StudentRiskAnalysisService.php`
**Occurrences**: 12+ instances
**Priority**: P1 (analytics, not real-time critical)
**Action**: Refactor with `school_now($school)`

#### 6. `app/Services/TeacherScheduleService.php`
**Occurrences**: 4 instances
- Line 198: `Carbon::now()->startOfWeek()`
- Line 206: `Carbon::now()->startOfWeek()->addDays($weekOffset)`
- Line 212: `Carbon::now()->startOfWeek()`
**Priority**: P1 (schedule display)
**Action**: Replace with `school_now($teacher->school)`

#### 7. `app/Services/StudentNotificationService.php`
**Occurrences**: 2 instances
- Line 75: `Carbon::now()->startOfMonth()`
- Line 76: `Carbon::now()`
**Priority**: P1 (notifications)
**Action**: Replace with `school_now($student->school)`

#### 8. `app/Services/StudentCardService.php`
**Occurrences**: 3 instances
- Line 16: `'revoked_at' => now()`
- Line 27: `'issued_at' => now()`
- Line 36: `'timestamp' => now()`
**Priority**: P1 (card management)
**Action**: Replace with `school_now($student->school)`

---

### 🟢 P2 - MEDIUM (Less Critical Services)

#### 9. `app/Services/TokenHardeningService.php`
**Occurrences**: 7 instances
**Priority**: P2 (token management, uses UTC is fine)
**Action**: Keep as-is OR use `now()` (UTC is standard for tokens)

#### 10. `app/Services/TeacherSecurityReportService.php`
**Occurrences**: 5 instances
**Priority**: P2 (reports)
**Action**: Replace with `school_now($teacher->school)`

#### 11. `app/Services/TeacherHeatmapService.php`
**Occurrences**: 2 instances
**Priority**: P2 (analytics)
**Action**: Replace with `school_now($teacher->school)`

#### 12. `app/Services/TenantScopeBypassAuditService.php`
**Occurrences**: 2 instances
**Priority**: P2 (audit logs, UTC is fine)
**Action**: Keep as-is (audit logs should use UTC)

---

## 🎯 REFACTORING STRATEGY

### Phase 1: Critical Business Logic (TODAY)
1. ✅ Create helper functions
2. ✅ Register in composer
3. ⏳ Refactor `HasAttendanceStateMachine.php`
4. ⏳ Refactor `StudentQrService.php`
5. ⏳ Refactor `AttendanceCheckInService.php`

### Phase 2: Services (TOMORROW)
6. Refactor `StudentRiskAnalysisService.php`
7. Refactor `TeacherScheduleService.php`
8. Refactor `StudentNotificationService.php`
9. Refactor `StudentCardService.php`

### Phase 3: Reports & Analytics (DAY 3)
10. Refactor `TeacherSecurityReportService.php`
11. Refactor `TeacherHeatmapService.php`
12. Review and test all changes

---

## 🧪 TESTING CHECKLIST

After each refactoring:
- [ ] Run unit tests: `php artisan test`
- [ ] Test QR generation in different timezones
- [ ] Test attendance check-in
- [ ] Test schedule validation
- [ ] Verify timestamps in database
- [ ] Check audit logs

---

## 🚨 IMPORTANT NOTES

### When to Use `school_now()`
✅ **USE** for:
- Attendance timestamps
- Schedule validation
- Student/teacher activity
- Reports scoped to school
- Any business logic tied to school time

### When to Keep `now()` (UTC)
❌ **DON'T USE** for:
- API tokens (should be UTC)
- System audit logs (should be UTC)
- Cache keys (should be UTC)
- Database migrations (should be UTC)

### Migration Path
```php
// BEFORE
$now = now();
$checkInTime = now();

// AFTER
$now = school_now($school);
$checkInTime = school_now($attendance->schedule->school);

// OR (if school is in auth context)
$now = school_now(); // Auto-uses auth()->user()->school
```

---

## 📝 CODE EXAMPLES

### Example 1: Attendance State Machine
```php
// BEFORE
public function checkIn(): void
{
    $this->update([
        'check_in_time' => now(),
        'status' => 'present',
    ]);
}

// AFTER
public function checkIn(): void
{
    $this->update([
        'check_in_time' => school_now($this->schedule->school),
        'status' => 'present',
    ]);
}
```

### Example 2: Schedule Validation
```php
// BEFORE
$now = Carbon::now();
$scheduleTime = Carbon::createFromFormat(
    'Y-m-d H:i:s',
    $now->format('Y-m-d') . ' ' . $schedule->start_time
);

// AFTER
$now = school_now($schedule->school);
$scheduleTime = school_create_from_format(
    'Y-m-d H:i:s',
    $now->format('Y-m-d') . ' ' . $schedule->start_time,
    $schedule->school
);
```

### Example 3: QR Token Validation
```php
// BEFORE
if ($payload['exp'] < now()->timestamp) {
    throw new Exception('Token expired');
}

// AFTER
if ($payload['exp'] < school_now($student->school)->timestamp) {
    throw new Exception('Token expired');
}
```

---

## 🎯 SUCCESS CRITERIA

- [ ] All P0 files refactored
- [ ] All P1 files refactored
- [ ] Tests passing
- [ ] No timezone-related bugs in production
- [ ] Documentation updated

---

**Next Action**: Start refactoring `HasAttendanceStateMachine.php`
