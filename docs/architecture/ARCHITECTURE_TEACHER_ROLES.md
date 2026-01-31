# Arsitektur Role Guru - AbsensiQR Pro

## ⚠️ PRINSIP KUNCI: Satu Guru = Satu Akun

### ❌ KESALAHAN DESAIN (Jangan Lakukan Ini!)
```
❌ Buat akun terpisah untuk:
   - akun_guru_kelas
   - akun_guru_mapel
   
❌ Buat role_type berbeda:
   - role_type: 'homeroom_teacher'
   - role_type: 'subject_teacher'
```

### ✅ DESAIN YANG BENAR

#### 1. Database Schema
```sql
-- Tabel Users (Satu akun per guru)
users
├── id
├── school_id
├── name
├── email
├── role_type: 'teacher' (HANYA INI, tidak ada homeroom_teacher)
└── is_active

-- Tabel Guru Roles (Konfigurasi oleh Admin Sekolah)
teacher_roles
├── id
├── teacher_id (FK to users)
├── is_homeroom_teacher (boolean)
├── class_id (FK, nullable - untuk wali kelas)
└── assigned_at

-- Tabel Guru Mapel (Many-to-Many)
teacher_subjects
├── id
├── teacher_id (FK to users)
├── subject_id (FK to subjects)
├── class_id (FK to classes)
└── academic_year_id
```

#### 2. Frontend Logic (Conditional UI)

```typescript
// ❌ SALAH: Cek role_type
if (user.role_type === 'homeroom_teacher') {
    showWaliKelasMenu();
}

// ✅ BENAR: Cek konfigurasi guru
const teacherProfile = await api.get('/teacher/profile');

if (teacherProfile.is_homeroom_teacher) {
    showWaliKelasMenu(); // Tampilkan menu tambahan
}

// Menu SELALU menampilkan fitur guru mapel
// Menu CONDITIONAL menampilkan fitur wali kelas
```

#### 3. Sidebar Menu Strategy

```typescript
// Semua guru mendapat menu dasar
const baseTeacherMenu = [
    'Dashboard',
    'Jadwal Mengajar',
    'Absensi Mapel',
    'Laporan Pribadi',
    'Profil'
];

// Jika guru adalah wali kelas, tambahkan menu
if (teacherProfile.is_homeroom_teacher) {
    menu.splice(2, 0, {
        label: 'Absensi Kelas',
        badge: 'Wali Kelas',
        children: [...]
    });
}
```

---

## 🔄 Alur Konfigurasi oleh Admin Sekolah

### Step 1: Admin Tambah Guru
```
Admin Sekolah → Manajemen Guru → Tambah Guru
├── Nama: Rina Wati
├── Email: rina@smp1.com
├── Role: teacher (otomatis)
└── Status: Aktif
```

### Step 2: Admin Tetapkan Peran
```
Admin Sekolah → Tetapkan Peran → Pilih Guru: Rina Wati
├── ☑ Jadikan Wali Kelas
│   └── Kelas: 7A
├── ☑ Ajarkan Mapel
│   ├── Matematika → Kelas 7A, 7B
│   └── IPA → Kelas 7A
└── Simpan
```

### Step 3: Guru Login (UI Dinamis)
```
Login sebagai: rina@smp1.com
└── Sidebar Menu:
    ├── Dashboard
    ├── Jadwal Mengajar
    ├── Absensi Kelas (🏷️ Wali Kelas) ← Muncul karena is_homeroom_teacher = true
    │   ├── Absensi Harian
    │   ├── Input Izin/Sakit
    │   └── Rekap Kelas
    ├── Absensi Mapel ← Selalu ada
    │   ├── Generate QR
    │   └── Validasi Manual
    └── Laporan Pribadi
        ├── Rekap Kelas Wali ← Conditional
        └── Rekap Mapel ← Selalu ada
```

---

## 🛠️ Backend API Endpoints

### Get Teacher Profile (dengan role info)
```
GET /api/v1/teacher/profile

Response:
{
  "user": {
    "id": 5,
    "name": "Rina Wati",
    "role_type": "teacher"
  },
  "is_homeroom_teacher": true,
  "homeroom_class": {
    "id": 10,
    "name": "7A"
  },
  "subjects": [
    { "id": 1, "name": "Matematika", "classes": ["7A", "7B"] },
    { "id": 2, "name": "IPA", "classes": ["7A"] }
  ]
}
```

---

## 📝 Migration yang Diperlukan

```php
// Tambah kolom di user_profiles atau buat tabel teacher_roles
Schema::create('teacher_roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
    $table->boolean('is_homeroom_teacher')->default(false);
    $table->foreignId('homeroom_class_id')->nullable()->constrained('classes');
    $table->timestamps();
});

Schema::create('teacher_subjects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
    $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
    $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
    $table->foreignId('academic_year_id')->constrained('academic_years');
    $table->timestamps();
    
    $table->unique(['teacher_id', 'subject_id', 'class_id', 'academic_year_id'], 'unique_teacher_subject_class');
});
```

---

## ✅ Checklist Implementasi

- [x] Database: Satu tabel `users` dengan `role_type: 'teacher'`
- [ ] Database: Tabel `teacher_roles` untuk konfigurasi wali kelas
- [ ] Database: Tabel `teacher_subjects` untuk mapping guru-mapel-kelas
- [ ] Backend: API `/teacher/profile` yang return role info
- [ ] Frontend: Conditional menu berdasarkan `is_homeroom_teacher`
- [ ] Frontend: Hapus `role_type: 'homeroom_teacher'` dari enum
- [ ] Admin UI: Fitur "Tetapkan Peran" untuk assign wali kelas
- [ ] Admin UI: Fitur "Assign Mapel" untuk mapping guru-mapel-kelas

---

## 🎯 Kesimpulan

**Satu Guru = Satu Akun**  
**Peran = Konfigurasi Admin**  
**UI = Conditional Rendering**

Ini memastikan:
- ✅ Fleksibilitas: Guru bisa jadi wali kelas DAN guru mapel
- ✅ Skalabilitas: Mudah ubah peran tanpa buat akun baru
- ✅ Data Integrity: Tidak ada duplikasi data guru
- ✅ User Experience: Guru hanya login sekali, lihat semua fitur mereka

---

**Dokumentasi ini adalah SUMBER KEBENARAN untuk implementasi role guru.**
