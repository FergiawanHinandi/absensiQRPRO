# Timezone Best Practices Guide

**Version**: 1.0  
**Last Updated**: March 4, 2026  
**Spec Reference**: SaaS Hardening 30-Day Roadmap - Week 1 Day 1

---

## Table of Contents

1. [Overview](#overview)
2. [Core Principles](#core-principles)
3. [The TimezoneHelper Utility](#the-timezonehelper-utility)
4. [Common Patterns](#common-patterns)
5. [Anti-Patterns to Avoid](#anti-patterns-to-avoid)
6. [Database Queries](#database-queries)
7. [Queue Jobs](#queue-jobs)
8. [API Responses](#api-responses)
9. [Testing](#testing)
10. [Migration Guide](#migration-guide)
11. [Troubleshooting](#troubleshooting)

---

## Overview

AbsensiQR Pro is a multi-tenant SaaS application serving schools across Indonesia's three time zones (WIB, WITA, WIT). Consistent timezone handling is critical to prevent:

- **Date mismatch bugs**: Attendance recorded on wrong date
- **Report inaccuracies**: Statistics calculated with wrong timezone
- **User confusion**: Times displayed in wrong timezone
- **Data integrity issues**: Cross-timezone data corruption

This guide establishes best practices for timezone-aware development.

---

## Core Principles

### 1. Always Use School Timezone

Every school has its own timezone setting. Always use the school's timezone for date/time operations:

```php
// ✅ CORRECT
$now = TimezoneHelper::schoolNow($school);

// ❌ WRONG
$now = Carbon::now(); // Uses server timezone
```

### 2. Never Use Raw PHP Date Functions

Raw PHP functions (`date()`, `time()`, `strtotime()`) use server timezone and ignore school settings:

```php
// ❌ WRONG
$date = date('Y-m-d');
$timestamp = time();
$parsed = strtotime('2026-03-04');

// ✅ CORRECT
$date = TimezoneHelper::schoolNow($school)->toDateString();
$timestamp = TimezoneHelper::schoolNow($school)->timestamp;
$parsed = TimezoneHelper::parse('2026-03-04', $school->timezone);
```

### 3. Store UTC, Display Local

Store all timestamps in UTC in the database, but display them in the school's local timezone:

```php
// Storage (automatic with Laravel timestamps)
$attendance->created_at; // Stored as UTC in database

// Display
$localTime = TimezoneHelper::convertToSchool($attendance->created_at, $school);
```

### 4. Be Explicit About Timezone

When timezone matters, always be explicit. Don't rely on defaults:

```php
// ❌ AMBIGUOUS
$now = Carbon::now();

// ✅ EXPLICIT
$now = TimezoneHelper::schoolNow($school);
```

---

## The TimezoneHelper Utility

The `TimezoneHelper` class (`app/Helpers/TimezoneHelper.php`) provides all timezone operations:

### Basic Methods

```php
use App\Helpers\TimezoneHelper;
use App\Models\School;

// Get current time in school timezone
$school = School::find($schoolId);
$now = TimezoneHelper::schoolNow($school);

// Get current time in specific timezone
$now = TimezoneHelper::now('Asia/Makassar');

// Parse date string in school timezone
$date = TimezoneHelper::parse('2026-03-04', $school->timezone);

// Get today's date in school timezone
$today = TimezoneHelper::schoolToday($school);

// Convert existing Carbon instance to school timezone
$localTime = TimezoneHelper::convertToSchool($utcTime, $school);
```

### When to Use Each Method

| Method | Use Case | Example |
|--------|----------|---------|
| `schoolNow($school)` | Current time for school operations | Recording attendance |
| `now($timezone)` | Current time in specific timezone | System operations |
| `parse($date, $timezone)` | Parse user input dates | Form submissions |
| `schoolToday($school)` | Today's date for queries | Daily reports |
| `convertToSchool($date, $school)` | Display UTC times locally | Showing timestamps |

---

## Common Patterns

### Pattern 1: Recording Attendance

```php
use App\Helpers\TimezoneHelper;
use App\Models\Attendance;

public function recordAttendance(School $school, Student $student)
{
    $now = TimezoneHelper::schoolNow($school);
    
    $attendance = Attendance::create([
        'student_id' => $student->id,
        'school_id' => $school->id,
        'attendance_date' => $now->toDateString(),
        'check_in_time' => $now,
        'status' => 'present',
    ]);
    
    return $attendance;
}
```

### Pattern 2: Daily Report Query

```php
use App\Helpers\TimezoneHelper;
use App\Models\Attendance;

public function getDailyReport(School $school, string $date)
{
    // Parse date in school timezone
    $targetDate = TimezoneHelper::parse($date, $school->timezone);
    
    return Attendance::where('school_id', $school->id)
        ->whereDate('attendance_date', $targetDate->toDateString())
        ->with(['student', 'schedule'])
        ->get();
}
```

### Pattern 3: Date Range Query

```php
use App\Helpers\TimezoneHelper;
use App\Models\Attendance;

public function getAttendanceRange(School $school, string $startDate, string $endDate)
{
    $start = TimezoneHelper::parse($startDate, $school->timezone)->startOfDay();
    $end = TimezoneHelper::parse($endDate, $school->timezone)->endOfDay();
    
    return Attendance::where('school_id', $school->id)
        ->whereBetween('created_at', [$start, $end])
        ->get();
}
```

### Pattern 4: Displaying Timestamps

```php
use App\Helpers\TimezoneHelper;

public function formatAttendanceTime(Attendance $attendance, School $school)
{
    $localTime = TimezoneHelper::convertToSchool(
        $attendance->check_in_time,
        $school
    );
    
    return [
        'check_in_time' => $localTime->format('H:i:s'),
        'check_in_date' => $localTime->format('Y-m-d'),
        'timezone' => $school->timezone,
    ];
}
```

### Pattern 5: Schedule Validation

```php
use App\Helpers\TimezoneHelper;
use App\Models\Schedule;

public function isScheduleActive(Schedule $schedule, School $school): bool
{
    $now = TimezoneHelper::schoolNow($school);
    $currentTime = $now->format('H:i:s');
    
    return $currentTime >= $schedule->start_time 
        && $currentTime <= $schedule->end_time;
}
```

---

## Anti-Patterns to Avoid

### ❌ Anti-Pattern 1: Using date()

```php
// ❌ WRONG - Uses server timezone
$today = date('Y-m-d');

// ✅ CORRECT - Uses school timezone
$today = TimezoneHelper::schoolNow($school)->toDateString();
```

### ❌ Anti-Pattern 2: Using Carbon::now() Without Timezone

```php
// ❌ WRONG - Uses server timezone
$now = Carbon::now();

// ✅ CORRECT - Uses school timezone
$now = TimezoneHelper::schoolNow($school);
```

### ❌ Anti-Pattern 3: Using strtotime()

```php
// ❌ WRONG - Ignores timezone
$timestamp = strtotime($dateString);

// ✅ CORRECT - Respects timezone
$timestamp = TimezoneHelper::parse($dateString, $school->timezone)->timestamp;
```

### ❌ Anti-Pattern 4: Hardcoding Timezones

```php
// ❌ WRONG - Assumes all schools use WIB
$now = Carbon::now('Asia/Jakarta');

// ✅ CORRECT - Uses school's configured timezone
$now = TimezoneHelper::schoolNow($school);
```

### ❌ Anti-Pattern 5: Using today() Without Context

```php
// ❌ WRONG - Uses server timezone
$today = today();

// ✅ CORRECT - Uses school timezone
$today = TimezoneHelper::schoolToday($school);
```

### ❌ Anti-Pattern 6: Comparing Dates Without Timezone

```php
// ❌ WRONG - May compare different timezones
if ($attendance->created_at->isToday()) {
    // ...
}

// ✅ CORRECT - Compare in school timezone
$schoolNow = TimezoneHelper::schoolNow($school);
$attendanceLocal = TimezoneHelper::convertToSchool($attendance->created_at, $school);
if ($attendanceLocal->isSameDay($schoolNow)) {
    // ...
}
```

---

## Database Queries

### whereDate() Queries

Always use timezone-aware dates in `whereDate()` queries:

```php
// ❌ WRONG
Attendance::whereDate('attendance_date', today())->get();

// ✅ CORRECT
$today = TimezoneHelper::schoolToday($school)->toDateString();
Attendance::whereDate('attendance_date', $today)
    ->where('school_id', $school->id)
    ->get();
```

### whereBetween() Queries

Use school timezone for date range boundaries:

```php
// ❌ WRONG
$start = Carbon::parse($startDate)->startOfDay();
$end = Carbon::parse($endDate)->endOfDay();

// ✅ CORRECT
$start = TimezoneHelper::parse($startDate, $school->timezone)->startOfDay();
$end = TimezoneHelper::parse($endDate, $school->timezone)->endOfDay();

Attendance::whereBetween('created_at', [$start, $end])
    ->where('school_id', $school->id)
    ->get();
```

### Aggregation Queries

Group by date in school timezone:

```php
use Illuminate\Support\Facades\DB;

// ✅ CORRECT - Use DB::raw with timezone conversion
$stats = Attendance::where('school_id', $school->id)
    ->select(
        DB::raw("DATE(CONVERT_TZ(created_at, '+00:00', '{$school->timezone}')) as date"),
        DB::raw('COUNT(*) as count')
    )
    ->groupBy('date')
    ->get();
```

---

## Queue Jobs

Queue jobs must maintain timezone context:

### Pattern: Passing School ID

```php
use App\Helpers\TimezoneHelper;
use App\Jobs\TenantAwareJob;
use App\Models\School;

class GenerateDailyReport extends TenantAwareJob
{
    protected $reportDate;
    
    public function __construct(int $schoolId, string $reportDate)
    {
        parent::__construct($schoolId);
        $this->reportDate = $reportDate;
    }
    
    public function handle(): void
    {
        $school = School::find($this->schoolId);
        
        // Use school timezone for date operations
        $date = TimezoneHelper::parse($this->reportDate, $school->timezone);
        
        // Generate report with correct timezone
        $attendances = Attendance::where('school_id', $this->schoolId)
            ->whereDate('attendance_date', $date->toDateString())
            ->get();
        
        // ...
    }
}
```

### Pattern: Scheduling Jobs

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Run daily reports for each school at midnight in their timezone
    $schools = School::all();
    
    foreach ($schools as $school) {
        $schedule->job(new GenerateDailyReport($school->id, 'today'))
            ->dailyAt('00:00')
            ->timezone($school->timezone);
    }
}
```

---

## API Responses

### Pattern: Include Timezone in Response

```php
public function show(School $school, Attendance $attendance)
{
    $localTime = TimezoneHelper::convertToSchool(
        $attendance->check_in_time,
        $school
    );
    
    return response()->json([
        'id' => $attendance->id,
        'student_id' => $attendance->student_id,
        'check_in_time' => $localTime->toIso8601String(),
        'check_in_time_local' => $localTime->format('Y-m-d H:i:s'),
        'timezone' => $school->timezone,
        'timezone_offset' => $localTime->format('P'),
    ]);
}
```

### Pattern: Accept Timezone in Requests

```php
use App\Http\Requests\AttendanceReportRequest;
use App\Helpers\TimezoneHelper;

public function generateReport(AttendanceReportRequest $request, School $school)
{
    $startDate = TimezoneHelper::parse(
        $request->input('start_date'),
        $school->timezone
    );
    
    $endDate = TimezoneHelper::parse(
        $request->input('end_date'),
        $school->timezone
    );
    
    // Generate report...
}
```

---

## Testing

### Unit Tests

Test timezone-aware methods:

```php
use App\Helpers\TimezoneHelper;
use App\Models\School;
use Tests\TestCase;

class TimezoneHelperTest extends TestCase
{
    public function test_school_now_uses_school_timezone()
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Makassar'
        ]);
        
        $now = TimezoneHelper::schoolNow($school);
        
        $this->assertEquals('Asia/Makassar', $now->timezone->getName());
    }
    
    public function test_parse_respects_timezone()
    {
        $date = TimezoneHelper::parse('2026-03-04 10:00:00', 'Asia/Jayapura');
        
        $this->assertEquals('Asia/Jayapura', $date->timezone->getName());
        $this->assertEquals('2026-03-04', $date->toDateString());
    }
}
```

### Feature Tests

Test timezone consistency in features:

```php
use App\Helpers\TimezoneHelper;
use App\Models\School;
use App\Models\Attendance;
use Tests\TestCase;

class AttendanceTimezoneTest extends TestCase
{
    public function test_attendance_recorded_in_school_timezone()
    {
        $school = School::factory()->create([
            'timezone' => 'Asia/Makassar'
        ]);
        
        $now = TimezoneHelper::schoolNow($school);
        
        $attendance = Attendance::create([
            'school_id' => $school->id,
            'attendance_date' => $now->toDateString(),
            'check_in_time' => $now,
        ]);
        
        $localTime = TimezoneHelper::convertToSchool(
            $attendance->check_in_time,
            $school
        );
        
        $this->assertEquals(
            $now->toDateString(),
            $localTime->toDateString()
        );
    }
}
```

### Property-Based Tests

Verify timezone consistency properties:

```php
use App\Helpers\TimezoneHelper;
use Tests\TestCase;

class TimezoneConsistencyPropertyTest extends TestCase
{
    /**
     * Property 1: No raw date() calls in application code
     */
    public function test_no_raw_date_calls_in_app_code()
    {
        $appFiles = $this->getPhpFilesInDirectory(app_path());
        
        foreach ($appFiles as $file) {
            $content = file_get_contents($file);
            
            // Check for date() calls (excluding comments)
            $this->assertDoesNotMatchRegex(
                '/(?<!\/\/.*)\bdate\s*\(/',
                $content,
                "Found raw date() call in {$file}"
            );
        }
    }
    
    /**
     * Property 2: All Carbon::now() calls should use timezone
     */
    public function test_carbon_now_uses_timezone()
    {
        $appFiles = $this->getPhpFilesInDirectory(app_path());
        
        foreach ($appFiles as $file) {
            $content = file_get_contents($file);
            
            // Allow TimezoneHelper usage
            if (str_contains($content, 'TimezoneHelper')) {
                continue;
            }
            
            // Check for Carbon::now() without timezone
            $this->assertDoesNotMatchRegex(
                '/Carbon::now\(\s*\)/',
                $content,
                "Found Carbon::now() without timezone in {$file}"
            );
        }
    }
}
```

---

## Migration Guide

### Step 1: Identify Raw Date Functions

Use grep to find all occurrences:

```bash
# Find date() calls
grep -r "\\bdate\\s*(" app/ --exclude-dir=vendor

# Find time() calls
grep -r "\\btime\\s*(" app/ --exclude-dir=vendor

# Find strtotime() calls
grep -r "\\bstrtotime\\s*(" app/ --exclude-dir=vendor

# Find Carbon::now() without timezone
grep -r "Carbon::now()" app/ --exclude-dir=vendor
```

### Step 2: Replace with TimezoneHelper

Common replacements:

```php
// date('Y-m-d') → TimezoneHelper::schoolNow($school)->toDateString()
// date('Y-m-d H:i:s') → TimezoneHelper::schoolNow($school)->toDateTimeString()
// time() → TimezoneHelper::schoolNow($school)->timestamp
// strtotime($date) → TimezoneHelper::parse($date, $school->timezone)->timestamp
// Carbon::now() → TimezoneHelper::schoolNow($school)
// today() → TimezoneHelper::schoolToday($school)
```

### Step 3: Update Controllers

```php
// Before
public function index(Request $request)
{
    $today = date('Y-m-d');
    $attendances = Attendance::whereDate('attendance_date', $today)->get();
    return view('attendance.index', compact('attendances'));
}

// After
public function index(Request $request, School $school)
{
    $today = TimezoneHelper::schoolToday($school)->toDateString();
    $attendances = Attendance::where('school_id', $school->id)
        ->whereDate('attendance_date', $today)
        ->get();
    return view('attendance.index', compact('attendances'));
}
```

### Step 4: Update Services

```php
// Before
class AttendanceService
{
    public function recordAttendance($studentId)
    {
        return Attendance::create([
            'student_id' => $studentId,
            'attendance_date' => date('Y-m-d'),
            'check_in_time' => now(),
        ]);
    }
}

// After
class AttendanceService
{
    public function recordAttendance(School $school, $studentId)
    {
        $now = TimezoneHelper::schoolNow($school);
        
        return Attendance::create([
            'student_id' => $studentId,
            'school_id' => $school->id,
            'attendance_date' => $now->toDateString(),
            'check_in_time' => $now,
        ]);
    }
}
```

### Step 5: Update Queue Jobs

```php
// Before
class GenerateReport implements ShouldQueue
{
    public function handle()
    {
        $today = date('Y-m-d');
        // ...
    }
}

// After
class GenerateReport extends TenantAwareJob
{
    public function __construct(int $schoolId)
    {
        parent::__construct($schoolId);
    }
    
    public function handle()
    {
        $school = School::find($this->schoolId);
        $today = TimezoneHelper::schoolToday($school)->toDateString();
        // ...
    }
}
```

### Step 6: Verify with Tests

Run property-based tests to verify:

```bash
php artisan test --filter=TimezoneConsistencyPropertyTest
```

---

## Troubleshooting

### Issue: Attendance Shows Wrong Date

**Symptoms**: Attendance recorded at 11 PM shows as next day

**Cause**: Using server timezone instead of school timezone

**Solution**:
```php
// ❌ WRONG
$date = Carbon::now()->toDateString();

// ✅ CORRECT
$date = TimezoneHelper::schoolNow($school)->toDateString();
```

### Issue: Reports Missing Data

**Symptoms**: Daily report doesn't include all attendance records

**Cause**: Query uses server timezone, misses records near midnight

**Solution**:
```php
// ❌ WRONG
$today = today();
Attendance::whereDate('attendance_date', $today)->get();

// ✅ CORRECT
$today = TimezoneHelper::schoolToday($school)->toDateString();
Attendance::where('school_id', $school->id)
    ->whereDate('attendance_date', $today)
    ->get();
```

### Issue: Timestamps Display Wrong Time

**Symptoms**: Check-in time shows 3 hours off

**Cause**: Not converting UTC to school timezone for display

**Solution**:
```php
// ❌ WRONG
$time = $attendance->check_in_time->format('H:i:s');

// ✅ CORRECT
$localTime = TimezoneHelper::convertToSchool($attendance->check_in_time, $school);
$time = $localTime->format('H:i:s');
```

### Issue: Queue Jobs Use Wrong Timezone

**Symptoms**: Scheduled reports run at wrong time

**Cause**: Job doesn't maintain school timezone context

**Solution**:
```php
// ❌ WRONG
class GenerateReport implements ShouldQueue
{
    public function handle()
    {
        $now = Carbon::now();
        // ...
    }
}

// ✅ CORRECT
class GenerateReport extends TenantAwareJob
{
    public function __construct(int $schoolId)
    {
        parent::__construct($schoolId);
    }
    
    public function handle()
    {
        $school = School::find($this->schoolId);
        $now = TimezoneHelper::schoolNow($school);
        // ...
    }
}
```

### Issue: Date Comparison Fails

**Symptoms**: `isToday()` returns false for today's records

**Cause**: Comparing dates in different timezones

**Solution**:
```php
// ❌ WRONG
if ($attendance->created_at->isToday()) {
    // ...
}

// ✅ CORRECT
$schoolNow = TimezoneHelper::schoolNow($school);
$attendanceLocal = TimezoneHelper::convertToSchool($attendance->created_at, $school);
if ($attendanceLocal->isSameDay($schoolNow)) {
    // ...
}
```

---

## Quick Reference

### Supported Indonesian Timezones

| Timezone | Region | UTC Offset | Abbreviation |
|----------|--------|------------|--------------|
| `Asia/Jakarta` | Western Indonesia | UTC+7 | WIB |
| `Asia/Makassar` | Central Indonesia | UTC+8 | WITA |
| `Asia/Jayapura` | Eastern Indonesia | UTC+9 | WIT |

### Common TimezoneHelper Methods

```php
// Current time
TimezoneHelper::schoolNow($school)
TimezoneHelper::now($timezone)

// Today's date
TimezoneHelper::schoolToday($school)
TimezoneHelper::today($timezone)

// Parse dates
TimezoneHelper::parse($dateString, $timezone)

// Convert timezones
TimezoneHelper::convertToSchool($date, $school)
TimezoneHelper::convertTo($date, $timezone)

// Get default
TimezoneHelper::defaultTimezone()
```

### Replacement Cheat Sheet

| Old (❌) | New (✅) |
|---------|---------|
| `date('Y-m-d')` | `TimezoneHelper::schoolNow($school)->toDateString()` |
| `date('H:i:s')` | `TimezoneHelper::schoolNow($school)->toTimeString()` |
| `time()` | `TimezoneHelper::schoolNow($school)->timestamp` |
| `strtotime($date)` | `TimezoneHelper::parse($date, $school->timezone)->timestamp` |
| `Carbon::now()` | `TimezoneHelper::schoolNow($school)` |
| `today()` | `TimezoneHelper::schoolToday($school)` |
| `Carbon::parse($date)` | `TimezoneHelper::parse($date, $school->timezone)` |

---

## Related Documentation

- [Timezone Configuration Guide](./timezone-configuration.md)
- [Timezone Audit Report](./timezone-audit-report.md)
- [Timezone Implementation Status](./timezone-implementation-status.md)
- [TimezoneHelper Source Code](../app/Helpers/TimezoneHelper.php)
- [Laravel Carbon Documentation](https://carbon.nesbot.com/docs/)

---

## Checklist for New Features

When implementing new features, verify:

- [ ] All date/time operations use `TimezoneHelper`
- [ ] No raw `date()`, `time()`, or `strtotime()` calls
- [ ] School timezone passed to all date operations
- [ ] Database queries use timezone-aware dates
- [ ] API responses include timezone information
- [ ] Queue jobs maintain timezone context
- [ ] Tests verify timezone consistency
- [ ] Documentation updated if needed

---

**Document Owner**: Engineering Team  
**Review Cycle**: Quarterly  
**Next Review**: June 2026
