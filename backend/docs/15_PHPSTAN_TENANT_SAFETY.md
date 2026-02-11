# PHPStan Tenant Safety Rules

## 🎯 Purpose
Protect multi-tenant data integrity by preventing developers from accidentally bypassing Global Scopes when writing queries.

## ⚠️ The Problem

AbsensiQRPro uses **Global Scopes** to automatically filter data by `school_id`:

```php
// ✅ SAFE: Uses Eloquent with BelongsToSchool trait
Attendance::where('date', today())->get();
// SQL: SELECT * FROM attendances WHERE date = '2026-01-15' AND school_id = 123

// ❌ UNSAFE: Bypasses Global Scope
DB::table('attendances')->where('date', today())->get();
// SQL: SELECT * FROM attendances WHERE date = '2026-01-15'
//      ^ Missing school_id filter! Can leak data across tenants!
```

## 🛡️ The Solution

PHPStan custom rule **blocks** `DB::table()` usage on tenant-scoped tables at **compile time**.

### Protected Tables
```php
const TENANT_TABLES = [
    'attendances',
    'students',
    'users',
    'schedules',
    'qr_codes',
    'classes',
    'subjects',
    'teacher_devices',
    'security_events',
    'audit_logs',
    'notifications',
];
```

## 📋 Quick Start

### Run Analysis Locally
```bash
cd backend
composer analyse
```

### Fix Violations
When PHPStan reports an error:

```
------ ERROR -----------------------------------------------
Using DB::table('attendances') bypasses Global Scopes and may leak data across tenants.
Use Eloquent model instead (e.g., Attendance::query()).
------------------------------------------------------------
```

**Fix:**
```php
// ❌ BEFORE
$attendances = DB::table('attendances')
    ->where('date', $date)
    ->get();

// ✅ AFTER
$attendances = Attendance::query()
    ->where('date', $date)
    ->get();
```

## 🔧 Exceptions (When You MUST Use Raw Queries)

### Scenario 1: Cross-Tenant Admin Operations
```php
// Super admin viewing ALL schools' attendance (rare, must be logged)
$globalStats = DB::table('attendances')
    ->select('school_id', DB::raw('COUNT(*) as total'))
    ->groupBy('school_id')
    ->get();

// Security: Add @phpstan-ignore-next-line with justification
/** @phpstan-ignore-next-line Cross-tenant query for super_admin dashboard */
$globalStats = DB::table('attendances')
    ->select('school_id', DB::raw('COUNT(*) as total'))
    ->groupBy('school_id')
    ->get();
```

### Scenario 2: Complex Joins Not Possible in Eloquent
```php
// When Eloquent subquery becomes too complex
/** @phpstan-ignore-next-line Complex aggregation requires raw SQL */
$report = DB::table('attendances as a')
    ->join(DB::raw('(SELECT ...) as subquery'), 'a.id', '=', 'subquery.attendance_id')
    ->where('a.school_id', auth()->user()->school_id) // ⚠️ Manual school_id check required!
    ->get();
```

## 🚀 CI/CD Integration

GitHub Actions automatically runs PHPStan on every PR:
- ✅ PR passes only if no violations found
- 📊 Results visible in PR checks
- 🔒 Enforces tenant safety before merge

**Workflow:** `.github/workflows/phpstan.yml`

## 📚 Configuration

### PHPStan Level: 5
- **Level 0-4:** Basic type checking (method exists, not too strict on mixed types)
- **Level 5 (current):** Checks unknown variables, undefined properties
- **Level 6-9:** Strictest (recommended after codebase cleanup)

Upgrade when ready:
```yaml
# backend/phpstan.neon
parameters:
    level: 6  # Incrementally increase
```

### Add More Tenant Tables
Update custom rule when adding new multi-tenant entities:

```php
// backend/phpstan/PreventRawQueryOnTenantModels.php
private const TENANT_TABLES = [
    'attendances',
    'students',
    'your_new_tenant_table', // ← Add here
];
```

## 🐛 Troubleshooting

### "Class not found" errors
```bash
# Regenerate autoload files
composer dump-autoload
```

### "Out of memory" during analysis
```bash
# Increase memory limit
composer analyse -- --memory-limit=4G
```

### False positives
```yaml
# backend/phpstan.neon
parameters:
    ignoreErrors:
        - '#Specific error pattern to ignore#'
```

## 📖 Resources
- PHPStan Docs: https://phpstan.org/
- Larastan (Laravel Extension): https://github.com/larastan/larastan
- Laravel Global Scopes: https://laravel.com/docs/eloquent#global-scopes

## ✅ Security Audit Compliance
- **Issue:** Raw queries bypassing Global Scopes (CRITICAL)
- **Status:** ✅ FIXED via PHPStan custom rule
- **Prevention:** Compile-time validation in CI/CD
- **Impact:** Zero-tolerance for tenant data leaks
