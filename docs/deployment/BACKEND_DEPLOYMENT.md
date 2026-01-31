# Deployment Guide - Security Implementation

**Last Updated:** January 28, 2026  
**Version:** 1.0.0  
**For:** DevOps & Deployment Engineers

---

## 📋 Table of Contents

1. [Pre-Deployment](#pre-deployment)
2. [Backend Deployment](#backend-deployment)
3. [Mobile Deployment](#mobile-deployment)
4. [Post-Deployment](#post-deployment)
5. [Rollback Procedures](#rollback-procedures)

---

## 🎯 Pre-Deployment

### 1. Prerequisites Checklist

#### Backend Requirements

- [ ] PHP 8.2+ installed
- [ ] Composer installed
- [ ] MySQL/PostgreSQL database
- [ ] Redis (for cache & rate limiting)
- [ ] Web server (Nginx/Apache)
- [ ] SSL certificate (HTTPS required)

#### Mobile Requirements

- [ ] Node.js 18+ installed
- [ ] npm or yarn installed
- [ ] Xcode (for iOS)
- [ ] Android Studio (for Android)
- [ ] Apple Developer Account (iOS)
- [ ] Google Play Console Account (Android)

### 2. Environment Configuration

#### Backend `.env`

```env
# Application
APP_NAME="AbsensiQRPro"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.school.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=absensi_pro
DB_USERNAME=absensi_user
DB_PASSWORD=your-secure-password

# Cache & Session
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Security
SANCTUM_STATEFUL_DOMAINS=app.school.com,www.school.com
SANCTUM_EXPIRATION=null
SESSION_LIFETIME=120

# CORS
FRONTEND_URL=https://app.school.com
MOBILE_APP_URL=absensiapp://

# Rate Limiting (optional - defaults in middleware)
RATE_LIMIT_GLOBAL=1000
RATE_LIMIT_LOGIN=5
RATE_LIMIT_SCAN=10
RATE_LIMIT_API=60

# QR Code
QR_CODE_SECRET=CHANGE_THIS_TO_RANDOM_STRING
QR_CODE_EXPIRY=300

# Mail (for notifications)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
```

#### Mobile `.env`

```env
API_URL=https://api.school.com/api
API_TIMEOUT=15000
```

### 3. Run All Tests

```bash
cd backend

# Run all security tests
php artisan test --filter="Policy|Security|Validation|RateLimit"

# Expected: 59 tests pass ✅
```

**⚠️ DO NOT PROCEED if tests fail!**

---

## 🚀 Backend Deployment

### Step 1: Prepare Application

```bash
cd backend

# 1. Pull latest code
git pull origin main

# 2. Install dependencies (production only)
composer install --no-dev --optimize-autoloader

# 3. Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 4. Run migrations
php artisan migrate --force

# Expected: All migrations run successfully ✅
```

### Step 2: Optimize for Production

```bash
# 1. Cache configuration
php artisan config:cache

# 2. Cache routes
php artisan route:cache

# 3. Cache views
php artisan view:cache

# 4. Optimize autoloader
composer dump-autoload --optimize

# 5. Generate app key (if not exists)
php artisan key:generate
```

### Step 3: Set Permissions

```bash
# Storage and cache directories
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Or for specific user
chown -R $USER:www-data storage bootstrap/cache
```

### Step 4: Configure Web Server

#### Nginx Configuration

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.school.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.school.com;

    root /var/www/absensi/backend/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /etc/ssl/certs/school.com.crt;
    ssl_certificate_key /etc/ssl/private/school.com.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security Headers (already in SecurityHeaders middleware, but good to have)
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Logging
    access_log /var/log/nginx/absensi-access.log;
    error_log /var/log/nginx/absensi-error.log;

    # PHP-FPM
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

#### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName api.school.com
    Redirect permanent / https://api.school.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName api.school.com
    DocumentRoot /var/www/absensi/backend/public

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/school.com.crt
    SSLCertificateKeyFile /etc/ssl/private/school.com.key

    # Security Headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"

    <Directory /var/www/absensi/backend/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/absensi-error.log
    CustomLog ${APACHE_LOG_DIR}/absensi-access.log combined
</VirtualHost>
```

### Step 5: Setup Queue Workers (Optional)

```bash
# Install supervisor
sudo apt-get install supervisor

# Create supervisor config
sudo nano /etc/supervisor/conf.d/absensi-worker.conf
```

```ini
[program:absensi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/absensi/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/absensi/backend/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
# Start supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start absensi-worker:*
```

### Step 6: Setup Scheduler

```bash
# Add to crontab
crontab -e

# Add this line
* * * * * cd /var/www/absensi/backend && php artisan schedule:run >> /dev/null 2>&1
```

### Step 7: Verify Backend Deployment

```bash
# 1. Check health endpoint
curl https://api.school.com/api/v1/health

# Expected: {"status":"ok","timestamp":"..."}

# 2. Test login
curl -X POST https://api.school.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# Expected: {"success":true,"token":"..."}

# 3. Check rate limiting
for i in {1..6}; do
  curl -X POST https://api.school.com/api/v1/auth/login \
    -d '{"username":"admin","password":"wrong"}'
done

# Expected: First 5 return 401, 6th returns 429 ✅
```

---

## 📱 Mobile Deployment

### Step 1: Prepare Mobile App

```bash
cd AbsensiQRMobile

# 1. Install dependencies
npm install

# 2. Update environment
cp .env.example .env
# Edit .env with production API URL

# 3. Update version
# Edit package.json, ios/Info.plist, android/app/build.gradle
```

### Step 2: iOS Deployment

#### Build for TestFlight

```bash
# 1. Install pods
cd ios
pod install
cd ..

# 2. Open in Xcode
open ios/AbsensiQR.xcworkspace

# 3. In Xcode:
# - Select "Any iOS Device" as target
# - Product > Archive
# - Upload to App Store Connect
# - Submit for TestFlight review
```

#### Configuration Checklist

- [ ] Bundle ID correct
- [ ] Version number incremented
- [ ] Build number incremented
- [ ] Signing certificate valid
- [ ] Provisioning profile valid
- [ ] App icons present
- [ ] Launch screen configured

### Step 3: Android Deployment

#### Build Release APK/AAB

```bash
cd android

# 1. Generate release keystore (first time only)
keytool -genkeypair -v -storetype PKCS12 \
  -keystore absensi-release.keystore \
  -alias absensi-key \
  -keyalg RSA -keysize 2048 -validity 10000

# 2. Configure gradle
# Edit android/gradle.properties
MYAPP_RELEASE_STORE_FILE=absensi-release.keystore
MYAPP_RELEASE_KEY_ALIAS=absensi-key
MYAPP_RELEASE_STORE_PASSWORD=STRONG_PASSWORD
MYAPP_RELEASE_KEY_PASSWORD=STRONG_PASSWORD

# 3. Build release
./gradlew bundleRelease

# Output: android/app/build/outputs/bundle/release/app-release.aab
```

#### Upload to Play Console

```bash
# 1. Go to Google Play Console
# 2. Create new release
# 3. Upload AAB file
# 4. Fill release notes
# 5. Submit for review
```

### Step 4: Verify Mobile Deployment

#### iOS Testing

```
1. Install from TestFlight
2. Login with test account
3. Verify token storage (check logs - no actual tokens)
4. Test QR scanning
5. Test logout
6. Verify tokens cleared
```

#### Android Testing

```
1. Install from Play Console (internal testing)
2. Login with test account
3. Verify token storage (check logcat - no actual tokens)
4. Test QR scanning
5. Test logout
6. Verify tokens cleared
```

---

## ✅ Post-Deployment

### 1. Smoke Tests

```bash
# Backend
curl https://api.school.com/api/v1/health
curl https://api.school.com/api/v1/test

# Login test
curl -X POST https://api.school.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"testuser","password":"password"}'
```

### 2. Monitor Logs

```bash
# Backend logs
tail -f /var/www/absensi/backend/storage/logs/laravel.log

# Nginx logs
tail -f /var/log/nginx/absensi-error.log

# Watch for errors
grep "ERROR" /var/www/absensi/backend/storage/logs/laravel.log
```

### 3. Check Security

```bash
# Check rate limit violations
grep "Rate Limit Exceeded" storage/logs/laravel.log

# Check QR security anomalies
grep "QR Security Anomaly" storage/logs/laravel.log

# Check failed auth attempts
grep "Login failed" storage/logs/laravel.log
```

### 4. Performance Monitoring

```bash
# Check response times
grep "Attendance recorded" storage/logs/laravel.log | tail -20

# Should see ~75ms average
```

### 5. Database Verification

```bash
php artisan tinker

# Check policies registered
Gate::policies();

# Check global scopes active
User::first(); // Should automatically filter by school_id

# Check rate limiting
Cache::get('login:127.0.0.1'); // Should show attempts
```

---

## 🔄 Rollback Procedures

### Backend Rollback

```bash
# 1. Switch to previous version
git checkout previous-tag

# 2. Rollback migrations (if needed)
php artisan migrate:rollback --step=1

# 3. Clear caches
php artisan config:clear
php artisan cache:clear

# 4. Reinstall dependencies
composer install --no-dev

# 5. Recache
php artisan config:cache
php artisan route:cache

# 6. Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

### Mobile Rollback

#### iOS
```
1. Go to App Store Connect
2. Select previous version
3. Submit for review
4. Or: Keep old version in TestFlight
```

#### Android
```
1. Go to Play Console
2. Create new release with previous APK/AAB
3. Or: Rollback to previous release (Play Console feature)
```

### Database Rollback

```bash
# If migrations were run
php artisan migrate:rollback

# If data was modified
# Restore from backup
mysql -u user -p absensi_pro < backup.sql
```

---

## 📊 Deployment Checklist

### Pre-Deployment

- [ ] All tests pass (59 tests)
- [ ] Code reviewed
- [ ] Environment configured
- [ ] Database backup created
- [ ] Rollback plan ready

### Backend Deployment

- [ ] Code deployed
- [ ] Dependencies installed
- [ ] Migrations run
- [ ] Caches cleared and rebuilt
- [ ] Permissions set
- [ ] Web server configured
- [ ] SSL certificate valid
- [ ] Queue workers running
- [ ] Scheduler configured

### Mobile Deployment

- [ ] Version incremented
- [ ] Environment configured
- [ ] iOS build uploaded
- [ ] Android build uploaded
- [ ] TestFlight/Internal testing complete

### Post-Deployment

- [ ] Smoke tests pass
- [ ] Logs monitored (no errors)
- [ ] Security checks pass
- [ ] Performance acceptable
- [ ] Users can login
- [ ] QR scanning works
- [ ] Rate limiting active
- [ ] Monitoring configured
- [ ] Team notified

---

## 🆘 Troubleshooting

### Issue: 500 Server Error

```bash
# Check logs
tail -f storage/logs/laravel.log

# Common causes:
# - Permission issues
# - Missing .env
# - Database connection failed
# - Cache issues

# Solutions:
chmod -R 775 storage bootstrap/cache
php artisan config:clear
php artisan cache:clear
```

### Issue: Rate Limiting Not Working

```bash
# Check Redis connection
redis-cli ping
# Expected: PONG

# Check cache driver
php artisan tinker
Cache::get('test');

# Verify middleware registered
php artisan route:list | grep rate.limit
```

### Issue: Mobile App Can't Connect

```bash
# Check API URL in .env
cat .env | grep API_URL

# Test API from mobile network
curl https://api.school.com/api/v1/health

# Check CORS settings
# Verify FRONTEND_URL and MOBILE_APP_URL in backend .env
```

### Issue: Tokens Not Persisting (Mobile)

```bash
# Check keychain installation
npm list react-native-keychain

# Reinstall if needed
npm install react-native-keychain
cd ios && pod install

# Check logs for errors
# Should see "Tokens stored securely"
# Should NOT see actual token values
```

---

## 📚 Additional Resources

- **Backend Deployment:** Laravel Deployment Docs
- **Mobile Deployment:** React Native Deployment Docs
- **Server Setup:** DigitalOcean/AWS Guides
- **SSL Certificates:** Let's Encrypt/Certbot

---

## 🎉 Deployment Complete!

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│  ✅ DEPLOYMENT SUCCESSFUL                           │
│                                                      │
│  Backend:  https://api.school.com ✅                │
│  Mobile:   App Store / Play Store ✅                │
│  Security: All layers active ✅                     │
│  Tests:    All passing ✅                           │
│  Monitoring: Active ✅                              │
│                                                      │
│  YOUR SYSTEM IS NOW LIVE & SECURE! 🚀              │
│                                                      │
└──────────────────────────────────────────────────────┘
```

**Next Steps:**
1. Monitor logs for 24-48 hours
2. Gather user feedback
3. Plan next iteration

---

**Last Updated:** January 28, 2026  
**Maintained by:** DevOps Team  
**For Support:** See main documentation

**Happy Deploying! 🚀**
