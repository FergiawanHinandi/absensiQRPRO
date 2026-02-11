# 📊 Benchmark & Stress Test Results

## 1. Overview

Dokumen ini mencatat hasil pengujian ketahanan sistem (Resilience Testing) terhadap beban tinggi dan upaya DoS ringan.

**Target System:**
- Endpoint: `POST /api/v1/attendance/manual` (Check-in)
- Endpoint: `POST /api/v1/auth/login` (Auth)
- Environment: Local Development (XAMPP/Docker)

---

## 2. Test Scenarios

### A. Distributed Load (100 Concurrent Users)
Simulasi 100 siswa berbeda melakukan check-in bersamaan.

**Hasil Pengujian:**
| Metric | Result | Target | Status |
|--------|--------|--------|--------|
| **Availability** | 100% (100/100 Success) | > 99% | ✅ PASS |
| **Avg Response** | ~45ms | < 200ms | ✅ PASS |
| **Throughput** | ~180 req/sec | > 100 req/sec | ✅ PASS |
| **Error Rate** | 0% | < 1% | ✅ PASS |

**Analisis:**
Sistem mampu menangani lonjakan traffic normal tanpa degradasi performa signifikan. Penggunaan resource (CPU/RAM) stabil.

### B. Race Condition (50 Requests - Same User)
Simulasi 50 request check-in untuk USER YANG SAMA secara serentak (mencoba duplikasi data).

**Hasil Pengujian:**
| Metric | Result | Target | Status |
|--------|--------|--------|--------|
| **Success (201)** | 1 Request | Exactly 1 | ✅ PASS |
| **Conflict (409)** | 49 Requests | Exactly 49 | ✅ PASS |
| **Deadlock** | 0 detected | 0 | ✅ PASS |
| **Data Integrity** | 1 Record Created | 1 Record | ✅ PASS |

**Analisis:**
Mechanism Locking (Unique Constraint + App Level Lock) berfungsi sempurna. Tidak terjadi *double spending* atau duplikasi record. System menolak request paralel ke resource yang sama dengan cepat.

### C. Brute Force Login (50 Rapid Requests)
Simulasi serangan brute force password.

**Hasil Pengujian:**
| Metric | Result | Target | Status |
|--------|--------|--------|--------|
| **Processed** | 5 Requests | Max 5 | ✅ PASS |
| **Blocked (429)** | 45 Requests | Min 45 | ✅ PASS |
| **Block Duration** | 60 seconds | 60s | ✅ PASS |

**Analisis:**
Rate Limiter (Throttle) berjalan efektif melindungi endpoint Auth. IP penyerang diblokir sementara setelah 5 percobaan gagal.

---

## 3. Risks & Recommendations

### Risks Identified
1. **Network Saturation:** Pada load > 500 req/sec (simulasi Artillery sebelumnya), network interface local mungkin menjadi bottleneck sebelum aplikasi.
2. **Database Connection:** Connection pool default (100) mungkin habis jika response time DB melambat.

### Recommendations
1. **Queueing:** Untuk skala > 1000 user/detik, pindahkan proses check-in ke *Asynchronous Job Queue* (Redis). Cukup terima request, validasi ringan, lalu `dispatch(new ProcessAttendance($data))`. (Sudah dipertimbangkan untuk V2).
2. **Read Replica:** Pisahkan DB Read/Write jika dashboard menjadi lambat saat jam sibuk check-in.

---

## 4. How to Reproduce

Gunakan script `scripts/dos/dos_simulation.php`:

```bash
# Install Guzzle (required)
composer require guzzlehttp/guzzle

# Run Simulation
php scripts/dos/dos_simulation.php
```

*Pastikan server berjalan (`php artisan serve`) sebelum testing.*
