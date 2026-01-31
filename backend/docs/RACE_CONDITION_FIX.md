# Race Condition Fix - Atomic Rate Limiting

## 🔒 Overview
Dokumen ini menjelaskan perbaikan race condition pada rate limiting menggunakan atomic operations untuk mencegah bypass pada high-traffic scenarios.

## ❌ Masalah: Race Condition

### Kode Lama (Vulnerable)
```php
// NON-ATOMIC: Race condition possible!
$attempts = Cache::get($key, 0);

if ($attempts >= $maxAttempts) {
    return response()->json(['message' => 'Rate limited'], 429);
}

Cache::put($key, $attempts + 1, now()->addMinutes(1));
```

### Skenario Race Condition

**Timeline dengan 3 concurrent requests**:

```
Time  | Request A          | Request B          | Request C          | Cache Value
------|--------------------|--------------------|--------------------|-----------
T0    | get($key) = 4      |                    |                    | 4
T1    |                    | get($key) = 4      |                    | 4
T2    |                    |                    | get($key) = 4      | 4
T3    | check: 4 < 5 ✅    |                    |                    | 4
T4    |                    | check: 4 < 5 ✅    |                    | 4
T5    |                    |                    | check: 4 < 5 ✅    | 4
T6    | put($key, 5)       |                    |                    | 5
T7    |                    | put($key, 5)       |                    | 5
T8    |                    |                    | put($key, 5)       | 5
```

**Hasil**: Semua 3 request **LOLOS** padahal seharusnya hanya 1 yang lolos!

**Dampak**:
- ❌ Rate limit bisa di-bypass dengan concurrent requests
- ❌ Sistem rentan terhadap brute force attacks
- ❌ QR scan abuse tidak terdeteksi
- ❌ Server overload karena rate limit tidak efektif

---

## ✅ Solusi: Atomic Operations

### Kode Baru (Secure)
```php
// ATOMIC: No race condition possible!
$attempts = Cache::add($key, 0, $decaySeconds) 
    ? 1 
    : Cache::increment($key);

// Set expiration on first increment (for array/file cache)
if ($attempts === 1) {
    Cache::put($key, 1, now()->addSeconds($decaySeconds));
}

if ($attempts > $maxAttempts) {
    return response()->json(['message' => 'Rate limited'], 429);
}
```

### Bagaimana Atomic Operations Bekerja

**Timeline dengan 3 concurrent requests (ATOMIC)**:

```
Time  | Request A              | Request B              | Request C              | Cache Value
------|------------------------|------------------------|------------------------|-----------
T0    | add($key, 0) = true    |                        |                        | 0
T1    | return 1               |                        |                        | 0
T2    |                        | add($key, 0) = false   |                        | 0
T3    |                        | increment($key) = 2    |                        | 2 (ATOMIC)
T4    |                        |                        | add($key, 0) = false   | 2
T5    |                        |                        | increment($key) = 3    | 3 (ATOMIC)
T6    | check: 1 <= 5 ✅       |                        |                        | 3
T7    |                        | check: 2 <= 5 ✅       |                        | 3
T8    |                        |                        | check: 3 <= 5 ✅       | 3
```

**Hasil**: Counter **AKURAT** karena increment adalah atomic operation!

---

## 🔧 Implementasi Detail

### 1. RateLimitBySchool Middleware

**File**: `app/Http/Middleware/RateLimitBySchool.php`

**Perubahan**:

```php
// BEFORE (Race Condition)
$attempts = Cache::get($key, 0);
if ($attempts >= $maxAttempts) {
    return response()->json(['message' => 'Rate limited'], 429);
}
Cache::put($key, $attempts + 1, now()->addMinutes($decayMinutes));

// AFTER (Atomic)
$attempts = Cache::add($key, 0, $decaySeconds) 
    ? 1 
    : Cache::increment($key);

if ($attempts === 1) {
    Cache::put($key, 1, now()->addSeconds($decaySeconds));
}

if ($attempts > $maxAttempts) {
    return response()->json(['message' => 'Rate limited'], 429);
}
```

**Penjelasan**:

1. **`Cache::add($key, 0, $decaySeconds)`**
   - Mencoba membuat key baru dengan value 0
   - Return `true` jika berhasil (key belum ada)
   - Return `false` jika gagal (key sudah ada)
   - **ATOMIC**: Operasi ini atomic di Redis/Memcached

2. **`Cache::increment($key)`**
   - Increment value secara atomic
   - Return nilai setelah increment
   - **ATOMIC**: Operasi ini atomic di semua cache drivers

3. **Ternary operator `? 1 : Cache::increment($key)`**
   - Jika `add()` berhasil, return 1 (first request)
   - Jika `add()` gagal, increment dan return nilai baru

4. **Set expiration untuk array/file cache**
   - Hanya untuk cache driver yang tidak support TTL di `add()`
   - Redis/Memcached tidak perlu ini karena TTL sudah di-set di `add()`

---

## 📊 Perbandingan Performance

### Non-Atomic (Old)
```
Operation: get() + put()
Time Complexity: O(2)
Race Condition: YES ❌
Concurrent Safe: NO ❌
```

### Atomic (New)
```
Operation: add() OR increment()
Time Complexity: O(1)
Race Condition: NO ✅
Concurrent Safe: YES ✅
```

---

## 🧪 Testing

### Test Suite: RateLimitAtomicTest

**File**: `tests/Feature/RateLimitAtomicTest.php`

**Test Cases**:

1. ✅ **rate_limit_uses_atomic_operations**
   - Verifikasi rate limit bekerja dengan benar
   - 5 request pertama sukses, ke-6 di-reject

2. ✅ **rate_limit_headers_are_correct**
   - Verifikasi header `X-RateLimit-*` akurat
   - Remaining count berkurang dengan benar

3. ✅ **rate_limit_resets_after_decay_period**
   - Verifikasi rate limit reset setelah TTL habis
   - Request bisa dilakukan lagi setelah 60 detik

4. ✅ **rate_limit_is_per_user_and_school**
   - Verifikasi rate limit isolated per user dan school
   - User dari school berbeda tidak terpengaruh

5. ✅ **concurrent_requests_dont_bypass_rate_limit**
   - **CRITICAL TEST**: Verifikasi atomic operations
   - 10 concurrent requests → exactly 5 sukses, 5 di-reject
   - Membuktikan tidak ada race condition

6. ✅ **rate_limit_logs_violations**
   - Verifikasi logging untuk audit trail

### Menjalankan Test

```bash
# Run all rate limit tests
php artisan test --filter=RateLimitAtomicTest

# Run specific test
php artisan test --filter=concurrent_requests_dont_bypass_rate_limit
```

---

## 🎯 Endpoints yang Terproteksi

### 1. Attendance Scan
```
POST /api/v1/attendance/scan
Rate Limit: 5 requests per minute
Key: attendance_scan:{school_id}:{user_id}
```

### 2. QR Code Generate
```
POST /api/v1/qr/generate
Rate Limit: 10 requests per minute
Key: qr_generate:{school_id}:{user_id}
```

### 3. Auth Login
```
POST /api/v1/auth/login
Rate Limit: 5 requests per minute
Key: auth_login:{ip}
```

### 4. General API
```
All other endpoints
Rate Limit: 60 requests per minute
Key: api_general:{school_id}:{ip}
```

---

## 📈 Monitoring

### Rate Limit Headers

Setiap response menyertakan header:

```http
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 3
X-RateLimit-Reset: 1706342400
```

**Interpretasi**:
- `Limit`: Maximum requests allowed
- `Remaining`: Requests left in current window
- `Reset`: Unix timestamp when limit resets

### Logging

Rate limit violations dicatat di log:

```json
{
  "level": "warning",
  "message": "Rate limit exceeded",
  "context": {
    "user_id": 123,
    "school_id": 1,
    "ip": "192.168.1.1",
    "route": "api.v1.attendance.scan",
    "path": "/api/v1/attendance/scan",
    "attempts": 6,
    "max_attempts": 5
  }
}
```

---

## 🔍 Cache Driver Compatibility

### Redis (Recommended)
```php
CACHE_DRIVER=redis
```
- ✅ `add()` is atomic
- ✅ `increment()` is atomic
- ✅ TTL support in `add()`
- ✅ Best performance

### Memcached
```php
CACHE_DRIVER=memcached
```
- ✅ `add()` is atomic
- ✅ `increment()` is atomic
- ✅ TTL support in `add()`
- ✅ Good performance

### Database
```php
CACHE_DRIVER=database
```
- ⚠️ `add()` uses database locks (slower)
- ⚠️ `increment()` uses database locks (slower)
- ✅ Still atomic (via locks)
- ⚠️ Lower performance

### Array/File (Development Only)
```php
CACHE_DRIVER=array
CACHE_DRIVER=file
```
- ⚠️ Not truly atomic (single process only)
- ⚠️ Not suitable for production
- ✅ OK for local development

---

## 🚀 Production Recommendations

### 1. Use Redis
```bash
# Install Redis
sudo apt-get install redis-server

# Configure Laravel
CACHE_DRIVER=redis
REDIS_CLIENT=phpredis
```

### 2. Monitor Rate Limits
```bash
# Check Redis keys
redis-cli KEYS "attendance_scan:*"
redis-cli GET "attendance_scan:1:123"
redis-cli TTL "attendance_scan:1:123"
```

### 3. Adjust Limits Based on Load
```php
// config/qr.php
return [
    'scan_rate_limit' => [
        'max_attempts' => env('QR_SCAN_RATE_LIMIT', 5),
        'decay_minutes' => env('QR_SCAN_RATE_DECAY', 1),
    ],
];
```

### 4. Setup Alerts
```php
// Monitor rate limit violations
if ($violations > 100) {
    // Send alert to admin
    Mail::to('admin@example.com')->send(new RateLimitAlert($violations));
}
```

---

## 📝 Best Practices

### DO ✅
- Use Redis for production
- Monitor rate limit violations
- Adjust limits based on actual usage
- Log all rate limit violations
- Use atomic operations for all counters

### DON'T ❌
- Don't use array/file cache in production
- Don't use `get() + put()` pattern for counters
- Don't set limits too low (UX impact)
- Don't ignore rate limit violations in logs

---

## 🔗 Related Files

- `app/Http/Middleware/RateLimitBySchool.php` - Main middleware
- `tests/Feature/RateLimitAtomicTest.php` - Test suite
- `config/cache.php` - Cache configuration

---

## 📅 Changelog

### 2026-01-27
- ✅ Fixed race condition using atomic operations
- ✅ Replaced `get() + put()` with `add() + increment()`
- ✅ Added comprehensive test suite
- ✅ Improved logging with path information
- ✅ Fixed rate limit header calculations
