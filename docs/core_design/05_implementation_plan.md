# Implementation Plan - Sistem Absensi QR Code

## Goal

Membangun sistem absensi berbasis QR Code untuk SD-SMK menggunakan Laravel 12 sebagai REST API backend dan Mobile App (Flutter) sebagai client. Sistem ini harus scalable, aman, dan mendukung multi-tenant (sekolah).

## Proposed Changes

### Phase 1: Backend API Setup

#### [NEW] Laravel 12 Project Structure

**Location:** `d:/Project/absensiQRPro/backend`

- Inisialisasi Laravel 12 project
- Setup PostgreSQL database connection
- Install dependencies:
  - `spatie/laravel-permission` (RBAC)
  - `simplesoftwareio/simple-qrcode` (QR generation)
  - `predis/predis` (Redis cache)
  - `laravel/sanctum` (API authentication)
  - `maatwebsite/excel` (Export reports)

**Config changes:**
- `config/database.php` - PostgreSQL settings
- `config/sanctum.php` - API token configuration
- `config/cache.php` - Redis cache driver

---

### Phase 2: Database Implementation

#### [NEW] Migration Files

**Location:** `database/migrations/`

Implement migrations sesuai `database_schema.md`:

1. `2024_01_01_000001_create_schools_table.php`
2. `2024_01_01_000002_create_users_table.php`
3. `2024_01_01_000003_create_user_profiles_table.php`
4. `2024_01_01_000004_create_academic_years_table.php`
5. `2024_01_01_000005_create_subjects_table.php`
6. `2024_01_01_000006_create_classes_table.php`
7. `2024_01_01_000007_create_class_students_table.php`
8. `2024_01_01_000008_create_schedules_table.php`
9. `2024_01_01_000009_create_qr_codes_table.php`
10. `2024_01_01_000010_create_attendances_table.php`
11. `2024_01_01_000011_create_attendance_logs_table.php`
12. `2024_01_01_000012_create_attendance_reports_table.php`

**Indexes yang wajib:**
- Composite index pada `attendances(school_id, attendance_date, status)`
- Unique index pada `qr_codes(token)`
- Index pada `users(school_id, is_active)`

#### [NEW] Seeders

**Location:** `database/seeders/`

- `RolePermissionSeeder.php` - Seed 9 roles & permissions
- `SchoolSeeder.php` - Sample schools data
- `UserSeeder.php` - Admin, guru, siswa test accounts
- `AcademicYearSeeder.php` - Tahun ajaran aktif
- `DevelopmentSeeder.php` - Complete test data

---

### Phase 3: Core Models & Repositories

#### [NEW] Eloquent Models

**Location:** `app/Models/`

- `School.php`
- `User.php` (extends Authenticatable, HasRoles)
- `UserProfile.php`
- `AcademicYear.php`
- `Subject.php`
- `ClassModel.php`
- `Schedule.php`
- `QrCode.php`
- `Attendance.php`
- `AttendanceLog.php`
- `AttendanceReport.php`

**Key features:**
- Global scope untuk `school_id` filtering
- Relationships (hasMany, belongsTo, belongsToMany)
- Mutators & Accessors (e.g., encrypted QR token)
- Soft deletes where applicable

#### [NEW] Repository Pattern

**Location:** `app/Repositories/`

- `AttendanceRepository.php`
- `QrCodeRepository.php`
- `UserRepository.php`
- `ScheduleRepository.php`

Implementasi CRUD operations dengan caching.

---

### Phase 4: Core Services

#### [NEW] QR Service

**Location:** `app/Services/QrService.php`

**Methods:**
- `generateQrCode(Schedule $schedule, array $options): QrCode`
  - Generate encrypted token
  - Create QR image (PNG)
  - Store to MinIO/S3
  - Save metadata to DB
  
- `validateQrToken(string $token): array`
  - Decrypt & verify token
  - Check expiration
  - Check max_scans limit
  
- `deactivateQrCode(int $qrId): bool`

**Encryption:**
```php
// Token format: AES-256-CBC encrypted
$payload = [
    'schedule_id' => $scheduleId,
    'timestamp' => time(),
    'nonce' => Str::random(16),
];
$token = encrypt(json_encode($payload));
```

#### [NEW] Attendance Service

**Location:** `app/Services/AttendanceService.php`

**Methods:**
- `scanQrCode(User $student, string $token, array $location): Attendance`
  - Validate QR via QrService
  - Verify GPS location
  - Check duplicate scan
  - Create attendance record
  - Log to attendance_logs
  - Send notification
  
- `createManualAttendance(User $teacher, array $data): Attendance`
  - Validate permission
  - Create manual record
  - Audit log
  
- `getClassAttendance(Schedule $schedule, Carbon $date): Collection`
- `getStudentSummary(User $student, Carbon $start, Carbon $end): array`

#### [NEW] Location Service

**Location:** `app/Services/LocationService.php`

**Methods:**
- `validateLocation(float $lat, float $lon, School $school): bool`
  - Calculate Haversine distance
  - Check within radius
  
```php
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // meters
    // Implementation
}
```

#### [NEW] Notification Service

**Location:** `app/Services/NotificationService.php`

**Methods:**
- `sendAttendanceConfirmation(Attendance $attendance): void`
- `sendDailySummary(School $school, Carbon $date): void`
- Firebase Cloud Messaging integration

---

### Phase 5: API Controllers

#### [NEW] Authentication Controller

**Location:** `app/Http/Controllers/Api/AuthController.php`

**Endpoints:**
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

#### [NEW] Attendance Controller

**Location:** `app/Http/Controllers/Api/AttendanceController.php`

**Endpoints:**
- `POST /api/v1/attendance/scan`
- `POST /api/v1/attendance/manual`
- `GET /api/v1/attendance/schedule/{id}`
- `GET /api/v1/attendance/student/{id}`

**Validation:**
```php
// ScanQrRequest validation
'qr_token' => 'required|string',
'latitude' => 'required|numeric|between:-90,90',
'longitude' => 'required|numeric|between:-180,180',
'device_info' => 'required|array',
```

#### [NEW] QR Controller

**Location:** `app/Http/Controllers/Api/QrController.php`

**Endpoints:**
- `POST /api/v1/qr/generate`
- `GET /api/v1/qr/{id}`
- `DELETE /api/v1/qr/{id}`

#### [NEW] Report Controller

**Location:** `app/Http/Controllers/Api/ReportController.php`

**Endpoints:**
- `GET /api/v1/reports/daily`
- `GET /api/v1/reports/student/{id}/summary`
- `POST /api/v1/reports/export`

---

### Phase 6: Middleware & Security

#### [NEW] Middleware

**Location:** `app/Http/Middleware/`

- `TenantScope.php` - Auto-filter by school_id
- `CheckPermission.php` - RBAC validation
- `ApiRateLimit.php` - Custom rate limiting
- `LogApiRequest.php` - Audit trail

**Usage:**
```php
Route::middleware(['auth:sanctum', 'tenant', 'permission:attendance.scan'])
    ->post('/attendance/scan', [AttendanceController::class, 'scan']);
```

#### [MODIFY] app/Http/Kernel.php

Add custom middleware to `$middlewareGroups['api']`:
```php
'api' => [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'throttle:api',
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\TenantScope::class, // NEW
    \App\Http\Middleware\LogApiRequest::class, // NEW
],
```

---

### Phase 7: Routes

#### [NEW] routes/api.php

```php
Route::prefix('v1')->group(function () {
    // Public
    Route::post('/auth/login', [AuthController::class, 'login']);
    
    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        
        // Attendance
        Route::prefix('attendance')->group(function () {
            Route::post('/scan', [AttendanceController::class, 'scan'])
                ->middleware('permission:attendance.scan');
            Route::post('/manual', [AttendanceController::class, 'manual'])
                ->middleware('permission:attendance.manual_input');
            // ... more routes
        });
        
        // QR
        Route::prefix('qr')->middleware('permission:school.qr_generate')->group(function () {
            Route::post('/generate', [QrController::class, 'generate']);
            // ...
        });
        
        // Reports
        Route::prefix('reports')->middleware('permission:reports.view_all')->group(function () {
            // ...
        });
    });
});
```

---

### Phase 8: Mobile App (Flutter)

#### [NEW] Flutter Project

**Location:** `d:/Project/absensiQRPro/mobile`

**Structure:**
```
lib/
├── main.dart
├── app/
│   ├── routes/
│   └── theme/
├── core/
│   ├── api/
│   │   └── api_client.dart
│   ├── constants/
│   └── utils/
├── features/
│   ├── auth/
│   │   ├── presentation/
│   │   ├── domain/
│   │   └── data/
│   ├── attendance/
│   │   ├── presentation/
│   │   │   ├── pages/scan_qr_page.dart
│   │   │   └── widgets/
│   │   └── data/
│   └── profile/
└── shared/
    └── widgets/
```

**Key packages:**
```yaml
dependencies:
  flutter_bloc: ^8.1.3
  dio: ^5.3.3
  qr_code_scanner: ^1.0.1
  geolocator: ^10.1.0
  firebase_messaging: ^14.6.9
  shared_preferences: ^2.2.2
  get_it: ^7.6.4
```

**Core features:**
- QR Scanner dengan kamera
- GPS location capture
- Push notification handler
- Offline-first dengan local storage
- State management: BLoC pattern

---

## Security Considerations

### QR Code Encryption

```php
// QrService.php
private function generateToken(int $scheduleId): string {
    $payload = [
        'schedule_id' => $scheduleId,
        'timestamp' => now()->timestamp,
        'nonce' => Str::random(16),
        'version' => 'v1',
    ];
    
    return encrypt(json_encode($payload));
}
```

### Location Verification

```php
// LocationService.php
public function validateLocation(float $userLat, float $userLon, School $school): bool {
    $distance = $this->haversineDistance(
        $userLat, $userLon,
        $school->latitude, $school->longitude
    );
    
    return $distance <= $school->radius_meters;
}
```

### Rate Limiting

- API: 60 requests/minute per user
- QR Scan: 10 scans/minute per student
- Login attempts: 5 per 5 minutes

---

## Verification Plan

### 1. Automated Tests

#### Unit Tests

**Location:** `tests/Unit/Services/`

- `QrServiceTest.php`
  - ✅ `test_generate_qr_code_success()`
  - ✅ `test_validate_qr_token_success()`
  - ✅ `test_validate_expired_qr_token_fails()`
  - ✅ `test_validate_invalid_token_fails()`

- `AttendanceServiceTest.php`
  - ✅ `test_scan_qr_code_success()`
  - ✅ `test_scan_duplicate_fails()`
  - ✅ `test_manual_attendance_without_permission_fails()`

- `LocationServiceTest.php`
  - ✅ `test_validate_location_within_radius()`
  - ✅ `test_validate_location_outside_radius()`

**Run command:**
```bash
cd backend
php artisan test --filter Services
```

#### Feature Tests

**Location:** `tests/Feature/Api/`

- `AuthenticationTest.php`
  - ✅ `test_user_can_login_with_valid_credentials()`
  - ✅ `test_user_cannot_login_with_invalid_credentials()`
  - ✅ `test_user_can_logout()`

- `AttendanceTest.php`
  - ✅ `test_student_can_scan_qr_code()`
  - ✅ `test_student_cannot_scan_expired_qr()`
  - ✅ `test_student_cannot_scan_from_wrong_location()`
  - ✅ `test_teacher_can_create_manual_attendance()`

**Run command:**
```bash
cd backend
php artisan test --filter Feature/Api
```

### 2. Integration Tests

#### API Integration

**Using Postman Collection:**

Create collection: `AbsensiQR.postman_collection.json`

Test scenarios:
1. **Login Flow**
   - POST /api/v1/auth/login → Get token
   - GET /api/v1/auth/me → Verify user data

2. **QR Generation & Scan Flow**
   - POST /api/v1/qr/generate → Create QR
   - POST /api/v1/attendance/scan (with valid QR) → Success
   - POST /api/v1/attendance/scan (duplicate) → Error 409

3. **Manual Attendance Flow**
   - POST /api/v1/attendance/manual (as teacher) → Success
   - POST /api/v1/attendance/manual (as student) → Error 403

4. **Reports Flow**
   - GET /api/v1/reports/daily → Get daily report
   - POST /api/v1/reports/export → Export PDF

**Run command:**
```bash
newman run AbsensiQR.postman_collection.json --environment production.json
```

### 3. Database Verification

**Verify migrations:**
```bash
cd backend
php artisan migrate:fresh --seed
php artisan db:show
php artisan db:table attendances
```

**Verify indexes:**
```sql
SELECT indexname, indexdef 
FROM pg_indexes 
WHERE tablename IN ('attendances', 'qr_codes', 'users');
```

### 4. Manual Verification

> [!IMPORTANT]
> The following manual tests require physical devices and GPS access.

#### Mobile App Testing

**Prerequisites:**
- Android/iOS device dengan GPS enabled
- Berada di lokasi sekolah test (atau mock GPS untuk dev)

**Test steps:**

1. **Login Test**
   - Launch mobile app
   - Login sebagai siswa (username: `siswa001`, password: `password`)
   - ✅ Verify: Dashboard muncul dengan jadwal hari ini

2. **QR Scan Test**
   - Dari web admin, generate QR untuk jadwal aktif
   - Di mobile app, tap "Scan Absensi"
   - Scan QR code yang di-generate
   - ✅ Verify: Muncul konfirmasi "Absensi berhasil"
   - ✅ Verify: Status berubah di dashboard web

3. **Location Validation Test**
   - Generate QR baru
   - Mock GPS ke lokasi di luar radius sekolah (>100m)
   - Scan QR code
   - ✅ Verify: Error "Lokasi tidak valid"

4. **Duplicate Scan Test**
   - Scan QR code yang sama lagi
   - ✅ Verify: Error "Sudah absen sebelumnya"

5. **Push Notification Test**
   - Setelah scan sukses
   - ✅ Verify: Muncul push notification konfirmasi

#### Web Admin Testing

**Test steps:**

1. Login sebagai `school_admin`
2. Generate QR untuk kelas VII-A, Matematika
3. ✅ Verify: QR image tampil dan bisa di-download
4. View realtime attendance untuk kelas tersebut
5. ✅ Verify: Siswa yang scan QR muncul di list
6. Export laporan harian ke PDF
7. ✅ Verify: PDF ter-download dengan data benar

---

## Deployment Checklist

- [ ] Setup PostgreSQL production database
- [ ] Configure Redis cache server
- [ ] Setup MinIO/S3 for file storage
- [ ] Configure Laravel `.env` production
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Seed initial data: `php artisan db:seed --class=ProductionSeeder`
- [ ] Setup Nginx/Apache with SSL
- [ ] Configure Firebase Cloud Messaging
- [ ] Build Flutter APK/AAB for production
- [ ] Setup monitoring (Laravel Telescope, Sentry)
- [ ] Configure backup strategy (daily DB dumps)

---

## Next Steps After Approval

1. Initialize Laravel 12 project
2. Setup database & run migrations
3. Implement core services (QR, Attendance, Location)
4. Build API controllers & routes
5. Write & run unit tests
6. Create Flutter mobile app
7. Integration testing
8. Deploy to staging
9. User acceptance testing
10. Production deployment
