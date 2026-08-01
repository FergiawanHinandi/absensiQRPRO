# Timezone Configuration Guide

## Overview

AbsensiQR Pro supports per-school timezone configuration to ensure accurate attendance tracking across different regions in Indonesia. Each school can have its own timezone setting, which is used for all date/time operations related to that school.

## Database Schema

### Schools Table

The `schools` table includes a `timezone` column:

```sql
timezone VARCHAR(50) DEFAULT 'Asia/Jakarta'
```

**Supported Timezones** (Indonesia):
- `Asia/Jakarta` - Western Indonesia Time (WIB) - UTC+7
- `Asia/Makassar` - Central Indonesia Time (WITA) - UTC+8
- `Asia/Jayapura` - Eastern Indonesia Time (WIT) - UTC+9

## Application Configuration

### Default Timezone

The application default timezone is configured in `config/app.php`:

```php
'timezone' => 'Asia/Jakarta',
```

This serves as the fallback timezone when a school doesn't have a specific timezone set.

## School Model

The `School` model includes timezone in its fillable attributes:

```php
protected $fillable = [
    // ... other fields
    'timezone',
    // ... other fields
];
```

### Usage Example

```php
// Get school timezone
$school = School::find($schoolId);
$timezone = $school->timezone ?? config('app.timezone');

// Use with TimezoneHelper
$now = TimezoneHelper::schoolNow($school);
$date = TimezoneHelper::parse('2026-03-04', $school->timezone);
```

## TimezoneHelper Utility

The `TimezoneHelper` class provides timezone-aware date/time operations:

```php
use App\Helpers\TimezoneHelper;

// Get current time in school timezone
$now = TimezoneHelper::schoolNow($school);

// Get current time in specific timezone
$now = TimezoneHelper::now('Asia/Makassar');

// Parse date in school timezone
$date = TimezoneHelper::parse('2026-03-04', $school->timezone);
```

## Best Practices

### DO ✅

1. **Always use TimezoneHelper** for date/time operations:
   ```php
   $now = TimezoneHelper::schoolNow($school);
   ```

2. **Use school timezone for queries**:
   ```php
   $date = TimezoneHelper::schoolNow($school)->toDateString();
   Attendance::whereDate('attendance_date', $date)
       ->where('school_id', $school->id)
       ->get();
   ```

3. **Store timezone in school settings**:
   ```php
   $school->update(['timezone' => 'Asia/Makassar']);
   ```

### DON'T ❌

1. **Don't use raw PHP date functions**:
   ```php
   // ❌ BAD
   $date = date('Y-m-d');
   
   // ✅ GOOD
   $date = TimezoneHelper::schoolNow($school)->toDateString();
   ```

2. **Don't use Carbon::now() without timezone**:
   ```php
   // ❌ BAD
   $now = Carbon::now();
   
   // ✅ GOOD
   $now = TimezoneHelper::schoolNow($school);
   ```

3. **Don't hardcode timezones**:
   ```php
   // ❌ BAD
   $now = Carbon::now('Asia/Jakarta');
   
   // ✅ GOOD
   $now = TimezoneHelper::schoolNow($school);
   ```

## API Endpoints

### Get School Timezone

```http
GET /api/v1/schools/{id}
```

Response includes timezone:
```json
{
  "id": 1,
  "name": "SD Negeri 1 Jakarta",
  "timezone": "Asia/Jakarta",
  ...
}
```

### Update School Timezone

```http
PUT /api/v1/schools/{id}
Content-Type: application/json

{
  "timezone": "Asia/Makassar"
}
```

## Migration Guide

If you need to update existing schools' timezones:

```php
use App\Models\School;

// Update all schools in Central Indonesia to WITA
School::where('address', 'LIKE', '%Makassar%')
    ->orWhere('address', 'LIKE', '%Sulawesi%')
    ->update(['timezone' => 'Asia/Makassar']);

// Update all schools in Eastern Indonesia to WIT
School::where('address', 'LIKE', '%Papua%')
    ->update(['timezone' => 'Asia/Jayapura']);
```

## Testing

Timezone consistency is verified through property-based tests:

```php
// tests/Unit/Helpers/TimezoneHelperTest.php
test('schoolNow uses school timezone', function () {
    $school = School::factory()->create(['timezone' => 'Asia/Makassar']);
    $now = TimezoneHelper::schoolNow($school);
    expect($now->timezone->getName())->toBe('Asia/Makassar');
});
```

## Troubleshooting

### Issue: Attendance dates don't match

**Cause**: Using raw date functions instead of TimezoneHelper

**Solution**: Replace all `date()`, `time()`, `strtotime()` with TimezoneHelper methods

### Issue: Wrong timezone in reports

**Cause**: Not passing school timezone to date queries

**Solution**: Always use `TimezoneHelper::schoolNow($school)` for date operations

### Issue: Timezone not persisting

**Cause**: Timezone not in fillable array or validation failing

**Solution**: Verify School model has 'timezone' in $fillable and validate timezone values

## References

- [PHP Supported Timezones](https://www.php.net/manual/en/timezones.php)
- [Laravel Carbon Documentation](https://carbon.nesbot.com/docs/)
- [TimezoneHelper Source](../app/Helpers/TimezoneHelper.php)
- [Timezone Audit Report](./timezone-audit-report.md)
