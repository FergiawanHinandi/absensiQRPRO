# Performance Testing Guide 🚀

## 1. Overview

Skenario load testing ini menguji dua endpoint kritis:
- **Write Intensive**: Attendance Check-In (POST `/api/v1/attendance/manual`)
- **Read Intensive**: Dashboard Summary (GET `/api/v1/teacher/dashboard`)

Load disimulasikan menggunakan **Artillery.io** dengan fase:
1. **Warm Up**: 10 user/detik (60s)
2. **Normal Load**: 100 user/detik (60s)
3. **High Load**: 500 user/detik (30s)

---

## 2. Prerequisites

### A. Install Artillery
```bash
npm install -g artillery
# Optional: install plugin for metrics
npm install -g artillery-plugin-metrics-by-endpoint
```

### B. Enable Performance Middleware
Pastikan `App\Http\Middleware\PerformanceMonitorMiddleware` terdaftar di `bootstrap/app.php` (global middleware) agar kita bisa melihat **Query Count** dan **Memory Usage** di response headers.

*(Sudah dibuat di `app/Http/Middleware/PerformanceMonitorMiddleware.php`)*

### C. Server Preparation
1. Pastikan database, Redis cache, dan queue worker jalan.
2. Jalankan server aplikasi (mode production disarankan):
```bash
php artisan optimize
php artisan serve --port=8000
```

---

## 3. Configuration

File konfigurasi ada di `backend/scripts/artillery/performance-test.yml`.

### Setup Token
Anda **HARUS** mengganti `YOUR_TEACHER_TOKEN_HERE` di file `performance-test.yml` dengan token JWT valid dari Guru yang sudah login.

Cara mendapatkan token:
```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"guru@sekolah.com", "password":"password"}'
```

---

## 4. Running the Test

Jalankan perintah berikut dari folder `backend/scripts/artillery`:

```bash
artillery run performance-test.yml --output report.json
```

Generate HTML report untuk visualisasi yang lebih baik:
```bash
artillery report report.json
# Buka report.html di browser
```

---

## 5. Expected Thresholds (Success Criteria)

### A. Response Time (Latency)

| Metric | Dashboard (Read) | Check-in (Write) | Status |
|--------|------------------|------------------|--------|
| **Median (p50)** | < 100ms | < 200ms | ✅ Excellent |
| **p95** | < 300ms | < 500ms | ⚠️ Warning |
| **p99** | < 1000ms | < 1500ms | ❌ Critical |

### B. Internal Metrics (Per Request)

| Metric | Threshold | Notes |
|--------|-----------|-------|
| **Query Count** | < 5 queries | Dashboard harus caching! |
| **Memory Usage** | < 20 MB | Optimalkan Eloquent usage |
| **Error Rate** | < 1% | HTTP 500/Timeout tidak boleh > 1% |

### C. Scalability

- **100 Concurrent Users**: CPU Load < 60%, No Errors.
- **500 Concurrent Users**: CPU Load < 90%, Queue Depth stabil.

---

## 6. Troubleshooting

### High Query Count?
- Cek apakah Cache Hit di Dashboard.
- Pastikan N+1 Query tidak terjadi (Gunakan `with()` di Eloquent).
- Lihat `X-Perf-Query-Count` header di response.

### 500 Errors saat Load Tinggi?
- Cek `max_connections` database.
- Cek batas `pm.max_children` di PHP-FPM.
- Cek Redis connection limits.

### High Memory Usage?
- Gunakan `cursor()` atau `chunk()` untuk data besar.
- Hindari memuat seluruh collection (`get()`) jika hanya butuh count.
