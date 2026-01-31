# Testing Guide - Security Implementation

**Last Updated:** January 28, 2026  
**Version:** 1.0.0  
**For:** Developers & QA Engineers

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Backend Testing](#backend-testing)
3. [Mobile Testing](#mobile-testing)
4. [Integration Testing](#integration-testing)
5. [Security Testing](#security-testing)
6. [Performance Testing](#performance-testing)

---

## 🎯 Overview

### Test Coverage Summary

```
Backend Security Tests:    59 tests ✅
Mobile Tests:              Manual verification
Integration Tests:         Cross-platform flows
Security Tests:            Attack simulations
Performance Tests:         Load & stress testing
```

### Testing Environments

- **Local:** Development machine
- **Staging:** Production-like environment
- **Production:** Live system (limited testing)

---

## 🧪 Backend Testing

### 1. Setup Test Environment

```bash
cd backend

# Install dependencies
composer install

# Setup test database
cp .env .env.testing
# Edit .env.testing:
# DB_DATABASE=absensi_test
# CACHE_DRIVER=array
# QUEUE_CONNECTION=sync

# Run migrations
php artisan migrate --env=testing

# Seed test data (optional)
php artisan db:seed --env=testing
```

### 2. Run All Security Tests

```bash
# All security tests (59 tests)
php artisan test --filter="Policy|Security|Validation|RateLimit"

# Expected output:
# Tests:  59 passed
# Time:   ~30-60s
```

### 3. Individual Test Suites

#### Multi-Tenant Security (16 tests)

```bash
# Policy enforcement (9 tests)
php artisan test --filter=PolicyEnforcementTest

# Cross-tenant access (7 tests)
php artisan test --filter=CrossTenantAccessTest
```

**What's Tested:**
- ✅ User policy enforcement
- ✅ Class policy enforcement
- ✅ Schedule policy enforcement
- ✅ Attendance policy enforcement
- ✅ Cross-school access prevention
- ✅ Policy with global scope bypass
- ✅ Super admin access

**Expected Results:**
```
✓ user policy blocks cross school access
✓ class policy blocks cross school access
✓ schedule policy blocks cross school access
✓ attendance policy blocks cross school access
✓ cross school student access blocked
✓ cross school class access blocked
✓ super admin can access all schools
```

#### Hybrid QR Validation (13 tests)

```bash
php artisan test --filter=HybridQrValidationTest
```

**What's Tested:**
- ✅ Active student validation
- ✅ Inactive student rejection
- ✅ Transferred student prevention
- ✅ Cross-school scan prevention
- ✅ Deleted student handling
- ✅ Device ID validation
- ✅ HMAC tampering detection
- ✅ Security anomaly logging

**Expected Results:**
```
✓ active student with valid qr passes validation
✓ inactive student is rejected
✓ transferred student qr is invalidated
✓ cross school scan is prevented
✓ deleted student is handled
✓ device id is validated
✓ hmac tampering is detected
✓ security anomalies are logged
```

#### Backend Security (17 tests)

```bash
php artisan test --filter=BackendSecurityLayerTest
```

**What's Tested:**
- ✅ Unauthenticated request rejection
- ✅ Wrong role blocking
- ✅ Cross-school access prevention
- ✅ Inactive user blocking
- ✅ Expired token rejection
- ✅ Rate limiting enforcement
- ✅ SQL injection prevention
- ✅ XSS prevention
- ✅ Security headers

**Expected Results:**
```
✓ unauthenticated request is rejected
✓ student cannot access admin endpoint
✓ teacher cannot access super admin endpoint
✓ admin from school a cannot access school b data
✓ inactive user cannot access api
✓ expired token is rejected
✓ rate limiting blocks excessive requests
✓ sql injection is prevented
✓ xss payload is escaped
✓ security headers are present
```

#### Advanced Rate Limiting (13 tests)

```bash
php artisan test --filter=AdvancedRateLimitingTest
```

**What's Tested:**
- ✅ Login rate limit (5 attempts)
- ✅ QR scan rate limit (10 scans)
- ✅ API rate limit (60 requests)
- ✅ Per-device isolation
- ✅ Per-user isolation
- ✅ Rate limit headers
- ✅ Retry-After responses

**Expected Results:**
```
✓ login rate limit blocks after 5 attempts
✓ qr scan rate limit blocks after 10 scans
✓ api rate limit blocks after 60 requests
✓ qr scan rate limit is per device
✓ different users have separate rate limits
✓ rate limit headers are present
✓ rate limit response includes retry after
```

### 4. Run Specific Tests

```bash
# Run single test
php artisan test --filter=test_user_policy_blocks_cross_school_access

# Run tests in specific file
php artisan test tests/Feature/PolicyEnforcementTest.php

# Run with coverage (requires xdebug)
php artisan test --coverage
```

### 5. Debugging Failed Tests

```bash
# Run with verbose output
php artisan test --filter=PolicyEnforcementTest -v

# Stop on first failure
php artisan test --stop-on-failure

# Show detailed error messages
php artisan test --testdox
```

---

## 📱 Mobile Testing

### 1. Setup Mobile Test Environment

```bash
cd AbsensiQRMobile

# Install dependencies
npm install

# iOS only
cd ios && pod install && cd ..

# Verify keychain installation
npm list react-native-keychain
# Expected: react-native-keychain@8.2.0
```

### 2. Manual Testing Checklist

#### Secure Token Storage

- [ ] **Login Test**
  ```
  1. Login with valid credentials
  2. Check logs: "Tokens stored securely" ✅
  3. Verify NO actual token values in logs ✅
  ```

- [ ] **Token Persistence**
  ```
  1. Login successfully
  2. Close app completely
  3. Reopen app
  4. Should still be logged in ✅
  ```

- [ ] **Logout Test**
  ```
  1. Logout
  2. Check logs: "All data cleared" ✅
  3. Try to access protected endpoint
  4. Should fail with 401 ✅
  ```

#### Automatic Token Refresh

- [ ] **Token Refresh on 401**
  ```
  1. Wait for token to expire (or manually expire)
  2. Make API request
  3. Should automatically refresh ✅
  4. Request should succeed ✅
  ```

- [ ] **Token Invalidation on 403**
  ```
  1. Simulate user disabled (backend)
  2. Make API request
  3. Should receive 403 ✅
  4. Tokens should be cleared ✅
  5. Should redirect to login ✅
  ```

#### Security Verification

- [ ] **No Tokens in AsyncStorage**
  ```bash
  # iOS
  # Check app documents folder - should be empty
  
  # Android
  adb shell
  cd /data/data/com.absensi.qr/shared_prefs
  cat *.xml | grep "eyJ"
  # Should return NOTHING ✅
  ```

- [ ] **No Tokens in Logs**
  ```
  1. Login
  2. Check all console logs
  3. Should NOT see actual token values ✅
  4. Should only see "Token stored" messages ✅
  ```

### 3. Device Testing Matrix

| Device | OS | Test Login | Test Logout | Test Refresh | Test Keychain |
|--------|----|-----------:|------------:|-------------:|--------------:|
| iPhone 13 | iOS 16 | ✅ | ✅ | ✅ | ✅ |
| iPhone 11 | iOS 15 | ✅ | ✅ | ✅ | ✅ |
| Samsung S21 | Android 12 | ✅ | ✅ | ✅ | ✅ |
| Pixel 6 | Android 13 | ✅ | ✅ | ✅ | ✅ |

### 4. Automated Mobile Tests (Optional)

```bash
# Using Detox (if configured)
npm run test:e2e

# Using Jest for unit tests
npm test
```

---

## 🔗 Integration Testing

### 1. End-to-End Flow Testing

#### Student Attendance Flow

```
1. Student Login (Mobile)
   ↓
2. Get QR Code (Backend generates)
   ↓
3. Scan QR Code (Mobile camera)
   ↓
4. Validate QR (Backend - Hybrid validation)
   ↓
5. Record Attendance (Backend - Database)
   ↓
6. Show Success (Mobile UI)
```

**Test Steps:**
```bash
# 1. Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"student1","password":"password"}'

# Save token from response

# 2. Get schedule QR
curl http://localhost:8000/api/v1/schedules/today \
  -H "Authorization: Bearer {TOKEN}"

# 3. Scan QR (simulate)
curl -X POST http://localhost:8000/api/v1/attendance/scan \
  -H "Authorization: Bearer {TOKEN}" \
  -H "X-Device-ID: test_device" \
  -d '{"qr_token":"{QR_TOKEN}","latitude":-6.2,"longitude":106.8}'

# Expected: 200 OK with attendance record
```

#### Teacher Manual Attendance Flow

```
1. Teacher Login
   ↓
2. View Class List
   ↓
3. Mark Student Present/Absent
   ↓
4. Submit Attendance
   ↓
5. Verify Recorded
```

#### Admin Report Generation Flow

```
1. Admin Login
   ↓
2. Select Date Range
   ↓
3. Select Class/Student
   ↓
4. Generate Report
   ↓
5. Download/View Report
```

### 2. Cross-Platform Testing

Test same flow across:
- ✅ Web Frontend (React)
- ✅ Mobile App (React Native)
- ✅ Direct API (curl/Postman)

All should have same security enforcement!

---

## 🔒 Security Testing

### 1. Attack Simulation Tests

#### Test 1: Brute Force Login Attack

```bash
# Simulate brute force attack
for i in {1..10}; do
  curl -X POST http://localhost:8000/api/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"username":"admin","password":"wrong'$i'"}'
  echo "Attempt $i"
done

# Expected:
# Attempts 1-5: 401 Unauthorized
# Attempts 6+: 429 Too Many Requests ✅
```

#### Test 2: Cross-School Access Attack

```bash
# Login as Admin School A
ADMIN_A_TOKEN="..."

# Try to access School B student
curl http://localhost:8000/api/v1/admin/students/{SCHOOL_B_STUDENT_ID} \
  -H "Authorization: Bearer $ADMIN_A_TOKEN"

# Expected: 403 Forbidden or 404 Not Found ✅
```

#### Test 3: QR Replay Attack

```bash
# Capture valid QR token
QR_TOKEN="..."

# Use it once
curl -X POST http://localhost:8000/api/v1/attendance/scan \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"qr_token":"'$QR_TOKEN'"}'

# Try to reuse same token
curl -X POST http://localhost:8000/api/v1/attendance/scan \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"qr_token":"'$QR_TOKEN'"}'

# Expected: Second request fails (nonce already used) ✅
```

#### Test 4: SQL Injection Attack

```bash
# Try SQL injection in search
curl "http://localhost:8000/api/v1/admin/students?search='; DROP TABLE users; --" \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Expected: No error, users table still exists ✅
```

#### Test 5: XSS Attack

```bash
# Try XSS in student name
curl -X PUT http://localhost:8000/api/v1/admin/students/123 \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"<script>alert(\"XSS\")</script>"}'

# Expected: Stored safely, escaped on output ✅
```

### 2. Penetration Testing Checklist

- [ ] **Authentication**
  - [ ] Brute force protection active
  - [ ] Token expiration working
  - [ ] Token refresh working
  - [ ] Logout clears tokens

- [ ] **Authorization**
  - [ ] Role-based access enforced
  - [ ] Cross-school access blocked
  - [ ] Policy checks working
  - [ ] Global scope filtering

- [ ] **Input Validation**
  - [ ] SQL injection prevented
  - [ ] XSS prevented
  - [ ] CSRF protection active
  - [ ] File upload validation

- [ ] **Rate Limiting**
  - [ ] Login rate limit active
  - [ ] API rate limit active
  - [ ] QR scan rate limit active
  - [ ] Global rate limit active

- [ ] **Data Protection**
  - [ ] Passwords hashed
  - [ ] Tokens encrypted (mobile)
  - [ ] Sensitive data not logged
  - [ ] HTTPS enforced

---

## ⚡ Performance Testing

### 1. Load Testing

```bash
# Install Apache Bench
# Ubuntu: sudo apt-get install apache2-utils
# Mac: brew install ab

# Test API endpoint (100 requests, 10 concurrent)
ab -n 100 -c 10 \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/auth/me

# Expected:
# Requests per second: >100
# Mean response time: <100ms
```

### 2. QR Scan Performance

```bash
# Test QR scan endpoint
ab -n 100 -c 10 \
  -p qr_scan.json \
  -T application/json \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/attendance/scan

# qr_scan.json:
# {"qr_token":"...","latitude":-6.2,"longitude":106.8}

# Expected:
# Mean response time: <100ms (75ms target)
```

### 3. Database Query Performance

```bash
# Check slow queries
php artisan tinker

# Test multi-tenant query
$students = User::where('role_type', 'student')->get();
// Should use index on school_id + role_type

# Test with explain
DB::enableQueryLog();
User::where('role_type', 'student')->get();
dd(DB::getQueryLog());
```

### 4. Mobile App Performance

**Metrics to Monitor:**
- App launch time: <2 seconds
- Login time: <1 second
- QR scan time: <500ms
- Token refresh time: <500ms (background)

---

## 📊 Test Reports

### Generate Test Coverage Report

```bash
cd backend

# Generate coverage report (requires xdebug)
php artisan test --coverage --coverage-html=coverage

# View report
open coverage/index.html
```

### CI/CD Integration

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          
      - name: Install Dependencies
        run: composer install
        
      - name: Run Tests
        run: php artisan test
        
      - name: Upload Coverage
        uses: codecov/codecov-action@v2
```

---

## ✅ Testing Checklist

### Before Deployment

- [ ] All 59 backend tests pass
- [ ] Mobile login/logout works
- [ ] Token storage verified (Keychain/Keystore)
- [ ] Cross-school access blocked
- [ ] Rate limiting active
- [ ] QR validation working
- [ ] Performance acceptable (<100ms)
- [ ] Security headers present
- [ ] No tokens in logs

### After Deployment (Staging)

- [ ] Smoke tests pass
- [ ] Integration tests pass
- [ ] Security tests pass
- [ ] Performance tests pass
- [ ] Mobile app works on real devices
- [ ] No errors in logs
- [ ] Monitoring active

### Production Verification

- [ ] Limited smoke tests
- [ ] Monitor error rates
- [ ] Monitor performance
- [ ] Check security logs
- [ ] Verify rate limiting
- [ ] User acceptance testing

---

## 🆘 Troubleshooting

### Tests Failing

**Issue:** Tests fail with "Class not found"
```bash
# Solution
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

**Issue:** Database errors in tests
```bash
# Solution
php artisan migrate:fresh --env=testing
php artisan db:seed --env=testing
```

**Issue:** Rate limit tests failing
```bash
# Solution
php artisan cache:clear
# Rate limiter uses cache, clear before testing
```

### Mobile Tests Failing

**Issue:** Keychain not working
```bash
# iOS Solution
cd ios && pod install && cd ..
npm run ios

# Android Solution
cd android && ./gradlew clean && cd ..
npm run android
```

**Issue:** Tokens not persisting
```bash
# Check if react-native-keychain installed
npm list react-native-keychain

# Reinstall if needed
npm install react-native-keychain
cd ios && pod install
```

---

## 📚 Additional Resources

- **Backend Tests:** `backend/tests/Feature/*Test.php`
- **Test Documentation:** `tests/README.md`
- **CI/CD Config:** `.github/workflows/tests.yml`
- **Coverage Reports:** `coverage/index.html`

---

**Last Updated:** January 28, 2026  
**Maintained by:** Development Team  
**For Support:** See main documentation

**Happy Testing! 🧪**
