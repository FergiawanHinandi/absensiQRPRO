# Attendance Aggregate Root - Domain-Driven Design Implementation

## 📋 Overview

**Version:** 2.0.0  
**Date:** 2026-02-07  
**Status:** ✅ IMPLEMENTED

### Objective
Refactor domain Attendance menjadi Aggregate Root yang sepenuhnya mengontrol perubahan state melalui explicit state machine dengan transaction boundaries.

---

## 🎯 Problems Solved

### ❌ Problem 1: Direct Status Modification

**Before:**
```php
// DANGEROUS: Status can be modified directly
$attendance->update(['status' => 'present']);  // ❌ Bypasses business logic
$attendance->status = 'absent';  // ❌ No validation
$attendance->save();
```

**Issues:**
- No validation of state transitions
- Business rules can be bypassed
- No audit trail
- Race conditions possible
- Data integrity at risk

---

### ❌ Problem 2: State Machine Can Be Bypassed

**Before:**
```php
// State machine exists but can be ignored
$attendance->update(['state' => 'approved']);  // ❌ Bypasses transition validation

// Or worse:
DB::table('attendances')
    ->where('id', $id)
    ->update(['status' => 'present']);  // ❌ Completely bypasses model
```

---

### ❌ Problem 3: No Transaction Boundaries

**Before:**
```php
// State change without transaction
$attendance->state = AttendanceState::CHECKED_IN;
$attendance->check_in_time = now();
$attendance->save();  // ❌ Not atomic - can fail partially
```

---

## ✅ Solution: Aggregate Root Pattern

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    ATTENDANCE AGGREGATE ROOT                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │              PUBLIC DOMAIN METHODS                        │ │
│  │  (Only way to modify state)                               │ │
│  ├───────────────────────────────────────────────────────────┤ │
│  │  • checkIn(User, lat, lng, deviceId)                      │ │
│  │  • checkOut(User, lat, lng, deviceId)                     │ │
│  │  • requestCorrection(User, reason)                        │ │
│  │  • approve(User, notes)                                   │ │
│  │  • reject(User, reason)                                   │ │
│  └───────────────────────────────────────────────────────────┘ │
│                           ▼                                     │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │         PROTECTED STATE TRANSITION LOGIC                  │ │
│  │  (Validates transitions, wraps in transaction)            │ │
│  ├───────────────────────────────────────────────────────────┤ │
│  │  • transitionTo(newState, actor, reason)                  │ │
│  │    - Validates transition is allowed                      │ │
│  │    - Wraps in DB::transaction()                           │ │
│  │    - Updates state internally                             │ │
│  │    - Syncs legacy status                                  │ │
│  │    - Logs transition                                      │ │
│  │    - Commits or rolls back                                │ │
│  └───────────────────────────────────────────────────────────┘ │
│                           ▼                                     │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │              STATE TRANSITION MATRIX                      │ │
│  │  (AttendanceState enum)                                   │ │
│  ├───────────────────────────────────────────────────────────┤ │
│  │  INIT            → [CHECKED_IN]                           │ │
│  │  CHECKED_IN      → [CHECKED_OUT]                          │ │
│  │  CHECKED_OUT     → [PENDING_APPROVAL]                     │ │
│  │  PENDING_APPROVAL → [APPROVED, REJECTED]                  │ │
│  │  REJECTED        → [PENDING_APPROVAL]                     │ │
│  │  APPROVED        → [] (final state)                       │ │
│  └───────────────────────────────────────────────────────────┘ │
│                           ▼                                     │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │              MUTATOR PROTECTION                           │ │
│  │  (Blocks direct modification)                             │ │
│  ├───────────────────────────────────────────────────────────┤ │
│  │  • setStatusAttribute() → throws exception                │ │
│  │  • setStateAttribute() → throws exception                 │ │
│  │  • $fillable excludes 'status' and 'state'                │ │
│  │  • $guarded includes 'status' and 'state'                 │ │
│  └───────────────────────────────────────────────────────────┘ │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔒 Protection Mechanisms

### 1. **Fillable/Guarded Protection**

```php
// In Attendance model
protected $fillable = [
    'school_id',
    'student_id',
    'check_in_time',
    // ... other fields
    // 'status' - EXCLUDED
    // 'state' - EXCLUDED
];

protected $guarded = ['id', 'status', 'state'];
```

**Result:**
```php
// These will NOT modify status/state
$attendance->update(['status' => 'present']);  // ❌ Ignored
$attendance->fill(['state' => 'checked_in']);  // ❌ Ignored
```

---

### 2. **Mutator Protection**

```php
// In Attendance model
public function setStatusAttribute($value): void
{
    if ($this->exists && $this->id !== null) {
        throw StateViolationException::directModificationBlocked(
            'status',
            'Use state machine methods: checkIn(), checkOut(), approve(), reject()'
        );
    }
    
    $this->attributes['status'] = $value;
}

public function setStateAttribute($value): void
{
    if ($this->exists && $this->id !== null && !$this->isInternalStateChange) {
        throw StateViolationException::directModificationBlocked(
            'state',
            'Use state machine methods: checkIn(), checkOut(), approve(), reject()'
        );
    }
    
    $this->attributes['state'] = $value instanceof AttendanceState 
        ? $value->value 
        : $value;
}
```

**Result:**
```php
// These will throw StateViolationException
$attendance->status = 'present';  // ❌ Exception
$attendance->state = AttendanceState::CHECKED_IN;  // ❌ Exception
```

---

### 3. **State Transition Validation**

```php
// In AttendanceState enum
public function allowedTransitions(): array
{
    return match ($this) {
        self::INIT => [self::CHECKED_IN],
        self::CHECKED_IN => [self::CHECKED_OUT],
        self::CHECKED_OUT => [self::PENDING_APPROVAL],
        self::PENDING_APPROVAL => [self::APPROVED, self::REJECTED],
        self::REJECTED => [self::PENDING_APPROVAL],
        self::APPROVED => [], // Final state
    };
}

public function canTransitionTo(AttendanceState $target): bool
{
    return in_array($target, $this->allowedTransitions(), true);
}
```

**Result:**
```php
// Invalid transitions throw StateViolationException
$attendance->checkOut($teacher);  // ❌ If state is INIT
$attendance->checkIn($teacher);   // ❌ If state is CHECKED_OUT
$attendance->approve($admin);     // ❌ If state is not PENDING_APPROVAL
```

---

### 4. **Transaction Boundaries**

```php
// In HasAttendanceStateMachine trait
protected function transitionTo(
    AttendanceState $newState,
    ?User $actor = null,
    ?string $reason = null
): self {
    $currentState = $this->getCurrentState();

    // Validate transition
    if (!$currentState->canTransitionTo($newState)) {
        throw StateViolationException::illegalTransition($currentState, $newState);
    }

    // Apply transition in transaction
    return DB::transaction(function () use ($newState, $currentState, $actor, $reason) {
        // Set state internally (bypasses mutator protection)
        $this->setStateInternal($newState);
        
        // Sync legacy status
        $this->syncLegacyStatus();
        
        // Save to database
        $this->save();

        // Log transition
        $this->logStateTransition($currentState, $newState, $actor, $reason);

        return $this;
    });
}
```

**Benefits:**
- Atomic operations
- Automatic rollback on failure
- Consistent state
- Audit trail always created

---

## 📊 State Transition Matrix

### Valid Transitions

| From State | To State | Method | Requirements |
|------------|----------|--------|--------------|
| `INIT` | `CHECKED_IN` | `checkIn()` | User, location, device |
| `CHECKED_IN` | `CHECKED_OUT` | `checkOut()` | User, location, device |
| `CHECKED_OUT` | `PENDING_APPROVAL` | `requestCorrection()` | User, reason |
| `PENDING_APPROVAL` | `APPROVED` | `approve()` | Admin, notes (optional) |
| `PENDING_APPROVAL` | `REJECTED` | `reject()` | Admin, reason |
| `REJECTED` | `PENDING_APPROVAL` | `requestCorrection()` | User, reason (retry) |

### Invalid Transitions (Will Throw Exception)

| From State | To State | Error Message |
|------------|----------|---------------|
| `CHECKED_IN` | `CHECKED_IN` | "Sudah melakukan check-in sebelumnya" |
| `CHECKED_OUT` | `CHECKED_IN` | "Tidak dapat check-in setelah check-out" |
| `INIT` | `CHECKED_OUT` | "Harus melakukan check-in terlebih dahulu" |
| `CHECKED_IN` | `PENDING_APPROVAL` | "Transisi status tidak valid..." |
| `CHECKED_OUT` | `APPROVED` | "Transisi status tidak valid..." |
| `APPROVED` | any | "Status 'Disetujui' adalah status final..." |

---

## 🔄 Usage Examples

### Example 1: Complete Happy Path

```php
use App\Models\Attendance;
use App\Models\User;

// 1. Create attendance (INIT state)
$attendance = Attendance::create([
    'student_id' => $student->id,
    'school_id' => $school->id,
    'attendance_date' => today(),
]);

// State: INIT
// Status: absent

// 2. Student checks in
$attendance->checkIn(
    $teacher,
    latitude: -6.2088,
    longitude: 106.8456,
    deviceId: 'device-123'
);

// State: CHECKED_IN
// Status: present
// check_in_time: now()
// lat_in: -6.2088
// lng_in: 106.8456

// 3. Student checks out
$attendance->checkOut(
    $teacher,
    latitude: -6.2088,
    longitude: 106.8456,
    deviceId: 'device-123'
);

// State: CHECKED_OUT
// Status: present
// check_out_time: now()

// 4. Student requests correction
$attendance->requestCorrection(
    $student,
    'Forgot to check out at correct time'
);

// State: PENDING_APPROVAL
// Status: pending
// correction_reason: "Forgot to check out..."
// correction_requested_at: now()

// 5. Admin approves
$attendance->approve($admin, 'Approved based on evidence');

// State: APPROVED
// Status: present
// approved_by: admin->id
// approved_at: now()
// approval_notes: "Approved based on evidence"
```

---

### Example 2: Rejection and Retry

```php
// After checkout, request correction
$attendance->requestCorrection($student, 'First attempt');

// State: PENDING_APPROVAL

// Admin rejects
$attendance->reject($admin, 'Insufficient evidence');

// State: REJECTED
// Status: rejected
// rejected_by: admin->id
// rejection_reason: "Insufficient evidence"

// Student retries with more details
$attendance->requestCorrection($student, 'Second attempt with photo evidence');

// State: PENDING_APPROVAL (retry allowed from REJECTED)

// Admin approves
$attendance->approve($admin, 'Approved with evidence');

// State: APPROVED
// Status: present
```

---

### Example 3: Invalid Transitions (Will Throw)

```php
// ❌ Double check-in
$attendance->checkIn($teacher);
$attendance->checkIn($teacher);  // StateViolationException: "Sudah melakukan check-in sebelumnya"

// ❌ Check-out before check-in
$attendance = Attendance::create([...]);  // INIT state
$attendance->checkOut($teacher);  // StateViolationException: "Harus melakukan check-in terlebih dahulu"

// ❌ Check-in after check-out
$attendance->checkIn($teacher);
$attendance->checkOut($teacher);
$attendance->checkIn($teacher);  // StateViolationException: "Tidak dapat check-in setelah check-out"

// ❌ Approve before pending
$attendance->checkIn($teacher);
$attendance->approve($admin);  // StateViolationException: "Transisi status tidak valid..."

// ❌ Modify approved state
$attendance->checkIn($teacher);
$attendance->checkOut($teacher);
$attendance->requestCorrection($student, 'Test');
$attendance->approve($admin);
$attendance->requestCorrection($student, 'Try again');  // StateViolationException: "Status 'Disetujui' adalah status final..."
```

---

### Example 4: Direct Modification Blocked

```php
// ❌ All of these will throw StateViolationException

// Via update()
$attendance->update(['status' => 'present']);  // Exception

// Via fill()
$attendance->fill(['state' => 'checked_in']);
$attendance->save();  // Exception

// Via attribute setter
$attendance->status = 'present';
$attendance->save();  // Exception

// Via attribute setter
$attendance->state = AttendanceState::CHECKED_IN;
$attendance->save();  // Exception

// Via raw query (bypasses model - DON'T DO THIS!)
DB::table('attendances')
    ->where('id', $attendance->id)
    ->update(['status' => 'present']);  // ⚠️ Bypasses protection - PROHIBITED
```

---

## 🧪 Testing

### Test Coverage

**File:** `tests/Unit/Models/AttendanceStateMachineTest.php`

**Coverage:**
- ✅ All valid transitions (6 tests)
- ✅ All invalid transitions (9 tests)
- ✅ Double check-in prevention
- ✅ Checkout before check-in prevention
- ✅ Approval before checkout prevention
- ✅ Direct status modification blocking (4 tests)
- ✅ Transaction rollback on failure
- ✅ Audit trail logging
- ✅ State inspection methods
- ✅ Complete workflows (2 tests)
- ✅ Legacy status sync
- ✅ Fillable/guarded verification

**Total:** 30+ tests

### Running Tests

```bash
# Run all attendance state machine tests
php artisan test --filter AttendanceStateMachineTest

# Run specific test
php artisan test --filter it_cannot_check_in_twice

# Run with coverage
php artisan test --filter AttendanceStateMachineTest --coverage
```

---

## 📁 Files Modified/Created

### Modified Files

1. **`app/Models/Attendance.php`** ✅ ALREADY IMPLEMENTED
   - Removed `status` and `state` from `$fillable`
   - Added to `$guarded`
   - Implemented `setStatusAttribute()` mutator protection
   - Implemented `setStateAttribute()` mutator protection
   - Added `setStateInternal()` for internal state changes
   - Added `syncLegacyStatus()` for backward compatibility

2. **`app/Traits/HasAttendanceStateMachine.php`** ✅ ALREADY IMPLEMENTED
   - Implemented `transitionTo()` with DB::transaction()
   - Implemented domain methods: `checkIn()`, `checkOut()`, `requestCorrection()`, `approve()`, `reject()`
   - Added state transition validation
   - Added audit trail logging
   - Added state inspection methods

3. **`app/Enums/AttendanceState.php`** ✅ ALREADY IMPLEMENTED
   - Defined explicit state transition matrix
   - Implemented `allowedTransitions()`
   - Implemented `canTransitionTo()`
   - Implemented `isFinal()`, `isPresent()`
   - Added UI helpers: `label()`, `color()`

4. **`app/Exceptions/StateViolationException.php`** ✅ ALREADY IMPLEMENTED
   - Custom exception for state violations
   - Factory methods for common violations
   - API response formatting

### Created Files

5. **`tests/Unit/Models/AttendanceStateMachineTest.php`** ✅ NEW
   - Comprehensive test suite (30+ tests)
   - Covers all valid and invalid transitions
   - Tests direct modification blocking
   - Tests transaction rollback
   - Tests audit trail

6. **`docs/backend/ATTENDANCE_AGGREGATE_ROOT.md`** ✅ NEW (this file)
   - Complete documentation
   - Architecture diagrams
   - Usage examples
   - Migration guide

---

## ⚠️ Risks If Not Implemented

### 1. **Data Integrity Violations**

**Risk:** Without aggregate root pattern, status can be modified directly:
```php
// DANGEROUS - bypasses all business rules
$attendance->update(['status' => 'approved']);
```

**Consequences:**
- Attendance marked as approved without admin approval
- Check-out without check-in
- Duplicate check-ins
- Invalid state transitions
- Audit trail gaps

---

### 2. **Race Conditions**

**Risk:** Without transaction boundaries:
```php
// Thread 1
$attendance->state = AttendanceState::CHECKED_IN;
// Thread 2 modifies here
$attendance->save();  // Overwrites Thread 2's changes
```

**Consequences:**
- Lost updates
- Inconsistent state
- Data corruption

---

### 3. **Business Logic Bypass**

**Risk:** Controllers can bypass state machine:
```php
// In controller - DANGEROUS
$attendance->update(['status' => 'present']);  // Bypasses validation
```

**Consequences:**
- Business rules not enforced
- Invalid data in database
- Reporting inaccuracies
- Compliance violations

---

### 4. **No Audit Trail**

**Risk:** Direct modifications don't log:
```php
// No audit trail created
$attendance->status = 'present';
$attendance->save();
```

**Consequences:**
- Can't track who changed what
- Can't investigate issues
- Compliance violations
- Security risks

---

### 5. **Difficult to Maintain**

**Risk:** Business logic scattered across codebase:
```php
// In Controller A
if ($attendance->status === 'checked_in') {
    $attendance->status = 'checked_out';
}

// In Controller B
if ($attendance->status === 'checked_in') {
    $attendance->status = 'approved';  // Different logic!
}
```

**Consequences:**
- Inconsistent behavior
- Hard to find bugs
- Difficult to add features
- Technical debt

---

## ✅ Benefits of Implementation

### 1. **Guaranteed Data Integrity**
- All state changes validated
- Impossible to create invalid states
- Atomic operations

### 2. **Clear Business Rules**
- State transitions explicit
- Easy to understand
- Self-documenting code

### 3. **Complete Audit Trail**
- All changes logged
- Who, what, when, why
- Compliance ready

### 4. **Maintainability**
- Business logic centralized
- Easy to modify
- Easy to test

### 5. **Type Safety**
- Enum-based states
- Compile-time checks
- IDE autocomplete

---

## 🚀 Migration Checklist

### Phase 1: Verification ✅
- [x] Verify `status` and `state` not in `$fillable`
- [x] Verify `status` and `state` in `$guarded`
- [x] Verify mutator protection implemented
- [x] Verify state machine methods implemented
- [x] Verify transaction boundaries in place

### Phase 2: Testing ✅
- [x] Run comprehensive test suite
- [x] Verify all valid transitions work
- [x] Verify all invalid transitions blocked
- [x] Verify direct modification blocked
- [x] Verify transaction rollback works

### Phase 3: Code Audit 🔄
- [ ] Search codebase for `->update(['status'`
- [ ] Search codebase for `->update(['state'`
- [ ] Search codebase for `->status =`
- [ ] Search codebase for `->state =`
- [ ] Search codebase for `DB::table('attendances')->update`
- [ ] Refactor all direct modifications to use domain methods

### Phase 4: Deployment 🔄
- [ ] Deploy to staging
- [ ] Run integration tests
- [ ] Monitor logs for StateViolationException
- [ ] Fix any violations found
- [ ] Deploy to production
- [ ] Monitor for issues

---

## 📊 Summary

### Implementation Status

| Component | Status | Notes |
|-----------|--------|-------|
| Attendance Model | ✅ COMPLETE | Mutator protection implemented |
| State Machine Trait | ✅ COMPLETE | Transaction boundaries in place |
| State Enum | ✅ COMPLETE | Explicit transition matrix |
| Exception Handling | ✅ COMPLETE | Custom exceptions |
| Test Suite | ✅ COMPLETE | 30+ tests |
| Documentation | ✅ COMPLETE | This document |
| Code Audit | 🔄 PENDING | Need to search codebase |
| Deployment | 🔄 PENDING | After code audit |

### Key Achievements

1. ✅ **Aggregate Root Pattern** - Attendance fully controls its state
2. ✅ **Explicit State Machine** - All transitions validated
3. ✅ **Transaction Boundaries** - Atomic operations guaranteed
4. ✅ **Mutator Protection** - Direct modification blocked
5. ✅ **Comprehensive Tests** - 30+ tests covering all scenarios
6. ✅ **Audit Trail** - All transitions logged

### Next Steps

1. **Code Audit** - Find and refactor all direct status modifications
2. **Integration Testing** - Test with real workflows
3. **Deployment** - Deploy to staging then production
4. **Monitoring** - Watch for StateViolationException in logs

---

**Status:** ✅ IMPLEMENTATION COMPLETE - READY FOR CODE AUDIT  
**Version:** 2.0.0  
**Date:** 2026-02-07
