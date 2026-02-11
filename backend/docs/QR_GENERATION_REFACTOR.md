# Refactored: AttendanceService::generateQR()

## Overview

Comprehensive refactoring of the QR generation method with enhanced validation, timezone awareness, time-based restrictions, and detailed audit logging.

---

## 1. Validation Flow

### Step-by-Step Validation Process

```
1. Teacher Role Validation
   ↓
2. Fetch Schedule by session_id
   ↓
3. Validate school_id Match
   ↓
4. Validate teacher_id Match
   ↓
5. Validate is_active = true
   ↓
6. Validate day_of_week (timezone-aware)
   ↓
7. Validate Time Window
   ↓
8. Generate QR Token
   ↓
9. Store in Redis
   ↓
10. Log Success
```

---

## 2. Schedule Fetching

### Before (OLD)
```php
$schedule = Schedule::where('id', $sessionId)
    ->where('school_id', $teacher->school_id)
    ->first();
```

**Issues:**
- ❌ Fetches all columns (SELECT *)
- ❌ No eager loading
- ❌ Missing is_active check
- ❌ Combines fetch + validation

### After (NEW)
```php
$schedule = Schedule::select([
        'id',
        'school_id',
        'teacher_id',
        'class_id',
        'subject_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
        'schedule_type'
    ])
    ->with(['class:id,name', 'subject:id,name'])
    ->find($sessionId);
```

**Improvements:**
- ✅ Specific columns only
- ✅ Eager loads class and subject
- ✅ Fetches is_active for validation
- ✅ Separation of concerns

---

## 3. Validation Logic

### A. School ID Match
```php
if ($schedule->school_id !== $teacher->school_id) {
    Log::channel('audit')->warning('qr_generation_failed_school_mismatch', [
        'session_id' => $sessionId,
        'teacher_id' => $teacher->id,
        'teacher_school_id' => $teacher->school_id,
        'schedule_school_id' => $schedule->school_id,
    ]);
    
    throw new AttendanceException('Sesi tidak ditemukan atau tidak valid.');
}
```

**Purpose:** Multi-tenant security - prevents cross-school access

### B. Teacher ID Match
```php
if ($schedule->teacher_id !== $teacher->id) {
    Log::channel('audit')->warning('qr_generation_failed_teacher_mismatch', [
        'session_id' => $sessionId,
        'teacher_id' => $teacher->id,
        'schedule_teacher_id' => $schedule->teacher_id,
        'school_id' => $teacher->school_id,
    ]);
    
    throw new AttendanceException('Anda tidak memiliki akses untuk membuat QR pada sesi ini.');
}
```

**Purpose:** Authorization - only session owner can generate QR

### C. Is Active Check
```php
if (!$schedule->is_active) {
    Log::channel('audit')->warning('qr_generation_failed_inactive_schedule', [
        'session_id' => $sessionId,
        'teacher_id' => $teacher->id,
        'school_id' => $teacher->school_id,
        'is_active' => $schedule->is_active,
    ]);
    
    throw new AttendanceException('Sesi ini tidak aktif dan tidak dapat digunakan untuk absensi.');
}
```

**Purpose:** Prevents QR generation for disabled/archived schedules

---

## 4. Time-Based Validation (NEW FEATURE)

### Timezone-Aware Time Calculation

```php
// Get school timezone (not server timezone!)
$schoolTimezone = $teacher->school->timezone ?? 'Asia/Jakarta';
$now = \Carbon\Carbon::now($schoolTimezone);

$currentTime = $now->format('H:i:s');
$startTime = $schedule->start_time;
$endTime = $schedule->end_time;
```

### Time Window Rules

```php
// Parse times for comparison
$currentTimeCarbon = Carbon::createFromFormat('H:i:s', $currentTime, $schoolTimezone);
$startTimeCarbon = Carbon::createFromFormat('H:i:s', $startTime, $schoolTimezone);
$endTimeCarbon = Carbon::createFromFormat('H:i:s', $endTime, $schoolTimezone);

// Allow QR generation 10 minutes before start time
$earliestAllowedTime = $startTimeCarbon->copy()->subMinutes(10);

// Allow QR generation until end time + 5 minutes tolerance
$latestAllowedTime = $endTimeCarbon->copy()->addMinutes(5);
```

### Visual Timeline

```
Schedule: 08:00 - 09:30

Timeline:
├─────────┼─────────┼─────────┼─────────┼─────────┤
07:50    08:00    08:30    09:00    09:30    09:35
  ↑        ↑                            ↑        ↑
  │        │                            │        │
Earliest  Start                        End    Latest
Allowed   Time                        Time   Allowed

✅ QR can be generated: 07:50 - 09:35
❌ Too early: Before 07:50
❌ Too late: After 09:35
```

### Validation: Too Early

```php
if ($currentTimeCarbon->lt($earliestAllowedTime)) {
    Log::channel('audit')->warning('qr_generation_failed_too_early', [
        'session_id' => $sessionId,
        'teacher_id' => $teacher->id,
        'school_id' => $teacher->school_id,
        'current_time' => $currentTime,
        'start_time' => $startTime,
        'earliest_allowed' => $earliestAllowedTime->format('H:i:s'),
        'timezone' => $schoolTimezone,
    ]);
    
    throw new AttendanceException(
        "QR absensi hanya dapat dibuat mulai 10 menit sebelum sesi dimulai. " .
        "Sesi dimulai pukul {$startTime}, QR dapat dibuat mulai pukul " . 
        $earliestAllowedTime->format('H:i') . "."
    );
}
```

**Example Error Message:**
```
QR absensi hanya dapat dibuat mulai 10 menit sebelum sesi dimulai. 
Sesi dimulai pukul 08:00:00, QR dapat dibuat mulai pukul 07:50.
```

### Validation: Too Late

```php
if ($currentTimeCarbon->gt($latestAllowedTime)) {
    Log::channel('audit')->warning('qr_generation_failed_too_late', [
        'session_id' => $sessionId,
        'teacher_id' => $teacher->id,
        'school_id' => $teacher->school_id,
        'current_time' => $currentTime,
        'end_time' => $endTime,
        'latest_allowed' => $latestAllowedTime->format('H:i:s'),
        'timezone' => $schoolTimezone,
    ]);
    
    throw new AttendanceException(
        "QR absensi tidak dapat dibuat setelah sesi berakhir. " .
        "Sesi berakhir pukul {$endTime}."
    );
}
```

**Example Error Message:**
```
QR absensi tidak dapat dibuat setelah sesi berakhir. 
Sesi berakhir pukul 09:30:00.
```

---

## 5. Audit Logging

### Failed Attempts (Logged as WARNING)

All validation failures are logged with detailed context:

1. **Schedule Not Found**
   ```php
   Log::channel('audit')->warning('qr_generation_failed_schedule_not_found', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'school_id' => $teacher->school_id,
   ]);
   ```

2. **School Mismatch**
   ```php
   Log::channel('audit')->warning('qr_generation_failed_school_mismatch', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'teacher_school_id' => $teacher->school_id,
       'schedule_school_id' => $schedule->school_id,
   ]);
   ```

3. **Teacher Mismatch**
   ```php
   Log::channel('audit')->warning('qr_generation_failed_teacher_mismatch', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'schedule_teacher_id' => $schedule->teacher_id,
       'school_id' => $teacher->school_id,
   ]);
   ```

4. **Inactive Schedule**
   ```php
   Log::channel('audit')->warning('qr_generation_failed_inactive_schedule', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'school_id' => $teacher->school_id,
       'is_active' => $schedule->is_active,
   ]);
   ```

5. **Wrong Day**
   ```php
   Log::channel('audit')->warning('qr_generation_failed_wrong_day', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'school_id' => $teacher->school_id,
       'schedule_day' => $schedule->day_of_week,
       'current_day' => $todayDayOfWeek,
       'timezone' => $schoolTimezone,
   ]);
   ```

6. **Too Early** ⭐ NEW
   ```php
   Log::channel('audit')->warning('qr_generation_failed_too_early', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'school_id' => $teacher->school_id,
       'current_time' => $currentTime,
       'start_time' => $startTime,
       'earliest_allowed' => $earliestAllowedTime->format('H:i:s'),
       'timezone' => $schoolTimezone,
   ]);
   ```

7. **Too Late** ⭐ NEW
   ```php
   Log::channel('audit')->warning('qr_generation_failed_too_late', [
       'session_id' => $sessionId,
       'teacher_id' => $teacher->id,
       'school_id' => $teacher->school_id,
       'current_time' => $currentTime,
       'end_time' => $endTime,
       'latest_allowed' => $latestAllowedTime->format('H:i:s'),
       'timezone' => $schoolTimezone,
   ]);
   ```

### Successful Generation (Logged as INFO)

```php
Log::channel('audit')->info('qr_generated', [
    'teacher_id' => $teacher->id,
    'session_id' => $sessionId,
    'school_id' => $teacher->school_id,
    'class' => $schedule->class->name ?? null,
    'subject' => $schedule->subject->name ?? null,
    'token' => $sessionToken,
    'current_time' => $currentTime,
    'start_time' => $startTime,
    'end_time' => $endTime,
    'expires_at' => $expiresAt->toIso8601String(),
    'timezone' => $schoolTimezone,
]);
```

---

## 6. Enhanced Response

### Before (OLD)
```json
{
  "data": { ... },
  "signature": "...",
  "expires_at": "2026-02-07T23:37:08+08:00",
  "valid_for_seconds": 60
}
```

### After (NEW)
```json
{
  "data": { ... },
  "signature": "...",
  "expires_at": "2026-02-07T23:37:08+08:00",
  "valid_for_seconds": 60,
  "session_info": {
    "class": "X RPL 1",
    "subject": "Matematika",
    "start_time": "08:00:00",
    "end_time": "09:30:00",
    "current_time": "08:15:23"
  }
}
```

**Benefits:**
- ✅ Frontend can display session context
- ✅ Teacher can verify correct session
- ✅ Useful for debugging

---

## 7. Redis Storage Enhancement

### Before (OLD)
```php
Redis::setex($redisKey, 60, json_encode([
    'session_id' => $sessionId,
    'teacher_id' => $teacher->id,
    'school_id' => $teacher->school_id,
    'created_at' => $now->toIso8601String(),
]));
```

### After (NEW)
```php
Redis::setex($redisKey, 60, json_encode([
    'session_id' => $sessionId,
    'teacher_id' => $teacher->id,
    'school_id' => $teacher->school_id,
    'class_id' => $schedule->class_id,      // NEW
    'subject_id' => $schedule->subject_id,  // NEW
    'created_at' => $now->toIso8601String(),
    'timezone' => $schoolTimezone,          // NEW
]));
```

**Benefits:**
- ✅ Can validate scan against class/subject
- ✅ Timezone info for scan validation
- ✅ More context for debugging

---

## 8. Error Response Examples

### 403 Forbidden - Too Early
```json
{
  "status": "fail",
  "message": "QR absensi hanya dapat dibuat mulai 10 menit sebelum sesi dimulai. Sesi dimulai pukul 08:00:00, QR dapat dibuat mulai pukul 07:50."
}
```

### 403 Forbidden - Too Late
```json
{
  "status": "fail",
  "message": "QR absensi tidak dapat dibuat setelah sesi berakhir. Sesi berakhir pukul 09:30:00."
}
```

### 403 Forbidden - Wrong Day
```json
{
  "status": "fail",
  "message": "Sesi ini dijadwalkan untuk hari Senin, bukan Jumat."
}
```

### 403 Forbidden - Inactive Schedule
```json
{
  "status": "fail",
  "message": "Sesi ini tidak aktif dan tidak dapat digunakan untuk absensi."
}
```

### 403 Forbidden - Unauthorized
```json
{
  "status": "fail",
  "message": "Anda tidak memiliki akses untuk membuat QR pada sesi ini."
}
```

---

## 9. Security Improvements

| Validation | Before | After | Impact |
|------------|--------|-------|--------|
| **School Isolation** | ✅ | ✅ | Multi-tenant security |
| **Teacher Authorization** | ✅ | ✅ | Ownership verification |
| **Active Status** | ❌ | ✅ | Prevents inactive schedule use |
| **Day Validation** | ⚠️ (String) | ✅ (Integer) | Timezone-aware |
| **Time Window** | ❌ | ✅ | Prevents early/late generation |
| **Audit Logging** | ⚠️ (Basic) | ✅ (Detailed) | Full traceability |

---

## 10. Testing Scenarios

### Test Case 1: Valid QR Generation
```php
// Schedule: Monday 08:00-09:30
// Current: Monday 08:15
// Expected: SUCCESS
```

### Test Case 2: Too Early
```php
// Schedule: Monday 08:00-09:30
// Current: Monday 07:45 (before 07:50)
// Expected: 403 "QR absensi hanya dapat dibuat mulai 10 menit sebelum..."
```

### Test Case 3: Too Late
```php
// Schedule: Monday 08:00-09:30
// Current: Monday 09:40 (after 09:35)
// Expected: 403 "QR absensi tidak dapat dibuat setelah sesi berakhir..."
```

### Test Case 4: Wrong Day
```php
// Schedule: Monday 08:00-09:30
// Current: Tuesday 08:15
// Expected: 403 "Sesi ini dijadwalkan untuk hari Senin, bukan Selasa."
```

### Test Case 5: Inactive Schedule
```php
// Schedule: is_active = false
// Expected: 403 "Sesi ini tidak aktif..."
```

### Test Case 6: Timezone Edge Case
```php
// Server: UTC 23:55 (Friday)
// School: Asia/Jakarta 06:55 (Saturday)
// Schedule: Saturday 07:00-08:30
// Expected: SUCCESS (uses school timezone, not server)
```

---

## 11. Summary of Changes

✅ **Fetch schedule with specific columns** - Performance optimization  
✅ **Validate school_id match** - Multi-tenant security  
✅ **Validate teacher_id match** - Authorization  
✅ **Validate is_active = true** - Prevents inactive schedule use  
✅ **Timezone-aware day validation** - Correct day calculation  
✅ **Time window validation** - 10 min before to 5 min after  
✅ **Detailed audit logging** - All failures logged with context  
✅ **Clear error messages** - User-friendly feedback  
✅ **Enhanced response** - Includes session info  
✅ **Enhanced Redis storage** - More context for validation  

**Result:** Robust, secure, and user-friendly QR generation with comprehensive validation and audit trail.
