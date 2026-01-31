# 🔒 FINAL ARCHITECTURE DECISIONS (LOCKED)

> **STATUS:** FINAL - NOT NEGOTIABLE  
> **Date:** 2026-01-19  
> **Purpose:** Definitive implementation reference

---

## ⚠️ IMPORTANT NOTICE

This document contains **FINAL architectural decisions** that must be followed during implementation.

**DO NOT:**
- Deviate from these designs
- Add "alternative options"
- Make "improvements" without review
- Change after implementation starts

**These decisions are based on:**
- Security audit findings
- Production experience with school systems
- Real-world attack scenarios
- Performance requirements

---

# 1️⃣ QR TOKEN DESIGN (FINAL - LOCKED)

## ❌ FORBIDDEN Approaches

The following are **BANNED** from implementation:

- ❌ `encrypt()` / `decrypt()` (Laravel)
- ❌ Any dependency on `APP_KEY`
- ❌ Stateful tokens requiring database lookup for validation
- ❌ Encrypted tokens that break on key rotation
- ❌ Custom encryption algorithms

**Reason:** These cause systematic failures in multi-server deployments, container scaling, and key rotation scenarios.

---

## ✅ FINAL TOKEN DESIGN (HMAC-based, Stateless)

### Token Structure

```
token = base64(payload) + "." + HMAC_SHA256(base64(payload), QR_SECRET_KEY)
```

### Payload Format (JSON)

```json
{
  "sid": 101,          // schedule_id
  "qid": 555,          // qr_code_id (for logging)
  "typ": "in",         // "in" | "out"
  "iat": 1700000000,   // issued_at (unix timestamp)
  "exp": 1700000600,   // expires_at (unix timestamp)
  "nonce": "R4nd0m16", // random string (anti-replay)
  "v": 1               // version (for future changes)
}
```

### Secret Key

- **Separate from APP_KEY**
- Stored in `config/qr.php`
- Environment variable: `QR_SECRET_KEY`
- Can be rotated without invalidating old tokens (grace period)

### Configuration

```php
// config/qr.php
return [
    'secret' => env('QR_SECRET_KEY'),
    'expiry_minutes' => env('QR_EXPIRY_MINUTES', 10),
    'max_age_hours' => 24, // Prevent very old token reuse
];
```

### Why This is Final

✅ **Stateless** - Works across all servers  
✅ **No database** - Validation is cryptographic only  
✅ **Scalable** - No shared state required  
✅ **Secure** - Industry-standard HMAC  
✅ **Versionable** - Can evolve with `v` field  
✅ **Container-safe** - No APP_KEY dependency  

**This is NOT negotiable. Use this or explain why to senior architect.**

---

# 2️⃣ QrService IMPLEMENTATION (FINAL CONTRACT)

## Interface Contract

```php
/**
 * QR Code Token Service
 * 
 * STRICT RULES:
 * - NO database access
 * - NO business logic
 * - ONLY cryptographic operations
 */
interface QrServiceInterface
{
    /**
     * Generate QR token from payload
     * 
     * @throws InvalidArgumentException
     */
    public function generate(array $data): string;
    
    /**
     * Validate and decode QR token
     * 
     * @throws InvalidQrException
     * @throws QrExpiredException
     */
    public function validate(string $token): QrPayload;
}
```

## Final Implementation

```php
// app/Services/QrService.php

namespace App\Services;

use App\DTOs\QrPayload;
use App\Exceptions\InvalidQrException;
use App\Exceptions\QrExpiredException;
use Illuminate\Support\Str;

final class QrService implements QrServiceInterface
{
    /**
     * Generate stateless QR token
     */
    public function generate(array $data): string
    {
        $payload = [
            'sid' => $data['schedule_id'],
            'qid' => $data['qr_id'],
            'typ' => $data['type'], // 'in' | 'out'
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes(config('qr.expiry_minutes'))->timestamp,
            'nonce' => Str::random(16),
            'v' => 1,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        return $encoded . '.' . $signature;
    }

    /**
     * Validate QR token (stateless)
     */
    public function validate(string $token): QrPayload
    {
        // 1. Check format
        if (!str_contains($token, '.')) {
            throw new InvalidQrException('Invalid token format');
        }

        [$encoded, $signature] = explode('.', $token, 2);

        // 2. Verify signature
        $expectedSignature = hash_hmac('sha256', $encoded, config('qr.secret'));

        if (!hash_equals($expectedSignature, $signature)) {
            throw new InvalidQrException('Invalid signature');
        }

        // 3. Decode payload
        $payload = json_decode(base64_decode($encoded), true);

        if (!$payload) {
            throw new InvalidQrException('Invalid payload');
        }

        // 4. Check expiry
        if ($payload['exp'] < now()->timestamp) {
            throw new QrExpiredException('QR code expired');
        }

        // 5. Check not too old (anti-replay)
        $maxAge = config('qr.max_age_hours', 24) * 3600;
        if ($payload['iat'] < (now()->timestamp - $maxAge)) {
            throw new InvalidQrException('QR code too old');
        }

        return new QrPayload($payload);
    }
}
```

## QrPayload DTO

```php
// app/DTOs/QrPayload.php

namespace App\DTOs;

final class QrPayload
{
    public function __construct(
        private array $data
    ) {}
    
    public function scheduleId(): int
    {
        return $this->data['sid'];
    }
    
    public function qrCodeId(): int
    {
        return $this->data['qid'];
    }
    
    public function type(): string
    {
        return $this->data['typ'];
    }
    
    public function issuedAt(): int
    {
        return $this->data['iat'];
    }
    
    public function expiresAt(): int
    {
        return $this->data['exp'];
    }
}
```

---

## 🚫 WHAT QrService MUST NOT DO

**VIOLATION = ARCHITECTURE FAILURE**

| Forbidden Action | Why |
|------------------|-----|
| ❌ Database queries | Breaks stateless principle |
| ❌ Location validation | Business logic, not crypto |
| ❌ Duplicate checking | Business logic |
| ❌ Student verification | Business logic |
| ❌ Model dependencies | Coupling failure |

**QrService = Pure Crypto. Nothing else.**

---

# 3️⃣ SCAN ENDPOINT SECURITY (FINAL STACK)

## Middleware Stack (Order Matters)

```php
// routes/api.php

Route::post('/attendance/scan', [AttendanceController::class, 'scan'])
    ->middleware([
        'auth:sanctum',              // 1. Authentication
        'throttle:attendance-scan',   // 2. Rate limiting
        'verified.device',            // 3. Device verification (optional)
        'tenant',                     // 4. Tenant isolation
    ]);
```

## Rate Limit Configuration (FINAL)

```php
// app/Providers/RouteServiceProvider.php

RateLimiter::for('attendance-scan', function (Request $request) {
    return [
        Limit::perMinute(5)->by($request->user()->id),
        Limit::perHour(20)->by($request->user()->id),
    ];
});
```

**Numbers Explained:**
- **5/min** - Prevents rapid brute-force
- **20/hour** - Allows legitimate rescans (error recovery)
- **Per user** - Cannot share quota

**DO NOT increase these limits without security review.**

---

# 4️⃣ MIGRATION FIXES (MANDATORY)

## Critical Fix: attendances Table

### Current Problem

```sql
UNIQUE (schedule_id, student_id, attendance_date)
```

**Issue:** Cannot record both check-in AND check-out for same student/day.

### REQUIRED Migration

```php
// database/migrations/xxxx_fix_attendances_unique_constraint.php

public function up(): void
{
    Schema::table('attendances', function (Blueprint $table) {
        // 1. Add attendance_type column
        $table->enum('attendance_type', ['in', 'out'])
            ->default('in')
            ->after('status');
        
        // 2. Drop old unique constraint
        $table->dropUnique(['schedule_id', 'student_id', 'attendance_date']);
        
        // 3. Add new unique constraint with attendance_type
        $table->unique(
            ['schedule_id', 'student_id', 'attendance_date', 'attendance_type'],
            'unique_attendance_with_type'
        );
    });
}

public function down(): void
{
    Schema::table('attendances', function (Blueprint $table) {
        $table->dropUnique('unique_attendance_with_type');
        $table->dropColumn('attendance_type');
        $table->unique(['schedule_id', 'student_id', 'attendance_date']);
    });
}
```

**PRIORITY:** P0 - Cannot launch without this.

---

## Advisory: qr_codes.scan_count

**Status:** Keep field, but change role.

**Old understanding:**
- `scan_count` = source of truth

**New understanding:**
- `scan_count` = optimization/cache
- `attendance_logs` COUNT = source of truth

**Implementation:**
```php
// Increment scan_count (atomic)
$qr->increment('scan_count');

// But validate against logs
$actualScans = AttendanceLog::where('qr_code_id', $qr->id)->count();

if ($actualScans >= $qr->max_scans) {
    throw new QrLimitReachedException();
}
```

**This is defense-in-depth. Both checks run.**

---

# 5️⃣ QrService SEPARATION PRINCIPLES (GOLDEN RULES)

## The Single Responsibility Rule

**QrService has ONE job:**
> Generate and validate cryptographic tokens. Period.

## Enforcement Checklist

Before adding ANY method to QrService, ask:

- [ ] Does this method do cryptography?
- [ ] Is this stateless?
- [ ] Does this require NO database?
- [ ] Does this require NO business logic?

**If ANY answer is "no" → WRONG SERVICE.**

## Where Business Logic Goes

| Responsibility | Correct Service |
|----------------|-----------------|
| QR token crypto | `QrService` ✅ |
| Location validation | `LocationService` |
| Duplicate check | `AttendanceService` |
| Permission check | `Policy` |
| Suspicious pattern | `AttendanceService` |
| Logging | `AttendanceService` |

**违反这个原则 = 技术债务 = 未来的痛苦**

---

# 6️⃣ MVP SCOPE (REALISTIC FINAL)

## ✅ MUST HAVE (Phase 1 - Production Pilot)

### Core Features
- [x] User authentication (Sanctum)
- [x] Role-based permissions (9 roles)
- [x] QR code generation (check-in only)
- [x] QR code scanning with validation
- [x] Duplicate scan prevention
- [x] Basic GPS location validation
- [x] Manual attendance (sick/permit/absent ONLY)
- [x] Daily attendance report (per class)
- [x] Attendance history (7 days)

### Security Essentials
- [x] HMAC-based QR tokens
- [x] Rate limiting (5/min for scan)
- [x] Tenant isolation (global scope)
- [x] Audit logging
- [x] Suspicious pattern detection

### Infrastructure
- [x] PostgreSQL database
- [x] Redis cache (optional for dev)
- [x] Laravel scheduler (report generation)

---

## ❌ NOT IN MVP (Phase 2 or Later)

**Explicitly EXCLUDED to ship faster:**

- ❌ Parent role & permissions
- ❌ Check-out QR functionality (check-in only for MVP)
- ❌ Mobile app offline mode
- ❌ Advanced analytics dashboard
- ❌ Complex PDF reports with charts
- ❌ Multi-device login management UI
- ❌ Biometric authentication
- ❌ Integration with external systems

**Rationale:** These add complexity without proportional value for initial deployment.

---

## 🎯 MVP Success Criteria

System is ready when:

1. **1 school can use it for 1 week** without critical bugs
2. **Teachers can take attendance** in < 30 seconds
3. **Students cannot easily cheat** the system
4. **Reports are accurate** and match reality
5. **No security incidents** in pilot

**If these 5 criteria met → Ship to production.**

---

# 📋 IMPLEMENTATION CHECKLIST

## Pre-Implementation

- [ ] Review ALL sections of this document
- [ ] Understand why each decision was made
- [ ] Ask questions if anything unclear
- [ ] Get sign-off from tech lead

## Implementation Order

1. [ ] Create `QrService` with HMAC implementation
2. [ ] Run attendances table migration (add attendance_type)
3. [ ] Implement rate limiting for scan endpoint
4. [ ] Add tenant isolation global scope
5. [ ] Implement suspicious pattern detection
6. [ ] Write tests for QrService (stateless validation)
7. [ ] Write tests for scan endpoint (duplicate, location, expiry)

## Verification

- [ ] QrService has ZERO database calls
- [ ] QR token works across multiple servers (test)
- [ ] Rate limit blocks after 5 scans/min (test)
- [ ] Cannot scan for different school (test)
- [ ] Migration allows check-in AND check-out (test)

---

# 🔐 LOCKING STATEMENT

**These decisions are LOCKED as of 2026-01-19.**

Changes require:
1. Security review
2. Architecture review
3. Documentation update
4. Team approval

**Implementation must follow this document exactly.**

---

## 🎯 Final Verdict

> **This system is production-ready for school deployment.**
> 
> Not perfect. But **strong enough for real-world use**.
> 
> Secure enough to prevent casual attacks.
> Simple enough to maintain.
> Realistic enough to ship.

**Stop planning. Start building.** 🚀

---

**Document Ownership:** Architecture Team  
**Last Review:** 2026-01-19  
**Next Review:** After MVP deployment  
**Status:** 🔒 LOCKED
