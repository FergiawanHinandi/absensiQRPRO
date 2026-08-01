# Offline-First QR Attendance System

> **⚠️ Archived**: This document describes the Flutter prototype that has been archived to `_archived/mobile_flutter/`.
> The active mobile app is **React Native** (`AbsensiQRMobile/`). Refer to this document only as a design reference
> if implementing similar offline-first patterns in the React Native codebase.

## Overview

This Flutter implementation provides a robust offline-first attendance scanning system that:
- Stores scanned attendance locally using Hive
- Syncs to server every 30 seconds
- Retries failed requests with exponential backoff
- Prevents duplicate submissions
- Handles connectivity changes gracefully

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────────┐  │
│  │ QR Scanner  │  │  Sync Badge │  │  Attendance History Screen  │  │
│  │   Screen    │  │  (Pending)  │  │                             │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────────┬──────────────┘  │
└─────────┼────────────────┼─────────────────────────┼────────────────┘
          │                │                         │
┌─────────┼────────────────┼─────────────────────────┼────────────────┐
│         ▼                ▼                         ▼   DOMAIN LAYER │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │              SubmitAttendanceUseCase                         │   │
│  │  - Parse QR code                                             │   │
│  │  - Validate expiry                                           │   │
│  │  - Check duplicates                                          │   │
│  │  - Store locally                                             │   │
│  │  - Trigger sync                                              │   │
│  └────────────────────────────┬─────────────────────────────────┘   │
└───────────────────────────────┼─────────────────────────────────────┘
                                │
┌───────────────────────────────┼─────────────────────────────────────┐
│                               ▼                        DATA LAYER   │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │           AttendanceLocalRepository                         │    │
│  │  - Store scans (Hive)                                       │    │
│  │  - Find duplicates                                          │    │
│  │  - Get pending records                                      │    │
│  │  - Cleanup sent records                                     │    │
│  └──────────────────────────────┬──────────────────────────────┘    │
│                                 │                                    │
│  ┌──────────────────────────────┼──────────────────────────────┐    │
│  │           AttendanceSyncService                             │    │
│  │  - 30-second periodic sync                                  │    │
│  │  - Connectivity monitoring                                  │    │
│  │  - Exponential backoff                                      │    │
│  │  - Batch processing (3 concurrent)                          │    │
│  └──────────────────────────────┼──────────────────────────────┘    │
│                                 │                                    │
│                                 ▼                                    │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │               Hive (Local SQLite-like Storage)               │   │
│  │  pending_attendances box                                     │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
                                │
                                ▼
              ┌─────────────────────────────────────┐
              │         Backend API Server          │
              │  POST /api/v1/attendance/scan       │
              └─────────────────────────────────────┘
```

---

## Local Database Schema

### PendingAttendance (Hive TypeId: 1)

| Field | Type | Description |
|-------|------|-------------|
| `id` | String | UUID v4, local primary key |
| `studentId` | int | User ID of student |
| `scheduleId` | int | Schedule ID from QR |
| `scannedAt` | DateTime | When QR was scanned |
| `syncStatus` | SyncStatus | pending/syncing/sent/failed |
| `retryCount` | int | Number of sync attempts |
| `lastSyncAttempt` | DateTime? | Last sync attempt time |
| `lastError` | String? | Error from last failed sync |
| `serverAttendanceId` | int? | ID from server after success |
| `qrToken` | String | Original QR data |
| `latitude` | double? | GPS latitude |
| `longitude` | double? | GPS longitude |
| `accuracy` | double? | GPS accuracy in meters |
| `deviceId` | String | Device ID for anti-joki |
| `requestId` | String | UUID for idempotency |
| `createdAt` | DateTime | Record creation time |

### SyncStatus Enum (Hive TypeId: 2)

| Value | Code | Description |
|-------|------|-------------|
| `pending` | 0 | Waiting to be synced |
| `syncing` | 1 | Currently being synced |
| `sent` | 2 | Successfully synced to server |
| `failed` | 3 | Sync failed, will retry |

---

## Sync Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                         SCAN QR CODE                                 │
└────────────────────────────────┬────────────────────────────────────┘
                                 ▼
                    ┌────────────────────────┐
                    │    Parse QR Token      │
                    └────────────┬───────────┘
                                 ▼
                    ┌────────────────────────┐
                    │   Check if expired?    │
                    └────────────┬───────────┘
                        Yes │         │ No
                            ▼         ▼
               ┌──────────────┐  ┌────────────────────────┐
               │ Show Error   │  │   Check duplicate?     │
               └──────────────┘  └────────────┬───────────┘
                                    Yes │         │ No
                                        ▼         ▼
                           ┌──────────────┐  ┌────────────────────────┐
                           │ Show Already │  │   Store in Hive        │
                           │ Scanned      │  │   (syncStatus=pending) │
                           └──────────────┘  └────────────┬───────────┘
                                                          ▼
                                              ┌────────────────────────┐
                                              │   Trigger Sync Now     │
                                              └────────────┬───────────┘
                                                          ▼
                                              ┌────────────────────────┐
                                              │     Is Online?         │
                                              └────────────┬───────────┘
                                              Yes │         │ No
                                                  ▼         ▼
                                     ┌──────────────┐  ┌────────────┐
                                     │ POST to API  │  │ Show Toast │
                                     └──────┬───────┘  │ "Offline"  │
                                            │          └────────────┘
                                            ▼
                                  ┌────────────────────┐
                                  │   HTTP 200/201?    │
                                  └────────┬───────────┘
                                  Yes │         │ No
                                      ▼         ▼
                         ┌──────────────┐  ┌────────────────┐
                         │ Mark as SENT │  │ Mark as FAILED │
                         │ + Show ✓     │  │ + Schedule     │
                         └──────────────┘  │   Retry        │
                                           └────────────────┘
```

---

## Exponential Backoff

Failed requests are retried with increasing delays:

| Attempt | Delay | Total Wait |
|---------|-------|------------|
| 1 | 5 seconds | 5s |
| 2 | 10 seconds | 15s |
| 3 | 20 seconds | 35s |
| 4 | 40 seconds | 75s |
| 5 | 80 seconds | 155s (max) |

After 5 failed attempts, the record is marked as permanently failed and requires manual retry.

---

## API Submission

### Request

```http
POST /api/v1/attendance/scan
Authorization: Bearer <token>
X-Request-ID: <uuid>
X-Idempotency-Key: <uuid>
Content-Type: application/json

{
  "token": "{\"id\":123,\"exp\":1706655600,\"n\":\"abc123\",\"sig\":\"...\"}",
  "request_id": "550e8400-e29b-41d4-a716-446655440000",
  "latitude": -6.2088,
  "longitude": 106.8456,
  "accuracy": 10.5,
  "device_info": {
    "device_id": "device-abc-123"
  },
  "scanned_at": "2026-01-31T04:00:00.000Z"
}
```

### Success Response (200)

```json
{
  "success": true,
  "message": "Absensi berhasil dicatat.",
  "data": {
    "id": 12345,
    "student_name": "Budi Santoso",
    "schedule": "Matematika - Kelas 10A",
    "status": "present",
    "check_in_time": "08:05:23"
  }
}
```

### Duplicate Response (409)

```json
{
  "success": false,
  "message": "Anda sudah absen untuk jadwal ini.",
  "data": {
    "id": 12345
  }
}
```

---

## Duplicate Prevention

Duplicates are prevented at multiple levels:

### 1. Local Level (Mobile)

```dart
// Check before storing
if (_localRepo.hasAttendanceForToday(studentId, scheduleId)) {
  return ScanSubmissionResult.duplicate(existing);
}
```

### 2. API Level (Backend)

```php
// Idempotency via request_id
if ($existing && $existing->request_id === $requestId) {
    return $existing; // Return same response
}

// Duplicate check via unique constraint
// UNIQUE(student_id, schedule_id, attendance_date)
```

---

## Usage Examples

### 1. Store Scan Locally

```dart
final repo = AttendanceLocalRepository();
await repo.init();

final attendance = await repo.storeScan(
  studentId: 12345,
  scheduleId: 100,
  qrToken: '{"id":100,"exp":1706655600}',
  deviceId: 'device-abc-123',
  latitude: -6.2088,
  longitude: 106.8456,
  accuracy: 10.5,
);

print('Stored: ${attendance.id}');
print('Status: ${attendance.syncStatus}'); // pending
```

### 2. Manual Sync Trigger

```dart
final syncService = AttendanceSyncService(
  localRepository: repo,
  dio: Dio(),
);
await syncService.init();

// Trigger immediate sync
final result = await syncService.syncNow();
print('Synced: ${result.success}, Failed: ${result.failed}');
```

### 3. Listen to Sync Events

```dart
syncService.syncStatusStream.listen((update) {
  switch (update.type) {
    case SyncStatusType.recordSynced:
      print('Record ${update.recordId} synced!');
      showSuccessToast();
      break;
    case SyncStatusType.recordFailed:
      print('Record ${update.recordId} failed: ${update.error}');
      break;
    case SyncStatusType.connectivityChanged:
      if (update.isOnline!) {
        showToast('Back online - syncing...');
      }
      break;
  }
});
```

### 4. Full Scan Flow

```dart
final useCase = SubmitAttendanceUseCase(
  localRepository: repo,
  syncService: syncService,
);

// When user scans QR
final result = await useCase.execute(
  studentId: currentUser.id,
  qrData: scannedQrCode,
  deviceId: await getDeviceId(),
  latitude: position.latitude,
  longitude: position.longitude,
  accuracy: position.accuracy,
);

if (result.success) {
  if (result.isOffline) {
    showToast('Tersimpan offline, akan dikirim saat online');
  } else {
    showSuccessDialog('Absensi berhasil!');
  }
} else if (result.isDuplicate) {
  showWarning('Sudah absen untuk jadwal ini');
} else {
  showError(result.message);
}
```

---

## Testing

### Unit Tests

```dart
void main() {
  group('AttendanceLocalRepository', () {
    late AttendanceLocalRepository repo;

    setUp(() async {
      await Hive.initFlutter();
      repo = AttendanceLocalRepository();
      await repo.init();
    });

    test('should store attendance scan', () async {
      final attendance = await repo.storeScan(
        studentId: 1,
        scheduleId: 100,
        qrToken: 'test-token',
        deviceId: 'device-1',
      );

      expect(attendance.syncStatus, SyncStatus.pending);
      expect(attendance.retryCount, 0);
    });

    test('should prevent duplicate for same schedule same day', () async {
      await repo.storeScan(
        studentId: 1,
        scheduleId: 100,
        qrToken: 'test-token',
        deviceId: 'device-1',
      );

      expect(repo.hasAttendanceForToday(1, 100), isTrue);
      expect(repo.hasAttendanceForToday(1, 101), isFalse);
    });
  });
}
```

---

## Setup Instructions

### 1. Add Dependencies

```bash
cd mobile_flutter
flutter pub get
```

### 2. Generate Hive Adapters

```bash
flutter pub run build_runner build --delete-conflicting-outputs
```

### 3. Initialize in main.dart

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize dependencies
  await initializeDependencies();

  runApp(const MyApp());
}
```

### 4. Configure API Base URL

Edit `lib/di/injection.dart`:

```dart
final dio = Dio(BaseOptions(
  baseUrl: 'https://your-api-server.com',
  // ...
));
```

---

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| Sync interval | 30 seconds | How often to check for pending records |
| Max concurrent | 3 | Maximum parallel API requests |
| Max retries | 5 | Maximum retry attempts before giving up |
| Cleanup age | 7 days | Delete sent records older than this |
| Request timeout | 10 seconds | API request timeout |
