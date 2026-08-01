# Backend Setup Notes - AbsensiQRPro

## Project Status

✅ **Completed:**
- Laravel 11 project initialized
- Core dependencies installed:
  - Laravel Sanctum v4.2 (API Authentication)
  - Spatie Laravel Permission v6.24 (RBAC)
  - Bacon QR Code v3.0 (QR Code generation)
  - Predis v3.3 (Redis client)
- Package configurations published
- Environment configured for PostgreSQL & Redis

## Database Configuration

### Option 1: PostgreSQL (Production-ready)

**Konfigurasi di `.env`:**
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=absensi_qr
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password
```

**Setup PostgreSQL:**
1. Install PostgreSQL 15+ dari https://www.postgresql.org/download/
2. Buat database:
   ```sql
   CREATE DATABASE absensi_qr;
   ```
3. Update password di `.env`
4. Run migrations: `php artisan migrate`

### Option 2: SQLite (Quick Development)

Untuk development tanpa install PostgreSQL:
```env
DB_CONNECTION=sqlite
```

Buat file database:
```bash
touch database/database.sqlite
php artisan migrate
```

## Redis Configuration

**Current Config:**
```env
REDIS_CLIENT=predis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

**Setup Redis (Optional untuk development):**
- Windows: Install Redis via WSL atau gunakan Memurai
- Atau gunakan `CACHE_STORE=database` untuk development

## Next Steps

### Phase 1: Finish Project Structure
- [  ] Create directory structure (Services, Repositories, etc.)
- [ ] Setup base Model traits
- [ ] Configure middleware

### Phase 2: Database Migrations
- [ ] Create migrations (schools, users, academic_years, etc.)
- [ ] Create seeders (roles, permissions, test data)
- [ ] Run migrations
- [ ] Verify schema

### Phase 3: Core Services
- [ ] Implement QrService
- [ ] Implement AttendanceService
- [ ] Implement LocationService
- [ ] Implement NotificationService

### Phase 4: API Development
- [ ] Auth endpoints
- [ ] Attendance endpoints
- [ ] QR management endpoints
- [ ] Reports endpoints

## Important Notes

> [!WARNING]
> **GD Extension Missing**: The `ext-gd` extension is not installed. This is required for:
> - QR code image rendering (optional - we're using Bacon QR Code which can work without it)
> - Excel export via Maatwebsite Excel
> 
> To enable GD extension:
> 1. Open `C:\xampp\php\php.ini`
> 2. Find `;extension=gd`
> 3. Remove the semicolon: `extension=gd`
> 4. Restart Apache

> [!TIP]
> For now, we can work without GD by:
> - Generating QR codes as SVG instead of PNG
> - Skipping Excel export feature temporarily

## Quick Start Commands

```bash
# Check PHP version
php --version

# Install dependencies (done)
composer install

# Generate app key (done)
php artisan key:generate

# Run migrations (after DB setup)
php artisan migrate

# Run seeders
php artisan db:seed

# Start development server
php artisan serve

# Run tests
php artisan test
```

## Environment Variables Summary

| Variable | Value | Purpose |
|----------|-------|---------|
| APP_NAME | "AbsensiQR API" | Application name |
| APP_LOCALE | id | Indonesian locale |
| DB_CONNECTION | pgsql | PostgreSQL database |
| CACHE_STORE | redis | Redis caching |
| QUEUE_CONNECTION | redis | Redis queue |
| REDIS_CLIENT | predis | Predis driver |

---

**Last updated:** 2026-01-19  
**Laravel version:** 12.47.0  
**PHP version:** 8.2.12
