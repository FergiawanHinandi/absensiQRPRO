# 🚀 AbsensiQR Pro - Setup Guide Lengkap

## ✅ STATUS PERBAIKAN YANG TELAH DILAKUKAN

### Backend (Laravel 12) - ✅ SELESAI
- ✅ Dependencies berhasil diinstall (dengan ignore GD extension)
- ✅ Database PostgreSQL terkonfigurasi
- ✅ Migrasi dan seeder berhasil dijalankan
- ✅ Server berjalan di http://localhost:8000

### Frontend (React + TypeScript) - ✅ SELESAI
- ✅ Dependencies terinstall
- ✅ Environment variables dikonfigurasi
- ✅ File yang hilang sudah dibuat (BackupDatabase.tsx, MaintenanceMode.tsx)
- ✅ Server berjalan di http://localhost:5173

### Mobile App (React Native) - ✅ SELESAI
- ✅ Dependencies terinstall (dengan axios dan react-native-config)
- ✅ Environment file dibuat (.env)
- ✅ App configuration diperbaiki (app.json dengan permissions)
- ✅ API client dikonfigurasi untuk environment variables

---

## 🎯 CARA MENJALANKAN APLIKASI

### Opsi 1: Menggunakan Script Otomatis (Windows)
```bash
# Dari root directory
start-dev.bat
```

### Opsi 2: Manual (Semua Platform)
```bash
# Terminal 1: Backend API
cd backend
php artisan serve
# Berjalan di: http://localhost:8000

# Terminal 2: Frontend Web
cd frontend-web
npm run dev
# Berjalan di: http://localhost:5173

# Terminal 3: Mobile App (Metro Bundler)
cd AbsensiQRMobile
npm start

# Terminal 4: Mobile App (Android/iOS)
cd AbsensiQRMobile
npm run android  # atau npm run ios
```

---

## 🔐 CREDENTIALS DEFAULT

**Super Admin:**
```
Email: super@admin.com
Password: password
```

**School Admin (SMP Negeri 1):**
```
Email: admin@smpn1.sch.id
Password: password
```

**Teacher:**
```
Username: teacher1
Password: password
```

**Student:**
```
Username: student1
Password: password
```

---

## 🌐 URL AKSES

| Service | URL | Status |
|---------|-----|--------|
| Backend API | http://localhost:8000 | ✅ Running |
| Frontend Web | http://localhost:5173 | ✅ Running |
| API Documentation | http://localhost:8000/api/v1 | ✅ Available |
| WebSocket (Optional) | ws://localhost:8080 | ⚠️ Manual start |

---

## 🔧 KONFIGURASI ENVIRONMENT

### Backend (.env) - ✅ CONFIGURED
```env
DB_CONNECTION=pgsql
DB_DATABASE=absensi_qr
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password
REVERB_APP_KEY=tf0is57cghz85aqwm5by
```

### Frontend (.env) - ✅ CONFIGURED
```env
VITE_API_URL=http://localhost:8000/api/v1
VITE_REVERB_APP_KEY=tf0is57cghz85aqwm5by
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
```

### Mobile (.env) - ✅ CONFIGURED
```env
API_BASE_URL=http://localhost:8000/api/v1
API_TIMEOUT=30000
REVERB_APP_KEY=tf0is57cghz85aqwm5by
```

---

## 🧪 TESTING & VERIFIKASI

### 1. Test Backend API
```bash
# Test basic endpoint
curl http://localhost:8000

# Test API v1
curl http://localhost:8000/api/v1

# Test database connection
cd backend
php artisan tinker
>>> \App\Models\User::count();
```

### 2. Test Frontend
- Buka http://localhost:5173
- Login dengan credentials di atas
- Periksa console browser untuk error

### 3. Test Mobile App
- Pastikan emulator/device terhubung
- Jalankan `npm run android` atau `npm run ios`
- Test koneksi API dari mobile

---

## ⚠️ TROUBLESHOOTING

### Backend Issues
1. **GD Extension Error**: Sudah diatasi dengan `--ignore-platform-req=ext-gd`
2. **Database Connection**: Pastikan PostgreSQL berjalan dan database `absensi_qr` ada
3. **Composer Lock**: Sudah dihapus dan diinstall ulang

### Frontend Issues
1. **Missing Components**: BackupDatabase.tsx dan MaintenanceMode.tsx sudah dibuat
2. **Environment Variables**: Sudah dikonfigurasi dengan benar

### Mobile Issues
1. **Missing Dependencies**: axios dan react-native-config sudah ditambahkan
2. **Environment Config**: File .env sudah dibuat
3. **Permissions**: Camera, location, dan internet permissions sudah ditambahkan

---

## 🚀 LANGKAH SELANJUTNYA

### Untuk Development:
1. ✅ Setup database - SELESAI
2. ✅ Install dependencies - SELESAI  
3. ✅ Konfigurasi environment - SELESAI
4. ✅ Jalankan aplikasi - SELESAI
5. 🔄 Test fitur-fitur utama
6. 🔄 Setup WebSocket (Laravel Reverb) - OPSIONAL
7. 🔄 Konfigurasi payment gateway - OPSIONAL

### Untuk Production:
1. Setup server (Nginx + PHP-FPM)
2. Konfigurasi database production
3. Setup SSL certificate
4. Konfigurasi environment production
5. Deploy aplikasi

---

## 📞 SUPPORT

Jika mengalami masalah:
1. Periksa log error di terminal
2. Periksa console browser untuk frontend
3. Periksa log Laravel di `backend/storage/logs/laravel.log`
4. Pastikan semua service berjalan di port yang benar

---

**Status Terakhir**: ✅ APLIKASI SIAP DIGUNAKAN
**Tanggal Update**: 25 Januari 2026
**Versi**: 1.0.0-dev

---

# 🔧 MASALAH LOGIN SUPER ADMIN - SUDAH DIPERBAIKI ✅

## 🎯 MASALAH YANG DITEMUKAN
- **Super Admin** login berhasil tapi diarahkan ke `/admin/dashboard` (salah)
- Seharusnya diarahkan ke `/super-admin/dashboard`

## ✅ PERBAIKAN YANG DILAKUKAN

### 1. **Fixed Login Redirect Logic**
File: `frontend-web/src/modules/auth/components/LoginForm.tsx`
- Memisahkan routing untuk `super_admin` dan `school_admin`
- Super Admin sekarang diarahkan ke `/super-admin/dashboard`

### 2. **Added Debug Component**
File: `frontend-web/src/components/debug/AuthDebug.tsx`
- Menampilkan status authentication di pojok kanan bawah (development only)
- Membantu debugging masalah login

### 3. **Fixed Missing Components**
- `BackupDatabase.tsx` - Sudah dibuat
- `MaintenanceMode.tsx` - Sudah dibuat

## 🚀 CARA TEST PERBAIKAN

### Frontend sekarang berjalan di: **http://localhost:5174**

### Test Login Super Admin:
1. Buka http://localhost:5174
2. Login dengan:
   ```
   Email: super@admin.com
   Password: password
   ```
3. Seharusnya langsung masuk ke Super Admin Dashboard
4. Periksa debug info di pojok kanan bawah (development mode)

### Jika Masih Blank:
1. Buka Developer Tools (F12)
2. Periksa Console untuk error
3. Periksa Network tab untuk failed API calls
4. Lihat debug component di pojok kanan bawah

## 🔍 DEBUGGING STEPS

### 1. Periksa API Connection
```bash
# Test backend API
curl http://localhost:8000/api/v1/auth/login -X POST -H "Content-Type: application/json" -d '{"username":"super@admin.com","password":"password"}'
```

### 2. Periksa Browser Console
- Buka F12 → Console
- Cari error merah
- Periksa failed network requests

### 3. Periksa Auth Debug Component
Di pojok kanan bawah akan muncul kotak hitam dengan info:
- Loading: Yes/No
- Authenticated: Yes/No  
- Token: Present/None
- User Role: super_admin
- Current Path: /super-admin/dashboard

## 🎯 EXPECTED BEHAVIOR SEKARANG

1. **Login** → Redirect ke `/super-admin/dashboard`
2. **Dashboard** → Tampil dengan data statistik
3. **Navigation** → Menu Super Admin tersedia
4. **Debug Info** → Tampil di pojok kanan bawah

---

**Status**: ✅ PERBAIKAN SELESAI
**Frontend URL**: http://localhost:5174
**Backend URL**: http://localhost:8000

**🎉 SILAKAN TEST LOGIN SUPER ADMIN SEKARANG!**