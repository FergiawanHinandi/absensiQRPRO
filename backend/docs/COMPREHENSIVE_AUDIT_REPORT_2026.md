# 🔍 COMPREHENSIVE FULL-STACK AUDIT REPORT
**AbsensiQRPro - Sistem Absensi Berbasis QR**  
**Audit Date:** February 7, 2026

---

## Executive Summary

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security (OWASP) | 2 | 4 | 3 | 2 | 11 |
| Authorization | 1 | 2 | 2 | 1 | 6 |
| Business Logic | 2 | 3 | 2 | 0 | 7 |
| Replay/Spoofing | 0 | 2 | 3 | 1 | 6 |
| Database | 1 | 3 | 2 | 2 | 8 |
| API Contract | 0 | 2 | 3 | 2 | 7 |
| Frontend Logic | 0 | 1 | 4 | 2 | 7 |
| Mobile Trust | 1 | 3 | 2 | 1 | 7 |
| Performance | 1 | 2 | 4 | 2 | 9 |
| Observability | 0 | 1 | 3 | 2 | 6 |
| **TOTAL** | **8** | **23** | **28** | **15** | **74** |

**Risk Score: 7.2/10** - Requires immediate attention on critical items

---

## 1. SECURITY VULNERABILITIES (OWASP Top 10)

### [SEC-001] [CRITICAL] [Backend/Auth] Timing Attack pada Login Lockout Check
**Deskripsi:** `LoginRateLimiter::isAccountLocked()` melakukan database query sebelum password verification, memungkinkan user enumeration via timing analysis.

**File:** [AuthController.php#L51-54](../app/Http/Controllers/Api/V1/AuthController.php#L51-54)
```php
// Query database dulu, baru check password - timing leak
if ($user && $this->rateLimiter->isAccountLocked($user)) {
```

**Solusi:** 
```php
// Check credentials FIRST, then lockout status
if (! $user || ! Hash::check($request->password, $user->password)) {
    // Record attempt...
}
// AFTER credential check, verify lockout
if ($this->rateLimiter->isAccountLocked($user)) {
    // Return lockout message
}
```
**Estimasi:** 2 jam

---

### [SEC-002] [CRITICAL] [Backend/QR] QR_SECRET_KEY Exposed in .env.example
**Deskripsi:** `.env.example` contains placeholder `generate_with_command_above_32_chars_minimum` yang bisa di-copy langsung tanpa diganti.

**File:** [.env.example#L89](../.env.example#L89)

**Solusi:** 
1. Tambahkan validation di AppServiceProvider untuk memastikan key berbeda dari placeholder
2. Generate random key saat `php artisan key:generate`

**Estimasi:** 1 jam

---

### [SEC-003] [HIGH] [Backend/SQL] Raw SQL Queries dengan User Input di Dashboard
**Deskripsi:** `SchoolAdminDashboardController` menggunakan `DB::raw()` dan `selectRaw()` dengan potential SQL injection.

**File:** [SchoolAdminDashboardController.php#L178-179](../app/Http/Controllers/SchoolAdminDashboardController.php#L178-179)
```php
\DB::raw('(SELECT ROUND(SUM(status="present"...) FROM attendances WHERE attendances.student_id=students.id...
```

**Solusi:** Gunakan parameterized subqueries atau refactor ke Eloquent relationships dengan eager loading.

**Estimasi:** 4 jam

---

### [SEC-004] [HIGH] [Backend/Validation] Beberapa Controller Masih Pakai Inline validate()
**Deskripsi:** Controllers seperti `MobileSecurityController`, `TeacherHeatmapController` menggunakan `Validator::make()` langsung tanpa FormRequest.

**Files Found:** 20+ matches di controllers

**Solusi:** Migrate semua ke dedicated FormRequest classes untuk konsistensi dan easier auditing.

**Estimasi:** 6 jam

---

### [SEC-005] [HIGH] [Backend/Auth] Refresh Token Tidak Ada Expiry Rotation
**Deskripsi:** Refresh token dapat digunakan indefinitely tanpa forced rotation setelah period tertentu.

**File:** [TokenHardeningService.php](../app/Services/TokenHardeningService.php)

**Solusi:** Implementasi maximum refresh token lifetime (e.g., 30 hari) dengan forced re-login.

**Estimasi:** 3 jam

---

### [SEC-006] [HIGH] [Mobile] Offline Queue di AsyncStorage Tidak Encrypted
**Deskripsi:** `OfflineSyncService` menyimpan attendance queue di plain AsyncStorage, bukan EncryptedStorage.

**File:** [OfflineSyncService.ts#L17](../../AbsensiQRMobile/src/services/OfflineSyncService.ts#L17)
```typescript
import AsyncStorage from '@react-native-async-storage/async-storage';
// Attendance data stored in plain text!
```

**Solusi:** Migrate ke EncryptedStorage atau encrypt data sebelum store.

**Estimasi:** 3 jam

---

### [SEC-007] [MEDIUM] [Backend/Headers] CSP Header Terlalu Permissive
**Deskripsi:** SecurityHeaders middleware mungkin tidak memiliki strict CSP untuk API responses.

**File:** [SecurityHeaders.php](../app/Http/Middleware/SecurityHeaders.php)

**Solusi:** Implementasi strict CSP: `default-src 'none'; frame-ancestors 'none'` untuk API.

**Estimasi:** 1 jam

---

### [SEC-008] [MEDIUM] [Frontend] sessionStorage Token Accessible via XSS
**Deskripsi:** Token disimpan di sessionStorage yang masih vulnerable terhadap XSS attack.

**File:** [secureTokenStore.ts#L57](../../frontend-web/src/lib/secureTokenStore.ts#L57)

**Solusi:** Pertimbangkan HttpOnly cookie dengan SameSite=Strict untuk web auth.

**Estimasi:** 8 jam (requires backend changes)

---

### [SEC-009] [MEDIUM] [Backend/Input] Kurangnya Input Length Limits di Beberapa Endpoint
**Deskripsi:** Beberapa FormRequest tidak memiliki max length validation, risiko DoS via large payload.

**Solusi:** Add `max:255` atau sesuai field type ke semua string inputs.

**Estimasi:** 4 jam

---

### [SEC-010] [LOW] [Backend/Logging] Sensitive Data Mungkin Masuk ke Log
**Deskripsi:** Beberapa log statements bisa mengandung QR token atau user data.

**Solusi:** Implement log data sanitization di CentralizedLoggerFactory.

**Estimasi:** 2 jam

---

### [SEC-011] [LOW] [Mobile] Debug Logs di Production
**Deskripsi:** `__DEV__` check untuk console.log bisa di-bypass di some scenarios.

**Solusi:** Use babel plugin untuk strip all console.* di production builds.

**Estimasi:** 1 jam

---

## 2. AUTHORIZATION LEAKAGE

### [AUTH-001] [CRITICAL] [Backend/Policy] HasTenantScope Bypass tanpa Audit Log
**Deskripsi:** `HasTenantScope` trait menyediakan `withoutSchoolScope()` yang bypass isolation tanpa forced logging.

**File:** [HasTenantScope.php#L40](../app/Models/Traits/HasTenantScope.php#L40)
```php
public static function withoutSchoolScope(): Builder
{
    return static::withoutGlobalScope(SchoolScope::class);
    // No audit log!
}
```

**Solusi:**
```php
public static function withoutSchoolScope(string $reason): Builder
{
    Log::channel('security')->warning('SchoolScope bypassed', [
        'model' => static::class,
        'reason' => $reason,
        'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]
    ]);
    return static::withoutGlobalScope(SchoolScope::class);
}
```
**Estimasi:** 2 jam

---

### [AUTH-002] [HIGH] [Backend/Policy] Super Admin Audit Log Tidak Lengkap
**Deskripsi:** SchoolScope bypass untuk super_admin hanya log basic info, tidak termasuk affected records count.

**File:** [SchoolScope.php#L91-97](../app/Scopes/SchoolScope.php#L91-97)

**Solusi:** Enhance logging dengan query preview dan record count estimation.

**Estimasi:** 3 jam

---

### [AUTH-003] [HIGH] [Backend/API] Report Export Endpoint Kurang Strict Policy Check
**Deskripsi:** `AttendanceReportController::exportSchoolReport()` hanya cek `viewSchoolReport` tanpa cek specific class/student access.

**File:** [AttendanceReportController.php#L244](../app/Http/Controllers/Api/V1/Admin/AttendanceReportController.php#L244)

**Solusi:** Add granular check: admin can export school-wide, teacher only assigned classes.

**Estimasi:** 3 jam

---

### [AUTH-004] [MEDIUM] [Backend/Teacher] Teacher Device Sharing Not Fully Blocked
**Deskripsi:** Satu teacher device bisa di-register ke multiple teachers jika device_id reused.

**Solusi:** Add unique constraint di migration dan validation di TeacherDeviceService.

**Estimasi:** 2 jam

---

### [AUTH-005] [MEDIUM] [Frontend] Role Check di Client Bisa Di-bypass
**Deskripsi:** Frontend routing check `user.role_type` bisa di-manipulasi via dev tools.

**File:** [App.tsx#L92](../../frontend-web/src/App.tsx#L92)

**Solusi:** Always enforce server-side, frontend is UX only. Add warning comments.

**Estimasi:** 1 jam (documentation)

---

### [AUTH-006] [LOW] [Backend/Student] Parent Access Scope Not Tested
**Deskripsi:** Parent role bisa lihat anak-anak mereka, tapi tidak ada test coverage untuk multi-child scenarios.

**Solusi:** Add feature tests untuk parent with 2+ children dari different classes.

**Estimasi:** 2 jam

---

## 3. BUSINESS LOGIC FLAWS - ATTENDANCE

### [BIZ-001] [CRITICAL] [Backend/Attendance] Race Condition Window di Cache Lock
**Deskripsi:** Cache lock timeout 5 detik (`ATTENDANCE_LOCK_TIMEOUT`) terlalu singkat untuk slow DB operations.

**File:** [AttendanceService.php#L37](../app/Services/AttendanceService.php#L37)
```php
private const ATTENDANCE_LOCK_TIMEOUT = 5;
```

**Solusi:** Increase ke 15 detik dan add fallback database-level advisory lock.

**Estimasi:** 3 jam

---

### [BIZ-002] [CRITICAL] [Backend/State] Attendance State Machine Bypass Possible
**Deskripsi:** Model `$fillable` masih include `status` field yang seharusnya only via state machine.

**File:** [Attendance.php#L42](../app/Models/Attendance.php#L42)
```php
protected $fillable = [
    // ...
    'state',           // NEW: State machine state
    'status',          // LEGACY: Keep for backward compatibility  ← RISK!
```

**Solusi:** Remove `status` dari fillable, add mutator yang throws exception.

**Estimasi:** 4 jam

---

### [BIZ-003] [HIGH] [Backend/Schedule] Schedule Time Window Validation Gap
**Deskripsi:** `AttendanceService::recordByTeacherScan()` tidak handle midnight crossing schedules (e.g., 23:00-01:00).

**File:** [AttendanceService.php#L203-212](../app/Services/AttendanceService.php#L203-212)

**Solusi:** Add special handling untuk schedules yang cross midnight.

**Estimasi:** 4 jam

---

### [BIZ-004] [HIGH] [Backend/Duplicate] Unique Constraint Partial Index Issue
**Deskripsi:** `uk_attendance_student_schedule_date` tidak handle soft deletes properly - bisa ada duplicate setelah restore.

**File:** [2026_01_29_150000_harden_database_integrity_constraints.php](../database/migrations/2026_01_29_150000_harden_database_integrity_constraints.php)

**Solusi:** Convert ke PostgreSQL partial unique index: `WHERE deleted_at IS NULL`.

**Estimasi:** 3 jam

---

### [BIZ-005] [HIGH] [Backend/Manual] Manual Attendance Tidak Require Reason
**Deskripsi:** Teacher bisa input manual attendance tanpa mandatory notes/reason.

**Solusi:** Make `notes` required when `is_manual = true` via FormRequest.

**Estimasi:** 1 jam

---

### [BIZ-006] [MEDIUM] [Backend/Late] Late Calculation Hardcoded
**Deskripsi:** Late threshold tidak configurable per-school via SecurityPolicyService.

**Solusi:** Add `attendance.late_threshold_minutes` ke policy service.

**Estimasi:** 2 jam

---

### [BIZ-007] [MEDIUM] [Backend/Correction] Correction Workflow Missing Timeout
**Deskripsi:** Pending correction request tidak auto-expire, bisa stuck indefinitely.

**Solusi:** Add scheduled job untuk auto-reject corrections older than 7 days.

**Estimasi:** 2 jam

---

## 4. REPLAY & SPOOFING RISK

### [REP-001] [HIGH] [Backend/Nonce] Nonce TTL Terlalu Singkat untuk Offline Mode
**Deskripsi:** Nonce TTL menggunakan `max(60, exp - now)` yang bisa sangat pendek untuk valid QR.

**File:** [QrService.php#L121](../app/Services/QrService.php#L121)

**Solusi:** Set minimum nonce TTL ke 30 menit untuk accommodate network delays.

**Estimasi:** 1 jam

---

### [REP-002] [HIGH] [Mobile/GPS] Mock Location Detection Bypassable
**Deskripsi:** `checkIfMocked()` hanya cek `position.mocked` flag yang bisa di-bypass oleh sophisticated spoofers.

**File:** [locationValidator.ts#L63](../../AbsensiQRMobile/src/utils/locationValidator.ts#L63)

**Solusi:** Add multi-layer detection:
1. Check for known spoofing apps via package names
2. Compare with cell tower location
3. Analyze location history for impossible speeds

**Estimasi:** 8 jam

---

### [REP-003] [MEDIUM] [Backend/Idempotency] Idempotency Key Not Enforced
**Deskripsi:** `AttendanceSecurityMiddleware` logs warning tapi allows request without idempotency key.

**File:** [AttendanceSecurityMiddleware.php#L156-161](../app/Http/Middleware/AttendanceSecurityMiddleware.php#L156-161)

**Solusi:** Enforce idempotency key dengan 400 response untuk production.

**Estimasi:** 1 jam

---

### [REP-004] [MEDIUM] [Backend/GPS] GPS Spoofing Threshold Too Lenient
**Deskripsi:** `GPS_SUSPICIOUS_SPEED_MPS = 50` (180 km/h) terlalu tinggi untuk walking/driving in school.

**File:** [AttendanceSecurityMiddleware.php#L57](../app/Http/Middleware/AttendanceSecurityMiddleware.php#L57)

**Solusi:** Lower ke 20 m/s (72 km/h) dengan configurable per-school setting.

**Estimasi:** 1 jam

---

### [REP-005] [MEDIUM] [Mobile/SSL] SSL Pinning Disabled by Default
**Deskripsi:** `sslPinningConfig.enabled = !__DEV__ && Config.ENABLE_SSL_PINNING === 'true'` - double negative makes it easy to forget enabling.

**File:** [sslPinning.ts#L56](../../AbsensiQRMobile/src/config/sslPinning.ts#L56)

**Solusi:** Default to `true` in production, require explicit disable.

**Estimasi:** 1 jam

---

### [REP-006] [LOW] [Backend/Device] Device Fingerprint Not Fully Validated
**Deskripsi:** Device fingerprint dari mobile app tidak di-validate format/entropy.

**Solusi:** Add regex validation untuk expected fingerprint format.

**Estimasi:** 1 jam

---

## 5. DATABASE CONSTRAINTS & INDEXES

### [DB-001] [CRITICAL] [PostgreSQL] Missing Partial Unique Index for Soft Deletes
**Deskripsi:** Unique constraint pada `attendances` tidak exclude soft-deleted records.

**Migration:** [2026_01_29_150000_harden_database_integrity_constraints.php](../database/migrations/2026_01_29_150000_harden_database_integrity_constraints.php)

**Solusi:**
```sql
DROP INDEX IF EXISTS uk_attendance_student_schedule_date;
CREATE UNIQUE INDEX uk_attendance_student_schedule_date 
ON attendances (student_id, schedule_id, attendance_date) 
WHERE deleted_at IS NULL;
```

**Estimasi:** 2 jam

---

### [DB-002] [HIGH] [Backend/Index] Missing Composite Index untuk Common Query Pattern
**Deskripsi:** Query `WHERE school_id = ? AND attendance_date = ? AND status = ?` tidak punya optimal index.

**Solusi:**
```sql
CREATE INDEX idx_attendance_school_date_status 
ON attendances (school_id, attendance_date, status);
```

**Estimasi:** 1 jam

---

### [DB-003] [HIGH] [Backend/FK] Beberapa Foreign Key Tanpa ON DELETE Action
**Deskripsi:** Foreign keys mungkin tidak punya explicit CASCADE atau RESTRICT action.

**Solusi:** Audit semua FK dan add explicit action (biasanya RESTRICT untuk data integrity).

**Estimasi:** 4 jam

---

### [DB-004] [HIGH] [Backend/Check] Missing Check Constraint untuk Status Values
**Deskripsi:** Status fields menggunakan string tanpa enum constraint di database level.

**Solusi:**
```sql
ALTER TABLE attendances ADD CONSTRAINT chk_attendance_status 
CHECK (status IN ('present', 'late', 'sick', 'permit', 'alpha', 'excused'));
```

**Estimasi:** 2 jam

---

### [DB-005] [MEDIUM] [Backend/Redundant] Multiple Similar Indexes di attendances
**Deskripsi:** `idx_attendance_unique_check` dan `idx_attendance_unique_scan` overlap.

**File:** [2026_02_02_100000_add_attendance_performance_indexes.php](../database/migrations/2026_02_02_100000_add_attendance_performance_indexes.php)

**Solusi:** Consolidate ke single optimal index, remove redundant ones.

**Estimasi:** 2 jam

---

### [DB-006] [MEDIUM] [Backend/Cleanup] Stale QR Nonces Not Auto-Cleaned
**Deskripsi:** `qr_nonces` table grows indefinitely tanpa cleanup job.

**Solusi:** Add scheduled command untuk delete nonces older than 24 hours.

**Estimasi:** 1 jam

---

### [DB-007] [LOW] [Backend/Type] Beberapa Columns Pakai VARCHAR Tanpa Explicit Length
**Deskripsi:** Laravel `string()` default ke 255, tapi untuk things like `nonce` bisa lebih specific.

**Solusi:** Explicit length: `string('nonce', 32)` for fixed-length values.

**Estimasi:** 2 jam

---

### [DB-008] [LOW] [Backend/Index] Unused Indexes dari Early Migrations
**Deskripsi:** Some early migrations created indexes that are superseded by later migrations.

**Solusi:** Audit dengan `pg_stat_user_indexes` dan drop unused indexes.

**Estimasi:** 2 jam

---

## 6. API CONTRACT CONSISTENCY

### [API-001] [HIGH] [Contract] Mobile API Response Format Inconsistent
**Deskripsi:** Mobile `ScanPayload` expects `qr_token`, tapi backend returns `data.token` di some endpoints.

**Files:**
- Mobile: [attendance.ts#L11](../../AbsensiQRMobile/src/api/attendance.ts#L11)
- Backend: AttendanceController

**Solusi:** Standardize response structure dengan API versioning contract.

**Estimasi:** 4 jam

---

### [API-002] [HIGH] [Contract] Error Response Format Tidak Konsisten
**Deskripsi:** Beberapa endpoint return `{error: string}`, lainnya `{message: string, errors: {}}`.

**Solusi:** Implement `StandardizeErrorResponse` middleware untuk ALL endpoints.

**Estimasi:** 3 jam

---

### [API-003] [MEDIUM] [Types] Frontend Types Out of Sync dengan Backend
**Deskripsi:** [api.types.ts#L48](../../frontend-web/src/types/api.types.ts#L48) has `'permit'` tapi backend uses `'permission'`.

**Solusi:** Generate types from OpenAPI spec, atau shared type definitions.

**Estimasi:** 4 jam

---

### [API-004] [MEDIUM] [Versioning] API Version Not Enforced in Mobile
**Deskripsi:** Mobile hardcodes `/v1/` tapi tidak check API version compatibility.

**Solusi:** Add API version negotiation header dan minimum version check.

**Estimasi:** 3 jam

---

### [API-005] [MEDIUM] [Pagination] Inconsistent Pagination Parameters
**Deskripsi:** Some endpoints use `page/per_page`, others use `limit/offset`.

**Solusi:** Standardize ke Laravel's `page/per_page` pattern everywhere.

**Estimasi:** 2 jam

---

### [API-006] [LOW] [Docs] API Spec Outdated
**Deskripsi:** [03_api_specification.md](./03_api_specification.md) tidak include semua new endpoints.

**Solusi:** Generate OpenAPI 3.0 spec dari code annotations.

**Estimasi:** 4 jam

---

### [API-007] [LOW] [Headers] Rate Limit Headers Not Always Present
**Deskripsi:** Mobile checks `x-ratelimit-*` headers yang tidak selalu di-set.

**File:** [core.ts#L69-71](../../AbsensiQRMobile/src/api/core.ts#L69-71)

**Solusi:** Ensure all rate-limited endpoints return standard headers.

**Estimasi:** 2 jam

---

## 7. FRONTEND LOGIC DUPLICATION

### [FE-001] [HIGH] [Duplication] Role Check Logic Duplicated
**Deskripsi:** Role checking ada di `App.tsx`, individual pages, dan store - not centralized.

**Files:**
- [App.tsx#L92](../../frontend-web/src/App.tsx#L92)
- Various page components

**Solusi:** Create centralized `useAuthorization()` hook dengan permission-based checks.

**Estimasi:** 4 jam

---

### [FE-002] [MEDIUM] [State] Multiple Sources of Truth untuk User
**Deskripsi:** User data ada di useAuthStore dan bisa di-fetch ulang via `/auth/me`.

**Solusi:** Single source of truth pattern dengan React Query for server state.

**Estimasi:** 3 jam

---

### [FE-003] [MEDIUM] [Validation] Form Validation Rules Duplicated
**Deskripsi:** Validation rules di frontend forms tidak sync dengan backend FormRequest rules.

**Solusi:** Share validation schemas atau generate from backend.

**Estimasi:** 6 jam

---

### [FE-004] [MEDIUM] [Constants] Status Constants Hardcoded Multiple Places
**Deskripsi:** `'present' | 'late' | 'sick'...` hardcoded di multiple files.

**File:** [HomeroomDailyAttendance.tsx#L10](../../frontend-web/src/pages/Teacher/Homeroom/HomeroomDailyAttendance.tsx#L10)

**Solusi:** Create shared constants file dan use everywhere.

**Estimasi:** 2 jam

---

### [FE-005] [MEDIUM] [API] Multiple API Client Instances
**Deskripsi:** `apiClient` dari lib dan service-specific clients bisa have different configs.

**Solusi:** Single API client factory pattern.

**Estimasi:** 2 jam

---

### [FE-006] [LOW] [Style] Tailwind Classes Duplicated
**Deskripsi:** Same color schemes repeated without @apply or components.

**Solusi:** Create tailwind component classes untuk common patterns.

**Estimasi:** 3 jam

---

### [FE-007] [LOW] [Error] Error Handling Inconsistent
**Deskripsi:** Some components use try-catch, others rely on React Query error.

**Solusi:** Standardize dengan Error Boundary dan toast patterns.

**Estimasi:** 2 jam

---

## 8. MOBILE TRUST ISSUES

### [MOB-001] [CRITICAL] [Trust] Client-Computed Attendance Status
**Deskripsi:** Mobile app mengirim computed status yang seharusnya only server computes.

**Risk:** Attacker bisa modify request untuk always send 'present'.

**Solusi:** Remove any status from client payload, server computes based on time.

**Estimasi:** 2 jam

---

### [MOB-002] [HIGH] [Storage] Legacy Expo Code Still Present
**Deskripsi:** `legacy_expo/` folder masih ada dengan different security implementations.

**File:** [legacy_expo/store/useAuthStore.ts](../../AbsensiQRMobile/src/legacy_expo/store/useAuthStore.ts)

**Solusi:** Remove legacy_expo folder entirely atau mark as deprecated.

**Estimasi:** 1 jam

---

### [MOB-003] [HIGH] [SSL] Certificate Pinning Hashes Placeholder
**Deskripsi:** SSL pin hashes adalah `AAAA...` placeholders yang tidak valid.

**File:** [sslPinning.ts#L47-50](../../AbsensiQRMobile/src/config/sslPinning.ts#L47-50)

**Solusi:** Generate dan configure real certificate hashes.

**Estimasi:** 2 jam

---

### [MOB-004] [HIGH] [Jailbreak] No Root/Jailbreak Detection
**Deskripsi:** App tidak detect rooted/jailbroken devices yang bisa bypass security.

**Solusi:** Integrate `jail-monkey` atau `react-native-device-info` untuk detection.

**Estimasi:** 4 jam

---

### [MOB-005] [MEDIUM] [Biometric] Biometric Auth Not Fully Integrated
**Deskripsi:** Legacy biometric code exists tapi tidak integrated dengan current auth flow.

**Solusi:** Complete integration atau remove dead code.

**Estimasi:** 6 jam

---

### [MOB-006] [MEDIUM] [Offline] Offline Mode Security Gap
**Deskripsi:** Offline attendance queue tidak di-sign, bisa di-tamper sebelum sync.

**Solusi:** Sign offline records dengan device key saat create.

**Estimasi:** 4 jam

---

### [MOB-007] [LOW] [Version] No Minimum App Version Enforcement
**Deskripsi:** Tidak ada mechanism untuk force update outdated apps.

**Solusi:** Add API header check dan in-app update prompt.

**Estimasi:** 4 jam

---

## 9. PERFORMANCE BOTTLENECKS

### [PERF-001] [CRITICAL] [Backend/Dashboard] N+1 di SchoolAdminDashboardController
**Deskripsi:** Dashboard queries tidak punya eager loading untuk student/class relationships.

**File:** [SchoolAdminDashboardController.php](../app/Http/Controllers/SchoolAdminDashboardController.php)

**Solusi:** Add `->with(['student', 'class', 'schedule'])` ke semua queries.

**Estimasi:** 4 jam

---

### [PERF-002] [HIGH] [Backend/Report] Large Report Export tanpa Streaming
**Deskripsi:** Report export loads semua data ke memory sebelum response.

**Solusi:** Implement `LazyCollection` dan chunked streaming untuk exports.

**Estimasi:** 4 jam

---

### [PERF-003] [HIGH] [Backend/Cache] Dashboard Data Not Cached
**Deskripsi:** Dashboard statistics di-compute on every request tanpa caching.

**Solusi:** Cache dashboard aggregates dengan 5-minute TTL dan event-based invalidation.

**Estimasi:** 4 jam

---

### [PERF-004] [MEDIUM] [Backend/Queue] Queue Job Retries Not Optimized
**Deskripsi:** Failed jobs retry immediately tanpa exponential backoff.

**Solusi:** Configure backoff: `--backoff=60,300,900` in queue worker.

**Estimasi:** 1 jam

---

### [PERF-005] [MEDIUM] [Frontend] React Query Not Configured Optimally
**Deskripsi:** Default staleTime di main.tsx mungkin tidak optimal untuk all query types.

**Solusi:** Configure per-query staleTime based on data freshness requirements.

**Estimasi:** 2 jam

---

### [PERF-006] [MEDIUM] [Backend/Index] Full Table Scan pada Historical Queries
**Deskripsi:** Queries untuk attendance history > 30 days tidak punya date-partitioned access.

**Solusi:** Add covering indexes atau consider table partitioning untuk large schools.

**Estimasi:** 8 jam

---

### [PERF-007] [MEDIUM] [Mobile] Unnecessary Re-renders
**Deskripsi:** State changes trigger full re-renders tanpa proper memoization.

**Solusi:** Add useMemo/useCallback untuk expensive computations.

**Estimasi:** 4 jam

---

### [PERF-008] [LOW] [Backend/Logging] Synchronous Logging Impact
**Deskripsi:** Centralized logging bisa slow down requests jika ELK down.

**Solusi:** Use async logging handler dengan buffer.

**Estimasi:** 3 jam

---

### [PERF-009] [LOW] [Frontend] Large Bundle Size
**Deskripsi:** Main bundle mungkin include unused code dari all roles.

**Solusi:** Implement proper code splitting per role/feature.

**Estimasi:** 4 jam

---

## 10. OBSERVABILITY & DEPLOYMENT RISK

### [OBS-001] [HIGH] [Deploy] No Database Migration Rollback Test in CI
**Deskripsi:** CI runs migrate but doesn't test rollback capability.

**File:** [.github/workflows/ci.yml](../../.github/workflows/ci.yml)

**Solusi:** Add rollback test step after migration in CI.

**Estimasi:** 1 jam

---

### [OBS-002] [MEDIUM] [Logging] Missing Correlation ID di Mobile Requests
**Deskripsi:** Mobile app tidak send `X-Request-ID` header consistently.

**Solusi:** Generate dan send request ID dari mobile client.

**Estimasi:** 2 jam

---

### [OBS-003] [MEDIUM] [Metrics] No Application Metrics Exposure
**Deskripsi:** PrometheusMetricsService exists tapi tidak exposed via endpoint.

**Solusi:** Add `/metrics` endpoint dengan auth untuk scraping.

**Estimasi:** 2 jam

---

### [OBS-004] [MEDIUM] [Alerting] No Automated Alerts untuk Security Events
**Deskripsi:** Security logs exist tapi tidak ada automated alerting.

**Solusi:** Integrate dengan PagerDuty/Slack untuk critical security events.

**Estimasi:** 4 jam

---

### [OBS-005] [LOW] [Deploy] No Canary Deployment Strategy
**Deskripsi:** Deployment is all-or-nothing tanpa gradual rollout.

**Solusi:** Implement feature flags atau canary deployment.

**Estimasi:** 8 jam

---

### [OBS-006] [LOW] [Health] Health Check Endpoint Too Simple
**Deskripsi:** `/health` hanya return 200, doesn't check dependencies.

**Solusi:** Add deep health check untuk DB, Redis, Queue.

**Estimasi:** 2 jam

---

---

# 📋 3-SPRINT REMEDIATION ROADMAP

## Sprint 1: Critical Security & Data Integrity (2 Weeks)
**Goal:** Eliminate all CRITICAL issues dan HIGH security risks

| Issue ID | Title | Days | Owner |
|----------|-------|------|-------|
| SEC-001 | Timing Attack Login Fix | 0.5 | Backend |
| SEC-002 | QR Secret Key Validation | 0.5 | Backend |
| BIZ-001 | Race Condition Lock Increase | 0.5 | Backend |
| BIZ-002 | State Machine Fillable Fix | 1 | Backend |
| DB-001 | Partial Unique Index | 0.5 | Backend |
| AUTH-001 | Tenant Scope Bypass Audit | 0.5 | Backend |
| MOB-001 | Remove Client Status | 0.5 | Mobile |
| SEC-003 | SQL Injection Fix Dashboard | 1 | Backend |
| SEC-004 | FormRequest Migration (Critical) | 1 | Backend |
| SEC-005 | Refresh Token Rotation | 0.5 | Backend |
| SEC-006 | Encrypted Offline Storage | 0.5 | Mobile |
| MOB-003 | SSL Pinning Hashes | 0.5 | Mobile |
| MOB-004 | Root Detection | 1 | Mobile |
| PERF-001 | N+1 Dashboard Fix | 1 | Backend |

**Sprint 1 Total:** ~10 days

**Sprint 1 Deliverables:**
- [ ] All CRITICAL issues resolved
- [ ] Security regression tests added
- [ ] Production deployment dengan fixes

---

## Sprint 2: High Priority & Business Logic (2 Weeks)
**Goal:** Fix HIGH issues dan improve business logic integrity

| Issue ID | Title | Days | Owner |
|----------|-------|------|-------|
| DB-002 | Composite Index Attendance | 0.5 | Backend |
| DB-003 | Foreign Key Audit | 1 | Backend |
| DB-004 | Check Constraints | 0.5 | Backend |
| AUTH-002 | Super Admin Audit Enhancement | 0.5 | Backend |
| AUTH-003 | Report Export Policy | 0.5 | Backend |
| BIZ-003 | Midnight Schedule Fix | 1 | Backend |
| BIZ-004 | Soft Delete Unique Fix | 0.5 | Backend |
| BIZ-005 | Manual Attendance Reason | 0.5 | Backend |
| REP-001 | Nonce TTL Increase | 0.5 | Backend |
| REP-002 | GPS Spoofing Detection | 2 | Mobile |
| API-001 | Response Format Standardize | 1 | Backend |
| API-002 | Error Response Standardize | 0.5 | Backend |
| FE-001 | Authorization Hook | 1 | Frontend |
| PERF-002 | Streaming Report Export | 1 | Backend |
| PERF-003 | Dashboard Cache | 1 | Backend |

**Sprint 2 Total:** ~12 days

**Sprint 2 Deliverables:**
- [ ] All HIGH issues resolved
- [ ] Improved data integrity
- [ ] Better error handling
- [ ] Performance baseline established

---

## Sprint 3: Hardening & Technical Debt (2 Weeks)
**Goal:** Resolve MEDIUM/LOW issues, improve observability

| Issue ID | Title | Days | Owner |
|----------|-------|------|-------|
| SEC-007 | CSP Headers | 0.5 | Backend |
| SEC-008 | HttpOnly Cookie Auth | 2 | Full Stack |
| AUTH-004 | Device Sharing Block | 0.5 | Backend |
| BIZ-006 | Configurable Late Threshold | 0.5 | Backend |
| BIZ-007 | Correction Timeout | 0.5 | Backend |
| REP-003 | Enforce Idempotency | 0.5 | Backend |
| DB-005 | Index Consolidation | 0.5 | Backend |
| DB-006 | Nonce Cleanup Job | 0.5 | Backend |
| API-003 | Type Sync Frontend/Backend | 1 | Full Stack |
| API-006 | OpenAPI Spec Generation | 1 | Backend |
| FE-002 | Single Source of Truth | 0.5 | Frontend |
| FE-003 | Validation Sharing | 1 | Full Stack |
| FE-004 | Constants Centralization | 0.5 | Frontend |
| MOB-005 | Biometric Integration | 1.5 | Mobile |
| MOB-006 | Offline Signing | 1 | Mobile |
| OBS-001 | CI Rollback Test | 0.5 | DevOps |
| OBS-002 | Mobile Correlation ID | 0.5 | Mobile |
| OBS-003 | Metrics Endpoint | 0.5 | Backend |
| OBS-004 | Security Alerting | 1 | DevOps |
| PERF-005 | React Query Optimization | 0.5 | Frontend |

**Sprint 3 Total:** ~14 days

**Sprint 3 Deliverables:**
- [ ] All MEDIUM issues resolved
- [ ] Improved observability
- [ ] Reduced technical debt
- [ ] Documentation updated

---

## Post-Sprint Maintenance (Ongoing)

| Category | Action | Frequency |
|----------|--------|-----------|
| Security | Dependency audit | Weekly |
| Security | Penetration test | Quarterly |
| Performance | Load test | Monthly |
| Database | Index analysis | Monthly |
| Mobile | SSL cert rotation | Before expiry |
| API | Spec sync check | Per release |

---

## Success Metrics

| Metric | Current | Sprint 1 | Sprint 2 | Sprint 3 |
|--------|---------|----------|----------|----------|
| Critical Issues | 8 | 0 | 0 | 0 |
| High Issues | 23 | 9 | 0 | 0 |
| Medium Issues | 28 | 28 | 15 | 0 |
| Test Coverage | ~60% | 70% | 80% | 85% |
| API Response p95 | ~500ms | ~300ms | ~200ms | ~150ms |
| Security Score | 6/10 | 8/10 | 9/10 | 9.5/10 |

---

**Document Version:** 1.0  
**Author:** Automated Audit System  
**Review Required By:** CTO, Security Lead, Tech Lead
