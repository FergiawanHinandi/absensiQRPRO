# Timezone Consistency Audit Findings

## Audit Date: February 12, 2026
## Auditor: Kiro (AI Assistant)
## Scope: Backend PHP codebase

## Summary

The audit was conducted to identify all raw PHP date function usage (`date()`, `time()`, `strtotime()`) that need to be replaced with the centralized `TimezoneHelper` utility class.

## Findings

### 1. Raw `date()` Function Usage

**Location**: Scripts directory (development/testing scripts only)
- `backend/scripts/replay_attack_test.php` - Line 37: `'attendance_date' => date('Y-m-d'),`
- `backend/scripts/simulate_idempotency.php` - Line 45-46: `'attendance_date' => date('Y-m-d'), 'check_in_time' => date('Y-m-d H:i:s'),`
- `backend/scripts/simulate_concurrent_checkin.php` - Line 35-36: `'attendance_date' => date('Y-m-d'), 'check_in_time' => date('Y-m-d H:i:s'),`
- `backend/scripts/restore_system.php` - Multiple occurrences in logging and backup naming
- `backend/scripts/race_condition_test.php` - Line 34: `'attendance_date' => date('Y-m-d'),`
- `backend/scripts/dos/dos_simulation.php` - Line 36: `'attendance_date' => date('Y-m-d'),`
- `backend/scripts/disaster_recovery_simulation.php` - Multiple occurrences in logging and backup naming
- `backend/scripts/backup_system.php` - Multiple occurrences in logging and backup naming
- `backend/scripts/backup_scheduler.php` - Line 196, 281: Log file naming and timestamp
- `backend/scripts/advanced_backup_strategy.php` - Line 593: Log timestamp
- `backend/database/scripts/query_duplicate_attendances.php` - Line 136: Export file naming
- `backend/database/scripts/cleanup_duplicate_attendances.php` - Line 201: Export file naming

**Status**: These are development/testing scripts and can be updated as part of the cleanup process.

### 2. Raw `time()` Function Usage

**Location**: Various application files
- `backend/app/Services/ObservabilityService.php` - Line 85, 117, 137, 311: Time-based calculations
- `backend/app/Services/FailSecure/RateLimitFallbackService.php` - Line 233, 266, 277, 321, 473: Rate limiting timers
- `backend/app/Infrastructure/Redis/RedisCircuitBreaker.php` - Line 131, 148, 170: Circuit breaker timing
- `backend/app/Http/Middleware/VerifyWebhookSignature.php` - Line 143: Webhook timestamp validation
- `backend/routes/health.php` - Line 76, 119: Health check cache keys
- `backend/app/Http/Controllers/HealthCheckController.php` - Line 434, 471: Health check cache keys
- `backend/app/Http/Controllers/Api/V1/HealthController.php` - Line 132: Health check cache key
- `backend/app/Http/Controllers/Api/HealthCheckController.php` - Line 319: Health check cache key

**Status**: These are legitimate uses of `time()` for timestamp generation and should be reviewed for timezone awareness.

### 3. Raw `strtotime()` Function Usage

**Location**: Minimal usage
- `backend/app/Services/ObservabilityService.php` - Line 309: Worker heartbeat parsing
- `backend/app/Services/FailSecure/RateLimitFallbackService.php` - Line 320: Expiration time parsing

**Status**: These should be replaced with `TimezoneHelper::parse()`.

### 4. Main Application Files Checked

The following critical application files were checked and found to be using `Carbon` or `now()` instead of raw date functions:

1. **`app/Services/SecureAttendanceService.php`** - ✅ Uses `Carbon::now()`, `Carbon::parse()`
2. **`app/Http/Controllers/Api/V1/Teacher/QRGeneratorController.php`** - ✅ Uses `now()`, `Carbon`
3. **`app/Http/Controllers/Api/V1/Admin/AttendanceReportController.php`** - ✅ Uses `Carbon::today()`, `Carbon::now()`
4. **`app/Jobs/ExportAttendanceReport.php`** - ✅ Uses `Carbon::now()`

### 5. TimezoneHelper Status

✅ **TimezoneHelper already exists** at `app/Helpers/TimezoneHelper.php`
- Contains all required methods: `now()`, `schoolNow()`, `parse()`, `today()`, `timeFromString()`, `isBetween()`
- Well-documented with examples
- Already in use in some test files

### 6. School Timezone Field Status

✅ **Schools table already has timezone column** in migration `2026_01_19_045132_create_schools_table.php`
- Column: `$table->string('timezone', 50)->default('Asia/Jakarta');`
- Already in `$fillable` array of School model
- Default value matches config

### 7. Application Default Timezone

✅ **Config already set** in `config/app.php`
- `'timezone' => 'Asia/Jakarta'`
- Matches schools table default

## Recommendations

### High Priority (P0)
1. **Update development scripts** to use `TimezoneHelper::now()->toDateString()` instead of `date('Y-m-d')`
2. **Replace `strtotime()` calls** with `TimezoneHelper::parse()->timestamp`
3. **Review `time()` usage** for timezone awareness

### Medium Priority (P1)
1. **Update test files** to use `TimezoneHelper` consistently
2. **Add PHPStan rule** to prevent raw date function usage in application code
3. **Document timezone best practices** for new development

### Low Priority (P2)
1. **Consider updating `time()` usage** where timezone matters (e.g., cache keys with timestamps)

## Next Steps

1. ✅ Sub-task 1.1: Audit complete (this document)
2. ➡️ Sub-task 1.2: TimezoneHelper already exists, verify functionality
3. ➡️ Sub-task 1.3: Replace raw date functions in identified files
4. ➡️ Sub-task 1.4: Timezone field already exists, verify School model usage
5. ➡️ Sub-task 1.5: Write property tests for timezone consistency

## Risk Assessment

**Risk Level**: 🔴 HIGH (8/10)
- Raw date functions in scripts could cause timezone issues in development/testing
- `time()` usage may not respect timezone in some contexts
- `strtotime()` assumes server timezone

**Mitigation**: Replace with `TimezoneHelper` which ensures consistent timezone handling across the application.