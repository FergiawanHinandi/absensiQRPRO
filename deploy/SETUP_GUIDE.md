# AbsensiQRPro - Production Setup Guide

Panduan lengkap untuk deployment AbsensiQRPro ke production.

---

## Quick Start (5 Menit)

```bash
# 1. Jalankan Setup Wizard (interaktif)
./deploy/setup-wizard.sh

# 2. Generate Android keystore
./deploy/generate-keystore.sh

# 3. Setup server (jika belum ada)
./deploy/setup-server.sh absensiqr.example.com absensiqr

# 4. Deploy
./deploy/deploy-production.sh
```

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Step 1: Setup Wizard](#step-1-setup-wizard)
3. [Step 2: Android Keystore](#step-2-android-keystore)
4. [Step 3: Server Setup](#step-3-server-setup)
5. [Step 4: Deployment](#step-4-deployment)
6. [Step 5: Verification](#step-5-verification)
7. [Step 6: Load Testing](#step-6-load-testing)
8. [Step 7: Security Scanning](#step-7-security-scanning)
9. [Troubleshooting](#troubleshooting)
10. [Rollback Procedure](#rollback-procedure)

---

## Prerequisites

### Server Requirements
- **OS**: Ubuntu 22.04 LTS or similar
- **RAM**: Minimum 2GB (4GB recommended)
- **Storage**: Minimum 20GB
- **Access**: SSH root or sudo access

### Software Requirements
- PHP 8.2+
- PostgreSQL 15+
- Redis 7+
- Nginx
- Supervisor
- Composer
- Node.js 20+

### Local Requirements
- Git
- Bash shell
- OpenSSL (for generating secrets)
- Java JDK (for Android keystore)

---

## Step 1: Setup Wizard

Setup wizard akan memandu Anda mengisi semua konfigurasi yang diperlukan.

```bash
# Jalankan setup wizard
./deploy/setup-wizard.sh
```

### Yang akan dikonfigurasi:
1. **Domain** - URL production aplikasi
2. **Database** - PostgreSQL connection settings
3. **Redis** - Cache dan queue settings
4. **Midtrans** - Payment gateway (opsional)
5. **Firebase** - Push notifications
6. **Monitoring** - Sentry, Telegram, Slack (opsional)

### Output:
- `.env.production` - Environment configuration
- `deploy/.setup-config` - Configuration backup

---

## Step 2: Android Keystore

Keystore diperlukan untuk signing APK/AAB release.

```bash
# Generate keystore
./deploy/generate-keystore.sh
```

### Yang akan dibuat:
- `AbsensiQRMobile/android/app/keystore.jks` - Signing keystore
- `AbsensiQRMobile/android/app/keystore.properties` - Configuration

### ⚠️ PENTING:
1. **SIMPAN keystore.jks di tempat aman**
2. **JANGAN commit ke git**
3. **Buat backup di tempat terpisah**
4. **Jika keystore hilang, Anda TIDAK BISA update app di Play Store!**

---

## Step 3: Server Setup

Setup server akan menginstall dan konfigurasi semua dependencies.

```bash
# Setup server
./deploy/setup-server.sh [domain] [deploy_user]

# Contoh:
./deploy/setup-server.sh absensiqr.example.com absensiqr
```

### Yang akan diinstall:
- PHP 8.2-FPM
- PostgreSQL 15
- Redis 7
- Nginx
- Supervisor
- Certbot (SSL)

### Yang akan dikonfigurasi:
- Database PostgreSQL
- Redis Sentinel (HA)
- Nginx reverse proxy
- SSL certificate (Let's Encrypt)
- Queue workers (Supervisor)
- Cron scheduler

---

## Step 4: Deployment

### Option A: Deploy ke Server yang Sudah Ada

```bash
# Deploy dengan rollback mechanism
./deploy/deploy-production.sh
```

### Option B: Deployment Day (Fresh Server)

```bash
# Deployment day script
./deploy/deployment-day.sh
```

### Option C: Manual Deployment

```bash
# 1. SSH ke server
ssh deploy@your-server

# 2. Clone repository
cd /var/www
git clone https://github.com/[USER]/absensiqrpro.git
cd absensiqrpro

# 3. Setup environment
cp .env.production .env
php artisan key:generate

# 4. Install dependencies
composer install --no-dev --optimize-autoloader
npm install --prefix frontend-web
npm run build --prefix frontend-web

# 5. Run migrations
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force

# 6. Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# 7. Start services
sudo supervisorctl restart all
```

---

## Step 5: Verification

### Manual Verification

```bash
# 1. Health check
curl https://your-domain.com/health

# 2. API check
curl https://your-domain.com/api/v1/health

# 3. SSL check
echo | openssl s_client -connect your-domain.com:443 -servername your-domain.com 2>/dev/null | openssl x509 -noout -dates

# 4. DNS check
dig your-domain.com
```

### Automated Verification

```bash
# Jalankan verifikasi otomatis
./deploy/master-setup.sh verify
```

---

## Step 6: Load Testing

### Prerequisites
- K6 load testing tool installed
- Application running and accessible

### Jalankan Load Test

```bash
# Option 1: Via master setup
./deploy/master-setup.sh loadtest

# Option 2: Direct execution
cd tests/load-test
./run.sh https://your-domain.com
```

### Interpreting Results
- **http_req_duration**: Response time
- **http_req_failed**: Error rate
- **http_reqs**: Total requests

### Thresholds
- 95% requests < 500ms
- Error rate < 1%

---

## Step 7: Security Scanning

### Prerequisites
- Docker installed
- Target application accessible

### Jalankan Security Scan

```bash
# Option 1: Via master setup
./deploy/master-setup.sh security

# Option 2: Direct execution
cd tests/security
./security-scan.sh https://your-domain.com
```

### Output
- HTML report: `tests/security/reports/security-report-*.html`
- JSON report: `tests/security/reports/security-report-*.json`
- XML report: `tests/security/reports/security-report-*.xml`

### Review Report
1. Open HTML report in browser
2. Review all findings
3. Address critical/high vulnerabilities
4. Re-run scan after fixes

---

## Troubleshooting

### Common Issues

#### 1. Database Connection Failed
```bash
# Check PostgreSQL status
sudo systemctl status postgresql

# Check database exists
sudo -u postgres psql -c "\l"

# Check user permissions
sudo -u postgres psql -c "\du"
```

#### 2. Redis Connection Failed
```bash
# Check Redis status
sudo systemctl status redis-server

# Test connection
redis-cli ping
```

#### 3. Nginx 502 Bad Gateway
```bash
# Check PHP-FPM status
sudo systemctl status php8.2-fpm

# Check Supervisor status
sudo supervisorctl status
```

#### 4. SSL Certificate Error
```bash
# Check certificate
sudo certbot certificates

# Renew certificate
sudo certbot renew
```

#### 5. Queue Jobs Not Processing
```bash
# Check queue workers
sudo supervisorctl status

# Restart queue workers
sudo supervisorctl restart absensiqrpro-worker:*

# Check failed jobs
php artisan queue:failed
```

---

## Rollback Procedure

### Quick Rollback

```bash
# Rollback to previous version
./deploy/deploy-production.sh --rollback
```

### Manual Rollback

```bash
# 1. SSH to server
ssh deploy@your-server

# 2. Go to app directory
cd /var/www/absensiqrpro

# 3. Reset to last commit
git reset --hard HEAD~1

# 4. Reinstall dependencies
composer install --no-dev --optimize-autoloader

# 5. Rollback migrations (if needed)
php artisan migrate:rollback --force

# 6. Clear caches
php artisan config:cache
php artisan route:cache

# 7. Restart services
sudo supervisorctl restart all
```

### Emergency Rollback

Jika rollback gagal, restore dari backup:

```bash
# 1. Stop application
sudo supervisorctl stop all

# 2. Restore database
pg_restore -d absensiqrpro -U absensiqrpro_user /backups/latest.dump

# 3. Restore files
tar -xzf /backups/files-latest.tar.gz -C /var/www/absensiqrpro

# 4. Start application
sudo supervisorctl start all
```

---

## Master Setup Script

Gunakan master setup script untuk kemudahan:

```bash
# Jalankan master setup
./deploy/master-setup.sh

# Atau langsung dengan command
./deploy/master-setup.sh 1    # Setup Wizard
./deploy/master-setup.sh 2    # Generate Keystore
./deploy/master-setup.sh 3    # Setup Server
./deploy/master-setup.sh 4    # Deploy
./deploy/master-setup.sh 5    # Load Test
./deploy/master-setup.sh 6    # Security Scan
./deploy/master-setup.sh 7    # Verify Deployment
./deploy/master-setup.sh 8    # Health Check
./deploy/master-setup.sh 9    # View Checklist
./deploy/master-setup.sh 10   # View DR Runbook
```

---

## Additional Resources

- [FINAL_CHECKLIST.md](FINAL_CHECKLIST.md) - Pre-deployment checklist
- [DR_RUNBOOK.md](../docs/DR_RUNBOOK.md) - Disaster recovery guide
- [security-checklist.md](../tests/security/security-checklist.md) - Security verification

---

## Support

Jika mengalami masalah:
1. Cek logs: `tail -f /var/log/supervisor/absensiqrpro-*.log`
2. Cek health: `curl https://your-domain.com/health`
3. Ikuti troubleshooting guide di atas
4. Jika masih stuck, cek DR_RUNBOOK.md
