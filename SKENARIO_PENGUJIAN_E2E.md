# Skenario Pengujian End-to-End - AbsensiQR Pro

## Persiapan Lingkungan Pengujian

### Prasyarat
- Backend API berjalan di `http://localhost:8000`
- Frontend Web berjalan di `http://localhost:5173`
- Aplikasi mobile terinstal di perangkat uji
- Database uji dengan kondisi bersih
- Kredensial uji yang valid untuk semua peran

### Persiapan Data Uji
```sql
-- Sekolah Uji
INSERT INTO schools (name, code, address) VALUES ('SMA Negeri 1 Jakarta', 'SMAN1JKT', 'Jakarta Selatan');

-- Pengguna Uji
INSERT INTO users (name, email, role) VALUES 
  ('Admin Sekolah', 'admin@sman1jkt.sch.id', 'school_admin'),
  ('Guru Matematika', 'guru.math@sman1jkt.sch.id', 'teacher'),
  ('Siswa Uji', 'siswa001@sman1jkt.sch.id', 'student'),
  ('Orang Tua Siswa', 'ortu001@gmail.com', 'parent');
```

---

## Alur 1: Admin Sekolah Mengatur Struktur Akademik

### Skenario Pengujian: Pengaturan Lengkap Struktur Akademik
**Prioritas**: P0 (Kritis)  
**Durasi**: ~15 menit  
**Peran**: Admin Sekolah

### Langkah-langkah Pengujian

#### 1.1 Login sebagai Admin Sekolah
```
POST /api/v1/auth/login
{
  "email": "admin@sman1jkt.sch.id",
  "password": "password123"
}
```
**Hasil yang Diharapkan**: 
- Status: 200
- Response berisi `access_token` dan peran pengguna `school_admin`
- Redirect ke dashboard admin

#### 1.2 Membuat Tahun Ajaran
```
POST /api/v1/academic-years
{
  "name": "2024/2025",
  "start_date": "2024-07-15",
  "end_date": "2025-06-30",
  "is_active": true
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Tahun ajaran dibuat dengan ID otomatis
- Ditetapkan sebagai tahun ajaran aktif

#### 1.3 Membuat Tingkat Kelas
```
POST /api/v1/grades
{
  "name": "Kelas X",
  "level": 10,
  "academic_year_id": 1
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Tingkat kelas dibuat dan terhubung ke tahun ajaran
- Ulangi untuk Kelas XI, XII

#### 1.4 Membuat Kelas
```
POST /api/v1/classes
{
  "name": "X-IPA-1",
  "grade_id": 1,
  "homeroom_teacher_id": 2,
  "capacity": 36
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Kelas dibuat dengan penugasan wali kelas
- Validasi kapasitas diterapkan

#### 1.5 Membuat Mata Pelajaran
```
POST /api/v1/subjects
{
  "name": "Matematika",
  "code": "MTK",
  "credit_hours": 4
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Mata pelajaran dibuat dengan kode unik
- Jam kredit tercatat

#### 1.6 Membuat Jadwal
```
POST /api/v1/schedules
{
  "class_id": 1,
  "subject_id": 1,
  "teacher_id": 2,
  "day_of_week": 1,
  "start_time": "07:30",
  "end_time": "09:00",
  "room": "Lab Komputer 1"
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Jadwal dibuat tanpa konflik
- Validasi slot waktu berhasil

### Endpoint API yang Terlibat
- `POST /api/v1/auth/login`
- `POST /api/v1/academic-years`
- `POST /api/v1/grades`
- `POST /api/v1/classes`
- `POST /api/v1/subjects`
- `POST /api/v1/schedules`
- `GET /api/v1/dashboard/admin` (verifikasi)

### Kasus Tepi (Edge Cases)

#### 1.E1 Tahun Ajaran Duplikat
**Pengujian**: Membuat tahun ajaran dengan nama yang sama
**Diharapkan**: Status 422, error validasi "Tahun ajaran sudah ada"

#### 1.E2 Konflik Jadwal
**Pengujian**: Membuat jadwal yang bertumpang tindih untuk guru yang sama
**Diharapkan**: Status 422, error validasi "Guru memiliki jadwal yang bertabrakan"

#### 1.E3 Rentang Waktu Tidak Valid
**Pengujian**: Membuat jadwal dengan end_time sebelum start_time
**Diharapkan**: Status 422, error validasi "Waktu selesai harus setelah waktu mulai"

---

## Alur 2: Pembuatan Kartu Siswa Massal & Review Foto

### Skenario Pengujian: Pembuatan Kartu Identitas Siswa dengan Validasi Foto AI
**Prioritas**: P1 (Tinggi)  
**Durasi**: ~20 menit  
**Peran**: Admin Sekolah

### Langkah-langkah Pengujian

#### 2.1 Upload Data Siswa (Excel)
```
POST /api/v1/students/bulk-import
Content-Type: multipart/form-data
{
  "file": data_siswa.xlsx,
  "class_id": 1
}
```
**Hasil yang Diharapkan**:
- Status: 202 (Diterima)
- Job dimasukkan ke antrian untuk pemrosesan background
- Mengembalikan job_id untuk pelacakan

#### 2.2 Memantau Progress Import
```
GET /api/v1/jobs/{job_id}/status
```
**Hasil yang Diharapkan**:
- Status: 200
- Persentase progress dan status saat ini
- Detail error jika ada validasi yang gagal

#### 2.3 Review Siswa yang Diimpor
```
GET /api/v1/students?class_id=1&status=pending_photo
```
**Hasil yang Diharapkan**:
- Status: 200
- Daftar siswa tanpa foto
- Data siswa berhasil diimpor dengan benar

#### 2.4 Upload Foto Siswa (Massal)
```
POST /api/v1/students/photos/bulk-upload
Content-Type: multipart/form-data
{
  "photos": [foto1.jpg, foto2.jpg, ...],
  "mapping": {"12345": "foto1.jpg", "12346": "foto2.jpg"}
}
```
**Hasil yang Diharapkan**:
- Status: 202
- Foto dimasukkan ke antrian untuk pemrosesan AI
- Job deteksi wajah dimulai

#### 2.5 Review Hasil Pemrosesan Foto AI
```
GET /api/v1/students/photos/review
```
**Hasil yang Diharapkan**:
- Status: 200
- Foto dikategorikan: disetujui, perlu_review, ditolak
- Skor kepercayaan AI ditampilkan

#### 2.6 Persetujuan Foto Manual
```
PATCH /api/v1/students/{student_id}/photo/approve
{
  "action": "approve",
  "cropped_coordinates": {"x": 100, "y": 50, "width": 200, "height": 250}
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Foto disetujui dan dipotong
- Status siswa diperbarui menjadi "aktif"

#### 2.7 Generate Kartu Identitas Siswa
```
POST /api/v1/students/cards/generate
{
  "class_ids": [1, 2, 3],
  "template": "standard",
  "include_qr": true
}
```
**Hasil yang Diharapkan**:
- Status: 202
- Job pembuatan kartu dimasukkan ke antrian
- Pembuatan PDF dimulai

#### 2.8 Download Kartu yang Dibuat
```
GET /api/v1/students/cards/download/{batch_id}
```
**Hasil yang Diharapkan**:
- Status: 200
- File PDF dengan semua kartu siswa
- QR code tertanam untuk setiap siswa

### Endpoint API yang Terlibat
- `POST /api/v1/students/bulk-import`
- `GET /api/v1/jobs/{job_id}/status`
- `GET /api/v1/students`
- `POST /api/v1/students/photos/bulk-upload`
- `GET /api/v1/students/photos/review`
- `PATCH /api/v1/students/{id}/photo/approve`
- `POST /api/v1/students/cards/generate`
- `GET /api/v1/students/cards/download/{batch_id}`

### Kasus Tepi

#### 2.E1 Format Excel Tidak Valid
**Pengujian**: Upload Excel dengan kolom wajib yang hilang
**Diharapkan**: Status 422, error validasi detail per baris

#### 2.E2 ID Siswa Duplikat
**Pengujian**: Import siswa dengan student_id yang sudah ada
**Diharapkan**: Status 422, error "ID Siswa sudah ada"

#### 2.E3 Wajah Tidak Terdeteksi
**Pengujian**: Upload foto tanpa wajah yang dapat dideteksi
**Diharapkan**: Foto ditandai sebagai "perlu_review", persetujuan manual diperlukan

#### 2.E4 Beberapa Wajah Terdeteksi
**Pengujian**: Upload foto dengan beberapa orang
**Diharapkan**: Foto ditandai untuk review manual dengan opsi pemilihan wajah

---

## Alur 3: Guru Memulai Sesi Absensi QR

### Skenario Pengujian: Membuat dan Mengelola Sesi Absensi
**Prioritas**: P0 (Kritis)  
**Durasi**: ~10 menit  
**Peran**: Guru

### Langkah-langkah Pengujian

#### 3.1 Login Guru
```
POST /api/v1/auth/login
{
  "email": "guru.math@sman1jkt.sch.id",
  "password": "password123"
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Peran guru terautentikasi
- Akses ke dashboard guru

#### 3.2 Lihat Jadwal Hari Ini
```
GET /api/v1/teacher/schedule/today
```
**Hasil yang Diharapkan**:
- Status: 200
- Daftar kelas hari ini dengan slot waktu
- Sesi saat ini/mendatang disorot

#### 3.3 Mulai Sesi Absensi
```
POST /api/v1/attendance/sessions
{
  "schedule_id": 1,
  "session_type": "regular",
  "location": {
    "latitude": -6.2088,
    "longitude": 106.8456,
    "accuracy": 10
  }
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Sesi dibuat dengan QR code unik
- QR kedaluwarsa dalam 5 menit (dapat dikonfigurasi)
- Lokasi tercatat untuk validasi

#### 3.4 Generate QR Code
```
GET /api/v1/attendance/sessions/{session_id}/qr
```
**Hasil yang Diharapkan**:
- Status: 200
- Gambar QR terenkode Base64
- QR berisi data sesi terenkripsi
- Timestamp kedaluwarsa disertakan

#### 3.5 Pantau Absensi Live
```
WebSocket: /ws/attendance/{session_id}
```
**Hasil yang Diharapkan**:
- Update absensi real-time
- Nama siswa muncul saat mereka scan
- Jumlah absensi terupdate secara langsung

#### 3.6 Input Absensi Manual
```
POST /api/v1/attendance/manual
{
  "session_id": 1,
  "student_id": 123,
  "status": "present",
  "notes": "Terlambat datang - dimaafkan"
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Absensi manual tercatat
- Timestamp dan ID guru tercatat

#### 3.7 Tutup Sesi Absensi
```
PATCH /api/v1/attendance/sessions/{session_id}/close
{
  "absent_students": [124, 125],
  "notes": "Sesi selesai normal"
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Sesi ditandai sebagai ditutup
- Siswa tidak hadir otomatis ditandai
- Ringkasan absensi akhir dibuat

### Endpoint API yang Terlibat
- `POST /api/v1/auth/login`
- `GET /api/v1/teacher/schedule/today`
- `POST /api/v1/attendance/sessions`
- `GET /api/v1/attendance/sessions/{id}/qr`
- `WebSocket /ws/attendance/{session_id}`
- `POST /api/v1/attendance/manual`
- `PATCH /api/v1/attendance/sessions/{id}/close`

### Kasus Tepi

#### 3.E1 Tidak Ada Jadwal Aktif
**Pengujian**: Coba mulai sesi di luar waktu terjadwal
**Diharapkan**: Status 422, error "Tidak ada jadwal aktif ditemukan"

#### 3.E2 Lokasi Tidak Sesuai
**Pengujian**: Mulai sesi dari lokasi yang salah
**Diharapkan**: Status 422, error "Validasi lokasi gagal"

#### 3.E3 Sesi Duplikat
**Pengujian**: Mulai sesi ketika sudah ada yang aktif
**Diharapkan**: Status 409, error "Sesi aktif sudah ada"

---

## Alur 4: Siswa Scan QR dan Catat Absensi

### Skenario Pengujian: Alur Absensi Aplikasi Mobile Siswa
**Prioritas**: P0 (Kritis)  
**Durasi**: ~5 menit  
**Peran**: Siswa (Aplikasi Mobile)

### Langkah-langkah Pengujian

#### 4.1 Login Siswa (Mobile)
```
POST /api/v1/auth/login
{
  "email": "siswa001@sman1jkt.sch.id",
  "password": "password123",
  "device_info": {
    "device_id": "android_123456",
    "platform": "android",
    "app_version": "1.0.0"
  }
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Peran siswa terautentikasi
- Sidik jari perangkat tercatat
- Token khusus mobile diterbitkan

#### 4.2 Periksa Izin Lokasi
**Aksi Mobile**: Minta izin lokasi
**Hasil yang Diharapkan**:
- Izin GPS diberikan
- Akurasi lokasi dalam 10 meter
- Lokasi background dinonaktifkan

#### 4.3 Buka Scanner QR
**Aksi Mobile**: Navigasi ke layar scanner QR
**Hasil yang Diharapkan**:
- Izin kamera diberikan
- Interface scanner QR dimuat
- Preview kamera real-time aktif

#### 4.4 Scan QR Code
**Aksi Mobile**: Arahkan kamera ke QR code guru
```
POST /api/v1/attendance/scan
{
  "qr_data": "data_sesi_terenkripsi",
  "location": {
    "latitude": -6.2088,
    "longitude": 106.8456,
    "accuracy": 8
  },
  "device_info": {
    "device_id": "android_123456",
    "timestamp": "2024-02-02T08:15:30Z"
  }
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Absensi berhasil tercatat
- Pesan sukses ditampilkan
- Waktu absensi tercatat

#### 4.5 Verifikasi Absensi Tercatat
```
GET /api/v1/student/attendance/today
```
**Hasil yang Diharapkan**:
- Status: 200
- Status absensi hari ini ditampilkan
- Detail mata pelajaran dan waktu disertakan
- Absensi ditandai sebagai "hadir"

#### 4.6 Lihat Riwayat Absensi
```
GET /api/v1/student/attendance/history?limit=30
```
**Hasil yang Diharapkan**:
- Status: 200
- Catatan absensi 30 hari terakhir
- Status hadir/tidak hadir/terlambat per mata pelajaran
- Persentase absensi dihitung

### Endpoint API yang Terlibat
- `POST /api/v1/auth/login`
- `POST /api/v1/attendance/scan`
- `GET /api/v1/student/attendance/today`
- `GET /api/v1/student/attendance/history`

### Kasus Tepi

#### 4.E1 QR Code Kedaluwarsa
**Pengujian**: Scan QR code setelah kedaluwarsa 5 menit
**Diharapkan**: Status 410, error "QR code telah kedaluwarsa"

#### 4.E2 Scan Duplikat
**Pengujian**: Scan QR code yang sama dua kali
**Diharapkan**: Status 409, error "Absensi sudah tercatat"

#### 4.E3 Lokasi Salah
**Pengujian**: Scan QR dari lokasi berbeda (>50m)
**Diharapkan**: Status 422, error "Validasi lokasi gagal"

#### 4.E4 Tidak Ada Sesi Aktif
**Pengujian**: Scan QR ketika sesi sudah ditutup
**Diharapkan**: Status 410, error "Sesi absensi telah berakhir"

#### 4.E5 Siswa Salah
**Pengujian**: Siswa tidak terdaftar di kelas coba scan
**Diharapkan**: Status 403, error "Tidak diotorisasi untuk sesi ini"

---

## Alur 5: Siswa Melihat Data Dashboard

### Skenario Pengujian: Dashboard dan Analitik Siswa
**Prioritas**: P2 (Sedang)  
**Durasi**: ~8 menit  
**Peran**: Siswa (Mobile/Web)

### Langkah-langkah Pengujian

#### 5.1 Muat Overview Dashboard
```
GET /api/v1/student/dashboard
```
**Hasil yang Diharapkan**:
- Status: 200
- Jadwal hari ini ditampilkan
- Persentase absensi (bulan ini)
- Catatan absensi terbaru
- Kelas mendatang disorot

#### 5.2 Lihat Statistik Absensi
```
GET /api/v1/student/attendance/stats?period=monthly
```
**Hasil yang Diharapkan**:
- Status: 200
- Persentase absensi bulanan per mata pelajaran
- Total jumlah hadir/tidak hadir/terlambat
- Data chart tren absensi
- Perbandingan dengan rata-rata kelas

#### 5.3 Lihat Jadwal
```
GET /api/v1/student/schedule/weekly
```
**Hasil yang Diharapkan**:
- Status: 200
- Jadwal kelas mingguan
- Nama guru dan nomor ruangan
- Slot waktu ditampilkan jelas
- Hari ini disorot

#### 5.4 Lihat Nilai (jika tersedia)
```
GET /api/v1/student/grades/current-semester
```
**Hasil yang Diharapkan**:
- Status: 200
- Nilai semester saat ini per mata pelajaran
- Skor tugas dan ujian
- Perhitungan IPK
- Tren nilai

#### 5.5 Lihat Notifikasi
```
GET /api/v1/student/notifications?unread=true
```
**Hasil yang Diharapkan**:
- Status: 200
- Daftar notifikasi belum dibaca
- Alert absensi
- Perubahan jadwal
- Pengumuman umum

#### 5.6 Update Profil
```
PATCH /api/v1/student/profile
{
  "phone": "+628123456789",
  "emergency_contact": "+628987654321",
  "address": "Jakarta Selatan"
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Profil berhasil diperbarui
- Perubahan tercermin langsung
- Notifikasi orang tua dikirim (jika dikonfigurasi)

### Endpoint API yang Terlibat
- `GET /api/v1/student/dashboard`
- `GET /api/v1/student/attendance/stats`
- `GET /api/v1/student/schedule/weekly`
- `GET /api/v1/student/grades/current-semester`
- `GET /api/v1/student/notifications`
- `PATCH /api/v1/student/profile`

### Kasus Tepi

#### 5.E1 Tidak Ada Data Tersedia
**Pengujian**: Siswa baru tanpa riwayat absensi
**Diharapkan**: State kosong dengan pesan membantu, tanpa error

#### 5.E2 Jaringan Offline (Mobile)
**Pengujian**: Muat dashboard tanpa koneksi internet
**Diharapkan**: Data cache ditampilkan dengan indikator offline

#### 5.E3 Transisi Semester
**Pengujian**: Lihat data selama periode pergantian semester
**Diharapkan**: Indikasi jelas data semester saat ini vs sebelumnya

---

## Alur 6: Orang Tua Menerima Notifikasi

### Skenario Pengujian: Sistem Notifikasi Orang Tua
**Prioritas**: P1 (Tinggi)  
**Durasi**: ~12 menit  
**Peran**: Orang Tua

### Langkah-langkah Pengujian

#### 6.1 Konfigurasi Preferensi Notifikasi
```
POST /api/v1/parent/notification-settings
{
  "whatsapp_enabled": true,
  "email_enabled": true,
  "sms_enabled": false,
  "attendance_alerts": true,
  "grade_alerts": true,
  "schedule_changes": true
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Preferensi berhasil disimpan
- Pesan verifikasi dikirim ke channel yang dikonfigurasi

#### 6.2 Absensi Siswa Memicu Notifikasi
**Pemicu**: Siswa scan absensi (dari Alur 4)
**Proses Background**:
```
Event: AttendanceRecorded
Listener: SendParentNotification
Queue Job: ProcessWhatsAppNotification
```
**Hasil yang Diharapkan**:
- Pesan WhatsApp dikirim dalam 2 menit
- Pesan berisi: nama siswa, mata pelajaran, waktu, status
- Status pengiriman dilacak

#### 6.3 Orang Tua Menerima Notifikasi WhatsApp
**Format Pesan WhatsApp**:
```
🎓 AbsensiQR Pro - SMA Negeri 1 Jakarta

Siswa: Ahmad Rizki (12345)
Mata Pelajaran: Matematika
Waktu: 08:15 WIB
Status: ✅ HADIR

Kelas: X-IPA-1
Guru: Ibu Sari Matematika

Lihat detail: https://app.absensiQR.com/parent/attendance
```
**Hasil yang Diharapkan**:
- Pesan diterima di WhatsApp orang tua
- Semua informasi akurat dan terformat dengan baik
- Link berfungsi dan redirect ke portal orang tua

#### 6.4 Orang Tua Melihat Absensi via Link
```
GET /api/v1/parent/student/{student_id}/attendance/today
```
**Hasil yang Diharapkan**:
- Status: 200
- Catatan absensi lengkap hari ini
- Semua mata pelajaran dan statusnya
- Timestamp untuk setiap absensi

#### 6.5 Notifikasi Ketidakhadiran (Alert Terlambat)
**Pemicu**: Siswa tidak scan dalam 15 menit setelah kelas dimulai
**Proses Background**:
```
Scheduled Job: CheckMissingAttendance (berjalan setiap 15 menit)
Queue Job: SendAbsenceAlert
```
**Hasil yang Diharapkan**:
- Alert dikirim ke orang tua via WhatsApp
- Pesan menunjukkan potensi ketidakhadiran
- Instruksi untuk menghubungi sekolah jika diperlukan

#### 6.6 Login Portal Orang Tua
```
POST /api/v1/auth/login
{
  "email": "ortu001@gmail.com",
  "password": "password123"
}
```
**Hasil yang Diharapkan**:
- Status: 200
- Peran orang tua terautentikasi
- Akses hanya ke data anak

#### 6.7 Lihat Dashboard Anak
```
GET /api/v1/parent/children/{child_id}/dashboard
```
**Hasil yang Diharapkan**:
- Status: 200
- Ringkasan absensi anak
- Timeline aktivitas terbaru
- Overview performa akademik
- Jadwal mendatang

### Endpoint API yang Terlibat
- `POST /api/v1/parent/notification-settings`
- `GET /api/v1/parent/student/{id}/attendance/today`
- `POST /api/v1/auth/login`
- `GET /api/v1/parent/children/{id}/dashboard`
- Background: Integrasi WhatsApp API
- Background: Integrasi layanan email

### Kasus Tepi

#### 6.E1 Nomor WhatsApp Tidak Valid
**Pengujian**: Konfigurasi notifikasi dengan nomor telepon tidak valid
**Diharapkan**: Status 422, error validasi nomor telepon

#### 6.E2 Layanan WhatsApp Down
**Pengujian**: Kirim notifikasi ketika WhatsApp API tidak tersedia
**Diharapkan**: Fallback ke email, mekanisme retry diaktifkan

#### 6.E3 Beberapa Anak
**Pengujian**: Orang tua dengan beberapa anak menerima notifikasi
**Diharapkan**: Setiap notifikasi jelas mengidentifikasi anak mana

#### 6.E4 Pencegahan Spam Notifikasi
**Pengujian**: Beberapa perubahan absensi cepat berturut-turut
**Diharapkan**: Notifikasi dibatch, maksimal 1 per 5 menit per siswa

---

## Alur 7: Admin Sekolah Ekspor Laporan Bulanan

### Skenario Pengujian: Pelaporan Bulanan Komprehensif
**Prioritas**: P1 (Tinggi)  
**Durasi**: ~15 menit  
**Peran**: Admin Sekolah

### Langkah-langkah Pengujian

#### 7.1 Akses Dashboard Laporan
```
GET /api/v1/admin/reports/dashboard
```
**Hasil yang Diharapkan**:
- Status: 200
- Jenis laporan yang tersedia terdaftar
- Statistik cepat untuk bulan ini
- Riwayat pembuatan laporan terbaru

#### 7.2 Konfigurasi Parameter Laporan Bulanan
```
POST /api/v1/reports/attendance/monthly
{
  "month": "2024-02",
  "classes": [1, 2, 3],
  "subjects": ["all"],
  "format": "excel",
  "include_charts": true,
  "include_summary": true,
  "email_recipients": ["admin@sman1jkt.sch.id"]
}
```
**Hasil yang Diharapkan**:
- Status: 202 (Diterima)
- Job pembuatan laporan dimasukkan ke antrian
- Job ID dikembalikan untuk pelacakan
- Estimasi waktu penyelesaian diberikan

#### 7.3 Pantau Progress Pembuatan Laporan
```
GET /api/v1/reports/jobs/{job_id}/status
```
**Hasil yang Diharapkan**:
- Status: 200
- Persentase progress (0-100%)
- Tahap pemrosesan saat ini
- ETA untuk penyelesaian

#### 7.4 Download Laporan yang Dibuat
```
GET /api/v1/reports/download/{report_id}
```
**Hasil yang Diharapkan**:
- Status: 200
- Download file Excel dimulai
- File berisi beberapa sheet:
  - Dashboard Ringkasan
  - Absensi Harian
  - Statistik Siswa
  - Performa Guru
  - Perbandingan Kelas

#### 7.5 Verifikasi Konten Laporan
**Excel Sheet 1: Dashboard Ringkasan**
- Total siswa: 108
- Tingkat absensi keseluruhan: 87.5%
- Mata pelajaran paling dihadiri: Matematika (92%)
- Mata pelajaran paling sedikit dihadiri: Olahraga (78%)
- Waktu absensi puncak: 07:30-08:30

**Excel Sheet 2: Absensi Harian**
- Absensi per tanggal untuk setiap kelas
- Jumlah Hadir/Tidak Hadir/Terlambat
- Persentase absensi harian
- Pengecualian hari libur dan akhir pekan

**Excel Sheet 3: Statistik Siswa**
- Tingkat absensi siswa individual
- Siswa dengan absensi <80% disorot
- Siswa dengan absensi sempurna terdaftar
- Alert absensi kronis

#### 7.6 Jadwalkan Laporan Otomatis
```
POST /api/v1/reports/schedule
{
  "report_type": "monthly_attendance",
  "schedule": "0 0 1 * *",
  "recipients": ["admin@sman1jkt.sch.id", "principal@sman1jkt.sch.id"],
  "format": "pdf",
  "auto_email": true
}
```
**Hasil yang Diharapkan**:
- Status: 201
- Laporan otomatis dijadwalkan
- Cron job dibuat
- Email konfirmasi dikirim

#### 7.7 Ekspor Laporan Kartu Siswa
```
POST /api/v1/reports/student-cards
{
  "classes": [1, 2, 3],
  "include_photos": true,
  "include_qr": true,
  "format": "pdf"
}
```
**Hasil yang Diharapkan**:
- Status: 202
- Laporan kartu siswa dimasukkan ke antrian
- PDF dengan semua informasi siswa
- QR code untuk setiap siswa disertakan

### Endpoint API yang Terlibat
- `GET /api/v1/admin/reports/dashboard`
- `POST /api/v1/reports/attendance/monthly`
- `GET /api/v1/reports/jobs/{job_id}/status`
- `GET /api/v1/reports/download/{report_id}`
- `POST /api/v1/reports/schedule`
- `POST /api/v1/reports/student-cards`

### Kasus Tepi

#### 7.E1 Timeout Dataset Besar
**Pengujian**: Buat laporan untuk sekolah dengan 5000+ siswa
**Diharapkan**: Pemrosesan background, update progress, tanpa error timeout

#### 7.E2 Tidak Ada Data Tersedia
**Pengujian**: Buat laporan untuk bulan tanpa data absensi
**Diharapkan**: Laporan kosong dengan pesan jelas, tanpa error

#### 7.E3 Data Rusak
**Pengujian**: Buat laporan dengan beberapa catatan absensi rusak
**Diharapkan**: Laporan dibuat dengan peringatan kualitas data

#### 7.E4 Batas Penyimpanan Terlampaui
**Pengujian**: Buat beberapa laporan besar melebihi kuota penyimpanan
**Diharapkan**: Error yang elegan, pembersihan laporan lama, notifikasi dikirim

---

## Pengujian Integrasi Lintas-Alur

### Pengujian Integrasi 1: Perjalanan Lengkap Siswa
**Durasi**: ~45 menit
**Alur**: 1 → 2 → 3 → 4 → 5 → 6

1. Admin mengatur struktur akademik
2. Admin mengimpor siswa dan membuat kartu
3. Guru memulai sesi absensi
4. Siswa scan QR dan catat absensi
5. Siswa melihat dashboard yang diperbarui
6. Orang tua menerima notifikasi

**Kriteria Sukses**: Semua alur selesai tanpa error, konsistensi data terjaga

### Pengujian Integrasi 2: Sesi Absensi Multi-Kelas
**Durasi**: ~30 menit
**Skenario**: Guru menangani 3 kelas berbeda berturut-turut

1. Mulai sesi untuk Kelas A (07:30-08:30)
2. Siswa scan dan catat absensi
3. Tutup sesi dan mulai sesi baru untuk Kelas B (08:30-09:30)
4. Tangani keterlambatan dan input manual
5. Buat ringkasan absensi untuk semua kelas

**Kriteria Sukses**: Tidak ada konflik sesi, catatan absensi akurat, transisi sesi yang tepat

### Pengujian Integrasi 3: Uji Beban Sistem
**Durasi**: ~60 menit
**Skenario**: 500 siswa scan QR secara bersamaan

1. 10 guru memulai sesi secara bersamaan
2. 500 siswa scan QR code dalam jendela 5 menit
3. Pantau performa sistem dan waktu respons
4. Verifikasi semua catatan absensi akurat
5. Periksa tingkat keberhasilan pengiriman notifikasi

**Kriteria Sukses**: Waktu respons <2 detik, tingkat keberhasilan 99.9%, tidak ada kehilangan data

---

## Benchmark Performa

### Target Waktu Respons API
- Autentikasi: <500ms
- Pembuatan QR: <1000ms
- Scan Absensi: <2000ms
- Muat Dashboard: <1500ms
- Pembuatan Laporan: <30 detik (background)

### Batas Pengguna Bersamaan
- Scan QR simultan: 1000 pengguna
- Sesi absensi aktif: 100 sesi
- Pembuatan laporan: 10 laporan bersamaan
- Koneksi WebSocket: 500 koneksi

### Batas Volume Data
- Siswa per sekolah: 10,000
- Catatan absensi per hari: 50,000
- Ukuran laporan bulanan: <50MB
- Penyimpanan foto per siswa: <2MB

---

## Framework Otomasi Pengujian

### Tools yang Direkomendasikan
- **Pengujian API**: Postman/Newman, REST Assured
- **Pengujian Mobile**: Appium, Detox
- **Pengujian Web**: Cypress, Playwright
- **Pengujian Beban**: JMeter, Artillery
- **Pengujian Database**: DBUnit, Testcontainers

### Integrasi CI/CD
```yaml
# .github/workflows/e2e-tests.yml
name: Pengujian E2E
on: [push, pull_request]
jobs:
  e2e-tests:
    runs-on: ubuntu-latest
    steps:
      - name: Setup Lingkungan Uji
        run: |
          docker-compose up -d
          npm run test:e2e:setup
      - name: Jalankan Pengujian API
        run: newman run e2e-tests.postman_collection.json
      - name: Jalankan Pengujian Mobile
        run: detox test --configuration ios.sim.release
      - name: Buat Laporan Pengujian
        run: allure generate --clean
```

### Manajemen Data Uji
- Gunakan database seeder untuk data uji yang konsisten
- Implementasi pembersihan data uji setelah setiap test suite
- Gunakan factory untuk menghasilkan data uji yang realistis
- Pertahankan database uji terpisah untuk isolasi

Dokumen pengujian komprehensif ini mencakup semua perjalanan pengguna kritis dan kasus tepi untuk sistem absensi sekolah Anda. Setiap skenario pengujian mencakup langkah-langkah detail, hasil yang diharapkan, dan endpoint API untuk memastikan validasi menyeluruh terhadap fungsi sistem.