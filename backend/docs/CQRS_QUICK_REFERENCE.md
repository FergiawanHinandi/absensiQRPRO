# CQRS Quick Reference Card

**For Developers** | Last Updated: 2026-02-10

---

## 🚀 Quick Start

### Write Operations (Commands)

```php
use App\Application\Services\AttendanceApplicationService;

$service = app(AttendanceApplicationService::class);
```

#### Check In
```php
$attendance = $service->checkIn([
    'student_id' => 1,
    'schedule_id' => 1,
    'school_id' => 1,
    'attendance_date' => '2026-02-10',
    'check_in_time' => now(),
    'latitude' => -6.2088,
    'longitude' => 106.8456,
]);
```

#### Check Out
```php
$attendance = $service->checkOut($attendanceId, [
    'check_out_time' => now(),
]);
```

#### Request Correction
```php
$attendance = $service->requestCorrection(
    attendanceId: $id,
    reason: 'Wrong status',
    requesterId: auth()->id()
);
```

### Read Operations (Queries)

```php
use App\Application\Services\DashboardQueryService;

$query = app(DashboardQueryService::class);
```

#### Today's Summary
```php
$summary = $query->getTodaySummary($schoolId);
// Returns: total_present, total_late, total_absent, attendance_rate
```

#### Weekly Trend
```php
$trend = $query->getWeeklyTrend($schoolId, days: 7);
```

#### Class Summaries
```php
$classes = $query->getClassSummaries($schoolId, today());
```

#### Chart Data
```php
$chartData = $query->getAttendanceRateTrend($schoolId, days: 30);
```

---

## 📁 Where to Find Things

### Write Operations
```
app/Domain/Attendance/
├── Aggregates/AttendanceAggregate.php    ← Business logic
├── Commands/CheckInCommand.php           ← User intent
├── Handlers/CheckInHandler.php           ← Orchestration
└── Events/AttendanceRecorded.php         ← What happened
```

### Read Operations
```
app/ReadModels/
├── AttendanceDailySummary.php            ← Pre-aggregated data
└── Projectors/AttendanceSummaryProjector.php ← Updates read model
```

### Application Services
```
app/Application/Services/
├── AttendanceApplicationService.php      ← Write facade
└── DashboardQueryService.php             ← Read facade
```

---

## ✅ Do's

```php
// ✅ Use Application Services
$service->checkIn($data);

// ✅ Use Query Service for reads
$query->getTodaySummary($schoolId);

// ✅ Let domain enforce rules
// (time windows, geofences, etc. are automatic)

// ✅ Use events for side effects
// (notifications, cache invalidation, etc.)
```

---

## ❌ Don'ts

```php
// ❌ Don't query write models directly
Attendance::where(...)->get();

// ❌ Don't put business logic in controllers
if ($time > $schedule->end_time) { ... }

// ❌ Don't update read models directly
AttendanceDailySummary::where(...)->update([...]);
```

---

## 🎯 Performance

| Query | Response Time |
|-------|---------------|
| Today's Summary | ~15ms |
| Weekly Trend | ~20ms |
| Class Summaries | ~12ms |
| Monthly Report | ~45ms |

**All queries are cached!**

---

## 📚 Full Documentation

1. [CQRS_USAGE_GUIDE.md](./CQRS_USAGE_GUIDE.md) - Complete examples
2. [CQRS_FINAL_STRUCTURE.md](./CQRS_FINAL_STRUCTURE.md) - Architecture
3. [CQRS_IMPLEMENTATION_SUMMARY.md](./CQRS_IMPLEMENTATION_SUMMARY.md) - Features

---

## 🆘 Need Help?

1. Check [CQRS_USAGE_GUIDE.md](./CQRS_USAGE_GUIDE.md) for examples
2. Review existing code in `app/Application/Services/`
3. Look at tests for usage patterns

---

**Remember**: 
- **Write** = `AttendanceApplicationService`
- **Read** = `DashboardQueryService`
- **Controllers** = Thin, delegate to services
