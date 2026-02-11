# AbsensiQRPro - Panduan Deployment Produksi

## Daftar Isi
1. [Persyaratan Sistem](#persyaratan-sistem)
2. [Persiapan Environment](#persiapan-environment)
3. [Database Migration](#database-migration)
4. [Konfigurasi Server](#konfigurasi-server)
5. [SSL Setup](#ssl-setup)
6. [Monitoring Setup](#monitoring-setup)
7. [Backup & Recovery](#backup--recovery)
8. [Deployment Checklist](#deployment-checklist)
9. [Troubleshooting](#troubleshooting)

---

## Persyaratan Sistem

### Backend Requirements
| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.2+ | 8.4+ |
| PostgreSQL | 14+ | 16+ |
| Redis | 7.0+ | 7.2+ |
| RAM | 4GB | 8GB+ |
| Storage | 50GB SSD | 100GB+ SSD |

### Frontend Requirements
| Component | Version |
|-----------|---------|
| Node.js | 18+ |
| npm/yarn | Latest LTS |

### Mobile App Requirements
| Component | Version |
|-----------|---------|
| React Native | 0.72+ |
| Android SDK | 30+ |
| iOS | 14+ |

---

## Persiapan Environment

### 1. Environment Variables (Backend)

Buat file `.env` di direktori `backend/`:

```env
# Application
APP_NAME="AbsensiQRPro"
APP_ENV=production
APP_KEY=base64:your-32-char-key-here
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=absensi_production
DB_USERNAME=your-db-user
DB_PASSWORD=your-secure-password

# Redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis

# Session
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true

# Cache
CACHE_DRIVER=redis

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-mail-user
MAIL_PASSWORD=your-mail-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@your-domain.com"
MAIL_FROM_NAME="AbsensiQRPro"

# QR Security (CRITICAL - Must be unique and 32+ chars)
QR_SECRET_KEY=your-super-secret-key-minimum-32-characters

# WebSocket (Laravel Reverb)
REVERB_APP_ID=your-reverb-app-id
REVERB_APP_KEY=your-reverb-app-key
REVERB_APP_SECRET=your-reverb-app-secret
REVERB_HOST="0.0.0.0"
REVERB_PORT=8080
REVERB_SCHEME=https

# Sentry Error Tracking
SENTRY_LARAVEL_DSN=https://your-sentry-dsn
SENTRY_TRACES_SAMPLE_RATE=0.2

# Centralized Logging
LOG_CHANNEL=centralized
LOGSTASH_HOST=your-elk-host
LOGSTASH_PORT=5044
```

### 2. Environment Variables (Frontend Web)

Buat file `.env` di direktori `frontend-web/`:

```env
VITE_API_URL=https://api.your-domain.com/api/v1
VITE_WS_URL=wss://ws.your-domain.com
VITE_SENTRY_DSN=https://your-sentry-dsn
VITE_APP_ENV=production
```

### 3. Environment Variables (Mobile)

Buat file `.env` di direktori `AbsensiQRMobile/`:

```env
EXPO_PUBLIC_API_URL=https://api.your-domain.com/api
EXPO_PUBLIC_WS_URL=wss://ws.your-domain.com
```

---

## Database Migration

### 1. Persiapan Database

```bash
# Connect ke PostgreSQL
psql -U postgres

# Create database dan user
CREATE DATABASE absensi_production;
CREATE USER absensi_user WITH ENCRYPTED PASSWORD 'your-secure-password';
GRANT ALL PRIVILEGES ON DATABASE absensi_production TO absensi_user;

# Enable extensions
\c absensi_production
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
```

### 2. Run Migrations

```bash
cd backend

# Clear caches
php artisan config:clear
php artisan cache:clear

# Run migrations
php artisan migrate --force

# Seed initial data (optional)
php artisan db:seed --class=ProductionSeeder --force

# Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 3. Backup Existing Data (Jika Upgrade)

```bash
# Backup sebelum migration
pg_dump -U absensi_user -h localhost absensi_production > backup_$(date +%Y%m%d_%H%M%S).sql

# Verify backup
pg_restore --list backup_*.sql | head -20
```

---

## Konfigurasi Server

### 1. Nginx Configuration

```nginx
# /etc/nginx/sites-available/absensi-api
server {
    listen 443 ssl http2;
    server_name api.your-domain.com;
    root /var/www/absensi/backend/public;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/api.your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.your-domain.com/privkey.pem;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:50m;
    ssl_session_tickets off;

    # Modern TLS configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;

    # Security Headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';" always;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Laravel
    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Rate limiting
    limit_req_zone $binary_remote_addr zone=api:10m rate=60r/m;
    limit_req zone=api burst=20 nodelay;
}

# Frontend Web
server {
    listen 443 ssl http2;
    server_name app.your-domain.com;
    root /var/www/absensi/frontend-web/dist;

    ssl_certificate /etc/letsencrypt/live/app.your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.your-domain.com/privkey.pem;

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

### 2. PHP-FPM Configuration

```ini
; /etc/php/8.4/fpm/pool.d/absensi.conf
[absensi]
user = www-data
group = www-data
listen = /var/run/php/php8.4-fpm-absensi.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 10M

; Opcache
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 256
php_admin_value[opcache.max_accelerated_files] = 20000
php_admin_value[opcache.validate_timestamps] = 0
```

### 3. Supervisor Configuration (Queue & WebSocket)

```ini
; /etc/supervisor/conf.d/absensi-queue.conf
[program:absensi-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/absensi/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/absensi-queue.log
stopwaitsecs=3600

; /etc/supervisor/conf.d/absensi-reverb.conf
[program:absensi-reverb]
command=php /var/www/absensi/backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/absensi-reverb.log
```

---

## SSL Setup

### 1. Let's Encrypt dengan Certbot

```bash
# Install certbot
apt update
apt install certbot python3-certbot-nginx

# Generate certificates
certbot --nginx -d api.your-domain.com -d app.your-domain.com

# Auto-renewal (cron)
0 0 1 * * certbot renew --quiet
```

### 2. SSL Pinning Certificates (Mobile)

```bash
# Extract public key hash untuk SSL pinning
openssl s_client -servername api.your-domain.com -connect api.your-domain.com:443 < /dev/null 2>/dev/null | \
  openssl x509 -pubkey -noout | \
  openssl pkey -pubin -outform der | \
  openssl dgst -sha256 -binary | \
  openssl enc -base64

# Output akan seperti: AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
# Simpan hash ini di mobile app config
```

Update file `AbsensiQRMobile/src/config/sslPinning.ts`:

```typescript
export const SSL_PINS = {
  production: [
    'sha256/YOUR_PRIMARY_CERTIFICATE_HASH',
    'sha256/YOUR_BACKUP_CERTIFICATE_HASH',
  ],
};
```

---

## Monitoring Setup

### 1. Sentry Error Tracking

```bash
# Backend
composer require sentry/sentry-laravel

# Publish config
php artisan sentry:publish
```

### 2. New Relic APM

```bash
# Install New Relic PHP agent
wget -O - https://download.newrelic.com/548C16BF.gpg | apt-key add -
echo "deb http://apt.newrelic.com/debian/ newrelic non-free" > /etc/apt/sources.list.d/newrelic.list
apt update
apt install newrelic-php5

# Configure
newrelic-install install
```

### 3. Prometheus + Grafana

```yaml
# docker-compose.monitoring.yml
version: '3.8'
services:
  prometheus:
    image: prom/prometheus:latest
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
    ports:
      - "9090:9090"

  grafana:
    image: grafana/grafana:latest
    ports:
      - "3000:3000"
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=your-secure-password
```

### 4. Health Check Endpoints

Endpoint yang sudah tersedia:
- `GET /api/health` - Basic health check
- `GET /api/health/detailed` - Detailed system status

---

## Backup & Recovery

### 1. Automated Database Backup

```bash
#!/bin/bash
# /opt/scripts/backup-db.sh

BACKUP_DIR="/var/backups/absensi"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=30

# Create backup
pg_dump -U absensi_user -h localhost absensi_production | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Upload to S3 (optional)
aws s3 cp $BACKUP_DIR/db_$DATE.sql.gz s3://your-backup-bucket/absensi/

# Cleanup old backups
find $BACKUP_DIR -name "*.sql.gz" -mtime +$RETENTION_DAYS -delete
```

Tambahkan ke crontab:
```bash
0 2 * * * /opt/scripts/backup-db.sh >> /var/log/backup.log 2>&1
```

### 2. Disaster Recovery Procedure

1. **RTO (Recovery Time Objective):** 1 jam
2. **RPO (Recovery Point Objective):** 24 jam (backup harian)

```bash
# Recovery steps
# 1. Setup new server dengan konfigurasi yang sama
# 2. Restore database
gunzip -c /path/to/backup.sql.gz | psql -U absensi_user -d absensi_production

# 3. Deploy aplikasi
cd /var/www/absensi/backend
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache

# 4. Restart services
supervisorctl restart all
systemctl restart php8.4-fpm
systemctl restart nginx
```

---

## Deployment Checklist

### Pre-Deployment
- [ ] Backup database existing
- [ ] Review environment variables
- [ ] Test migrations di staging
- [ ] Verify SSL certificates valid
- [ ] Check disk space (min 20% free)

### Deployment
- [ ] Enable maintenance mode: `php artisan down`
- [ ] Pull latest code: `git pull origin main`
- [ ] Install dependencies: `composer install --no-dev`
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Clear caches: `php artisan optimize:clear`
- [ ] Cache configs: `php artisan config:cache`
- [ ] Cache routes: `php artisan route:cache`
- [ ] Restart queue workers: `supervisorctl restart absensi-queue:*`
- [ ] Disable maintenance mode: `php artisan up`

### Post-Deployment
- [ ] Verify health endpoint: `curl https://api.your-domain.com/api/health`
- [ ] Test login flow
- [ ] Test QR scanning
- [ ] Monitor error rates di Sentry
- [ ] Check log files untuk errors
- [ ] Verify queue processing

### Rollback Procedure
```bash
# Jika ada masalah, rollback ke commit sebelumnya
php artisan down
git checkout HEAD~1
composer install --no-dev
php artisan migrate:rollback --step=1
php artisan optimize:clear
supervisorctl restart absensi-queue:*
php artisan up
```

---

## Troubleshooting

### Common Issues

#### 1. 500 Internal Server Error
```bash
# Check logs
tail -f /var/www/absensi/backend/storage/logs/laravel.log
tail -f /var/log/nginx/error.log

# Fix permissions
chown -R www-data:www-data /var/www/absensi/backend/storage
chmod -R 775 /var/www/absensi/backend/storage
```

#### 2. Queue Jobs Not Processing
```bash
# Check supervisor status
supervisorctl status

# Restart workers
supervisorctl restart absensi-queue:*

# Check Redis connection
redis-cli ping
```

#### 3. WebSocket Connection Failed
```bash
# Check Reverb status
supervisorctl status absensi-reverb

# Test WebSocket
wscat -c wss://ws.your-domain.com/app/your-key
```

#### 4. Database Connection Issues
```bash
# Test connection
psql -U absensi_user -h localhost -d absensi_production -c "SELECT 1"

# Check max connections
psql -c "SHOW max_connections;"
```

---

## Contacts

**Technical Support:**
- Email: support@your-domain.com
- Phone: +62-xxx-xxx-xxxx (Jam kerja: 08.00-17.00 WIB)

**Emergency Contact (24/7):**
- DevOps On-Call: +62-xxx-xxx-xxxx

---

*Dokumen ini terakhir diperbarui: Februari 2026*
