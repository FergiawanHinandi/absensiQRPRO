# Service Layer Consistency - Clean Architecture

## 🏗️ Overview
Dokumen ini menjelaskan refactoring business logic dari controller ke service layer untuk mencapai clean architecture dan separation of concerns.

## ❌ Masalah: Business Logic di Controller

### Kode Lama (Anti-Pattern)
```php
// AttendanceController.php
public function manual(ManualAttendanceRequest $request)
{
    $validated = $request->validated();

    // ❌ BUSINESS LOGIC IN CONTROLLER
    $attendance = \App\Models\Attendance::updateOrCreate(
        [
            'schedule_id' => $validated['schedule_id'],
            'student_id' => $validated['student_id'],
            'attendance_date' => $validated['attendance_date'],
        ],
        [
            'school_id' => $request->user()->school_id,
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'is_manual' => true,
            'recorded_by' => $request->user()->id,
        ]
    );

    return response()->json([...]);
}
```

**Masalah**:
- ❌ Business logic tercampur dengan HTTP layer
- ❌ Sulit untuk di-test secara isolated
- ❌ Tidak bisa di-reuse di tempat lain (CLI, Queue, etc)
- ❌ Melanggar Single Responsibility Principle
- ❌ Duplicate logic jika ada endpoint lain yang butuh logic serupa

---

## ✅ Solusi: Service Layer Pattern

### Arsitektur Baru

```
┌─────────────────────────────────────────────────────────┐
│                    HTTP Request                         │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│                  CONTROLLER                             │
│  Responsibilities:                                      │
│  1. Validate Request (FormRequest)                      │
│  2. Validate Ownership (Security)                       │
│  3. Call Service                                        │
│  4. Return Response                                     │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│                  SERVICE LAYER                          │
│  Responsibilities:                                      │
│  1. Business Logic                                      │
│  2. Validation (Business Rules)                         │
│  3. Orchestration                                       │
│  4. Transaction Management                              │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│                  REPOSITORY                             │
│  Responsibilities:                                      │
│  1. Data Access                                         │
│  2. Query Building                                      │
│  3. Caching                                             │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│                  DATABASE                               │
└─────────────────────────────────────────────────────────┘
```

---

## 🔧 Implementasi

### 1. Service Layer

**File**: `app/Core/Services/Attendance/AttendanceService.php`

```php
class AttendanceService
{
    protected $qrCodeService;
    protected $attendanceRepo;

    public function __construct(
        QRCodeService $qrCodeService,
        AttendanceRepositoryInterface $attendanceRepo
    ) {
        $this->qrCodeService = $qrCodeService;
        $this->attendanceRepo = $attendanceRepo;
    }

    /**
     * Process QR scan attendance
     */
    public function processScan(User $student, array $data): Attendance
    {
        // 1. Decrypt & Validate Token
        $payload = $this->qrCodeService->decryptAndValidate($data['qr_token']);
        
        // 2. Validate Device ID (Anti-Joki)
        if ($student->device_id && $student->device_id !== $data['device_id']) {
            throw new Exception('Perangkat tidak dikenali.');
        }
        
        // 3. Geofence Validation
        $school = School::find($student->school_id);
        if ($school && $school->latitude && $school->longitude) {
            $distance = $this->calculateDistance(...);
            if ($distance > $maxRadius) {
                throw new Exception("Anda berada di luar radius sekolah.");
            }
        }
        
        // 4. Duplicate Check
        if ($this->attendanceRepo->hasAttended(...)) {
            throw new Exception('Anda sudah melakukan absensi.');
        }
        
        // 5. Record Attendance
        return $this->attendanceRepo->create([...]);
    }

    /**
     * Process manual attendance (Teacher/Admin only)
     */
    public function manualAttendance(array $data, int $recordedBy): Attendance
    {
        // Check for duplicate
        $exists = $this->attendanceRepo->hasAttended(
            $data['student_id'],
            $data['schedule_id'],
            $data['attendance_date']
        );

        if ($exists) {
            // Update existing
            $attendance = Attendance::where([...])->first();
            $attendance->update([
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_manual' => true,
                'recorded_by' => $recordedBy,
            ]);
            return $attendance;
        }

        // Create new
        return $this->attendanceRepo->create([
            'school_id' => $data['school_id'],
            'schedule_id' => $data['schedule_id'],
            'student_id' => $data['student_id'],
            'attendance_date' => $data['attendance_date'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'is_manual' => true,
            'recorded_by' => $recordedBy,
        ]);
    }
}
```

---

### 2. Controller Layer

**File**: `app/Http/Controllers/Api/V1/AttendanceController.php`

#### **Scan Attendance** (Already Clean ✅)
```php
public function scan(AttendanceScanRequest $request)
{
    try {
        // 1. Validate Request ✅ (FormRequest)
        $scanData = [
            'qr_token' => $request->token,
            'lat' => $request->latitude,
            'lng' => $request->longitude,
            'device_id' => $request->device_id,
            'request_id' => $request->header('X-Request-ID'),
        ];

        // 2. Call Service ✅
        $attendance = $this->attendanceService->processScan(
            $request->user(),
            $scanData
        );

        // 3. Dispatch Event (Optional)
        \App\Events\StudentAttended::dispatch($attendance, ...);

        // 4. Return Response ✅
        return response()->success([
            'attendance' => [
                'id' => $attendance->id,
                'status' => $attendance->status,
                'check_in_time' => $attendance->check_in_time,
            ],
        ], 'Absensi berhasil', 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}
```

#### **Manual Attendance** (Refactored ✅)
```php
public function manual(ManualAttendanceRequest $request)
{
    // 1. Validate Request ✅ (FormRequest)
    $validated = $request->validated();

    // 2. Validate Ownership (Security) ✅
    $student = $this->validateSchoolOwnershipById(
        \App\Models\User::class,
        $validated['student_id'],
        'Siswa tidak ditemukan atau bukan milik sekolah Anda.'
    );

    $schedule = $this->validateSchoolOwnershipById(
        \App\Models\Schedule::class,
        $validated['schedule_id'],
        'Jadwal tidak ditemukan atau bukan milik sekolah Anda.'
    );

    // 3. Call Service ✅
    $attendance = $this->attendanceService->manualAttendance(
        [
            'school_id' => $request->user()->school_id,
            'student_id' => $validated['student_id'],
            'schedule_id' => $validated['schedule_id'],
            'attendance_date' => $validated['attendance_date'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ],
        $request->user()->id
    );

    // 4. Return Response ✅
    return response()->json([
        'success' => true,
        'data' => ['attendance' => $attendance],
        'message' => 'Absensi manual berhasil disimpan',
    ], 201);
}
```

---

## 📊 Perbandingan

### Before (Anti-Pattern)
```php
// Controller
public function manual(Request $request)
{
    // ❌ Validation in controller
    $validated = $request->validate([...]);
    
    // ❌ Business logic in controller
    $attendance = Attendance::updateOrCreate([...], [...]);
    
    return response()->json([...]);
}
```

**Masalah**:
- Controller terlalu gemuk (Fat Controller)
- Business logic tidak reusable
- Sulit untuk di-test
- Tidak bisa dipanggil dari CLI/Queue

### After (Clean Architecture)
```php
// Controller
public function manual(ManualAttendanceRequest $request)
{
    // ✅ Validation via FormRequest
    $validated = $request->validated();
    
    // ✅ Security validation
    $this->validateSchoolOwnership(...);
    
    // ✅ Delegate to service
    $attendance = $this->attendanceService->manualAttendance(...);
    
    // ✅ Return response
    return response()->json([...]);
}

// Service
class AttendanceService
{
    public function manualAttendance(array $data, int $recordedBy): Attendance
    {
        // ✅ Business logic here
        // ✅ Reusable from anywhere
        // ✅ Easy to test
    }
}
```

**Keuntungan**:
- ✅ Thin Controller (hanya HTTP concerns)
- ✅ Business logic reusable
- ✅ Easy to test (mock service)
- ✅ Bisa dipanggil dari CLI/Queue/Event

---

## 🧪 Testing Benefits

### Before (Hard to Test)
```php
// Harus test via HTTP
public function test_manual_attendance()
{
    $response = $this->postJson('/api/v1/attendance/manual', [...]);
    $response->assertStatus(201);
}
```

**Masalah**:
- Harus setup HTTP request
- Harus setup authentication
- Slow (full stack test)
- Sulit isolate business logic

### After (Easy to Test)
```php
// Test service secara isolated
public function test_manual_attendance_service()
{
    $service = new AttendanceService($qrService, $repo);
    
    $attendance = $service->manualAttendance([...], 1);
    
    $this->assertEquals('present', $attendance->status);
    $this->assertTrue($attendance->is_manual);
}

// Test controller secara isolated (mock service)
public function test_manual_attendance_controller()
{
    $this->mock(AttendanceService::class, function ($mock) {
        $mock->shouldReceive('manualAttendance')
             ->once()
             ->andReturn(new Attendance([...]));
    });
    
    $response = $this->postJson('/api/v1/attendance/manual', [...]);
    $response->assertStatus(201);
}
```

**Keuntungan**:
- ✅ Fast (unit test)
- ✅ Isolated (mock dependencies)
- ✅ Easy to debug
- ✅ Better coverage

---

## 🎯 Controller Responsibilities

### ✅ DO (Controller Should)
1. **Validate Request** via FormRequest
2. **Validate Ownership** via ValidatesSchoolOwnership trait
3. **Call Service** with prepared data
4. **Return Response** in consistent format
5. **Handle HTTP Errors** (try-catch)
6. **Dispatch Events** (optional, for side effects)

### ❌ DON'T (Controller Should NOT)
1. ❌ Direct database queries
2. ❌ Business logic
3. ❌ Complex calculations
4. ❌ Data transformation (use Resources)
5. ❌ Transaction management
6. ❌ External API calls

---

## 🎯 Service Responsibilities

### ✅ DO (Service Should)
1. **Business Logic** (core domain logic)
2. **Validation** (business rules)
3. **Orchestration** (coordinate multiple operations)
4. **Transaction Management** (DB::transaction)
5. **External API Calls** (if needed)
6. **Complex Calculations**

### ❌ DON'T (Service Should NOT)
1. ❌ HTTP concerns (request/response)
2. ❌ Direct $_GET/$_POST access
3. ❌ Session management
4. ❌ Cookie handling
5. ❌ Redirect logic

---

## 📝 Best Practices

### 1. Dependency Injection
```php
// ✅ Good
class AttendanceService
{
    public function __construct(
        QRCodeService $qrService,
        AttendanceRepositoryInterface $repo
    ) {
        $this->qrService = $qrService;
        $this->repo = $repo;
    }
}

// ❌ Bad
class AttendanceService
{
    public function processScan()
    {
        $qrService = new QRCodeService(); // Hard dependency
    }
}
```

### 2. Return Types
```php
// ✅ Good
public function manualAttendance(array $data, int $recordedBy): Attendance
{
    return $this->repo->create([...]);
}

// ❌ Bad
public function manualAttendance($data, $recordedBy)
{
    return $this->repo->create([...]);
}
```

### 3. Exception Handling
```php
// ✅ Good (Service throws, Controller catches)
// Service
public function processScan(User $student, array $data): Attendance
{
    if ($distance > $maxRadius) {
        throw new GeofenceException("Di luar radius sekolah");
    }
}

// Controller
try {
    $attendance = $this->service->processScan(...);
} catch (GeofenceException $e) {
    return response()->json(['message' => $e->getMessage()], 400);
}

// ❌ Bad (Service returns response)
public function processScan(User $student, array $data)
{
    if ($distance > $maxRadius) {
        return response()->json([...], 400); // HTTP in service!
    }
}
```

---

## 🔗 Related Files

- `app/Core/Services/Attendance/AttendanceService.php` - Service layer
- `app/Http/Controllers/Api/V1/AttendanceController.php` - Controller layer
- `app/Core/Domain/Repositories/AttendanceRepositoryInterface.php` - Repository interface
- `app/Core/Infrastructure/Repositories/AttendanceRepository.php` - Repository implementation

---

## 📅 Changelog

### 2026-01-27
- ✅ Added `manualAttendance()` method to AttendanceService
- ✅ Refactored `manual()` controller to use service layer
- ✅ Removed business logic from controller
- ✅ Improved separation of concerns
- ✅ Made business logic reusable and testable
