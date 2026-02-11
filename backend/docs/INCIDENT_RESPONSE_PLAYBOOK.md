# 🚨 Incident Response Playbook - AbsensiQRPro

**Versi:** 1.0  
**Tanggal:** 7 Februari 2026  
**Klasifikasi:** INTERNAL - OPERASIONAL  
**Pemilik Dokumen:** Security Team  

---

## Daftar Isi

1. [Pendahuluan](#pendahuluan)
2. [Tim Respons & Eskalasi](#tim-respons--eskalasi)
3. [Skenario 1: Tenant Data Leak](#skenario-1-tenant-data-leak)
4. [Skenario 2: Replay Exploit Massal](#skenario-2-replay-exploit-massal)
5. [Skenario 3: SQL Injection Berhasil](#skenario-3-sql-injection-berhasil)
6. [Skenario 4: Database Corruption dari Migration Gagal](#skenario-4-database-corruption-dari-migration-gagal)
7. [Template Laporan Insiden](#template-laporan-insiden)
8. [Kontak Darurat](#kontak-darurat)

---

## Pendahuluan

### Tujuan Dokumen
Playbook ini memberikan panduan langkah-demi-langkah untuk menangani insiden keamanan dan operasional pada sistem AbsensiQRPro. Setiap anggota tim yang terlibat dalam respons insiden **WAJIB** membaca dan memahami dokumen ini.

### Severity Level

| Level | Deskripsi | Response Time | Eskalasi |
|-------|-----------|---------------|----------|
| **P1 - Critical** | Data breach aktif, sistem down, eksploitasi massal | < 15 menit | CTO + CEO |
| **P2 - High** | Vulnerability tereksploitasi, data terancam | < 1 jam | CTO + Lead Dev |
| **P3 - Medium** | Anomali keamanan, potensi eksploitasi | < 4 jam | Lead Dev |
| **P4 - Low** | Aktivitas mencurigakan, false positive | < 24 jam | DevOps |

### Prinsip PICERL

Semua respons insiden mengikuti framework **PICERL**:
1. **P**reparation - Kesiapan sebelum insiden
2. **I**dentification - Deteksi dan konfirmasi insiden
3. **C**ontainment - Membatasi dampak
4. **E**radication - Menghilangkan ancaman
5. **R**ecovery - Pemulihan sistem
6. **L**essons Learned - Evaluasi dan perbaikan

---

## Tim Respons & Eskalasi

### Incident Response Team (IRT)

| Role | Tanggung Jawab | Backup |
|------|----------------|--------|
| **Incident Commander** | Koordinasi keseluruhan, keputusan eksekutif | CTO |
| **Technical Lead** | Analisis teknis, eksekusi containment | Senior Backend Dev |
| **Communications Lead** | Notifikasi stakeholder, dokumentasi | Product Manager |
| **DevOps Lead** | Infrastruktur, backup, recovery | System Admin |

### Eskalasi Matrix

```
[Deteksi Anomali]
       │
       ▼
[DevOps/On-Call] ──(P4)──> Log & Monitor
       │
      (P3)
       │
       ▼
[Technical Lead] ──(P2)──> [CTO + IRT Activation]
       │
      (P1)
       │
       ▼
[Full IRT + Executive Notification]
```

---

## Skenario 1: Tenant Data Leak

### 📋 Overview

**Severity:** P1 - CRITICAL  
**Deskripsi:** Admin atau user dari Sekolah A dapat mengakses data dari Sekolah B, melanggar isolasi multi-tenant.

**Contoh Vektor:**
- SchoolScope bypass via `withoutGlobalScope()`
- Direct DB query tanpa filter `school_id`
- API endpoint tanpa policy check
- Join query yang mengekspos data lintas tenant

---

### 🔍 DETECTION METHOD

#### A. Automated Detection (Real-time)

**1. Log Monitor - SchoolScope Bypass**
```bash
# Cek log bypass scope yang tidak authorized
tail -f storage/logs/security_json.log | grep -E "scope_bypass|unauthorized_access"

# Query Kibana/ELK (jika ada)
# index: absensi-security-*
# query: event_type:"scope_bypass" AND authorized:false
```

**2. Database Audit Query**
```sql
-- Deteksi akses lintas tenant dalam 24 jam terakhir
SELECT 
    al.user_id,
    al.action,
    al.target_school_id,
    u.school_id as user_school_id,
    al.created_at
FROM audit_logs al
JOIN users u ON al.user_id = u.id
WHERE al.target_school_id != u.school_id
  AND u.role_type != 'super_admin'
  AND al.created_at > NOW() - INTERVAL '24 hours'
ORDER BY al.created_at DESC;
```

**3. Aplikasi Health Check**
```php
// app/Console/Commands/SecurityAuditCommand.php
// Jalankan: php artisan security:audit --check=tenant-isolation
```

#### B. Manual Detection Indicators

| Indikator | Severity | Action |
|-----------|----------|--------|
| User report: "Melihat data sekolah lain" | P1 | Immediate escalation |
| Log: `SchoolScope bypass by non-super_admin` | P1 | Investigate immediately |
| Anomaly: User query count spike untuk multiple schools | P2 | Investigate within 1hr |
| Code review: `withoutGlobalScope` tanpa authorization | P3 | Schedule fix |

---

### 🛡️ CONTAINMENT STEPS

**Timeline Target: < 30 menit dari deteksi**

#### Step 1: Konfirmasi Insiden (5 menit)
```bash
# 1.1 Verifikasi dari log
cd /var/www/absensi/backend
grep -r "school_id.*mismatch\|cross.tenant" storage/logs/laravel*.log | tail -50

# 1.2 Cek affected users
php artisan tinker --execute="
    \DB::table('audit_logs')
        ->where('event_type', 'cross_tenant_access')
        ->where('created_at', '>', now()->subHours(24))
        ->get()
        ->each(fn(\$log) => dump(\$log));
"
```

#### Step 2: Isolasi Affected Endpoints (10 menit)
```bash
# 2.1 Identifikasi endpoint yang vulnerable
grep -r "withoutGlobalScope" app/Http/Controllers/ --include="*.php"

# 2.2 Emergency disable endpoint (via middleware)
# Edit config/security.php
php artisan config:clear

# Atau via .env
echo "EMERGENCY_LOCKDOWN_ENDPOINTS=/api/v1/reports,/api/v1/export" >> .env
php artisan config:cache
```

#### Step 3: Revoke Affected Sessions (5 menit)
```php
// Jalankan via tinker atau artisan command
// php artisan emergency:revoke-sessions --affected-schools=1,2,3

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

$affectedUserIds = [/* IDs dari audit */];

PersonalAccessToken::whereIn('tokenable_id', $affectedUserIds)->delete();

// Force logout semua user affected schools
User::whereIn('school_id', $affectedSchoolIds)
    ->update(['force_logout_at' => now()]);
```

#### Step 4: Enable Enhanced Logging (5 menit)
```bash
# Tambah debug logging untuk semua query
# .env
LOG_LEVEL=debug
QUERY_LOG_ENABLED=true

php artisan config:cache
php artisan cache:clear
```

#### Step 5: Notify Stakeholders (5 menit)
```
TEMPLATE NOTIFIKASI INTERNAL:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[SECURITY INCIDENT - P1]

Time Detected: {TIMESTAMP}
Type: Tenant Data Isolation Breach
Status: CONTAINMENT IN PROGRESS

Affected:
- Schools: {LIST}
- Estimated Users: {COUNT}
- Data Type: {attendance/student/schedule}

Actions Taken:
✓ Vulnerable endpoints disabled
✓ Affected sessions revoked
✓ Enhanced logging enabled

Next Update: {TIMESTAMP + 30min}

Incident Commander: {NAME}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

### 🔧 ERADICATION STEPS

**Timeline Target: < 2 jam dari containment**

#### Step 1: Root Cause Analysis
```bash
# 1.1 Trace vulnerable code path
git log --oneline --since="7 days ago" -- app/Http/Controllers/ app/Policies/ app/Scopes/

# 1.2 Review recent changes yang bypass scope
git diff HEAD~20..HEAD -- app/

# 1.3 Check for missing policy
grep -rL "authorize\|policy" app/Http/Controllers/Api/ --include="*.php"
```

#### Step 2: Patch Vulnerable Code

**Pattern A: Missing SchoolScope**
```php
// BEFORE (Vulnerable)
$data = Attendance::where('id', $id)->first();

// AFTER (Fixed) - Scope otomatis via BelongsToSchool trait
// Jika model sudah pakai trait, cek kenapa bypass:
$data = Attendance::where('id', $id)->first();

// Atau explicit check:
$data = Attendance::where('id', $id)
    ->where('school_id', auth()->user()->school_id)
    ->firstOrFail();
```

**Pattern B: Missing Policy Check**
```php
// BEFORE (Vulnerable)
public function show($id) {
    return Attendance::findOrFail($id);
}

// AFTER (Fixed)
public function show($id) {
    $attendance = Attendance::findOrFail($id);
    $this->authorize('view', $attendance); // Policy check
    return $attendance;
}
```

**Pattern C: Raw Query tanpa Filter**
```php
// BEFORE (Vulnerable)
DB::table('attendances')->where('student_id', $studentId)->get();

// AFTER (Fixed)
DB::table('attendances')
    ->where('student_id', $studentId)
    ->where('school_id', auth()->user()->school_id)
    ->get();
```

#### Step 3: Deploy Hotfix
```bash
# 3.1 Create hotfix branch
git checkout -b hotfix/tenant-isolation-$(date +%Y%m%d)

# 3.2 Commit fixes
git add -A
git commit -m "SECURITY: Fix tenant isolation breach - [INCIDENT-XXX]"

# 3.3 Run tests
php artisan test --filter=MultiTenancyIsolationTest
php artisan test --filter=PolicyEnforcementTest

# 3.4 Deploy (with approval)
git push origin hotfix/tenant-isolation-$(date +%Y%m%d)
# Create PR, get emergency approval, merge & deploy
```

#### Step 4: Verify Fix
```bash
# 4.1 Run security test suite
php artisan test --group=security

# 4.2 Manual verification
php artisan tinker --execute="
    // Login as School A admin
    \$adminA = User::where('school_id', 1)->where('role_type', 'school_admin')->first();
    auth()->login(\$adminA);
    
    // Try to access School B data (should fail)
    \$schoolBAttendance = Attendance::where('school_id', 2)->first();
    dump('Should be null:', \$schoolBAttendance);
"
```

---

### 🔄 RECOVERY STEPS

**Timeline Target: < 4 jam dari eradication**

#### Step 1: Assess Data Exposure
```sql
-- Generate laporan data yang terekspos
SELECT 
    al.user_id,
    u.email as accessor_email,
    u.school_id as accessor_school,
    al.target_school_id as exposed_school,
    al.resource_type,
    al.resource_id,
    al.action,
    al.created_at
FROM audit_logs al
JOIN users u ON al.user_id = u.id
WHERE al.event_type = 'cross_tenant_access'
  AND al.created_at BETWEEN '{INCIDENT_START}' AND '{INCIDENT_END}'
ORDER BY al.created_at;
```

#### Step 2: Notify Affected Schools
```
TEMPLATE NOTIFIKASI SEKOLAH:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Kepada Yth. Admin {SCHOOL_NAME},

Kami informasikan bahwa pada {DATE}, sistem kami mengalami 
insiden keamanan yang telah dimitigasi.

RINGKASAN INSIDEN:
- Jenis: Akses data tidak sah antar institusi
- Durasi: {START_TIME} - {END_TIME}
- Status: RESOLVED

DATA YANG MUNGKIN TEREKSPOS:
- {DATA_TYPE_1}: {COUNT} records
- {DATA_TYPE_2}: {COUNT} records

TINDAKAN YANG TELAH DILAKUKAN:
✓ Celah keamanan telah diperbaiki
✓ Sesi yang terpengaruh telah diakhiri
✓ Monitoring keamanan ditingkatkan

REKOMENDASI UNTUK ADMIN:
1. Reset password admin sekolah
2. Review log aktivitas 7 hari terakhir
3. Laporkan jika menemukan anomali

Kami mohon maaf atas ketidaknyamanan ini.

Hormat kami,
Tim Keamanan AbsensiQRPro

[Lampiran: Laporan Detail Insiden]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

#### Step 3: Re-enable Services
```bash
# 3.1 Remove emergency lockdown
sed -i '/EMERGENCY_LOCKDOWN/d' .env
php artisan config:cache

# 3.2 Re-enable disabled endpoints
php artisan route:cache

# 3.3 Clear maintenance mode (if applied)
php artisan up

# 3.4 Verify services
curl -X GET https://api.absensi.app/health
```

#### Step 4: Monitor for Recurrence
```bash
# Setup alert untuk 7 hari ke depan
# Tambahkan ke monitoring dashboard:

# Alert Rule 1: Cross-tenant access attempt
# Trigger: log contains "cross_tenant" AND severity >= WARNING
# Action: PagerDuty alert

# Alert Rule 2: SchoolScope bypass
# Trigger: log contains "scope_bypass" AND authorized = false  
# Action: Slack notification + log to incident channel
```

---

### 📝 POST-MORTEM CHECKLIST

**Timeline: Dalam 48 jam setelah recovery**

#### Documentation
- [ ] Timeline insiden lengkap (deteksi → recovery)
- [ ] Root cause analysis document
- [ ] List semua affected schools dan data types
- [ ] Screenshots/logs sebagai evidence
- [ ] Hotfix code diff

#### Technical Review
- [ ] Code review: Semua penggunaan `withoutGlobalScope()`
- [ ] Audit: Semua controller tanpa policy check
- [ ] Test: Tambah test case untuk vektor serangan baru
- [ ] Security scan: Run static analysis (PHPStan, Psalm)

#### Process Improvement
- [ ] Update PR checklist untuk include tenant isolation review
- [ ] Tambah automated test ke CI/CD pipeline
- [ ] Update security monitoring rules
- [ ] Schedule security training untuk tim

#### Compliance
- [ ] Notifikasi ke affected parties (jika required by regulation)
- [ ] Update risk register
- [ ] Review insurance coverage
- [ ] File incident report ke management

#### Sign-off
- [ ] Technical Lead: _______________  Date: _______
- [ ] Security Lead: _______________  Date: _______
- [ ] CTO: _______________  Date: _______

---

## Skenario 2: Replay Exploit Massal

### 📋 Overview

**Severity:** P1 - CRITICAL  
**Deskripsi:** Attacker berhasil melakukan replay attack massal untuk membuat attendance record palsu.

**Contoh Vektor:**
- Capture & replay QR token sebelum expired
- Bypass idempotency check
- Nonce reuse attack
- Token signature forgery

---

### 🔍 DETECTION METHOD

#### A. Automated Detection

**1. Rate Anomaly Detection**
```sql
-- Deteksi user dengan attendance scan abnormal
SELECT 
    student_id,
    COUNT(*) as scan_count,
    COUNT(DISTINCT schedule_id) as unique_schedules,
    MIN(created_at) as first_scan,
    MAX(created_at) as last_scan
FROM attendances
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY student_id
HAVING COUNT(*) > 10  -- Threshold: max 10 scans per hour
ORDER BY scan_count DESC;
```

**2. Duplicate Request Detection**
```bash
# Monitor idempotency violations
grep "idempotency.*duplicate\|replay.*detected" storage/logs/security_json.log | \
    awk '{print $0}' | \
    sort | uniq -c | sort -rn | head -20
```

**3. QR Token Anomaly**
```sql
-- Deteksi token reuse attempts
SELECT 
    qr_token_hash,
    COUNT(*) as use_count,
    COUNT(DISTINCT student_id) as unique_students,
    COUNT(DISTINCT ip_address) as unique_ips
FROM attendance_scan_logs
WHERE created_at > NOW() - INTERVAL '1 hour'
GROUP BY qr_token_hash
HAVING COUNT(*) > 1
ORDER BY use_count DESC;
```

**4. Security Event Monitor**
```php
// Cek SecurityEvent untuk replay attempts
SecurityEvent::where('event_type', 'replay_attack_detected')
    ->where('created_at', '>', now()->subHour())
    ->count();
// Alert jika > 10 dalam 1 jam
```

#### B. Indicators of Compromise (IoC)

| Indikator | Threshold | Severity |
|-----------|-----------|----------|
| Same token used multiple times | > 1 use | P1 |
| Same student, multiple scans < 1 min apart | > 3 scans | P1 |
| Attendance from same IP, different students | > 20/hour | P2 |
| Failed idempotency checks spike | > 100/hour | P2 |
| QR token usage after expiry attempts | > 50/hour | P3 |

---

### 🛡️ CONTAINMENT STEPS

**Timeline Target: < 15 menit dari deteksi**

#### Step 1: Immediate Block (2 menit)
```bash
# 1.1 Block attacking IPs at firewall level
# Identifikasi IPs dari log
ATTACKER_IPS=$(grep "replay_attack" storage/logs/security_json.log | \
    jq -r '.ip_address' | sort | uniq -c | sort -rn | \
    awk '$1 > 10 {print $2}')

# Block via iptables atau cloud firewall
for ip in $ATTACKER_IPS; do
    iptables -A INPUT -s $ip -j DROP
    echo "Blocked: $ip"
done

# Atau via Laravel rate limiter emergency
php artisan tinker --execute="
    Cache::put('emergency_blocked_ips', explode(',', '$ATTACKER_IPS'), 3600);
"
```

#### Step 2: Disable Attendance Endpoints (3 menit)
```bash
# 2.1 Emergency maintenance mode untuk attendance only
echo "ATTENDANCE_ENDPOINTS_DISABLED=true" >> .env
php artisan config:cache

# 2.2 Atau via route middleware
# Tambah ke app/Http/Middleware/EmergencyMaintenance.php
```

```php
// Temporary middleware check
if (config('app.attendance_endpoints_disabled') && 
    str_contains($request->path(), 'attendance')) {
    return response()->json([
        'success' => false,
        'message' => 'Layanan attendance sedang dalam maintenance.',
        'retry_after' => 300
    ], 503);
}
```

#### Step 3: Invalidate All Active QR Tokens (5 menit)
```php
// Invalidate semua QR token yang active
// php artisan emergency:invalidate-qr-tokens

use Illuminate\Support\Facades\Cache;

// Jika menggunakan cache-based tokens
Cache::tags(['qr_tokens'])->flush();

// Jika ada di database
QrCode::where('status', 'active')
    ->where('created_at', '<', now())
    ->update([
        'status' => 'invalidated',
        'invalidated_reason' => 'security_incident_' . date('Ymd'),
    ]);

// Broadcast ke semua teacher apps untuk regenerate QR
event(new EmergencyQrInvalidation());
```

#### Step 4: Flag Suspicious Attendance Records (5 menit)
```sql
-- Flag attendance records yang suspicious untuk review
UPDATE attendances 
SET 
    notes = COALESCE(notes, '') || ' [FLAGGED: Replay investigation]',
    requires_review = true
WHERE created_at BETWEEN '{ATTACK_START}' AND '{ATTACK_END}'
  AND (
    -- Multiple scans in short window
    id IN (
        SELECT a1.id FROM attendances a1
        JOIN attendances a2 ON a1.student_id = a2.student_id
        WHERE a1.id != a2.id
          AND ABS(EXTRACT(EPOCH FROM (a1.created_at - a2.created_at))) < 60
    )
    -- Or from flagged IPs
    OR request_metadata->>'ip_address' IN ({ATTACKER_IPS})
  );
```

---

### 🔧 ERADICATION STEPS

**Timeline Target: < 2 jam dari containment**

#### Step 1: Identify Attack Vector
```bash
# 1.1 Analyze attack pattern
php artisan security:analyze-replay-attack \
    --start="{ATTACK_START}" \
    --end="{ATTACK_END}" \
    --output=storage/incident/replay-analysis.json

# 1.2 Check if token validation was bypassed
grep -A5 "validateQrToken\|verifySignature" app/Services/QrService.php
```

#### Step 2: Strengthen Token Validation
```php
// app/Services/QrService.php

public function validate(string $token): array
{
    // EXISTING: Signature verification
    if (!$this->verifySignature($token)) {
        throw new InvalidTokenException('Invalid signature');
    }
    
    // EXISTING: Expiry check  
    if ($this->isExpired($token)) {
        throw new TokenExpiredException('Token expired');
    }
    
    // ADD: Single-use enforcement
    $tokenHash = hash('sha256', $token);
    if (Cache::has("used_token:{$tokenHash}")) {
        SecurityEvent::log('replay_attack_blocked', [
            'token_hash' => $tokenHash,
            'student_id' => $payload['student_id'] ?? null,
        ]);
        throw new ReplayAttackException('Token already used');
    }
    
    // Mark as used with TTL = token expiry + buffer
    Cache::put("used_token:{$tokenHash}", true, $this->tokenTtl + 300);
    
    // ADD: Request binding
    $expectedRequestId = $payload['request_id'] ?? null;
    $actualRequestId = request()->header('X-Request-ID');
    if ($expectedRequestId && $expectedRequestId !== $actualRequestId) {
        throw new RequestBindingException('Request ID mismatch');
    }
    
    return $payload;
}
```

#### Step 3: Deploy Fixes
```bash
# 3.1 Test fixes locally
php artisan test --filter=ReplayAttackPreventionTest

# 3.2 Deploy with zero-downtime
php artisan down --retry=60 --secret="maintenance-secret-token"
git pull origin hotfix/replay-prevention
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan up
```

#### Step 4: Remove Fraudulent Records
```php
// Review dan soft-delete fraudulent attendance
$fraudulentIds = Attendance::where('requires_review', true)
    ->whereRaw("notes LIKE '%FLAGGED: Replay%'")
    ->pluck('id');

// Backup dulu
DB::table('attendances_backup_incident_xxx')->insertUsing(
    ['*'],
    Attendance::whereIn('id', $fraudulentIds)
);

// Soft delete
Attendance::whereIn('id', $fraudulentIds)->delete();

// Log untuk audit
AuditLog::create([
    'action' => 'bulk_delete_fraudulent_attendance',
    'record_count' => count($fraudulentIds),
    'incident_id' => 'INC-XXX',
    'performed_by' => auth()->id(),
]);
```

---

### 🔄 RECOVERY STEPS

#### Step 1: Re-enable Attendance System
```bash
# 1.1 Remove emergency blocks
sed -i '/ATTENDANCE_ENDPOINTS_DISABLED/d' .env
php artisan config:cache

# 1.2 Unblock IPs (jika diperlukan, atau keep blocked)
# Review ATTACKER_IPS list sebelum unblock

# 1.3 Generate new QR tokens untuk semua active schedules
php artisan qr:regenerate-all --notify-teachers
```

#### Step 2: Notify Affected Schools
```bash
# Kirim notifikasi ke sekolah yang attendance-nya terpengaruh
php artisan notify:affected-schools \
    --incident="replay-attack" \
    --date="{DATE}" \
    --template="replay_attack_notification"
```

#### Step 3: Manual Review Queue
```php
// Buat queue untuk admin sekolah review flagged attendance
$flaggedBySchool = Attendance::where('requires_review', true)
    ->selectRaw('school_id, COUNT(*) as count')
    ->groupBy('school_id')
    ->get();

foreach ($flaggedBySchool as $school) {
    Notification::send(
        User::where('school_id', $school->school_id)
            ->where('role_type', 'school_admin')
            ->get(),
        new AttendanceReviewRequired($school->count)
    );
}
```

---

### 📝 POST-MORTEM CHECKLIST

- [ ] Document attack vector dan timeline
- [ ] Quantify: Jumlah fraudulent records
- [ ] Quantify: Jumlah affected students/schools
- [ ] Review: Apakah attacker mendapat akses lain?
- [ ] Code: Implement rate limiting per student
- [ ] Code: Add device fingerprinting
- [ ] Infra: Setup anomaly detection alerting
- [ ] Test: Add replay attack simulation test
- [ ] Process: Update QR token spec dengan shorter TTL
- [ ] Training: Brief teachers tentang QR security

---

## Skenario 3: SQL Injection Berhasil

### 📋 Overview

**Severity:** P1 - CRITICAL  
**Deskripsi:** Attacker berhasil mengeksekusi SQL injection, potentially accessing atau modifying data.

**Contoh Vektor:**
- Raw query dengan user input tidak di-sanitize
- `DB::raw()` dengan concatenated strings
- Order by clause injection
- LIKE clause injection

---

### 🔍 DETECTION METHOD

#### A. Automated Detection

**1. WAF/Firewall Alerts**
```bash
# Jika menggunakan ModSecurity atau CloudFlare
# Check WAF logs untuk SQL injection patterns
grep -E "SQLI|sql.injection|union.select|or.1.=.1" /var/log/modsec_audit.log
```

**2. Database Query Anomaly**
```sql
-- PostgreSQL: Check for unusual queries
SELECT 
    query,
    calls,
    total_time,
    rows
FROM pg_stat_statements
WHERE query ~* '(union|select.*from.*information_schema|;.*drop|;.*delete|;.*update)'
ORDER BY calls DESC
LIMIT 20;
```

**3. Laravel Query Log Analysis**
```php
// Jika QUERY_LOG_ENABLED=true
$suspiciousPatterns = [
    '/union\s+select/i',
    '/or\s+1\s*=\s*1/i',
    '/;\s*(drop|delete|update|insert)/i',
    '/information_schema/i',
    '/sleep\s*\(/i',
    '/benchmark\s*\(/i',
];

$logs = file('storage/logs/query.log');
foreach ($logs as $line) {
    foreach ($suspiciousPatterns as $pattern) {
        if (preg_match($pattern, $line)) {
            alert("SQLI DETECTED: $line");
        }
    }
}
```

**4. Application Error Spike**
```bash
# Monitor untuk database error spike
grep -c "SQLSTATE\|QueryException" storage/logs/laravel-$(date +%Y-%m-%d).log
# Alert jika > 100 dalam 5 menit
```

#### B. Indicators of Compromise

| Indikator | Severity | Immediate Action |
|-----------|----------|------------------|
| Successful data exfiltration query | P1 | Full shutdown |
| DROP/DELETE query dari non-admin | P1 | Block IP + investigate |
| `information_schema` access | P1 | Investigate source |
| Multiple UNION SELECT attempts | P2 | Block IP |
| Error-based injection probing | P3 | Monitor + rate limit |

---

### 🛡️ CONTAINMENT STEPS

**Timeline Target: IMMEDIATELY upon detection**

#### Step 1: Emergency Database Lockdown (2 menit)
```bash
# 1.1 Untuk serangan aktif, pertimbangkan read-only mode
# PostgreSQL:
psql -U postgres -c "ALTER DATABASE absensi SET default_transaction_read_only = on;"

# Atau restrict application user permissions temporarily
psql -U postgres -c "REVOKE INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public FROM absensi_app;"
```

#### Step 2: Application Emergency Mode (3 menit)
```bash
# 2.1 Full maintenance mode
php artisan down --secret="emergency-access-token" --render="errors::503"

# 2.2 Jika perlu akses partial, block vulnerable endpoints only
# Identifikasi dari error logs endpoint mana yang kena
```

#### Step 3: Capture Evidence (5 menit)
```bash
# 3.1 Snapshot current state
pg_dump absensi > /backup/incident/db_snapshot_$(date +%Y%m%d_%H%M%S).sql

# 3.2 Copy relevant logs
cp storage/logs/laravel*.log /backup/incident/
cp storage/logs/security*.log /backup/incident/
cp /var/log/nginx/access.log /backup/incident/

# 3.3 Capture active connections
psql -U postgres -c "SELECT * FROM pg_stat_activity WHERE datname='absensi';" > /backup/incident/active_connections.txt
```

#### Step 4: Block Attack Source (2 menit)
```bash
# 4.1 Identify attacker IP from logs
ATTACKER_IP=$(grep -E "union.select|or.1.=.1" /var/log/nginx/access.log | \
    awk '{print $1}' | sort | uniq -c | sort -rn | head -1 | awk '{print $2}')

# 4.2 Block at firewall
iptables -A INPUT -s $ATTACKER_IP -j DROP
ufw deny from $ATTACKER_IP

# 4.3 Block at application level
php artisan tinker --execute="
    Cache::forever('blocked_ips', array_merge(
        Cache::get('blocked_ips', []),
        ['$ATTACKER_IP']
    ));
"
```

---

### 🔧 ERADICATION STEPS

#### Step 1: Identify Injection Point
```bash
# 1.1 Find raw queries in codebase
grep -rn "DB::raw\|DB::select\|DB::statement\|whereRaw\|orderByRaw" app/ --include="*.php"

# 1.2 Find string concatenation in queries
grep -rn '"\s*\.\s*\$\|'\''\s*\.\s*\$' app/ --include="*.php" | grep -i "query\|select\|where"

# 1.3 Check request input usage in queries
grep -rn '\$request->.*DB::\|\$request->.*->where\|\$request->.*->orderBy' app/ --include="*.php"
```

#### Step 2: Fix Vulnerable Code

**Pattern A: Raw Query with User Input**
```php
// BEFORE (VULNERABLE!)
$results = DB::select("SELECT * FROM users WHERE name = '" . $request->name . "'");

// AFTER (FIXED)
$results = DB::select("SELECT * FROM users WHERE name = ?", [$request->name]);
```

**Pattern B: OrderBy Injection**
```php
// BEFORE (VULNERABLE!)
$query->orderBy($request->input('sort_by'));

// AFTER (FIXED)
$allowedSortColumns = ['name', 'created_at', 'email'];
$sortBy = in_array($request->input('sort_by'), $allowedSortColumns) 
    ? $request->input('sort_by') 
    : 'created_at';
$query->orderBy($sortBy);
```

**Pattern C: LIKE with User Input**
```php
// BEFORE (VULNERABLE to wildcards)
$query->where('name', 'LIKE', '%' . $request->search . '%');

// AFTER (FIXED - escape special chars)
$search = str_replace(['%', '_'], ['\%', '\_'], $request->search);
$query->where('name', 'LIKE', '%' . $search . '%');
```

#### Step 3: Check for Data Tampering
```sql
-- Check for unauthorized data modifications
-- Compare dengan backup terakhir

-- 1. Check user modifications
SELECT * FROM users 
WHERE updated_at > '{ATTACK_START}'
  AND updated_at < '{ATTACK_END}'
ORDER BY updated_at DESC;

-- 2. Check attendance anomalies
SELECT * FROM attendances
WHERE created_at > '{ATTACK_START}'
  AND (
    notes LIKE '%union%' OR
    notes LIKE '%select%' OR
    student_id NOT IN (SELECT id FROM users WHERE role_type = 'student')
  );

-- 3. Check for new admin users
SELECT * FROM users
WHERE role_type IN ('super_admin', 'admin', 'school_admin')
  AND created_at > '{ATTACK_START}';
```

#### Step 4: Data Integrity Verification
```php
// Compare row counts dengan backup
$tables = ['users', 'schools', 'attendances', 'schedules', 'classes'];

foreach ($tables as $table) {
    $currentCount = DB::table($table)->count();
    $backupCount = DB::connection('backup')->table($table)->count();
    
    if ($currentCount !== $backupCount) {
        Log::alert("DATA INTEGRITY ISSUE: {$table} - Current: {$currentCount}, Backup: {$backupCount}");
    }
}
```

#### Step 5: Deploy Security Patches
```bash
# 5.1 Test all fixes
php artisan test --group=security

# 5.2 Run static analysis
./vendor/bin/phpstan analyse app/ --level=8
./vendor/bin/psalm --show-info=true

# 5.3 Deploy
git add -A
git commit -m "SECURITY: Fix SQL injection vulnerabilities [INC-XXX]"
git push origin hotfix/sqli-fix
# Merge via PR dengan emergency approval
```

---

### 🔄 RECOVERY STEPS

#### Step 1: Restore Database Permissions
```sql
-- Restore normal permissions
ALTER DATABASE absensi SET default_transaction_read_only = off;
GRANT INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO absensi_app;
```

#### Step 2: Restore Data if Needed
```bash
# Jika data termodifikasi, restore dari backup
# HATI-HATI: Ini akan overwrite data sejak backup

# Option A: Full restore (extreme)
pg_restore -d absensi /backup/daily/absensi_$(date +%Y%m%d).dump

# Option B: Table-specific restore
pg_restore -d absensi -t users /backup/daily/absensi_$(date +%Y%m%d).dump

# Option C: Row-level restore (safest)
# Insert missing rows from backup
```

#### Step 3: Re-enable Application
```bash
php artisan up
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

#### Step 4: Security Scan
```bash
# Run security scan setelah fix
./vendor/bin/security-checker security:check

# OWASP ZAP scan (jika available)
zap-cli quick-scan --self-contained --spider https://api.absensi.app
```

---

### 📝 POST-MORTEM CHECKLIST

- [ ] Document exact injection point dan payload
- [ ] Assess: Data apa yang dibaca attacker?
- [ ] Assess: Data apa yang dimodifikasi?
- [ ] Audit: Review semua raw queries di codebase
- [ ] Fix: Parameterize semua queries
- [ ] Add: Input validation layer
- [ ] Add: WAF rules untuk SQL injection
- [ ] Implement: Prepared statement enforcement
- [ ] Test: SQL injection test suite
- [ ] Training: Secure coding training untuk developers
- [ ] Consider: Database activity monitoring (DAM) tool

---

## Skenario 4: Database Corruption dari Migration Gagal

### 📋 Overview

**Severity:** P2 - HIGH (bisa P1 jika production down)  
**Deskripsi:** Migration script gagal di tengah jalan, menyebabkan database dalam state tidak konsisten.

**Contoh Skenario:**
- Foreign key constraint violation
- Timeout saat ALTER TABLE on large table
- Disk full saat migration
- Partial migration (beberapa queries sukses, beberapa gagal)
- Migration di-rollback tapi data sudah berubah

---

### 🔍 DETECTION METHOD

#### A. Automated Detection

**1. Migration Status Check**
```bash
# Check migration status
php artisan migrate:status

# Output yang menunjukkan masalah:
# | Ran? | Migration | Batch |
# | Yes  | 2026_02_05_create_xxx | 45 |
# | No   | 2026_02_06_alter_xxx |    |  <- Stuck here
# | No   | 2026_02_07_create_yyy |    |
```

**2. Database Consistency Check**
```sql
-- PostgreSQL: Check for invalid foreign keys
SELECT 
    tc.table_name, 
    kcu.column_name, 
    ccu.table_name AS foreign_table_name,
    ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc 
JOIN information_schema.key_column_usage AS kcu
    ON tc.constraint_name = kcu.constraint_name
JOIN information_schema.constraint_column_usage AS ccu
    ON ccu.constraint_name = tc.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY'
  AND NOT EXISTS (
    SELECT 1 FROM information_schema.tables 
    WHERE table_name = ccu.table_name
  );

-- Check for orphaned records
SELECT 'attendances with invalid school_id' as issue, COUNT(*) as count
FROM attendances a
LEFT JOIN schools s ON a.school_id = s.id
WHERE s.id IS NULL
UNION ALL
SELECT 'attendances with invalid student_id', COUNT(*)
FROM attendances a
LEFT JOIN users u ON a.student_id = u.id
WHERE u.id IS NULL;
```

**3. Application Error Detection**
```bash
# Monitor untuk database structure errors
grep -E "column.*does.not.exist|relation.*does.not.exist|constraint.*violated" \
    storage/logs/laravel-$(date +%Y-%m-%d).log
```

#### B. Symptoms Checklist

| Symptom | Possible Cause | Severity |
|---------|---------------|----------|
| "Column X does not exist" error | Incomplete ADD COLUMN | P2 |
| Foreign key constraint errors | FK added before data cleanup | P2 |
| Duplicate key errors on INSERT | Unique constraint added wrongly | P2 |
| Application timeout on specific query | Missing index after migration | P3 |
| Data truncation warnings | Column type change failed | P2 |

---

### 🛡️ CONTAINMENT STEPS

**Timeline Target: < 30 menit**

#### Step 1: Stop Further Migrations (2 menit)
```bash
# 1.1 Prevent auto-migrations di deployment
touch storage/framework/down_migrations

# 1.2 Jika CI/CD running, cancel
# Cancel di GitHub Actions/GitLab CI/etc

# 1.3 Put app in maintenance (optional, tergantung severity)
php artisan down --retry=60
```

#### Step 2: Assess Current State (10 menit)
```bash
# 2.1 Check which migration failed
php artisan migrate:status

# 2.2 Check database state
php artisan db:show

# 2.3 Check specific table structure
php artisan db:table attendances
```

```sql
-- 2.4 Check for partial migration artifacts
-- Contoh: Jika migration menambah column, check apakah ada
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_name = 'attendances'
ORDER BY ordinal_position;
```

#### Step 3: Document Current State (5 menit)
```bash
# 3.1 Dump current schema
pg_dump -s absensi > /backup/incident/schema_corrupted_$(date +%Y%m%d_%H%M%S).sql

# 3.2 Document migration batch
psql -d absensi -c "SELECT * FROM migrations ORDER BY id DESC LIMIT 10;" > /backup/incident/migration_state.txt

# 3.3 Take table stats
psql -d absensi -c "
    SELECT 
        relname as table, 
        n_live_tup as rows 
    FROM pg_stat_user_tables 
    ORDER BY n_live_tup DESC;
" > /backup/incident/table_stats.txt
```

#### Step 4: Notify Team (5 menit)
```
TEMPLATE NOTIFIKASI:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[DATABASE INCIDENT - Migration Failure]

Time Detected: {TIMESTAMP}
Environment: {PRODUCTION/STAGING}
Status: INVESTIGATING

Failed Migration: {MIGRATION_NAME}
Error: {ERROR_MESSAGE}

Current Impact:
- Application: {PARTIALLY_WORKING/DOWN}
- Affected Features: {LIST}

DO NOT:
- Run any migrations
- Deploy any code changes
- Manually modify database

Team assigned: {NAMES}
Next update: {TIMESTAMP + 30min}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

### 🔧 ERADICATION STEPS

#### Step 1: Analyze Failed Migration
```bash
# 1.1 Read the migration file
cat database/migrations/{FAILED_MIGRATION}.php

# 1.2 Check if it's idempotent
grep -E "Schema::(hasColumn|hasTable|dropIfExists)" database/migrations/{FAILED_MIGRATION}.php

# 1.3 Check transaction usage
grep -E "DB::transaction|->transaction" database/migrations/{FAILED_MIGRATION}.php
```

#### Step 2: Choose Recovery Strategy

**Strategy A: Complete the Migration Manually**
```sql
-- Jika migration hampir selesai, complete secara manual
-- Contoh: Migration menambah column, column sudah ada tapi entry di migrations table tidak

-- 1. Verify column exists
SELECT column_name FROM information_schema.columns 
WHERE table_name = 'attendances' AND column_name = 'new_column';

-- 2. If exists, mark migration as complete
INSERT INTO migrations (migration, batch) 
VALUES ('{MIGRATION_NAME}', (SELECT MAX(batch) + 1 FROM migrations));
```

**Strategy B: Rollback and Fix**
```bash
# Jika migration partial dan harus di-rollback

# 2.1 Create rollback script
cat > /tmp/rollback_fix.sql << 'EOF'
-- Reverse the partial migration
BEGIN;

-- Remove partially added column
ALTER TABLE attendances DROP COLUMN IF EXISTS new_column;

-- Remove from migrations table
DELETE FROM migrations WHERE migration = '{MIGRATION_NAME}';

COMMIT;
EOF

# 2.2 Execute rollback
psql -d absensi < /tmp/rollback_fix.sql
```

**Strategy C: Restore from Backup**
```bash
# Jika corruption parah, restore dari backup terakhir

# 3.1 Stop application
php artisan down

# 3.2 Create backup of current (corrupted) state for analysis
pg_dump absensi > /backup/incident/corrupted_$(date +%Y%m%d_%H%M%S).sql

# 3.3 Restore from last good backup
dropdb absensi
createdb absensi
pg_restore -d absensi /backup/daily/absensi_YYYYMMDD.dump

# 3.4 Re-run migrations up to last good state
php artisan migrate

# 3.5 Assess data loss
# Compare row counts, identify transactions to replay
```

#### Step 3: Fix the Migration File
```php
// Make migration idempotent dan safe

// BEFORE (Risky)
public function up()
{
    Schema::table('attendances', function (Blueprint $table) {
        $table->string('new_column');
    });
}

// AFTER (Safe)
public function up()
{
    // Check before adding
    if (!Schema::hasColumn('attendances', 'new_column')) {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('new_column')->nullable();
        });
    }
    
    // For large tables, consider batching
    // Or use raw SQL with timeout settings
}

public function down()
{
    // Always make down() work
    Schema::table('attendances', function (Blueprint $table) {
        $table->dropColumn('new_column');
    });
}
```

#### Step 4: Test Fixed Migration
```bash
# 4.1 Test di local/staging dulu
php artisan migrate:fresh --seed
php artisan migrate:rollback --step=1
php artisan migrate

# 4.2 Check for integrity issues
php artisan db:check-integrity  # Custom command

# 4.3 Run application tests
php artisan test
```

---

### 🔄 RECOVERY STEPS

#### Step 1: Complete Migrations
```bash
# 1.1 Run fixed migration
php artisan migrate --force

# 1.2 Verify all migrations complete
php artisan migrate:status
# Ensure all show "Yes" in Ran? column
```

#### Step 2: Data Integrity Verification
```php
// Create dan run integrity check command
// php artisan db:verify-integrity

$issues = [];

// Check foreign keys
$orphanedAttendances = Attendance::whereNotIn('student_id', 
    User::where('role_type', 'student')->pluck('id')
)->count();

if ($orphanedAttendances > 0) {
    $issues[] = "Found {$orphanedAttendances} orphaned attendance records";
}

// Check required fields
$nullSchoolIds = Attendance::whereNull('school_id')->count();
if ($nullSchoolIds > 0) {
    $issues[] = "Found {$nullSchoolIds} attendances without school_id";
}

// Report
if (empty($issues)) {
    $this->info('✓ Database integrity verified');
} else {
    foreach ($issues as $issue) {
        $this->error("✗ {$issue}");
    }
}
```

#### Step 3: Restore Service
```bash
# 3.1 Clear caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3.2 Restart queue workers
php artisan queue:restart

# 3.3 Bring app back up
php artisan up

# 3.4 Monitor error rate
# Watch for elevated error rates in first 15 minutes
```

#### Step 4: Data Recovery (if needed)
```php
// Jika ada data loss, replay dari audit logs atau backup
// Contoh: Restore attendance records dari backup yang hilang

$backupRecords = DB::connection('backup')
    ->table('attendances')
    ->where('created_at', '>', $incidentStart)
    ->where('created_at', '<', $incidentEnd)
    ->get();

foreach ($backupRecords as $record) {
    // Check if exists in current DB
    $exists = Attendance::where('student_id', $record->student_id)
        ->where('schedule_id', $record->schedule_id)
        ->where('attendance_date', $record->attendance_date)
        ->exists();
    
    if (!$exists) {
        // Restore with audit note
        Attendance::create([
            ...collect($record)->except(['id'])->toArray(),
            'notes' => ($record->notes ?? '') . ' [Restored from backup - INC-XXX]',
        ]);
    }
}
```

---

### 📝 POST-MORTEM CHECKLIST

- [ ] Document: Timeline lengkap (trigger → detection → resolution)
- [ ] Root cause: Apa yang menyebabkan migration gagal?
- [ ] Impact: Downtime duration, data affected
- [ ] Improve: Migration testing di staging environment
- [ ] Improve: Add pre-flight checks sebelum migration
- [ ] Improve: Better rollback procedures
- [ ] Add: Database backup verification job
- [ ] Add: Migration dry-run capability
- [ ] Process: Require staging migration sign-off
- [ ] Monitor: Add migration health dashboard

---

## Template Laporan Insiden

```markdown
# Incident Report: [INCIDENT-ID]

## Summary
- **Incident Type:** [Tenant Leak / Replay Attack / SQL Injection / DB Corruption]
- **Severity:** [P1/P2/P3/P4]
- **Status:** [Investigating / Contained / Resolved / Closed]
- **Duration:** [Start Time] to [End Time] ([X hours Y minutes])

## Timeline
| Time (WIB) | Event |
|------------|-------|
| HH:MM | Initial detection via [method] |
| HH:MM | Incident declared, IRT activated |
| HH:MM | Containment measures implemented |
| HH:MM | Root cause identified |
| HH:MM | Fix deployed |
| HH:MM | Services restored |
| HH:MM | Incident closed |

## Impact
- **Users Affected:** [Number]
- **Schools Affected:** [Number]
- **Data Exposed/Modified:** [Description]
- **Downtime:** [Duration]
- **Financial Impact:** [If applicable]

## Root Cause
[Detailed technical explanation of what went wrong]

## Resolution
[Steps taken to fix the issue]

## Action Items
| ID | Action | Owner | Due Date | Status |
|----|--------|-------|----------|--------|
| 1 | [Action] | [Name] | [Date] | [Status] |

## Lessons Learned
- What went well:
- What could be improved:
- What will we do differently:

## Appendices
- A: Relevant log excerpts
- B: Code diffs
- C: Communication records
```

---

## Kontak Darurat

### Internal Team

| Role | Name | Phone | Email | Availability |
|------|------|-------|-------|--------------|
| On-Call Primary | [Name] | +62-xxx | xxx@company.com | 24/7 |
| On-Call Secondary | [Name] | +62-xxx | xxx@company.com | 24/7 |
| CTO | [Name] | +62-xxx | xxx@company.com | Business hours |
| CEO | [Name] | +62-xxx | xxx@company.com | P1 only |

### External Partners

| Service | Contact | SLA |
|---------|---------|-----|
| Cloud Provider (AWS/GCP) | [Support URL] | 15 min response (Business) |
| Database (Managed) | [Support URL] | 1 hour response |
| CDN/WAF | [Support URL] | 30 min response |

### Communication Channels

- **Slack:** #incident-response (pin: IRT Playbook)
- **PagerDuty:** [Policy URL]
- **War Room (Google Meet):** [Permanent Link]
- **Status Page:** [URL]

---

## Document Control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-02-07 | Security Team | Initial version |

**Review Schedule:** Quarterly (next: May 2026)  
**Approval:** CTO, Security Lead

---

*"Hope for the best, prepare for the worst."*
