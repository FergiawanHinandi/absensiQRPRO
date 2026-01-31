# Race Condition Prevention in Attendance Check-In

## Overview

The attendance check-in process uses a **4-layer concurrency protection strategy** to prevent duplicate attendance records, even under high concurrent load.

---

## The Race Condition Problem

Without proper locking, two concurrent requests can both succeed:

```
Timeline WITHOUT proper locking:
─────────────────────────────────────────────────────────────────────
Time     Transaction 1              Transaction 2
─────────────────────────────────────────────────────────────────────
0ms      Check exists? → No
1ms                                  Check exists? → No
2ms      INSERT → Success
3ms                                  INSERT → Success (or DUPLICATE!)
─────────────────────────────────────────────────────────────────────
RESULT: Both requests succeeded = DATA CORRUPTION
```

---

## The 4-Layer Solution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    CONCURRENCY PROTECTION LAYERS                    │
└─────────────────────────────────────────────────────────────────────┘

     ┌──────────────────────────────────────────────────────┐
     │  Layer 1: APPLICATION-LEVEL LOCK (Redis/Cache)       │
     │  • Fast distributed lock before DB access            │
     │  • Reduces database contention                       │
     │  • Works across multiple app servers                 │
     │  • Key: attendance_checkin:{student}:{schedule}:date │
     └────────────────────────┬─────────────────────────────┘
                              │
                              ▼
     ┌──────────────────────────────────────────────────────┐
     │  Layer 2: DATABASE TRANSACTION                        │
     │  • All-or-nothing semantics                          │
     │  • Auto-rollback on failure                          │
     │  • ACID guarantees                                   │
     └────────────────────────┬─────────────────────────────┘
                              │
                              ▼
     ┌──────────────────────────────────────────────────────┐
     │  Layer 3: ROW LOCK (SELECT ... FOR UPDATE)           │
     │  • Exclusive lock on existing row                    │
     │  • Other transactions WAIT                           │
     │  • Released on COMMIT/ROLLBACK                       │
     └────────────────────────┬─────────────────────────────┘
                              │
                              ▼
     ┌──────────────────────────────────────────────────────┐
     │  Layer 4: GAP LOCK (for new rows)                    │
     │  • Lock on "gap" in index where row would be         │
     │  • Prevents concurrent INSERT of same key            │
     │  • Requires unique composite index                   │
     └──────────────────────────────────────────────────────┘
```

---

## Implementation

### Layer 1: Application-Level Lock

```php
// More specific lock key includes all uniqueness criteria
$lockKey = "attendance_checkin:{$student->id}:{$schedule->id}:" . now()->toDateString();

return Cache::lock($lockKey, self::LOCK_TIMEOUT)->block(self::LOCK_WAIT, function () {
    // Only one request at a time can proceed past this point
});
```

**Why this helps:**
- First line of defense
- Reduces database round-trips under contention
- Works across multiple application servers

### Layer 2: Database Transaction

```php
return DB::transaction(function () {
    // Everything inside here is atomic
    // If any exception is thrown, all changes are rolled back
});
```

### Layer 3 & 4: Row Lock with Gap Lock

```php
private function findExistingAttendanceWithLock(
    int $studentId,
    int $scheduleId,
    string $date
): ?Attendance {
    return Attendance::where('student_id', $studentId)
        ->where('schedule_id', $scheduleId)
        ->whereDate('attendance_date', $date)
        ->lockForUpdate()  // SELECT ... FOR UPDATE
        ->first();
}
```

**SQL Generated:**
```sql
SELECT * FROM attendances
WHERE student_id = 123
  AND schedule_id = 456
  AND attendance_date = '2026-01-31'
FOR UPDATE;
```

---

## How `lockForUpdate()` Works

### Case A: Row EXISTS

```
T1: SELECT ... FOR UPDATE → Row found → Acquires X (exclusive) lock
T2: SELECT ... FOR UPDATE → BLOCKED (waiting for T1's lock)
T1: ... does work ... COMMIT → Lock released
T2: Lock acquired → Row found (T1's insert) → Reject duplicate ✓
```

### Case B: Row DOES NOT EXIST

```
T1: SELECT ... FOR UPDATE → No row → Acquires GAP LOCK on index
T2: SELECT ... FOR UPDATE → BLOCKED (gap lock held by T1)
T1: INSERT → Success → COMMIT → Lock released
T2: Lock acquired → Row found → Reject duplicate ✓
```

---

## Required Database Index

The gap lock mechanism requires a proper unique composite index:

```php
// Migration
Schema::table('attendances', function (Blueprint $table) {
    $table->unique(
        ['student_id', 'schedule_id', 'attendance_date'],
        'idx_attendance_unique'
    );
});
```

**Why this index is critical:**
- Gap locks work on **index gaps**, not table gaps
- Without this index, gap locking is less effective
- Also serves as a final safety net (duplicate key error)

---

## Fallback: Duplicate Key Exception Handling

Even with all protections, we handle the edge case:

```php
try {
    $attendance = Attendance::create([...]);
} catch (\Illuminate\Database\QueryException $e) {
    if ($this->isDuplicateKeyException($e)) {
        // Race condition slipped through - handle gracefully
        throw AttendanceException::alreadyRecorded();
    }
    throw $e;
}

private function isDuplicateKeyException($e): bool
{
    $errorCode = $e->errorInfo[1] ?? null;
    
    // MySQL: 1062 = Duplicate entry
    // PostgreSQL: 23505 = unique_violation
    return in_array($errorCode, [1062, 23505], true);
}
```

---

## Timeline with All Protections

```
Timeline WITH proper locking:
─────────────────────────────────────────────────────────────────────
Time     Transaction 1              Transaction 2
─────────────────────────────────────────────────────────────────────
0ms      Cache::lock() → Acquired
1ms                                  Cache::lock() → WAITING...
2ms      BEGIN TRANSACTION
3ms      SELECT ... FOR UPDATE → No row (gap lock)
4ms      INSERT → Success
5ms      COMMIT → Success
6ms      Cache lock released
7ms                                  Cache::lock() → Acquired
8ms                                  BEGIN TRANSACTION
9ms                                  SELECT ... FOR UPDATE → Row found!
10ms                                 Throw: Already recorded ✓
─────────────────────────────────────────────────────────────────────
RESULT: Only first request succeeded = DATA INTEGRITY ✓
```

---

## Configuration

### Cache Lock Settings

```php
// In AttendanceCheckInService
private const LOCK_TIMEOUT = 5;   // Max time to hold lock (seconds)
private const LOCK_WAIT = 3;      // Max time to wait for lock (seconds)
```

### Redis Configuration (Recommended)

```env
CACHE_DRIVER=redis
REDIS_CLIENT=phpredis
```

---

## Testing Concurrent Requests

### PHPUnit Test

```php
public function test_concurrent_checkins_only_one_succeeds()
{
    $student = User::factory()->create();
    $schedule = Schedule::factory()->create();
    
    $results = [];
    $threads = [];
    
    // Simulate 5 concurrent requests using parallel processes
    for ($i = 0; $i < 5; $i++) {
        $threads[] = Process::path(base_path())
            ->start("php artisan test:concurrent-checkin {$student->id} {$schedule->id}");
    }
    
    // Wait for all to complete
    foreach ($threads as $thread) {
        $results[] = $thread->wait();
    }
    
    // Only ONE should have succeeded
    $successes = collect($results)->filter->successful()->count();
    $this->assertEquals(1, $successes);
    
    // Only ONE attendance record in database
    $this->assertEquals(1, Attendance::where('student_id', $student->id)
        ->where('schedule_id', $schedule->id)
        ->count());
}
```

### Load Testing with Artillery

```yaml
# artillery-concurrent.yml
config:
  target: "http://localhost:8000"
  phases:
    - duration: 10
      arrivalRate: 50  # 50 requests per second

scenarios:
  - name: "Concurrent check-in"
    flow:
      - post:
          url: "/api/v1/attendance/scan"
          json:
            qr_token: "{{ $randomString() }}"
          headers:
            Authorization: "Bearer {{ token }}"
```

---

## Performance Considerations

| Protection Layer | Latency Added | Trade-off |
|-----------------|---------------|-----------|
| Cache Lock | ~1-5ms | Fast fail for concurrent requests |
| DB Transaction | ~1ms | ACID guarantees |
| Row Lock | ~1-10ms | Serializes conflicting requests |
| Gap Lock | ~1-10ms | Prevents duplicate inserts |

**Total overhead:** ~5-25ms under contention (acceptable for this use case)

---

## Summary

| Layer | Mechanism | Protects Against |
|-------|-----------|------------------|
| 1 | Application Lock | High concurrent load from same student |
| 2 | DB Transaction | Partial writes, data corruption |
| 3 | Row Lock (FOR UPDATE) | Concurrent updates to existing row |
| 4 | Gap Lock | Concurrent inserts of same unique row |
| Fallback | Unique Constraint | Any edge case that slips through |
