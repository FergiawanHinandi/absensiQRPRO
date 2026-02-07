# Security Abuse & Misuse Test Scenarios
## School Attendance System - AbsensiQRPro

**Document Version**: 1.0.0  
**Last Updated**: February 2, 2026  
**Author**: Security QA Engineering Team  
**Classification**: CONFIDENTIAL - Internal Use Only

---

## 📋 Table of Contents

1. [QR Sharing Between Students](#1-qr-sharing-between-students)
2. [Payload Tampering](#2-payload-tampering)
3. [Scan Spamming](#3-scan-spamming)
4. [Unauthorized Teacher Access](#4-unauthorized-teacher-access)
5. [API Brute Force](#5-api-brute-force)
6. [Lost Student Card Misuse](#6-lost-student-card-misuse)
7. [Direct API Access Without UI](#7-direct-api-access-without-ui)
8. [Data Access Between Users](#8-data-access-between-users)
9. [Export Abuse](#9-export-abuse)
10. [Detection & Monitoring](#10-detection--monitoring)

---

## 1. QR Sharing Between Students

### 1.1 Attack Scenario: Screenshot Sharing

**Attack Method:**
```
1. Student A scans legitimate QR code
2. Student A takes screenshot of QR code
3. Student A shares screenshot to Student B via WhatsApp
4. Student B scans screenshot from their phone
5. Both students marked as present
```

**Expected System Defense:**

```php
// Defense Layer 1: Nonce-based QR with single use
$qrData = [
    'teacher_id' => $teacher->id,
    'schedule_id' => $schedule->id,
    'class_id' => $class->id,
    'timestamp' => now()->timestamp,
    'nonce' => Str::random(32), // Unique per generation
    'expires_at' => now()->addMinutes(5)->timestamp
];

// Defense Layer 2: Check nonce usage
$nonceKey = "qr_nonce:{$qrData['nonce']}";
if (Cache::has($nonceKey)) {
    throw new QRAlreadyUsedException([
        'message' => 'QR code sudah digunakan',
        'used_by' => Cache::get($nonceKey),
        'used_at' => Cache::get("{$nonceKey}:timestamp")
    ]);
}

// Mark nonce as used
Cache::put($nonceKey, $student->id, 300);
Cache::put("{$nonceKey}:timestamp", now(), 300);

// Defense Layer 3: Device fingerprinting
$deviceFingerprint = hash('sha256', 
    $request->userAgent() . 
    $request->ip() . 
    $request->header('Accept-Language')
);

// Check if same QR scanned from multiple devices
$deviceKey = "qr_device:{$qrData['nonce']}";
if (Cache::has($deviceKey) && Cache::get($deviceKey) !== $deviceFingerprint) {
    Log::warning('QR scanned from multiple devices', [
        'qr_nonce' => $qrData['nonce'],
        'original_device' => Cache::get($deviceKey),
        'new_device' => $deviceFingerprint,
        'student_id' => $student->id
    ]);
    
    // Flag for review
    SecurityAlert::create([
        'type' => 'qr_sharing_suspected',
        'severity' => 'medium',
        'details' => [...]
    ]);
}
```

**Detection in Logs:**

```json
// Log Pattern 1: Multiple scans with same nonce
{
  "event": "qr_scan_attempt",
  "level": "warning",
  "qr_nonce": "abc123...",
  "student_id": 456,
  "status": "rejected",
  "reason": "nonce_already_used",
  "original_user": 123,
  "timestamp": "2026-02-02T07:05:30Z"
}

// Log Pattern 2: Rapid scans from different students
{
  "event": "suspicious_scan_pattern",
  "level": "alert",
  "qr_nonce": "abc123...",
  "scans": [
    {"student_id": 123, "time": "07:05:00", "device": "hash1"},
    {"student_id": 456, "time": "07:05:05", "device": "hash2"}
  ],
  "time_difference_seconds": 5,
  "flag": "possible_qr_sharing"
}
```

### 1.2 Attack Scenario: QR Code Photo Relay

**Attack Method:**
```
1. Student A enters classroom
2. Student A photographs teacher's QR on projector
3. Student A sends photo to Student B (still outside)
4. Student B scans from photo while outside school
5. Student B marked present without being in class
```

**Expected System Defense:**

```php
// Defense: GPS validation with strict radius
public function validateLocation($latitude, $longitude, $school)
{
    $distance = $this->calculateDistance(
        $latitude, 
        $longitude,
        $school->latitude,
        $school->longitude
    );
    
    // School radius: 100 meters (strict)
    if ($distance > 100) {
        Log::warning('Attendance attempt outside school radius', [
            'student_id' => auth()->id(),
            'distance_meters' => $distance,
            'student_location' => [$latitude, $longitude],
            'school_location' => [$school->latitude, $school->longitude],
            'allowed_radius' => 100
        ]);
        
        throw new LocationOutOfRangeException([
            'message' => 'Lokasi Anda di luar area sekolah',
            'distance' => round($distance),
            'allowed' => 100
        ]);
    }
    
    // Additional check: Location accuracy
    $accuracy = $request->input('gps_accuracy');
    if ($accuracy > 50) { // More than 50 meters accuracy
        throw new GPSAccuracyTooLowException([
            'message' => 'Akurasi GPS terlalu rendah',
            'accuracy' => $accuracy,
            'required' => 50
        ]);
    }
}
```

**Detection in Logs:**

```json
{
  "event": "location_violation",
  "student_id": 456,
  "student_name": "Budi Santoso",
  "class": "XII IPA 1",
  "distance_from_school": 2500,
  "allowed_radius": 100,
  "student_gps": {
    "lat": -6.2088,
    "lng": 106.8456,
    "accuracy": 15
  },
  "school_gps": {
    "lat": -6.2000,
    "lng": 106.8400
  },
  "timestamp": "2026-02-02T07:05:00Z",
  "action": "scan_rejected"
}
```

---

## 2. Payload Tampering

### 2.1 Attack Scenario: Modified QR Data

**Attack Method:**
```
1. Attacker intercepts QR code data
2. Decrypts or reverse-engineers QR payload
3. Modifies timestamp to extend validity
4. Modifies class_id to attend different class
5. Re-encrypts and generates new QR
6. Scans modified QR
```

**Expected System Defense:**

```php
// Defense: HMAC signature verification
class QRCodeService
{
    private $secretKey;
    
    public function generateQR($data)
    {
        $payload = json_encode($data);
        
        // Create HMAC signature
        $signature = hash_hmac('sha256', $payload, $this->secretKey);
        
        // Combine payload + signature
        $combined = base64_encode($payload . '::' . $signature);
        
        // Encrypt entire package
        $encrypted = encrypt($combined);
        
        return $encrypted;
    }
    
    public function validateQR($encryptedQR)
    {
        try {
            // Decrypt
            $combined = decrypt($encryptedQR);
            $decoded = base64_decode($combined);
            
            // Split payload and signature
            [$payload, $signature] = explode('::', $decoded);
            
            // Verify signature
            $expectedSignature = hash_hmac('sha256', $payload, $this->secretKey);
            
            if (!hash_equals($expectedSignature, $signature)) {
                throw new InvalidSignatureException();
            }
            
            // Decode payload
            $data = json_decode($payload, true);
            
            // Validate timestamp
            if ($data['expires_at'] < now()->timestamp) {
                throw new QRExpiredException();
            }
            
            // Validate nonce hasn't been used
            if (Cache::has("qr_nonce:{$data['nonce']}")) {
                throw new QRAlreadyUsedException();
            }
            
            return $data;
            
        } catch (DecryptException $e) {
            Log::critical('QR decryption failed - possible tampering', [
                'encrypted_qr' => substr($encryptedQR, 0, 50) . '...',
                'error' => $e->getMessage(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
            
            throw new TamperedQRException();
        }
    }
}
```

**Detection in Logs:**

```json
{
  "event": "qr_tampering_detected",
  "level": "critical",
  "type": "invalid_signature",
  "encrypted_payload": "eyJpdiI6Ik...",
  "ip_address": "192.168.1.100",
  "user_agent": "Mozilla/5.0...",
  "student_id": 789,
  "timestamp": "2026-02-02T07:10:00Z",
  "action": "blocked_and_flagged"
}
```

### 2.2 Attack Scenario: Replay Attack

**Attack Method:**
```
1. Attacker captures valid QR code
2. Stores encrypted payload
3. Waits for next day
4. Replays same QR code
5. Attempts to mark attendance without teacher present
```

**Expected System Defense:**

```php
// Defense: Date-bound nonce + timestamp validation
public function validateReplay($qrData)
{
    // Check 1: Timestamp within acceptable range (5 minutes)
    $generatedAt = Carbon::createFromTimestamp($qrData['timestamp']);
    $now = now();
    
    if ($now->diffInMinutes($generatedAt) > 5) {
        Log::warning('Replay attack detected - old timestamp', [
            'qr_generated_at' => $generatedAt,
            'current_time' => $now,
            'difference_minutes' => $now->diffInMinutes($generatedAt),
            'student_id' => auth()->id()
        ]);
        
        throw new ReplayAttackException();
    }
    
    // Check 2: Date matches
    if ($generatedAt->toDateString() !== $now->toDateString()) {
        throw new QRDateMismatchException([
            'message' => 'QR code dari tanggal yang berbeda',
            'qr_date' => $generatedAt->toDateString(),
            'current_date' => $now->toDateString()
        ]);
    }
    
    // Check 3: Nonce stored with date prefix
    $nonceKey = "qr_nonce:" . $now->toDateString() . ":{$qrData['nonce']}";
    
    if (Cache::has($nonceKey)) {
        throw new QRAlreadyUsedException();
    }
    
    Cache::put($nonceKey, auth()->id(), 86400); // 24 hours
}
```

**Detection in Logs:**

```json
{
  "event": "replay_attack_detected",
  "level": "critical",
  "qr_generated_at": "2026-02-01T07:00:00Z",
  "scan_attempted_at": "2026-02-02T07:00:00Z",
  "time_difference_hours": 24,
  "student_id": 123,
  "student_name": "Ahmad Rizki",
  "class": "XII IPA 1",
  "ip_address": "192.168.1.50",
  "action": "blocked",
  "alert_sent_to": ["security_team", "school_admin"]
}
```

---

## 3. Scan Spamming

### 3.1 Attack Scenario: Rapid Scan Attempts

**Attack Method:**
```
1. Student writes script to automate QR scanning
2. Sends 1000 scan requests per minute
3. Attempts to overwhelm system
4. Tries to find valid QR through brute force
```

**Expected System Defense:**

```php
// Defense: Rate limiting with progressive penalties
class ScanRateLimiter
{
    public function checkRateLimit($studentId)
    {
        $key = "scan_attempts:{$studentId}";
        $attempts = Cache::get($key, 0);
        
        // Progressive limits
        if ($attempts >= 20) {
            // Ban for 1 hour after 20 attempts
            Cache::put("scan_banned:{$studentId}", true, 3600);
            
            Log::alert('Student banned for scan spamming', [
                'student_id' => $studentId,
                'attempts' => $attempts,
                'ban_duration' => '1 hour'
            ]);
            
            // Notify admin
            SecurityAlert::create([
                'type' => 'scan_spamming',
                'severity' => 'high',
                'student_id' => $studentId,
                'attempts' => $attempts
            ]);
            
            throw new ScanBannedException([
                'message' => 'Terlalu banyak percobaan scan',
                'retry_after' => 3600
            ]);
        }
        
        if ($attempts >= 10) {
            // Slow down after 10 attempts (CAPTCHA)
            return ['require_captcha' => true];
        }
        
        // Increment counter
        Cache::increment($key);
        Cache::expire($key, 60); // Reset after 1 minute
        
        return ['allowed' => true];
    }
}

// Middleware
class ThrottleScanRequests
{
    public function handle($request, Closure $next)
    {
        $studentId = auth()->id();
        
        // Check if banned
        if (Cache::has("scan_banned:{$studentId}")) {
            return response()->json([
                'error' => 'SCAN_BANNED',
                'message' => 'Akun Anda diblokir sementara',
                'retry_after' => Cache::get("scan_banned:{$studentId}:ttl")
            ], 429);
        }
        
        // Check rate limit
        $limiter = new ScanRateLimiter();
        $result = $limiter->checkRateLimit($studentId);
        
        if (isset($result['require_captcha'])) {
            if (!$request->has('captcha_token')) {
                return response()->json([
                    'error' => 'CAPTCHA_REQUIRED',
                    'message' => 'Verifikasi CAPTCHA diperlukan'
                ], 429);
            }
        }
        
        return $next($request);
    }
}
```

**Detection in Logs:**

```json
{
  "event": "scan_spamming_detected",
  "level": "alert",
  "student_id": 456,
  "student_name": "Budi Santoso",
  "attempts_per_minute": 150,
  "threshold": 10,
  "ip_address": "192.168.1.75",
  "user_agent": "Python-requests/2.28.0",
  "action": "banned_1_hour",
  "timestamp": "2026-02-02T07:15:00Z",
  "pattern": "automated_script_detected"
}
```

---

## 4. Unauthorized Teacher Access

### 4.1 Attack Scenario: Teacher Accessing Other Teacher's QR

**Attack Method:**
```
1. Teacher A logs in
2. Teacher A modifies API request
3. Changes teacher_id parameter to Teacher B's ID
4. Generates QR for Teacher B's class
5. Marks attendance for students not in their class
```

**Expected System Defense:**

```php
// Defense: Strict authorization checks
class GenerateQRController
{
    public function generate(Request $request)
    {
        $scheduleId = $request->input('schedule_id');
        $schedule = Schedule::findOrFail($scheduleId);
        
        // Authorization check
        if ($schedule->teacher_id !== auth()->id()) {
            Log::warning('Unauthorized QR generation attempt', [
                'attempting_teacher_id' => auth()->id(),
                'attempting_teacher_name' => auth()->user()->name,
                'target_schedule_id' => $scheduleId,
                'target_teacher_id' => $schedule->teacher_id,
                'target_teacher_name' => $schedule->teacher->name,
                'class' => $schedule->class->name,
                'ip_address' => $request->ip()
            ]);
            
            // Create security incident
            SecurityIncident::create([
                'type' => 'unauthorized_qr_generation',
                'severity' => 'high',
                'user_id' => auth()->id(),
                'details' => [
                    'target_schedule' => $scheduleId,
                    'target_teacher' => $schedule->teacher_id
                ]
            ]);
            
            abort(403, 'Anda tidak memiliki akses ke jadwal ini');
        }
        
        // Additional check: Verify current time matches schedule
        $now = now();
        if (!$this->isScheduleActive($schedule, $now)) {
            throw new ScheduleNotActiveException();
        }
        
        // Generate QR
        return $this->qrService->generate($schedule);
    }
}
```

**Detection in Logs:**

```json
{
  "event": "unauthorized_access_attempt",
  "level": "warning",
  "type": "cross_teacher_qr_generation",
  "attacker": {
    "teacher_id": 10,
    "teacher_name": "Pak Ahmad",
    "assigned_classes": ["X IPA 1", "X IPA 2"]
  },
  "target": {
    "teacher_id": 15,
    "teacher_name": "Bu Siti",
    "schedule_id": 250,
    "class": "XII IPA 3"
  },
  "ip_address": "192.168.1.100",
  "timestamp": "2026-02-02T08:00:00Z",
  "action": "blocked",
  "incident_id": "INC-20260202-001"
}
```

### 4.2 Attack Scenario: Privilege Escalation

**Attack Method:**
```
1. Regular teacher account
2. Modifies role in JWT token
3. Attempts to access admin endpoints
4. Tries to modify school settings
```

**Expected System Defense:**

```php
// Defense: Server-side role verification
class VerifyRole
{
    public function handle($request, Closure $next, ...$roles)
    {
        $user = auth()->user();
        
        // Never trust client-provided role
        // Always fetch from database
        $actualRole = User::find($user->id)->role_type;
        
        if ($actualRole !== $user->role_type) {
            Log::critical('Token role mismatch - possible tampering', [
                'user_id' => $user->id,
                'token_role' => $user->role_type,
                'database_role' => $actualRole,
                'ip_address' => $request->ip(),
                'endpoint' => $request->path()
            ]);
            
            // Invalidate all user sessions
            $this->revokeAllSessions($user->id);
            
            // Create security incident
            SecurityIncident::create([
                'type' => 'token_tampering',
                'severity' => 'critical',
                'user_id' => $user->id
            ]);
            
            abort(401, 'Token tidak valid');
        }
        
        if (!in_array($actualRole, $roles)) {
            Log::warning('Unauthorized role access attempt', [
                'user_id' => $user->id,
                'user_role' => $actualRole,
                'required_roles' => $roles,
                'endpoint' => $request->path()
            ]);
            
            abort(403, 'Akses ditolak');
        }
        
        return $next($request);
    }
}
```

**Detection in Logs:**

```json
{
  "event": "privilege_escalation_attempt",
  "level": "critical",
  "user_id": 25,
  "username": "teacher_ahmad",
  "actual_role": "teacher",
  "claimed_role": "school_admin",
  "endpoint": "/api/v1/admin/settings",
  "method": "PUT",
  "ip_address": "192.168.1.120",
  "timestamp": "2026-02-02T09:00:00Z",
  "action": "sessions_revoked",
  "incident_id": "INC-20260202-002"
}
```

---

## 5. API Brute Force

### 5.1 Attack Scenario: Login Brute Force

**Attack Method:**
```
1. Attacker obtains list of usernames
2. Uses automated tool (Hydra, Burp Intruder)
3. Tries common passwords
4. 1000+ login attempts per minute
```

**Expected System Defense:**

```php
// Defense: Multi-layer brute force protection
class LoginThrottler
{
    public function checkAttempts($username, $ip)
    {
        $usernameKey = "login_attempts:username:{$username}";
        $ipKey = "login_attempts:ip:{$ip}";
        $globalKey = "login_attempts:global";
        
        $usernameAttempts = Cache::get($usernameKey, 0);
        $ipAttempts = Cache::get($ipKey, 0);
        $globalAttempts = Cache::get($globalKey, 0);
        
        // Check 1: Username-based (5 attempts per 15 minutes)
        if ($usernameAttempts >= 5) {
            $this->notifySecurityTeam('username_brute_force', [
                'username' => $username,
                'attempts' => $usernameAttempts
            ]);
            
            throw new TooManyAttemptsException([
                'message' => 'Terlalu banyak percobaan login',
                'retry_after' => 900
            ]);
        }
        
        // Check 2: IP-based (20 attempts per hour)
        if ($ipAttempts >= 20) {
            // Block IP temporarily
            Cache::put("ip_blocked:{$ip}", true, 3600);
            
            Log::alert('IP blocked for brute force', [
                'ip' => $ip,
                'attempts' => $ipAttempts
            ]);
            
            throw new IPBlockedException();
        }
        
        // Check 3: Global rate limit (1000 per minute)
        if ($globalAttempts >= 1000) {
            // DDoS protection - enable CAPTCHA globally
            Cache::put('global_captcha_required', true, 300);
            
            Log::emergency('Possible DDoS attack - global CAPTCHA enabled', [
                'global_attempts' => $globalAttempts
            ]);
        }
        
        // Increment counters
        Cache::increment($usernameKey, 1);
        Cache::expire($usernameKey, 900);
        
        Cache::increment($ipKey, 1);
        Cache::expire($ipKey, 3600);
        
        Cache::increment($globalKey, 1);
        Cache::expire($globalKey, 60);
    }
    
    public function recordSuccess($username, $ip)
    {
        // Clear counters on successful login
        Cache::forget("login_attempts:username:{$username}");
        Cache::forget("login_attempts:ip:{$ip}");
    }
}
```

**Detection in Logs:**

```json
{
  "event": "brute_force_attack_detected",
  "level": "critical",
  "attack_type": "login_brute_force",
  "target_username": "admin",
  "source_ip": "203.0.113.50",
  "attempts_count": 150,
  "time_window": "5 minutes",
  "passwords_tried": [
    "admin123",
    "password",
    "12345678",
    "..."
  ],
  "user_agents": [
    "Python-requests/2.28.0",
    "curl/7.68.0"
  ],
  "action": "ip_blocked_1_hour",
  "timestamp": "2026-02-02T10:00:00Z",
  "geolocation": {
    "country": "Unknown",
    "city": "Unknown"
  }
}
```

---

## 6. Lost Student Card Misuse

### 6.1 Attack Scenario: Stolen Card Usage

**Attack Method:**
```
1. Student A loses physical student card
2. Student B finds the card
3. Student B scans QR from lost card
4. Student B marked as Student A
```

**Expected System Defense:**

```php
// Defense: Card revocation + biometric verification
class StudentCardValidator
{
    public function validateCard($cardQR, $scanningStudent)
    {
        $cardData = $this->decryptCard($cardQR);
        
        // Check 1: Card not revoked
        $card = StudentCard::where('card_number', $cardData['card_number'])
            ->first();
        
        if ($card->status === 'revoked') {
            Log::warning('Revoked card scan attempt', [
                'card_number' => $cardData['card_number'],
                'card_owner' => $card->student_id,
                'scanning_user' => $scanningStudent->id,
                'revoked_at' => $card->revoked_at,
                'revocation_reason' => $card->revocation_reason
            ]);
            
            throw new RevokedCardException([
                'message' => 'Kartu ini sudah tidak berlaku',
                'action' => 'Hubungi admin untuk kartu baru'
            ]);
        }
        
        // Check 2: Card owner matches scanning student
        if ($card->student_id !== $scanningStudent->id) {
            Log::alert('Card ownership mismatch - possible theft', [
                'card_owner_id' => $card->student_id,
                'card_owner_name' => $card->student->name,
                'scanning_student_id' => $scanningStudent->id,
                'scanning_student_name' => $scanningStudent->name,
                'location' => [
                    'lat' => request()->input('latitude'),
                    'lng' => request()->input('longitude')
                ]
            ]);
            
            // Auto-revoke card
            $card->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revocation_reason' => 'Suspicious usage detected'
            ]);
            
            // Notify both students and admin
            $this->notifyCardMisuse($card, $scanningStudent);
            
            throw new CardOwnershipMismatchException([
                'message' => 'Kartu ini bukan milik Anda',
                'action' => 'Kartu telah diblokir. Admin akan menghubungi Anda.'
            ]);
        }
        
        // Check 3: Optional biometric verification
        if ($card->requires_biometric) {
            $this->verifyBiometric($scanningStudent, request()->input('biometric_data'));
        }
        
        return $card;
    }
}
```

**Detection in Logs:**

```json
{
  "event": "stolen_card_usage_detected",
  "level": "alert",
  "card_number": "SC-2026-001234",
  "card_owner": {
    "student_id": 100,
    "name": "Ahmad Rizki",
    "class": "XII IPA 1"
  },
  "unauthorized_user": {
    "student_id": 200,
    "name": "Budi Santoso",
    "class": "XII IPA 2"
  },
  "scan_location": {
    "lat": -6.2000,
    "lng": 106.8400
  },
  "timestamp": "2026-02-02T07:30:00Z",
  "action": "card_auto_revoked",
  "notifications_sent": [
    "card_owner",
    "unauthorized_user",
    "school_admin",
    "security_team"
  ]
}
```

---

## 7. Direct API Access Without UI

### 7.1 Attack Scenario: Automated API Calls

**Attack Method:**
```
1. Attacker reverse-engineers mobile app
2. Extracts API endpoints and authentication
3. Writes Python script to call APIs directly
4. Bypasses UI validations and rate limits
```

**Expected System Defense:**

```php
// Defense: API request validation + fingerprinting
class ValidateAPIRequest
{
    public function handle($request, Closure $next)
    {
        // Check 1: Required headers
        $requiredHeaders = [
            'X-App-Version',
            'X-Device-ID',
            'X-Platform'
        ];
        
        foreach ($requiredHeaders as $header) {
            if (!$request->hasHeader($header)) {
                Log::warning('Missing required header - possible API abuse', [
                    'missing_header' => $header,
                    'ip' => $request->ip(),
                    'endpoint' => $request->path()
                ]);
                
                return response()->json([
                    'error' => 'INVALID_REQUEST',
                    'message' => 'Request tidak valid'
                ], 400);
            }
        }
        
        // Check 2: App version validation
        $appVersion = $request->header('X-App-Version');
        $minVersion = config('app.min_supported_version');
        
        if (version_compare($appVersion, $minVersion, '<')) {
            return response()->json([
                'error' => 'APP_UPDATE_REQUIRED',
                'message' => 'Aplikasi perlu diupdate',
                'min_version' => $minVersion,
                'current_version' => $appVersion
            ], 426);
        }
        
        // Check 3: Device fingerprint consistency
        $deviceId = $request->header('X-Device-ID');
        $userId = auth()->id();
        
        $knownDevice = UserDevice::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();
        
        if (!$knownDevice) {
            // New device - require additional verification
            Log::info('New device detected', [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'platform' => $request->header('X-Platform')
            ]);
            
            // Require OTP verification for sensitive operations
            if ($this->isSensitiveEndpoint($request->path())) {
                return response()->json([
                    'error' => 'DEVICE_VERIFICATION_REQUIRED',
                    'message' => 'Verifikasi perangkat diperlukan'
                ], 403);
            }
            
            // Register device
            UserDevice::create([
                'user_id' => $userId,
                'device_id' => $deviceId,
                'platform' => $request->header('X-Platform'),
                'first_seen' => now()
            ]);
        }
        
        // Check 4: Detect automated tools
        $userAgent = $request->userAgent();
        $automatedPatterns = [
            'python-requests',
            'curl',
            'postman',
            'insomnia',
            'httpie'
        ];
        
        foreach ($automatedPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                Log::alert('Automated tool detected', [
                    'user_id' => $userId,
                    'user_agent' => $userAgent,
                    'endpoint' => $request->path(),
                    'ip' => $request->ip()
                ]);
                
                // Flag for review
                SecurityAlert::create([
                    'type' => 'automated_api_access',
                    'severity' => 'medium',
                    'user_id' => $userId,
                    'details' => [
                        'user_agent' => $userAgent,
                        'endpoint' => $request->path()
                    ]
                ]);
            }
        }
        
        return $next($request);
    }
}
```

**Detection in Logs:**

```json
{
  "event": "automated_api_access_detected",
  "level": "warning",
  "user_id": 150,
  "username": "student_ahmad",
  "user_agent": "python-requests/2.28.0",
  "missing_headers": ["X-App-Version", "X-Device-ID"],
  "endpoint": "/api/v1/attendance/scan",
  "ip_address": "192.168.1.200",
  "request_pattern": {
    "requests_per_minute": 60,
    "average_response_time": "50ms",
    "pattern": "highly_regular_intervals"
  },
  "timestamp": "2026-02-02T11:00:00Z",
  "action": "flagged_for_review"
}
```

---

## 8. Data Access Between Users

### 8.1 Attack Scenario: IDOR (Insecure Direct Object Reference)

**Attack Method:**
```
1. Student A views their attendance: /api/students/100/attendance
2. Student A changes ID to 101 in URL
3. Attempts to view Student B's attendance
4. Tries sequential IDs to scrape all student data
```

**Expected System Defense:**

```php
// Defense: Authorization policy enforcement
class StudentAttendanceController
{
    public function show($studentId)
    {
        $requestingUser = auth()->user();
        $targetStudent = Student::findOrFail($studentId);
        
        // Authorization check
        $authorized = false;
        
        switch ($requestingUser->role_type) {
            case 'student':
                // Students can only view their own data
                $authorized = ($targetStudent->user_id === $requestingUser->id);
                break;
                
            case 'parent':
                // Parents can only view their children's data
                $authorized = $targetStudent->parents()
                    ->where('parent_id', $requestingUser->id)
                    ->exists();
                break;
                
            case 'teacher':
            case 'homeroom_teacher':
                // Teachers can view students in their classes
                $authorized = $targetStudent->class->teachers()
                    ->where('teacher_id', $requestingUser->id)
                    ->exists();
                break;
                
            case 'school_admin':
            case 'super_admin':
                // Admins can view all students in their school
                $authorized = ($targetStudent->school_id === $requestingUser->school_id);
                break;
        }
        
        if (!$authorized) {
            Log::warning('Unauthorized data access attempt (IDOR)', [
                'requesting_user_id' => $requestingUser->id,
                'requesting_user_role' => $requestingUser->role_type,
                'target_student_id' => $studentId,
                'target_student_name' => $targetStudent->name,
                'endpoint' => request()->path(),
                'ip' => request()->ip()
            ]);
            
            // Track IDOR attempts
            $this->trackIDORAttempt($requestingUser->id, $studentId);
            
            abort(403, 'Anda tidak memiliki akses ke data ini');
        }
        
        return $targetStudent->attendances;
    }
    
    private function trackIDORAttempt($userId, $targetId)
    {
        $key = "idor_attempts:{$userId}";
        $attempts = Cache::increment($key);
        Cache::expire($key, 3600);
        
        if ($attempts >= 5) {
            Log::alert('Multiple IDOR attempts - possible data scraping', [
                'user_id' => $userId,
                'attempts' => $attempts,
                'last_target' => $targetId
            ]);
            
            // Temporary ban
            Cache::put("user_banned:{$userId}", true, 3600);
        }
    }
}
```

**Detection in Logs:**

```json
{
  "event": "idor_attempt_detected",
  "level": "warning",
  "attacker": {
    "user_id": 100,
    "username": "student_ahmad",
    "role": "student",
    "class": "XII IPA 1"
  },
  "target": {
    "student_id": 101,
    "student_name": "Budi Santoso",
    "class": "XII IPA 2"
  },
  "endpoint": "/api/v1/students/101/attendance",
  "method": "GET",
  "ip_address": "192.168.1.150",
  "timestamp": "2026-02-02T12:00:00Z",
  "sequential_attempts": [
    {"id": 99, "time": "11:59:50"},
    {"id": 100, "time": "11:59:55"},
    {"id": 101, "time": "12:00:00"}
  ],
  "action": "blocked",
  "pattern": "sequential_id_enumeration"
}
```

---

## 9. Export Abuse

### 9.1 Attack Scenario: Mass Data Exfiltration

**Attack Method:**
```
1. Admin account compromised
2. Attacker exports all student data
3. Exports all attendance records
4. Downloads all reports
5. Exfiltrates sensitive information
```

**Expected System Defense:**

```php
// Defense: Export auditing + rate limiting
class ExportController
{
    public function exportAttendance(Request $request)
    {
        $user = auth()->user();
        
        // Check 1: Export rate limit (5 per hour)
        $exportKey = "exports:{$user->id}";
        $exportCount = Cache::get($exportKey, 0);
        
        if ($exportCount >= 5) {
            Log::warning('Export rate limit exceeded', [
                'user_id' => $user->id,
                'exports_this_hour' => $exportCount
            ]);
            
            throw new TooManyExportsException([
                'message' => 'Terlalu banyak export',
                'limit' => 5,
                'retry_after' => 3600
            ]);
        }
        
        // Check 2: Data scope validation
        $dateRange = $request->input('date_range');
        $maxDays = 365;
        
        if ($this->calculateDays($dateRange) > $maxDays) {
            throw new ExportScopeTooLargeException([
                'message' => 'Periode export terlalu panjang',
                'max_days' => $maxDays
            ]);
        }
        
        // Check 3: Sensitive data masking for non-admins
        $includeSensitive = $request->input('include_sensitive', false);
        
        if ($includeSensitive && !in_array($user->role_type, ['school_admin', 'super_admin'])) {
            Log::alert('Unauthorized sensitive data export attempt', [
                'user_id' => $user->id,
                'user_role' => $user->role_type
            ]);
            
            abort(403, 'Akses ditolak');
        }
        
        // Audit log
        ExportLog::create([
            'user_id' => $user->id,
            'export_type' => 'attendance',
            'date_range' => $dateRange,
            'record_count' => $this->getRecordCount($dateRange),
            'include_sensitive' => $includeSensitive,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);
        
        // Increment counter
        Cache::increment($exportKey);
        Cache::expire($exportKey, 3600);
        
        // Generate export
        return $this->generateExport($dateRange, $includeSensitive);
    }
}
```

**Detection in Logs:**

```json
{
  "event": "mass_export_detected",
  "level": "alert",
  "user_id": 5,
  "username": "admin_school",
  "role": "school_admin",
  "export_details": {
    "type": "attendance",
    "date_range": "2025-01-01 to 2026-02-02",
    "total_days": 398,
    "record_count": 15000,
    "include_sensitive": true,
    "file_size_mb": 25
  },
  "export_pattern": {
    "exports_last_hour": 5,
    "exports_last_day": 20,
    "unusual_time": true,
    "time": "02:30:00"
  },
  "ip_address": "203.0.113.100",
  "geolocation": {
    "country": "Indonesia",
    "city": "Jakarta",
    "unusual_location": false
  },
  "timestamp": "2026-02-02T02:30:00Z",
  "action": "allowed_but_flagged",
  "alert_sent_to": ["security_team", "super_admin"]
}
```

---

## 10. Detection & Monitoring

### 10.1 Real-time Monitoring Dashboard

```php
// Security Metrics Collection
class SecurityMetricsCollector
{
    public function collectMetrics()
    {
        return [
            'failed_logins_last_hour' => $this->getFailedLogins(),
            'blocked_ips' => $this->getBlockedIPs(),
            'suspicious_scans' => $this->getSuspiciousScans(),
            'idor_attempts' => $this->getIDORAttempts(),
            'export_anomalies' => $this->getExportAnomalies(),
            'active_security_incidents' => $this->getActiveIncidents()
        ];
    }
    
    private function getFailedLogins()
    {
        return DB::table('audit_logs')
            ->where('event', 'login_failed')
            ->where('created_at', '>', now()->subHour())
            ->count();
    }
    
    // ... other metric methods
}
```

### 10.2 Automated Alert Rules

```yaml
# config/security_alerts.yml
alert_rules:
  - name: "Brute Force Attack"
    condition: "failed_logins > 50 in 5 minutes"
    severity: critical
    actions:
      - notify_security_team
      - enable_global_captcha
      - block_source_ip
      
  - name: "Mass Data Export"
    condition: "exports > 10 in 1 hour from single user"
    severity: high
    actions:
      - notify_admin
      - require_mfa_for_next_export
      - log_detailed_audit
      
  - name: "QR Sharing Suspected"
    condition: "same_qr_scanned_from_multiple_devices"
    severity: medium
    actions:
      - notify_teacher
      - flag_students_for_review
      - invalidate_qr
```

### 10.3 Log Analysis Queries

```sql
-- Find potential QR sharing
SELECT 
    qr_nonce,
    COUNT(DISTINCT student_id) as unique_students,
    COUNT(DISTINCT device_fingerprint) as unique_devices,
    MIN(scan_time) as first_scan,
    MAX(scan_time) as last_scan,
    TIMESTAMPDIFF(SECOND, MIN(scan_time), MAX(scan_time)) as time_diff_seconds
FROM attendance_logs
WHERE date = CURDATE()
GROUP BY qr_nonce
HAVING unique_students > 1 OR unique_devices > 1;

-- Find IDOR enumeration attempts
SELECT 
    user_id,
    COUNT(*) as attempts,
    COUNT(DISTINCT target_id) as unique_targets,
    MIN(target_id) as min_id,
    MAX(target_id) as max_id
FROM access_denied_logs
WHERE created_at > NOW() - INTERVAL 1 HOUR
GROUP BY user_id
HAVING attempts > 5 AND (max_id - min_id) = (unique_targets - 1);

-- Find suspicious export patterns
SELECT 
    user_id,
    COUNT(*) as export_count,
    SUM(record_count) as total_records,
    MIN(created_at) as first_export,
    MAX(created_at) as last_export
FROM export_logs
WHERE created_at > NOW() - INTERVAL 24 HOUR
GROUP BY user_id
HAVING export_count > 10 OR total_records > 50000;
```

---

## 11. Incident Response Playbook

### 11.1 QR Sharing Incident

**Detection**: Multiple students scanned with same QR nonce

**Response Steps**:
1. Invalidate the QR code immediately
2. Identify all students involved
3. Review GPS coordinates of scans
4. Interview teacher and students
5. Apply disciplinary action if confirmed
6. Update security measures

### 11.2 Data Breach Incident

**Detection**: Unauthorized mass data export

**Response Steps**:
1. Immediately revoke user access
2. Audit all recent exports by user
3. Identify compromised data scope
4. Notify affected parties (GDPR compliance)
5. Change all admin passwords
6. Review and patch security vulnerability
7. File incident report

### 11.3 API Abuse Incident

**Detection**: Automated API calls detected

**Response Steps**:
1. Rate limit or block the IP
2. Invalidate user's API tokens
3. Analyze attack pattern
4. Patch exploited vulnerability
5. Update API security measures
6. Monitor for similar patterns

---

**Document Classification**: CONFIDENTIAL  
**Distribution**: Security Team, QA Team, Development Team  
**Review Cycle**: Monthly  
**Last Security Audit**: February 2, 2026
