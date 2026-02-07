# 🔍 AUDIT CONTROLLER ABSENSI - HASIL ANALISIS

> **Tanggal Audit:** 7 Februari 2026  
> **Auditor:** GitHub Copilot (Clean Architecture Review)

---

## 📊 RINGKASAN TEMUAN

| Controller | Status | Pelanggaran | Prioritas |
|------------|--------|-------------|-----------|
| `AttendanceController` | ✅ CLEAN | Minimal | LOW |
| `TeacherScanController` | ⚠️ PERLU FIX | Inline validation, role check | MEDIUM |
| `SecureAttendanceScanController` | 🔴 KRITIS | Business logic dalam controller | HIGH |
| `AttendanceReportController` | ⚠️ PERLU FIX | Direct model update | MEDIUM |
| `PermissionController` | 🔴 KRITIS | Raw DB queries, business logic | HIGH |

---

## 🔴 TEMUAN DETAIL

### 1. TeacherScanController (MEDIUM)

**File:** `app/Http/Controllers/Api/V1/TeacherScanController.php`

**Pelanggaran:**
```php
// ❌ Line 21-26: Inline validation (harus pakai FormRequest)
$request->validate([
    'qr_token' => 'required|string',
    'lat' => 'nullable|numeric',
    ...
]);

// ❌ Line 31-33: Role checking di controller (harus di middleware/policy)
if (! $teacher->hasRole(['teacher', 'homeroom_teacher'])) {
    return response()->json(['message' => 'Unauthorized...'], 403);
}
```

**Risiko:**
- Validasi tidak konsisten dengan endpoint lain
- Role logic tersebar di berbagai tempat
- Sulit di-unit test

---

### 2. SecureAttendanceScanController (HIGH)

**File:** `app/Http/Controllers/Api/V1/SecureAttendanceScanController.php`

**Pelanggaran KRITIS:**

```php
// ❌ Line 60-75: Inline validation (seharusnya FormRequest)
$validated = $request->validate([...]);

// ❌ Line 82-91: Role checking di controller
if (! $this->isTeacher($teacher)) { ... }

// ❌ Line 107-170: BUSINESS LOGIC - DB transaction dengan query
return DB::transaction(function () use ($teacher, $verificationResult, $validated) {
    // Query student
    $student = \App\Models\User::where('id', $verificationResult['student_id'])
        ->where('role_type', 'student')
        ->where('school_id', $teacher->school_id)
        ->lockForUpdate()
        ->first();

    // Check existing attendance
    $existingAttendance = \App\Models\Attendance::where('student_id', $student->id)
        ->whereDate('attendance_date', today())
        ->lockForUpdate()
        ->first();

    // ❌ CRITICAL: Attendance recorded TANPA service layer
    // Melanggar single source of truth
});

// ❌ Line 310-370: processVerifiedScan() - Duplicate business logic
// Sama persis dengan scan() tapi terpisah

// ❌ Line 375-400: buildQrToken() - Token building di controller
// Seharusnya di QrService
```

**Risiko TINGGI:**
- **Double Recording:** Attendance bisa dibuat dari 2 tempat berbeda dengan logic berbeda
- **Inconsistent Status:** Status determination tidak seragam
- **No Event Dispatch:** Tidak ada event StudentAttended/AttendanceLate
- **No Gamification:** Points tidak diberikan
- **Security Bypass:** Bisa bypass security checks di AttendanceService

---

### 3. AttendanceReportController.update (MEDIUM)

**File:** `app/Http/Controllers/Api/V1/AttendanceReportController.php`

**Pelanggaran:**
```php
// ❌ Line 267-275: Direct model update TANPA service
$attendance->update([
    'status' => $validated['status'],
    'notes' => $validated['notes'] ?? $attendance->notes,
]);
```

**Risiko:**
- Status berubah tanpa audit trail
- Tidak ada event dispatch
- Tidak ada validation business rule (misal: tidak bisa ubah setelah 7 hari)

---

### 4. PermissionController (HIGH)

**File:** `app/Http/Controllers/Api/V1/PermissionController.php`

**Pelanggaran KRITIS:**

```php
// ❌ Line 23-35: Raw DB::table() queries
$query = DB::table('student_permissions')
    ->join('users', 'student_permissions.student_id', '=', 'users.id')
    ...

// ❌ Line 88-104: Direct DB insert TANPA model
$permissionId = DB::table('student_permissions')->insertGetId([...]);

// ❌ Line 119-163: generateAttendanceForPermission() - Raw DB manipulation
// BYPASS SELURUH AttendanceService!
DB::table('attendances')->insert([
    'student_id' => $permission->student_id,
    'status' => $attendStatus,
    ...
]);

// ❌ Line 192-245: updateStatus() - Attendance update via raw DB
DB::table('attendances')
    ->where('school_id', $permission->school_id)
    ->update([
        'status' => $attendStatus,
        ...
    ]);
```

**Risiko KRITIS:**
- **NO MODEL EVENTS:** Observer tidak terpanggil
- **NO VALIDATION:** Unique constraint bisa dilanggar
- **NO SCHOOL SCOPE:** BelongsToSchool trait di-bypass
- **NO AUDIT:** Tidak ada AttendanceLog
- **DUPLICATE POSSIBLE:** Tidak ada idempotency check

---

## 📋 DAFTAR BUSINESS LOGIC YANG HARUS DIPINDAH KE SERVICE

### AttendanceService harus memiliki:

```php
class AttendanceService
{
    // ✅ Sudah ada
    public function checkIn(User $student, array $data): AttendanceResult;
    public function teacherCheckIn(User $teacher, string $qrToken, array $data): AttendanceResult;
    public function manualCheckIn(array $data, int $recordedBy): Attendance;
    
    // ❌ BELUM ADA - harus ditambahkan
    public function checkOut(User $user, int $attendanceId, array $data): AttendanceResult;
    public function update(int $attendanceId, array $data, User $updatedBy): Attendance;
    public function approve(int $attendanceId, User $approver): Attendance;
    public function reject(int $attendanceId, User $rejecter, string $reason): Attendance;
    public function submitCorrection(int $attendanceId, array $data, User $requester): Attendance;
    public function createFromPermission(Permission $permission): Collection;
}
```

---

## 🛠️ RENCANA REFACTORING

### Fase 1: FormRequest (Hari 1)
1. Buat `TeacherScanRequest`
2. Buat `SecureAttendanceScanRequest`
3. Buat `AttendanceUpdateRequest`

### Fase 2: Service Layer (Hari 2-3)
1. Tambahkan method `update()` ke AttendanceService
2. Tambahkan method `createFromPermission()` 
3. Buat `PermissionService` untuk approval workflow

### Fase 3: Controller Cleanup (Hari 4)
1. Refactor TeacherScanController
2. Refactor SecureAttendanceScanController
3. Refactor AttendanceReportController
4. Refactor PermissionController

### Fase 4: Testing (Hari 5)
1. Unit test untuk service methods baru
2. Integration test untuk workflow

---

## ⚠️ RISIKO JIKA TIDAK DIREFACTOR

| Risiko | Dampak | Probabilitas |
|--------|--------|--------------|
| Double recording attendance | Data corrupt | HIGH |
| Status tidak konsisten | Laporan salah | HIGH |
| Security bypass | Unauthorized changes | MEDIUM |
| Missing audit trail | Compliance issue | HIGH |
| Points tidak diberikan | UX broken | MEDIUM |
| Event tidak terdispatch | Notifikasi gagal | MEDIUM |

---

## ✅ ACTION ITEMS - COMPLETED

1. [x] Buat `TeacherScanRequest` - `app/Http/Requests/Api/TeacherScanRequest.php`
2. [x] Buat `SecureAttendanceScanRequest` - `app/Http/Requests/Api/SecureAttendanceScanRequest.php`
3. [x] Buat `AttendanceUpdateRequest` - `app/Http/Requests/Api/AttendanceUpdateRequest.php`
4. [x] Buat `AttendanceOperationService` - `app/Services/AttendanceOperationService.php`
5. [x] Buat `PermissionService` - `app/Services/PermissionService.php`
6. [x] Buat `StudentPermission` Model - `app/Models/StudentPermission.php`
7. [x] Buat `ApiResponseTrait` - `app/Traits/ApiResponseTrait.php`
8. [x] Refactor `TeacherScanController` - Now uses FormRequest
9. [x] Refactor `SecureAttendanceScanController` - Completely rewritten
10. [x] Refactor `AttendanceReportController` - Uses AttendanceOperationService
11. [x] Refactor `PermissionController` - Uses PermissionService

### Remaining Work
- [ ] Add `ApiResponseTrait` to all controllers
- [ ] Unit tests for new services
- [ ] Integration tests for workflows

---

## 📁 FILES CREATED/MODIFIED

### New Files Created:
```
app/Http/Requests/Api/TeacherScanRequest.php
app/Http/Requests/Api/SecureAttendanceScanRequest.php
app/Http/Requests/Api/AttendanceUpdateRequest.php
app/Services/AttendanceOperationService.php
app/Services/PermissionService.php
app/Models/StudentPermission.php
app/Traits/ApiResponseTrait.php
```

### Files Refactored:
```
app/Http/Controllers/Api/V1/TeacherScanController.php
app/Http/Controllers/Api/V1/SecureAttendanceScanController.php
app/Http/Controllers/Api/V1/AttendanceReportController.php
app/Http/Controllers/Api/V1/PermissionController.php
```
