# SaaS Hardening 30-Day Roadmap - Design Document

## 🎯 Design Principles

1. **Risk-First Approach**: Fix critical bugs before optimization
2. **Backward Compatible**: No breaking changes to API
3. **Production Safe**: Deploy incrementally with rollback plans
4. **Test-Driven**: Every fix has automated tests
5. **Observable**: Add monitoring before removing safety nets

---

## 📅 WEEK 1: DATA INTEGRITY & TENANT SAFETY

### Day 1: Timezone Consistency (Monday)

**Morning (4h): Audit & Design**

```bash
# 1. Search for timezone issues
grep -r "date(" app/ | grep -v "update"
grep -r "time()" app/
grep -r "strtotime" app/

# 2. Identify patterns
# - Controllers using date()
# - Services using time()
# - Reports using strtotime()
```

**Design Decision**:
```php
// Create TimezoneHelper utility
class TimezoneHelper {
    public static function now(?string $timezone = null): Carbon {
        return now($timezone ?? config('app.timezone'));
    }
    
    public static function schoolNow(School $school): Carbon {
        return now($school->timezone ?? config('app.timezone'));
    }
    
    public static function parse(string $date, ?string $timezone = null): Carbon {
        return Carbon::parse($date, $timezone ?? config('app.timezone'));
    }
}
```

**Afternoon (2h): Implementation**

```php
// Replace patterns:
// ❌ date('Y-m-d')
// ✅ TimezoneHelper::now()->toDateString()

// ❌ Carbon::now()
// ✅ TimezoneHelper::schoolNow($school)

// ❌ whereDate('created_at', today())
// ✅ whereDate('created_at', TimezoneHelper::now()->toDateString())
```

**Files to Update**:
- `app/Services/SecureAttendanceService.php`
- `app/Http/Controllers/Api/V1/Teacher/QRGeneratorController.php`
- `app/Http/Controllers/Api/V1/Admin/AttendanceReportController.php`
- `app/Jobs/ExportAttendanceReport.php`

**Tests (2h)**:
```php
// tests/Unit/TimezoneHelperTest.php
test('now returns correct timezone', function () {
    config(['app.timezone' => 'Asia/Jakarta']);
    $now = TimezoneHelper::now();
    expect($now->timezone->getName())->toBe('Asia/Jakarta');
});

test('schoolNow uses school timezone', function () {
    $school = School::factory()->create(['timezone' => 'Asia/Tokyo']);
    $now = TimezoneHelper::schoolNow($school);
    expect($now->timezone->getName())->toBe('Asia/Tokyo');
});
```

**Risk Reduction**: 🔴 8/10  
**Rollback**: `git revert <commit-hash>`

---

### Day 2: Unique Attendance Constraint (Tuesday)

**Morning (2h): Duplicate Detection**

```sql
-- Find existing duplicates
SELECT 
    student_id, 
    schedule_id, 
    attendance_date, 
    school_id,
    COUNT(*) as count
FROM attendances
GROUP BY student_id, schedule_id, attendance_date, school_id
HAVING count > 1;
```

**Design Decision**:
```php
// Migration strategy:
// 1. Keep oldest record
// 2. Soft delete duplicates
// 3. Add unique constraint
// 4. Log cleanup for audit
```

**Afternoon (2h): Migration**

```php
// database/migrations/2026_02_10_000001_add_unique_attendance_constraint.php
public function up() {
    // Step 1: Cleanup duplicates
    DB::statement("
        DELETE a1 FROM attendances a1
        INNER JOIN attendances a2 
        WHERE a1.id > a2.id
        AND a1.student_id = a2.student_id
        AND a1.schedule_id = a2.schedule_id
        AND a1.attendance_date = a2.attendance_date
        AND a1.school_id = a2.school_id
    ");
    
    // Step 2: Add unique constraint
    Schema::table('attendances', function (Blueprint $table) {
        $table->unique(
            ['student_id', 'schedule_id', 'attendance_date', 'school_id'],
            'unique_attendance_per_day'
        );
    });
}

public function down() {
    Schema::table('attendances', function (Blueprint $table) {
        $table->dropUnique('unique_attendance_per_day');
    });
}
```

**Code Update**:
```php
// Ensure all code uses firstOrCreate
$attendance = Attendance::firstOrCreate(
    [
        'student_id' => $student->id,
        'schedule_id' => $scheduleId,
        'attendance_date' => $date,
        'school_id' => $schoolId,
    ],
    [
        'status' => $status,
        'check_in_time' => now(),
        // ... other fields
    ]
);
```

**Risk Reduction**: 🔴 10/10  
**Rollback**: `php artisan migrate:rollback --step=1`

---

### Day 3: Queue Job Tenant Context (Wednesday)

**Morning (4h): Audit & Design**

```bash
# Find all queue jobs
find app/Jobs -name "*.php" -exec grep -l "implements ShouldQueue" {} \;

# Check for school_id usage
grep -r "school_id" app/Jobs/
```

**Design Decision**:
```php
// Create base class for tenant-aware jobs
abstract class TenantAwareJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected int $schoolId;
    
    public function __construct(int $schoolId) {
        $this->schoolId = $schoolId;
    }
    
    protected function forSchool(): Builder {
        return DB::table('attendances')->where('school_id', $this->schoolId);
    }
}
```

**Afternoon (4h): Implementation**

```php
// Update ExportAttendanceReport
class ExportAttendanceReport extends TenantAwareJob {
    protected $userId;
    protected $reportType;
    protected $params;
    
    public function __construct(int $userId, int $schoolId, string $reportType, array $params) {
        parent::__construct($schoolId);
        $this->userId = $userId;
        $this->reportType = $reportType;
        $this->params = $params;
    }
    
    public function handle(): void {
        // ✅ Force school_id filter
        $this->params['school_id'] = $this->schoolId;
        
        $export = new AttendanceReportExport($this->reportType, $this->params);
        // ...
    }
}

// Update all dispatchers
ExportAttendanceReport::dispatch(
    $user->id,
    $user->school_id,  // ✅ Pass school_id
    $reportType,
    $params
);
```

**Files to Update**:
- `app/Jobs/ExportAttendanceReport.php`
- `app/Jobs/SendAttendanceNotification.php`
- `app/Jobs/GenerateMonthlyReport.php`
- All controllers that dispatch jobs

**Risk Reduction**: 🔴 10/10  
**Rollback**: `git revert <commit-hash>`

---

### Day 4: State Machine Enforcement (Thursday)

**Morning (3h): Block Direct Modification**

```php
// app/Models/Attendance.php
public function setStatusAttribute($value): void {
    // ✅ ALWAYS block direct modification
    throw StateViolationException::directModificationBlocked(
        'status',
        'Use state machine methods: checkIn(), checkOut(), approve(), reject()'
    );
}

public function setStateAttribute($value): void {
    // ✅ ALWAYS block direct modification
    if (!$this->isInternalStateChange) {
        throw StateViolationException::directModificationBlocked(
            'state',
            'Use state machine methods only'
        );
    }
    
    $this->attributes['state'] = $value instanceof AttendanceState 
        ? $value->value 
        : $value;
}
```

**Afternoon (3h): Update Seeders/Factories**

```php
// database/seeders/AttendanceSeeder.php
// ❌ OLD
Attendance::create([
    'status' => 'present',
    'state' => 'checked_in',
]);

// ✅ NEW
$attendance = Attendance::create([
    'student_id' => $student->id,
    'schedule_id' => $schedule->id,
    'attendance_date' => today(),
    'school_id' => $school->id,
]);
$attendance->checkIn($teacher->id, $lat, $lng, $deviceId);
```

**Files to Update**:
- `database/seeders/AttendanceSeeder.php`
- `database/factories/AttendanceFactory.php`
- All test files creating attendance

**Risk Reduction**: 🔴 9/10  
**Rollback**: Allow status during creation

---

### Day 5: DB::table() Elimination (Friday)

**Morning (2h): Audit**

```bash
# Find all DB::table usage
grep -r "DB::table(" app/ --exclude-dir=vendor

# Expected results:
# - Migrations (OK)
# - Some raw queries (need review)
```

**Afternoon (2h): Replace with Eloquent**

```php
// ❌ BAD
$count = DB::table('attendances')
    ->where('student_id', $studentId)
    ->count();

// ✅ GOOD
$count = Attendance::where('student_id', $studentId)->count();

// ❌ BAD
DB::table('attendances')->insert([...]);

// ✅ GOOD
Attendance::create([...]);
```

**Add PHPStan Rule**:
```neon
# phpstan.neon
parameters:
    ignoreErrors:
        - '#Call to static method table\(\) on class Illuminate\\Support\\Facades\\DB#'
    
    excludePaths:
        - database/migrations/*
```

**Risk Reduction**: 🔴 8/10  
**Rollback**: `git revert <commit-hash>`

---

## 📅 WEEK 2: CONCURRENCY & WEBHOOK HARDENING

### Day 6: Deadlock Retry (Monday)

**Design**:
```php
// app/Http/Middleware/DeadlockRetryMiddleware.php
class DeadlockRetryMiddleware {
    private const MAX_RETRIES = 3;
    private const BASE_DELAY_MS = 100;
    
    public function handle(Request $request, Closure $next) {
        $attempt = 0;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                return $next($request);
            } catch (DeadlockException $e) {
                $attempt++;
                
                if ($attempt >= self::MAX_RETRIES) {
                    throw $e;
                }
                
                // Exponential backoff: 100ms, 200ms, 400ms
                $delay = self::BASE_DELAY_MS * pow(2, $attempt - 1);
                usleep($delay * 1000);
                
                Log::warning('Deadlock detected, retrying', [
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                ]);
            }
        }
    }
}
```

**Risk Reduction**: 🟠 6/10  
**Effort**: 5 hours

---

### Day 7: Redis Health Guard (Tuesday)

**Design**:
```php
// config/cache.php
'default' => env('CACHE_STORE', 'failover'),

'stores' => [
    'failover' => [
        'driver' => 'failover',
        'stores' => ['redis', 'database', 'array'],
    ],
],

// app/Http/Controllers/HealthController.php
public function redis() {
    try {
        Cache::store('redis')->put('health_check', 'ok', 10);
        Cache::store('redis')->get('health_check');
        
        return response()->json([
            'status' => 'healthy',
            'redis' => 'connected',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'redis' => 'disconnected',
            'error' => $e->getMessage(),
        ], 503);
    }
}
```

**Risk Reduction**: 🔴 9/10  
**Effort**: 6 hours

---

### Day 8: Webhook Idempotency (Wednesday)

**Design**:
```php
// Increase lock timeout
$lock = Cache::lock($lockKey, 300);  // 5 minutes

// Add processing status
ProcessedWebhook::markAsProcessing($orderId);

if (!$lock->block(60)) {
    if (ProcessedWebhook::isProcessing($orderId)) {
        return response()->json(['success' => true, 'processing' => true]);
    }
    return response()->json(['message' => 'Retry later'], 429);
}
```

**Risk Reduction**: 🔴 10/10  
**Effort**: 4 hours

---

### Day 9: Cache Stampede (Thursday)

**Design**:
```php
public function getDashboardStats(int $schoolId): array {
    $cacheKey = "dashboard_stats:{$schoolId}";
    $lockKey = "lock:{$cacheKey}";
    
    // Try cache first
    $stats = Cache::get($cacheKey);
    if ($stats !== null) {
        return $stats;
    }
    
    // Acquire lock
    $lock = Cache::lock($lockKey, 10);
    
    if ($lock->get()) {
        try {
            // Double-check cache
            $stats = Cache::get($cacheKey);
            if ($stats !== null) {
                return $stats;
            }
            
            // Calculate and cache
            $stats = $this->calculateStats($schoolId);
            Cache::put($cacheKey, $stats, 300);
            
            return $stats;
        } finally {
            $lock->release();
        }
    }
    
    // Wait and retry
    sleep(1);
    return $this->getDashboardStats($schoolId);
}
```

**Risk Reduction**: 🟠 7/10  
**Effort**: 5 hours

---

### Day 10: Subscription Cache Fix (Friday)

**Design**:
```php
// Reduce TTL
$subscriptionStatus = Cache::remember($cacheKey, 60, function () { ... });

// Validate after cache hit
if ($subscriptionStatus['active']) {
    $expiresAt = Carbon::parse($subscriptionStatus['expires_at']);
    if (now()->greaterThan($expiresAt)) {
        Cache::forget($cacheKey);
        return response()->json(['error' => 'SUBSCRIPTION_EXPIRED'], 402);
    }
}

// Clear cache in webhook
private function applyPackageToSchool($schoolId, $packageId): void {
    $school->update([...]);
    CheckActiveSubscription::clearCache($schoolId);
}
```

**Risk Reduction**: 🔴 10/10  
**Effort**: 3 hours

---

## 📅 WEEK 3: PERFORMANCE OPTIMIZATION

### Day 11: Database Indexes (Monday)

**Analysis**:
```sql
-- Find slow queries
SELECT 
    query_time,
    sql_text
FROM mysql.slow_log
WHERE query_time > 0.1
ORDER BY query_time DESC
LIMIT 20;

-- Check index usage
EXPLAIN SELECT * FROM attendances 
WHERE school_id = 1 
AND attendance_date >= '2026-02-01';
```

**Migration**:
```php
Schema::table('attendances', function (Blueprint $table) {
    // Composite index for dashboard queries
    $table->index(['school_id', 'attendance_date', 'status'], 'idx_school_date_status');
    
    // Index for student queries
    $table->index(['student_id', 'attendance_date'], 'idx_student_date');
    
    // Index for schedule queries
    $table->index(['schedule_id', 'attendance_date'], 'idx_schedule_date');
});
```

**Risk Reduction**: 🟠 7/10  
**Effort**: 6 hours

---

### Day 12-15: Continue with remaining tasks...

(Similar detailed breakdown for each day)

---

## 📊 Daily Effort Breakdown

| Day | Task | Hours | Risk | Priority |
|-----|------|-------|------|----------|
| 1 | Timezone Consistency | 6 | 🔴 8/10 | P0 |
| 2 | Unique Constraint | 4 | 🔴 10/10 | P0 |
| 3 | Queue Tenant Context | 8 | 🔴 10/10 | P0 |
| 4 | State Machine | 6 | 🔴 9/10 | P0 |
| 5 | DB::table() Audit | 4 | 🔴 8/10 | P0 |
| 6 | Deadlock Retry | 5 | 🟠 6/10 | P1 |
| 7 | Redis Health | 6 | 🔴 9/10 | P0 |
| 8 | Webhook Idempotency | 4 | 🔴 10/10 | P0 |
| 9 | Cache Stampede | 5 | 🟠 7/10 | P1 |
| 10 | Subscription Cache | 3 | 🔴 10/10 | P0 |
| 11 | Database Indexes | 6 | 🟠 7/10 | P1 |
| 12 | N+1 Elimination | 8 | 🟠 6/10 | P1 |
| 13 | Summary Table | 8 | 🟠 7/10 | P1 |
| 14 | Export Chunking | 4 | 🟠 6/10 | P1 |
| 15 | Query Profiling | 6 | 🟢 4/10 | P2 |
| 16-17 | Health Checks | 12 | 🟠 7/10 | P1 |
| 18 | Queue Monitoring | 6 | 🟠 6/10 | P1 |
| 19 | Redis Monitoring | 5 | 🟠 6/10 | P1 |
| 20 | Disk Monitoring | 4 | 🟠 6/10 | P1 |
| 21-22 | Backup Testing | 12 | 🔴 9/10 | P0 |
| 23 | Chaos Testing | 8 | 🟠 7/10 | P1 |

**Total**: 130 hours over 23 working days = ~5.7 hours/day

---

## 🔄 Rollback Strategy

Each sprint has a rollback plan:

**Week 1**: Database migrations can be rolled back, code changes reverted  
**Week 2**: Middleware can be disabled, config restored  
**Week 3**: Indexes can be dropped, queries restored  
**Week 4**: Monitoring is additive, no rollback needed

**Rollback Window**: 24 hours after each Friday deployment

