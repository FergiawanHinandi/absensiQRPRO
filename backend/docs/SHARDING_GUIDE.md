# 🔀 School-based Sharding Strategy - Complete Guide

**Distributed Systems Architect**  
**Date**: 2026-02-10  
**Status**: ✅ Ready for Implementation

---

## 📋 Executive Summary

Comprehensive school-based sharding strategy for Attendance SaaS to achieve:
- **Horizontal scalability** (100M+ attendance records)
- **Query performance** (<100ms dashboard queries)
- **Reduced lock contention** (isolated per shard)
- **Safe migration** (zero downtime, zero data loss)

---

## 🎯 Problem & Solution

### Current Problem (Single Database)

```
Single Database (10M+ rows)
├── Slow queries (full table scan)
├── Heavy indexes (GB of index data)
├── Lock contention (schools compete)
└── Limited scalability (vertical only)
```

### Solution (Sharded by School ID)

```
Shard 1 (2M rows)    Shard 2 (2M rows)    Shard 3 (2M rows)
school_id 1-1000     school_id 1001-2000  school_id 2001-3000
├── Fast queries     ├── Fast queries     ├── Fast queries
├── Small indexes    ├── Small indexes    ├── Small indexes
├── No contention    ├── No contention    ├── No contention
└── Isolated         └── Isolated         └── Isolated
```

---

## 🗺️ Sharding Strategy

### Range-based Sharding

| Shard | School ID Range | Capacity | Status |
|-------|----------------|----------|--------|
| Shard 1 | 1-1000 | 1000 schools | Active |
| Shard 2 | 1001-2000 | 1000 schools | Active |
| Shard 3 | 2001-3000 | 1000 schools | Active |
| Shard N | N-M | 1000 schools | Active |

**Routing Logic**:
```php
if (school_id <= 1000) → mysql_shard_1
else if (school_id <= 2000) → mysql_shard_2
else if (school_id <= 3000) → mysql_shard_3
```

---

## 🔧 Implementation

### 1. TenantResolver Service

**Automatic shard routing**:

```php
use App\Services\TenantResolver;

// Resolve connection for a school
$connection = TenantResolver::resolveConnection($schoolId);

// Get shard info
$info = TenantResolver::getShardInfo($schoolId);

// Get statistics
$stats = TenantResolver::getShardStatistics();
```

---

### 2. ShardAware Trait

**Add to models**:

```php
use App\Traits\ShardAware;

class Attendance extends Model
{
    use ShardAware;
}
```

**Usage**:

```php
// Create on correct shard (automatic)
$attendance = new Attendance();
$attendance->school_id = 500;
$attendance->save(); // Automatically routes to Shard 1

// Query with shard routing
$attendances = Attendance::forSchool(500)
    ->where('attendance_date', today())
    ->get();

// Manual shard selection
$attendance = new Attendance();
$attendance->onShard(500);
```

---

### 3. Database Configuration

**config/database.php**:

```php
'connections' => [
    // Global database
    'mysql_global' => [
        'host' => env('DB_GLOBAL_HOST'),
        'database' => 'attendance_global',
        // ...
    ],

    // Shard 1
    'mysql_shard_1' => [
        'host' => env('DB_SHARD_1_HOST'),
        'database' => 'attendance_shard_1',
        // ...
    ],

    // Shard 2
    'mysql_shard_2' => [
        'host' => env('DB_SHARD_2_HOST'),
        'database' => 'attendance_shard_2',
        // ...
    ],
],
```

**.env**:

```env
# Global Database
DB_GLOBAL_HOST=global.mysql.rds.amazonaws.com
DB_GLOBAL_DATABASE=attendance_global

# Shard 1
DB_SHARD_1_HOST=shard1.mysql.rds.amazonaws.com
DB_SHARD_1_DATABASE=attendance_shard_1

# Shard 2
DB_SHARD_2_HOST=shard2.mysql.rds.amazonaws.com
DB_SHARD_2_DATABASE=attendance_shard_2
```

---

## 📊 Data Distribution

### Global Database

**Tables**:
- `users` - All users (authentication)
- `subscriptions` - All subscriptions (billing)
- `shard_mapping` - Shard configuration
- `schools` - School metadata

### Shard Databases

**Tables** (per shard):
- `attendances` - Attendance records
- `students` - Students
- `teachers` - Teachers
- `schedules` - Schedules
- `attendance_daily_summaries` - Summaries

---

## 🚫 Implementation Rules

### 1. No Cross-Shard Transactions ❌

```php
// ❌ BAD
DB::transaction(function () {
    Attendance::forSchool(500)->create([...]); // Shard 1
    Attendance::forSchool(1500)->create([...]); // Shard 2
});

// ✅ GOOD
DB::connection('mysql_shard_1')->transaction(function () {
    Attendance::forSchool(500)->create([...]);
});

DB::connection('mysql_shard_2')->transaction(function () {
    Attendance::forSchool(1500)->create([...]);
});
```

---

### 2. No Cross-Shard Joins ❌

```php
// ❌ BAD
$results = DB::table('attendances')
    ->join('students', ...) // Different shards!
    ->get();

// ✅ GOOD
$attendances = Attendance::forSchool(500)->get();
$studentIds = $attendances->pluck('student_id');
$students = Student::forSchool(500)->whereIn('id', $studentIds)->get();

// Merge in application layer
$results = $attendances->map(function ($attendance) use ($students) {
    $attendance->student = $students->firstWhere('id', $attendance->student_id);
    return $attendance;
});
```

---

### 3. Summary Per Shard ✅

```php
// Query single shard
$summary = DB::connection('mysql_shard_1')
    ->table('attendance_daily_summaries')
    ->where('school_id', 500)
    ->first();

// Global summary (scatter-gather)
$shards = TenantResolver::getAllShards();
$globalSummary = collect();

foreach ($shards as $shard) {
    $shardSummary = DB::connection($shard['shard_name'])
        ->table('attendance_daily_summaries')
        ->where('attendance_date', today())
        ->sum('total_students');
    
    $globalSummary->push($shardSummary);
}

$total = $globalSummary->sum();
```

---

## 🔄 Migration Strategy

### Phase 1: Keep Single DB (Months 1-2)

```
Current State:
┌─────────────────────────────────────┐
│     Single Database (All schools)   │
└─────────────────────────────────────┘

Tasks:
- [ ] Create global database
- [ ] Create shard mapping table
- [ ] Implement TenantResolver
- [ ] Test routing logic
- [ ] Create empty shard databases
```

---

### Phase 2: New Schools to New Shard (Months 3-4)

```
┌─────────────────────────────────────┐
│  Shard 1 (Existing schools 1-1000)  │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│  Shard 2 (New schools 1001+)        │
└─────────────────────────────────────┘

Tasks:
- [ ] Update school creation logic
- [ ] New schools go to Shard 2
- [ ] Existing schools stay in Shard 1
- [ ] Monitor performance
```

---

### Phase 3: Gradual Migration (Months 5-12)

```
┌─────────────────────────────────────┐
│  Shard 1 (Schools 1-1000)           │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│  Shard 2 (Schools 1001-2000)        │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│  Shard 3 (Schools 2001-3000)        │
└─────────────────────────────────────┘

Tasks:
- [ ] Migrate old schools gradually
- [ ] Verify data integrity
- [ ] Update shard mapping
- [ ] Decommission old database
```

---

## 🛠️ Management Commands

### Show Statistics

```bash
php artisan shard:manage stats
```

**Output**:
```
📊 Shard Statistics

Shard           Range        Schools  Students  Attendances  Size (MB)  Status
mysql_shard_1   1-1000       850      42,500    2,125,000    1,250.50   ✅ Active
mysql_shard_2   1001-2000    720      36,000    1,800,000    1,050.25   ✅ Active
mysql_shard_3   2001-3000    450      22,500    1,125,000    650.75     ✅ Active

Total Schools: 2,020
Total Students: 101,000
Total Attendances: 5,050,000
Total Size: 2,951.50 MB
```

---

### Validate Configuration

```bash
php artisan shard:manage validate
```

**Output**:
```
🔍 Validating Shard Configuration

✅ Configuration is valid
Total shards: 3
```

---

### Clear Cache

```bash
php artisan shard:manage clear-cache
```

---

### Migrate School

```bash
php artisan shard:manage migrate --school=500 --target=mysql_shard_2
```

---

## 🔍 Cross-Shard Query Strategies

### 1. Scatter-Gather Pattern

```php
public function getGlobalSummary(\Carbon\Carbon $date): array
{
    $shards = TenantResolver::getAllShards();
    $results = [];

    // Scatter: Query each shard
    foreach ($shards as $shard) {
        $results[] = DB::connection($shard['shard_name'])
            ->table('attendance_daily_summaries')
            ->where('attendance_date', $date)
            ->selectRaw('SUM(total_students) as total')
            ->first();
    }

    // Gather: Aggregate results
    return [
        'total_students' => collect($results)->sum('total'),
    ];
}
```

---

### 2. Parallel Queries

```php
use Illuminate\Support\Facades\Parallel;

public function getParallelSummary(): array
{
    $shards = TenantResolver::getAllShards();

    // Execute queries in parallel
    $results = Parallel::map($shards, function ($shard) {
        return DB::connection($shard['shard_name'])
            ->table('attendances')
            ->count();
    });

    return [
        'total_attendances' => array_sum($results),
    ];
}
```

---

## 📝 Implementation Checklist

### Week 1-2: Infrastructure Setup
- [ ] Create global database
- [ ] Run shard_mapping migration
- [ ] Create shard databases (empty)
- [ ] Configure database connections
- [ ] Test connectivity

### Week 3-4: Code Implementation
- [ ] Deploy TenantResolver service
- [ ] Add ShardAware trait to models
- [ ] Update service layer
- [ ] Test shard routing
- [ ] Unit tests

### Week 5-6: Migration Preparation
- [ ] Create migration scripts
- [ ] Test migration on staging
- [ ] Prepare rollback plan
- [ ] Document procedures
- [ ] Team training

### Week 7-8: Gradual Rollout
- [ ] New schools to new shard
- [ ] Monitor performance
- [ ] Migrate pilot schools
- [ ] Verify data integrity
- [ ] Full migration

---

## 📊 Summary Statistics

| Metric | Value |
|--------|-------|
| Global Database | 1 |
| Shard Databases | 3+ (scalable) |
| Schools per Shard | 1000 |
| Sharding Strategy | Range-based |
| Migration Phases | 3 |
| Documentation Files | 2 |
| Code Files | 4 |

---

## 🎉 Status

**✅ READY FOR IMPLEMENTATION**

- ✅ Sharding strategy designed
- ✅ TenantResolver service created
- ✅ ShardAware trait created
- ✅ Shard mapping migration created
- ✅ Management commands created
- ✅ Migration strategy defined
- ✅ Cross-shard query patterns documented
- ✅ Implementation checklist provided

---

## 📚 Documentation

1. **[SHARDING_STRATEGY.md](./SHARDING_STRATEGY.md)** - Complete strategy document

---

**Last Updated**: 2026-02-10  
**Version**: 1.0  
**Next Review**: 2026-03-10
