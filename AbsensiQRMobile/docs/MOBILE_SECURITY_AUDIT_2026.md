# Mobile Security Audit 2026

**Audit Date:** January 2026  
**Scope:** AbsensiQRMobile - GPS Validation, Device Binding, Offline Queue, Error Handling  
**Status:** ✅ MOSTLY COMPLIANT with improvements needed

---

## Executive Summary

| Area | Status | Risk Level |
|------|--------|------------|
| GPS Validation | ⚠️ Needs Fix | Medium |
| Device Binding | ✅ Good | Low |
| Radius Logic | ✅ Server-side Only | None |
| Offline Queue | ⚠️ Partial | Medium |
| Error Handling | ✅ Good | Low |
| SSL Pinning | ✅ Implemented | Low |

---

## 1. GPS Validation Audit

### Current Implementation

**Location:** `src/utils/locationValidator.ts`

```typescript
// CURRENT (Client-side blocking - WRONG)
export const getValidatedLocation = async (): Promise<ValidatedLocation> => {
  const location = await getCurrentLocation();
  
  if (location.isMocked) {
    throw new LocationError('MOCK_LOCATION', 'Lokasi palsu terdeteksi');
  }
  
  return location;
};
```

### Issues Found

| # | Issue | Severity | Current Behavior |
|---|-------|----------|------------------|
| 1 | Client blocks on mock detection | Medium | Throws error, stops submission |
| 2 | Attacker can patch app to bypass | High | No server verification of flag |
| 3 | lat/lng optional in ScanPayload | Medium | Can submit without location |

### Correct Pattern: Send Flag to Server

```typescript
// RECOMMENDED: Send flag, let server decide
export const getLocationWithMetadata = async (): Promise<LocationMetadata> => {
  const location = await getCurrentLocation();
  
  return {
    latitude: location.latitude,
    longitude: location.longitude,
    accuracy: location.accuracy,
    timestamp: location.timestamp,
    // Send to server for logging, NOT client-side blocking
    is_mocked: location.isMocked,
    mock_detection_method: location.mockDetectionMethod, // 'android_flag' | 'ios_accuracy'
    altitude: location.altitude,
  };
};
```

### Backend Already Validates (Good)

```php
// AttendanceCheckInService.php:570-600
private function validateLocation(?School $school, ?float $lat, ?float $lng): void {
    $distance = $this->calculateDistance($lat, $lng, $school->latitude, $school->longitude);
    $maxRadius = $school->radius_meters ?? 100;
    
    if ($distance > $maxRadius) {
        // Logs security anomaly THEN rejects
        $this->logger->securityAnomaly($request, 'outside_geofence', ...);
        throw AttendanceException::outsideRadius();
    }
}
```

### Recommendation

1. **Remove client-side blocking** - Don't throw on mock detection
2. **Add `is_mocked` flag to payload** - Server logs and decides
3. **Make lat/lng required** - Update ScanPayload interface

---

## 2. Device Binding Audit

### Current Implementation (Good ✅)

**Location:** `src/services/DeviceSecurityService.ts`

```typescript
// Comprehensive device fingerprint
async generateDeviceFingerprint(): Promise<string> {
    const components = [
        Platform.OS,
        Platform.Version?.toString() || '',
        Platform.constants.Brand || '',
        Platform.constants.Model || '',
        Platform.constants.Manufacturer || '',
    ];
    return this.simpleHash(components.join('|'));
}
```

**Location:** `src/screens/attendance/ScanQRScreen.tsx` (Line 46)

```typescript
const response = await apiClient.post('/attendance/scan', {
    qr_code: qrCode,
    latitude: location.latitude,
    longitude: location.longitude,
    device_fingerprint: deviceFingerprint, // ✅ Sent to server
});
```

### Backend Verification (Good ✅)

```php
// AttendanceCheckInService.php - Device validation
private function validateDevice(User $student, ?string $deviceId): void {
    if ($student->device_id && $deviceId && $student->device_id !== $deviceId) {
        $this->logger->securityAnomaly($request, 'device_mismatch', 
            'Student attempt with different device (potential joki)');
        throw AttendanceException::deviceMismatch();
    }
}
```

### Security Checks Performed

| Check | Library | Status |
|-------|---------|--------|
| Root/Jailbreak Detection | JailMonkey | ✅ Implemented |
| Emulator Detection | Platform.constants | ✅ Implemented |
| Debug Mode Detection | JailMonkey | ✅ Implemented |
| App Signature Validation | Native Module | ✅ Implemented |
| Mock Location Flag | Geolocation | ✅ Detected |

---

## 3. Radius Logic Audit

### Mobile Code Search Results

```
Query: radius|distance|haversine|geofence
Result: 0 business logic matches (only CSS borderRadius)
```

### Verification: NO Client-Side Radius Calculation ✅

The mobile app does **NOT** calculate distance or validate radius. This is correctly done server-side only.

### Server-Side Validation (Correct)

```php
// AttendanceCheckInService.php:903-920
private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    // Haversine formula - SERVER ONLY
    $earthRadius = 6371000; // meters
    // ... calculation ...
    return $distance;
}
```

---

## 4. Offline Queue Audit

### Current Implementation

**Location:** `src/legacy_expo/services/offlineService.ts`

```typescript
export interface QueuedAttendance {
  id: string;
  qr_token: string;
  scanned_at: string;
  latitude?: number;
  longitude?: number;
}

export const addAttendanceToQueue = async (attendance) => {
  // Deduplication within 60 seconds
  const isDuplicate = currentQueue.some(item => 
    item.qr_token === attendance.qr_token && 
    (new Date(attendance.scanned_at).getTime() - new Date(item.scanned_at).getTime()) < 60000
  );
  
  if (!isDuplicate) {
    const newRecord = { ...attendance, id: generateId() };
    await AsyncStorage.setItem(ATTENDANCE_QUEUE_KEY, JSON.stringify([...queue, newRecord]));
  }
};
```

### Issues Found

| # | Issue | Severity | Impact |
|---|-------|----------|--------|
| 1 | No sync service implementation | High | Queued items never submitted |
| 2 | No network status listener | Medium | No automatic retry |
| 3 | No request_id for idempotency | Medium | Backend may reject duplicates |
| 4 | Legacy Expo only | Medium | React Native version doesn't have offline |

### Missing: Sync Service

```typescript
// REQUIRED: Background sync service
export const syncOfflineQueue = async (): Promise<void> => {
  const queue = await getAttendanceQueue();
  
  for (const item of queue) {
    try {
      await attendanceApi.scan({
        qr_token: item.qr_token,
        lat: item.latitude,
        lng: item.longitude,
        request_id: item.id, // Idempotency key
      });
      await removeFromQueue(item.id);
    } catch (error) {
      if (error.status === 409) {
        // Already recorded, safe to remove
        await removeFromQueue(item.id);
      }
      // Other errors: keep in queue for retry
    }
  }
};
```

### Recommendation

Create `src/services/OfflineSyncService.ts` with:
1. Network status listener (NetInfo)
2. Background sync on reconnect
3. Include `request_id` for idempotency
4. Exponential backoff retry

---

## 5. Error Handling Audit

### Current Implementation (Good ✅)

**Location:** `src/screens/attendance/ScanQRScreen.tsx`

```typescript
const handleScan = async (qrCode: string) => {
    try {
        const location = await getValidatedLocation();
        const response = await apiClient.post('/attendance/scan', payload);
        
        if (response.data.success) {
            Alert.alert('Berhasil', 'Absensi berhasil dicatat!');
        }
    } catch (error: any) {
        // Specific error handling
        if (error.code === 'MOCK_LOCATION') {
            Alert.alert('Lokasi Palsu Terdeteksi', error.message);
            return;
        }
        
        if (error.code === 'PERMISSION_DENIED' || error.code === 'TIMEOUT') {
            Alert.alert('Error Lokasi', error.message, [
                { text: 'Coba Lagi', onPress: () => setIsActive(true) }
            ]);
            return;
        }
        
        // API errors
        const msg = error.response?.data?.message || 'Gagal mengirim data absensi';
        Alert.alert('Gagal', msg, [
            { text: 'Coba Lagi', onPress: () => setIsActive(true) },
            { text: 'Kembali', onPress: () => navigation.goBack() }
        ]);
    }
};
```

### Rate Limit Handling (Good ✅)

**Location:** `src/api/client.ts`

```typescript
if (error.response?.status === 429) {
    const retryAfter = error.response.headers['retry-after'] || 60;
    Alert.alert('Batas Permintaan Tercapai',
        `Terlalu banyak permintaan. Coba lagi dalam ${retryAfter} detik.`);
}
```

---

## 6. Secure Check-In Flow

### Current Flow Diagram

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  MOBILE APP │     │  SSL PINNED │     │   BACKEND   │
│             │     │   CHANNEL   │     │   SERVER    │
└─────┬───────┘     └──────┬──────┘     └──────┬──────┘
      │                    │                   │
      │ 1. Run Security    │                   │
      │    Checks          │                   │
      │    - Root/JB       │                   │
      │    - Emulator      │                   │
      │    - Debug Mode    │                   │
      ▼                    │                   │
   [BLOCK if Critical]     │                   │
      │                    │                   │
      │ 2. Get Location    │                   │
      │    + Mock Flag     │                   │
      ▼                    │                   │
      │ 3. Scan QR Code    │                   │
      │                    │                   │
      │ 4. POST /scan ─────┼──────────────────►│
      │    - qr_token      │                   │ 5. Verify HMAC
      │    - lat/lng       │                   │ 6. Check Nonce
      │    - device_fp     │                   │ 7. Validate Student
      │    - is_mocked     │                   │ 8. Check Device
      │    - request_id    │                   │ 9. Check Geofence
      │                    │                   │ 10. Check Schedule
      │                    │                   │ 11. Atomic Insert
      │                    │                   │
      │ ◄──────────────────┼───────────────────│ Response
      │                    │                   │
      ▼                    │                   │
   [Show Result]           │                   │
```

### Recommended Payload Structure

```typescript
interface SecureAttendancePayload {
  // Required
  qr_token: string;        // HMAC-signed token from teacher QR
  latitude: number;        // Required, validated server-side
  longitude: number;       // Required, validated server-side
  device_fingerprint: string;
  request_id: string;      // UUID for idempotency
  
  // Metadata (for logging/analysis)
  accuracy: number;        // GPS accuracy in meters
  is_mocked: boolean;      // Android flag or iOS accuracy anomaly
  timestamp: number;       // Client timestamp (NOT used for attendance time)
  
  // Security context (for anomaly detection)
  security_context?: {
    is_rooted: boolean;
    is_emulator: boolean;
    risk_level: 'none' | 'low' | 'medium' | 'high' | 'critical';
  };
}
```

---

## 7. Anti-Mock Location Strategy

### Multi-Layer Detection

| Layer | Implementation | Bypass Difficulty |
|-------|---------------|-------------------|
| 1. Android `isMocked` flag | Geolocation API | Easy (root) |
| 2. iOS accuracy check | <1m = suspicious | Medium |
| 3. Movement pattern analysis | Server-side | Hard |
| 4. Historical location validation | Server-side | Hard |
| 5. Device fingerprint consistency | Server-side | Hard |

### Server-Side Pseudocode

```php
// Recommended: Enhanced location validation
class LocationSecurityService {
    
    public function validateLocation(array $data, User $user): LocationValidationResult {
        $lat = $data['latitude'];
        $lng = $data['longitude'];
        $isMocked = $data['is_mocked'] ?? false;
        $accuracy = $data['accuracy'] ?? null;
        
        $flags = [];
        
        // 1. Direct mock flag
        if ($isMocked) {
            $flags[] = 'mock_flag_detected';
            $this->logAnomaly($user, 'mock_location_flag', 'high');
        }
        
        // 2. Suspiciously accurate
        if ($accuracy !== null && $accuracy < 1.0) {
            $flags[] = 'suspicious_accuracy';
            $this->logAnomaly($user, 'impossible_accuracy', 'medium');
        }
        
        // 3. Teleportation check (optional, needs history)
        $lastLocation = $this->getLastLocation($user);
        if ($lastLocation) {
            $timeDiff = now()->diffInMinutes($lastLocation->timestamp);
            $distance = $this->haversine($lat, $lng, $lastLocation->lat, $lastLocation->lng);
            $maxPossibleSpeed = 500; // km/h (plane)
            $maxPossibleDistance = ($timeDiff / 60) * $maxPossibleSpeed * 1000;
            
            if ($distance > $maxPossibleDistance) {
                $flags[] = 'teleportation_detected';
                $this->logAnomaly($user, 'impossible_travel', 'critical');
            }
        }
        
        // 4. Known spoofing app locations (static database)
        if ($this->isKnownSpoofLocation($lat, $lng)) {
            $flags[] = 'known_spoof_location';
            $this->logAnomaly($user, 'common_spoof_coords', 'high');
        }
        
        // Decision: Allow but log, or reject
        return new LocationValidationResult(
            isValid: empty($flags) || !$this->strictMode,
            flags: $flags,
            shouldLog: !empty($flags)
        );
    }
}
```

---

## 8. Required Fixes

### Priority 1: Critical Fixes

#### Fix 1: Make Location Required

```typescript
// src/api/attendance.ts
export interface ScanPayload {
  qr_token: string;
  lat: number;          // Changed from optional
  lng: number;          // Changed from optional
  accuracy: number;
  is_mocked: boolean;
  device_fingerprint: string;
  request_id: string;
}
```

#### Fix 2: Send Mock Flag Instead of Blocking

```typescript
// src/utils/locationValidator.ts
export const getLocationMetadata = async (): Promise<LocationMetadata> => {
  const config = { ... };
  
  return new Promise((resolve, reject) => {
    Geolocation.getCurrentPosition(
      (position) => {
        const isMocked = checkIfMocked(position);
        
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
          altitude: position.coords.altitude,
          timestamp: position.timestamp,
          is_mocked: isMocked,        // Send flag, don't throw
          mock_detection_method: Platform.OS === 'android' 
            ? 'android_flag' 
            : 'ios_accuracy_heuristic',
        });
      },
      (error) => reject(new LocationError(error.code, error.message))
    );
  });
};
```

### Priority 2: Offline Queue Sync

```typescript
// src/services/OfflineSyncService.ts
import NetInfo from '@react-native-community/netinfo';
import { getAttendanceQueue, removeFromQueue } from './offlineService';
import { attendanceApi } from '../api/attendance';

class OfflineSyncService {
  private issyncing = false;
  
  init() {
    NetInfo.addEventListener(state => {
      if (state.isConnected && !this.isSyncing) {
        this.syncQueue();
      }
    });
  }
  
  async syncQueue() {
    this.isSyncing = true;
    const queue = await getAttendanceQueue();
    
    for (const item of queue) {
      try {
        await attendanceApi.scan({
          qr_token: item.qr_token,
          lat: item.latitude!,
          lng: item.longitude!,
          request_id: item.id,
          // ... other fields
        });
        await removeFromQueue(item.id);
      } catch (error: any) {
        if (error.response?.status === 409 || error.response?.status === 422) {
          // Already processed or invalid - remove
          await removeFromQueue(item.id);
        }
        // Keep in queue for retry on other errors
      }
    }
    
    this.isSyncing = false;
  }
}

export const offlineSyncService = new OfflineSyncService();
```

### Priority 3: Add Security Context to Payload

```typescript
// src/screens/attendance/ScanQRScreen.tsx
const handleScan = async (qrCode: string) => {
  const location = await getLocationMetadata();
  const { isSecure, violations, deviceFingerprint } = useDeviceSecurity();
  
  const response = await apiClient.post('/attendance/scan', {
    qr_code: qrCode,
    lat: location.latitude,
    lng: location.longitude,
    accuracy: location.accuracy,
    is_mocked: location.is_mocked,
    device_fingerprint: deviceFingerprint,
    request_id: uuid.v4(),
    security_context: {
      is_secure: isSecure,
      risk_level: calculateRiskLevel(violations),
      violations: violations.map(v => v.type),
    }
  });
};
```

---

## 9. Testing Checklist

### Manual Testing

- [ ] Scan with mock location app → Should submit but be logged server-side
- [ ] Scan from outside radius → Should be rejected by server
- [ ] Scan with rooted device → Should show warning, allow scan
- [ ] Offline scan → Should queue and sync when online
- [ ] Rate limit exceeded → Should show retry time

### Automated Testing

```bash
# Run mobile tests
cd AbsensiQRMobile
npm test -- --coverage

# Run backend integration tests
cd backend
php artisan test --filter=AttendanceIntegrityTest
```

---

## 10. Summary

### What's Good ✅
1. **No radius calculation on client** - All geofencing is server-side
2. **Device fingerprint included in payload** - Server validates device binding
3. **SSL pinning implemented** - MITM protection active
4. **Comprehensive security checks** - Root, jailbreak, emulator detection
5. **Error handling follows patterns** - User-friendly messages

### What Needs Improvement ⚠️
1. **Don't block on mock detection** - Send flag to server instead
2. **Make lat/lng required** - Enforce location submission
3. **Implement offline sync service** - Queue exists but no sync
4. **Add request_id to payload** - Backend supports idempotency
5. **Port offline queue to React Native** - Currently Expo-only

---

**Audit Completed By:** AI Security Auditor  
**Next Review:** Q2 2026
