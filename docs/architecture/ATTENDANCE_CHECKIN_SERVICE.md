# Attendance Check-In - Clean Architecture Refactor

## Overview

This document describes the refactored attendance check-in system following clean architecture principles where:

- **Controllers**: Handle HTTP request/response only
- **Services**: Contain all business logic
- **Exceptions**: For rule violations
- **DTOs**: For structured data transfer

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        HTTP REQUEST                              │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                    AttendanceController                          │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Responsibilities:                                            ││
│  │ ✓ Validate request (via AttendanceScanRequest)              ││
│  │ ✓ Call service                                               ││
│  │ ✓ Return JSON response                                       ││
│  │ ✗ NO business logic                                          ││
│  │ ✗ NO direct DB access                                        ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                  AttendanceCheckInService                        │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Responsibilities:                                            ││
│  │ ✓ Validate student exists and is active                     ││
│  │ ✓ Check active schedule                                      ││
│  │ ✓ Prevent duplicate attendance                               ││
│  │ ✓ Validate geofence/location                                 ││
│  │ ✓ Validate time window                                       ││
│  │ ✓ Store attendance (with DB transaction)                    ││
│  │ ✓ Return structured result (AttendanceResult)               ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               │
                    ┌──────────┴──────────┐
                    ▼                      ▼
┌──────────────────────────┐  ┌──────────────────────────┐
│   AttendanceException    │  │    AttendanceResult      │
│  ┌────────────────────┐  │  │  ┌────────────────────┐  │
│  │ Static factories:  │  │  │  │ DTO Properties:    │  │
│  │ - studentNotFound()│  │  │  │ - success          │  │
│  │ - alreadyRecorded()│  │  │  │ - attendance       │  │
│  │ - noActiveSchedule()│ │  │  │ - message          │  │
│  │ - deviceMismatch() │  │  │  │ - status           │  │
│  └────────────────────┘  │  │  └────────────────────┘  │
└──────────────────────────┘  └──────────────────────────┘
```

---

## Files Created/Modified

| File | Type | Description |
|------|------|-------------|
| `app/Services/AttendanceCheckInService.php` | **NEW** | Service layer with all business logic |
| `app/Services/AttendanceResult.php` | **NEW** | DTO for structured response |
| `app/Exceptions/AttendanceException.php` | **MODIFIED** | Added new static factory methods |
| `app/Http/Controllers/Api/V1/AttendanceController.php` | **MODIFIED** | Refactored to thin controller |

---

## AttendanceCheckInService Methods

### `checkIn(User $student, array $data): AttendanceResult`

Main entry point for student check-in.

**Parameters:**
```php
$data = [
    'qr_token' => 'string',      // Required: QR token from scan
    'lat' => 'float|null',       // Optional: Latitude
    'lng' => 'float|null',       // Optional: Longitude
    'device_id' => 'string|null',// Optional: Device identifier
    'request_id' => 'string',    // Optional: Idempotency key
];
```

**Returns:** `AttendanceResult`

**Throws:** `AttendanceException` on business rule violations

---

## Exception Types

| Exception Factory | When Thrown |
|-------------------|-------------|
| `AttendanceException::studentNotFound()` | Student inactive or not found |
| `AttendanceException::invalidRole()` | Non-student attempting check-in |
| `AttendanceException::invalidQrCode()` | QR token validation failed |
| `AttendanceException::noActiveSchedule()` | No schedule for today |
| `AttendanceException::deviceMismatch()` | Different device than registered |
| `AttendanceException::outsideRadius()` | Outside school geofence |
| `AttendanceException::outsideTimeWindow()` | Class has ended |
| `AttendanceException::alreadyRecorded()` | Duplicate attendance attempt |

---

## Example JSON Responses

### ✅ Success Response (201 Created)

```json
{
    "success": true,
    "message": "Absensi berhasil dicatat.",
    "data": {
        "attendance": {
            "id": 12345,
            "status": "present",
            "check_in_time": "07:45:30",
            "attendance_date": "2026-01-31",
            "schedule_id": 42,
            "student_id": 789
        }
    },
    "errors": []
}
```

### ✅ Success - Late Status (201 Created)

```json
{
    "success": true,
    "message": "Absensi berhasil dicatat.",
    "data": {
        "attendance": {
            "id": 12346,
            "status": "late",
            "check_in_time": "08:20:15",
            "attendance_date": "2026-01-31",
            "schedule_id": 42,
            "student_id": 789
        }
    },
    "errors": []
}
```

### ❌ Already Checked In (400 Bad Request)

```json
{
    "success": false,
    "message": "Absensi sudah dicatat sebelumnya.",
    "data": []
}
```

### ❌ No Active Schedule (400 Bad Request)

```json
{
    "success": false,
    "message": "Tidak ada jadwal aktif untuk hari ini.",
    "data": []
}
```

### ❌ Invalid Student (400 Bad Request)

```json
{
    "success": false,
    "message": "Siswa tidak ditemukan atau tidak aktif.",
    "data": []
}
```

### ❌ Outside Time Window (400 Bad Request)

```json
{
    "success": false,
    "message": "Absensi belum dibuka. Silakan scan mulai pukul 07:00.",
    "data": []
}
```

### ❌ Device Mismatch (400 Bad Request)

```json
{
    "success": false,
    "message": "Perangkat tidak dikenali. Harap gunakan HP Anda sendiri yang terdaftar.",
    "data": []
}
```

### ❌ Outside Geofence (400 Bad Request)

```json
{
    "success": false,
    "message": "Lokasi di luar radius yang diizinkan.",
    "data": []
}
```

### ❌ Invalid QR Code (400 Bad Request)

```json
{
    "success": false,
    "message": "QR Code tidak valid atau sudah kadaluarsa.",
    "data": []
}
```

### ❌ Server Error (500 Internal Server Error)

```json
{
    "success": false,
    "message": "Terjadi kesalahan saat memproses absensi.",
    "data": []
}
```

---

## Controller Code (Refactored)

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceException;
use App\Services\AttendanceCheckInService;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceCheckInService $checkInService
    ) {}

    /**
     * CLEAN ARCHITECTURE:
     * Controller responsibilities:
     * 1. Validate request
     * 2. Call service
     * 3. Return JSON response
     */
    public function scan(AttendanceScanRequest $request): JsonResponse
    {
        try {
            // 1. Get authenticated user
            $student = $request->user();

            // 2. Prepare scan data (simple mapping only)
            $scanData = [
                'qr_token' => $request->input('token'),
                'lat' => $request->input('latitude'),
                'lng' => $request->input('longitude'),
                'device_id' => $request->input('device_info.device_id'),
                'request_id' => $request->input('request_id') ?? Str::uuid(),
            ];

            // 3. Delegate to service (ALL business logic here)
            $result = $this->checkInService->checkIn($student, $scanData);

            // 4. Return response
            return response()->json($result->toArray(), $result->getHttpStatusCode());

        } catch (AttendanceException $e) {
            // Business logic exceptions - safe to show
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ], 400);

        } catch (\Exception $e) {
            // Unexpected errors - log and generic message
            Log::error('Attendance scan failed', [...]);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses absensi.',
            ], 500);
        }
    }
}
```

---

## Service Code (Business Logic)

```php
<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

final class AttendanceCheckInService
{
    public function checkIn(User $student, array $data): AttendanceResult
    {
        // STEP 1: Validate student
        $this->validateStudent($student);

        // STEP 2: Validate QR token
        $payload = $this->validateQrToken($data['qr_token']);

        // STEP 3: Find active schedule
        $schedule = $this->findActiveSchedule($payload['id'], $student->school_id);

        // STEP 4: Validate device (anti-joki)
        $this->validateDevice($student, $data['device_id']);

        // STEP 5: Validate geofence
        $this->validateLocation($student->school, $data['lat'], $data['lng']);

        // STEP 6: Validate time window
        $status = $this->validateTimeWindow($schedule, $student->school);

        // STEP 7: Atomic check-in with DB transaction
        $attendance = $this->atomicCheckIn($student, $schedule, $status, $data);

        return AttendanceResult::success($attendance, $status);
    }

    private function atomicCheckIn(...): Attendance
    {
        return Cache::lock("attendance_{$studentId}", 5)->block(3, function () {
            return DB::transaction(function () {
                // Check duplicate with row lock
                // Create attendance record
                // Return result
            });
        });
    }
}
```

---

## Usage Example

```php
// In a test or another service
$service = app(AttendanceCheckInService::class);

try {
    $result = $service->checkIn($student, [
        'qr_token' => 'abc123...',
        'lat' => -6.2088,
        'lng' => 106.8456,
        'device_id' => 'device-uuid',
    ]);

    if ($result->isSuccessful()) {
        echo "Check-in successful: " . $result->attendance->id;
    }
} catch (AttendanceException $e) {
    echo "Business error: " . $e->getMessage();
}
```
