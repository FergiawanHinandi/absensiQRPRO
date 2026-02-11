# Panduan Troubleshooting AbsensiQRPro

## Daftar Isi
1. [Masalah Umum Aplikasi Mobile](#masalah-umum-aplikasi-mobile)
2. [Masalah QR Code](#masalah-qr-code)
3. [Masalah Login & Autentikasi](#masalah-login--autentikasi)
4. [Masalah Jaringan](#masalah-jaringan)
5. [Masalah Backend/Server](#masalah-backendserver)
6. [Masalah Database](#masalah-database)
7. [Debug Procedures](#debug-procedures)
8. [Log Analysis](#log-analysis)
9. [Recovery Procedures](#recovery-procedures)
10. [Kontak Support](#kontak-support)

---

## Masalah Umum Aplikasi Mobile

### 1. Aplikasi Crash Saat Dibuka

**Gejala:**
- Aplikasi terbuka sebentar lalu tertutup
- Muncul pesan "AbsensiQRPro has stopped"

**Penyebab & Solusi:**

| Penyebab | Solusi |
|----------|--------|
| Cache rusak | Bersihkan cache: Settings → Apps → AbsensiQRPro → Clear Cache |
| Versi OS tidak didukung | Minimum Android 8.0 / iOS 13.0 |
| Storage penuh | Hapus data tidak penting, pastikan minimal 100MB kosong |
| Instalasi corrupt | Uninstall lalu install ulang dari store |

**Langkah Debug:**
```bash
# Android - Lihat crash log via ADB
adb logcat | grep -i "absensi"

# iOS - Lihat crash report
# Settings → Privacy → Analytics → Analytics Data
```

### 2. Aplikasi Lambat/Lag

**Gejala:**
- Transisi antar halaman lambat
- Scroll terasa patah-patah
- Loading spinner terus muncul

**Solusi:**
1. Pastikan koneksi internet stabil (minimal 3G/4G)
2. Tutup aplikasi lain yang tidak digunakan
3. Restart perangkat
4. Update aplikasi ke versi terbaru
5. Jika masih lambat, laporkan ke IT

### 3. Notifikasi Tidak Masuk

**Android:**
1. Buka Settings → Apps → AbsensiQRPro
2. Tap "Notifications" → Enable All
3. Periksa Battery Optimization → Set "Don't optimize"
4. Periksa Do Not Disturb mode

**iOS:**
1. Buka Settings → AbsensiQRPro
2. Enable "Allow Notifications"
3. Enable "Background App Refresh"

---

## Masalah QR Code

### 1. QR Code Tidak Terbaca

**Error:** "QR tidak dapat dipindai"

**Solusi berurutan:**
1. **Jarak**: Sesuaikan jarak 20-50cm dari layar
2. **Cahaya**: Pastikan tidak ada pantulan di layar
3. **Fokus**: Tunggu kamera fokus (1-2 detik)
4. **Kebersihan**: Bersihkan lensa kamera
5. **Retry**: Tap area kamera untuk re-focus

### 2. QR Sudah Kadaluarsa

**Error:** `QR_EXPIRED - QR Code sudah tidak berlaku`

**Penyebab:**
- QR Code hanya valid 30 detik - 5 menit
- Waktu device tidak sinkron dengan server

**Solusi:**
1. Minta guru generate QR baru
2. Sinkronkan waktu device: Settings → Date & Time → Automatic
3. Jika berulang, cek koneksi internet

### 3. Di Luar Radius/Area

**Error:** `OUTSIDE_RADIUS - Anda berada di luar area yang diizinkan`

**Solusi:**
1. Pastikan **GPS aktif** dan memiliki akurasi baik
2. Beri izin lokasi "While Using App"
3. Pastikan berada **di dalam gedung sekolah**
4. Jika di dalam tapi tetap error:
   - Tunggu 10 detik agar GPS lock
   - Pindah ke area dengan sinyal GPS lebih baik
   - Laporkan ke admin jika radius sekolah perlu diperbesar

### 4. Sudah Absen Sebelumnya

**Error:** `ALREADY_CHECKED_IN - Anda sudah melakukan absensi`

**Ini bukan error**, melainkan konfirmasi bahwa:
- Absensi Anda sudah tercatat sebelumnya
- Tidak bisa absen 2x untuk jadwal yang sama

### 5. QR Generator Error (Guru)

**Error saat generate QR:**

| Error | Solusi |
|-------|--------|
| "Tidak ada jadwal aktif" | Pastikan jadwal sudah diatur admin |
| "Di luar jam pelajaran" | Generate hanya bisa ±10 menit dari jadwal |
| "Lokasi tidak terdeteksi" | Aktifkan GPS dan beri izin lokasi |

---

## Masalah Login & Autentikasi

### 1. Username/Password Salah

**Error:** `INVALID_CREDENTIALS`

**Langkah:**
1. Pastikan caps lock tidak aktif
2. Periksa username (siswa: NISN, guru: NIP)
3. Coba password default: tanggal lahir DDMMYYYY
4. Gunakan "Lupa Password" jika tetap gagal

### 2. Akun Tidak Aktif

**Error:** `ACCOUNT_INACTIVE - Akun Anda sedang tidak aktif`

**Penyebab:**
- Akun dinonaktifkan admin
- Masa aktif berakhir (alumni)
- Pelanggaran kebijakan

**Solusi:**
Hubungi admin sekolah untuk aktivasi ulang.

### 3. Device Tidak Dikenal

**Error:** `DEVICE_NOT_REGISTERED`

**Penyebab:**
- Login dari perangkat baru (guru)
- Device limit tercapai

**Solusi:**
1. **Guru**: Minta admin mendaftarkan device baru
2. **Siswa**: Logout dari device lama terlebih dahulu

### 4. Session Expired

**Error:** `TOKEN_EXPIRED - Sesi Anda telah berakhir`

**Penyebab:**
- Token access expired (15 menit tanpa aktivitas)
- Refresh token expired (7 hari)
- Logout dari device lain

**Solusi:**
Aplikasi akan otomatis redirect ke halaman login. Login kembali.

### 5. Too Many Attempts

**Error:** `TOO_MANY_ATTEMPTS - Terlalu banyak percobaan login`

**Solusi:**
- Tunggu **15 menit** sebelum mencoba lagi
- Pastikan kredensial benar sebelum retry
- Jika lupa password, gunakan fitur reset

---

## Masalah Jaringan

### 1. Connection Error

**Error:** `NETWORK_ERROR - Tidak dapat terhubung ke server`

**Diagnosa:**
1. Cek koneksi internet (buka browser, akses google.com)
2. Coba switch WiFi ↔ Data seluler
3. Restart perangkat
4. Cek status server di status.your-domain.com

### 2. Request Timeout

**Error:** `TIMEOUT - Request memakan waktu terlalu lama`

**Penyebab:**
- Koneksi lambat
- Server sedang overloaded
- Request terlalu besar

**Solusi:**
1. Coba lagi dalam 30 detik
2. Gunakan koneksi yang lebih stabil
3. Jika di jam sibuk (pagi), tunggu beberapa menit

### 3. SSL/Certificate Error

**Error:** "SSL Handshake Failed" atau "Certificate Error"

**Mobile App:**
- Pastikan OS dan aplikasi up-to-date
- Set waktu perangkat ke automatic

**Backend Admin:**
```bash
# Cek certificate validity
openssl s_client -connect your-domain.com:443 -servername your-domain.com

# Renew Let's Encrypt
sudo certbot renew --force-renewal
```

---

## Masalah Backend/Server

### 1. 500 Internal Server Error

**Diagnosa:**
```bash
# Cek Laravel logs
tail -f storage/logs/laravel.log

# Cek PHP error log
tail -f /var/log/php-fpm/error.log

# Cek Nginx error
tail -f /var/log/nginx/error.log
```

**Perbaikan Umum:**
```bash
# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Restart services
sudo systemctl restart php8.4-fpm
sudo systemctl restart nginx
```

### 2. 503 Service Unavailable

**Penyebab:**
- Maintenance mode aktif
- Server overloaded
- Upstream tidak responsif

**Solusi:**
```bash
# Cek maintenance mode
php artisan down:status

# Matikan maintenance mode
php artisan up

# Cek PHP-FPM
sudo systemctl status php8.4-fpm

# Cek queue worker
sudo supervisorctl status all
```

### 3. Queue Jobs Stuck

**Gejala:**
- Notifikasi tidak terkirim
- Report tidak tergenerate

**Diagnosa:**
```bash
# Cek failed jobs
php artisan queue:failed

# Lihat pending jobs
php artisan queue:monitor

# Di Redis
redis-cli LLEN queues:default
```

**Perbaikan:**
```bash
# Restart worker
sudo supervisorctl restart laravel-worker:*

# Retry failed jobs
php artisan queue:retry all

# Flush semua jobs (HATI-HATI)
php artisan queue:flush
```

### 4. WebSocket Tidak Terhubung

**Gejala:**
- Data tidak real-time
- Siswa baru scan tidak muncul langsung

**Diagnosa:**
```bash
# Cek Reverb running
sudo supervisorctl status laravel-reverb

# Test WebSocket
websocat wss://ws.your-domain.com/app/your-app-key
```

**Perbaikan:**
```bash
# Restart Reverb
sudo supervisorctl restart laravel-reverb

# Cek firewall
sudo ufw status
sudo ufw allow 6001/tcp  # atau port WebSocket Anda
```

---

## Masalah Database

### 1. Connection Refused

**Error:** `SQLSTATE[HY000] [2002] Connection refused`

```bash
# Cek PostgreSQL running
sudo systemctl status postgresql

# Start jika mati
sudo systemctl start postgresql

# Cek port
sudo netstat -tlpn | grep 5432
```

### 2. Too Many Connections

**Error:** `SQLSTATE[08006]: too many connections`

```bash
# Cek connections
sudo -u postgres psql -c "SELECT count(*) FROM pg_stat_activity;"

# Kill idle connections
sudo -u postgres psql -c "
SELECT pg_terminate_backend(pid) 
FROM pg_stat_activity 
WHERE state = 'idle' 
AND query_start < now() - interval '10 minutes';
"

# Atau restart PostgreSQL
sudo systemctl restart postgresql
```

### 3. Deadlock Detected

**Error:** `deadlock detected`

**Diagnosa:**
```bash
# Check blocking queries
sudo -u postgres psql -c "
SELECT blocked.pid AS blocked_pid,
       blocking.pid AS blocking_pid,
       blocked.query AS blocked_query
FROM pg_locks blocked
JOIN pg_stat_activity ON blocked.pid = pg_stat_activity.pid
JOIN pg_locks blocking ON blocked.transactionid = blocking.transactionid
WHERE blocked.granted = false;
"
```

**Solusi:**
- Retry transaction (aplikasi sudah handle retry)
- Jika persistent, optimize query lambat

### 4. Migration Failed

```bash
# Rollback migration terakhir
php artisan migrate:rollback --step=1

# Cek status migration
php artisan migrate:status

# Fresh migration (HANYA DI DEVELOPMENT)
php artisan migrate:fresh --seed
```

---

## Debug Procedures

### Enable Debug Mode (Development Only)

```env
# .env
APP_DEBUG=true
APP_LOG_LEVEL=debug
```

> ⚠️ **JANGAN AKTIFKAN** di production!

### Request Tracing

```bash
# Tambahkan request ID untuk tracking
# Di header response ada X-Request-Id

# Cari di log
grep "request_id_here" storage/logs/laravel.log
```

### Performance Profiling

```bash
# Enable query log
DB::enableQueryLog();
// ... code ...
dd(DB::getQueryLog());

# Check slow queries
sudo -u postgres psql -c "
SELECT query, calls, mean_time 
FROM pg_stat_statements 
ORDER BY mean_time DESC 
LIMIT 10;
"
```

### Memory Leak Detection

```bash
# Monitor PHP memory
watch -n 1 "ps aux | grep php-fpm | awk '{sum+=\$6} END {print sum/1024 \"MB\"}'"

# Jika terus naik, restart FPM
sudo systemctl restart php8.4-fpm
```

---

## Log Analysis

### Log Locations

| Service | Location |
|---------|----------|
| Laravel | `storage/logs/laravel.log` |
| Nginx Access | `/var/log/nginx/access.log` |
| Nginx Error | `/var/log/nginx/error.log` |
| PHP-FPM | `/var/log/php-fpm/error.log` |
| PostgreSQL | `/var/log/postgresql/postgresql-*.log` |
| Supervisor | `/var/log/supervisor/` |
| System | `/var/log/syslog` |

### Useful Commands

```bash
# Real-time Laravel errors
tail -f storage/logs/laravel.log | grep -i "error\|exception"

# Count errors per hour
grep "$(date '+%Y-%m-%d')" storage/logs/laravel.log | grep -c ERROR

# Find specific user activity
grep "user_id.*123" storage/logs/laravel.log

# Nginx top IPs
awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head -20

# 5xx errors
grep ' 5[0-9][0-9] ' /var/log/nginx/access.log
```

### Sentry Integration

Error otomatis dilaporkan ke Sentry. Akses dashboard:
1. Buka https://sentry.io/
2. Login dengan akun organisasi
3. Pilih project "AbsensiQRPro-Backend"
4. Filter berdasarkan severity/user/browser

---

## Recovery Procedures

### 1. Server Crash Recovery

```bash
# 1. Check and start services
sudo systemctl start nginx
sudo systemctl start php8.4-fpm
sudo systemctl start postgresql
sudo systemctl start redis

# 2. Check Laravel
cd /var/www/absensi
php artisan optimize
php artisan config:cache
php artisan route:cache

# 3. Start background jobs
sudo supervisorctl start all

# 4. Check health
curl http://localhost/api/v1/health
```

### 2. Database Restore

```bash
# Stop aplikasi
php artisan down

# Restore dari backup
pg_restore -h localhost -U postgres -d absensi_prod /backups/daily/absensi_latest.dump

# Verify
php artisan migrate:status

# Back online
php artisan up
```

### 3. Redis Data Loss

```bash
# Restart Redis
sudo systemctl restart redis

# Rebuild cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# Queue jobs akan hilang, perlu manual check:
# - Notifikasi pending
# - Report generation pending
```

### 4. Full System Recovery

Lihat [DEPLOYMENT_GUIDE.md](./DEPLOYMENT_GUIDE.md#pemulihan-dari-backup) untuk prosedur lengkap.

---

## Health Check Commands

```bash
# Quick health check
curl -s http://localhost/api/v1/health | jq

# Extended check
php artisan health:check

# Service status
systemctl status nginx php8.4-fpm postgresql redis supervisor --no-pager
```

---

## Kontak Support

### Level 1 - Help Desk
- **Untuk:** User (siswa, guru, orang tua)
- **Scope:** Reset password, panduan penggunaan
- **Email:** support@absensi-sekolah.com
- **WA:** +62-xxx-xxx-xxxx
- **Jam:** Senin-Jumat, 08:00-17:00 WIB

### Level 2 - Technical Support
- **Untuk:** Admin sekolah
- **Scope:** Konfigurasi sistem, import data, bug report
- **Email:** tech@absensi-sekolah.com
- **Response:** 1x24 jam kerja

### Level 3 - Engineering
- **Untuk:** Sistem kritis down
- **Scope:** Server issues, security incidents
- **Hotline:** +62-xxx-xxx-xxxx (24/7)
- **Email:** emergency@absensi-sekolah.com

### Escalation Matrix

| Severity | Response Time | Resolution Time | Contact |
|----------|---------------|-----------------|---------|
| Critical (Down) | 15 menit | 4 jam | Hotline |
| High (Major Bug) | 1 jam | 24 jam | Tech Email |
| Medium (Bug) | 4 jam | 72 jam | Tech Email |
| Low (Enhancement) | 24 jam | 2 minggu | Support Email |

---

## Checklist Troubleshooting

Sebelum eskalasi, pastikan sudah:

- [ ] Restart aplikasi/browser
- [ ] Clear cache
- [ ] Cek koneksi internet
- [ ] Coba device/browser lain
- [ ] Screenshot error message
- [ ] Catat langkah-langkah reproduksi
- [ ] Cek di known issues list

---

*Dokumen ini terakhir diperbarui: Februari 2026*
