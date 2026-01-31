# QR Attendance System - Cryptographic Signature Validation & Anti-Replay Protection

## Overview

This implementation provides a secure QR verification flow with HMAC-SHA256 signature validation and a short expiration window (10 seconds by default) to prevent replay attacks.

## Files Created/Modified

### 1. QRSignatureService Class
**Path:** `app/Services/QRSignatureService.php`

```php
<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * QR Signature Service - Cryptographic Signature Validation & Anti-Replay Protection
 *
 * SECURITY FEATURES:
 * - HMAC-SHA256 signature validation using app.key
 * - 10-second expiration window (configurable)
 * - Timing-safe signature comparison (hash_equals)
 * - Comprehensive security logging
 */
final class QRSignatureService
{
    private const DEFAULT_EXPIRATION_SECONDS = 10;

    private string $secretKey;
    private int $expirationSeconds;

    public function __construct()
    {
        $this->secretKey = config('app.key');
        $this->expirationSeconds = config('qr.signature_expiration_seconds', self::DEFAULT_EXPIRATION_SECONDS);
    }

    /**
     * Generate a signed QR payload
     */
    public function generateSignedPayload(int $studentId, string $nisn, ?int $timestamp = null): array;

    /**
     * Verify a QR payload signature and expiration
     * - Recalculates signature
     * - Uses hash_equals for comparison
     * - Rejects QR older than 10 seconds
     */
    public function verifyPayload(array $payload): array;

    /**
     * Generate HMAC-SHA256 signature
     * Formula: hash_hmac('sha256', "$studentId|$nisn|$timestamp", config('app.key'))
     */
    public function generateSignature(int $studentId, string $nisn, int $timestamp): string;
}
```

### 2. Updated Controller Method
**Path:** `app/Http/Controllers/Api/V1/SecureAttendanceScanController.php`

The controller provides three endpoints:
- `POST /api/v1/attendance/secure/scan` - Scan with JSON payload
- `POST /api/v1/attendance/secure/scan-encoded` - Scan with base64 encoded QR
- `POST /api/v1/attendance/secure/generate-qr` - Generate signed QR for student

### 3. Logging Configuration
**Path:** `config/logging.php`

Added new channel `attendance_security`:

```php
'attendance_security' => [
    'driver' => 'daily',
    'path' => storage_path('logs/attendance_security.log'),
    'level' => 'debug',
    'days' => 90, // Keep attendance security logs for 90 days
    'replace_placeholders' => true,
],
```

### 4. QR Configuration
**Path:** `config/qr.php`

Added new config option:

```php
'signature_expiration_seconds' => env('QR_SIGNATURE_EXPIRY_SECONDS', 10),
```

---

## Example JSON Request Payloads

### 1. Scan with JSON Payload
**Endpoint:** `POST /api/v1/attendance/secure/scan`

```json
{
    "qr_payload": {
        "student_id": 123,
        "nisn": "0012345678",
        "generated_at": 1738296600,
        "signature": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6"
    },
    "lat": -6.2088,
    "lng": 106.8456,
    "device_id": "device-uuid-123",
    "request_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### 2. Scan with Encoded QR String
**Endpoint:** `POST /api/v1/attendance/secure/scan-encoded`

```json
{
    "qr_encoded": "eyJzdHVkZW50X2lkIjoxMjMsIm5pc24iOiIwMDEyMzQ1Njc4IiwiZ2VuZXJhdGVkX2F0IjoxNzM4Mjk2NjAwLCJzaWduYXR1cmUiOiJhMWIyYzNkNGU1ZjZnN2g4aTlqMGsxbDJtM240bzVwNnE3cjhzOXQwdTF2MnczeHk1ejYifQ==",
    "lat": -6.2088,
    "lng": 106.8456,
    "device_id": "device-uuid-123"
}
```

### 3. Generate Signed QR
**Endpoint:** `POST /api/v1/attendance/secure/generate-qr`

**Request:**
```json
{
    "student_id": 123
}
```

**Response:**
```json
{
    "status": "success",
    "data": {
        "qr_payload": {
            "student_id": 123,
            "nisn": "0012345678",
            "generated_at": 1738296600,
            "signature": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6"
        },
        "qr_encoded": "eyJzdHVkZW50X2lkIjoxMjMsIm5pc24iOiIwMDEyMzQ1Njc4IiwiZ2VuZXJhdGVkX2F0IjoxNzM4Mjk2NjAwLCJzaWduYXR1cmUiOiJhMWIyYzNkNGU1ZjZnN2g4aTlqMGsxbDJtM240bzVwNnE3cjhzOXQwdTF2MnczeHk1ejYifQ==",
        "expires_at": "2026-01-31T11:10:10+08:00",
        "valid_for_seconds": 10
    }
}
```

---

## Security Features

### 1. HMAC-SHA256 Signature Validation
```php
// Signature generation formula:
$dataToSign = "{$studentId}|{$nisn}|{$timestamp}";
$signature = hash_hmac('sha256', $dataToSign, config('app.key'));
```

### 2. Timing-Safe Comparison
```php
// Uses hash_equals to prevent timing attacks
if (!hash_equals($expectedSignature, $providedSignature)) {
    throw new \Exception('Tanda tangan QR tidak valid.');
}
```

### 3. Short Expiration Window (Anti-Replay)
```php
// Default: 10 seconds
// Configurable via QR_SIGNATURE_EXPIRY_SECONDS env variable
if ($ageSeconds > $this->expirationSeconds) {
    throw new \Exception("QR Code sudah kadaluarsa.");
}
```

### 4. Failed Attempt Logging
All failed verification attempts are logged to the `attendance_security` channel:
- `qr_expired` - QR code has expired
- `invalid_signature` - HMAC signature mismatch (potential tampering)
- `missing_fields` - Required fields missing from payload
- `invalid_encoding` - Base64 decode failed
- `student_not_found` - Student ID not found after valid signature
- `duplicate_attendance_attempt` - Student already checked in today

---

## Environment Variables

Add these to your `.env` file:

```env
# QR Signature Expiration (seconds)
QR_SIGNATURE_EXPIRY_SECONDS=10
```

---

## API Routes Added

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| POST | `/api/v1/attendance/secure/scan` | SecureAttendanceScanController@scan | Scan with JSON payload |
| POST | `/api/v1/attendance/secure/scan-encoded` | SecureAttendanceScanController@scanEncoded | Scan with base64 encoded QR |
| POST | `/api/v1/attendance/secure/generate-qr` | SecureAttendanceScanController@generateQR | Generate signed QR for student |

---

## Usage Flow

1. **Teacher generates QR for student** (or student displays pre-generated QR):
   - Call `POST /api/v1/attendance/secure/generate-qr` with `student_id`
   - Receive signed QR payload with 10-second validity

2. **Teacher scans student's QR**:
   - Call `POST /api/v1/attendance/secure/scan` with the QR payload
   - System validates signature, checks expiration, records attendance

3. **Security logging**:
   - All attempts (success/failure) logged to `storage/logs/attendance_security-YYYY-MM-DD.log`
   - Logs include IP address, user agent, timestamp, severity level
