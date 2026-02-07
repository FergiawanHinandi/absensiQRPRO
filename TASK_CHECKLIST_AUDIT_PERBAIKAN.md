# ✅ MASTER CHECKLIST AUDIT & PERBAIKAN ABSENSI QR PRO

> **Tanggal Audit:** 7 Februari 2026  
> **Status Project:** Monorepo (Backend Laravel 12 + Frontend React + Mobile React Native)

---

## 📊 HASIL IDENTIFIKASI MENDALAM PROJECT

### Struktur Arsitektur Yang Sudah Ada:

| Komponen | Status | Keterangan |
|----------|--------|------------|
| **Service Layer** | ✅ Ada | `AttendanceService`, `AttendanceCheckInService`, dll |
| **FormRequest** | ✅ Ada | `AttendanceScanRequest`, `ManualAttendanceRequest` |
| **Policies** | ✅ Ada | `AttendancePolicy` dengan 487 baris |
| **DB Transaction** | ✅ Ada | Digunakan di services utama |
| **Unique Constraint** | ✅ Ada | `unique_attendance_per_schedule` |
| **Rate Limiting** | ✅ Ada | `RateLimitBySchool`, `CriticalRateLimiting` |
| **Error Boundary (FE)** | ✅ Ada | `AppErrorBoundary` |
| **Request ID Logging** | ✅ Ada | `LogContext`, `JsonLogFormatter` |
| **Custom Exception** | ✅ Ada | `AttendanceException` dengan factory methods |

### Area yang Perlu Perhatian:

| Area | Status | Prioritas |
|------|--------|-----------|
| Controller query langsung | ⚠️ Ditemukan | HIGH |
| Inline validation di controller | ⚠️ Ditemukan | MEDIUM |
| Mobile location validation | 🔍 Perlu Audit | HIGH |
| Soft delete di unique constraint | ⚠️ Partial | MEDIUM |
| Error response format consistency | 🔍 Perlu Audit | MEDIUM |

---

## 🔴 TAHAP 1 - KUNCI DATA (PRIORITAS MUTLAK)

### A. BACKEND – ATTENDANCE SERVICE ✅ (SUDAH ADA)

#### 📁 Files: 
- [backend/app/Services/AttendanceService.php](backend/app/Services/AttendanceService.php) (667 lines)
- [backend/app/Services/AttendanceCheckInService.php](backend/app/Services/AttendanceCheckInService.php) (1190 lines)

#### Checklist Method yang Wajib Ada:

- [x] `checkIn(User $user, array $payload)` → Ada di `AttendanceCheckInService`
- [ ] `checkOut(User $user, array $payload)` → **PERLU VERIFIKASI**
- [ ] `submitCorrection(User $user, int $attendanceId, array $data)` → **PERLU IMPLEMENTASI**
- [ ] `approve(User $approver, int $attendanceId)` → **PERLU VERIFIKASI**  
- [ ] `reject(User $approver, int $attendanceId, string $reason)` → **PERLU VERIFIKASI**

#### ✅ Checklist Service Quality:

- [x] Semua operasi dibungkus `DB::transaction()` → ✅ Sudah ada
- [ ] Tidak ada update status langsung via model di luar service
- [x] Service melempar exception, bukan return boolean → ✅ `AttendanceException`
- [ ] Tidak ada logic bercabang berdasarkan role (gunakan Policy)

#### 🔧 TODO:
```
[ ] 1.1 Audit semua tempat yang memodifikasi Attendance di luar service
[ ] 1.2 Implementasi method checkOut jika belum ada
[ ] 1.3 Implementasi method submitCorrection untuk koreksi absensi
[ ] 1.4 Standarisasi return type (semua pakai AttendanceResult DTO)
```

---

### B. DATABASE CONSTRAINT ✅ (SUDAH ADA SEBAGIAN)

#### 📁 Files:
- [backend/database/migrations/2026_01_19_045145_create_attendances_table.php](backend/database/migrations/2026_01_19_045145_create_attendances_table.php)
- [backend/database/migrations/2026_01_26_000002_add_critical_constraints.php](backend/database/migrations/2026_01_26_000002_add_critical_constraints.php)
- [backend/database/migrations/2026_01_29_150000_harden_database_integrity_constraints.php](backend/database/migrations/2026_01_29_150000_harden_database_integrity_constraints.php)

#### ✅ Checklist Database:

- [x] Unique constraint: `student_id + schedule_id + attendance_date` → ✅ Ada
- [x] Foreign key dengan `ON DELETE CASCADE/RESTRICT` → ✅ Ada
- [x] Index untuk performance → ✅ Ada multiple indexes
- [ ] Soft delete-safe unique constraint (partial unique index)

#### 🔧 TODO:
```
[ ] 2.1 Verifikasi soft delete tidak mempengaruhi unique constraint
[ ] 2.2 Buat partial unique index untuk PostgreSQL jika pakai soft delete:
      CREATE UNIQUE INDEX IF NOT EXISTS uk_attendance_active 
      ON attendances (student_id, schedule_id, attendance_date) 
      WHERE deleted_at IS NULL;
[ ] 2.3 Verifikasi FK constraint menggunakan RESTRICT untuk data kritikal
[ ] 2.4 Audit index yang sudah ada untuk overlap/redundancy
```

---

### C. IDEMPOTENCY API ✅ (SUDAH ADA)

#### 📁 Files:
- [backend/app/Services/AttendanceCheckInService.php](backend/app/Services/AttendanceCheckInService.php) - Line 76-102

#### ✅ Checklist API Idempotency:

- [x] Request ID untuk check-in → ✅ Sudah ada `request_id` field
- [x] Idempotency check early exit → ✅ Ada `findByRequestId()`
- [x] Server time digunakan (bukan jam client) → ✅ `now()` Laravel
- [ ] Nonce/one-time token untuk QR scan
- [x] Rate limit di endpoint absensi → ✅ `critical.rate.limit:qr-scan`

#### 🔧 TODO:
```
[ ] 3.1 Verifikasi nonce QR Code divalidasi dan ditandai used
[ ] 3.2 Audit semua endpoint POST untuk idempotency key
[ ] 3.3 Tambahkan header X-Idempotency-Key di response
[ ] 3.4 Set expiry untuk idempotency records (misal 24 jam)
```

---

## 🟠 TAHAP 2 - KUNCI AKSES

### D. BACKEND – CONTROLLER AUDIT ⚠️ (PERLU PERBAIKAN)

#### 📁 Files yang Perlu Diaudit:

| Controller | Status | Issue |
|------------|--------|-------|
| `AttendanceController` | ✅ Clean | Sudah delegasi ke service |
| `TeacherScanController` | ⚠️ | Inline validation, tidak pakai FormRequest |
| `BackupMonitoringController` | ⚠️ | Query langsung di controller |
| `SecurityPolicyController` | ⚠️ | Query langsung, logic kompleks |

#### ✅ Checklist Controller (PER CONTROLLER):

```
[ ] Hanya menerima Request / FormRequest
[ ] Memanggil 1 service utama (bukan banyak logic inline)
[ ] Menggunakan $this->authorize() / Gate::authorize()
[ ] Tidak ada if/else berbasis role panjang
[ ] Tidak ada manipulasi tanggal/waktu manual
[ ] Tidak ada query Eloquent langsung (kecuali read sederhana)
```

#### 🔧 TODO Controller:
```
[ ] 4.1 TeacherScanController: Migrate ke FormRequest
[ ] 4.2 BackupMonitoringController: Pindahkan query ke Repository/Service
[ ] 4.3 SecurityPolicyController: Pindahkan logic ke SecurityPolicyService
[ ] 4.4 Audit semua controller di Api/V1/ untuk authorize() calls
[ ] 4.5 Buat checklist per-controller dan centang satu persatu
```

---

### E. BACKEND – FORM REQUEST AUDIT ⚠️

#### 📁 Files yang Sudah Ada:
- `AttendanceScanRequest` ✅ - Lengkap dengan GPS validation
- `ManualAttendanceRequest` ✅

#### 📁 Files yang BELUM Ada (Perlu Dibuat):
```
[ ] TeacherScanRequest.php → untuk TeacherScanController
[ ] AttendanceCorrectionRequest.php → untuk koreksi absensi
[ ] AttendanceApprovalRequest.php → untuk approval workflow
```

#### ✅ Checklist FormRequest:

- [x] GPS validation (lat, lng, accuracy) → ✅ Ada di `AttendanceScanRequest`
- [x] Device ID validation → ✅ Ada
- [x] Attendance type validation → ⚠️ Perlu verifikasi
- [ ] Custom validation untuk jam absensi
- [ ] Custom validation untuk radius lokasi

#### 🔧 TODO FormRequest:
```
[ ] 5.1 Buat TeacherScanRequest dengan validasi lengkap
[ ] 5.2 Tambahkan custom rule untuk validasi jam absensi
[ ] 5.3 Audit semua endpoint POST/PUT tanpa FormRequest
[ ] 5.4 Pastikan tidak ada Request::all() di manapun
```

---

### F. BACKEND – POLICY & AUTHORIZATION ✅ (SUDAH ADA)

#### 📁 Files:
- [backend/app/Policies/AttendancePolicy.php](backend/app/Policies/AttendancePolicy.php) (487 lines)
- [backend/app/Policies/UserPolicy.php](backend/app/Policies/UserPolicy.php)
- [backend/app/Policies/SchoolPolicy.php](backend/app/Policies/SchoolPolicy.php)
- + 8 policy lainnya

#### ✅ Checklist Policy per Model:

| Model | Policy Ada | view | create | update | delete | approve |
|-------|------------|------|--------|--------|--------|---------|
| Attendance | ✅ | ✅ | ✅ | ✅ | ✅ | 🔍 |
| User | ✅ | 🔍 | 🔍 | 🔍 | 🔍 | - |
| Schedule | ✅ | 🔍 | 🔍 | 🔍 | 🔍 | - |
| School | ✅ | 🔍 | 🔍 | 🔍 | 🔍 | - |
| QrCode | ✅ | 🔍 | 🔍 | 🔍 | 🔍 | - |

#### 🔧 TODO Policy:
```
[ ] 6.1 Audit setiap controller yang TIDAK memanggil authorize()
[ ] 6.2 Verifikasi AttendancePolicy.approve() method ada
[ ] 6.3 Tambahkan policy untuk model Leave jika ada
[ ] 6.4 Audit global scope bypass (withoutGlobalScope) - harus logged
```

---

### G. MOBILE TRUST REMOVAL 🔴 (KRITIS)

#### 📁 Files:
- [AbsensiQRMobile/src/api/attendance.ts](AbsensiQRMobile/src/api/attendance.ts)
- [AbsensiQRMobile/src/screens/attendance/](AbsensiQRMobile/src/screens/attendance/)

#### ✅ Checklist Mobile (CRITICAL):

- [ ] GPS hanya dikirim, TIDAK divalidasi final di client
- [ ] Device ID hanya identifier, bukan kepercayaan
- [ ] Tidak ada logic radius di client
- [ ] Retry + fallback jelas
- [ ] Error message dari backend ditampilkan apa adanya
- [ ] Tidak ada "status sah/tidak" ditentukan di mobile

#### 🔧 TODO Mobile:
```
[ ] 7.1 Audit screens/attendance/* untuk logika bisnis
[ ] 7.2 Hapus semua validasi radius di mobile (jika ada)
[ ] 7.3 Hapus semua penentuan status absensi di mobile
[ ] 7.4 Pastikan semua decision making dari backend response
[ ] 7.5 Review error handling - tidak boleh hide backend error
```

---

## 🟡 TAHAP 3 - STABILKAN

### H. LOGGING & OBSERVABILITY ✅ (SUDAH ADA)

#### 📁 Files:
- [backend/app/Logging/LogContext.php](backend/app/Logging/LogContext.php)
- [backend/app/Logging/JsonLogFormatter.php](backend/app/Logging/JsonLogFormatter.php)
- [backend/app/Services/Logging/AttendanceLogger.php](backend/app/Services/Logging/AttendanceLogger.php)

#### ✅ Checklist Logging:

- [x] Request ID global → ✅ Ada `X-Request-ID` support
- [x] Log correlation → ✅ `trace_id` support
- [ ] Error severity jelas (ERROR vs WARNING vs INFO)
- [ ] Tidak ada silent fail

#### 🔧 TODO Logging:
```
[ ] 8.1 Audit semua catch block untuk silent fails
[ ] 8.2 Standarisasi error severity levels
[ ] 8.3 Tambahkan log untuk setiap attendance state change
[ ] 8.4 Buat alert untuk anomaly (misal: >10 failed scans/minute)
```

---

### I. ERROR RESPONSE CONTRACT ⚠️ (PERLU STANDARISASI)

#### Format Error WAJIB:
```json
{
  "code": "ATTENDANCE_ALREADY_CHECKED_IN",
  "message": "User already checked in today",
  "context": {}
}
```

#### Status Saat Ini:
- `AttendanceException` mengembalikan message tapi tidak ada code
- Response format tidak konsisten antar controller

#### 🔧 TODO Error Contract:
```
[ ] 9.1 Tambahkan error code ke semua AttendanceException factory methods
[ ] 9.2 Buat ErrorResponse DTO/Resource untuk standarisasi
[ ] 9.3 Update semua controller untuk pakai format yang sama
[ ] 9.4 Dokumentasikan semua error codes di API spec
```

---

### J. FRONTEND WEB AUDIT ✅ (CUKUP BAIK)

#### 📁 Files:
- [frontend-web/src/components/common/AppErrorBoundary.tsx](frontend-web/src/components/common/AppErrorBoundary.tsx)
- [frontend-web/src/lib/api.ts](frontend-web/src/lib/api.ts)
- [frontend-web/src/modules/auth/stores/useAuthStore.ts](frontend-web/src/modules/auth/stores/useAuthStore.ts)

#### ✅ Checklist Frontend:

- [ ] Tidak menghitung status absensi sendiri
- [ ] Tidak menebak error code
- [x] State absensi datang dari API → ✅
- [x] Global error boundary → ✅ `AppErrorBoundary`
- [x] Auth flow satu pintu → ✅ `useAuthStore`

#### 🔧 TODO Frontend:
```
[ ] 10.1 Audit semua komponen yang render attendance status
[ ] 10.2 Hapus local status calculation (jika ada)
[ ] 10.3 Verifikasi error handling konsisten
[ ] 10.4 Audit role-based rendering di frontend
```

---

### K. PERFORMANCE INDEX ✅ (SUDAH ADA)

#### 📁 Migrations dengan Index:
- `2026_01_26_000001_add_performance_indexes.php`
- `2026_01_30_000001_add_composite_indexes_for_performance.php`
- `2026_02_02_100000_add_attendance_performance_indexes.php`
- `2026_02_02_110000_add_critical_performance_indexes.php`

#### 🔧 TODO Performance:
```
[ ] 11.1 Run EXPLAIN ANALYZE pada query laporan utama
[ ] 11.2 Audit N+1 queries dengan Laravel Debugbar
[ ] 11.3 Verifikasi index digunakan (tidak covering scan)
[ ] 11.4 Hapus duplicate/unused indexes
```

---

## 📋 SUMMARY PRIORITAS EKSEKUSI

### 🔴 MINGGU 1 - KUNCI DATA (WAJIB SELESAI)
| # | Task | File/Area | Status |
|---|------|-----------|--------|
| 1.1 | Audit modifikasi Attendance di luar service | All controllers | ⬜ |
| 1.2 | Implementasi checkOut method | AttendanceCheckInService | ⬜ |
| 2.1 | Verifikasi soft delete constraint | Migrations | ⬜ |
| 3.1 | Verifikasi nonce QR validation | QrService | ⬜ |
| 7.1-7.5 | Mobile trust removal | AbsensiQRMobile | ⬜ |

### 🟠 MINGGU 2 - KUNCI AKSES
| # | Task | File/Area | Status |
|---|------|-----------|--------|
| 4.1 | TeacherScanController → FormRequest | Controllers | ⬜ |
| 5.1 | Buat TeacherScanRequest | Requests | ⬜ |
| 6.1 | Audit authorize() calls | Controllers | ⬜ |
| 6.4 | Audit global scope bypass | Services | ⬜ |

### 🟡 MINGGU 3 - STABILKAN
| # | Task | File/Area | Status |
|---|------|-----------|--------|
| 8.1 | Audit silent fails | Catch blocks | ⬜ |
| 9.1-9.4 | Error response contract | Exceptions | ⬜ |
| 10.1-10.4 | Frontend audit | frontend-web | ⬜ |
| 11.1-11.4 | Performance audit | Database | ⬜ |

---

## 📝 TEMPLATE AUDIT PER CONTROLLER

Copy template ini untuk setiap controller:

```markdown
### Controller: [NamaController]
📁 Path: backend/app/Http/Controllers/Api/V1/[NamaController].php

| Check | Status | Notes |
|-------|--------|-------|
| Pakai FormRequest | ⬜/✅ | |
| Panggil 1 Service | ⬜/✅ | |
| Ada authorize() | ⬜/✅ | |
| Tidak ada query langsung | ⬜/✅ | |
| Tidak ada logic role | ⬜/✅ | |
| Exception handling benar | ⬜/✅ | |

Issues Found:
- [ ] 

Actions Required:
- [ ] 
```

---

## 🔗 REFERENSI DOKUMEN TERKAIT

- [backend/docs/03_api_specification.md](backend/docs/03_api_specification.md) - API Contract
- [backend/docs/09_security_improvements.md](backend/docs/09_security_improvements.md) - Security Audit
- [backend/docs/10_FINAL_DECISIONS.md](backend/docs/10_FINAL_DECISIONS.md) - QR Token Architecture
- [backend/docs/11_service_implementation.md](backend/docs/11_service_implementation.md) - Service Layer Guide
- [backend/docs/12_AUDIT_FINDINGS.md](backend/docs/12_AUDIT_FINDINGS.md) - Previous Audit

---

> **Catatan:** Checklist ini dibuat berdasarkan hasil analisis otomatis. Beberapa item mungkin sudah terimplementasi tapi tidak terdeteksi. Verifikasi manual tetap diperlukan.

**Last Updated:** 7 Februari 2026
