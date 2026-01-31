# Database Schema Architecture - AbsensiQRPro

Dokumen ini menjelaskan struktur database relasional yang dirancang untuk mendukung sistem absensi sekolah multi-tenant dengan keamanan tinggi.

**Architectural Pattern**: Shared Database, Column-Based Multi-tenancy (`school_id`).

## 1. Entity Relationship Diagram (Conceptual)

```mermaid
erDiagram
    SCHOOL ||--|{ USER : "has members"
    SCHOOL ||--|{ CLASS : "has classes"
    SCHOOL ||--|{ ACADEMIC_YEAR : "defines years"
    
    USER ||--o{ USER_PROFILE : "has details"
    USER ||--o{ CLASS_STUDENT : "enrolls in"
    USER ||--o{ ATTENDANCE : "scans"
    USER ||--o{ PERMISSION : "requests"
    
    CLASS ||--|{ SCHEDULE : "has schedules"
    CLASS ||--|{ CLASS_STUDENT : "contains students"
    
    SUBJECT ||--|{ SCHEDULE : "is taught in"
    USER ||--|{ SCHEDULE : "teaches (as teacher)"
    
    SCHEDULE ||--o{ QR_CODE : "generates"
    SCHEDULE ||--o{ ATTENDANCE : "records"
```

---

## 2. Core Tables (Multi-Tenancy Foundation)

### `schools`
Tabel root untuk multi-tenancy. Semua data lain mengacu ke sini.
- **PK**: `id`
- **Columns**: `name`, `npsn`, `school_level` (SD/SMP/SMA/SMK), `address`, `radius_meters` (Geofence settings).
- **Critical Design**: `radius_meters` menentukan seberapa ketat validasi lokasi GPS.

### `users`
Tabel pengguna global. Login menggunakan `email` atau `username` (NIS/NIP).
- **PK**: `id`
- **FK**: `school_id`
- **Columns**: `role_type` (Enum: super_admin, school_admin, teacher, student, parent), `device_id` (Hardware lock), `is_active`.
- **Security**: `device_id` digunakan untuk mencegah "joki" absen (satu akun hanya bisa login di satu HP).

---

## 3. Academic Structure

### `academic_years`
Memungkinkan histori data. Siswa naik kelas, data lama tetap tersimpan.
- **PK**: `id`
- **Columns**: `name` (2025/2026), `semester` (ganjil/genap), `is_active`.

### `classes` (Rombel)
- **PK**: `id`
- **Columns**: `name` (X-A, XII-IPA-1), `level` (10, 11, 12), `homeroom_teacher_id` (FK User).

### `schedules` (Jadwal Pelajaran)
Jantung dari validasi absensi. QR Code tidak bisa digenerate tanpa jadwal.
- **PK**: `id`
- **Columns**: `day_of_week`, `start_time`, `end_time`, `teacher_id` (Guru mapel bisa beda dengan wali kelas).

---

## 4. Attendance Engine (High Transaction)

### `qr_codes`
Tabel ephemeral (sementara) untuk menyimpan token QR yang valid.
- **PK**: `id`
- **Columns**: `token` (Encrypted), `valid_until` (Timestamp), `usage_limit` (Max scan per QR).
- **Design Note**: Token dirotasi setiap 15-60 detik di aplikasi guru.

### `attendances` (Fact Table)
Menyimpan **Single Source of Truth** kehadiran siswa.
- **PK**: `id`
- **Composite Unique Key**: `[student_id, schedule_id, date]` (Mencegah double entri).
- **Columns**:
  - `status`: `present`, `late`, `sick`, `permit`, `alpha`.
  - `lat_in`, `lng_in`: Koordinat saat scan.
  - `distance_meters`: Jarak dari titik pusat sekolah (Audit compliance).
  - `is_manual`: Flag jika data diinput manual oleh guru (bukan scan).

### `permissions`
Menangani birokrasi izin/sakit digital.
- **STATUS**: `pending` -> `approved` -> (Trigger insert ke tabel `attendances`).
- **Columns**: `attachment_path` (Bukti foto surat dokter).

---

## 5. Design Decisions & Trade-offs

1.  **Column-Based Tenancy vs Database-per-Tenant**
    *   *Decision*: Menggunakan `school_id` di setiap tabel (Shared DB).
    *   *Reason*: Lebih hemat resource infrastruktur, migrasi schema lebih mudah, memudahkan Super Admin melakukan agregasi data global (Laporan Nasional).

2.  **Audit Trail (`attendance_logs`)**
    *   *Decision*: Memisahkan tabel log mentah dari tabel utama `attendances`.
    *   *Reason*: Performa. Tabel log akan sangat besar tapi jarang dibaca. Tabel `attendances` harus cepat untuk query dashboard.

3.  **Soft Deletes**
    *   Menggunakan `SoftDeletes` di tabel master (`users`, `classes`) untuk mencegah kehilangan data referensi tidak sengaja.

4.  **Security Measures**
    *   `device_id` di tabel `users` bersifat fixed. Reset device harus melalui admin.
    *   `qr_codes` memiliki TTL (Time To Live) pendek.
