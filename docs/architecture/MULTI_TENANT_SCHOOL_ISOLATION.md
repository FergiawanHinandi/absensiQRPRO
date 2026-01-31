# Multi-Tenant School Isolation

## Overview

The `SchoolScope` global scope automatically filters all database queries on school-scoped models, ensuring data isolation between schools in the multi-tenant system.

---

## How It Works

### Query Transformation

**WITHOUT global scope:**
```sql
SELECT * FROM attendances WHERE status = 'present'
```

**WITH global scope (user from School #123):**
```sql
SELECT * FROM attendances WHERE status = 'present' AND school_id = 123
```

The scope is applied **automatically** to every query on models using the `BelongsToSchool` trait.

---

## Model Implementation

```php
// app/Models/Attendance.php
class Attendance extends Model
{
    use BelongsToSchool;  // ← This adds the SchoolScope automatically
    
    // ...
}
```

The `BelongsToSchool` trait:

```php
// app/Traits/BelongsToSchool.php
trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        // Add global scope
        static::addGlobalScope(new SchoolScope);

        // Auto-fill school_id on create
        static::creating(function ($model) {
            if (Auth::check() && !$model->school_id) {
                $model->school_id = Auth::user()->school_id;
            }
        });
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
```

---

## Bypass Scenarios

| Scenario | Behavior | Logged? |
|----------|----------|---------|
| **Super Admin** | No filter applied | ✅ Yes |
| **CLI Context** | No filter (unless explicit) | ✅ Yes |
| **Explicit School** | Uses specified school_id | No |
| **No Auth User** | No filter | No |
| **User without school_id** | Empty result (safety) | ✅ Yes |

---

## Super Admin Bypass

Super admins can see all schools' data:

```php
// User with role 'super_admin' or role_type = 'super_admin'

// This query returns ALL schools' attendances
$allAttendances = Attendance::all();
```

Detection methods (in order):
1. Spatie Permission: `$user->hasRole('super_admin')`
2. Role column: `$user->role_type === 'super_admin'`
3. Flag column: `$user->is_super_admin === true`

---

## CLI Context Bypass

Artisan commands and queue jobs run without the scope by default:

```bash
# This works even without auth - scope is bypassed
php artisan attendance:generate-report
```

For CLI with specific school context:

```php
// In artisan command or job
use App\Scopes\SchoolScope;

SchoolScope::forSchool(123);
$attendances = Attendance::where('status', 'present')->get();
SchoolScope::clearSchool();
```

---

## Explicit School Context

### Static Method

```php
use App\Scopes\SchoolScope;

// Set context
SchoolScope::forSchool(123);

// All queries now filter by school_id = 123
$attendances = Attendance::all();
$students = User::where('role_type', 'student')->get();

// Clear context
SchoolScope::clearSchool();
```

### Callback Pattern (Recommended)

```php
use App\Scopes\SchoolScope;

$result = SchoolScope::withSchool(123, function () {
    return [
        'attendances' => Attendance::count(),
        'students' => User::where('role_type', 'student')->count(),
    ];
});
// Context automatically cleared after callback
```

### In Queue Jobs

```php
class GenerateSchoolReport implements ShouldQueue
{
    public function __construct(
        public int $schoolId
    ) {}

    public function handle()
    {
        SchoolScope::withSchool($this->schoolId, function () {
            // All queries filtered by school_id
            $report = new AttendanceReport();
            $report->generate();
        });
    }
}
```

---

## Emergency Bypass (Use with Caution!)

For system-wide operations that need ALL data:

```php
use App\Scopes\SchoolScope;

// ⚠️ DANGEROUS - Logs warning and stack trace
$allSchoolsData = SchoolScope::withoutScope(function () {
    return Attendance::all();
});
```

This logs:
```json
{
  "level": "WARNING",
  "message": "SchoolScope COMPLETELY DISABLED",
  "context": {
    "trace": [/* stack trace */]
  }
}
```

---

## Safety Benefits

### 1. Prevents Data Leaks

```php
// Developer makes a mistake - forgets school filter
$attendances = Attendance::where('date', today())->get();

// WITHOUT SchoolScope: Returns ALL schools' data! ❌
// WITH SchoolScope: Auto-filtered to user's school ✅
```

### 2. Defense in Depth

Even without explicit checks, data is isolated:

```php
// Policy check forgotten, but data still safe
public function index()
{
    // SchoolScope automatically adds school_id filter
    return Attendance::all(); // Only user's school data
}
```

### 3. Audit Trail

All bypass scenarios are logged:

```json
{
  "level": "INFO",
  "message": "SchoolScope bypassed",
  "context": {
    "reason": "super_admin",
    "user_id": 1,
    "email": "admin@system.com",
    "model": "App\\Models\\Attendance",
    "table": "attendances"
  }
}
```

### 4. Zero Developer Effort

Once a model uses `BelongsToSchool`, isolation is automatic:

```php
// All these are automatically filtered
Attendance::all();
Attendance::where('status', 'present')->get();
Attendance::find(123); // Returns null if wrong school
Attendance::paginate(15);
```

---

## Disabling for Specific Queries

Use Laravel's `withoutGlobalScope()`:

```php
use App\Scopes\SchoolScope;

// Query without school filter
$allAttendances = Attendance::withoutGlobalScope(SchoolScope::class)->get();

// Or without all global scopes
$allAttendances = Attendance::withoutGlobalScopes()->get();
```

---

## Testing

### Test Isolation

```php
public function test_user_only_sees_own_school_data()
{
    $school1 = School::factory()->create();
    $school2 = School::factory()->create();
    
    $user1 = User::factory()->create(['school_id' => $school1->id]);
    
    Attendance::factory()->count(5)->create(['school_id' => $school1->id]);
    Attendance::factory()->count(3)->create(['school_id' => $school2->id]);
    
    $this->actingAs($user1);
    
    // Should only see school1's attendances
    $this->assertCount(5, Attendance::all());
}

public function test_super_admin_sees_all_schools()
{
    $superAdmin = User::factory()->create([
        'role_type' => 'super_admin',
    ]);
    
    Attendance::factory()->count(5)->create(['school_id' => 1]);
    Attendance::factory()->count(3)->create(['school_id' => 2]);
    
    $this->actingAs($superAdmin);
    
    // Should see all attendances
    $this->assertCount(8, Attendance::all());
}

public function test_cli_context_bypasses_scope()
{
    Attendance::factory()->count(5)->create(['school_id' => 1]);
    Attendance::factory()->count(3)->create(['school_id' => 2]);
    
    // In CLI (no auth), should see all
    $this->assertCount(8, Attendance::all());
}
```

---

## Models Using SchoolScope

All models with multi-tenant data should use the `BelongsToSchool` trait:

- `Attendance`
- `Schedule`
- `SchoolClass`
- `Subject`
- `User` (students, teachers)
- `QrCode`
- `Report`
- etc.

---

## Security Log Examples

### Super Admin Access

```json
{
  "message": "SchoolScope bypassed",
  "context": {
    "reason": "super_admin",
    "user_id": 1,
    "email": "admin@platform.com",
    "model": "App\\Models\\Attendance",
    "table": "attendances",
    "timestamp": "2026-01-31T12:30:00+07:00"
  }
}
```

### User Without School (Security Warning)

```json
{
  "level": "WARNING",
  "message": "User without school_id accessing school-scoped model",
  "context": {
    "user_id": 999,
    "model": "App\\Models\\Attendance",
    "role_type": "unknown"
  }
}
```

### Scope Completely Disabled (Critical)

```json
{
  "level": "WARNING",
  "message": "SchoolScope COMPLETELY DISABLED",
  "context": {
    "trace": [
      "App\\Console\\Commands\\MigrateData->handle()",
      "Illuminate\\Container\\BoundMethod->call()"
    ]
  }
}
```
