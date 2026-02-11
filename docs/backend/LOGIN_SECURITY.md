# Login Security - Timing Attack & Brute Force Prevention

## 📋 Overview

**Version:** 2.0.0  
**Date:** 2026-02-07  
**Status:** ✅ IMPLEMENTED

### Objective
Refactor login flow untuk mencegah timing attack dan brute force dengan constant-time comparison, rate limiting, progressive delay, dan account lockout.

---

## 🎯 Problems Solved

### ❌ Problem 1: Timing Attack Vulnerability

**Before:**
```php
// VULNERABLE: Different response times reveal user existence
$user = User::where('email', $email)->first();

if (!$user) {
    return error('User not found');  // Fast response (~10ms)
}

if (!Hash::check($password, $user->password)) {
    return error('Wrong password');  // Slow response (~100ms due to bcrypt)
}
```

**Issues:**
- User not found: Fast response (~10ms)
- Wrong password: Slow response (~100ms)
- **Attacker can enumerate valid users by measuring response time!**

---

### ❌ Problem 2: No Rate Limiting

**Before:**
```php
// VULNERABLE: Unlimited login attempts
$user = User::where('email', $email)->first();
if ($user && Hash::check($password, $user->password)) {
    return success();
}
return error();
```

**Issues:**
- Attacker can try millions of passwords
- No delay between attempts
- No account lockout

---

### ❌ Problem 3: Information Leakage

**Before:**
```php
if (!$user) {
    return error('User not found');  // ❌ Reveals user doesn't exist
}

if (!Hash::check($password, $user->password)) {
    return error('Wrong password');  // ❌ Reveals user exists
}
```

**Issues:**
- Error messages reveal which field is wrong
- Attacker can enumerate valid emails/usernames

---

## ✅ Solution: Multi-Layer Security

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      LOGIN SECURITY LAYERS                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. RATE LIMITING (IP + Email)                                  │
│     ├─ 5 attempts / minute per IP+Email                         │
│     ├─ Check BEFORE database query                              │
│     └─ Return generic error if exceeded                         │
│                                                                 │
│  2. TIMING ATTACK PREVENTION                                    │
│     ├─ Always perform Hash::check (even for non-existent user)  │
│     ├─ Use dummy hash when user not found                       │
│     ├─ Enforce minimum response time (100ms)                    │
│     └─ Add random jitter (0-10ms)                               │
│                                                                 │
│  3. PROGRESSIVE DELAY                                           │
│     ├─ No delay: 1-2 attempts                                   │
│     ├─ 2s delay: 3rd attempt                                    │
│     ├─ 4s delay: 4th attempt                                    │
│     ├─ 8s delay: 5th attempt                                    │
│     └─ Exponential backoff (capped at 60s)                      │
│                                                                 │
│  4. ACCOUNT LOCKOUT                                             │
│     ├─ Lock after 10 failed attempts                            │
│     ├─ Lockout duration: 10 minutes                             │
│     ├─ Persistent in database                                   │
│     └─ Auto-unlock after expiry                                 │
│                                                                 │
│  5. GENERIC ERROR MESSAGES                                      │
│     ├─ Same message for all failures                            │
│     ├─ "Invalid credentials" (don't reveal which field)         │
│     └─ No information leakage                                   │
│                                                                 │
│  6. COMPREHENSIVE AUDIT LOGGING                                 │
│     ├─ Log all failed attempts                                  │
│     ├─ Log successful logins                                    │
│     ├─ Log account lockouts                                     │
│     └─ Include IP, user agent, timestamp                        │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔒 Implementation Details

### 1. **Timing Attack Prevention** ✅

#### Constant-Time Comparison

```php
// In AuthController::login()

// CRITICAL: Always perform hash check to maintain constant response time
$dummyHash = '$2y$12$K4O0R4b5xQYzKj0xH4bWoOvB2Y9d8q0Z3X5n6l7m8kJhIgFeDcBa.';
$passwordToCheck = $user ? $user->password : $dummyHash;
$passwordValid = Hash::check($request->password, $passwordToCheck);

// Both paths (user exists / doesn't exist) perform Hash::check
// Response time is constant regardless of user existence
```

**Benefits:**
- User not found: ~100ms (performs Hash::check with dummy hash)
- Wrong password: ~100ms (performs Hash::check with real hash)
- **Attacker cannot enumerate users by timing!**

#### Minimum Response Time

```php
// Start timing
$startTime = hrtime(true);
$minResponseTimeNs = 100_000_000; // 100ms minimum

// ... login logic ...

// Enforce minimum response time before returning
$this->enforceMinimumResponseTime($startTime, $minResponseTimeNs);

protected function enforceMinimumResponseTime(int $startTime, int $minTimeNs): void
{
    $elapsed = hrtime(true) - $startTime;
    $remainingNs = $minTimeNs - $elapsed;
    
    if ($remainingNs > 0) {
        $remainingUs = (int) ($remainingNs / 1000);
        
        // Add random jitter (0-10ms) to prevent statistical analysis
        $jitterUs = random_int(0, 10000);
        
        usleep($remainingUs + $jitterUs);
    }
}
```

**Benefits:**
- All responses take at least 100ms
- Random jitter prevents statistical timing analysis
- Consistent response time for all failure scenarios

---

### 2. **Rate Limiting** ✅

#### IP + Email Combination

```php
// In LoginRateLimiter

protected const MAX_ATTEMPTS = 5;           // Max attempts before rate limit
protected const DECAY_MINUTES = 1;          // Time window

protected function throttleKey(Request $request, string $email): string
{
    return Str::transliterate(
        Str::lower($email) . '|' . $request->ip()
    );
}

public function tooManyAttempts(Request $request, string $email): bool
{
    $key = $this->throttleKey($request, $email);
    return $this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS);
}
```

**Benefits:**
- Rate limit per IP + Email combination
- Prevents distributed brute force
- Automatically resets after 1 minute

#### Check Before Database Query

```php
// In AuthController::login()

// CRITICAL: Check rate limiting BEFORE database query
$this->ensureIsNotRateLimited($request);

// Only query database if not rate limited
$user = User::where(...)->first();
```

**Benefits:**
- Prevents database load from brute force
- Fails fast for rate-limited requests
- Reduces attack surface

---

### 3. **Progressive Delay** ✅

```php
// In LoginRateLimiter

protected function applyProgressiveDelay(int $attempts): void
{
    if ($attempts < 3) {
        return; // No delay for first 2 attempts
    }

    // Progressive delay: 2^(attempts-2) seconds
    // 3rd attempt: 2s, 4th: 4s, 5th: 8s, 6th: 16s, etc.
    $delay = min(pow(2, $attempts - 2), 60); // Cap at 60 seconds

    usleep($delay * 1000000);
}
```

**Delay Schedule:**

| Attempt | Delay | Total Time |
|---------|-------|------------|
| 1st | 0s | 0s |
| 2nd | 0s | 0s |
| 3rd | 2s | 2s |
| 4th | 4s | 6s |
| 5th | 8s | 14s |
| 6th | 16s | 30s |
| 7th | 32s | 62s |
| 8th+ | 60s (cap) | ... |

**Benefits:**
- Slows down brute force attacks exponentially
- Legitimate users not heavily impacted (first 2 attempts fast)
- Makes brute force impractical

---

### 4. **Account Lockout** ✅

#### Database-Persisted Lockout

```php
// In LoginRateLimiter

protected const LOCKOUT_ATTEMPTS = 10;      // Attempts before lockout
protected const LOCKOUT_DURATION = 10;      // Minutes

public function lockAccount(User $user): void
{
    $lockedUntil = now()->addMinutes(self::LOCKOUT_DURATION);

    $user->update([
        'locked_until' => $lockedUntil,
        'failed_login_attempts' => $user->failed_login_attempts + 1,
        'last_failed_login_at' => now(),
    ]);

    Log::warning('Account locked due to excessive failed login attempts', [
        'user_id' => $user->id,
        'locked_until' => $lockedUntil->toDateTimeString(),
    ]);
}
```

#### Auto-Unlock After Expiry

```php
public function isAccountLocked(User $user): bool
{
    if (!$user->locked_until) {
        return false;
    }

    // Check if lock has expired
    if (now()->greaterThan($user->locked_until)) {
        $user->update(['locked_until' => null]);
        return false;
    }

    return true;
}
```

**Benefits:**
- Persistent lockout (survives server restart)
- Automatic unlock after expiry
- Prevents brute force even with distributed IPs

---

### 5. **Generic Error Messages** ✅

```php
// CRITICAL: Generic error message (don't reveal which field is wrong)
throw ValidationException::withMessages([
    'email' => ['Kredensial yang Anda masukkan tidak valid. Silakan periksa kembali.'],
]);
```

**Same message for:**
- User not found
- Wrong password
- Inactive user
- Locked account (different message, but doesn't reveal user existence)

**Benefits:**
- No information leakage
- Cannot enumerate valid users
- Cannot determine which field is wrong

---

### 6. **Comprehensive Audit Logging** ✅

#### Failed Login Attempts

```php
// Log to security channel
Log::channel('security')->warning('Failed Login Attempt', [
    'username' => $request->username,
    'ip' => $request->ip(),
    'reason' => 'Invalid Credentials', // Generic
]);

// Create audit log entry
AuditLog::create([
    'user_id' => $user->id,
    'action' => 'failed_login',
    'description' => "Failed login attempt #{$attempts}. IP: {$request->ip()}",
    'ip_address' => $request->ip(),
    'user_agent' => $request->userAgent(),
]);
```

#### Successful Login

```php
AuditLog::create([
    'user_id' => $user->id,
    'action' => 'login',
    'description' => "Login success via {$deviceInfo}. Location: {$location}.",
    'ip_address' => $ip,
    'user_agent' => $userAgent,
]);
```

#### Account Lockout

```php
Log::warning('Account locked due to excessive failed login attempts', [
    'user_id' => $user->id,
    'username' => $user->username,
    'locked_until' => $lockedUntil->toDateTimeString(),
    'total_failed_attempts' => $user->failed_login_attempts,
]);
```

---

## 📊 Security Comparison

### Before vs After

| Feature | Before | After | Improvement |
|---------|--------|-------|-------------|
| **Timing Attack** | ❌ Vulnerable | ✅ Protected | Constant-time comparison |
| **Rate Limiting** | ❌ None | ✅ 5/min per IP+Email | Prevents brute force |
| **Progressive Delay** | ❌ None | ✅ Exponential backoff | Slows down attacks |
| **Account Lockout** | ❌ None | ✅ 10 attempts, 10 min | Prevents persistent attacks |
| **Error Messages** | ❌ Leaks info | ✅ Generic | No enumeration |
| **Audit Logging** | ❌ Minimal | ✅ Comprehensive | Full visibility |
| **Response Time** | ❌ Variable | ✅ Constant (100ms+) | No timing analysis |

---

## 🧪 Testing

### Test Coverage

**File:** `tests/Feature/Auth/LoginSecurityTest.php`

**Coverage:**
- ✅ Timing attack prevention (4 tests)
- ✅ Rate limiting (2 tests)
- ✅ Account lockout (4 tests)
- ✅ Progressive delay (1 test)
- ✅ Audit logging (4 tests)
- ✅ Successful login (3 tests)
- ✅ Edge cases (2 tests)

**Total:** 20+ comprehensive tests

### Running Tests

```bash
# Run all login security tests
php artisan test --filter LoginSecurityTest

# Run specific test
php artisan test --filter it_uses_constant_time_comparison_for_invalid_user

# Run with coverage
php artisan test --filter LoginSecurityTest --coverage
```

---

## 🔄 Login Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        LOGIN REQUEST                            │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
                  ┌──────────────────────┐
                  │  Rate Limit Check    │
                  │  (IP + Email)        │
                  └──────────┬───────────┘
                             │
                    ┌────────┴────────┐
                    │                 │
                    ▼                 ▼
            ┌──────────────┐   ┌─────────────┐
            │  Allowed     │   │ Rate Limited│
            └──────┬───────┘   └──────┬──────┘
                   │                  │
                   │                  ▼
                   │           ┌──────────────────┐
                   │           │ Return 422       │
                   │           │ "Too many tries" │
                   │           └──────────────────┘
                   │
                   ▼
          ┌─────────────────┐
          │ Start Timer     │
          │ (hrtime)        │
          └────────┬────────┘
                   │
                   ▼
          ┌─────────────────┐
          │ Query User      │
          │ (email/username)│
          └────────┬────────┘
                   │
          ┌────────┴────────┐
          │                 │
          ▼                 ▼
   ┌──────────┐      ┌──────────────┐
   │ User     │      │ User Not     │
   │ Found    │      │ Found        │
   └────┬─────┘      └──────┬───────┘
        │                   │
        │                   ▼
        │            ┌──────────────────┐
        │            │ Use Dummy Hash   │
        │            │ (constant time)  │
        │            └──────┬───────────┘
        │                   │
        ▼                   ▼
   ┌─────────────────────────────────┐
   │ Hash::check(password, hash)     │
   │ (always performed)               │
   └────────────┬────────────────────┘
                │
       ┌────────┴────────┐
       │                 │
       ▼                 ▼
┌──────────┐      ┌──────────────┐
│ Valid    │      │ Invalid      │
└────┬─────┘      └──────┬───────┘
     │                   │
     │                   ▼
     │            ┌──────────────────┐
     │            │ Record Failed    │
     │            │ Attempt          │
     │            └──────┬───────────┘
     │                   │
     │                   ▼
     │            ┌──────────────────┐
     │            │ Check Lockout    │
     │            │ Threshold        │
     │            └──────┬───────────┘
     │                   │
     │          ┌────────┴────────┐
     │          │                 │
     │          ▼                 ▼
     │   ┌──────────┐      ┌──────────────┐
     │   │ < 10     │      │ >= 10        │
     │   │ attempts │      │ attempts     │
     │   └────┬─────┘      └──────┬───────┘
     │        │                   │
     │        │                   ▼
     │        │            ┌──────────────────┐
     │        │            │ Lock Account     │
     │        │            │ (10 minutes)     │
     │        │            └──────────────────┘
     │        │
     │        ▼
     │   ┌──────────────────┐
     │   │ Progressive Delay│
     │   │ (exponential)    │
     │   └──────┬───────────┘
     │          │
     │          ▼
     │   ┌──────────────────┐
     │   │ Enforce Min Time │
     │   │ (100ms + jitter) │
     │   └──────┬───────────┘
     │          │
     │          ▼
     │   ┌──────────────────┐
     │   │ Return 422       │
     │   │ "Invalid creds"  │
     │   └──────────────────┘
     │
     ▼
┌──────────────────┐
│ Check Account    │
│ Locked           │
└────────┬─────────┘
         │
    ┌────┴────┐
    │         │
    ▼         ▼
┌────────┐ ┌──────────┐
│ Locked │ │ Not      │
└───┬────┘ │ Locked   │
    │      └────┬─────┘
    │           │
    ▼           ▼
┌────────┐ ┌──────────────────┐
│ Return │ │ Clear Attempts   │
│ 422    │ │ Issue Tokens     │
└────────┘ │ Update Last Login│
           │ Create Audit Log │
           └────────┬─────────┘
                    │
                    ▼
           ┌──────────────────┐
           │ Return 200       │
           │ + Access Token   │
           └──────────────────┘
```

---

## ✅ Summary

### Implementation Status

| Component | Status |
|-----------|--------|
| Timing Attack Prevention | ✅ COMPLETE |
| Constant-Time Comparison | ✅ COMPLETE |
| Minimum Response Time | ✅ COMPLETE |
| Rate Limiting (IP+Email) | ✅ COMPLETE |
| Progressive Delay | ✅ COMPLETE |
| Account Lockout | ✅ COMPLETE |
| Generic Error Messages | ✅ COMPLETE |
| Audit Logging | ✅ COMPLETE |
| Test Suite | ✅ COMPLETE (20+ tests) |
| Documentation | ✅ COMPLETE |

### Key Features

1. ✅ **Constant-Time Comparison** - Always performs Hash::check
2. ✅ **Minimum Response Time** - 100ms + random jitter
3. ✅ **Rate Limiting** - 5 attempts/min per IP+Email
4. ✅ **Progressive Delay** - Exponential backoff (2s, 4s, 8s, ...)
5. ✅ **Account Lockout** - 10 attempts → 10 min lockout
6. ✅ **Generic Errors** - No information leakage
7. ✅ **Comprehensive Logging** - All attempts logged

### Security Improvements

- 🔒 **Timing Attack:** PROTECTED (constant-time comparison)
- 🔒 **Brute Force:** PREVENTED (rate limiting + progressive delay)
- 🔒 **User Enumeration:** PREVENTED (generic error messages)
- 🔒 **Account Takeover:** PREVENTED (account lockout)
- 🔒 **Audit Trail:** COMPLETE (all attempts logged)

---

**Status:** ✅ PRODUCTION READY  
**Version:** 2.0.0  
**Date:** 2026-02-07

Login security sudah sangat robust dengan multi-layer protection! 🔒
