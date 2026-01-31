# Idempotent Attendance API

## Overview

The attendance scan endpoint supports **idempotency** via a client-provided `request_id`. This enables safe retries and offline sync without creating duplicate attendance records.

---

## How It Works

```
┌─────────────────────────────────────────────────────────────────────┐
│                    IDEMPOTENCY FLOW                                 │
└─────────────────────────────────────────────────────────────────────┘

  POST /api/v1/attendance/scan
  {
    "qr_token": "...",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"  ← Client-generated UUID
  }
        │
        ▼
  ┌─────────────────────────────────────────────┐
  │ 1. Check: Does request_id exist in DB?      │
  └──────────────────────┬──────────────────────┘
                         │
              ┌──────────┴──────────┐
             YES                    NO
              │                      │
              ▼                      ▼
  ┌─────────────────────┐  ┌─────────────────────┐
  │ Return existing     │  │ Run full validation │
  │ attendance record   │  │ and create new      │
  │ (HTTP 200)          │  │ attendance (HTTP 201)|
  └─────────────────────┘  └─────────────────────┘
```

---

## Database Schema

### Migration (Already Applied)

```php
// database/migrations/2026_01_24_060611_add_request_id_to_attendances_table.php
public function up(): void
{
    Schema::table('attendances', function (Blueprint $table) {
        $table->uuid('request_id')->nullable()->after('device_id_in');
        $table->unique('request_id', 'attendances_request_id_unique');
    });
}
```

### Index

The `request_id` column has a unique index for:
1. Fast lookups during idempotency check
2. Guaranteed uniqueness as a fallback

---

## API Usage

### Request

```http
POST /api/v1/attendance/scan
Authorization: Bearer {student_token}
Content-Type: application/json

{
  "qr_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJI...",
  "device_id": "abc-device-123",
  "lat": -6.2088,
  "lng": 106.8456,
  "request_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### Response: New Attendance (201 Created)

```json
{
  "success": true,
  "message": "Absensi berhasil dicatat.",
  "data": {
    "attendance": {
      "id": 12345,
      "status": "present",
      "check_in_time": "08:05:23",
      "attendance_date": "2026-01-31",
      "schedule_id": 456,
      "student_id": 100,
      "request_id": "550e8400-e29b-41d4-a716-446655440000"
    }
  }
}
```

### Response: Idempotent Retry (200 OK)

```json
{
  "success": true,
  "message": "Absensi sudah tercatat sebelumnya.",
  "data": {
    "attendance": {
      "id": 12345,
      "status": "present",
      "check_in_time": "08:05:23",
      "attendance_date": "2026-01-31",
      "schedule_id": 456,
      "student_id": 100,
      "request_id": "550e8400-e29b-41d4-a716-446655440000"
    }
  },
  "idempotent_retry": true
}
```

---

## Client Implementation

### Mobile App (Flutter/Dart)

```dart
class AttendanceService {
  Future<AttendanceResult> submitAttendance({
    required String qrToken,
    required double lat,
    required double lng,
    required String deviceId,
  }) async {
    // Generate idempotency key client-side
    final requestId = const Uuid().v4();
    
    // Store locally before sending (for retry)
    await _storeLocalRequest(requestId, qrToken, lat, lng);
    
    try {
      final response = await _api.post('/attendance/scan', {
        'qr_token': qrToken,
        'lat': lat,
        'lng': lng,
        'device_id': deviceId,
        'request_id': requestId,
      });
      
      // Success - remove from local storage
      await _removeLocalRequest(requestId);
      
      return AttendanceResult.fromJson(response.data);
      
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout) {
        // Timeout - request may have succeeded on server
        // Safe to retry with SAME request_id
        throw RetryableException(requestId: requestId);
      }
      rethrow;
    }
  }
  
  Future<AttendanceResult> retryPendingRequest(
    String requestId,
    Map<String, dynamic> savedData,
  ) async {
    // Use SAME request_id - server will return existing if processed
    final response = await _api.post('/attendance/scan', {
      ...savedData,
      'request_id': requestId,  // Same ID!
    });
    
    return AttendanceResult.fromJson(response.data);
  }
}
```

### JavaScript/Web

```javascript
async function submitAttendance(qrToken, lat, lng, deviceId) {
  // Generate idempotency key
  const requestId = crypto.randomUUID();
  
  // Store in localStorage for retry
  localStorage.setItem(`pending_${requestId}`, JSON.stringify({
    qrToken, lat, lng, deviceId, requestId
  }));
  
  try {
    const response = await fetch('/api/v1/attendance/scan', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({
        qr_token: qrToken,
        lat, lng,
        device_id: deviceId,
        request_id: requestId,
      }),
    });
    
    // Success - clean up
    localStorage.removeItem(`pending_${requestId}`);
    
    return await response.json();
    
  } catch (error) {
    // Network error - can safely retry with same request_id
    throw new RetryableError(requestId);
  }
}
```

---

## Offline Sync Pattern

For offline-first mobile apps:

```dart
class OfflineSyncService {
  Future<void> queueAttendance(AttendanceData data) async {
    // Generate request_id NOW (while offline)
    final requestId = const Uuid().v4();
    
    // Save to local database with request_id
    await _localDb.insert(PendingAttendance(
      requestId: requestId,
      qrToken: data.qrToken,
      lat: data.lat,
      lng: data.lng,
      deviceId: data.deviceId,
      scannedAt: DateTime.now(),
    ));
  }
  
  Future<void> syncPendingAttendances() async {
    final pending = await _localDb.getPendingAttendances();
    
    for (final attendance in pending) {
      try {
        final result = await _api.post('/attendance/scan', {
          'qr_token': attendance.qrToken,
          'lat': attendance.lat,
          'lng': attendance.lng,
          'device_id': attendance.deviceId,
          'request_id': attendance.requestId,  // Same ID from queue!
          'scanned_at': attendance.scannedAt.toIso8601String(),
        });
        
        // Mark as synced (whether new or idempotent retry)
        await _localDb.markSynced(attendance.requestId);
        
      } catch (e) {
        // Will retry on next sync
      }
    }
  }
}
```

---

## Server-Side Logic

### Service Implementation

```php
public function checkIn(User $student, array $data, ?Request $request = null): AttendanceResult
{
    $requestId = $data['request_id'] ?? (string) Str::uuid();
    
    // EARLY IDEMPOTENCY CHECK
    // If this request_id was already processed, return existing result
    $existing = $this->findByRequestId($requestId);
    if ($existing) {
        Log::info('Idempotent retry', ['request_id' => $requestId]);
        
        return new AttendanceResult(
            success: true,
            attendance: $existing,
            message: 'Absensi sudah tercatat sebelumnya.',
            status: $existing->status,
            isIdempotentRetry: true
        );
    }
    
    // ... continue with validations and create new attendance
}

private function findByRequestId(string $requestId): ?Attendance
{
    if (empty($requestId)) {
        return null;
    }
    
    return Attendance::where('request_id', $requestId)->first();
}
```

---

## HTTP Status Codes

| Scenario | Status Code | `idempotent_retry` |
|----------|-------------|-------------------|
| New attendance created | `201 Created` | Not present |
| Idempotent retry (existing) | `200 OK` | `true` |
| Validation failed | `400 Bad Request` | Not present |
| Unauthorized | `401 Unauthorized` | - |
| Rate limited | `429 Too Many Requests` | - |

---

## Why This Matters

### Problem: Network Timeout

```
Client                           Server
  │                                │
  │──── POST /scan ──────────────►│
  │                                │ Creates attendance
  │   ✗ Connection timeout         │
  │                                │
  │──── POST /scan (retry) ──────►│
  │                                │ Without idempotency:
  │                                │   → Duplicate record! ❌
  │                                │ With idempotency:
  │                                │   → Returns existing ✓
```

### Problem: Offline Sync

```
Mobile App                       Server
  │                                │
  │ Scans QR (offline)             │
  │ Generates request_id locally   │
  │ Queues for sync                │
  │                                │
  │ ... later, online ...          │
  │                                │
  │──── POST /scan (batch 1) ────►│ Creates attendance
  │                                │
  │ App crashes before             │
  │ marking as synced              │
  │                                │
  │ ... app restarts ...           │
  │                                │
  │──── POST /scan (batch 1) ────►│ Same request_id
  │                                │ Returns existing ✓
```

---

## Testing

### Unit Test

```php
public function test_duplicate_request_id_returns_existing()
{
    $requestId = Str::uuid()->toString();
    
    // First request
    $result1 = $this->service->checkIn($student, [
        'qr_token' => $this->validToken,
        'request_id' => $requestId,
    ]);
    
    $this->assertEquals(201, $result1->getHttpStatusCode());
    $this->assertFalse($result1->isIdempotentRetry);
    
    // Retry with same request_id
    $result2 = $this->service->checkIn($student, [
        'qr_token' => $this->validToken,
        'request_id' => $requestId,
    ]);
    
    $this->assertEquals(200, $result2->getHttpStatusCode());
    $this->assertTrue($result2->isIdempotentRetry);
    $this->assertEquals($result1->attendance->id, $result2->attendance->id);
}
```

### Integration Test

```php
public function test_idempotent_api_endpoint()
{
    $requestId = Str::uuid()->toString();
    
    // First request
    $response1 = $this->postJson('/api/v1/attendance/scan', [
        'qr_token' => $this->validToken,
        'request_id' => $requestId,
    ]);
    
    $response1->assertStatus(201)
        ->assertJsonMissing(['idempotent_retry']);
    
    // Retry
    $response2 = $this->postJson('/api/v1/attendance/scan', [
        'qr_token' => $this->validToken,
        'request_id' => $requestId,
    ]);
    
    $response2->assertStatus(200)
        ->assertJson(['idempotent_retry' => true]);
}
```
