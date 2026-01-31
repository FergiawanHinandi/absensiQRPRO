# 📝 Quick Reference Guide - AbsensiQRPro

> Panduan cepat untuk developer

---

## 🚀 Common Commands

### Development Server
```bash
# Start Laravel server
php artisan serve
# Access: http://localhost:8000

# Start with custom port
php artisan serve --port=8080
```

### Database

```bash
# Run migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Fresh migrate (drop all & migrate)
php artisan migrate:fresh

# Fresh + seed
php artisan migrate:fresh --seed

# Run specific seeder
php artisan db:seed --class=RolePermissionSeeder

# Check migration status
php artisan migrate:status
```

### Laravel Tinker (REPL)

```bash
php artisan tinker

# Common queries
>>> \App\Models\School::all()
>>> \App\Models\User::count()
>>> \Spatie\Permission\Models\Role::with('permissions')->get()
>>> DB::table('schools')->get()
```

### Cache & Config

```bash
# Clear all cache
php artisan optimize:clear

# Or individually:
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Cache config (production)
php artisan config:cache
php artisan route:cache
```

---

## 📊 Database Quick Reference

### Connection Strings

**PostgreSQL:**
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=absensi_qr
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password
```

**SQLite:**
```env
DB_CONNECTION=sqlite
# DB_DATABASE=database/database.sqlite (auto-detected)
```

### Check Tables

```bash
php artisan tinker
>>> DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

# Or for SQLite
>>> DB::select("SELECT name FROM sqlite_master WHERE type='table'");
```

---

## 🔑 Roles & Permissions Quick Access

### Check User Roles

```php
// In Tinker
$user = \App\Models\User::find(1);
$user->roles;
$user->permissions;
$user->hasRole('super_admin');
$user->can('attendance.scan');
```

### Assign Role

```php
$user = \App\Models\User::find(1);
$user->assignRole('teacher');

// Multiple roles
$user->syncRoles(['teacher', 'homeroom_teacher']);
```

### Give Permission

```php
$user->givePermissionTo('attendance.scan');

// Check permission
$user->hasPermissionTo('attendance.scan'); // true
```

---

## 🔌 API Testing with cURL

### Login

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "admin",
    "password": "password",
    "device_name": "Postman"
  }'

# Save token from response
export TOKEN="1|abc123..."
```

### Scan QR (Authenticated)

```bash
curl -X POST http://localhost:8000/api/v1/attendance/scan \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "qr_token": "encrypted_token_here",
    "latitude": -6.200000,
    "longitude": 106.816666,
    "location_accuracy": 15.5,
    "device_info": {
      "device_id": "abc123",
      "os": "Android 13",
      "app_version": "1.0.0"
    }
  }'
```

---

## 🐛 Debugging

### Enable Debug Mode

```env
APP_DEBUG=true
APP_ENV=local
LOG_LEVEL=debug
```

### View Logs

```bash
# Real-time log
tail -f storage/logs/laravel.log

# Last 50 lines
tail -n 50 storage/logs/laravel.log
```

### Debug Queries

```php
// In your code
DB::enableQueryLog();

// Your queries here
$users = User::all();

// Dump queries
dd(DB::getQueryLog());
```

---

## 🧪 Testing Quick Guide

### Run Tests

```bash
# All tests
php artisan test

# Specific file
php artisan test tests/Feature/AuthenticationTest.php

# Specific method
php artisan test --filter=test_user_can_login

# With coverage
php artisan test --coverage

# Parallel testing (faster)
php artisan test --parallel
```

### Create Test

```bash
# Feature test
php artisan make:test AttendanceTest

# Unit test
php artisan make:test Services/QrServiceTest --unit
```

---

## 📦 Package Management

### Install Package

```bash
composer require vendor/package
```

### Update Packages

```bash
# Update all
composer update

# Update specific
composer update laravel/sanctum
```

### Remove Package

```bash
composer remove vendor/package
```

---

## 🏗️ Code Generation

### Make Commands

```bash
# Controller
php artisan make:controller Api/AttendanceController --api

# Model
php artisan make:model Attendance -mfs
# -m: migration, -f: factory, -s: seeder

# Migration
php artisan make:migration create_table_name

# Seeder
php artisan make:seeder TableSeeder

# Request (validation)
php artisan make:request StoreAttendanceRequest

# Resource (API response)
php artisan make:resource AttendanceResource

# Service (custom)
# Create manually in app/Services/

# Repository (custom)
# Create manually in app/Repositories/
```

---

## 🔐 Security Checklist

- [ ] Change `APP_KEY` in production
- [ ] Set `APP_DEBUG=false` in production
- [ ] Use strong database password
- [ ] Enable HTTPS/SSL
- [ ] Configure CORS properly
- [ ] Set up rate limiting
- [ ] Enable Redis for cache in production
- [ ] Regular backup database
- [ ] Monitor logs for suspicious activity

---

## 📁 Important Files

| File | Purpose |
|------|---------|
| `.env` | Environment configuration |
| `config/database.php` | Database config |
| `config/sanctum.php` | API auth config |
| `routes/api.php` | API routes |
| `database/migrations/` | Database schema |
| `database/seeders/` | Seed data |
| `app/Models/` | Eloquent models |
| `app/Http/Controllers/Api/` | API controllers |
| `docs/` | Full documentation |

---

## 📞 Need Help?

1. Check [Full Documentation](./docs/README.md)
2. Run `php artisan list` for all commands
3. Run `php artisan help <command>` for command details
4. Check Laravel docs: https://laravel.com/docs/12.x

---

## 💡 Pro Tips

1. **Use Tinker for quick testing** - `php artisan tinker`
2. **Always migration before seeder** - `migrate` then `db:seed`
3. **Use SQLite for quick prototyping**
4. **Enable query log for debugging slow queries**
5. **Use factories for test data**
6. **Cache config in production** - `config:cache`
7. **Use queues for heavy tasks**
8. **Monitor `storage/logs/` regularly**

---

**Quick Links:**
- [System Architecture](./docs/01_system_architecture.md)
- [Database Schema](./docs/02_database_schema.md)
- [API Specification](./docs/03_api_specification.md)
- [Flow Charts](./docs/04_flow_charts.md)
