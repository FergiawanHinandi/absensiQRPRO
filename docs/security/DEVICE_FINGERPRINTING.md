# Device Fingerprinting for Security

## Overview

The device fingerprinting system provides secure device identification for:
- **Rate limiting** per device
- **Anti-joki detection** (proxy attendance)
- **Anomaly logging** and security monitoring

---

## Security Design Principles

### 1. Never Store Raw Identifiers

```
❌ WRONG: Store device_id directly
   device_id: "abc-device-123"

✅ CORRECT: Store SHA-256 hash only
   device_hash: "a1b2c3d4e5f6..."
```

**Why?**
- Cannot reverse hash to identify specific device
- Protects user privacy
- Complies with data minimization principles

### 2. Multi-Factor Fingerprint

The fingerprint combines multiple signals:

```
fingerprint = SHA256(
    app_key +        // Salt (prevents rainbow tables)
    device_id +      // Client-provided device ID
    user_id +        // Authenticated user
    ip_address +     // Network source
    user_agent       // Browser/app identifier
)
```

**Why multiple factors?**
- Harder to spoof than single identifier
- Detects device sharing
- Reveals network anomalies

### 3. Salted Hashing

```php
// Uses app key as salt
$salt = config('app.key');
$hash = hash('sha256', "{$salt}:{$type}:{$value}");
```

**Why?**
- Same device_id produces different hash in different installations
- Prevents pre-computed rainbow table attacks

---

## Components

### DeviceFingerprint (DTO)

```php
final readonly class DeviceFingerprint
{
    public string $fingerprint;      // Full composite hash
    public ?string $deviceHash;      // Hashed device_id
    public ?string $ipHash;          // Hashed IP address
    public ?string $userAgentHash;   // Hashed user agent
    public ?int $userId;             // Associated user
    public Carbon $timestamp;        // Generation time
}
```

### DeviceFingerprintService

```php
// Generate fingerprint
$fingerprint = $fingerprintService->generate($request, $userId, $deviceId);

// Detect anomalies
$anomalyResult = $fingerprintService->detectAnomalies($fingerprint, $userId);

// Record usage (for historical analysis)
$fingerprintService->recordUsage($fingerprint, $userId);

// Get rate limit key
$rateLimitKey = $fingerprintService->getRateLimitKey($fingerprint);
```

### DeviceAnomalyResult

```php
final readonly class DeviceAnomalyResult
{
    public bool $hasAnomalies;
    public array $anomalies;
    public int $riskScore;          // 0-100
    public string $recommendation;  // ALLOW, MONITOR, CHALLENGE, BLOCK
}
```

---

## Usage Examples

### 1. In Middleware (Automatic)

```php
// app/Http/Kernel.php
protected $middlewareAliases = [
    'device.fingerprint' => DeviceFingerprintMiddleware::class,
];

// routes/api.php
Route::middleware(['auth:sanctum', 'device.fingerprint'])
    ->prefix('attendance')
    ->group(function () {
        Route::post('/scan', [AttendanceController::class, 'scan']);
    });
```

### 2. In Service (Manual)

```php
class AttendanceCheckInService
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    public function checkIn(User $student, array $data, Request $request): AttendanceResult
    {
        // Generate fingerprint
        $fingerprint = $this->fingerprintService->generate(
            $request,
            $student->id,
            $data['device_id'] ?? null
        );

        // Check for anomalies
        $anomalies = $this->fingerprintService->detectAnomalies(
            $fingerprint,
            $student->id
        );

        if ($anomalies->shouldBlock()) {
            throw new SecurityException('Aktivitas mencurigakan terdeteksi.');
        }

        if ($anomalies->shouldChallenge()) {
            // Require additional verification
            $this->requireVerification($student);
        }

        // Record usage
        $this->fingerprintService->recordUsage($fingerprint, $student->id);

        // Proceed with check-in...
    }
}
```

### 3. For Rate Limiting

```php
class AttendanceScanThrottle
{
    public function handle(Request $request, Closure $next)
    {
        $fingerprint = $request->attributes->get('device_fingerprint');
        
        if ($fingerprint) {
            $key = $this->fingerprintService->getRateLimitKey($fingerprint);
            
            if (RateLimiter::tooManyAttempts($key, 5)) {
                return response()->json([
                    'message' => 'Terlalu banyak percobaan.',
                ], 429);
            }
            
            RateLimiter::hit($key, 60);
        }
        
        return $next($request);
    }
}
```

### 4. For Anti-Joki Detection

```php
// Detect if device is used by multiple users
$anomalies = $fingerprintService->detectAnomalies($fingerprint, $userId);

foreach ($anomalies->anomalies as $anomaly) {
    if ($anomaly['type'] === 'multi_user_device') {
        Log::channel('security_json')->critical('Possible joki detected', [
            'user_id' => $userId,
            'device_hash' => $fingerprint->deviceHash,
            'user_count' => $anomaly['user_count'],
        ]);
    }
}
```

---

## Anomaly Detection

### Detected Anomalies

| Type | Severity | Description |
|------|----------|-------------|
| `excessive_ip_changes` | MEDIUM | User accessed from >5 IPs in 24h |
| `multi_user_device` | HIGH | Device used by multiple students |
| `rapid_device_changes` | MEDIUM | User switched >3 devices in 24h |

### Risk Score

| Score | Recommendation | Action |
|-------|----------------|--------|
| 0-29 | ALLOW | Normal behavior |
| 30-49 | MONITOR | Log for review |
| 50-79 | CHALLENGE | Request additional verification |
| 80-100 | BLOCK | Block and require admin intervention |

---

## Security Logging

### Log Format

```json
{
  "event": "device.anomaly_detected",
  "fingerprint": "a1b2c3d4e5f6...",
  "user_id": 12345,
  "risk_score": 65,
  "recommendation": "CHALLENGE",
  "anomalies": [
    {
      "type": "multi_user_device",
      "severity": "HIGH",
      "details": "Device used by 3 different users (possible joki)",
      "user_count": 3
    }
  ],
  "path": "/api/v1/attendance/scan",
  "method": "POST"
}
```

### Log Channels

```php
// High risk (score >= 80)
Log::channel('security_json')->critical('Device Anomaly', $context);

// Medium risk (score 50-79)
Log::channel('security_json')->warning('Device Anomaly', $context);

// Low risk (score 30-49)
Log::channel('security_json')->info('Device Anomaly', $context);
```

---

## Client Requirements

### Mobile App

```dart
class DeviceInfo {
  Future<String> getDeviceId() async {
    // Use platform-specific device identifier
    // Android: Settings.Secure.ANDROID_ID
    // iOS: identifierForVendor
    return await platform.getUniqueDeviceId();
  }
}

// Send in every request
final response = await http.post(
  '/api/v1/attendance/scan',
  headers: {
    'X-Device-ID': await deviceInfo.getDeviceId(),
    'Authorization': 'Bearer $token',
  },
  body: jsonEncode({
    'qr_token': qrToken,
    'device_id': await deviceInfo.getDeviceId(),  // Also in body
    // ...
  }),
);
```

### Web App

```javascript
// Generate stable browser fingerprint
const deviceId = await generateBrowserFingerprint();

fetch('/api/v1/attendance/scan', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`,
    'X-Device-ID': deviceId,
  },
  body: JSON.stringify({
    qr_token: qrToken,
    device_id: deviceId,
    // ...
  }),
});
```

---

## Privacy Considerations

1. **No raw identifiers stored** - Only SHA-256 hashes
2. **Hashes are salted** - Cannot be used across systems
3. **Short retention** - Cache TTL of 24 hours
4. **Minimal data** - Only what's needed for security
5. **No cross-tracking** - Fingerprints are user-specific

---

## Configuration

```php
// config/security.php
return [
    'device_fingerprint' => [
        // Cache TTL for fingerprint data (seconds)
        'cache_ttl' => 86400,
        
        // Maximum IP changes per device per day
        'max_ip_changes' => 5,
        
        // Maximum devices per user per day
        'max_devices_per_user' => 3,
        
        // Risk score thresholds
        'risk_thresholds' => [
            'monitor' => 30,
            'challenge' => 50,
            'block' => 80,
        ],
    ],
];
```

---

## Testing

```php
public function test_fingerprint_generation()
{
    $request = Request::create('/test', 'POST');
    $request->headers->set('User-Agent', 'AbsensiQR/1.0');
    $request->server->set('REMOTE_ADDR', '192.168.1.1');
    
    $service = app(DeviceFingerprintService::class);
    $fingerprint = $service->generate($request, userId: 123, deviceId: 'test-device');
    
    $this->assertNotEmpty($fingerprint->fingerprint);
    $this->assertNotEmpty($fingerprint->deviceHash);
    $this->assertEquals(123, $fingerprint->userId);
}

public function test_anomaly_detection_multi_user()
{
    $service = app(DeviceFingerprintService::class);
    
    // User 1 uses device
    $fp1 = $service->generate($request, userId: 1, deviceId: 'shared-device');
    $service->recordUsage($fp1, 1);
    
    // User 2 uses SAME device
    $fp2 = $service->generate($request, userId: 2, deviceId: 'shared-device');
    $service->recordUsage($fp2, 2);
    
    // Detect anomaly
    $result = $service->detectAnomalies($fp2, 2);
    
    $this->assertTrue($result->hasAnomalies);
    $this->assertContains('multi_user_device', 
        array_column($result->anomalies, 'type'));
}
```
