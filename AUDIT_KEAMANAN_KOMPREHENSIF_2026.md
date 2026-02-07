# AUDIT KEAMANAN KOMPREHENSIF 2026
## AbsensiQR Pro - Security Vulnerability Assessment & Remediation Plan

**Tanggal Audit**: 6 Februari 2026  
**Auditor**: Security Analysis Team  
**Versi Sistem**: Laravel 12 + React 19 + React Native 0.73  
**Status Project**: 70% Production Ready

---

## EXECUTIVE SUMMARY

### Ringkasan Temuan
- **CRITICAL**: 6 vulnerabilities (Immediate action required)
- **HIGH**: 9 vulnerabilities (Fix within 1-2 weeks)
- **MEDIUM**: 15 vulnerabilities (Fix within 1 month)
- **LOW**: 7 vulnerabilities (Fix within 2-3 months)
- **TOTAL**: 37 security issues identified

### Risk Assessment
- **Overall Security Score**: 62/100 (MEDIUM RISK)
- **Data Protection**: 55/100 (HIGH RISK)
- **Authentication/Authorization**: 70/100 (MEDIUM RISK)
- **API Security**: 65/100 (MEDIUM RISK)
- **Infrastructure**: 75/100 (LOW RISK)

### Immediate Actions Required (Week 1)
1. Fix SQL Injection vulnerabilities in StudentRiskAnalysisService
2. Implement atomic check-in to prevent race conditions
3. Add school_id validation to all raw queries
4. Fix IDOR vulnerabilities in parent/teacher endpoints
5. Remove test routes from production

---

## DAFTAR VULNERABILITIES


---

## CRITICAL VULNERABILITIES (P0)

### [VULN-001] [CRITICAL] [Backend] SQL Injection via Raw Queries

**Module**: `backend/app/Services/StudentRiskAnalysisService.php`  
**Lines**: 45-78, 112-145, 189-223  
**CWE**: CWE-89 (SQL Injection)  
**CVSS Score**: 9.8 (Critical)

**Deskripsi**:
Multiple SQL injection vulnerabilities ditemukan dalam raw query yang menggunakan input user tanpa parameterisasi. Attacker dapat mengeksekusi arbitrary SQL commands.

**Vulnerable Code**:
```php
// Line 45-78
$riskStudents = DB::select("
    SELECT s.id, s.name, s.nis,
           COUNT(DISTINCT al.id) as total_absences,
           COUNT(DISTINCT CASE WHEN al.status = 'late' THEN al.id END) as late_count
    FROM students s
    LEFT JOIN attendance_logs al ON s.id = al.student_id
    WHERE s.school_id = {$schoolId}
      AND al.date >= '{$startDate}'
      AND al.date <= '{$endDate}'
    GROUP BY s.id
    HAVING total_absences > {$threshold}
");

// Line 112-145
$query = "SELECT * FROM students WHERE school_id = {$schoolId}";
if ($filters['class_id']) {
    $query .= " AND class_id = {$filters['class_id']}";
}
```

**Attack Scenario**:
```php
// Attacker input: $filters['class_id'] = "1 OR 1=1; DROP TABLE students--"
// Resulting query:
SELECT * FROM students WHERE school_id = 123 AND class_id = 1 OR 1=1; DROP TABLE students--
```

**Impact**:
- Data breach: Access to all student data across schools
- Data manipulation: Modify attendance records
- Data destruction: Drop tables
- Privilege escalation: Access admin data
- Multi-tenant isolation bypass

**Solution**:
```php
// BEFORE (Vulnerable)
$riskStudents = DB::select("
    SELECT s.id, s.name, s.nis,
           COUNT(DISTINCT al.id) as total_absences
    FROM students s
    LEFT JOIN attendance_logs al ON s.id = al.student_id
    WHERE s.school_id = {$schoolId}
      AND al.date >= '{$startDate}'
      AND al.date <= '{$endDate}'
    GROUP BY s.id
    HAVING total_absences > {$threshold}
");

// AFTER (Secure)
$riskStudents = DB::table('students as s')
    ->leftJoin('attendance_logs as al', 's.id', '=', 'al.student_id')
    ->where('s.school_id', $schoolId)
    ->whereBetween('al.date', [$startDate, $endDate])
    ->select([
        's.id',
        's.name',
        's.nis',
        DB::raw('COUNT(DISTINCT al.id) as total_absences'),
        DB::raw("COUNT(DISTINCT CASE WHEN al.status = 'late' THEN al.id END) as late_count")
    ])
    ->groupBy('s.id', 's.name', 's.nis')
    ->havingRaw('COUNT(DISTINCT al.id) > ?', [$threshold])
    ->get();
```

**Files to Fix**:
1. `backend/app/Services/StudentRiskAnalysisService.php` (3 methods)
2. `backend/app/Services/ReportGenerationService.php` (2 methods)
3. `backend/app/Http/Controllers/Api/V1/Admin/AnalyticsController.php` (1 method)

**Estimasi**: 8 hours  
**Priority**: P0 - Fix immediately  
**Assigned To**: Backend Security Team

---

### [VULN-002] [CRITICAL] [Backend] Race Condition in Attendance Check-In

**Module**: `backend/app/Services/AttendanceCheckInService.php`  
**Lines**: 67-89  
**CWE**: CWE-362 (Race Condition)  
**CVSS Score**: 8.1 (High)

**Deskripsi**:
Non-atomic duplicate check memungkinkan multiple check-ins dalam waktu bersamaan. Attacker dapat melakukan concurrent requests untuk bypass duplicate prevention.

**Vulnerable Code**:
```php
// Line 67-89
public function checkIn(array $data): AttendanceLog
{
    // Step 1: Check if already checked in (NOT ATOMIC)
    $existing = AttendanceLog::where('student_id', $data['student_id'])
        ->where('session_id', $data['session_id'])
        ->where('date', now()->toDateString())
        ->first();
    
    if ($existing) {
        throw new \Exception('Already checked in');
    }
    
    // Step 2: Create attendance log (RACE WINDOW HERE)
    $log = AttendanceLog::create([
        'student_id' => $data['student_id'],
        'session_id' => $data['session_id'],
        'date' => now()->toDateString(),
        'status' => 'present',
    ]);
    
    return $log;
}
```

**Attack Scenario**:
```bash
# Attacker sends 10 concurrent requests
for i in {1..10}; do
  curl -X POST http://api.example.com/attendance/check-in \
    -H "Authorization: Bearer $TOKEN" \
    -d '{"student_id": 123, "session_id": 456}' &
done

# Result: Multiple attendance logs created for same student/session
```

**Impact**:
- Duplicate attendance records
- Data integrity violation
- Report inaccuracy
- Audit trail corruption
- Potential for attendance fraud

**Solution**:
```php
// BEFORE (Vulnerable)
public function checkIn(array $data): AttendanceLog
{
    $existing = AttendanceLog::where('student_id', $data['student_id'])
        ->where('session_id', $data['session_id'])
        ->where('date', now()->toDateString())
        ->first();
    
    if ($existing) {
        throw new \Exception('Already checked in');
    }
    
    $log = AttendanceLog::create($data);
    return $log;
}

// AFTER (Secure - Using Database Transaction with Lock)
public function checkIn(array $data): AttendanceLog
{
    return DB::transaction(function () use ($data) {
        // Atomic check with row-level lock
        $existing = AttendanceLog::where('student_id', $data['student_id'])
            ->where('session_id', $data['session_id'])
            ->where('date', now()->toDateString())
            ->lockForUpdate()
            ->first();
        
        if ($existing) {
            throw new DuplicateCheckInException('Already checked in for this session');
        }
        
        // Create with unique constraint enforcement
        try {
            $log = AttendanceLog::create([
                'student_id' => $data['student_id'],
                'session_id' => $data['session_id'],
                'date' => now()->toDateString(),
                'status' => $this->determineStatus($data),
                'check_in_time' => now(),
                'location' => $data['location'] ?? null,
                'device_id' => $data['device_id'] ?? null,
            ]);
            
            return $log;
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle unique constraint violation
            if ($e->getCode() === '23000') {
                throw new DuplicateCheckInException('Concurrent check-in detected');
            }
            throw $e;
        }
    });
}
```

**Database Migration Required**:
```php
// Add unique constraint
Schema::table('attendance_logs', function (Blueprint $table) {
    $table->unique(['student_id', 'session_id', 'date'], 'unique_attendance_per_session');
});
```

**Files to Fix**:
1. `backend/app/Services/AttendanceCheckInService.php`
2. `backend/database/migrations/2026_02_06_add_unique_constraint_attendance.php` (new)
3. `backend/app/Exceptions/DuplicateCheckInException.php` (new)

**Estimasi**: 6 hours  
**Priority**: P0 - Fix immediately  
**Assigned To**: Backend Core Team

---

### [VULN-003] [CRITICAL] [Backend] Multi-Tenant Isolation Bypass

**Module**: Multiple services with raw queries  
**Files**: 8 files affected  
**CWE**: CWE-639 (Authorization Bypass)  
**CVSS Score**: 9.1 (Critical)

**Deskripsi**:
Raw SQL queries tidak memvalidasi `school_id`, memungkinkan cross-tenant data access. Attacker dari school A dapat mengakses data school B.

**Vulnerable Code**:
```php
// backend/app/Services/ReportGenerationService.php - Line 234
$stats = DB::select("
    SELECT 
        DATE(al.check_in_time) as date,
        COUNT(*) as total_attendance
    FROM attendance_logs al
    WHERE al.session_id = ?
    GROUP BY DATE(al.check_in_time)
", [$sessionId]);
// MISSING: school_id validation!

// backend/app/Services/StudentCardService.php - Line 156
$cards = DB::select("
    SELECT * FROM student_cards 
    WHERE student_id IN (" . implode(',', $studentIds) . ")
");
// MISSING: school_id validation!
```

**Attack Scenario**:
```php
// School A admin (school_id = 1) requests data
// Attacker modifies session_id to belong to School B (school_id = 2)
POST /api/v1/reports/attendance
{
  "session_id": 999  // Belongs to School B
}

// Response contains School B's attendance data!
```

**Impact**:
- **CRITICAL**: Complete multi-tenant isolation breach
- Cross-school data exposure
- Privacy violation (GDPR/Indonesian data protection)
- Competitive intelligence leak
- Regulatory compliance failure

**Solution**:
```php
// BEFORE (Vulnerable)
$stats = DB::select("
    SELECT DATE(al.check_in_time) as date, COUNT(*) as total_attendance
    FROM attendance_logs al
    WHERE al.session_id = ?
    GROUP BY DATE(al.check_in_time)
", [$sessionId]);

// AFTER (Secure)
$stats = DB::select("
    SELECT DATE(al.check_in_time) as date, COUNT(*) as total_attendance
    FROM attendance_logs al
    INNER JOIN sessions s ON al.session_id = s.id
    WHERE al.session_id = ?
      AND s.school_id = ?
    GROUP BY DATE(al.check_in_time)
", [$sessionId, auth()->user()->school_id]);

// BETTER: Use Query Builder with Global Scope
$stats = AttendanceLog::query()
    ->join('sessions', 'attendance_logs.session_id', '=', 'sessions.id')
    ->where('attendance_logs.session_id', $sessionId)
    ->where('sessions.school_id', auth()->user()->school_id)
    ->selectRaw('DATE(attendance_logs.check_in_time) as date, COUNT(*) as total_attendance')
    ->groupBy(DB::raw('DATE(attendance_logs.check_in_time)'))
    ->get();
```

**Comprehensive Fix Strategy**:
1. Add `SchoolScoped` trait to all models
2. Implement global scope for automatic school_id filtering
3. Add middleware to validate school_id in all requests
4. Audit all raw queries and add school_id validation
5. Add integration tests for cross-tenant access attempts

**Files to Fix** (8 files):
1. `backend/app/Services/ReportGenerationService.php` (3 methods)
2. `backend/app/Services/StudentCardService.php` (2 methods)
3. `backend/app/Services/StudentRiskAnalysisService.php` (3 methods)
4. `backend/app/Http/Controllers/Api/V1/Admin/AnalyticsController.php` (2 methods)
5. `backend/app/Http/Controllers/Api/V1/Teacher/ReportController.php` (1 method)
6. `backend/app/Traits/SchoolScoped.php` (new - create trait)
7. `backend/app/Http/Middleware/ValidateSchoolAccess.php` (new)
8. `backend/tests/Feature/MultiTenantIsolationTest.php` (new)

**Estimasi**: 16 hours  
**Priority**: P0 - Fix immediately  
**Assigned To**: Backend Security Team + QA Team

---

### [VULN-004] [CRITICAL] [Backend] QR Code Replay Attack

**Module**: `backend/app/Services/QRSignatureService.php`  
**Lines**: 89-112  
**CWE**: CWE-294 (Authentication Bypass)  
**CVSS Score**: 8.6 (High)

**Deskripsi**:
QR code validation menggunakan cache-based nonce yang dapat di-bypass. Attacker dapat mereplay QR code yang sudah digunakan dengan menunggu cache expiry atau menggunakan multiple devices.

**Vulnerable Code**:
```php
// Line 89-112
public function validateQRCode(string $qrData): bool
{
    $decoded = json_decode(base64_decode($qrData), true);
    
    // Check nonce in cache (CAN BE BYPASSED)
    $cacheKey = "qr_nonce:{$decoded['nonce']}";
    if (Cache::has($cacheKey)) {
        return false; // Already used
    }
    
    // Validate signature
    if (!$this->verifySignature($decoded)) {
        return false;
    }
    
    // Mark as used (RACE CONDITION HERE)
    Cache::put($cacheKey, true, 300); // 5 minutes
    
    return true;
}
```

**Attack Scenarios**:

**Scenario 1: Cache Bypass**
```bash
# Attacker captures QR code at 10:00 AM
# QR expires at 10:05 AM
# Cache expires at 10:05 AM
# Attacker waits until 10:05:01 AM
# Replays QR code - SUCCESS (cache cleared but QR still valid)
```

**Scenario 2: Distributed Attack**
```bash
# Attacker uses multiple devices/IPs
# Device 1: Scans QR at 10:00:00.000
# Device 2: Scans QR at 10:00:00.001 (before cache write)
# Both succeed due to race condition
```

**Scenario 3: Cache Flush Attack**
```bash
# Attacker triggers cache flush (if accessible)
# All nonces cleared
# Can replay any recent QR codes
```

**Impact**:
- Attendance fraud (students can share QR codes)
- Multiple check-ins with single QR
- Bypass location validation
- Audit trail manipulation
- System integrity compromise

**Solution**:
```php
// BEFORE (Vulnerable - Cache-based)
public function validateQRCode(string $qrData): bool
{
    $decoded = json_decode(base64_decode($qrData), true);
    
    $cacheKey = "qr_nonce:{$decoded['nonce']}";
    if (Cache::has($cacheKey)) {
        return false;
    }
    
    if (!$this->verifySignature($decoded)) {
        return false;
    }
    
    Cache::put($cacheKey, true, 300);
    return true;
}

// AFTER (Secure - Database-based with atomic operations)
public function validateQRCode(string $qrData): bool
{
    $decoded = json_decode(base64_decode($qrData), true);
    
    // Validate signature first
    if (!$this->verifySignature($decoded)) {
        throw new InvalidQRSignatureException('Invalid QR signature');
    }
    
    // Validate expiry
    if (Carbon::parse($decoded['expires_at'])->isPast()) {
        throw new ExpiredQRCodeException('QR code has expired');
    }
    
    // Atomic nonce check and mark as used
    return DB::transaction(function () use ($decoded) {
        // Try to insert nonce (will fail if already exists due to unique constraint)
        try {
            QrNonce::create([
                'nonce' => $decoded['nonce'],
                'qr_code_id' => $decoded['qr_code_id'],
                'used_at' => now(),
                'expires_at' => $decoded['expires_at'],
            ]);
            
            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation = already used
            if ($e->getCode() === '23000') {
                throw new QRCodeAlreadyUsedException('QR code has already been used');
            }
            throw $e;
        }
    });
}
```

**Database Migration Required**:
```php
// Create qr_nonces table
Schema::create('qr_nonces', function (Blueprint $table) {
    $table->id();
    $table->string('nonce', 64)->unique(); // Unique constraint prevents replay
    $table->foreignId('qr_code_id')->constrained()->onDelete('cascade');
    $table->timestamp('used_at');
    $table->timestamp('expires_at');
    $table->index(['expires_at']); // For cleanup job
    $table->timestamps();
});

// Cleanup job to remove expired nonces
// Run daily: DELETE FROM qr_nonces WHERE expires_at < NOW() - INTERVAL 24 HOUR
```

**Additional Security Measures**:
```php
// Add device fingerprinting
public function generateQRCode(Session $session, User $user): string
{
    $nonce = Str::random(32);
    $deviceFingerprint = $this->getDeviceFingerprint($user);
    
    $payload = [
        'session_id' => $session->id,
        'school_id' => $session->school_id,
        'nonce' => $nonce,
        'device_fingerprint' => $deviceFingerprint, // NEW
        'generated_at' => now()->toIso8601String(),
        'expires_at' => now()->addMinutes(5)->toIso8601String(),
    ];
    
    $payload['signature'] = $this->generateSignature($payload);
    
    return base64_encode(json_encode($payload));
}

// Validate device fingerprint on scan
public function validateQRCode(string $qrData, Request $request): bool
{
    $decoded = json_decode(base64_decode($qrData), true);
    
    // Validate device fingerprint matches
    $currentFingerprint = $this->getDeviceFingerprint($request->user());
    if ($decoded['device_fingerprint'] !== $currentFingerprint) {
        throw new DeviceMismatchException('QR code generated for different device');
    }
    
    // ... rest of validation
}
```

**Files to Fix**:
1. `backend/app/Services/QRSignatureService.php`
2. `backend/app/Models/QrNonce.php` (new)
3. `backend/database/migrations/2026_02_06_create_qr_nonces_table.php` (new)
4. `backend/app/Jobs/CleanupExpiredQrNonces.php` (new)
5. `backend/app/Exceptions/QRCodeAlreadyUsedException.php` (new)
6. `backend/app/Exceptions/InvalidQRSignatureException.php` (new)
7. `backend/app/Exceptions/ExpiredQRCodeException.php` (new)
8. `backend/tests/Feature/QRReplayAttackTest.php` (new)

**Estimasi**: 12 hours  
**Priority**: P0 - Fix immediately  
**Assigned To**: Backend Security Team

---


### [VULN-005] [CRITICAL] [Backend] Authentication Bypass via Super Admin Token

**Module**: `backend/app/Http/Middleware/EnsureTokenHasAbility.php`  
**Lines**: 23-34  
**CWE**: CWE-285 (Improper Authorization)  
**CVSS Score**: 9.3 (Critical)

**Deskripsi**:
Super admin token dengan ability '*' dapat bypass semua authorization checks. Jika token leaked, attacker memiliki akses unlimited ke seluruh sistem.

**Vulnerable Code**:
```php
// Line 23-34
public function handle(Request $request, Closure $next, string $ability): Response
{
    $token = $request->user()->currentAccessToken();
    
    // DANGEROUS: Wildcard ability bypasses all checks
    if ($token->can('*')) {
        return $next($request);
    }
    
    if (!$token->can($ability)) {
        abort(403, 'Insufficient permissions');
    }
    
    return $next($request);
}
```

**Attack Scenario**:
```bash
# Scenario 1: Token Leakage
# Super admin token leaked via:
# - Exposed in logs
# - Stolen from compromised device
# - Intercepted in transit (if HTTPS misconfigured)
# - Exposed in error messages

# Attacker uses leaked token
curl -X DELETE http://api.example.com/api/v1/schools/123 \
  -H "Authorization: Bearer leaked_super_admin_token"

# Result: Can delete any school, access any data, modify any record

# Scenario 2: Privilege Escalation
# Regular admin compromises super admin account
# Creates token with '*' ability
# Now has unlimited access across all schools
```

**Impact**:
- **CATASTROPHIC**: Complete system compromise
- Access to all schools' data
- Ability to delete/modify any data
- Bypass all authorization rules
- Regulatory compliance violation
- Potential for massive data breach

**Solution**:
```php
// BEFORE (Vulnerable)
public function handle(Request $request, Closure $next, string $ability): Response
{
    $token = $request->user()->currentAccessToken();
    
    if ($token->can('*')) {
        return $next($request); // DANGEROUS
    }
    
    if (!$token->can($ability)) {
        abort(403, 'Insufficient permissions');
    }
    
    return $next($request);
}

// AFTER (Secure - Remove wildcard, use explicit abilities)
public function handle(Request $request, Closure $next, string $ability): Response
{
    $token = $request->user()->currentAccessToken();
    
    // NO MORE WILDCARD - Must have explicit ability
    if (!$token->can($ability)) {
        // Log unauthorized access attempt
        Log::warning('Unauthorized access attempt', [
            'user_id' => $request->user()->id,
            'ability_required' => $ability,
            'token_abilities' => $token->abilities,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        
        abort(403, 'Insufficient permissions for this action');
    }
    
    return $next($request);
}

// Update token creation to use explicit abilities
public function createSuperAdminToken(User $user): string
{
    // BEFORE (Vulnerable)
    // return $user->createToken('super-admin', ['*'])->plainTextToken;
    
    // AFTER (Secure - Explicit abilities)
    $abilities = [
        'schools:read',
        'schools:write',
        'schools:delete',
        'users:read',
        'users:write',
        'users:delete',
        'reports:read',
        'reports:export',
        'settings:read',
        'settings:write',
        'audit:read',
        // ... all explicit abilities
    ];
    
    return $user->createToken('super-admin', $abilities)->plainTextToken;
}
```

**Additional Security Measures**:
```php
// 1. Token rotation policy
public function rotateToken(User $user): string
{
    // Revoke old tokens
    $user->tokens()->delete();
    
    // Create new token with expiry
    $token = $user->createToken('admin', $abilities, now()->addDays(30));
    
    return $token->plainTextToken;
}

// 2. Token usage monitoring
public function logTokenUsage(Request $request): void
{
    $token = $request->user()->currentAccessToken();
    
    TokenUsageLog::create([
        'token_id' => $token->id,
        'user_id' => $request->user()->id,
        'endpoint' => $request->path(),
        'method' => $request->method(),
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'used_at' => now(),
    ]);
    
    // Alert on suspicious usage
    $this->detectAnomalousUsage($token);
}

// 3. IP whitelisting for super admin
public function validateSuperAdminAccess(Request $request): void
{
    if ($request->user()->hasRole('super_admin')) {
        $allowedIps = config('security.super_admin_ips', []);
        
        if (!in_array($request->ip(), $allowedIps)) {
            Log::critical('Super admin access from unauthorized IP', [
                'user_id' => $request->user()->id,
                'ip' => $request->ip(),
            ]);
            
            abort(403, 'Access denied from this location');
        }
    }
}

// 4. MFA requirement for super admin
public function requireMFA(Request $request): void
{
    if ($request->user()->hasRole('super_admin')) {
        if (!$request->session()->has('mfa_verified')) {
            abort(403, 'MFA verification required');
        }
    }
}
```

**Configuration Changes**:
```php
// config/security.php (new file)
return [
    'super_admin_ips' => env('SUPER_ADMIN_IPS', '127.0.0.1'),
    'token_expiry_days' => env('TOKEN_EXPIRY_DAYS', 30),
    'require_mfa_for_super_admin' => env('REQUIRE_MFA_SUPER_ADMIN', true),
    'max_token_per_user' => env('MAX_TOKEN_PER_USER', 5),
];
```

**Files to Fix**:
1. `backend/app/Http/Middleware/EnsureTokenHasAbility.php`
2. `backend/app/Services/TokenManagementService.php` (new)
3. `backend/app/Models/TokenUsageLog.php` (new)
4. `backend/config/security.php` (new)
5. `backend/database/migrations/2026_02_06_create_token_usage_logs_table.php` (new)
6. `backend/app/Http/Middleware/RequireMFA.php` (new)
7. `backend/app/Http/Middleware/ValidateSuperAdminIP.php` (new)
8. `backend/tests/Feature/SuperAdminSecurityTest.php` (new)

**Estimasi**: 10 hours  
**Priority**: P0 - Fix immediately  
**Assigned To**: Backend Security Team + DevOps

---

### [VULN-006] [CRITICAL] [Backend] IDOR in Parent/Teacher Endpoints

**Module**: Multiple API controllers  
**Files**: 4 controllers affected  
**CWE**: CWE-639 (Insecure Direct Object Reference)  
**CVSS Score**: 8.2 (High)

**Deskripsi**:
API endpoints tidak memvalidasi ownership sebelum mengakses resources. Attacker dapat mengakses data user lain dengan mengubah ID parameter.

**Vulnerable Endpoints**:

**1. Parent Dashboard - Student Data Access**
```php
// backend/app/Http/Controllers/Api/V1/Parent/ParentDashboardController.php
// Line 45-67
public function getStudentAttendance(Request $request, int $studentId)
{
    // NO OWNERSHIP CHECK!
    $attendance = AttendanceLog::where('student_id', $studentId)
        ->whereBetween('date', [$request->start_date, $request->end_date])
        ->get();
    
    return response()->json($attendance);
}

// Attack: Parent A can access Parent B's student data
// GET /api/v1/parent/students/999/attendance?start_date=2026-01-01&end_date=2026-02-01
```

**2. Teacher Dashboard - Class Data Access**
```php
// backend/app/Http/Controllers/Api/V1/Teacher/TeacherDashboardController.php
// Line 123-145
public function getClassStudents(int $classId)
{
    // NO AUTHORIZATION CHECK!
    $students = Student::where('class_id', $classId)->get();
    
    return response()->json($students);
}

// Attack: Teacher A can access Teacher B's class data
// GET /api/v1/teacher/classes/888/students
```

**3. Student Profile Update**
```php
// backend/app/Http/Controllers/Api/V1/Student/ProfileController.php
// Line 78-95
public function updateProfile(Request $request, int $studentId)
{
    // NO OWNERSHIP CHECK!
    $student = Student::findOrFail($studentId);
    $student->update($request->validated());
    
    return response()->json($student);
}

// Attack: Student A can modify Student B's profile
// PUT /api/v1/students/777/profile
```

**4. Notification Access**
```php
// backend/app/Http/Controllers/Api/V1/NotificationController.php
// Line 34-48
public function show(int $notificationId)
{
    // NO OWNERSHIP CHECK!
    $notification = Notification::findOrFail($notificationId);
    
    return response()->json($notification);
}

// Attack: User A can read User B's notifications
// GET /api/v1/notifications/666
```

**Attack Scenarios**:

**Scenario 1: Parent Data Breach**
```bash
# Parent A (legitimate user) discovers endpoint structure
GET /api/v1/parent/students/123/attendance

# Parent A iterates through student IDs
for id in {1..1000}; do
  curl -H "Authorization: Bearer $TOKEN" \
    "http://api.example.com/api/v1/parent/students/$id/attendance"
done

# Result: Access to all students' attendance data
```

**Scenario 2: Teacher Grade Manipulation**
```bash
# Teacher A discovers they can access other classes
GET /api/v1/teacher/classes/456/students

# Teacher A modifies grades for students not in their class
PUT /api/v1/teacher/students/789/grades
{
  "subject_id": 10,
  "grade": 100
}
```

**Impact**:
- Privacy violation (access to personal data)
- Data manipulation (modify other users' data)
- Regulatory compliance failure (GDPR, Indonesian data protection)
- Reputation damage
- Legal liability

**Solution**:

**Approach 1: Policy-Based Authorization**
```php
// BEFORE (Vulnerable)
public function getStudentAttendance(Request $request, int $studentId)
{
    $attendance = AttendanceLog::where('student_id', $studentId)
        ->whereBetween('date', [$request->start_date, $request->end_date])
        ->get();
    
    return response()->json($attendance);
}

// AFTER (Secure - Using Policy)
public function getStudentAttendance(Request $request, int $studentId)
{
    $student = Student::findOrFail($studentId);
    
    // Check if parent owns this student
    $this->authorize('view', $student);
    
    $attendance = AttendanceLog::where('student_id', $studentId)
        ->whereBetween('date', [$request->start_date, $request->end_date])
        ->get();
    
    return response()->json($attendance);
}

// Create Policy
// backend/app/Policies/StudentPolicy.php
class StudentPolicy
{
    public function view(User $user, Student $student): bool
    {
        // Parent can only view their own children
        if ($user->hasRole('parent')) {
            return $user->children()->where('id', $student->id)->exists();
        }
        
        // Teacher can view students in their classes
        if ($user->hasRole('teacher')) {
            return $user->classes()->whereHas('students', function ($q) use ($student) {
                $q->where('id', $student->id);
            })->exists();
        }
        
        // Admin can view all students in their school
        if ($user->hasRole('admin')) {
            return $student->school_id === $user->school_id;
        }
        
        return false;
    }
    
    public function update(User $user, Student $student): bool
    {
        // Only admin and the student themselves can update
        if ($user->hasRole('admin')) {
            return $student->school_id === $user->school_id;
        }
        
        if ($user->hasRole('student')) {
            return $user->id === $student->user_id;
        }
        
        return false;
    }
}
```

**Approach 2: Route Model Binding with Scoping**
```php
// Define route with scoped binding
Route::get('/parent/students/{student}/attendance', [ParentDashboardController::class, 'getStudentAttendance'])
    ->middleware(['auth:sanctum', 'role:parent'])
    ->scopeBindings(); // Enable scope binding

// Controller uses implicit binding
public function getStudentAttendance(Request $request, Student $student)
{
    // $student is automatically scoped to current parent
    // Laravel will return 404 if student doesn't belong to parent
    
    $attendance = $student->attendanceLogs()
        ->whereBetween('date', [$request->start_date, $request->end_date])
        ->get();
    
    return response()->json($attendance);
}

// Define relationship in User model
public function children(): HasMany
{
    return $this->hasMany(Student::class, 'parent_id');
}

// Override route binding in RouteServiceProvider
public function boot(): void
{
    Route::bind('student', function ($value) {
        $student = Student::findOrFail($value);
        
        // Scope to current user based on role
        if (auth()->user()->hasRole('parent')) {
            if (!auth()->user()->children()->where('id', $student->id)->exists()) {
                abort(404);
            }
        }
        
        return $student;
    });
}
```

**Approach 3: Middleware-Based Validation**
```php
// Create middleware
// backend/app/Http/Middleware/ValidateResourceOwnership.php
class ValidateResourceOwnership
{
    public function handle(Request $request, Closure $next, string $resourceType): Response
    {
        $resourceId = $request->route($resourceType);
        
        if (!$this->userOwnsResource($request->user(), $resourceType, $resourceId)) {
            abort(403, 'You do not have permission to access this resource');
        }
        
        return $next($request);
    }
    
    private function userOwnsResource(User $user, string $resourceType, int $resourceId): bool
    {
        return match($resourceType) {
            'student' => $this->validateStudentOwnership($user, $resourceId),
            'class' => $this->validateClassOwnership($user, $resourceId),
            'notification' => $this->validateNotificationOwnership($user, $resourceId),
            default => false,
        };
    }
    
    private function validateStudentOwnership(User $user, int $studentId): bool
    {
        if ($user->hasRole('parent')) {
            return $user->children()->where('id', $studentId)->exists();
        }
        
        if ($user->hasRole('teacher')) {
            return $user->classes()->whereHas('students', function ($q) use ($studentId) {
                $q->where('id', $studentId);
            })->exists();
        }
        
        return false;
    }
}

// Apply to routes
Route::middleware(['auth:sanctum', 'validate.ownership:student'])
    ->get('/parent/students/{student}/attendance', [ParentDashboardController::class, 'getStudentAttendance']);
```

**Files to Fix** (12 files):
1. `backend/app/Http/Controllers/Api/V1/Parent/ParentDashboardController.php` (3 methods)
2. `backend/app/Http/Controllers/Api/V1/Teacher/TeacherDashboardController.php` (4 methods)
3. `backend/app/Http/Controllers/Api/V1/Student/ProfileController.php` (2 methods)
4. `backend/app/Http/Controllers/Api/V1/NotificationController.php` (2 methods)
5. `backend/app/Policies/StudentPolicy.php` (new)
6. `backend/app/Policies/ClassPolicy.php` (new)
7. `backend/app/Policies/NotificationPolicy.php` (new)
8. `backend/app/Http/Middleware/ValidateResourceOwnership.php` (new)
9. `backend/app/Providers/AuthServiceProvider.php` (register policies)
10. `backend/routes/api.php` (add middleware)
11. `backend/tests/Feature/IDORPreventionTest.php` (new)
12. `backend/tests/Feature/ResourceOwnershipTest.php` (new)

**Estimasi**: 14 hours  
**Priority**: P0 - Fix immediately  
**Assigned To**: Backend Security Team + QA Team

---


## HIGH PRIORITY VULNERABILITIES (P1)

### [VULN-007] [HIGH] [Backend] Sensitive Data Exposure in Logs

**Module**: Multiple services and controllers  
**Files**: 15+ files affected  
**CWE**: CWE-532 (Information Exposure Through Log Files)  
**CVSS Score**: 7.5 (High)

**Deskripsi**:
Sensitive data (passwords, tokens, location, device info) di-log tanpa sanitization. Attacker dengan akses ke log files dapat mengekstrak data sensitif.

**Vulnerable Code Examples**:
```php
// 1. Password logging
// backend/app/Http/Controllers/Api/V1/AuthController.php - Line 45
Log::info('Login attempt', [
    'email' => $request->email,
    'password' => $request->password, // EXPOSED!
    'ip' => $request->ip(),
]);

// 2. Token logging
// backend/app/Services/TokenManagementService.php - Line 78
Log::info('Token created', [
    'user_id' => $user->id,
    'token' => $token->plainTextToken, // EXPOSED!
]);

// 3. Location data logging
// backend/app/Services/AttendanceCheckInService.php - Line 123
Log::info('Check-in processed', [
    'student_id' => $student->id,
    'latitude' => $request->latitude, // PII
    'longitude' => $request->longitude, // PII
    'device_id' => $request->device_id,
]);

// 4. Personal data in exception messages
// backend/app/Exceptions/Handler.php - Line 56
Log::error('Database error', [
    'exception' => $e->getMessage(),
    'query' => $e->getSql(), // May contain sensitive data
    'bindings' => $e->getBindings(), // May contain passwords, etc
]);
```

**Impact**:
- Password exposure
- Token theft
- Privacy violation (location tracking)
- Regulatory compliance failure
- Identity theft risk

**Solution**:
```php
// Create log sanitizer
// backend/app/Helpers/LogSanitizer.php
class LogSanitizer
{
    private static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'secret',
        'authorization',
        'credit_card',
        'cvv',
        'ssn',
    ];
    
    private static array $piiKeys = [
        'latitude',
        'longitude',
        'location',
        'address',
        'phone',
        'email', // Mask partially
    ];
    
    public static function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitize($value);
                continue;
            }
            
            // Remove sensitive data completely
            if (in_array(strtolower($key), self::$sensitiveKeys)) {
                $data[$key] = '[REDACTED]';
                continue;
            }
            
            // Mask PII data
            if (in_array(strtolower($key), self::$piiKeys)) {
                $data[$key] = self::maskPII($key, $value);
                continue;
            }
        }
        
        return $data;
    }
    
    private static function maskPII(string $key, mixed $value): string
    {
        return match(strtolower($key)) {
            'email' => self::maskEmail($value),
            'phone' => self::maskPhone($value),
            'latitude', 'longitude' => self::maskCoordinate($value),
            default => '[MASKED]',
        };
    }
    
    private static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email);
        $maskedLocal = substr($local, 0, 2) . str_repeat('*', strlen($local) - 2);
        return $maskedLocal . '@' . $domain;
    }
    
    private static function maskPhone(string $phone): string
    {
        return substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 6) . substr($phone, -2);
    }
    
    private static function maskCoordinate(float $coordinate): string
    {
        // Round to 2 decimal places (accuracy ~1km)
        return number_format($coordinate, 2, '.', '');
    }
}

// BEFORE (Vulnerable)
Log::info('Login attempt', [
    'email' => $request->email,
    'password' => $request->password,
    'ip' => $request->ip(),
]);

// AFTER (Secure)
Log::info('Login attempt', LogSanitizer::sanitize([
    'email' => $request->email,
    'password' => $request->password, // Will be [REDACTED]
    'ip' => $request->ip(),
]));

// Or use custom log channel with automatic sanitization
// config/logging.php
'channels' => [
    'sanitized' => [
        'driver' => 'daily',
        'path' => storage_path('logs/sanitized.log'),
        'level' => 'debug',
        'days' => 14,
        'tap' => [App\Logging\SanitizeFormatter::class],
    ],
],

// backend/app/Logging/SanitizeFormatter.php
class SanitizeFormatter
{
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new class extends LineFormatter {
                public function format(array $record): string
                {
                    if (isset($record['context'])) {
                        $record['context'] = LogSanitizer::sanitize($record['context']);
                    }
                    return parent::format($record);
                }
            });
        }
    }
}
```

**Files to Fix** (15+ files):
1. `backend/app/Http/Controllers/Api/V1/AuthController.php`
2. `backend/app/Services/TokenManagementService.php`
3. `backend/app/Services/AttendanceCheckInService.php`
4. `backend/app/Exceptions/Handler.php`
5. `backend/app/Helpers/LogSanitizer.php` (new)
6. `backend/app/Logging/SanitizeFormatter.php` (new)
7. `backend/config/logging.php`
8. All controllers with logging (audit required)

**Estimasi**: 10 hours  
**Priority**: P1 - Fix within 1 week  
**Assigned To**: Backend Team

---

### [VULN-008] [HIGH] [Backend] Weak Password Policy

**Module**: `backend/app/Http/Requests/Auth/RegisterRequest.php`  
**Lines**: 34-42  
**CWE**: CWE-521 (Weak Password Requirements)  
**CVSS Score**: 7.3 (High)

**Deskripsi**:
Password policy terlalu lemah (hanya min 8 karakter). Tidak ada requirement untuk complexity, memudahkan brute force attacks.

**Vulnerable Code**:
```php
// Line 34-42
public function rules(): array
{
    return [
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8|confirmed', // TOO WEAK!
        'name' => 'required|string|max:255',
    ];
}
```

**Attack Scenario**:
```bash
# Common weak passwords that pass validation:
- "12345678" (8 digits)
- "password" (8 chars)
- "qwertyui" (8 chars)
- "aaaaaaaa" (8 chars)

# Brute force attack
# With 8-char lowercase only: 26^8 = 208 billion combinations
# With GPU: ~1-2 days to crack
# With weak password: Instant crack using dictionary
```

**Impact**:
- Account takeover via brute force
- Dictionary attacks succeed
- Credential stuffing attacks
- Weak security posture

**Solution**:
```php
// BEFORE (Vulnerable)
public function rules(): array
{
    return [
        'password' => 'required|min:8|confirmed',
    ];
}

// AFTER (Secure)
public function rules(): array
{
    return [
        'password' => [
            'required',
            'confirmed',
            'min:12', // Increased minimum
            'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', // Complexity
            new NotCommonPassword(), // Custom rule
            new NotUserInfo(), // Don't allow name/email in password
        ],
    ];
}

// Custom validation rules
// backend/app/Rules/NotCommonPassword.php
class NotCommonPassword implements Rule
{
    private array $commonPasswords = [
        'password', 'password123', '12345678', 'qwerty', 'abc123',
        'password1', '123456789', '12345', '1234567', 'password!',
        // Load from file: storage/security/common-passwords.txt (10k most common)
    ];
    
    public function passes($attribute, $value): bool
    {
        // Check against common password list
        if (in_array(strtolower($value), $this->commonPasswords)) {
            return false;
        }
        
        // Check against leaked password database (Have I Been Pwned API)
        if ($this->isPasswordPwned($value)) {
            return false;
        }
        
        return true;
    }
    
    private function isPasswordPwned(string $password): bool
    {
        // Use k-anonymity model (only send first 5 chars of hash)
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);
        
        try {
            $response = Http::get("https://api.pwnedpasswords.com/range/{$prefix}");
            
            if ($response->successful()) {
                $hashes = explode("\n", $response->body());
                foreach ($hashes as $line) {
                    [$hashSuffix, $count] = explode(':', $line);
                    if (trim($hashSuffix) === $suffix) {
                        return true; // Password found in breach database
                    }
                }
            }
        } catch (\Exception $e) {
            // If API fails, don't block registration
            Log::warning('Password breach check failed', ['error' => $e->getMessage()]);
        }
        
        return false;
    }
    
    public function message(): string
    {
        return 'Password ini terlalu umum atau pernah bocor dalam data breach. Gunakan password yang lebih kuat.';
    }
}

// backend/app/Rules/NotUserInfo.php
class NotUserInfo implements Rule
{
    public function passes($attribute, $value): bool
    {
        $request = request();
        
        // Don't allow name in password
        if ($request->has('name')) {
            $name = strtolower($request->name);
            if (str_contains(strtolower($value), $name)) {
                return false;
            }
        }
        
        // Don't allow email local part in password
        if ($request->has('email')) {
            $email = strtolower($request->email);
            $localPart = explode('@', $email)[0];
            if (str_contains(strtolower($value), $localPart)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function message(): string
    {
        return 'Password tidak boleh mengandung nama atau email Anda.';
    }
}
```

**Password Strength Meter (Frontend)**:
```typescript
// frontend-web/src/utils/passwordStrength.ts
export interface PasswordStrength {
  score: number; // 0-4
  feedback: string[];
  strength: 'very-weak' | 'weak' | 'medium' | 'strong' | 'very-strong';
}

export function checkPasswordStrength(password: string): PasswordStrength {
  let score = 0;
  const feedback: string[] = [];
  
  // Length check
  if (password.length >= 12) score++;
  else feedback.push('Gunakan minimal 12 karakter');
  
  // Uppercase check
  if (/[A-Z]/.test(password)) score++;
  else feedback.push('Tambahkan huruf besar');
  
  // Lowercase check
  if (/[a-z]/.test(password)) score++;
  else feedback.push('Tambahkan huruf kecil');
  
  // Number check
  if (/\d/.test(password)) score++;
  else feedback.push('Tambahkan angka');
  
  // Special char check
  if (/[@$!%*?&]/.test(password)) score++;
  else feedback.push('Tambahkan karakter khusus (@$!%*?&)');
  
  // Bonus for length
  if (password.length >= 16) score++;
  
  const strength = score <= 1 ? 'very-weak' :
                   score === 2 ? 'weak' :
                   score === 3 ? 'medium' :
                   score === 4 ? 'strong' : 'very-strong';
  
  return { score, feedback, strength };
}
```

**Additional Security Measures**:
```php
// 1. Password expiry policy
// backend/app/Models/User.php
public function passwordNeedsReset(): bool
{
    if (!$this->password_changed_at) {
        return true; // Force reset on first login
    }
    
    $expiryDays = config('security.password_expiry_days', 90);
    return $this->password_changed_at->addDays($expiryDays)->isPast();
}

// 2. Password history (prevent reuse)
// backend/app/Models/PasswordHistory.php
class PasswordHistory extends Model
{
    protected $fillable = ['user_id', 'password_hash'];
    
    public static function canUsePassword(User $user, string $newPassword): bool
    {
        $historyCount = config('security.password_history_count', 5);
        
        $recentPasswords = self::where('user_id', $user->id)
            ->latest()
            ->take($historyCount)
            ->get();
        
        foreach ($recentPasswords as $history) {
            if (Hash::check($newPassword, $history->password_hash)) {
                return false; // Password was used recently
            }
        }
        
        return true;
    }
}

// 3. Account lockout after failed attempts
// backend/app/Services/AuthService.php
public function attemptLogin(array $credentials): bool
{
    $email = $credentials['email'];
    $lockoutKey = "login_attempts:{$email}";
    
    // Check if account is locked
    if (Cache::has("account_locked:{$email}")) {
        throw new AccountLockedException('Account terkunci. Coba lagi dalam 30 menit.');
    }
    
    // Attempt login
    if (Auth::attempt($credentials)) {
        Cache::forget($lockoutKey);
        return true;
    }
    
    // Increment failed attempts
    $attempts = Cache::increment($lockoutKey);
    Cache::put($lockoutKey, $attempts, now()->addMinutes(30));
    
    // Lock account after 5 failed attempts
    if ($attempts >= 5) {
        Cache::put("account_locked:{$email}", true, now()->addMinutes(30));
        
        // Send notification
        $user = User::where('email', $email)->first();
        if ($user) {
            $user->notify(new AccountLockedNotification());
        }
        
        throw new AccountLockedException('Terlalu banyak percobaan login. Account terkunci selama 30 menit.');
    }
    
    return false;
}
```

**Configuration**:
```php
// config/security.php
return [
    'password' => [
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special_chars' => true,
        'expiry_days' => 90,
        'history_count' => 5, // Remember last 5 passwords
        'check_pwned_api' => true,
    ],
    'login' => [
        'max_attempts' => 5,
        'lockout_duration' => 30, // minutes
    ],
];
```

**Files to Fix**:
1. `backend/app/Http/Requests/Auth/RegisterRequest.php`
2. `backend/app/Http/Requests/Auth/ChangePasswordRequest.php`
3. `backend/app/Rules/NotCommonPassword.php` (new)
4. `backend/app/Rules/NotUserInfo.php` (new)
5. `backend/app/Models/PasswordHistory.php` (new)
6. `backend/app/Services/AuthService.php`
7. `backend/config/security.php`
8. `backend/database/migrations/2026_02_06_create_password_histories_table.php` (new)
9. `frontend-web/src/utils/passwordStrength.ts` (new)
10. `frontend-web/src/components/auth/PasswordStrengthMeter.tsx` (new)

**Estimasi**: 8 hours  
**Priority**: P1 - Fix within 1 week  
**Assigned To**: Backend Team + Frontend Team

---

### [VULN-009] [HIGH] [Backend] Missing CSRF Protection for API

**Module**: API routes configuration  
**Files**: `backend/routes/api.php`, middleware configuration  
**CWE**: CWE-352 (Cross-Site Request Forgery)  
**CVSS Score**: 7.1 (High)

**Deskripsi**:
API endpoints tidak memiliki CSRF protection. Meskipun menggunakan token-based auth, beberapa endpoints yang menggunakan session cookies vulnerable terhadap CSRF attacks.

**Vulnerable Configuration**:
```php
// backend/routes/api.php
// All API routes have no CSRF protection
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::delete('/students/{id}', [StudentController::class, 'destroy']);
    Route::post('/settings/update', [SettingsController::class, 'update']);
});
```

**Attack Scenario**:
```html
<!-- Attacker's malicious website -->
<html>
<body>
<h1>Win a Prize!</h1>
<form id="csrf-form" action="https://absensi-qr.com/api/v1/students/123" method="POST">
    <input type="hidden" name="_method" value="DELETE">
</form>
<script>
    // Auto-submit when victim visits page
    document.getElementById('csrf-form').submit();
</script>
</body>
</html>

<!-- If victim is logged in and visits attacker's site:
     - Browser sends cookies automatically
     - Student 123 gets deleted
     - Victim doesn't know what happened
-->
```

**Impact**:
- Unauthorized actions performed
- Data deletion/modification
- Account takeover
- Privilege escalation

**Solution**:
```php
// Approach 1: Double Submit Cookie Pattern
// backend/app/Http/Middleware/VerifyApiCsrfToken.php
class VerifyApiCsrfToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }
        
        // Skip for pure token-based auth (no cookies)
        if (!$request->hasCookie('XSRF-TOKEN')) {
            return $next($request);
        }
        
        // Verify CSRF token
        $tokenFromCookie = $request->cookie('XSRF-TOKEN');
        $tokenFromHeader = $request->header('X-XSRF-TOKEN');
        
        if (!$tokenFromHeader || !hash_equals($tokenFromCookie, $tokenFromHeader)) {
            throw new TokenMismatchException('CSRF token mismatch');
        }
        
        return $next($request);
    }
}

// Approach 2: SameSite Cookie Attribute
// backend/config/session.php
'same_site' => 'strict', // or 'lax'

// Approach 3: Origin/Referer Validation
// backend/app/Http/Middleware/ValidateOrigin.php
class ValidateOrigin
{
    private array $allowedOrigins = [
        'https://absensi-qr.com',
        'https://app.absensi-qr.com',
        'http://localhost:5173', // Development
    ];
    
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }
        
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        
        // Check Origin header (preferred)
        if ($origin) {
            if (!in_array($origin, $this->allowedOrigins)) {
                Log::warning('Invalid origin detected', [
                    'origin' => $origin,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
                
                abort(403, 'Invalid origin');
            }
        }
        // Fallback to Referer header
        elseif ($referer) {
            $refererHost = parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST);
            
            if (!in_array($refererHost, $this->allowedOrigins)) {
                abort(403, 'Invalid referer');
            }
        }
        // No origin/referer = suspicious
        else {
            Log::warning('Missing origin and referer headers', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            
            abort(403, 'Missing origin headers');
        }
        
        return $next($request);
    }
}

// Approach 4: Custom Request Signing
// backend/app/Http/Middleware/VerifyRequestSignature.php
class VerifyRequestSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }
        
        $signature = $request->header('X-Request-Signature');
        $timestamp = $request->header('X-Request-Timestamp');
        
        if (!$signature || !$timestamp) {
            abort(403, 'Missing request signature');
        }
        
        // Check timestamp (prevent replay attacks)
        if (abs(time() - $timestamp) > 300) { // 5 minutes
            abort(403, 'Request expired');
        }
        
        // Verify signature
        $payload = $request->method() . $request->path() . $timestamp . json_encode($request->all());
        $expectedSignature = hash_hmac('sha256', $payload, config('app.key'));
        
        if (!hash_equals($expectedSignature, $signature)) {
            abort(403, 'Invalid request signature');
        }
        
        return $next($request);
    }
}
```

**Frontend Implementation**:
```typescript
// frontend-web/src/lib/api.ts
import axios from 'axios';

// Add CSRF token to requests
axios.interceptors.request.use((config) => {
  // Get CSRF token from cookie
  const csrfToken = document.cookie
    .split('; ')
    .find(row => row.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];
  
  if (csrfToken) {
    config.headers['X-XSRF-TOKEN'] = csrfToken;
  }
  
  // Add origin header
  config.headers['Origin'] = window.location.origin;
  
  // Add request signature
  const timestamp = Math.floor(Date.now() / 1000);
  const payload = `${config.method?.toUpperCase()}${config.url}${timestamp}${JSON.stringify(config.data || {})}`;
  const signature = await generateSignature(payload);
  
  config.headers['X-Request-Timestamp'] = timestamp;
  config.headers['X-Request-Signature'] = signature;
  
  return config;
});

async function generateSignature(payload: string): Promise<string> {
  const encoder = new TextEncoder();
  const data = encoder.encode(payload);
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(import.meta.env.VITE_APP_KEY),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const signature = await crypto.subtle.sign('HMAC', key, data);
  return Array.from(new Uint8Array(signature))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}
```

**Files to Fix**:
1. `backend/app/Http/Middleware/VerifyApiCsrfToken.php` (new)
2. `backend/app/Http/Middleware/ValidateOrigin.php` (new)
3. `backend/app/Http/Middleware/VerifyRequestSignature.php` (new)
4. `backend/config/session.php`
5. `backend/config/cors.php`
6. `backend/bootstrap/app.php` (register middleware)
7. `frontend-web/src/lib/api.ts`
8. `backend/tests/Feature/CsrfProtectionTest.php` (new)

**Estimasi**: 6 hours  
**Priority**: P1 - Fix within 1 week  
**Assigned To**: Backend Team + Frontend Team

---

