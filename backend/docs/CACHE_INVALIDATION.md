# Event-Based Cache Invalidation for Real-Time Dashboard

## 🔄 Overview
Dokumen ini menjelaskan implementasi event-based cache invalidation untuk memastikan dashboard menampilkan data real-time tanpa menunggu cache expiration.

## ❌ Masalah: Stale Cache Data

### Skenario
```
09:00 - Dashboard di-load, data di-cache untuk 5 menit
09:01 - Siswa A melakukan absensi
09:02 - Admin refresh dashboard
       ❌ Data siswa A TIDAK MUNCUL (cache masih valid sampai 09:05)
09:05 - Cache expired
09:06 - Admin refresh dashboard
       ✅ Data siswa A MUNCUL
```

**Masalah**:
- ❌ Data tidak real-time (delay up to 5 minutes)
- ❌ User experience buruk (harus tunggu atau force refresh)
- ❌ Tidak ada notifikasi data baru
- ❌ Dashboard terlihat "stuck"

---

## ✅ Solusi: Event-Based Cache Invalidation

### Arsitektur

```
┌─────────────────────────────────────────────────────────┐
│              Student Scans QR Code                      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│          AttendanceController::scan()                   │
│  1. Validate QR token                                   │
│  2. Process attendance                                  │
│  3. Dispatch StudentAttended event                      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│            StudentAttended Event                        │
│  - Contains: attendance, schoolId                       │
│  - Broadcast to: school.{id} channel                    │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│      InvalidateDashboardCache Listener                  │
│  1. Check cache driver (Redis/Memcached/File)           │
│  2. Flush cache tags OR individual keys                 │
│  3. Log invalidation for monitoring                     │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│              Cache Invalidated                          │
│  - Next dashboard request will fetch fresh data         │
│  - Real-time update achieved                            │
└─────────────────────────────────────────────────────────┘
```

---

## 🔧 Implementasi

### 1. Event: StudentAttended

**File**: `app/Events/StudentAttended.php`

```php
class StudentAttended implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public $attendance,
        public $schoolId
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('school.'.$this->schoolId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->attendance->id,
            'student_id' => $this->attendance->student_id,
            'status' => $this->attendance->status,
            'check_in_time' => $this->attendance->check_in_time,
            'student_name' => $this->attendance->student->name ?? 'Unknown',
            'class_name' => $this->attendance->schedule->class->name ?? 'Unknown',
        ];
    }
}
```

---

### 2. Listener: InvalidateDashboardCache

**File**: `app/Listeners/InvalidateDashboardCache.php`

```php
class InvalidateDashboardCache
{
    public function handle(StudentAttended $event): void
    {
        $schoolId = $event->schoolId;

        if ($this->supportsTags()) {
            // RECOMMENDED: Use cache tags (Redis/Memcached)
            Cache::tags(['dashboard', "school_{$schoolId}"])->flush();
            Cache::tags(['metrics', "school_{$schoolId}"])->flush();
            Cache::tags(['attendance_summary', "school_{$schoolId}"])->flush();
        } else {
            // FALLBACK: Use individual keys (File/Database cache)
            Cache::forget("dashboard_metrics_school_{$schoolId}");
            Cache::forget("attendance_summary_school_{$schoolId}_today");
            Cache::forget("recent_attendances_school_{$schoolId}");
        }

        Log::info('Dashboard cache invalidated', [
            'school_id' => $schoolId,
            'attendance_id' => $event->attendance->id,
        ]);
    }

    protected function supportsTags(): bool
    {
        return in_array(config('cache.default'), ['redis', 'memcached']);
    }
}
```

---

### 3. Register Listener

**File**: `app/Providers/EventServiceProvider.php`

```php
protected $listen = [
    \App\Events\StudentAttended::class => [
        \App\Listeners\InvalidateDashboardCache::class,
    ],
];
```

---

### 4. Trait: UsesCacheTags

**File**: `app/Traits/UsesCacheTags.php`

Helper trait untuk menggunakan cache tags dengan mudah:

```php
trait UsesCacheTags
{
    /**
     * Remember data in cache with tags
     */
    protected function cacheWithTags($tags, string $key, int $ttl, callable $callback)
    {
        $tags = is_array($tags) ? $tags : [$tags];
        
        if ($this->supportsCacheTags()) {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        } else {
            $prefixedKey = $this->getPrefixedKey($tags, $key);
            return Cache::remember($prefixedKey, $ttl, $callback);
        }
    }

    /**
     * Flush cache by tags
     */
    protected function flushCacheTags($tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];
        
        if ($this->supportsCacheTags()) {
            return Cache::tags($tags)->flush();
        }
        
        return false;
    }
}
```

---

### 5. Usage in Controller

**File**: `app/Http/Controllers/Api/V1/DashboardController.php`

```php
class DashboardController extends Controller
{
    use UsesCacheTags;

    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;

        // Cache with tags
        $metrics = $this->cacheWithTags(
            ['dashboard', "school_{$schoolId}"],  // Tags
            "dashboard_metrics_{$schoolId}",       // Key
            300,                                    // TTL (5 minutes)
            function () use ($schoolId) {
                return $this->fetchDashboardMetrics($schoolId);
            }
        );

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    protected function fetchDashboardMetrics(int $schoolId): array
    {
        return [
            'total_students' => User::where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->count(),
            'today_attendance' => Attendance::where('school_id', $schoolId)
                ->where('attendance_date', today())
                ->count(),
            // ... more metrics
        ];
    }
}
```

---

## 📊 Cache Tags Strategy

### Tag Hierarchy

```
dashboard                    (All dashboard data)
├── school_1                 (School 1 specific)
│   ├── dashboard_metrics_1
│   ├── recent_attendances_1
│   └── attendance_summary_1_today
├── school_2                 (School 2 specific)
│   └── ...
└── ...

metrics                      (All metrics data)
├── school_1
│   └── dashboard_metrics_1
└── ...

attendance_summary           (All attendance summaries)
├── school_1
│   ├── attendance_summary_1_today
│   ├── attendance_summary_1_week
│   └── attendance_summary_1_month
└── ...
```

### Invalidation Strategy

| Event | Tags to Flush | Reason |
|-------|---------------|--------|
| `StudentAttended` | `['dashboard', 'school_{id}']` | New attendance affects all dashboard metrics |
| `StudentAttended` | `['metrics', 'school_{id}']` | Metrics need recalculation |
| `StudentAttended` | `['attendance_summary', 'school_{id}']` | Summary counts changed |
| `AcademicYearActivated` | `['dashboard', 'school_{id}']` | All data may be affected |

---

## 🎯 Cache Driver Comparison

### Redis (Recommended ✅)

```env
CACHE_DRIVER=redis
REDIS_CLIENT=phpredis
```

**Pros**:
- ✅ Native tag support
- ✅ Atomic operations
- ✅ Fast invalidation
- ✅ Efficient memory usage
- ✅ Production-ready

**Cons**:
- ⚠️ Requires Redis server

**Usage**:
```php
// Efficient tag-based invalidation
Cache::tags(['dashboard', 'school_1'])->flush();
```

---

### Memcached

```env
CACHE_DRIVER=memcached
```

**Pros**:
- ✅ Tag support
- ✅ Fast performance
- ✅ Distributed caching

**Cons**:
- ⚠️ Requires Memcached server
- ⚠️ Less feature-rich than Redis

---

### File/Database (Development Only ⚠️)

```env
CACHE_DRIVER=file
# or
CACHE_DRIVER=database
```

**Pros**:
- ✅ No external dependencies
- ✅ Easy setup

**Cons**:
- ❌ No native tag support
- ❌ Slower performance
- ❌ Manual key management
- ❌ Not recommended for production

**Fallback Strategy**:
```php
// Must invalidate individual keys
Cache::forget("dashboard_metrics_school_1");
Cache::forget("attendance_summary_school_1_today");
Cache::forget("recent_attendances_school_1");
```

---

## 🧪 Testing

### Test Cache Invalidation

```php
public function test_cache_invalidated_on_student_attended()
{
    $school = School::factory()->create();
    $student = User::factory()->create(['school_id' => $school->id]);
    
    // Cache some data
    Cache::tags(['dashboard', "school_{$school->id}"])
        ->put('dashboard_metrics_'.$school->id, ['total' => 100], 300);
    
    // Verify cache exists
    $this->assertNotNull(
        Cache::tags(['dashboard', "school_{$school->id}"])
            ->get('dashboard_metrics_'.$school->id)
    );
    
    // Dispatch event
    $attendance = Attendance::factory()->create([
        'school_id' => $school->id,
        'student_id' => $student->id,
    ]);
    
    StudentAttended::dispatch($attendance, $school->id);
    
    // Verify cache is cleared
    $this->assertNull(
        Cache::tags(['dashboard', "school_{$school->id}"])
            ->get('dashboard_metrics_'.$school->id)
    );
}
```

---

## 📈 Performance Impact

### Before (Without Event-Based Invalidation)

```
Request 1 (09:00): Cache MISS → Query DB → Cache for 5 min
Request 2 (09:01): Cache HIT  → Return cached (stale after new attendance)
Request 3 (09:02): Cache HIT  → Return cached (stale)
Request 4 (09:03): Cache HIT  → Return cached (stale)
Request 5 (09:04): Cache HIT  → Return cached (stale)
Request 6 (09:05): Cache MISS → Query DB → Cache for 5 min

Data Freshness: ❌ Up to 5 minutes stale
Cache Hit Rate: 80% (4/5 hits)
```

### After (With Event-Based Invalidation)

```
Request 1 (09:00): Cache MISS → Query DB → Cache for 5 min
[09:01 - Student attendance → Cache INVALIDATED]
Request 2 (09:01): Cache MISS → Query DB → Cache for 5 min
Request 3 (09:02): Cache HIT  → Return cached (fresh)
Request 4 (09:03): Cache HIT  → Return cached (fresh)
[09:04 - Student attendance → Cache INVALIDATED]
Request 5 (09:04): Cache MISS → Query DB → Cache for 5 min

Data Freshness: ✅ Always fresh (invalidated on change)
Cache Hit Rate: 60% (2/5 hits) - Lower but data is always fresh
```

**Trade-off**:
- ✅ Always fresh data
- ⚠️ Slightly lower cache hit rate
- ✅ Better user experience
- ✅ Real-time updates

---

## 🔍 Monitoring

### Log Analysis

```bash
# Check cache invalidations
tail -f storage/logs/laravel.log | grep "Dashboard cache invalidated"

# Output:
[2026-01-27 09:01:23] local.INFO: Dashboard cache invalidated {"school_id":1,"attendance_id":123}
[2026-01-27 09:04:15] local.INFO: Dashboard cache invalidated {"school_id":1,"attendance_id":124}
```

### Redis Monitoring

```bash
# Monitor cache keys
redis-cli KEYS "laravel_cache:*dashboard*"

# Monitor tag operations
redis-cli MONITOR | grep "dashboard"

# Check memory usage
redis-cli INFO memory
```

---

## 📝 Best Practices

### DO ✅

1. **Use Redis in Production**
   ```env
   CACHE_DRIVER=redis
   ```

2. **Use Descriptive Tags**
   ```php
   Cache::tags(['dashboard', 'school_1', 'metrics'])
   ```

3. **Log Invalidations**
   ```php
   Log::info('Cache invalidated', ['tags' => $tags]);
   ```

4. **Set Appropriate TTL**
   ```php
   // Still set TTL as fallback
   Cache::tags($tags)->remember($key, 300, $callback);
   ```

5. **Test Cache Invalidation**
   ```php
   public function test_cache_invalidated_on_event() { ... }
   ```

### DON'T ❌

1. ❌ Don't use File cache in production
2. ❌ Don't forget to register listeners
3. ❌ Don't invalidate too frequently (performance impact)
4. ❌ Don't use too many tags (complexity)
5. ❌ Don't forget to handle non-tag drivers

---

## 🔗 Related Files

- `app/Events/StudentAttended.php` - Event definition
- `app/Listeners/InvalidateDashboardCache.php` - Cache invalidation listener
- `app/Traits/UsesCacheTags.php` - Helper trait for cache tags
- `app/Providers/EventServiceProvider.php` - Event registration
- `app/Http/Controllers/Api/V1/DashboardController.example.php` - Usage example

---

## 📅 Changelog

### 2026-01-27
- ✅ Created InvalidateDashboardCache listener
- ✅ Registered listener for StudentAttended event
- ✅ Created UsesCacheTags trait for easy cache tag usage
- ✅ Created DashboardController example
- ✅ Added support for both tag-based and key-based invalidation
- ✅ Added comprehensive logging for monitoring
