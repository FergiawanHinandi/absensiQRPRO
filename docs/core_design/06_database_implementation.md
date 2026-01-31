# Database Implementation Summary

## ✅ Completed: Phase 2 - Database Schema

### Migration Files Created (12 Tables)

Semua migration files berhasil dibuat dengan schema lengkap sesuai `database_schema.md`:

1. **[2026_01_19_045132_create_schools_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045132_create_schools_table.php)**
   - Multi-tenant root table
   - Fields: name, npsn (unique), school_level, GPS coordinates, radius, settings JSON
   - Indexes: npsn, school_level, is_active

2. **[2026_01_19_045248_modify_users_table_for_multi_tenant.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045248_modify_users_table_for_multi_tenant.php)**
   - Added school_id FK, username, role_type enum
   - Fields: device_token (FCM), last_login_at
   - Indexes: school_id, username, role_type, is_active

3. **[2026_01_19_045133_create_user_profiles_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045133_create_user_profiles_table.php)**
   - Extended profile data
   - Fields: full_name, NIK, NISN, NIP, gender, birth_date, phone, photo_url, emergency_contact
   - Indexes: user_id (unique), nisn, nik, nip

4. **[2026_01_19_045135_create_academic_years_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045135_create_academic_years_table.php)**
   - Tahun ajaran management
   - Fields: name (e.g. "2024/2025"), start_date, end_date, semester
   - Unique constraint: (school_id, name)

5. **[2026_01_19_045137_create_subjects_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045137_create_subjects_table.php)**
   - Mata pelajaran
   - Fields: code, name, school_level, grade_level
   - Unique constraint: (school_id, code, grade_level)

6. **[2026_01_19_045139_create_classes_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045139_create_classes_table.php)**
   - Kelas/Rombongan belajar
   - Fields: name, grade_level, homeroom_teacher_id FK, max_students, classroom
   - Unique constraint: (school_id, academic_year_id, name)

7. **[2026_01_19_045140_create_class_students_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045140_create_class_students_table.php)**
   - Pivot table siswa-kelas
   - Fields: enrollment_date, status (active, moved, graduated, dropped)
   - Unique constraint: (class_id, student_id)

8. **[2026_01_19_045141_create_schedules_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045141_create_schedules_table.php)**
   - Jadwal pelajaran
   - Fields: day_of_week, start_time, end_time, room, schedule_type
   - Indexes: (class_id, day_of_week), (teacher_id, day_of_week)

9. **[2026_01_19_045143_create_qr_codes_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045143_create_qr_codes_table.php)**
   - QR code generation & tracking
   - Fields: token (unique, encrypted), qr_type, valid_from/until, max_scans, scan_count
   - Indexes: token, (schedule_id, valid_until), (is_active, valid_until)

10. **[2026_01_19_045145_create_attendances_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045145_create_attendances_table.php)**
    - Main attendance records
    - Fields: attendance_date, status, check_in/out_time, is_manual, notes, attachment_url
    - Unique constraint: (schedule_id, student_id, attendance_date)
    - Indexes: student_id, schedule_id, school_id, status (all dengan attendance_date)

11. **[2026_01_19_045146_create_attendance_logs_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045146_create_attendance_logs_table.php)**
    - Audit trail untuk semua aktivitas
    - Fields: action, GPS lat/lon, location_accuracy, device_info JSON, IP, user_agent
    - Indexes: attendance_id, (user_id, created_at), (action, created_at)

12. **[2026_01_19_045148_create_attendance_reports_table.php](file:///d:/Project/absensiQRPro/backend/database/migrations/2026_01_19_045148_create_attendance_reports_table.php)**
    - Pre-generated reports
    - Fields: report_type, period_start/end, present/late/absent counts, attendance_rate, report_data JSON
    - Indexes: (school_id, report_type, report_date), class_id, student_id

---

### Seeder Files Created

#### 1. [RolePermissionSeeder.php](file:///d:/Project/absensiQRPro/backend/database/seeders/RolePermissionSeeder.php)

**50+ Permissions Created:**
```php
// Attendance: scan, manual_input, approve, export, view_all, view_own, modify
// Students: create, update, delete, view, import, manage_class
// Classes: create, update, delete, view, assign_students, assign_teachers
// Reports: generate, export, view_all, approve
// School: settings, qr_generate, academic_year
// Users: create, update, delete, view
```

**9 Roles with Assigned Permissions:**

| Role | Permissions Summary |
|------|---------------------|
| **super_admin** | All permissions |
| **school_admin** | Full school management except super admin tasks |
| **principal** | View & approve reports, attendance overview |
| **vice_principal** | View-only access to reports & attendance |
| **teacher** | Scan QR, manual input, view own classes |
| **homeroom_teacher** | Teacher + manage class students & class reports |
| **staff** | Admin tasks: student CRUD, export, reports |
| **student** | View own attendance only |
| **parent** | View child attendance (future feature) |

#### 2. [SchoolSeeder.php](file:///d:/Project/absensiQRPro/backend/database/seeders/SchoolSeeder.php)

2 sample schools:
- SMP Negeri 1 Jakarta (NPSN: 20104001) - with GPS coordinates & settings JSON
- SMA Negeri 1 Bandung (NPSN: 20105001) - with GPS coordinates & settings JSON

#### 3. [DevelopmentSeeder.php](file:///d:/Project/absensiQRPro/backend/database/seeders/DevelopmentSeeder.php)

Main seeder yang memanggil:
- RolePermissionSeeder
- SchoolSeeder
- (TODO: AcademicYearSeeder, UserSeeder, dll)

---

## 🔧 Next Steps: Run Migrations

### Option 1: PostgreSQL (Production-Ready)

**Prerequisites:**
1. Install PostgreSQL 15+
2. Create database:
   ```sql
   CREATE DATABASE absensi_qr;
   ```
3. Update `.env`:
   ```env
   DB_CONNECTION=pgsql
   DB_DATABASE=absensi_qr
   DB_PASSWORD=your-secure-password
   ```

**Run migrations:**
```bash
cd d:\Project\absensiQRPro\backend
php artisan migrate
php artisan db:seed --class=DevelopmentSeeder
```

### Option 2: SQLite (Quick Development)

**Setup:**
```bash
cd d:\Project\absensiQRPro\backend

# Update .env
# DB_CONNECTION=sqlite

# Create database file
New-Item -Path "database\database.sqlite" -ItemType File

# Run migrations
php artisan migrate
php artisan db:seed --class=DevelopmentSeeder
```

---

## 📋 Verification Checklist

After running migrations, verify:

```bash
# Check migration status
php artisan migrate:status

# View tables (PostgreSQL)
php artisan tinker
>>> DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

# Or for SQLite
>>> DB::select("SELECT name FROM sqlite_master WHERE type='table'");

# Verify roles
>>> \Spatie\Permission\Models\Role::with('permissions')->get();

# Verify schools
>>> \App\Models\School::all();
```

---

## 🚀 Phase 3: Model Implementation (Next)

Perlu dibuat:
- [ ] Create Eloquent Models (School, User, UserProfile, dll)
- [ ] Add relationships (hasMany, belongsTo, belongsToMany)
- [ ] Add global scopes (tenant filtering)
- [ ] Add mutators & accessors
- [ ] Create Model factories untuk testing

---

## 📊 Database Statistics

| Table | Columns | Indexes | Foreign Keys | Purpose |
|-------|---------|---------|--------------|---------|
| schools | 15 | 3 | 0 | Multi-tenant root |
| users | 11 (+6 modified) | 4 | 1 | Authentication |
| user_profiles | 20 | 4 | 1 | Extended profiles |
| academic_years | 7 | 2 | 1 | Tahun ajaran |
| subjects | 8 | 2 | 1 | Mata pelajaran |
| classes | 10 | 3 | 3 | Kelas |
| class_students | 5 | 3 | 2 | Siswa-kelas pivot |
| schedules | 13 | 3 | 5 | Jadwal |
| qr_codes | 14 | 3 | 3 | QR management |
| attendances | 13 | 5 | 3 | Main attendance |
| attendance_logs | 15 | 3 | 3 | Audit trail |
| attendance_reports | 19 | 3 | 3 | Reports |
| **TOTAL** | **~150** | **38** | **26** | Full schema |

---

**Status:** Database schema implementation complete ✅  
**Last updated:** 2026-01-19 13:00 WIB
