# Coding Standards: PHPStan Static Analysis

## Quick Reference

### ✅ DO: Use Eloquent Models

```php
// ✅ Correct - Global Scopes applied automatically
$attendances = Attendance::where('status', 'present')->get();
$student = Student::find($id);
$count = Schedule::count();

// ✅ Correct - Query builder through model
$data = Attendance::query()
    ->where('attendance_date', today())
    ->orderBy('created_at', 'desc')
    ->get();
```

### ❌ DON'T: Use DB::table() on Tenant Tables

```php
// ❌ Wrong - Bypasses Global Scopes
$attendances = DB::table('attendances')->get();

// ❌ Wrong - Even with school_id filter
$data = DB::table('students')
    ->where('school_id', $schoolId)
    ->get();

// ❌ Wrong - Inserts without scope validation
DB::table('schedules')->insert([...]);
```

## Protected Tenant Tables

These tables **MUST** use Eloquent models:

| Table | Model | Reason |
|-------|-------|--------|
| `attendances` | `Attendance` | School-scoped data |
| `students` | `Student` | School-scoped data |
| `users` | `User` | School-scoped data |
| `schedules` | `Schedule` | School-scoped data |
| `qr_codes` | `QrCode` | School-scoped data |
| `classes` | `ClassRoom` | School-scoped data |
| `subjects` | `Subject` | School-scoped data |
| `teacher_devices` | `TeacherDevice` | School-scoped data |
| `security_events` | `SecurityEvent` | School-scoped data |
| `audit_logs` | `AuditLog` | School-scoped data |
| `notifications` | `Notification` | School-scoped data |

## Running PHPStan

### Command Line

```bash
# Full analysis
composer analyse

# Specific file
./vendor/bin/phpstan analyse app/Services/MyService.php

# With memory limit
./vendor/bin/phpstan analyse --memory-limit=2G
```

### IDE Integration

**PHPStorm/IntelliJ:**
1. Install PHPStan plugin
2. Configure: Settings → PHP → Quality Tools → PHPStan
3. Set path: `vendor/bin/phpstan`
4. Set config: `phpstan.neon`

**VS Code:**
1. Install "PHPStan" extension
2. Configure in `.vscode/settings.json`:
```json
{
    "phpstan.enabled": true,
    "phpstan.path": "vendor/bin/phpstan",
    "phpstan.configFile": "phpstan.neon"
}
```

## Common Patterns

### Querying Data

```php
// ❌ Wrong
$count = DB::table('attendances')
    ->where('school_id', $schoolId)
    ->count();

// ✅ Correct
$count = Attendance::count(); // school_id filtered automatically
```

### Inserting Data

```php
// ❌ Wrong
DB::table('students')->insert([
    'name' => 'John Doe',
    'school_id' => $schoolId,
]);

// ✅ Correct
Student::create([
    'name' => 'John Doe',
    'school_id' => auth()->user()->school_id,
]);
```

### Updating Data

```php
// ❌ Wrong
DB::table('schedules')
    ->where('id', $id)
    ->update(['name' => 'New Name']);

// ✅ Correct
$schedule = Schedule::find($id);
$schedule->update(['name' => 'New Name']);
```

### Complex Queries

```php
// ❌ Wrong
$results = DB::table('attendances')
    ->join('students', 'attendances.student_id', '=', 'students.id')
    ->where('attendances.school_id', $schoolId)
    ->select('attendances.*', 'students.name')
    ->get();

// ✅ Correct
$results = Attendance::with('student')
    ->select('attendances.*', 'students.name')
    ->get();
```

### Aggregations

```php
// ❌ Wrong
$stats = DB::table('attendances')
    ->where('school_id', $schoolId)
    ->selectRaw('status, COUNT(*) as count')
    ->groupBy('status')
    ->get();

// ✅ Correct
$stats = Attendance::query()
    ->selectRaw('status, COUNT(*) as count')
    ->groupBy('status')
    ->get();
```

## Exceptions

### When DB::table() is Allowed

1. **Non-tenant tables:**
```php
// ✅ OK - System table without tenant scope
DB::table('migrations')->where('batch', 1)->get();
```

2. **Migrations:**
```php
// ✅ OK - Migrations are excluded from PHPStan
Schema::table('attendances', function (Blueprint $table) {
    $table->index('school_id');
});
```

3. **Performance-critical raw queries (with justification):**
```php
// Document why raw query is needed
// @phpstan-ignore-next-line tenantSafety.rawQuery
$result = DB::table('attendances')
    ->where('school_id', $schoolId) // Explicit filter required
    ->selectRaw('DATE(attendance_date) as date, COUNT(*) as total')
    ->groupBy('date')
    ->get();
```

## Error Messages

### Typical PHPStan Error

```
------ ---------------------------------------------------------------
 Line   app/Services/AttendanceService.php
------ ---------------------------------------------------------------
 45     Using DB::table('attendances') bypasses Global Scopes and
        may leak data across tenants. Use Eloquent model instead
        (e.g., Attendance::query()).
------ ---------------------------------------------------------------
```

### How to Fix

1. **Identify the table** mentioned in the error
2. **Find the corresponding model** (usually singular, PascalCase)
3. **Replace `DB::table('table_name')` with `ModelName::query()`**
4. **Remove explicit `school_id` filters** (handled by Global Scope)
5. **Test the change** to ensure behavior is preserved

## Best Practices

### 1. Always Use Eloquent for Tenant Data

```php
// ✅ Eloquent handles tenant isolation
$data = Attendance::where('status', 'present')->get();
```

### 2. Trust Global Scopes

```php
// ❌ Don't add redundant school_id filters
$data = Attendance::where('school_id', auth()->user()->school_id)->get();

// ✅ Global Scope adds it automatically
$data = Attendance::all();
```

### 3. Use Eager Loading

```php
// ✅ Prevent N+1 queries
$attendances = Attendance::with(['student', 'schedule'])->get();
```

### 4. Leverage Query Scopes

```php
// ✅ Define reusable query scopes
class Attendance extends Model
{
    public function scopePresent($query)
    {
        return $query->where('status', 'present');
    }
}

// Usage
$present = Attendance::present()->get();
```

## CI/CD Integration

### GitHub Actions

```yaml
name: PHPStan

on: [push, pull_request]

jobs:
  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Install dependencies
        run: composer install
      - name: Run PHPStan
        run: composer analyse
```

### Pre-commit Hook

```bash
#!/bin/bash
# .git/hooks/pre-commit

echo "Running PHPStan..."
composer analyse --no-progress --quiet

if [ $? -ne 0 ]; then
    echo "❌ PHPStan found errors. Please fix before committing."
    exit 1
fi

echo "✅ PHPStan passed"
```

Make executable:
```bash
chmod +x .git/hooks/pre-commit
```

## Resources

- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)
- [Larastan Documentation](https://github.com/larastan/larastan)
- [Project: DB::table() Rule Documentation](./PHPSTAN_DB_TABLE_RULE.md)
- [Project: Multi-Tenant Security Guide](./MULTI_TENANT_VALIDATION_GUIDE.md)

## Support

If you encounter issues with PHPStan:

1. Check this guide for common patterns
2. Review the error message carefully
3. Consult the team's coding standards
4. Ask in the development channel

**Remember**: PHPStan is here to help prevent bugs, not to slow you down. If you're fighting the tool, there's usually a better way to structure your code.
