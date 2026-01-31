# AbsensiQRPro - Sistem Absensi QR Code

> Sistem absensi berbasis QR Code untuk SD/SMP/SMA/SMK dengan multi-tenant support

[![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## 🎯 Overview

**AbsensiQRPro** adalah sistem manajemen absensi sekolah yang modern, aman, dan scalable menggunakan teknologi QR Code. Sistem ini mendukung multi-tenant (banyak sekolah) dengan role-based access control yang komprehensif.

### ✨ Fitur Utama

- ✅ **QR Code Attendance** - Scan QR untuk absensi otomatis
- ✅ **GPS Verification** - Validasi lokasi siswa saat absensi
- ✅ **Multi-Tenant** - Support multiple schools
- ✅ **9 Role System** - Dari Super Admin hingga Student
- ✅ **Real-time Dashboard** - Update attendance live
- ✅ **Manual Input** - Guru bisa input manual untuk siswa yang tidak scan
- ✅ **Comprehensive Reports** - Daily, weekly, monthly reports dengan export PDF/Excel
- ✅ **Audit Trail** - Semua aktivitas tercatat dengan GPS & device info
- ✅ **RESTful API** - Backend API menggunakan Laravel 12
- ✅ **Mobile Ready** - Support untuk Flutter/React Native app

---

## 🏗️ Architecture

```
┌─────────────────┐
│  Mobile App     │  (Flutter/React Native)
│  (Student)      │
└────────┬────────┘
         │
         │ HTTPS/JSON
         ▼
┌─────────────────┐
│  API Gateway    │
│  (Load Balancer)│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Laravel 12 API │  ← YOU ARE HERE
│  (REST API)     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  PostgreSQL DB  │
│  + Redis Cache  │
└─────────────────┘
```

---

## 📚 Documentation

Dokumentasi lengkap tersedia di folder **[`docs/`](./docs/)**:

| Document | Description |
|----------|-------------|
| [**README**](./docs/README.md) | 📖 Master index semua dokumentasi |
| [01. System Architecture](./docs/01_system_architecture.md) | 🏛️ Arsitektur sistem & role structure |
| [02. Database Schema](./docs/02_database_schema.md) | 🗄️ ERD & 12 tabel dengan spesifikasi |
| [03. API Specification](./docs/03_api_specification.md) | 🔌 REST API endpoints & examples |
| [04. Flow Charts](./docs/04_flow_charts.md) | 📊 7 flowchart diagram (Mermaid) |
| [05. Implementation Plan](./docs/05_implementation_plan.md) | 🛠️ Roadmap development 8 phases |
| [06. Database Implementation](./docs/06_database_implementation.md) | ✅ Migration & seeder status |
| [07. Setup Notes](./docs/07_setup_notes.md) | ⚙️ Environment & configuration |
| [08. Walkthrough](./docs/08_walkthrough.md) | 🚶 Progress tracking |

---

## 🚀 Quick Start

### Prerequisites

- **PHP** 8.2 or higher
- **Composer** 2.x
- **PostgreSQL** 15+ (or SQLite for dev)
- **Redis** (optional for dev)

### Installation

```bash
# 1. Clone repository
git clone <repository-url>
cd backend

# 2. Install dependencies
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Configure database (.env)
# Option A: PostgreSQL (production)
DB_CONNECTION=pgsql
DB_DATABASE=absensi_qr
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password

# Option B: SQLite (quick dev)
DB_CONNECTION=sqlite
# Then: touch database/database.sqlite

# 6. Run migrations
php artisan migrate

# 7. Seed database (roles, permissions, schools)
php artisan db:seed --class=DevelopmentSeeder

# 8. Start server
php artisan serve
```

### Verify Installation

```bash
# Check migration status
php artisan migrate:status

# Test database connection
php artisan tinker
>>> \App\Models\School::all();

# Access API
curl http://localhost:8000/api/v1
```

---

## 🗄️ Database

### Schema Overview

12 tables inti:

1. **schools** - Multi-tenant root
2. **users** - Authentication
3. **user_profiles** - Extended profiles
4. **academic_years** - Tahun ajaran
5. **subjects** - Mata pelajaran
6. **classes** - Kelas
7. **class_students** - Pivot siswa-kelas
8. **schedules** - Jadwal
9. **qr_codes** - QR management
10. **attendances** - Main records
11. **attendance_logs** - Audit trail
12. **attendance_reports** - Pre-generated reports

**Total:** ~150 columns, 38 indexes, 26 foreign keys

Lihat [Database Schema](./docs/02_database_schema.md) untuk detail.

---

## 👥 Roles & Permissions

9 roles dengan granular permissions:

| Role | Description | Permissions |
|------|-------------|-------------|
| 🔴 **Super Admin** | System owner | All (*.*) |
| 🟠 **School Admin** | Manage school | School management |
| 🟡 **Principal** | Kepala sekolah | View & approve reports |
| 🟢 **Vice Principal** | Wakil kepala | View reports |
| 🔵 **Teacher** | Guru | Scan QR, manual input |
| 🟣 **Homeroom Teacher** | Wali kelas | Teacher + manage class |
| 🟤 **Staff** | Staff TU | Student CRUD, exports |
| ⚪ **Student** | Siswa | View own attendance |
| ⚫ **Parent** | Orang tua | View child attendance |

50+ permissions total. Lihat [RolePermissionSeeder](./database/seeders/RolePermissionSeeder.php).

---

## 🔌 API Endpoints

Base URL: `http://localhost:8000/api/v1`

### Authentication

```bash
POST /auth/login
POST /auth/logout
GET  /auth/me
```

### Attendance

```bash
POST /attendance/scan          # Scan QR code
POST /attendance/manual        # Manual input (teacher)
GET  /attendance/schedule/{id} # Class attendance list
GET  /attendance/student/{id}  # Student history
```

### QR Management

```bash
POST   /qr/generate    # Generate QR for schedule
GET    /qr/{id}        # View QR details
DELETE /qr/{id}        # Deactivate QR
```

### Reports

```bash
GET  /reports/daily              # Daily report
GET  /reports/student/{id}/summary  # Student summary
POST /reports/export             # Export to PDF/Excel
```

Lihat [API Specification](./docs/03_api_specification.md) untuk detail request/response.

---

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter=AttendanceTest

# Run with coverage
php artisan test --coverage
```

---

## 🛠️ Development

### Project Structure

```
backend/
├── app/
│   ├── Http/Controllers/Api/  # API Controllers
│   ├── Models/                # Eloquent Models
│   ├── Services/              # Business Logic
│   └── Repositories/          # Data Access
├── config/
│   ├── sanctum.php           # API Authentication
│   └── permission.php        # RBAC
├── database/
│   ├── migrations/           # ✅ 12 files
│   └── seeders/              # ✅ Roles, Schools
├── docs/                     # 📚 Full Documentation
└── routes/
    └── api.php               # API Routes
```

### Coding Standards

- **PSR-12** code style
- **Repository pattern** for data access
- **Service layer** for business logic
- **Request validation** using Form Requests
- **API Resources** for JSON responses

---

## 🔐 Security

- ✅ **Encrypted QR tokens** (AES-256-CBC)
- ✅ **GPS location verification**
- ✅ **Role-based permissions** (RBAC)
- ✅ **Multi-tenant isolation**
- ✅ **Audit logging** with GPS & device info
- ✅ **Rate limiting** (60 req/min per user)
- ✅ **Input validation**
- ✅ **SQL injection prevention**

---

## 📈 Roadmap

- [x] **Phase 1:** Backend setup
- [x] **Phase 2:** Database migrations & seeders
- [ ] **Phase 3:** Models & relationships
- [ ] **Phase 4:** Core services (QR, Attendance, Location)
- [ ] **Phase 5:** API implementation
- [ ] **Phase 6:** Mobile app (Flutter)
- [ ] **Phase 7:** Testing & QA
- [ ] **Phase 8:** Production deployment

---

## 📞 Support

- 📖 [Full Documentation](./docs/README.md)
- 🐛 [Issue Tracker](https://github.com/yourrepo/issues)
- 💬 [Discussions](https://github.com/yourrepo/discussions)

---

## 📄 License

MIT License - see [LICENSE](LICENSE) file for details.

---

## 👨‍💻 Contributors

Developed with ❤️ for Indonesian schools.

---

**Version:** 1.0.0-alpha  
**Last Updated:** 2026-01-19
