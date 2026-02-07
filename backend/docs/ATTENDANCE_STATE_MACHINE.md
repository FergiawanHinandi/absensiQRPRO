# 🔄 Attendance State Machine Documentation

## Overview

Sistem absensi menggunakan **Explicit State Machine** untuk mengontrol transisi status dengan ketat. Semua perubahan status **WAJIB** melalui method yang ditentukan.

---

## 📊 State Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      ATTENDANCE STATE MACHINE                                │
│                                                                              │
│   ┌────────────────────────────────────────────────────────────────────┐    │
│   │                         NORMAL FLOW                                 │    │
│   └────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
│                               ┌──────────┐                                   │
│                               │   INIT   │                                   │
│                               │  (awal)  │                                   │
│                               └────┬─────┘                                   │
│                                    │                                         │
│                                    │ checkIn()                               │
│                                    ▼                                         │
│                            ┌──────────────┐                                  │
│                            │  CHECKED_IN  │                                  │
│                            │ (sudah masuk)│                                  │
│                            └──────┬───────┘                                  │
│                                   │                                          │
│                                   │ checkOut()                               │
│                                   ▼                                          │
│                           ┌───────────────┐                                  │
│                           │  CHECKED_OUT  │                                  │
│                           │(sudah pulang) │                                  │
│                           └───────┬───────┘                                  │
│                                   │                                          │
│   ┌────────────────────────────────────────────────────────────────────┐    │
│   │                       CORRECTION FLOW                               │    │
│   └────────────────────────────────────────────────────────────────────┘    │
│                                   │                                          │
│                                   │ requestCorrection()                      │
│                                   ▼                                          │
│                         ┌──────────────────┐                                 │
│                         │ PENDING_APPROVAL │                                 │
│                         │  (menunggu)      │                                 │
│                         └────────┬─────────┘                                 │
│                            ┌─────┴─────┐                                     │
│                   approve()│           │reject()                             │
│                            ▼           ▼                                     │
│                     ┌──────────┐ ┌──────────┐                                │
│                     │ APPROVED │ │ REJECTED │◄────┐                          │
│                     │(disetuju)│ │ (ditolak)│     │                          │
│                     └──────────┘ └────┬─────┘     │                          │
│                          │            │           │                          │
│                          │            │requestCorrection()                   │
│                          │            │  (retry)  │                          │
│                          │            └───────────┘                          │
│                          │                                                   │
│                          ▼                                                   │
│                    ┌───────────┐                                             │
│                    │  FINAL    │                                             │
│                    │ (selesai) │                                             │
│                    └───────────┘                                             │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 📋 Valid States

| State | Value | Description | Is Final |
|-------|-------|-------------|----------|
| `INIT` | `init` | Belum ada aktivitas absensi | ❌ |
| `CHECKED_IN` | `checked_in` | Siswa sudah check-in | ❌ |
| `CHECKED_OUT` | `checked_out` | Siswa sudah check-out | ❌ |
| `PENDING_APPROVAL` | `pending_approval` | Menunggu persetujuan koreksi | ❌ |
| `APPROVED` | `approved` | Koreksi disetujui | ✅ |
| `REJECTED` | `rejected` | Koreksi ditolak | ❌ |

---

## 🔀 Valid Transitions

| From State | To State | Method | Description |
|------------|----------|--------|-------------|
| `INIT` | `CHECKED_IN` | `checkIn()` | Record check-in |
| `CHECKED_IN` | `CHECKED_OUT` | `checkOut()` | Record check-out |
| `CHECKED_OUT` | `PENDING_APPROVAL` | `requestCorrection()` | Request data correction |
| `PENDING_APPROVAL` | `APPROVED` | `approve()` | Approve correction |
| `PENDING_APPROVAL` | `REJECTED` | `reject()` | Reject correction |
| `REJECTED` | `PENDING_APPROVAL` | `requestCorrection()` | Retry correction request |

---

## 🚫 Illegal Transitions (Will Throw Exception)

| Attempted Transition | Error |
|---------------------|-------|
| `CHECKED_OUT` → `CHECKED_IN` | Cannot check-in after checkout |
| `CHECKED_IN` → `CHECKED_IN` | Already checked in |
| `INIT` → `CHECKED_OUT` | Must check-in first |
| `APPROVED` → any | Final state, cannot modify |
| any → `INIT` | Cannot reset to initial state |

---

## 💻 Usage Examples

### Basic Check-in/Check-out Flow

```php
use App\Models\Attendance;
use App\Exceptions\StateViolationException;

// Create new attendance record
$attendance = Attendance::create([
    'school_id' => $school->id,
    'student_id' => $student->id,
    'schedule_id' => $schedule->id,
    'attendance_date' => now()->toDateString(),
    'attendance_type' => 'in',
]);

// State is automatically INIT
echo $attendance->state; // 'init'

// Check-in
try {
    $attendance->checkIn(
        recordedBy: $teacher,
        latitude: -6.2088,
        longitude: 106.8456,
        deviceId: 'device-123'
    );
    echo $attendance->state; // 'checked_in'
} catch (StateViolationException $e) {
    // Handle invalid transition
    return response()->json($e->toArray(), 422);
}

// Check-out
$attendance->checkOut($teacher, -6.2088, 106.8456, 'device-123');
echo $attendance->state; // 'checked_out'
```

### Correction Workflow

```php
// Student requests correction
$attendance->requestCorrection(
    requestedBy: $student,
    reason: 'Waktu check-in salah karena koneksi lambat'
);

// Teacher/Admin approves
$attendance->approve(
    approver: $admin,
    notes: 'Koreksi disetujui berdasarkan log server'
);

// OR rejects
$attendance->reject(
    rejector: $admin,
    reason: 'Tidak ada bukti pendukung'
);

// Student can retry after rejection
$attendance->requestCorrection($student, 'Bukti sudah dilampirkan');
```

### State Inspection

```php
// Check current state
if ($attendance->isCheckedIn()) {
    // Show checkout button
}

if ($attendance->isPendingApproval()) {
    // Show in approval queue
}

if ($attendance->isFinalState()) {
    // Cannot modify anymore
}

// Check if counts as present
if ($attendance->countsAsPresent()) {
    // Include in presence statistics
}
```

### Query by State

```php
// Get all pending approvals for school
$pendingApprovals = Attendance::where('school_id', $schoolId)
    ->pendingApproval()
    ->with('student', 'schedule')
    ->get();

// Get completed attendances
$completed = Attendance::where('school_id', $schoolId)
    ->completed()
    ->today()
    ->get();

// Get specific state
$checkedIn = Attendance::inState(AttendanceState::CHECKED_IN)
    ->today()
    ->get();
```

---

## 🗄️ Database Schema

### New Columns Added

```sql
-- State machine column
state VARCHAR(20) DEFAULT 'init'
  COMMENT 'State: init|checked_in|checked_out|pending_approval|approved|rejected'

-- Check-out location
lat_out DECIMAL(10, 8) NULL
lng_out DECIMAL(11, 8) NULL
device_id_out VARCHAR(64) NULL

-- Correction workflow
correction_reason TEXT NULL
correction_requested_by BIGINT REFERENCES users(id) NULL
correction_requested_at TIMESTAMP NULL

-- Approval workflow
approved_by BIGINT REFERENCES users(id) NULL
approved_at TIMESTAMP NULL
approval_notes TEXT NULL

-- Rejection workflow
rejected_by BIGINT REFERENCES users(id) NULL
rejected_at TIMESTAMP NULL
rejection_reason TEXT NULL
```

### Unique Constraint

```sql
-- Prevent duplicate attendance per student per day per type
ALTER TABLE attendances 
ADD CONSTRAINT unique_student_date_type 
UNIQUE (student_id, attendance_date, attendance_type);
```

### Indexes

```sql
CREATE INDEX idx_attendances_state ON attendances(state);
CREATE INDEX idx_attendances_school_state ON attendances(school_id, state);
CREATE INDEX idx_attendances_student_date_state ON attendances(student_id, attendance_date, state);
```

---

## ⚠️ Exception Handling

```php
use App\Exceptions\StateViolationException;

try {
    $attendance->checkIn($teacher);
} catch (StateViolationException $e) {
    // Get detailed error info
    $error = $e->toArray();
    
    // {
    //   "error": "state_violation",
    //   "message": "Sudah melakukan check-in sebelumnya.",
    //   "from_state": "checked_in",
    //   "to_state": "checked_in",
    //   "allowed_transitions": ["checked_out"]
    // }
    
    return response()->json($error, $e->getCode());
}
```

---

## 🔧 Migration Command

```bash
# Run migration
php artisan migrate

# Rollback if needed
php artisan migrate:rollback --step=1
```

---

## 📝 Files Reference

| File | Purpose |
|------|---------|
| `app/Enums/AttendanceState.php` | State enum with transitions |
| `app/Traits/HasAttendanceStateMachine.php` | State machine trait |
| `app/Exceptions/StateViolationException.php` | Custom exception |
| `app/Models/Attendance.php` | Updated model |
| `database/migrations/2026_02_07_100000_add_state_machine_to_attendances.php` | DB schema |
