# Protokol Keamanan AbsensiQRPro

## Daftar Isi
1. [Ringkasan Keamanan](#ringkasan-keamanan)
2. [Arsitektur Keamanan](#arsitektur-keamanan)
3. [Autentikasi & Otorisasi](#autentikasi--otorisasi)
4. [Perlindungan Data](#perlindungan-data)
5. [Keamanan QR Code](#keamanan-qr-code)
6. [Keamanan Multi-Tenant](#keamanan-multi-tenant)
7. [Keamanan API](#keamanan-api)
8. [Keamanan Mobile App](#keamanan-mobile-app)
9. [Incident Response](#incident-response)
10. [Compliance Checklist](#compliance-checklist)
11. [Audit & Monitoring](#audit--monitoring)

---

## Ringkasan Keamanan

### Security Principles

AbsensiQRPro dibangun dengan prinsip **Defense in Depth**:

1. **Least Privilege** - User hanya dapat akses yang mereka butuhkan
2. **Zero Trust** - Verifikasi setiap request, tidak percaya blind trust
3. **Data Minimization** - Simpan hanya data yang diperlukan
4. **Encryption Everywhere** - Data terenkripsi at rest dan in transit
5. **Audit Everything** - Log semua aktivitas sensitif

### Security Features Overview

| Layer | Protection |
|-------|------------|
| Transport | TLS 1.3, HSTS, SSL Pinning (mobile) |
| Authentication | Sanctum tokens, MFA-ready, device binding |
| Authorization | RBAC, Policy-based, Multi-tenant isolation |
| Data | AES-256 encryption, bcrypt passwords |
| QR Code | HMAC-SHA256 signed, time-limited |
| API | Rate limiting, input validation, CORS |
| Monitoring | Security events, Sentry, audit logs |

---

## Arsitektur Keamanan

### Security Layers

```
┌─────────────────────────────────────────────────────────────┐
│                     CLIENT LAYER                            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │ Mobile App  │  │  Web Admin  │  │  Web Portal │         │
│  │ SSL Pinning │  │    HTTPS    │  │    HTTPS    │         │
│  └─────────────┘  └─────────────┘  └─────────────┘         │
└─────────────────────────────────────────────────────────────┘
                           │
                    TLS 1.3 Only
                           │
┌─────────────────────────────────────────────────────────────┐
│                   EDGE LAYER (CDN/WAF)                      │
│  • DDoS Protection  • Bot Detection  • Geo Blocking        │
│  • Rate Limiting    • WAF Rules                             │
└─────────────────────────────────────────────────────────────┘
                           │
┌─────────────────────────────────────────────────────────────┐
│                  APPLICATION LAYER                          │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                  NGINX (Reverse Proxy)               │   │
│  │  • Security Headers  • Rate Limiting  • Access Log   │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                 │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                        PHP-FPM                       │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                 │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                   LARAVEL APPLICATION                │   │
│  │  • FormRequest Validation  • Policy Authorization    │   │
│  │  • Rate Limiting Middleware • Security Middleware    │   │
│  │  • Service Layer  • Multi-tenant Scoping            │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           │
┌─────────────────────────────────────────────────────────────┐
│                     DATA LAYER                              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │ PostgreSQL  │  │    Redis    │  │  File Store │         │
│  │ Encrypted   │  │  Encrypted  │  │  Encrypted  │         │
│  └─────────────┘  └─────────────┘  └─────────────┘         │
└─────────────────────────────────────────────────────────────┘
```

### Middleware Stack

Request melewati middleware dalam urutan:

1. `SecurityHeaders` - X-Frame-Options, CSP, XSS Protection
2. `CheckApiMaintenance` - Block saat maintenance
3. `auth:sanctum` - Verifikasi token
4. `CheckUserActive` - Pastikan akun aktif
5. `RateLimitBySchool` - Per-tenant rate limiting
6. Route-specific policies

---

## Autentikasi & Otorisasi

### Token-Based Authentication

**Flow Autentikasi:**

```
1. Client → POST /api/v1/auth/login
   Body: { username, password, device_info }
   
2. Server validates credentials
   - Check password hash (bcrypt, cost 12)
   - Verify account active
   - Check device binding (for teachers)
   
3. Server → Client
   Response: {
     access_token: "...",     // 15 menit
     refresh_token: "...",    // 7 hari
     token_type: "Bearer"
   }
   
4. Client stores tokens securely
   - Mobile: EncryptedStorage
   - Web: sessionStorage (NOT localStorage)
   
5. Subsequent requests include:
   Authorization: Bearer <access_token>
```

### Token Management

```php
// Token Configuration
'sanctum' => [
    'expiration' => 15,        // Access token: 15 minutes
    'refresh_expiration' => 10080, // Refresh: 7 days
    'stateful' => [],          // API-only, no cookie sessions
]
```

**Token Refresh:**
```
POST /api/v1/auth/refresh
Header: Authorization: Bearer <refresh_token>
Response: { access_token: "new_token" }
```

### Password Policy

- **Minimum length:** 8 karakter
- **Complexity:** Huruf besar, huruf kecil, angka
- **Hash algorithm:** bcrypt dengan cost 12
- **Reset:** Via email, link expire 1 jam
- **Default password:** Tanggal lahir DDMMYYYY (wajib ganti saat login pertama)

### Role-Based Access Control (RBAC)

| Role | Capabilities |
|------|-------------|
| `super_admin` | Full system access, bypass tenant scope |
| `school_admin` | Manage school data, users, reports |
| `principal` | View-only monitoring, approve requests |
| `homeroom_teacher` | Manage class, view student attendance |
| `teacher` | QR generation, attendance management |
| `student` | Scan QR, view own attendance |
| `parent` | View child's attendance, receive notifications |

### Policy Authorization

```php
// Example: StudentPolicy
public function view(User $user, Student $student): bool
{
    // Student can only view themselves
    if ($user->role_type === 'student') {
        return $user->student_id === $student->id;
    }
    
    // Teacher can view students in their class
    if ($user->role_type === 'teacher') {
        return $user->teaches($student->class);
    }
    
    // Admin can view all students in their school
    return $user->school_id === $student->school_id;
}
```

---

## Perlindungan Data

### Data Classification

| Level | Examples | Protection |
|-------|----------|------------|
| **Public** | School name, schedule | No encryption |
| **Internal** | Attendance records | DB encryption |
| **Confidential** | User passwords, tokens | bcrypt/AES-256 |
| **Sensitive** | Student personal data | AES-256 + access log |

### Encryption Standards

**At Rest:**
- Database: PostgreSQL TDE (Transparent Data Encryption)
- Redis: Encrypted persistence
- File storage: AES-256 encrypted

**In Transit:**
- TLS 1.3 only (1.2 deprecated)
- Strong cipher suites
- Certificate pinning on mobile

**Application Level:**
```php
// Encrypt sensitive fields
use Illuminate\Support\Facades\Crypt;

$encryptedNisn = Crypt::encryptString($student->nisn);

// Environment variables
QR_SECRET_KEY=your-32-byte-secret-key  // Separate from APP_KEY
```

### Data Retention

| Data Type | Retention | Deletion Method |
|-----------|-----------|-----------------|
| Attendance records | 5 years | Soft delete → archive |
| User accounts | Active + 1 year | Hard delete on request |
| Audit logs | 2 years | Rotate to archive |
| Session tokens | Auto expire | TTL-based |
| Failed login attempts | 30 days | Auto purge |

### Personal Data Handling (GDPR-like)

1. **Right to Access:** User dapat export data mereka
2. **Right to Rectification:** Data dapat dikoreksi via admin
3. **Right to Erasure:** Hard delete available (with audit trail)
4. **Data Portability:** Export dalam format JSON/CSV
5. **Consent:** Explicit consent pada registrasi

---

## Keamanan QR Code

### QR Token Architecture

**PENTING: Tidak menggunakan Laravel `encrypt()`**

QR Token menggunakan **stateless HMAC-based tokens**:

```php
// Token Structure
base64(payload) . '.' . HMAC-SHA256(payload, QR_SECRET_KEY)

// Payload contains:
{
    "schedule_id": 123,
    "qr_id": "uuid",
    "type": "in",        // in/out
    "exp": 1678901234,   // expiry timestamp
    "nonce": "random"    // anti-replay
}
```

### Anti-Replay Protection

```php
// Redis-based nonce tracking
public function validateNonce(string $nonce, string $scheduleId): bool
{
    $key = "qr_nonce:{$scheduleId}:{$nonce}";
    
    if (Cache::has($key)) {
        throw new QrAlreadyUsedException();
    }
    
    // Mark as used, TTL = QR validity + buffer
    Cache::put($key, true, now()->addMinutes(10));
    
    return true;
}
```

### Location Validation

```php
// Haversine formula for distance calculation
public function validateLocation(float $lat, float $lng, School $school): bool
{
    $distance = $this->haversineDistance(
        $lat, $lng,
        $school->latitude, $school->longitude
    );
    
    if ($distance > $school->allowed_radius) {
        throw new OutsideRadiusException($distance, $school->allowed_radius);
    }
    
    return true;
}
```

### QR Security Measures

| Measure | Implementation |
|---------|----------------|
| Time-limited | 30 sec - 5 min validity |
| Location-bound | GPS radius validation |
| One-time use | Nonce-based anti-replay |
| Device-bound (teacher) | Device registration required |
| Signed | HMAC-SHA256 signature |
| Stateless | No database lookup for validation |

---

## Keamanan Multi-Tenant

### Tenant Isolation

```php
// SchoolScope automatically applied
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->check() && !auth()->user()->isSuperAdmin()) {
            $builder->where('school_id', auth()->user()->school_id);
        }
    }
}

// Usage in models
class Student extends Model
{
    use BelongsToSchool;  // Applies SchoolScope
}
```

### Cross-Tenant Access Prevention

```php
// NEVER access other tenant's data without explicit bypass
$students = Student::where('school_id', $otherSchoolId)->get(); // ❌ Blocked

// Bypass ONLY for super_admin with audit
$students = Student::withoutGlobalScope(SchoolScope::class)
    ->where('school_id', $otherSchoolId)
    ->get();
// ^ This is logged in audit
```

### Super Admin Bypass Logging

```php
// Setiap bypass SchoolScope dicatat
SecurityEvent::create([
    'event_type' => 'scope_bypass',
    'user_id' => auth()->id(),
    'details' => [
        'model' => get_class($model),
        'target_school_id' => $schoolId,
        'reason' => $reason
    ]
]);
```

---

## Keamanan API

### Input Validation

Semua input divalidasi via **FormRequest**:

```php
class AttendanceScanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
```

### Rate Limiting

```php
// Global rate limit
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// Per-school rate limit
RateLimiter::for('school', function (Request $request) {
    $schoolId = $request->user()?->school_id;
    return Limit::perMinute(1000)->by("school:{$schoolId}");
});

// Sensitive endpoints (login)
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->input('username').$request->ip());
});
```

### Security Headers

Response headers (via SecurityHeaders middleware):

```http
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Content-Security-Policy: default-src 'self'; script-src 'self'
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(self)
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

### CORS Configuration

```php
// cors.php
'allowed_origins' => [env('FRONTEND_URL')],
'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
'allowed_headers' => ['Content-Type', 'Authorization', 'X-Request-ID'],
'exposed_headers' => ['X-Request-ID'],
'max_age' => 86400,
'supports_credentials' => false,
```

---

## Keamanan Mobile App

### Certificate Pinning

```typescript
// React Native SSL Pinning
import { fetch } from 'react-native-ssl-pinning';

const response = await fetch(`${API_URL}/endpoint`, {
  method: 'POST',
  sslPinning: {
    certs: ['cert1', 'cert2']  // SHA256 fingerprints
  }
});
```

### Secure Storage

```typescript
// EncryptedStorage for sensitive data
import EncryptedStorage from 'react-native-encrypted-storage';

// Store tokens
await EncryptedStorage.setItem('auth_token', token);

// Retrieve tokens  
const token = await EncryptedStorage.getItem('auth_token');
```

### Device Security Checks

```typescript
// Check for rooted/jailbroken devices
import JailMonkey from 'jail-monkey';

if (JailMonkey.isJailBroken() || JailMonkey.canMockLocation()) {
  // Warn user, restrict functionality
  Alert.alert('Security Warning', 'Device security compromised');
}
```

### Mobile-Specific Protections

| Protection | Implementation |
|------------|----------------|
| Root/Jailbreak detection | JailMonkey library |
| Screen capture prevention | FLAG_SECURE (Android) |
| App tampering detection | Code signature verification |
| Debugger detection | Anti-debugging checks |
| Secure clipboard | Clear clipboard after paste |

---

## Incident Response

### Severity Levels

| Level | Description | Response Time | Example |
|-------|-------------|---------------|---------|
| **P1 Critical** | System down, data breach | 15 minutes | Full outage, data leak |
| **P2 High** | Major feature broken, security vulnerability | 1 hour | Auth bypass, SQL injection |
| **P3 Medium** | Minor feature impacted | 4 hours | UI bug, performance degradation |
| **P4 Low** | Cosmetic, enhancement | 24 hours | Typo, minor UX issue |

### Incident Response Procedure

```
┌─────────────────────────────────────────────────────────────┐
│                    INCIDENT DETECTED                        │
│         (Monitoring alert / User report / Audit log)        │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    1. IDENTIFICATION                        │
│  • Assess severity (P1-P4)                                  │
│  • Gather initial information                               │
│  • Assign incident owner                                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    2. CONTAINMENT                           │
│  • Isolate affected systems                                 │
│  • Block malicious access                                   │
│  • Preserve evidence (logs, memory dumps)                   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    3. ERADICATION                           │
│  • Remove threat/vulnerability                              │
│  • Patch systems                                            │
│  • Reset compromised credentials                            │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                     4. RECOVERY                             │
│  • Restore from clean backup                                │
│  • Verify system integrity                                  │
│  • Gradual traffic restoration                              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                   5. POST-MORTEM                            │
│  • Document timeline                                        │
│  • Root cause analysis                                      │
│  • Update procedures                                        │
│  • Communication to stakeholders                            │
└─────────────────────────────────────────────────────────────┘
```

### Security Incident Contacts

| Role | Contact | Responsibility |
|------|---------|----------------|
| Incident Commander | security@absensi-sekolah.com | Overall coordination |
| Technical Lead | tech-lead@absensi-sekolah.com | Technical investigation |
| Communications | comms@absensi-sekolah.com | External communication |
| Legal/Compliance | legal@absensi-sekolah.com | Regulatory notification |

### Data Breach Notification

Jika terjadi data breach:

1. **72 jam**: Investigasi awal selesai
2. **Assess impact**: Tentukan data apa yang terekspos
3. **Notify regulators**: Sesuai peraturan (jika applicable)
4. **Notify affected users**: Email + in-app notification
5. **Public disclosure**: Jika diperlukan

---

## Compliance Checklist

### Security Compliance Matrix

| Requirement | Status | Implementation |
|-------------|--------|----------------|
| **Data Encryption** | ✅ | TLS 1.3, AES-256 |
| **Access Control** | ✅ | RBAC, Policies |
| **Audit Logging** | ✅ | SecurityEvent model |
| **Password Policy** | ✅ | bcrypt, complexity rules |
| **Session Management** | ✅ | Short-lived tokens |
| **Input Validation** | ✅ | FormRequest classes |
| **Error Handling** | ✅ | No sensitive data in errors |
| **Backup & Recovery** | ✅ | Daily backups, tested |
| **Incident Response** | ✅ | Documented procedure |
| **Security Updates** | ✅ | Monthly patching |

### Pre-Deployment Security Checklist

```
□ APP_DEBUG=false
□ APP_ENV=production
□ Unique APP_KEY generated
□ Unique QR_SECRET_KEY (32+ chars)
□ Database credentials not default
□ Redis password set
□ HTTPS enforced
□ Security headers enabled
□ Rate limiting configured
□ CORS restricted to allowed origins
□ File permissions (755 dirs, 644 files)
□ Sensitive files not in webroot
□ Latest security patches applied
□ Firewall configured (only needed ports)
□ Logging enabled (not to public dir)
□ Error pages don't expose stack traces
□ Backup encryption enabled
□ SSL certificate valid
□ Two-person rule for production access
```

### Periodic Security Tasks

| Task | Frequency | Owner |
|------|-----------|-------|
| Dependency updates | Weekly | DevOps |
| Security patches | As released | DevOps |
| Access review | Monthly | Admin |
| Penetration testing | Quarterly | Security Team |
| Backup restore test | Monthly | DevOps |
| Incident response drill | Quarterly | All Teams |
| Security training | Annually | All Staff |

---

## Audit & Monitoring

### Security Events Logged

```php
// SecurityEvent types
const EVENT_TYPES = [
    'login_success',
    'login_failed',
    'password_reset',
    'password_changed',
    'token_refresh',
    'logout',
    'permission_denied',
    'scope_bypass',
    'suspicious_activity',
    'data_export',
    'bulk_operation',
];
```

### Monitoring Alerts

| Alert | Threshold | Action |
|-------|-----------|--------|
| Failed logins (same user) | 5 in 15 min | Lock account |
| Failed logins (same IP) | 20 in 15 min | Block IP |
| 5xx errors | > 10/min | Page on-call |
| Response time | > 5s average | Investigate |
| Scope bypass | Any | Review audit log |
| Unusual data export | > 1000 records | Alert admin |

### Log Retention

```yaml
# Log rotation policy
laravel.log:
  rotation: daily
  retention: 30 days
  compression: gzip

security_events:
  retention: 2 years
  archive: cold storage after 90 days

audit_logs:
  retention: 5 years (legal requirement)
  encryption: AES-256
```

### Security Dashboard Metrics

Monitoring dashboard menampilkan:

1. **Authentication**
   - Login attempts (success/fail ratio)
   - Active sessions per school
   - Password reset requests

2. **API Health**
   - Request volume
   - Error rates
   - Rate limit hits

3. **Security Events**
   - Permission denials
   - Suspicious activities
   - Scope bypasses

4. **System**
   - Certificate expiry countdown
   - Dependency vulnerabilities
   - Patch status

---

## Emergency Procedures

### Account Compromise

```bash
# 1. Force logout semua sessions user
php artisan user:revoke-tokens --user=user_id

# 2. Lock account
php artisan user:lock user_id --reason="Suspected compromise"

# 3. Reset password
php artisan user:reset-password user_id --notify

# 4. Review audit log
php artisan audit:review --user=user_id --days=30
```

### System Compromise

```bash
# 1. Enable maintenance mode
php artisan down --secret="emergency-bypass-token"

# 2. Rotate all secrets
php artisan key:generate --force
# Update QR_SECRET_KEY manually

# 3. Revoke all tokens
php artisan sanctum:prune-expired --hours=0

# 4. Review and restore from clean backup if needed
```

### DDoS Attack

1. Enable WAF aggressive mode
2. Activate CDN DDoS protection
3. Block suspicious IP ranges
4. Scale up server resources
5. Contact hosting provider

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Feb 2026 | Initial security protocol |

---

*Dokumen ini adalah CONFIDENTIAL dan tidak boleh dibagikan ke pihak eksternal tanpa izin.*
