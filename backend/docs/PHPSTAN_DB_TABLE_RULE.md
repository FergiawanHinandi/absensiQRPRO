# PHPStan DB::table() Prevention Rule

## Overview

This document describes the custom PHPStan rule that prevents developers from using `DB::table()` on tenant-scoped tables, which would bypass Laravel's Global Scopes and potentially cause data leaks across tenants.

## Why This Rule Exists

**Problem**: Using `DB::table()` bypasses Eloquent models and their Global Scopes, which means:
- Multi-tenant isolation is broken
- `school_id` filtering is not applied automatically
- Data from other schools can be accessed or modified
- Security vulnerabilities are introduced

**Solution**: This PHPStan rule detects and prevents `DB::table()` usage on tenant-scoped tables at static analysis time, before code reaches production.

## Rule Configuration

### Location
- **Rule Class**: `backend/phpstan/PreventRawQueryOnTenantModels.php`
- **Configuration**: `backend/phpstan.neon`
- **Namespace**: `PHPStan\Rules\TenantSafety\PreventRawQueryOnTenantModels`

### Protected Tables

The rule monitors these tenant-scoped tables:
- `attendances`
- `students`
- `users`
- `schedules`
- `qr_codes`
- `classes`
- `subjects`
- `teacher_devices`
- `security_events`
- `audit_logs`
- `notifications`

## Usage

### Running PHPStan

```bash
# Run full analysis
composer analyse

# Or directly
./vendor/bin/phpstan analyse --memory-limit=2G

# Analyze specific file
./vendor/bin/phpstan analyse app/Services/MyService.php
```

### What Gets Flagged

#### ❌ BAD - Will trigger PHPStan error:

```php
// Direct DB::table() usage on tenant tables
$count = DB::table('attendances')->count();

$students = DB::table('students')
    ->where('school_id', 1)
    ->get();

DB::table('schedules')->insert([
    'name' => 'Test Schedule'
]);

// Even with school_id filter, still flagged
$data = DB::table('attendances')
    ->where('school_id', $schoolId)
    ->get();
```

**PHPStan Error Message:**
```
Using DB::table('attendances') bypasses Global Scopes and may leak data across tenants.
Use Eloquent model instead (e.g., Attendance::query()).
```

#### ✅ GOOD - Correct usage:

```php
// Use Eloquent models (Global Scopes applied automatically)
$count = Attendance::count();

$students = Student::where('name', 'like', '%John%')->get();

Schedule::create([
    'name' => 'Test Schedule',
    'school_id' => auth()->user()->school_id,
]);

// Query builder through model
$data = Attendance::query()
    ->where('status', 'present')
    ->get();
```

### Exceptions

The rule **does NOT** flag:
- ✅ Migrations (excluded via `excludePaths`)
- ✅ Tests (excluded via `excludePaths`)
- ✅ Non-tenant tables (e.g., `DB::table('migrations')`)
- ✅ System tables without tenant scope

## Configuration Details

### phpstan.neon

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
        - routes
        - config
    
    level: 5
    
    excludePaths:
        - bootstrap
        - storage
        - database/migrations
        - tests

services:
    -
        class: PHPStan\Rules\TenantSafety\PreventRawQueryOnTenantModels
        tags:
            - phpstan.rules.rule
```

### composer.json

The custom rule is autoloaded via:

```json
{
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/",
            "PHPStan\\Rules\\TenantSafety\\": "phpstan/"
        }
    }
}
```

After modifying autoload, run:
```bash
composer dump-autoload
```

## Integration with CI/CD

### Add to CI Pipeline

```yaml
# .github/workflows/tests.yml
- name: Run PHPStan
  run: composer analyse
```

### Pre-commit Hook

```bash
#!/bin/bash
# .git/hooks/pre-commit

echo "Running PHPStan..."
composer analyse

if [ $? -ne 0 ]; then
    echo "❌ PHPStan failed. Fix errors before committing."
    exit 1
fi
```

## Troubleshooting

### Rule Not Working

1. **Check autoload registration:**
   ```bash
   composer dump-autoload
   ```

2. **Verify rule is loaded:**
   ```bash
   ./vendor/bin/phpstan analyse --debug
   ```

3. **Check namespace matches:**
   - File: `backend/phpstan/PreventRawQueryOnTenantModels.php`
   - Namespace: `PHPStan\Rules\TenantSafety`
   - Class: `PreventRawQueryOnTenantModels`

### False Positives

If you have a legitimate use case for `DB::table()`:

1. **Document why it's needed** (e.g., performance-critical raw query)
2. **Add explicit school_id filter**
3. **Add PHPStan ignore comment:**

```php
// @phpstan-ignore-next-line tenantSafety.rawQuery
$result = DB::table('attendances')
    ->where('school_id', $schoolId) // Explicit filter
    ->selectRaw('COUNT(*) as total')
    ->first();
```

### Adding New Protected Tables

Edit `backend/phpstan/PreventRawQueryOnTenantModels.php`:

```php
private const TENANT_TABLES = [
    'attendances',
    'students',
    // ... existing tables
    'new_tenant_table', // Add here
];
```

## Testing the Rule

### Manual Test

Create a test file with intentional violations:

```php
// test-phpstan.php
<?php

use Illuminate\Support\Facades\DB;

// This should trigger PHPStan error
$count = DB::table('attendances')->count();
```

Run PHPStan:
```bash
./vendor/bin/phpstan analyse test-phpstan.php
```

Expected output:
```
------ ---------------------------------------------------------------
 Line   test-phpstan.php
------ ---------------------------------------------------------------
 6      Using DB::table('attendances') bypasses Global Scopes and
        may leak data across tenants. Use Eloquent model instead
        (e.g., Attendance::query()).
------ ---------------------------------------------------------------
```

## Benefits

1. **Prevents Data Leaks**: Catches tenant isolation bypasses at development time
2. **Enforces Best Practices**: Encourages Eloquent usage over raw queries
3. **Automated Security**: No manual code review needed for this issue
4. **Fast Feedback**: Developers see errors immediately in IDE (with PHPStan plugin)
5. **CI/CD Integration**: Blocks unsafe code from reaching production

## Related Documentation

- [DB::table() Usage Audit](./DB_TABLE_USAGE_AUDIT.md)
- [DB::table() Quick Reference](./DB_TABLE_QUICK_REFERENCE.md)
- [Multi-Tenant Validation Guide](./MULTI_TENANT_VALIDATION_GUIDE.md)
- [Tenant Security Audit](./TENANT_SECURITY_AUDIT.md)

## Maintenance

### Updating the Rule

When adding new tenant-scoped models:

1. Add table name to `TENANT_TABLES` constant
2. Ensure model has `BelongsToSchool` trait or equivalent Global Scope
3. Test the rule catches violations
4. Update this documentation

### Version History

- **2026-02-10**: Initial implementation for Day 5 of SaaS Hardening
- Rule prevents DB::table() on 11 tenant-scoped tables
- Integrated with PHPStan 2.0 and Larastan 3.0
