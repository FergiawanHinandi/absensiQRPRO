# 🔒 System Health Monitoring - Security Update

## ⚠️ BREAKING CHANGE (January 28, 2026)

### What Changed?

System health monitoring endpoints now **require EXPLICIT `system:monitor` permission**.

**Before:**
```php
->middleware('ability:system:monitor,*')  // Accepted wildcard fallback
```

**After:**
```php
->middleware('ability:system:monitor')    // EXPLICIT permission required
```

---

## 🎯 Why This Change?

### Security Vulnerability

The previous implementation allowed **ANY token with wildcard (`*`) ability** to access system health endpoints, even if the token was not explicitly intended for system monitoring.

**Example Attack Scenario:**
```php
// Attacker creates token with wildcard for different purpose
$token = $user->createToken('analytics', ['*']);

// But can access system health (UNINTENDED)
GET /api/v1/admin/system/health
Authorization: Bearer {token}
// ✅ 200 OK (BEFORE) - SECURITY ISSUE
// ❌ 403 Forbidden (AFTER) - SECURE
```

### Principle of Least Privilege

System monitoring is a **sensitive operation** that should require explicit authorization, not implicit wildcard access.

---

## 📋 Migration Guide

### Step 1: Update Config (Already Done)

`config/abilities.php` now includes explicit `system:monitor`:

```php
'admin' => [
    // ... other permissions
    'system:monitor',  // ✅ EXPLICIT
],

'school_admin' => [
    // ... other permissions
    'system:monitor',  // ✅ EXPLICIT
],

'super_admin' => [
    '*',               // Wildcard for other endpoints
    'system:monitor',  // ✅ EXPLICIT (required even with wildcard)
],
```

### Step 2: Grant Permission to Existing Users

**For new logins:** Permission is automatically granted via `AuthController` using config.

**For existing tokens:** Users need to re-login to get new token with `system:monitor`.

**Manual grant (if needed):**
```bash
php artisan tinker

# Grant to specific user
$admin = User::find(1);
$admin->givePermissionTo('system:monitor');

# Grant to all admins
User::role('admin')->each(fn($u) => $u->givePermissionTo('system:monitor'));

# Grant to all school admins
User::role('school_admin')->each(fn($u) => $u->givePermissionTo('system:monitor'));

# Grant to all super admins
User::role('super_admin')->each(fn($u) => $u->givePermissionTo('system:monitor'));

exit
```

### Step 3: Update Frontend (If Applicable)

If your frontend caches tokens, users will need to **re-login** to get updated abilities.

**Recommended:** Add token refresh mechanism or force re-login on 403 errors.

```javascript
// Example: Handle 403 and force re-login
async function fetchSystemHealth() {
  try {
    const response = await fetch('/api/v1/admin/system/health', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    
    if (response.status === 403) {
      // Token lacks system:monitor - force re-login
      redirectToLogin('Your session needs to be refreshed');
    }
    
    return await response.json();
  } catch (error) {
    console.error('Health check failed:', error);
  }
}
```

---

## 🧪 Testing

### Test Cases Added

1. ✅ **Admin with explicit `system:monitor`** → 200 OK
2. ✅ **Admin WITHOUT `system:monitor`** → 403 Forbidden
3. ✅ **Token with wildcard ONLY** → 403 Forbidden (NEW)
4. ✅ **Super admin with explicit permission** → 200 OK
5. ✅ **Teacher (no permission)** → 403 Forbidden

### Run Tests

```bash
php artisan test --filter=SystemHealthMonitoringTest
```

---

## 📊 Impact Assessment

### Who is Affected?

| Role | Impact | Action Required |
|------|--------|-----------------|
| **New Users** | ✅ None | Permission granted automatically on login |
| **Existing Admins** | ⚠️ Need re-login | Re-login to get new token with `system:monitor` |
| **Existing Super Admins** | ⚠️ Need re-login | Re-login to get explicit `system:monitor` |
| **Teachers/Students/Parents** | ✅ None | Never had access anyway |

### Breaking Changes

- ❌ Tokens created BEFORE this change will NOT have `system:monitor` ability
- ❌ Wildcard-only tokens will be DENIED access
- ✅ New logins automatically get correct abilities from config

---

## 🔍 Verification

### Check if User Has Permission

```bash
php artisan tinker

$user = User::find(1);
$user->hasPermissionTo('system:monitor');  // Should be true for admins

# Check token abilities
$token = $user->tokens()->first();
$token->abilities;  // Should include 'system:monitor'

exit
```

### Test Endpoint Access

```bash
# Login to get new token
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# Test with new token
curl -X GET http://localhost:8000/api/v1/admin/system/health \
  -H "Authorization: Bearer {NEW_TOKEN}"

# Should return 200 OK with health data
```

---

## 📚 Updated Documentation

All documentation has been updated to reflect this change:

- ✅ `docs/api/SYSTEM_HEALTH_MONITORING.md`
- ✅ `docs/features/SYSTEM_HEALTH_MONITORING.md`
- ✅ `docs/SYSTEM_HEALTH_QUICK_REF.md`
- ✅ `config/abilities.php`
- ✅ `routes/api.php` (security comment added)
- ✅ `tests/Feature/Admin/SystemHealthMonitoringTest.php`

---

## ⚡ Quick Fix for Production

If you need to grant permission to all existing admin users immediately:

```bash
php artisan tinker

// Grant to all admins
\App\Models\User::whereHas('roles', function($q) {
    $q->whereIn('name', ['admin', 'school_admin', 'super_admin']);
})->each(function($user) {
    $user->givePermissionTo('system:monitor');
    echo "✅ Granted to: {$user->name}\n";
});

exit
```

Then notify users to re-login to get new tokens.

---

## 🛡️ Security Benefits

1. **Explicit Authorization** - No accidental access via wildcard
2. **Audit Trail** - Clear permission grants in database
3. **Least Privilege** - Only intended users can monitor system
4. **Token Scoping** - Tokens can be scoped to specific purposes

---

## 📞 Support

If you encounter issues after this change:

1. **Check user permissions:** `$user->hasPermissionTo('system:monitor')`
2. **Check token abilities:** `$token->abilities`
3. **Force re-login:** Clear old tokens and login again
4. **Manual grant:** Use tinker to grant permission if needed

---

**Version:** 2.0.0  
**Date:** January 28, 2026  
**Breaking Change:** Yes  
**Migration Required:** Re-login for existing users
