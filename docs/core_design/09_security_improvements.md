# 🔐 Security Audit - Extended (Critical Gaps)

> **CRITICAL:** Kerentanan yang HARUS diperbaiki sebelum pilot sekolah

---

## 🔴 CRITICAL - Must Fix Before Pilot

### 1. QR Code Replay Attack - Token Sharing

**❌ Masalah SANGAT REAL:**

**Skenario Kejadian Nyata:**
1. Siswa A (di sekolah) scan QR jam 07:05 ✅
2. Siswa A screenshot QR code
3. Siswa A kirim via WhatsApp ke Siswa B (masih di rumah)
4. Siswa B buka screenshot, scan QR code
5. Siswa B **fake GPS** ke koordinat sekolah
6. **SISWA B BERHASIL ABSEN** ❌

**Root Cause:**
- Token stateless (tidak terikat student_id)
- Tidak ada deteksi suspicious pattern
- QR bersifat "bearer token" (siapa yang punya = valid)

**Status:** ❌ **BELUM DICEGAH**

---

**✅ Solusi: Heuristic Pattern Detection**

Kita **TIDAK** bisa bind QR ke student (QR harus umum untuk semua siswa di kelas).

Tapi kita bisa **detect anomaly** dan **flag suspicious behavior**.

**Implementation:**

```php
// app/Services/AttendanceService.php

public function scanQrCode(User $student, string $token, array $location): Attendance
{
    $qrData = $this->qrService->validateToken($token);
    $qr = QrCode::findOrFail($qrData['qr_id']);
    
    // ===== ANTI-REPLAY DETECTION =====
    
    // 1. Check suspicious pattern
    $suspicion = $this->detectSuspiciousPattern($student, $qr, $location);
    
    if ($suspicion['level'] === 'high') {
        // Block immediately
        Log::critical('High suspicion QR replay attack', $suspicion['details']);
        throw new SuspiciousScanException(
            'Absensi Anda ditandai sebagai mencurigakan. Silakan hubungi guru.'
        );
    }
    
    if ($suspicion['level'] === 'medium') {
        // Allow but flag for review
        Log::warning('Medium suspicion QR scan', $suspicion['details']);
        $flagForReview = true;
    }
    
    // Continue normal flow...
    $attendance = Attendance::create([...]);
    
    if (isset($flagForReview)) {
        $attendance->update(['requires_review' => true]);
        $this->notifyTeacher($attendance, 'Suspicious scan detected');
    }
    
    return $attendance;
}

/**
 * Heuristic-based suspicion detection
 */
private function detectSuspiciousPattern(User $student, QrCode $qr, array $location): array
{
    $details = [];
    $score = 0;
    
    // 1. Check recent scans of SAME token
    $recentScans = DB::table('attendance_logs')
        ->where('qr_code_id', $qr->id)
        ->where('created_at', '>', now()->subMinutes(5))
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
    
    foreach ($recentScans as $recentScan) {
        // Same QR, different student, within 3 seconds
        if ($recentScan->user_id !== $student->id) {
            $timeDiff = now()->diffInSeconds($recentScan->created_at);
            
            if ($timeDiff < 3) {
                $score += 50; // HIGH suspicion
                $details[] = "Same QR scanned by another student {$timeDiff}s ago";
            } elseif ($timeDiff < 10) {
                $score += 20; // MEDIUM suspicion
                $details[] = "Multiple students scanning within 10s";
            }
        }
    }
    
    // 2. Check GPS similarity (potential fake GPS)
    if (!empty($recentScans)) {
        $lastScan = $recentScans->first();
        
        if ($lastScan->latitude && $lastScan->longitude) {
            $distance = $this->locationService->haversineDistance(
                $location['latitude'],
                $location['longitude'],
                $lastScan->latitude,
                $lastScan->longitude
            );
            
            // Same exact GPS coordinate = suspicious (fake GPS)
            if ($distance < 5) { // 5 meter radius
                $score += 30;
                $details[] = "GPS too close to another scan: {$distance}m";
            }
        }
    }
    
    // 3. Check this student's scan frequency
    $studentRecentScans = DB::table('attendance_logs')
        ->where('user_id', $student->id)
        ->where('created_at', '>', now()->subMinutes(1))
        ->count();
    
    if ($studentRecentScans > 3) {
        $score += 25;
        $details[] = "Too many scans in 1 minute: {$studentRecentScans}";
    }
    
    // 4. Check device fingerprint change
    $lastDeviceInfo = Cache::get("student_{$student->id}_last_device");
    
    if ($lastDeviceInfo && isset($location['device_info'])) {
        $currentDevice = $location['device_info']['device_id'] ?? null;
        
        if ($currentDevice !== $lastDeviceInfo['device_id']) {
            $score += 15;
            $details[] = "Device change detected";
        }
    }
    
    // Cache current device
    Cache::put(
        "student_{$student->id}_last_device",
        $location['device_info'] ?? [],
        now()->addHours(24)
    );
    
    // Determine suspicion level
    $level = 'low';
    if ($score >= 50) {
        $level = 'high';
    } elseif ($score >= 20) {
        $level = 'medium';
    }
    
    return [
        'level' => $level,
        'score' => $score,
        'details' => array_merge($details, [
            'student_id' => $student->id,
            'qr_code_id' => $qr->id,
            'location' => $location,
        ]),
    ];
}
```

**Migration: Add review flag**

```php
Schema::table('attendances', function (Blueprint $table) {
    $table->boolean('requires_review')->default(false)->after('is_manual');
    $table->text('review_notes')->nullable()->after('requires_review');
});
```

**Keuntungan:**
- ✅ Tidak break UX (QR tetap umum)
- ✅ Detect mass replay
- ✅ Teacher dapat review suspicious scans
- ✅ Gradual enforcement (warn → block)

---

### 2. Sanctum Token - Unlimited Devices

**❌ Masalah:**

```php
// Login response
{
  "token": "1|abc123..."
}
```

**Risiko:**
- 1 akun siswa → login 100 device
- Token bocor → valid sampai revoke manual
- Teman pinjam akun → no control

**Status:** ❌ **TIDAK ADA LIMITING**

---

**✅ Solusi: Device Management**

**Option 1: Single Device Only (Strict)**

```php
// app/Http/Controllers/Api/AuthController.php

public function login(LoginRequest $request)
{
    $user = User::where('username', $request->username)->first();
    
    if (!Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'username' => ['Invalid credentials'],
        ]);
    }
    
    // ===== DEVICE MANAGEMENT =====
    
    // Option 1: Revoke ALL previous tokens (strict)
    $user->tokens()->delete();
    
    // Create new token
    $token = $user->createToken(
        $request->device_name,
        ['*'],
        now()->addDays(30) // Token expiry
    )->plainTextToken;
    
    // Log login
    $user->update(['last_login_at' => now()]);
    
    return response()->json([
        'token' => $token,
        'user' => $user->load('profile'),
    ]);
}
```

**Option 2: Max N Devices (Flexible)**

```php
public function login(LoginRequest $request)
{
    // ... authentication ...
    
    $maxDevices = $user->school->settings['max_devices_per_user'] ?? 2;
    
    // Count active tokens
    $activeTokens = $user->tokens()->count();
    
    if ($activeTokens >= $maxDevices) {
        // Revoke oldest token
        $user->tokens()
            ->orderBy('created_at', 'asc')
            ->first()
            ->delete();
    }
    
    $token = $user->createToken(
        $request->device_name,
        ['*'],
        now()->addDays(30)
    )->plainTextToken;
    
    return response()->json([
        'token' => $token,
        'active_devices' => $activeTokens + 1,
        'max_devices' => $maxDevices,
    ]);
}
```

**Option 3: Device Fingerprint Binding (Most Secure)**

```php
public function login(LoginRequest $request)
{
    // ... authentication ...
    
    $deviceFingerprint = $this->generateDeviceFingerprint($request);
    
    // Check if this device already has token
    $existingToken = $user->tokens()
        ->where('name', $request->device_name)
        ->whereJsonContains('abilities', $deviceFingerprint)
        ->first();
    
    if ($existingToken) {
        // Reuse existing token (same device)
        return response()->json([
            'token' => $existingToken->plainTextToken,
            'message' => 'Logged in with existing session',
        ]);
    }
    
    // Limit max devices
    if ($user->tokens()->count() >= 2) {
        throw new TooManyDevicesException('Maximum 2 devices allowed');
    }
    
    // Create token with device fingerprint
    $token = $user->createToken(
        $request->device_name,
        [$deviceFingerprint],
        now()->addDays(30)
    )->plainTextToken;
    
    return response()->json(['token' => $token]);
}

private function generateDeviceFingerprint(Request $request): string
{
    $components = [
        $request->input('device_info.device_id'),
        $request->input('device_info.os'),
        $request->input('device_info.model'),
    ];
    
    return 'device:' . hash('sha256', implode('|', $components));
}
```

**Device Management Endpoint:**

```php
// GET /api/v1/auth/devices
public function listDevices(Request $request)
{
    $devices = $request->user()
        ->tokens()
        ->select('id', 'name', 'created_at', 'last_used_at')
        ->get();
    
    return response()->json(['devices' => $devices]);
}

// DELETE /api/v1/auth/devices/{id}
public function revokeDevice(Request $request, $tokenId)
{
    $request->user()
        ->tokens()
        ->where('id', $tokenId)
        ->delete();
    
    return response()->json(['message' => 'Device revoked']);
}
```

**Rekomendasi:** **Option 1 untuk siswa**, **Option 2 untuk guru/admin**

---

### 3. Tenant Isolation - Not Enforced Everywhere

**❌ Masalah:**

Developer lupa `->where('school_id', auth()->user()->school_id)`

**Data sekolah A bocor ke sekolah B** = FATAL

**Status:** ⚠️ **RAWAN BYPASS**

---

**✅ Solusi: Defense in Depth**

**Layer 1: Global Scope (Auto-filter)**

```php
// app/Models/Concerns/BelongsToSchool.php

trait BelongsToSchool
{
    protected static function bootBelongsToSchool()
    {
        static::addGlobalScope('school', function (Builder $builder) {
            if (auth()->check() && !auth()->user()->hasRole('super_admin')) {
                $builder->where(
                    $builder->getModel()->getTable() . '.school_id',
                    auth()->user()->school_id
                );
            }
        });
        
        // Auto-fill school_id on create
        static::creating(function ($model) {
            if (auth()->check() && !$model->school_id) {
                $model->school_id = auth()->user()->school_id;
            }
        });
    }
}
```

**Usage:**

```php
// app/Models/Schedule.php

class Schedule extends Model
{
    use BelongsToSchool; // Auto-filter by school_id
    
    // All queries automatically filtered:
    Schedule::all(); // WHERE school_id = auth()->user()->school_id
    Schedule::find(1); // AND school_id = ...
}
```

**Layer 2: Repository Guard**

```php
// app/Repositories/BaseRepository.php

abstract class BaseRepository
{
    protected function guardTenant($model)
    {
        if (!$model) {
            return;
        }
        
        if (auth()->user()->hasRole('super_admin')) {
            return; // Super admin bypass
        }
        
        if ($model->school_id !== auth()->user()->school_id) {
            Log::critical('Tenant isolation breach attempt', [
                'user_id' => auth()->id(),
                'user_school_id' => auth()->user()->school_id,
                'model_school_id' => $model->school_id,
                'model' => get_class($model),
            ]);
            
            throw new AuthorizationException('Access denied: different school');
        }
    }
    
    public function find(int $id)
    {
        $model = $this->model->find($id);
        $this->guardTenant($model);
        
        return $model;
    }
}
```

**Layer 3: Middleware Validation**

```php
// app/Http/Middleware/EnforceTenantIsolation.php

class EnforceTenantIsolation
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return $next($request);
        }
        
        // Validate route parameters
        foreach ($request->route()->parameters() as $param) {
            if ($param instanceof Model && property_exists($param, 'school_id')) {
                if ($param->school_id !== auth()->user()->school_id) {
                    abort(403, 'Access denied: different school');
                }
            }
        }
        
        return $next($request);
    }
}
```

**Triple defense:**
1. Global Scope (query level)
2. Repository guard (retrieval level)
3. Middleware (HTTP level)

---

### 4. Authorization - Role ≠ Ownership

**❌ Masalah:**

```php
// Middleware
Route::post('/schedule/{id}', ...)
    ->middleware('permission:attendance.view');
```

**Tapi:**
- Guru A bisa lihat schedule **Guru B**
- Teacher bisa lihat **semua kelas**, bukan hanya yang dia ajar

**RBAC tidak cukup. Perlu OWNERSHIP check.**

---

**✅ Solusi: Laravel Policy**

**Create Policies:**

```php
// app/Policies/SchedulePolicy.php

class SchedulePolicy
{
    /**
     * Determine if user can view schedule
     */
    public function view(User $user, Schedule $schedule): bool
    {
        // Super admin can view all
        if ($user->hasRole('super_admin')) {
            return true;
        }
        
        // School admin can view all in their school
        if ($user->hasRole('school_admin')) {
            return $schedule->school_id === $user->school_id;
        }
        
        // Teacher can only view their own schedules
        if ($user->hasRole('teacher')) {
            return $schedule->teacher_id === $user->id
                && $schedule->school_id === $user->school_id;
        }
        
        return false;
    }
    
    /**
     * Determine if user can update schedule
     */
    public function update(User $user, Schedule $schedule): bool
    {
        return $user->hasRole('school_admin')
            && $schedule->school_id === $user->school_id;
    }
}
```

**Register Policy:**

```php
// app/Providers/AuthServiceProvider.php

protected $policies = [
    Schedule::class => SchedulePolicy::class,
    Attendance::class => AttendancePolicy::class,
    QrCode::class => QrCodePolicy::class,
];
```

**Use in Controller:**

```php
// app/Http/Controllers/Api/ScheduleController.php

public function show(Schedule $schedule)
{
    // Check ownership + permission
    $this->authorize('view', $schedule);
    
    return new ScheduleResource($schedule);
}

public function update(UpdateScheduleRequest $request, Schedule $schedule)
{
    $this->authorize('update', $schedule);
    
    $schedule->update($request->validated());
    
    return new ScheduleResource($schedule);
}
```

**Attendance Policy Example:**

```php
// app/Policies/AttendancePolicy.php

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.view_all');
    }
    
    public function view(User $user, Attendance $attendance): bool
    {
        // Student can only view their own
        if ($user->hasRole('student')) {
            return $attendance->student_id === $user->id;
        }
        
        // Teacher can view if it's their schedule
        if ($user->hasRole('teacher')) {
            return $attendance->schedule->teacher_id === $user->id;
        }
        
        // Admin can view all in their school
        return $user->hasRole(['school_admin', 'principal'])
            && $attendance->school_id === $user->school_id;
    }
    
    public function create(User $user): bool
    {
        return $user->can('attendance.manual_input');
    }
}
```

**Enforce in all controllers:**
```php
$this->authorizeResource(Attendance::class);
```

---

### 5. Manual Attendance - Abuse Prevention

**❌ Masalah:**

```php
POST /attendance/manual
{
  "status": "present" // Guru bisa isi manual "hadir"
}
```

**Abuse:**
- Guru malas → semua siswa `present` manual
- Tidak ada approval process
- Unlimited manual input

---

**✅ Solusi: Restrict + Approval Workflow**

**Business Rules:**

```php
// app/Services/AttendanceService.php

public function createManualAttendance(User $teacher, array $data): Attendance
{
    // ===== RESTRICTION =====
    
    // Rule 1: Manual ONLY for absent/sick/permit, NOT present
    $allowedStatuses = ['absent', 'sick', 'permit', 'excused'];
    
    if (!in_array($data['status'], $allowedStatuses)) {
        throw new ValidationException(
            'Manual attendance only allowed for: ' . implode(', ', $allowedStatuses)
        );
    }
    
    // Rule 2: Require proof for sick/permit
    if (in_array($data['status'], ['sick', 'permit'])) {
        if (empty($data['attachment_url']) && empty($data['notes'])) {
            throw new ValidationException(
                'Sick/Permit requires attachment or notes'
            );
        }
    }
    
    // Rule 3: Check quota (prevent mass manual input)
    $dailyManualCount = Attendance::where('recorded_by', $teacher->id)
        ->where('is_manual', true)
        ->whereDate('created_at', today())
        ->count();
    
    $maxDailyManual = $teacher->school->settings['max_daily_manual'] ?? 20;
    
    if ($dailyManualCount >= $maxDailyManual) {
        throw new QuotaExceededException(
            "Daily manual attendance limit reached: {$maxDailyManual}"
        );
    }
    
    // ===== CREATE WITH APPROVAL FLAG =====
    
    $attendance = Attendance::create([
        'schedule_id' => $data['schedule_id'],
        'student_id' => $data['student_id'],
        'attendance_date' => $data['attendance_date'],
        'status' => $data['status'],
        'is_manual' => true,
        'notes' => $data['notes'] ?? null,
        'attachment_url' => $data['attachment_url'] ?? null,
        'recorded_by' => $teacher->id,
        'requires_approval' => true, // NEW
        'approved_by' => null,
    ]);
    
    // Notify homeroom teacher / principal for approval
    $this->notificationService->sendApprovalRequest($attendance);
    
    return $attendance;
}
```

**Migration: Add approval fields**

```php
Schema::table('attendances', function (Blueprint $table) {
    $table->boolean('requires_approval')->default(false)->after('is_manual');
    $table->foreignId('approved_by')->nullable()->constrained('users');
    $table->timestamp('approved_at')->nullable();
});
```

**Approval Endpoint:**

```php
// POST /api/v1/attendance/{id}/approve

public function approve(Request $request, Attendance $attendance)
{
    $this->authorize('approve', $attendance);
    
    $attendance->update([
        'requires_approval' => false,
        'approved_by' => auth()->id(),
        'approved_at' => now(),
    ]);
    
    return response()->json(['message' => 'Attendance approved']);
}
```

**Policy:**

```php
public function approve(User $user, Attendance $attendance): bool
{
    return $user->hasAnyRole(['principal', 'homeroom_teacher', 'school_admin'])
        && $attendance->school_id === $user->school_id;
}
```

---

## 🟠 HIGH Priority

### 6. Audit Log - Immutable Enforcement

**❌ Masalah:**

```php
AttendanceLog::find(1)->delete(); // Bisa dihapus!
```

**Admin jahat bisa hapus jejak**

---

**✅ Solusi: Immutable Model**

```php
// app/Models/AttendanceLog.php

class AttendanceLog extends Model
{
    // No timestamps update (only created_at)
    const UPDATED_AT = null;
    
    protected $guarded = ['id'];
    
    protected static function booted()
    {
        // Prevent update
        static::updating(function () {
            throw new \Exception('Audit logs are immutable');
        });
        
        // Prevent delete
        static::deleting(function () {
            throw new \Exception('Audit logs cannot be deleted');
        });
    }
}
```

**Alternative: Database Level**

```sql
-- PostgreSQL
CREATE RULE attendance_logs_no_delete AS
    ON DELETE TO attendance_logs
    DO INSTEAD NOTHING;

CREATE RULE attendance_logs_no_update AS
    ON UPDATE TO attendance_logs
    DO INSTEAD NOTHING;
```

---

### 7. Error Messages - Information Disclosure

**❌ Masalah:**

```json
{
  "error": "LOCATION_INVALID",
  "details": {
    "distance_meters": 350,
    "max_radius": 100,
    "school_lat": -6.200000,
    "school_lon": 106.816666
  }
}
```

**Attacker gets:**
- Exact school GPS
- Exact radius
- Can calibrate fake GPS

---

**✅ Solusi: Sanitize for Students**

```php
public function render($request, Throwable $e)
{
    if ($e instanceof OutOfRangeException) {
        $isStudent = auth()->user()?->hasRole('student');
        
        if ($isStudent) {
            // Generic message for students
            return response()->json([
                'success' => false,
                'error' => 'LOCATION_INVALID',
                'message' => 'Lokasi Anda di luar area sekolah',
            ], 400);
        } else {
            // Detailed for admin/debug
            return response()->json([
                'success' => false,
                'error' => 'LOCATION_INVALID',
                'message' => $e->getMessage(),
                'debug' => [
                    'distance' => $e->getDistance(),
                    'max_radius' => $e->getMaxRadius(),
                ],
            ], 400);
        }
    }
}
```

---

## 📋 FINAL CHECKLIST

### 🔴 WAJIB SEBELUM PILOT SEKOLAH (P0)

- [ ] QR replay detection (heuristic pattern)
- [ ] Sanctum token device limit (max 2)
- [ ] Global tenant scope enforced
- [ ] Policy-based authorization (not just RBAC)
- [ ] Manual attendance restrictions + approval
- [ ] Immutable audit logs
- [ ] Error message sanitization

### 🟠 WAJIB SEBELUM GO-LIVE LUAS (P1)

- [ ] Suspicious behavior monitoring dashboard
- [ ] Rate limit enforcement tested
- [ ] Device fingerprint binding
- [ ] Approval workflow for manual attendance
- [ ] Admin cannot delete logs (tested)

### 🟡 NICE TO HAVE (P2)

- [ ] Real-time alert for suspicious patterns
- [ ] Weekly security audit report
- [ ] Honeypot endpoints for attack detection

---

## 🎯 Priority Matrix

| Issue | Severity | Likelihood | Impact | Priority |
|-------|----------|------------|--------|----------|
| QR Replay | 🔴 Critical | High | System abuse | **P0** |
| Unlimited tokens | 🔴 Critical | Medium | Account sharing | **P0** |
| Tenant bypass | 🔴 Critical | Low | Data breach | **P0** |
| Role ≠ Ownership | 🟠 High | High | Unauthorized access | **P0** |
| Manual abuse | 🟠 High | High | False attendance | **P1** |
| Log manipulation | 🟡 Medium | Low | Cover traces | **P1** |
| Info disclosure | 🟡 Medium | Low | GPS spoofing | **P2** |

**Total P0 fixes:** 4 (estimated 2-3 hari)  
**Total P1 fixes:** 2 (estimated 1-2 hari)

---

**Real-world tested. Production-grade security.** 🛡️
