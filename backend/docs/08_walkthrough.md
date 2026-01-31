# Walkthrough: Setup Backend Laravel 12 - AbsensiQRPro

## ✅ Yang Sudah Diselesaikan

### 1. Dokumentasi Sistem (Phase 0 - Planning)

Berhasil membuat dokumentasi lengkap:

- **[system_architecture.md](file:///C:/Users/USER/.gemini/antigravity/brain/27421423-283b-4a21-9806-82300ba413d8/system_architecture.md)** - Arsitektur multi-layer dengan 9 role system, flow QR attendance
- **[database_schema.md](file:///C:/Users/USER/.gemini/antigravity/brain/27421423-283b-4a21-9806-82300ba413d8/database_schema.md)** - 12 tabel inti dengan ERD dan indexes
- **[api_specification.md](file:///C:/Users/USER/.gemini/antigravity/brain/27421423-283b-4a21-9806-82300ba413d8/api_specification.md)** - REST API endpoints lengkap
- **[implementation_plan.md](file:///C:/Users/USER/.gemini/antigravity/brain/27421423-283b-4a21-9806-82300ba413d8/implementation_plan.md)** - Roadmap development & testing

Semua dokumentasi sudah **APPROVED** oleh user.

---

### 2. Laravel 12 Project Setup (Phase 1)

#### Instalasi Project

```bash
✅ composer create-project laravel/laravel backend
✅ Laravel version: 12.47.0
✅ PHP version: 8.2.12
```

**Location:** `d:\Project\absensiQRPro\backend`

#### Dependencies Installed

| Package | Version | Purpose |
|---------|---------|---------|
| **laravel/sanctum** | ^4.2 | API authentication dengan token |
| **spatie/laravel-permission** | ^6.24 | Role-Based Access Control (RBAC) |
| **bacon/bacon-qr-code** | ^3.0 | QR Code generation (tanpa GD extension) |
| **predis/predis** | ^3.3 | Redis client untuk cache & queue |

**Published configs:**
```bash
✅ php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
✅ php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

#### Environment Configuration

**PostgreSQL** setup di [.env](file:///d:/Project/absensiQRPro/backend/.env):

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=absensi_qr
DB_USERNAME=postgres
DB_PASSWORD=
```

**Redis** configuration:

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
```

#### Project Structure (Current)

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   └── Middleware/
│   ├── Models/
│   └── Providers/
├── config/
│   ├── sanctum.php ✅
│   └── permission.php ✅
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 2019_12_14_000001_create_personal_access_tokens_table.php (Sanctum)
│   │   └── 2026_01_19_040039_create_permission_tables.php (Spatie)
│   └── seeders/
└── .env ✅ configured
```

---

## 🚧 Pending Setup Tasks

### Phase 1 Remaining:
- ⏳ Create directory structure (Services/, Repositories/, etc.)
- ⏳ Setup base Model traits

### Phase 2: Database Migrations (Next)
- ⏳ Create 12 custom migrations (schools, academic_years, classes, schedules, qr_codes, attendances, dll)
- ⏳ Create seeders (roles, permissions, test data)
- ⏳ Run migrations
- ⏳ Verify database schema

---

## ⚠️ Important Notes

> [!WARNING]
> **PostgreSQL Required**: Database dikonfigurasi untuk PostgreSQL. Opsi:
> 1. Install PostgreSQL 15+ dan buat database `absensi_qr`
> 2. Atau gunakan SQLite untuk development: uncomment `DB_CONNECTION=sqlite` di `.env`

> [!NOTE]
> **GD Extension**: Extension `ext-gd` tidak tersedia. Ini tidak masalah karena:
> - Bacon QR Code bisa generate QR sebagai SVG (tidak perlu GD)
> - Excel export (Maatwebsite) bisa di-skip untuk sementara
> 
> Untuk enable GD: edit `C:\xampp\php\php.ini`, uncomment `extension=gd`, restart Apache

> [!TIP]
> **Redis Optional**: Untuk development tanpa Redis, ubah di `.env`:
> ```env
> CACHE_STORE=database
> QUEUE_CONNECTION=database
> ```

---

## 📋 Next Steps

1. **Database Setup** (pilih salah satu):
   - Install PostgreSQL → Create database `absensi_qr`
   - Atau gunakan SQLite: `touch database/database.sqlite`

2. **Create Migrations** (Phase 2):
   - `schools` table
   - `users` & `user_profiles` (modify existing)
   - `academic_years`, `subjects`, `classes`
   - `schedules`, `qr_codes`
   - `attendances`, `attendance_logs`, `attendance_reports`

3. **Run Migrations**:
   ```bash
   php artisan migrate
   ```

4. **Create Seeders**:
   - RolePermissionSeeder (9 roles dengan permissions)
   - SchoolSeeder (sample schools)
   - UserSeeder (admin, guru, siswa test accounts)

5. **Backend Development**:
   - Implement Services (QR, Attendance, Location)
   - Create API Controllers
   - Setup routes `/api/v1/*`

---

## ✅ Validation Checklist

- [x] Laravel 12 installed successfully
- [x] Dependencies installed without errors
- [x] Config files published
- [x] `.env` configured for PostgreSQL & Redis
- [x] Project structure ready
- [ ] Database created
- [ ] Migrations run successfully
- [ ] Seeders completed
- [ ] API endpoints functional

---

**Status:** Backend setup selesai, siap untuk database implementation  
**Last updated:** 2026-01-19 11:50 WIB
