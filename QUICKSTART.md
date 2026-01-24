# 🚀 Quick Start Guide - absensiQRPRO

## Apa itu absensiQRPRO?

**absensiQRPRO** adalah sistem absensi berbasis QR Code untuk sekolah yang terdiri dari:
1. **Website** - Dashboard admin untuk mengelola data siswa dan melihat laporan
2. **Mobile App** - Aplikasi untuk guru scan kartu pelajar siswa

## 📋 Fitur Utama

### Website Admin
✅ Dashboard dengan statistik real-time  
✅ Manajemen database siswa  
✅ Generate QR Code otomatis  
✅ Cetak kartu pelajar  
✅ Laporan absensi harian  

### Mobile App Guru
✅ Login dengan akun guru  
✅ Scan QR Code kartu pelajar  
✅ Tandai kehadiran (Hadir/Terlambat/Izin)  
✅ Lihat riwayat absensi  
✅ Interface sederhana dan mudah digunakan  

## ⚡ Quick Setup (5 Menit)

### 1. Install Database

```bash
# Login ke MySQL
mysql -u root -p

# Buat database dan import schema
mysql -u root -p < website/backend/database/schema.sql
```

### 2. Setup Website

```bash
# Copy ke web server (XAMPP/WAMP/LAMP)
# Windows (XAMPP):
copy website C:\xampp\htdocs\absensiQRPRO

# Linux (Apache):
sudo cp -r website /var/www/html/absensiQRPRO
```

**Edit konfigurasi database:**
File: `website/backend/config/database.php`

```php
private $host = "localhost";
private $db_name = "absensi_qr_pro";
private $username = "root";
private $password = ""; // isi dengan password MySQL Anda
```

### 3. Akses Website

Buka browser: `http://localhost/absensiQRPRO/website/frontend/index.html`

### 4. Setup Mobile App (Opsional)

```bash
cd mobile-app
npm install
```

**Edit API URL:**
File: `mobile-app/src/api.js`

```javascript
// Ganti dengan IP server Anda
const API_BASE_URL = 'http://192.168.1.100/absensiQRPRO/website/backend/api';
```

**Run aplikasi:**
```bash
npm run android  # untuk Android
npm run ios      # untuk iOS (macOS only)
```

## 🎯 Testing - 3 Langkah

### Test 1: Website

1. Buka: `http://localhost/absensiQRPRO/website/frontend/index.html`
2. Lihat dashboard (total siswa: 5)
3. Klik "Data Siswa" → Klik "Tampilkan QR" pada siswa pertama
4. QR Code akan muncul

### Test 2: Mobile App

1. Buka aplikasi mobile
2. Login dengan:
   - Email: `budi@school.com`
   - Password: `teacher123`
3. Tap "Scan QR Code"
4. Scan QR Code dari website
5. Konfirmasi kehadiran

### Test 3: Verifikasi

1. Kembali ke website
2. Klik "Absensi" 
3. Pilih tanggal hari ini
4. Cek apakah siswa yang di-scan sudah muncul

## 📱 Demo Credentials

### Login Guru (Mobile App)
```
Email: budi@school.com
Password: teacher123
```
atau
```
Email: siti@school.com
Password: teacher123
```

### Sample QR Codes
Sudah ada 5 siswa dengan QR codes:
- `QR-NIS001-2024` - Ahmad Fauzi (10-A)
- `QR-NIS002-2024` - Dewi Lestari (10-A)
- `QR-NIS003-2024` - Eko Prasetyo (10-B)
- `QR-NIS004-2024` - Fitri Handayani (10-B)
- `QR-NIS005-2024` - Galih Saputra (11-A)

## 🎨 Preview Features

### Website Dashboard
```
┌────────────────────────────────────────┐
│  absensiQRPRO                          │
├────────────────────────────────────────┤
│  📊 Dashboard                           │
│                                         │
│  👨‍🎓 Total Siswa: 5                     │
│  ✓ Hadir Hari Ini: 3                   │
│  ✗ Tidak Hadir: 2                      │
│  📈 Persentase: 60%                    │
│                                         │
│  📋 Absensi Terbaru Hari Ini           │
│  ┌──────────────────────────────────┐  │
│  │ NIS001 | Ahmad | 10-A | 07:30   │  │
│  │ NIS002 | Dewi  | 10-A | 07:35   │  │
│  └──────────────────────────────────┘  │
└────────────────────────────────────────┘
```

### Mobile App Flow
```
1. Login Screen
   ↓
2. Home (Dashboard + Menu)
   ↓
3. Scan QR Code → Camera Opens
   ↓
4. Confirm Student Info
   ↓
5. Mark Attendance → Success!
```

## 📚 Documentation

Dokumentasi lengkap tersedia di folder `docs/`:

| File | Deskripsi |
|------|-----------|
| `INSTALLATION.md` | Panduan instalasi detail |
| `API.md` | Dokumentasi API endpoints |
| `USER_GUIDE.md` | Panduan pengguna (Admin & Guru) |
| `ARCHITECTURE.md` | Arsitektur sistem |

## 🔧 Troubleshooting Cepat

### ❌ Error: "Connection error"
**Solusi:** Cek database credentials di `config/database.php`

### ❌ QR Scanner tidak berfungsi
**Solusi:** 
1. Pastikan kamera permission di-enable
2. Test dengan QR code di website dulu

### ❌ Mobile app tidak connect ke server
**Solusi:**
1. Ganti `localhost` dengan IP address server
2. Pastikan firewall tidak block port 80
3. Test API di browser: `http://IP-SERVER/absensiQRPRO/website/backend/api/students.php`

### ❌ "Student not found" saat scan
**Solusi:**
1. Pastikan database sudah di-import
2. Cek apakah ada 5 sample students di database
3. Generate QR code dari website terlebih dahulu

## 🎓 Workflow Penggunaan

### Pagi Hari di Sekolah:

1. **Admin** membuka website, cek dashboard
2. **Siswa** datang ke sekolah dengan kartu pelajar
3. **Guru** buka aplikasi mobile
4. **Guru** scan QR code di kartu pelajar siswa satu per satu
5. **Siswa** masuk kelas
6. **Admin** bisa langsung lihat statistik kehadiran real-time di website

### End of Day:

1. **Admin** buka menu "Absensi"
2. Pilih tanggal hari ini
3. Lihat laporan lengkap:
   - Siapa yang hadir
   - Siapa yang terlambat
   - Siapa yang tidak hadir
4. Export atau print untuk arsip

## 💡 Tips & Best Practices

### Untuk Admin:
✅ Cetak kartu pelajar dengan QR code yang jelas  
✅ Laminasi kartu untuk ketahanan  
✅ Backup database secara berkala  
✅ Monitor statistik kehadiran setiap hari  

### Untuk Guru:
✅ Pastikan area cukup terang saat scan  
✅ Jaga jarak 15-30cm dari QR code  
✅ Scan dengan posisi kamera stabil  
✅ Verifikasi nama siswa sebelum konfirmasi  

### Untuk Siswa:
✅ Selalu bawa kartu pelajar  
✅ Jaga kartu tetap bersih dan tidak rusak  
✅ Scan di depan pintu masuk/kelas  

## 🚀 Next Steps

Setelah berhasil setup:

1. **Tambah Data Siswa**
   - Buka website → Data Siswa
   - Tambah siswa baru melalui database
   - Generate QR code
   - Cetak kartu pelajar

2. **Tambah Guru**
   - Insert ke table `teachers` di database
   - Guru bisa langsung login di mobile app

3. **Customize**
   - Ubah logo dan nama sekolah
   - Sesuaikan warna tema
   - Tambah field custom jika perlu

4. **Production Deployment**
   - Setup di server production
   - Enable HTTPS
   - Ganti password default
   - Setup backup otomatis

## 📞 Support

Ada masalah? Cek:
1. ✅ `docs/INSTALLATION.md` - Solusi umum masalah instalasi
2. ✅ `docs/USER_GUIDE.md` - Panduan lengkap cara pakai
3. ✅ `docs/API.md` - Troubleshoot API issues
4. ✅ GitHub Issues - Buat issue baru

## 🎉 Selamat!

Sistem absensi QR Code Anda sudah siap digunakan!

**Selamat mencoba dan semoga bermanfaat untuk sekolah Anda! 🎓**

---

Made with ❤️ for Indonesian Schools
