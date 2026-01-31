# Security Fixes Applied - January 2025

## Overview
This document summarizes all security fixes applied to the AbsensiQRPro project following a comprehensive security audit.

---

## CRITICAL (P0) Fixes

### 1. Mass Assignment Vulnerabilities
**Files Modified:**
- [backend/app/Models/ClassModel.php](backend/app/Models/ClassModel.php)
- [backend/app/Models/Subject.php](backend/app/Models/Subject.php)

**Issue:** Models used `$guarded = []` which allows mass assignment of ANY field including sensitive ones.

**Fix:** Changed to explicit `$fillable` arrays listing only allowed fields.

---

### 2. env() Usage in Runtime (config:cache Incompatibility)
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SuperAdmin/SystemController.php](backend/app/Http/Controllers/Api/V1/SuperAdmin/SystemController.php)
- [backend/app/Console/Commands/BackupDatabase.php](backend/app/Console/Commands/BackupDatabase.php)

**Issue:** Using `env()` at runtime fails after `php artisan config:cache`.

**Fix:** Changed to use `config()` helper which reads from cached configuration.

---

### 3. Command Injection in Backup/System Commands
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SuperAdmin/SystemController.php](backend/app/Http/Controllers/Api/V1/SuperAdmin/SystemController.php)
- [backend/app/Console/Commands/BackupDatabase.php](backend/app/Console/Commands/BackupDatabase.php)

**Issue:** Using `exec()` with password in command line exposes it in process list (`ps aux`).

**Fix:** Changed to Laravel `Process::env()` facade which passes secrets via environment variables (hidden from process list).

---

### 4. Missing Audit Logs for Critical Operations
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SuperAdmin/SchoolController.php](backend/app/Http/Controllers/Api/V1/SuperAdmin/SchoolController.php)

**Issue:** School deletion, activation, and deactivation had no audit trail.

**Fix:** Added comprehensive `Log::channel('security')` logging with IP, user agent, and user ID.

---

### 5. Impersonation Token Without Expiry
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SuperAdmin/SchoolController.php](backend/app/Http/Controllers/Api/V1/SuperAdmin/SchoolController.php)

**Issue:** Impersonation tokens never expired, creating security risk.

**Fix:** Added 2-hour expiry: `now()->addHours(2)` parameter to `createToken()`.

---

### 6. Sensitive Data in Webhook Logs
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/WebhookController.php](backend/app/Http/Controllers/Api/V1/WebhookController.php)

**Issue:** `Log::info('Webhook received', ['payload' => $request->all()])` logged sensitive payment data.

**Fix:** Added `sanitizePayloadForLogging()` method that redacts `signature_key`, card numbers, tokens, etc.

---

### 7. Race Condition in Academic Year Activation
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SchoolAdmin/SchoolController.php](backend/app/Http/Controllers/Api/V1/SchoolAdmin/SchoolController.php)

**Issue:** Concurrent requests could leave multiple academic years as "active".

**Fix:** Added `lockForUpdate()` row-level locking within transaction.

---

### 8. Hardcoded Maintenance Secret
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SuperAdmin/SystemController.php](backend/app/Http/Controllers/Api/V1/SuperAdmin/SystemController.php)

**Issue:** Maintenance mode used hardcoded secret.

**Fix:** Changed to use `config('app.key')` as the secret source.

---

## HIGH (P1) Fixes

### 1. Missing SoftDeletes on Critical Models
**Files Modified:**
- [backend/app/Models/School.php](backend/app/Models/School.php)
- [backend/app/Models/User.php](backend/app/Models/User.php)
- [backend/app/Models/Schedule.php](backend/app/Models/Schedule.php)
- [backend/app/Models/ClassModel.php](backend/app/Models/ClassModel.php)
- [backend/app/Models/Subject.php](backend/app/Models/Subject.php)

**Migration Created:**
- [backend/database/migrations/2025_01_15_000001_add_soft_deletes_to_critical_tables.php](backend/database/migrations/2025_01_15_000001_add_soft_deletes_to_critical_tables.php)

**Issue:** No data recovery capability for accidental deletions.

**Fix:** Added `SoftDeletes` trait and created migration to add `deleted_at` column.

---

### 2. Carbon::parse Without Validation
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/AttendanceController.php](backend/app/Http/Controllers/Api/V1/AttendanceController.php)

**Issue:** `Carbon::parse($request->query('date'))` could throw on invalid date.

**Fix:** Added regex validation for YYYY-MM-DD format and try-catch wrapper.

---

### 3. Generic Exception Messages Exposed to Users
**Files Modified:**
- [backend/app/Http/Controllers/Api/V1/SuperAdmin/PlatformConfigController.php](backend/app/Http/Controllers/Api/V1/SuperAdmin/PlatformConfigController.php)
- [backend/app/Http/Controllers/Api/V1/School/SubscriptionController.php](backend/app/Http/Controllers/Api/V1/School/SubscriptionController.php)
- [backend/app/Http/Controllers/Api/V1/TeacherScanController.php](backend/app/Http/Controllers/Api/V1/TeacherScanController.php)
- [backend/app/Http/Controllers/Api/V1/StudentQrController.php](backend/app/Http/Controllers/Api/V1/StudentQrController.php)

**Exception Classes Created:**
- [backend/app/Exceptions/AttendanceException.php](backend/app/Exceptions/AttendanceException.php)
- [backend/app/Exceptions/QrValidationException.php](backend/app/Exceptions/QrValidationException.php)

**Issue:** `$e->getMessage()` could leak sensitive implementation details.

**Fix:** Created safe exception classes and changed to log errors internally while showing generic messages to users.

---

## MEDIUM (P2) Fixes - Mobile App

### 1. Console.log Not Stripped in Production
**Files Modified:**
- [AbsensiQRMobile/babel.config.js](AbsensiQRMobile/babel.config.js)

**Issue:** Debug logs shipped to production builds.

**Fix:** Added `transform-remove-console` plugin for production builds.

---

### 2. Expo SecureStore Instead of Native Encrypted Storage
**Files Modified:**
- [AbsensiQRMobile/src/api/core.ts](AbsensiQRMobile/src/api/core.ts)

**Issue:** Using Expo's SecureStore which may be less secure than native alternatives.

**Fix:** Changed to use project's `storage.ts` which uses `react-native-encrypted-storage`.

---

### 3. GPS Spoofing Detection
**Files Created:**
- [AbsensiQRMobile/src/utils/locationValidator.ts](AbsensiQRMobile/src/utils/locationValidator.ts)

**Files Modified:**
- [AbsensiQRMobile/src/screens/attendance/ScanQRScreen.tsx](AbsensiQRMobile/src/screens/attendance/ScanQRScreen.tsx)

**Issue:** No validation for mock/spoofed GPS locations.

**Fix:** Created `locationValidator.ts` utility that:
- Detects Android mock location providers
- Validates accuracy (suspiciously perfect accuracy = fake)
- Throws specific error for mock location detection

---

## Configuration Changes

### New Config Entry
**File:** [backend/config/app.php](backend/config/app.php)

Added `backup_encryption_key` config entry to support secure backup encryption.

---

## Migration Required

After applying these changes, run:

```bash
cd backend
php artisan migrate
php artisan config:cache
```

---

## Testing Recommendations

1. **Mass Assignment:** Test that only allowed fields can be mass-assigned
2. **Soft Deletes:** Test that deleted records can be restored
3. **Audit Logs:** Verify critical operations are logged to security channel
4. **Impersonation:** Test that tokens expire after 2 hours
5. **Mobile GPS:** Test with mock location app to verify detection works

---

## Outstanding Items (Lower Priority)

1. Enable SSL pinning in production (already configured, needs certificate hashes)
2. Add `babel-plugin-transform-remove-console` to mobile devDependencies
3. Review remaining `$e->getMessage()` occurrences in services
4. Add additional GPS spoofing indicators (velocity checks, cell tower correlation)
