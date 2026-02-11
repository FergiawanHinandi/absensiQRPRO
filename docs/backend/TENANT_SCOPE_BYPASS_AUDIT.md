# Tenant Scope Bypass Audit - withoutSchoolScope()

## 📋 Overview

**Version:** 1.0.0  
**Date:** 2026-02-07  
**Status:** ✅ COMPLETED

### Objective
Audit penggunaan `withoutGlobalScope()` / `withoutSchoolScope()` untuk mencegah bypass tenant isolation yang tidak sah dan menambahkan audit logging.

---

## 🔍 Audit Results

### Lokasi Penggunaan `withoutGlobalScope()`

#### 1. **`app/Models/Traits/HasTenantScope.php`** ✅ SECURED
**Methods:**
- `allTenants()` - Line 28
- `queryAllTenants()` - Line 40
- `findAnyTenant()` - Line 52

**Status:** ✅ All methods now have:
- Authorization check (super_admin only)
- Audit logging (authorized & unauthorized attempts)
- Reason parameter for compliance

#### 2. **`tests/Feature/PolicyEnforcementTest.php`** ✅ TEST ONLY
**Line:** 341
**Context:** Test case for policy enforcement
**Status:** ✅ Safe (test environment only)

#### 3. **`app/Scopes/SchoolScope.php`** ✅ DOCUMENTATION
**Line:** 28
**Context:** Documentation comment
**Status:** ✅ Safe (comment only)

#### 4. **`app/Policies/AttendancePolicy.php`** ✅ DOCUMENTATION
**Line:** 29
**Context:** Documentation comment
**Status:** ✅ Safe (comment only)

### Summary
- **Total Usage:** 3 actual usages (all in HasTenantScope trait)
- **Secured:** 3/3 (100%)
- **Audit Logging:** ✅ Implemented
- **Authorization:** ✅ Super admin only

---

## 🎯 Masalah yang Diperbaiki

### ❌ Problem 1: No Authorization Check
**Before:**
```php
public static function allTenants()
{
    if (!auth()->check() || auth()->user()->role_type !== 'super_admin') {
        return collect();
    }
    return static::withoutGlobalScope(SchoolScope::class)->get();
}
```

**Issues:**
- Authorization check inline (not centralized)
- No audit logging
- No reason tracking

**After:**
```php
public static function allTenants(?string $reason = null)
{
    $auditService = app(TenantScopeBypassAuditService::class);
    
    if (!$auditService->isAuthorized()) {
        $auditService->logUnauthorizedAttempt('allTenants', static::class, [...]);
        return collect();
    }
    
    $auditService->logBypass('allTenants', static::class, [...], $reason);
    return static::withoutGlobalScope(SchoolScope::class)->get();
}
```

---

### ❌ Problem 2: No Audit Logging
**Before:**
- No logging when bypass occurs
- No tracking of who, when, why
- Cannot detect unauthorized attempts

**After:**
- ✅ All bypass attempts logged
- ✅ Unauthorized attempts logged with CRITICAL severity
- ✅ Includes user, IP, timestamp, backtrace
- ✅ Reason parameter for compliance

---

### ❌ Problem 3: No Compliance Trail
**Before:**
- Cannot answer "Who accessed cross-tenant data?"
- Cannot answer "Why was tenant isolation bypassed?"
- No audit trail for compliance

**After:**
- ✅ Complete audit trail in `tenant_bypass.log`
- ✅ Reason parameter required for compliance
- ✅ 365-day retention for audit
- ✅ Backtrace for debugging

---

## ✅ Solusi yang Diimplementasikan

### 1. **TenantScopeBypassAuditService** - NEW

Centralized service for audit logging:

#### Features:
```php
// Log authorized bypass
logBypass($operation, $model, $context, $reason)

// Log unauthorized attempt
logUnauthorizedAttempt($operation, $model, $context)

// Check authorization
isAuthorized(): bool

// Get statistics
getBypassStatistics($days): array
```

#### Logged Information:
```php
[
    'event' => 'tenant_scope_bypass',
    'operation' => 'allTenants',
    'model' => 'App\Models\Attendance',
    'user_id' => 123,
    'user_email' => 'admin@example.com',
    'user_role' => 'super_admin',
    'user_school_id' => null,
    'ip_address' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0...',
    'context' => [...],
    'reason' => 'Cross-school report generation',
    'timestamp' => '2026-02-07T14:40:30+08:00',
    'backtrace' => [
        ['file' => '/app/Http/Controllers/ReportController.php', 'line' => 45],
        ['file' => '/app/Services/ReportService.php', 'line' => 123],
    ],
]
```

---

### 2. **Updated HasTenantScope Trait**

#### Changes:
- ✅ Inject `TenantScopeBypassAuditService`
- ✅ Call `isAuthorized()` before bypass
- ✅ Log all bypass attempts (authorized & unauthorized)
- ✅ Add `$reason` parameter to all methods
- ✅ Enhanced documentation

#### Usage:
```php
// With reason (recommended)
$attendances = Attendance::allTenants('Generating cross-school report');

// Without reason (still logged, but no reason)
$attendances = Attendance::allTenants();

// Query builder
$query = Attendance::queryAllTenants('Admin dashboard');

// Find by ID
$attendance = Attendance::findAnyTenant(123, 'Support ticket investigation');
```

---

### 3. **Log Channels Configuration**

Added two new log channels:

#### `tenant_bypass.log`
```php
'tenant_bypass' => [
    'driver' => 'daily',
    'path' => storage_path('logs/tenant_bypass.log'),
    'level' => 'debug',
    'days' => 365, // 1 year retention
],
```

**Purpose:** All tenant scope bypass operations

#### `audit.log`
```php
'audit' => [
    'driver' => 'daily',
    'path' => storage_path('logs/audit.log'),
    'level' => 'info',
    'days' => 365, // 1 year retention
],
```

**Purpose:** General audit trail for compliance

---

### 4. **Comprehensive Tests**

Created `TenantIsolationTest.php` with 20+ test cases:

#### Test Coverage:
- ✅ Admin can only see own school
- ✅ Admin cannot access other school by ID
- ✅ Super admin can see all schools
- ✅ Admin cannot use bypass methods
- ✅ Unauthorized attempts are logged
- ✅ Authorized bypasses are logged
- ✅ Reason parameter is logged
- ✅ Tenant scope applies to CRUD operations
- ✅ Multiple bypass calls are all logged

---

## 📊 Security Improvements

### Authorization Matrix

| Role | Can Bypass? | Logged? | Severity |
|------|-------------|---------|----------|
| Super Admin | ✅ Yes | ✅ Warning | INFO |
| Admin | ❌ No | ✅ Critical | CRITICAL |
| Teacher | ❌ No | ✅ Critical | CRITICAL |
| Student | ❌ No | ✅ Critical | CRITICAL |
| Guest | ❌ No | ✅ Critical | CRITICAL |

### Audit Trail

#### Authorized Bypass:
```log
[2026-02-07 14:40:30] tenant_bypass.WARNING: Tenant scope bypassed {
    "event": "tenant_scope_bypass",
    "operation": "allTenants",
    "model": "App\\Models\\Attendance",
    "user_id": 1,
    "user_role": "super_admin",
    "reason": "Generating cross-school report",
    "timestamp": "2026-02-07T14:40:30+08:00"
}
```

#### Unauthorized Attempt:
```log
[2026-02-07 14:40:30] tenant_bypass.CRITICAL: UNAUTHORIZED tenant scope bypass attempt {
    "event": "tenant_scope_bypass_unauthorized",
    "operation": "allTenants",
    "model": "App\\Models\\Attendance",
    "user_id": 123,
    "user_role": "admin",
    "severity": "CRITICAL",
    "timestamp": "2026-02-07T14:40:30+08:00"
}
```

---

## 🔍 Risiko Jika Dibiarkan

### 1. **Data Breach** ⚠️
**Risk:** Unauthorized cross-tenant data access
- Admin bisa akses data sekolah lain
- Tidak ada audit trail
- Tidak terdeteksi

**Impact:**
- Privacy violation
- Compliance issues (GDPR, etc.)
- Legal liability

### 2. **Compliance Violations** ⚠️
**Risk:** Cannot prove data access controls
- No audit trail
- Cannot answer "who accessed what"
- Cannot prove authorization

**Impact:**
- Failed audits
- Regulatory fines
- Loss of certifications

### 3. **Security Incidents** ⚠️
**Risk:** Undetected unauthorized access
- No logging of bypass attempts
- Cannot detect attacks
- Cannot investigate incidents

**Impact:**
- Delayed incident response
- Cannot identify compromised accounts
- Cannot prevent future attacks

### 4. **Insider Threats** ⚠️
**Risk:** Malicious insiders bypass isolation
- No detection mechanism
- No audit trail
- No accountability

**Impact:**
- Data theft
- Sabotage
- Cannot prosecute

---

## 🧪 Testing

### Run Tests
```bash
# Run all tenant isolation tests
php artisan test --filter TenantIsolationTest

# Run specific test
php artisan test --filter test_admin_can_only_see_own_school_attendance

# Run with coverage
php artisan test --filter TenantIsolationTest --coverage
```

### Manual Testing

#### 1. Test Authorized Bypass
```php
// Login as super admin
$superAdmin = User::where('role_type', 'super_admin')->first();
auth()->login($superAdmin);

// Bypass with reason
$attendances = Attendance::allTenants('Testing authorized bypass');

// Check logs
tail -f storage/logs/tenant_bypass.log
```

#### 2. Test Unauthorized Attempt
```php
// Login as regular admin
$admin = User::where('role_type', 'admin')->first();
auth()->login($admin);

// Try to bypass
$attendances = Attendance::allTenants();

// Should return empty collection
// Check logs for CRITICAL alert
tail -f storage/logs/tenant_bypass.log | grep CRITICAL
```

#### 3. Test Audit Trail
```bash
# View all bypass attempts
cat storage/logs/tenant_bypass.log | grep "tenant_scope_bypass"

# View unauthorized attempts only
cat storage/logs/tenant_bypass.log | grep "UNAUTHORIZED"

# View by user
cat storage/logs/tenant_bypass.log | grep "user_id\":123"
```

---

## 📈 Monitoring

### Metrics to Track

#### 1. Bypass Frequency
```bash
# Count total bypasses per day
grep "tenant_scope_bypass" storage/logs/tenant_bypass.log \
    | grep -v "UNAUTHORIZED" \
    | awk '{print $1}' \
    | uniq -c
```

#### 2. Unauthorized Attempts
```bash
# Count unauthorized attempts
grep "UNAUTHORIZED" storage/logs/tenant_bypass.log | wc -l

# Group by user
grep "UNAUTHORIZED" storage/logs/tenant_bypass.log \
    | grep -o '"user_id":[0-9]*' \
    | sort | uniq -c
```

#### 3. Bypass Reasons
```bash
# Extract all reasons
grep "reason" storage/logs/tenant_bypass.log \
    | grep -o '"reason":"[^"]*"' \
    | sort | uniq -c
```

### Alerts

Set up alerts for:
- **CRITICAL:** Any unauthorized bypass attempt
- **WARNING:** More than 10 bypasses per hour
- **INFO:** Bypass without reason parameter
- **CRITICAL:** Bypass from unexpected IP address

### Dashboards

Create dashboards for:
- Bypass attempts over time
- Top users bypassing isolation
- Most common bypass reasons
- Unauthorized attempt trends

---

## 🚀 Deployment Checklist

- [x] Create `TenantScopeBypassAuditService`
- [x] Update `HasTenantScope` trait
- [x] Add log channels (`tenant_bypass`, `audit`)
- [x] Create comprehensive tests
- [x] Create documentation
- [ ] Run tests
- [ ] Deploy to staging
- [ ] Monitor logs
- [ ] Set up alerts
- [ ] Train super admins on reason parameter
- [ ] Deploy to production

---

## 📝 Best Practices

### For Developers

1. **Always provide reason:**
   ```php
   // Good
   $data = Model::allTenants('Generating monthly report');
   
   // Bad (no reason)
   $data = Model::allTenants();
   ```

2. **Use specific reasons:**
   ```php
   // Good
   $data = Model::allTenants('Support ticket #12345 investigation');
   
   // Bad (vague)
   $data = Model::allTenants('Admin task');
   ```

3. **Minimize bypass usage:**
   ```php
   // Good - only bypass when necessary
   if ($user->isSuperAdmin() && $needsCrossSchoolData) {
       $data = Model::allTenants($reason);
   }
   
   // Bad - unnecessary bypass
   $data = Model::allTenants(); // Use Model::all() instead
   ```

### For Super Admins

1. **Document reason:** Always provide clear reason for bypass
2. **Review logs:** Regularly review `tenant_bypass.log`
3. **Investigate alerts:** Respond to unauthorized attempt alerts
4. **Audit compliance:** Keep logs for required retention period

---

## ✅ Summary

### Changes Made
1. ✅ Created `TenantScopeBypassAuditService`
2. ✅ Updated `HasTenantScope` trait with audit logging
3. ✅ Added `$reason` parameter to all bypass methods
4. ✅ Added `tenant_bypass` and `audit` log channels
5. ✅ Created comprehensive tests (20+ test cases)
6. ✅ Created documentation

### Security Improvements
- 🔒 **Authorization:** Only super_admin can bypass
- 📊 **Audit Logging:** All attempts logged
- 🚨 **Unauthorized Detection:** CRITICAL alerts for violations
- 📝 **Compliance:** Reason tracking for audit trail
- 🔍 **Visibility:** Complete backtrace for debugging

### Risks Mitigated
- ❌ Unauthorized cross-tenant access
- ❌ Compliance violations
- ❌ Undetected security incidents
- ❌ Insider threats
- ❌ Data breaches

---

**Status:** ✅ READY FOR TESTING  
**Version:** 1.0.0  
**Date:** 2026-02-07  
**Next Action:** Run tests and deploy to staging
