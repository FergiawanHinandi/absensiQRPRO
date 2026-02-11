# Production Deployment Checklist

## AbsensiQRPro - Go-Live Checklist

> Gunakan checklist ini untuk memastikan semua aspek siap sebelum deployment ke production.

---

## 📋 Pre-Deployment Checks

### 1. Code Readiness

- [ ] Semua tests passing (`php artisan test`, `npm test`)
- [ ] No linting errors (`npm run lint`, `./vendor/bin/pint --test`)
- [ ] No TypeScript errors (`npm run type-check`)
- [ ] Code review completed dan approved
- [ ] Merge ke branch `main`/`production` sudah dilakukan
- [ ] Git tag version dibuat (e.g., `v1.0.0`)

### 2. Environment Configuration

#### Backend (.env)
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` generated (unique, 32 chars)
- [ ] `QR_SECRET_KEY` set (unique, 32+ chars, BUKAN sama dengan APP_KEY)
- [ ] `LOG_CHANNEL=stack` dengan daily rotation
- [ ] `LOG_LEVEL=warning` atau `error`

#### Database
- [ ] `DB_CONNECTION=pgsql`
- [ ] `DB_HOST` pointing ke production server
- [ ] `DB_DATABASE` created
- [ ] `DB_USERNAME` dengan minimal privileges
- [ ] `DB_PASSWORD` strong (20+ chars, random)

#### Cache & Queue
- [ ] `CACHE_DRIVER=redis`
- [ ] `QUEUE_CONNECTION=redis`
- [ ] `SESSION_DRIVER=redis`
- [ ] `REDIS_HOST` dan `REDIS_PASSWORD` configured
- [ ] Redis persistence enabled

#### Mail
- [ ] `MAIL_MAILER` configured (smtp/ses/mailgun)
- [ ] `MAIL_FROM_ADDRESS` set to valid domain
- [ ] Test email sent dan diterima

#### Services
- [ ] `SENTRY_LARAVEL_DSN` configured
- [ ] `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` set

#### Frontend (.env)
- [ ] `VITE_API_URL` pointing ke production API
- [ ] `VITE_WS_URL` pointing ke production WebSocket

#### Mobile
- [ ] `EXPO_PUBLIC_API_URL` updated
- [ ] SSL Pinning certificates updated

### 3. Database Preparation

- [ ] Backup existing production data (jika ada)
- [ ] Test migration di staging environment
- [ ] Migration script reviewed
- [ ] Rollback plan documented
- [ ] Seeders TIDAK akan run di production

```bash
# Run migrations (after backup)
php artisan migrate --force
```

### 4. Security Verification

- [ ] SSL Certificate installed dan valid
- [ ] HTTPS redirect enabled
- [ ] Security headers configured (X-Frame-Options, CSP, etc.)
- [ ] CORS restricted ke allowed domains saja
- [ ] Rate limiting enabled
- [ ] Firewall rules configured (only 80, 443, 22)
- [ ] SSH key-only authentication (no password)
- [ ] Admin passwords changed from defaults
- [ ] Test accounts removed

### 5. Infrastructure Setup

#### Server
- [ ] PHP 8.4+ installed dengan required extensions
- [ ] Composer dependencies installed (`--no-dev`)
- [ ] Node.js 20+ untuk frontend build
- [ ] Nginx configured dengan production settings
- [ ] PHP-FPM tuned untuk expected load

#### Services
- [ ] PostgreSQL 15+ running
- [ ] Redis 7+ running dengan password
- [ ] Supervisor configured untuk queue workers
- [ ] Supervisor configured untuk Laravel Reverb

#### CDN/DNS
- [ ] DNS records pointing ke production server
- [ ] CDN configured untuk static assets
- [ ] SSL certificates propagated

---

## 🚀 Deployment Steps

### Step 1: Preparation (D-1)

```bash
# Notify stakeholders
echo "Deployment scheduled for tomorrow at 02:00 WIB"

# Final backup
pg_dump -h localhost -U postgres absensi_prod > /backups/pre-deployment-$(date +%Y%m%d).sql
```

### Step 2: Enable Maintenance Mode

```bash
cd /var/www/absensi

# Enable maintenance dengan secret access
php artisan down --secret="deployment-bypass-secret-123"

# Verify maintenance mode
curl -I https://your-domain.com/api/v1/health
# Should return 503
```

### Step 3: Backup Current State

```bash
# Backup database
pg_dump -Fc absensi_prod > /backups/deployment_$(date +%Y%m%d_%H%M%S).dump

# Backup files
tar -czf /backups/www_backup_$(date +%Y%m%d).tar.gz /var/www/absensi

# Verify backups
ls -la /backups/
```

### Step 4: Deploy Code

```bash
# Pull latest code
cd /var/www/absensi
git fetch origin
git checkout v1.0.0  # atau tag/branch yang sesuai

# Install dependencies
composer install --no-dev --optimize-autoloader

# Build frontend
cd frontend-web
npm ci
npm run build

# Copy build to public
cp -r dist/* ../backend/public/
cd ../backend
```

### Step 5: Run Migrations

```bash
# Run migrations dengan force
php artisan migrate --force

# Verify
php artisan migrate:status
```

### Step 6: Optimize Application

```bash
# Clear old cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Rebuild cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Optimize autoloader
composer dump-autoload --optimize
```

### Step 7: Restart Services

```bash
# Restart PHP-FPM
sudo systemctl restart php8.4-fpm

# Restart queue workers
sudo supervisorctl restart laravel-worker:*

# Restart WebSocket server
sudo supervisorctl restart laravel-reverb

# Verify all services running
sudo supervisorctl status
```

### Step 8: Disable Maintenance Mode

```bash
php artisan up
```

### Step 9: Smoke Tests

```bash
# Health check
curl https://your-domain.com/api/v1/health

# Login test (using test account)
curl -X POST https://your-domain.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"test_admin","password":"test_password"}'

# Frontend loads
curl -I https://your-domain.com/admin
```

---

## ✅ Post-Deployment Verification

### Functional Tests

- [ ] Login works untuk semua role types
- [ ] QR Code generation works
- [ ] QR Code scanning works (test dengan mobile app)
- [ ] Attendance tercatat dengan benar
- [ ] Reports dapat di-generate
- [ ] Notifikasi terkirim (test push notification)
- [ ] WebSocket real-time updates working
- [ ] File upload works
- [ ] Export PDF/Excel works

### Performance Tests

- [ ] Response time < 500ms untuk API calls
- [ ] Dashboard loads < 3s
- [ ] No N+1 queries di log
- [ ] Memory usage normal
- [ ] CPU usage normal

### Security Tests

- [ ] HTTPS enforced (HTTP redirects)
- [ ] Security headers present
- [ ] Rate limiting working
- [ ] Invalid auth returns 401
- [ ] Cross-tenant access blocked

### Monitoring

- [ ] Sentry receiving errors (test with intentional error)
- [ ] Log files being written
- [ ] Queue jobs processing
- [ ] Disk space sufficient (>20% free)

---

## 📊 Success Metrics

| Metric | Target | Actual |
|--------|--------|--------|
| API Response Time (p95) | < 500ms | ___ms |
| Error Rate | < 0.1% | ___% |
| Uptime | > 99.9% | ___% |
| Login Success Rate | > 99% | ___% |
| QR Scan Success Rate | > 98% | ___% |

---

## 🔄 Rollback Plan

Jika terjadi masalah critical:

### Quick Rollback (< 5 minutes)

```bash
# 1. Enable maintenance
php artisan down --secret="emergency-access"

# 2. Rollback code
git checkout v0.9.0  # previous version

# 3. Rollback migration (jika perlu)
php artisan migrate:rollback --step=1

# 4. Restore cache
php artisan config:cache
php artisan route:cache

# 5. Restart services
sudo systemctl restart php8.4-fpm
sudo supervisorctl restart all

# 6. Back online
php artisan up
```

### Full Rollback (database restore)

```bash
# 1. Enable maintenance
php artisan down --secret="emergency-access"

# 2. Restore database
pg_restore -h localhost -U postgres -d absensi_prod --clean /backups/pre-deployment-xxx.dump

# 3. Restore files
tar -xzf /backups/www_backup_xxx.tar.gz -C /

# 4. Clear cache
php artisan cache:clear
php artisan config:cache

# 5. Restart services
sudo systemctl restart php8.4-fpm nginx

# 6. Back online
php artisan up
```

---

## 📞 Escalation Contacts

| Role | Name | Phone | Email |
|------|------|-------|-------|
| Project Manager | ___ | ___ | ___ |
| Tech Lead | ___ | ___ | ___ |
| DevOps | ___ | ___ | ___ |
| DBA | ___ | ___ | ___ |
| On-Call Engineer | ___ | ___ | ___ |

---

## 📝 Deployment Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| QA Lead | | | |
| Tech Lead | | | |
| Project Manager | | | |
| Security Officer | | | |

---

## 📋 Post-Deployment Tasks (D+1)

- [ ] Monitor error rates for 24 hours
- [ ] Review performance metrics
- [ ] Check user feedback
- [ ] Update documentation jika ada perubahan
- [ ] Close deployment ticket/issue
- [ ] Conduct retrospective meeting
- [ ] Archive deployment artifacts

---

## 📈 Monitoring Links

| Service | URL |
|---------|-----|
| Application | https://your-domain.com |
| API Health | https://your-domain.com/api/v1/health |
| Sentry Dashboard | https://sentry.io/organizations/your-org/projects/absensi/ |
| Server Metrics | https://your-monitoring-tool.com |
| Database Metrics | https://your-db-monitoring.com |

---

*Checklist Version: 1.0*  
*Last Updated: February 2026*
