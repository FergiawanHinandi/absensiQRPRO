# Penguatan Endpoint Absensi Terhadap Replay Attack

## 📋 Ringkasan

Implementasi lengkap untuk memperkuat endpoint absensi terhadap replay attack dengan menggunakan:
- ✅ **Idempotency Key** untuk mencegah request duplikat
- ✅ **Server Timestamp** sebagai sumber kebenaran waktu
- ✅ **Database Storage** dengan TTL untuk key management
- ✅ **Rate Limiting** khusus untuk endpoint attendance
- ✅ **Multi-layer Protection** (user, device, IP)

---

## 🔐 Fitur Keamanan

### 1. Idempotency Key Protection
**Masalah yang diselesaikan:**
- Request bisa dikirim ulang (replay attack)
- Double submission dari network retry
- Offline sync dengan pre-generated keys

**Solusi:**
- Client mengirim `X-Idempotency-Key` header (UUID v4)
- Server menyimpan key di database dengan TTL
- Request dengan key yang sama akan mengembalikan response yang di-cache

### 2. Server Timestamp Authority
**Masalah yang diselesaikan:**
- Client bisa manipulasi timestamp
- Clock tampering untuk bypass validasi waktu

**Solusi:**
- Server menggunakan `now()` untuk semua timestamp
- Client timestamp hanya untuk validasi, tidak untuk record
- Deteksi future timestamp sebagai security event

### 3. Rate Limiting Khusus
**Masalah yang diselesaikan:**
- Brute force attack
- Automated bot scanning
- Resource exhaustion

**Solusi:**
- Per-user rate limiting
- Per-device rate limiting
- Per-IP rate limiting (fallback)
- Configurable limits per endpoint type

---

## 📁 File yang Dibuat

### 1. Migration
**File:** `database/migrations/2026_02_07_134100_create_idempotency_keys_table.php`

**Struktur Tabel:**
```sql
CREATE TABLE idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    http_method VARCHAR(10) DEFAULT 'POST',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    device_id VARCHAR(255) NULL,
    response_payload TEXT NULL,
    response_status SMALLINT UNSIGNED DEFAULT 200,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL,
    
    UNIQUE KEY idempotency_unique (key, user_id, endpoint),
    INDEX idx_key (key),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX cleanup_index (expires_at, created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 2. Model
**File:** `app/Models/IdempotencyKey.php`

**Fungsi Utama:**
- `findExisting()` - Cari key yang sudah ada
- `store()` - Simpan key baru dengan response
- `isExpired()` - Check apakah key sudah expired
- `getCachedResponse()` - Ambil cached response

### 3. Middleware Idempotency
**File:** `app/Http/Middleware/IdempotencyMiddleware.php`

**Cara Kerja:**
1. Check header `X-Idempotency-Key`
2. Validasi format UUID v4
3. Cari key di database
4. Jika ada: return cached response
5. Jika tidak: proses request dan simpan key

**Usage:**
```php
Route::post('/attendance/scan')
    ->middleware('idempotency:60'); // TTL 60 menit
```

### 4. Middleware Rate Limiting
**File:** `app/Http/Middleware/AttendanceRateLimitMiddleware.php`

**Rate Limit Tiers:**
- QR Scan: 10 requests/minute
- Manual Entry: 30 requests/minute
- Report Export: 5 requests/minute
- Generate QR: 20 requests/minute

**Usage:**
```php
Route::post('/attendance/scan')
    ->middleware('attendance.rate.limit:qr-scan');
```

### 5. Cleanup Command
**File:** `app/Console/Commands/CleanupExpiredIdempotencyKeys.php`

**Fungsi:**
- Hapus key yang sudah expired
- Batch deletion untuk performa
- Progress bar untuk monitoring

**Usage:**
```bash
# Manual cleanup
php artisan idempotency:cleanup

# Force cleanup tanpa konfirmasi
php artisan idempotency:cleanup --force

# Custom retention period
php artisan idempotency:cleanup --days=7
```

---

## 🔧 Instalasi & Konfigurasi

### 1. Jalankan Migration
```bash
cd backend
php artisan migrate
```

### 2. Register Middleware
Middleware sudah otomatis terdaftar di `bootstrap/app.php`:
```php
'idempotency' => \App\Http\Middleware\IdempotencyMiddleware::class,
'attendance.rate.limit' => \App\Http\Middleware\AttendanceRateLimitMiddleware::class,
```

### 3. Update Routes
Routes sudah otomatis diupdate di `routes/api/v1/attendance.php`:
```php
// Student Scan
Route::middleware([
    'role:student',
    'attendance.security',
    'idempotency:60',
    'attendance.rate.limit:qr-scan',
])->group(function () {
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
});

// Teacher Scan
Route::post('/attendance/scan-student')
    ->middleware([
        'idempotency:60',
        'attendance.rate.limit:qr-scan',
    ]);

// Manual Entry
Route::post('/attendance/manual')
    ->middleware([
        'idempotency:30',
        'attendance.rate.limit:manual-entry',
    ]);
```

### 4. Schedule Cleanup Job
Tambahkan di `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // Cleanup expired idempotency keys setiap jam
    $schedule->command('idempotency:cleanup --force')
        ->hourly()
        ->withoutOverlapping()
        ->runInBackground();
}
```

---

## 📱 Contoh Penggunaan Client

### Mobile App (React Native / Flutter)

```javascript
// Generate UUID untuk idempotency key
import { v4 as uuidv4 } from 'uuid';

async function scanAttendance(qrData) {
    // Generate idempotency key (simpan untuk retry)
    const idempotencyKey = uuidv4();
    
    try {
        const response = await fetch('https://api.example.com/api/v1/attendance/scan', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json',
                'X-Idempotency-Key': idempotencyKey,  // PENTING!
                'X-Device-ID': deviceId,
            },
            body: JSON.stringify({
                qr_payload: qrData,
                lat: latitude,
                lng: longitude,
                device_id: deviceId,
                request_id: uuidv4(), // Untuk tracking internal
            }),
        });
        
        const data = await response.json();
        
        // Check jika ini adalah idempotent replay
        if (data._idempotent_replay) {
            console.log('Request sudah diproses sebelumnya:', data._original_timestamp);
        }
        
        return data;
        
    } catch (error) {
        // Jika network error, bisa retry dengan SAME idempotency key
        console.error('Network error, akan retry dengan key yang sama:', idempotencyKey);
        // Simpan idempotencyKey untuk retry
        await saveForRetry(idempotencyKey, qrData);
        throw error;
    }
}

// Retry dengan key yang sama
async function retryFailedRequest(savedKey, qrData) {
    const response = await fetch('https://api.example.com/api/v1/attendance/scan', {
        method: 'POST',
        headers: {
            'X-Idempotency-Key': savedKey, // GUNAKAN KEY YANG SAMA
            // ... headers lainnya
        },
        body: JSON.stringify(qrData),
    });
    
    // Server akan return response yang sama seperti request pertama
    return response.json();
}
```

### Web App (JavaScript)

```javascript
class AttendanceClient {
    constructor(apiUrl, token) {
        this.apiUrl = apiUrl;
        this.token = token;
        this.pendingRequests = new Map(); // Track pending requests
    }
    
    async scan(qrPayload, location) {
        // Generate idempotency key
        const idempotencyKey = this.generateUUID();
        
        // Check if request already pending
        if (this.pendingRequests.has(idempotencyKey)) {
            console.log('Request already in progress');
            return this.pendingRequests.get(idempotencyKey);
        }
        
        // Create request promise
        const requestPromise = this.executeRequest(idempotencyKey, qrPayload, location);
        
        // Track pending request
        this.pendingRequests.set(idempotencyKey, requestPromise);
        
        try {
            const result = await requestPromise;
            return result;
        } finally {
            // Cleanup
            this.pendingRequests.delete(idempotencyKey);
        }
    }
    
    async executeRequest(idempotencyKey, qrPayload, location) {
        const response = await fetch(`${this.apiUrl}/attendance/scan`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.token}`,
                'Content-Type': 'application/json',
                'X-Idempotency-Key': idempotencyKey,
            },
            body: JSON.stringify({
                qr_payload: qrPayload,
                lat: location.latitude,
                lng: location.longitude,
            }),
        });
        
        // Check rate limit headers
        const rateLimit = {
            limit: response.headers.get('X-RateLimit-Limit'),
            remaining: response.headers.get('X-RateLimit-Remaining'),
            reset: response.headers.get('X-RateLimit-Reset'),
        };
        
        console.log('Rate Limit:', rateLimit);
        
        if (response.status === 429) {
            const retryAfter = response.headers.get('Retry-After');
            throw new Error(`Rate limit exceeded. Retry after ${retryAfter} seconds`);
        }
        
        return response.json();
    }
    
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
}

// Usage
const client = new AttendanceClient('https://api.example.com/api/v1', userToken);

try {
    const result = await client.scan(qrData, {
        latitude: -6.2088,
        longitude: 106.8456,
    });
    
    console.log('Attendance recorded:', result);
} catch (error) {
    console.error('Failed to record attendance:', error);
}
```

---

## 🧪 Testing

### 1. Test Idempotency

```bash
# Request pertama
curl -X POST https://api.example.com/api/v1/attendance/scan \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{
    "qr_payload": "...",
    "lat": -6.2088,
    "lng": 106.8456
  }'

# Response:
# {
#   "success": true,
#   "message": "Absensi berhasil dicatat.",
#   "data": { ... }
# }

# Request kedua dengan KEY YANG SAMA
curl -X POST https://api.example.com/api/v1/attendance/scan \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{
    "qr_payload": "...",
    "lat": -6.2088,
    "lng": 106.8456
  }'

# Response (SAMA seperti request pertama):
# {
#   "success": true,
#   "message": "Absensi berhasil dicatat.",
#   "data": { ... },
#   "_idempotent_replay": true,
#   "_original_timestamp": "2026-02-07T13:41:17+08:00"
# }
```

### 2. Test Rate Limiting

```bash
# Kirim 11 request dalam 1 menit (limit: 10/min)
for i in {1..11}; do
  curl -X POST https://api.example.com/api/v1/attendance/scan \
    -H "Authorization: Bearer TOKEN" \
    -H "X-Idempotency-Key: $(uuidgen)" \
    -d '{"qr_payload": "..."}' \
    -w "\nStatus: %{http_code}\n"
  sleep 1
done

# Request ke-11 akan mendapat response:
# Status: 429
# {
#   "success": false,
#   "message": "Terlalu banyak permintaan. Silakan coba lagi nanti.",
#   "code": "RATE_LIMIT_EXCEEDED",
#   "data": {
#     "max_attempts": 10,
#     "retry_after_seconds": 60
#   }
# }
```

### 3. Test Invalid Idempotency Key

```bash
curl -X POST https://api.example.com/api/v1/attendance/scan \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Idempotency-Key: invalid-key-format" \
  -d '{"qr_payload": "..."}'

# Response:
# {
#   "success": false,
#   "message": "Invalid idempotency key format. Must be UUID v4.",
#   "code": "INVALID_IDEMPOTENCY_KEY"
# }
```

---

## 📊 Monitoring & Logging

### Security Logs
Semua event keamanan dicatat di `storage/logs/attendance_security.log`:

```json
{
  "timestamp": "2026-02-07T13:41:17+08:00",
  "event": "idempotent_request_detected",
  "key": "550e8400-e29b-41d4-a716-446655440000",
  "user_id": 123,
  "endpoint": "api/v1/attendance/scan",
  "original_created_at": "2026-02-07T13:40:00+08:00"
}

{
  "timestamp": "2026-02-07T13:42:00+08:00",
  "event": "rate_limit_exceeded",
  "user_id": 123,
  "limit_type": "qr-scan",
  "limit_key": "user",
  "severity": "MEDIUM",
  "recommendation": "Monitor for potential abuse or bot activity"
}
```

### Database Monitoring
Query untuk monitoring idempotency keys:

```sql
-- Total keys aktif
SELECT COUNT(*) FROM idempotency_keys WHERE expires_at > NOW();

-- Keys per user (top 10)
SELECT user_id, COUNT(*) as total
FROM idempotency_keys
WHERE expires_at > NOW()
GROUP BY user_id
ORDER BY total DESC
LIMIT 10;

-- Keys yang akan expired dalam 1 jam
SELECT COUNT(*) FROM idempotency_keys
WHERE expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR);

-- Cleanup statistics
SELECT 
    DATE(created_at) as date,
    COUNT(*) as total_keys,
    COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_keys
FROM idempotency_keys
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 7;
```

---

## 🔍 Troubleshooting

### Problem: "Request without idempotency key"
**Solusi:** Client harus mengirim header `X-Idempotency-Key` dengan UUID v4.

### Problem: "Invalid idempotency key format"
**Solusi:** Pastikan key menggunakan format UUID v4 yang valid.

### Problem: "Rate limit exceeded"
**Solusi:** 
- Tunggu sesuai `retry_after_seconds`
- Check apakah ada bot/automation yang berlebihan
- Review rate limit configuration jika legitimate traffic

### Problem: Idempotency key tidak bekerja
**Solusi:**
- Check apakah migration sudah dijalankan
- Verify middleware terdaftar di bootstrap/app.php
- Check database connection

---

## 📈 Performance Considerations

### Database Indexes
Tabel `idempotency_keys` sudah memiliki index optimal:
- `idx_key` - Fast lookup by key
- `idx_user_id` - Fast lookup by user
- `idx_expires_at` - Fast cleanup queries
- `idempotency_unique` - Enforce uniqueness

### Cleanup Strategy
- Jalankan cleanup setiap jam
- Batch deletion (1000 records per batch)
- Background processing untuk menghindari blocking

### Cache Considerations
- Response payload disimpan sebagai JSON text
- Maksimal size ~64KB per response (MySQL TEXT limit)
- Untuk response besar, pertimbangkan compression

---

## ✅ Checklist Implementasi

- [x] Migration untuk tabel `idempotency_keys`
- [x] Model `IdempotencyKey`
- [x] Middleware `IdempotencyMiddleware`
- [x] Middleware `AttendanceRateLimitMiddleware`
- [x] Command `CleanupExpiredIdempotencyKeys`
- [x] Register middleware di bootstrap
- [x] Update routes dengan middleware baru
- [x] Dokumentasi lengkap
- [x] Contoh penggunaan client
- [ ] Jalankan migration
- [ ] Schedule cleanup job
- [ ] Test idempotency
- [ ] Test rate limiting
- [ ] Monitor logs

---

## 🎯 Next Steps

1. **Jalankan Migration:**
   ```bash
   php artisan migrate
   ```

2. **Schedule Cleanup Job:**
   Tambahkan di `app/Console/Kernel.php`

3. **Update Mobile App:**
   Implementasikan UUID generation dan idempotency key handling

4. **Monitor Production:**
   - Check security logs
   - Monitor database size
   - Review rate limit effectiveness

5. **Fine-tune Rate Limits:**
   Sesuaikan limit berdasarkan usage pattern production

---

**Dibuat:** 2026-02-07  
**Versi:** 1.0.0  
**Author:** Security Team
