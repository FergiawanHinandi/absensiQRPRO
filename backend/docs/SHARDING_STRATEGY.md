# 🔀 School-based Sharding Strategy

**Distributed Systems Architect**  
**Date**: 2026-02-10  
**Purpose**: Horizontal scalability for multi-tenant SaaS

---

## 🎯 Objectives

Design school-based sharding strategy to achieve:
1. **Horizontal scalability** (handle 100M+ attendance records)
2. **Query performance** (<100ms for dashboard queries)
3. **Reduced lock contention** (isolated per shard)
4. **Safe migration** (zero downtime, zero data loss)
5. **Cost efficiency** (pay for what you use)

---

## 📊 Current State vs Target State

### Current: Single Database (Multi-Tenant)

```
┌─────────────────────────────────────────────────────────────┐
│                    SINGLE DATABASE                           │
│                                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │  attendances table (10M+ rows)                     │    │
│  │  - school_id (indexed)                             │    │
│  │  - student_id                                      │    │
│  │  - attendance_date                                 │    │
│  │  - ...                                             │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  Problems:                                                   │
│  - ❌ Slow queries (full table scan)                        │
│  - ❌ Heavy indexes (GB of index data)                      │
│  - ❌ Lock contention (schools compete)                     │
│  - ❌ Limited scalability (vertical only)                   │
└─────────────────────────────────────────────────────────────┘
```

### Target: Sharded by School ID

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          ROUTING LAYER                                       │
│                       (TenantResolver)                                       │
│                                                                              │
│  school_id → Determine Shard → Route to Connection                          │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               │
         ┌─────────────────────┼─────────────────────┬─────────────────────┐
         │                     │                     │                     │
         ▼                     ▼                     ▼                     ▼
┌────────────────┐   ┌────────────────┐   ┌────────────────┐   ┌────────────────┐
│   SHARD 1      │   │   SHARD 2      │   │   SHARD 3      │   │   SHARD N      │
│                │   │                │   │                │   │                │
│ school_id      │   │ school_id      │   │ school_id      │   │ school_id      │
│ 1-1000         │   │ 1001-2000      │   │ 2001-3000      │   │ N-M            │
│                │   │                │   │                │   │                │
│ ~2M rows       │   │ ~2M rows       │   │ ~2M rows       │   │ ~2M rows       │
└────────────────┘   └────────────────┘   └────────────────┘   └────────────────┘

Benefits:
- ✅ Fast queries (smaller dataset per shard)
- ✅ Smaller indexes (per shard)
- ✅ No lock contention (isolated)
- ✅ Horizontal scalability (add shards)
```

---

## 🗺️ Sharding Strategy

### Range-based Sharding by School ID

**Why Range-based?**
- ✅ **Simple routing logic** - Easy to determine shard
- ✅ **Predictable distribution** - Know which shard has which schools
- ✅ **Easy migration** - Move schools between shards
- ✅ **Sequential allocation** - New schools go to latest shard

**Trade-offs**:
- ⚠️ **Uneven distribution** - Some shards may be busier
- ⚠️ **Rebalancing needed** - As schools grow

**Mitigation**:
- Monitor shard size and activity
- Rebalance when shard reaches 80% capacity
- Use virtual shards for finer control

---

### Shard Mapping

```
┌─────────────────────────────────────────────────────────────┐
│                    SHARD MAPPING TABLE                       │
│                    (Global Database)                         │
└─────────────────────────────────────────────────────────────┘

| shard_id | shard_name      | school_id_min | school_id_max | status  |
|----------|-----------------|---------------|---------------|---------|
| 1        | mysql_shard_1   | 1             | 1000          | active  |
| 2        | mysql_shard_2   | 1001          | 2000          | active  |
| 3        | mysql_shard_3   | 2001          | 3000          | active  |
| 4        | mysql_shard_4   | 3001          | 4000          | active  |
```

---

## 🔧 Implementation

### 1. Tenant Resolver Service

**File**: `app/Services/TenantResolver.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tenant Resolver
 * 
 * Determines which database shard to use for a given school
 * 
 * @package App\Services
 */
class TenantResolver
{
    /**
     * Shard mapping cache key
     */
    private const CACHE_KEY = 'shard_mapping';

    /**
     * Cache TTL (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Resolve database connection for a school
     * 
     * @param int $schoolId
     * @return string Connection name
     */
    public static function resolveConnection(int $schoolId): string
    {
        // Get shard mapping
        $mapping = self::getShardMapping();

        // Find appropriate shard
        foreach ($mapping as $shard) {
            if ($schoolId >= $shard['school_id_min'] 
                && $schoolId <= $shard['school_id_max']
                && $shard['status'] === 'active') {
                
                Log::debug('Resolved shard for school', [
                    'school_id' => $schoolId,
                    'shard' => $shard['shard_name'],
                ]);

                return $shard['shard_name'];
            }
        }

        // Fallback to default (should not happen)
        Log::warning('No shard found for school, using default', [
            'school_id' => $schoolId,
        ]);

        return 'mysql';
    }

    /**
     * Get shard mapping from cache or database
     * 
     * @return array
     */
    private static function getShardMapping(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return DB::connection('mysql_global')
                ->table('shard_mapping')
                ->orderBy('school_id_min')
                ->get()
                ->toArray();
        });
    }

    /**
     * Get shard for a specific school
     * 
     * @param int $schoolId
     * @return array|null
     */
    public static function getShardInfo(int $schoolId): ?array
    {
        $mapping = self::getShardMapping();

        foreach ($mapping as $shard) {
            if ($schoolId >= $shard['school_id_min'] 
                && $schoolId <= $shard['school_id_max']) {
                return (array) $shard;
            }
        }

        return null;
    }

    /**
     * Assign new school to appropriate shard
     * 
     * @param int $schoolId
     * @return string Shard name
     */
    public static function assignShard(int $schoolId): string
    {
        $mapping = self::getShardMapping();

        // Find shard with capacity
        foreach (array_reverse($mapping) as $shard) {
            if ($shard['status'] === 'active' 
                && $schoolId <= $shard['school_id_max']) {
                
                Log::info('Assigned school to shard', [
                    'school_id' => $schoolId,
                    'shard' => $shard['shard_name'],
                ]);

                return $shard['shard_name'];
            }
        }

        // If no shard found, create new one
        Log::warning('No shard available, need to create new shard', [
            'school_id' => $schoolId,
        ]);

        return 'mysql'; // Fallback
    }

    /**
     * Clear shard mapping cache
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get all shards
     * 
     * @return array
     */
    public static function getAllShards(): array
    {
        return self::getShardMapping();
    }

    /**
     * Get shard statistics
     * 
     * @return array
     */
    public static function getShardStatistics(): array
    {
        $mapping = self::getShardMapping();
        $stats = [];

        foreach ($mapping as $shard) {
            $connection = $shard['shard_name'];

            try {
                // Get row count
                $attendanceCount = DB::connection($connection)
                    ->table('attendances')
                    ->count();

                $schoolCount = DB::connection($connection)
                    ->table('schools')
                    ->count();

                $stats[] = [
                    'shard_name' => $shard['shard_name'],
                    'school_id_range' => "{$shard['school_id_min']}-{$shard['school_id_max']}",
                    'school_count' => $schoolCount,
                    'attendance_count' => $attendanceCount,
                    'status' => $shard['status'],
                ];
            } catch (\Exception $e) {
                $stats[] = [
                    'shard_name' => $shard['shard_name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $stats;
    }
}
```

---

### 2. Database Configuration

**File**: `config/database.php`

```php
'connections' => [
    // Global database (shard mapping, subscriptions, users)
    'mysql_global' => [
        'driver' => 'mysql',
        'host' => env('DB_GLOBAL_HOST', '127.0.0.1'),
        'port' => env('DB_GLOBAL_PORT', '3306'),
        'database' => env('DB_GLOBAL_DATABASE', 'attendance_global'),
        'username' => env('DB_GLOBAL_USERNAME', 'root'),
        'password' => env('DB_GLOBAL_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ],

    // Shard 1 (school_id 1-1000)
    'mysql_shard_1' => [
        'driver' => 'mysql',
        'host' => env('DB_SHARD_1_HOST', '127.0.0.1'),
        'port' => env('DB_SHARD_1_PORT', '3306'),
        'database' => env('DB_SHARD_1_DATABASE', 'attendance_shard_1'),
        'username' => env('DB_SHARD_1_USERNAME', 'root'),
        'password' => env('DB_SHARD_1_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ],

    // Shard 2 (school_id 1001-2000)
    'mysql_shard_2' => [
        'driver' => 'mysql',
        'host' => env('DB_SHARD_2_HOST', '127.0.0.1'),
        'port' => env('DB_SHARD_2_PORT', '3306'),
        'database' => env('DB_SHARD_2_DATABASE', 'attendance_shard_2'),
        'username' => env('DB_SHARD_2_USERNAME', 'root'),
        'password' => env('DB_SHARD_2_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ],

    // Shard 3 (school_id 2001-3000)
    'mysql_shard_3' => [
        'driver' => 'mysql',
        'host' => env('DB_SHARD_3_HOST', '127.0.0.1'),
        'port' => env('DB_SHARD_3_PORT', '3306'),
        'database' => env('DB_SHARD_3_DATABASE', 'attendance_shard_3'),
        'username' => env('DB_SHARD_3_USERNAME', 'root'),
        'password' => env('DB_SHARD_3_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ],

    // Add more shards as needed...
],
```

**Environment Variables**:

```env
# Global Database
DB_GLOBAL_HOST=global.mysql.rds.amazonaws.com
DB_GLOBAL_PORT=3306
DB_GLOBAL_DATABASE=attendance_global
DB_GLOBAL_USERNAME=admin
DB_GLOBAL_PASSWORD=your-password

# Shard 1
DB_SHARD_1_HOST=shard1.mysql.rds.amazonaws.com
DB_SHARD_1_PORT=3306
DB_SHARD_1_DATABASE=attendance_shard_1
DB_SHARD_1_USERNAME=admin
DB_SHARD_1_PASSWORD=your-password

# Shard 2
DB_SHARD_2_HOST=shard2.mysql.rds.amazonaws.com
DB_SHARD_2_PORT=3306
DB_SHARD_2_DATABASE=attendance_shard_2
DB_SHARD_2_USERNAME=admin
DB_SHARD_2_PASSWORD=your-password

# Shard 3
DB_SHARD_3_HOST=shard3.mysql.rds.amazonaws.com
DB_SHARD_3_PORT=3306
DB_SHARD_3_DATABASE=attendance_shard_3
DB_SHARD_3_USERNAME=admin
DB_SHARD_3_PASSWORD=your-password
```

---

### 3. Model with Shard Awareness

**File**: `app/Models/Attendance.php`

```php
<?php

namespace App\Models;

use App\Services\TenantResolver;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $connection = 'mysql'; // Default

    /**
     * Set connection based on school_id
     * 
     * @param int $schoolId
     * @return self
     */
    public function onShard(int $schoolId): self
    {
        $connection = TenantResolver::resolveConnection($schoolId);
        $this->setConnection($connection);

        return $this;
    }

    /**
     * Scope to specific school (with shard routing)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $schoolId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSchool($query, int $schoolId)
    {
        // Resolve shard
        $connection = TenantResolver::resolveConnection($schoolId);
        
        // Switch connection
        $query->getModel()->setConnection($connection);

        // Filter by school_id
        return $query->where('school_id', $schoolId);
    }
}
```

**Usage**:

```php
// Create attendance (automatically routes to correct shard)
$attendance = new Attendance();
$attendance->onShard($schoolId);
$attendance->school_id = $schoolId;
$attendance->student_id = $studentId;
$attendance->save();

// Query attendance (automatically routes to correct shard)
$attendances = Attendance::forSchool($schoolId)
    ->where('attendance_date', today())
    ->get();
```

---

### 4. Service Layer with Shard Routing

**File**: `app/Application/Services/AttendanceApplicationService.php`

```php
public function checkIn(CheckInCommand $command): void
{
    // Resolve shard for school
    $connection = TenantResolver::resolveConnection($command->schoolId);

    // Execute on correct shard
    DB::connection($connection)->transaction(function () use ($command) {
        $attendance = new Attendance();
        $attendance->setConnection(TenantResolver::resolveConnection($command->schoolId));
        
        $attendance->school_id = $command->schoolId;
        $attendance->student_id = $command->studentId;
        $attendance->schedule_id = $command->scheduleId;
        $attendance->attendance_date = today();
        $attendance->status = 'present';
        $attendance->save();

        // Dispatch event
        event(new AttendanceRecorded($attendance));
    });
}
```

---

## 📊 Data Distribution

### Global Database

**Tables**:
- `users` - All users (global authentication)
- `subscriptions` - All subscriptions (billing)
- `shard_mapping` - Shard configuration
- `schools` - School metadata (which shard)

### Shard Databases

**Tables** (per shard):
- `attendances` - Attendance records for schools in this shard
- `students` - Students for schools in this shard
- `teachers` - Teachers for schools in this shard
- `schedules` - Schedules for schools in this shard
- `attendance_daily_summaries` - Summaries for schools in this shard

---

## 🚫 Implementation Rules

### 1. No Cross-Shard Transactions

```php
// ❌ BAD: Cross-shard transaction
DB::transaction(function () {
    // School 500 (Shard 1)
    Attendance::forSchool(500)->create([...]);
    
    // School 1500 (Shard 2) - Different shard!
    Attendance::forSchool(1500)->create([...]);
});

// ✅ GOOD: Separate transactions per shard
DB::connection('mysql_shard_1')->transaction(function () {
    Attendance::forSchool(500)->create([...]);
});

DB::connection('mysql_shard_2')->transaction(function () {
    Attendance::forSchool(1500)->create([...]);
});
```

---

### 2. No Cross-Shard Joins

```php
// ❌ BAD: Join across shards
$results = DB::table('attendances') // Shard 1
    ->join('students', 'students.id', '=', 'attendances.student_id') // Shard 2
    ->get();

// ✅ GOOD: Query each shard separately
$shard1Attendances = DB::connection('mysql_shard_1')
    ->table('attendances')
    ->where('school_id', 500)
    ->get();

$shard1Students = DB::connection('mysql_shard_1')
    ->table('students')
    ->whereIn('id', $shard1Attendances->pluck('student_id'))
    ->get();

// Merge in application layer
$results = $shard1Attendances->map(function ($attendance) use ($shard1Students) {
    $attendance->student = $shard1Students->firstWhere('id', $attendance->student_id);
    return $attendance;
});
```

---

### 3. Summary Per Shard

```php
// ✅ GOOD: Summary per shard
$shard1Summary = DB::connection('mysql_shard_1')
    ->table('attendance_daily_summaries')
    ->where('school_id', 500)
    ->where('attendance_date', today())
    ->first();

// For global summary, aggregate across shards
$globalSummary = collect();
foreach (TenantResolver::getAllShards() as $shard) {
    $shardSummary = DB::connection($shard['shard_name'])
        ->table('attendance_daily_summaries')
        ->where('attendance_date', today())
        ->sum('total_students');
    
    $globalSummary->push($shardSummary);
}

$totalStudents = $globalSummary->sum();
```

---

### 4. Subscription in Global DB

```php
// ✅ GOOD: Subscription always in global DB
$subscription = DB::connection('mysql_global')
    ->table('subscriptions')
    ->where('school_id', $schoolId)
    ->first();

// Attendance in shard DB
$attendances = Attendance::forSchool($schoolId)
    ->where('attendance_date', today())
    ->get();
```

---

## 🔄 Migration Strategy

### Phase 1: Keep Single DB (Months 1-2)

```
┌─────────────────────────────────────────────────────────────┐
│                    SINGLE DATABASE                           │
│                    (All schools)                             │
│                                                              │
│  - Continue normal operations                                │
│  - Prepare sharding infrastructure                           │
│  - Test shard routing logic                                  │
└─────────────────────────────────────────────────────────────┘
```

**Tasks**:
- [ ] Create global database
- [ ] Create shard mapping table
- [ ] Implement TenantResolver
- [ ] Test routing logic
- [ ] Create shard databases (empty)

---

### Phase 2: New Schools to New Shard (Months 3-4)

```
┌─────────────────────────────────────────────────────────────┐
│                    SINGLE DATABASE                           │
│                    (Existing schools 1-1000)                 │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    SHARD 2                                   │
│                    (New schools 1001+)                       │
└─────────────────────────────────────────────────────────────┘
```

**Tasks**:
- [ ] Update school creation to use shard routing
- [ ] New schools (1001+) go to Shard 2
- [ ] Existing schools stay in Shard 1
- [ ] Monitor performance

---

### Phase 3: Gradual Migration (Months 5-12)

```
┌─────────────────────────────────────────────────────────────┐
│                    SHARD 1                                   │
│                    (Schools 1-1000)                          │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    SHARD 2                                   │
│                    (Schools 1001-2000)                       │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    SHARD 3                                   │
│                    (Schools 2001-3000)                       │
└─────────────────────────────────────────────────────────────┘
```

**Tasks**:
- [ ] Migrate schools 1-1000 from single DB to Shard 1
- [ ] Verify data integrity
- [ ] Update shard mapping
- [ ] Decommission single DB

---

## 📝 Migration Safety Plan

### Pre-Migration Checklist

- [ ] **Backup all data** (full database dump)
- [ ] **Test migration script** on staging
- [ ] **Verify shard routing** works correctly
- [ ] **Prepare rollback plan**
- [ ] **Schedule maintenance window**
- [ ] **Notify customers** (if downtime required)

### Migration Script

**File**: `scripts/migrate-school-to-shard.php`

```php
<?php

use App\Services\TenantResolver;
use Illuminate\Support\Facades\DB;

/**
 * Migrate a school from one shard to another
 * 
 * @param int $schoolId
 * @param string $targetShard
 * @return void
 */
function migrateSchool(int $schoolId, string $targetShard): void
{
    echo "Migrating school {$schoolId} to {$targetShard}...\n";

    // 1. Get source shard
    $sourceShard = TenantResolver::resolveConnection($schoolId);
    echo "Source shard: {$sourceShard}\n";

    // 2. Start transaction on target shard
    DB::connection($targetShard)->beginTransaction();

    try {
        // 3. Copy school data
        $school = DB::connection($sourceShard)
            ->table('schools')
            ->where('id', $schoolId)
            ->first();

        DB::connection($targetShard)
            ->table('schools')
            ->insert((array) $school);

        // 4. Copy students
        $students = DB::connection($sourceShard)
            ->table('students')
            ->where('school_id', $schoolId)
            ->get();

        foreach ($students->chunk(1000) as $chunk) {
            DB::connection($targetShard)
                ->table('students')
                ->insert($chunk->toArray());
        }

        // 5. Copy attendances
        $attendances = DB::connection($sourceShard)
            ->table('attendances')
            ->where('school_id', $schoolId)
            ->get();

        foreach ($attendances->chunk(1000) as $chunk) {
            DB::connection($targetShard)
                ->table('attendances')
                ->insert($chunk->toArray());
        }

        // 6. Copy summaries
        $summaries = DB::connection($sourceShard)
            ->table('attendance_daily_summaries')
            ->where('school_id', $schoolId)
            ->get();

        foreach ($summaries->chunk(1000) as $chunk) {
            DB::connection($targetShard)
                ->table('attendance_daily_summaries')
                ->insert($chunk->toArray());
        }

        // 7. Verify counts match
        $sourceCount = DB::connection($sourceShard)
            ->table('attendances')
            ->where('school_id', $schoolId)
            ->count();

        $targetCount = DB::connection($targetShard)
            ->table('attendances')
            ->where('school_id', $schoolId)
            ->count();

        if ($sourceCount !== $targetCount) {
            throw new \Exception("Count mismatch: source={$sourceCount}, target={$targetCount}");
        }

        // 8. Commit transaction
        DB::connection($targetShard)->commit();

        // 9. Update shard mapping
        DB::connection('mysql_global')
            ->table('shard_mapping')
            ->where('school_id_min', '<=', $schoolId)
            ->where('school_id_max', '>=', $schoolId)
            ->update(['shard_name' => $targetShard]);

        // 10. Clear cache
        TenantResolver::clearCache();

        echo "✅ Migration successful!\n";

        // 11. Delete from source (optional, after verification)
        // DB::connection($sourceShard)
        //     ->table('attendances')
        //     ->where('school_id', $schoolId)
        //     ->delete();

    } catch (\Exception $e) {
        // Rollback on error
        DB::connection($targetShard)->rollBack();
        echo "❌ Migration failed: {$e->getMessage()}\n";
        throw $e;
    }
}

// Usage
migrateSchool(500, 'mysql_shard_1');
```

---

## 🔍 Cross-Shard Query Strategy

### 1. Scatter-Gather Pattern

```php
/**
 * Get global attendance summary across all shards
 * 
 * @param \Carbon\Carbon $date
 * @return array
 */
public function getGlobalSummary(\Carbon\Carbon $date): array
{
    $shards = TenantResolver::getAllShards();
    $results = [];

    // Scatter: Query each shard in parallel
    foreach ($shards as $shard) {
        $results[] = DB::connection($shard['shard_name'])
            ->table('attendance_daily_summaries')
            ->where('attendance_date', $date)
            ->selectRaw('SUM(total_students) as total, SUM(present) as present')
            ->first();
    }

    // Gather: Aggregate results
    $globalSummary = [
        'total_students' => collect($results)->sum('total'),
        'present' => collect($results)->sum('present'),
        'absent' => collect($results)->sum('total') - collect($results)->sum('present'),
    ];

    return $globalSummary;
}
```

---

### 2. Aggregation Service

```php
/**
 * Aggregation Service for cross-shard queries
 */
class AggregationService
{
    /**
     * Get top schools by attendance rate (across all shards)
     * 
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getTopSchools(int $limit = 10): Collection
    {
        $shards = TenantResolver::getAllShards();
        $allSchools = collect();

        // Query each shard
        foreach ($shards as $shard) {
            $schools = DB::connection($shard['shard_name'])
                ->table('attendance_daily_summaries')
                ->selectRaw('school_id, AVG(present / total_students * 100) as attendance_rate')
                ->groupBy('school_id')
                ->get();

            $allSchools = $allSchools->merge($schools);
        }

        // Sort and limit in application layer
        return $allSchools
            ->sortByDesc('attendance_rate')
            ->take($limit);
    }
}
```

---

**Status**: ✅ Ready for Implementation  
**Last Updated**: 2026-02-10  
**Version**: 1.0
