# absensiQRPRO - Installation Guide

## System Requirements

### Website
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- PDO MySQL extension enabled

### Mobile App
- Node.js 16 or higher
- React Native CLI
- Android Studio (for Android development)
- Xcode (for iOS development on macOS)

## Installation Steps

### 1. Database Setup

1. Create MySQL database:
```sql
CREATE DATABASE absensi_qr_pro;
```

2. Import database schema:
```bash
mysql -u root -p absensi_qr_pro < website/backend/database/schema.sql
```

3. Update database credentials in `website/backend/config/database.php`:
```php
private $host = "localhost";
private $db_name = "absensi_qr_pro";
private $username = "root";
private $password = "your_password";
```

### 2. Website Setup

1. Copy the `website` folder to your web server directory:
```bash
# For Apache (Linux)
sudo cp -r website /var/www/html/absensiQRPRO

# For XAMPP (Windows)
copy website C:\xampp\htdocs\absensiQRPRO
```

2. Set proper permissions (Linux only):
```bash
sudo chown -R www-data:www-data /var/www/html/absensiQRPRO
sudo chmod -R 755 /var/www/html/absensiQRPRO
```

3. Access the website:
```
http://localhost/absensiQRPRO/website/frontend/index.html
```

### 3. Mobile App Setup

1. Navigate to mobile app directory:
```bash
cd mobile-app
```

2. Install dependencies:
```bash
npm install
```

3. Update API URL in `mobile-app/src/api.js`:
```javascript
const API_BASE_URL = 'http://YOUR_SERVER_IP/absensiQRPRO/website/backend/api';
```

4. For Android development:
```bash
# Run on emulator
npm run android

# Or build APK
cd android
./gradlew assembleRelease
```

5. For iOS development (macOS only):
```bash
cd ios
pod install
cd ..
npm run ios
```

## Default Credentials

### Teachers (for mobile app login):
- **Email:** budi@school.com
- **Password:** teacher123

OR

- **Email:** siti@school.com
- **Password:** teacher123

### Sample Students with QR Codes:
- NIS001 - Ahmad Fauzi (10-A) - QR: QR-NIS001-2024
- NIS002 - Dewi Lestari (10-A) - QR: QR-NIS002-2024
- NIS003 - Eko Prasetyo (10-B) - QR: QR-NIS003-2024
- NIS004 - Fitri Handayani (10-B) - QR: QR-NIS004-2024
- NIS005 - Galih Saputra (11-A) - QR: QR-NIS005-2024

## Testing the System

### 1. Test Website
1. Open website in browser
2. Navigate to "Data Siswa" page
3. Click "Tampilkan QR" to generate QR codes
4. Check the dashboard for statistics

### 2. Test Mobile App
1. Open mobile app
2. Login with teacher credentials
3. Navigate to "Scan QR Code"
4. Scan a student QR code from the website
5. Confirm attendance
6. Check "Riwayat Absensi" to view records

## Troubleshooting

### Website Issues

**Problem:** Database connection error
- **Solution:** Check database credentials in `config/database.php`

**Problem:** CORS errors in mobile app
- **Solution:** Verify CORS headers are set in `config/cors.php`

**Problem:** Cannot access API endpoints
- **Solution:** Ensure mod_rewrite is enabled in Apache

### Mobile App Issues

**Problem:** Cannot connect to API
- **Solution:** 
  - Verify API URL in `src/api.js`
  - Use actual IP address, not localhost
  - Ensure server is accessible from mobile device

**Problem:** Camera permission denied
- **Solution:** Enable camera permission in device settings

**Problem:** QR scanner not working
- **Solution:** Ensure react-native-camera is properly linked

## Production Deployment

### Security Recommendations

1. **Change default passwords:**
```sql
UPDATE teachers SET password = PASSWORD('new_secure_password') WHERE email = 'budi@school.com';
```

2. **Enable HTTPS:**
- Obtain SSL certificate
- Configure web server for HTTPS

3. **Secure API endpoints:**
- Implement JWT authentication
- Add rate limiting
- Validate all inputs

4. **Database security:**
- Use strong passwords
- Restrict database access
- Regular backups

### Performance Optimization

1. **Enable caching** in web server
2. **Optimize database** indexes
3. **Compress assets** (CSS, JS)
4. **Use CDN** for static files

## Support

For issues or questions, please refer to the documentation in the `docs/` folder or create an issue on the repository.
