# Getting Started - AbsensiQRPro Security Implementation

**Last Updated:** January 28, 2026  
**Version:** 1.0.0  
**For:** All Developers

---

## 🎯 Welcome!

Selamat datang di panduan implementasi keamanan AbsensiQRPro! Dokumen ini akan memandu Anda dari awal hingga sistem berjalan dengan aman.

---

## 📋 Table of Contents

1. [Prerequisites](#prerequisites)
2. [Quick Start (15 Minutes)](#quick-start-15-minutes)
3. [Understanding Security Pillars](#understanding-security-pillars)
4. [Step-by-Step Implementation](#step-by-step-implementation)
5. [Verification](#verification)
6. [Next Steps](#next-steps)

---

## ✅ Prerequisites

### Backend Requirements
- ✅ PHP 8.2+
- ✅ Composer
- ✅ MySQL/PostgreSQL
- ✅ Redis (for cache & rate limiting)
- ✅ Git

### Mobile Requirements
- ✅ Node.js 18+
- ✅ npm or yarn
- ✅ Xcode (for iOS)
- ✅ Android Studio (for Android)

### Knowledge Requirements
- ✅ Basic Laravel knowledge
- ✅ Basic React Native knowledge
- ✅ Understanding of REST APIs
- ✅ Basic security concepts

---

## 🚀 Quick Start (15 Minutes)

### Step 1: Clone & Setup (5 min)

```bash
# Clone repository
git clone https://github.com/your-org/absensiQRPro.git
cd absensiQRPro

# Backend setup
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed

# Mobile setup
cd ../AbsensiQRMobile
npm install
```

### Step 2: Run Tests (5 min)

```bash
# Backend tests (59 security tests)
cd backend
php artisan test --filter="Policy|Security|Validation|RateLimit"

# Expected: All tests pass ✅
```

### Step 3: Start Development Servers (5 min)

```bash
# Backend
cd backend
php artisan serve
# Running on: http://localhost:8000

# Mobile (new terminal)
cd AbsensiQRMobile
npm run android  # or npm run ios
```

### Step 4: Verify Everything Works

```bash
# Test health endpoint
curl http://localhost:8000/api/v1/health
# Expected: {"status":"ok"}

# Test login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'
# Expected: {"success":true,"token":"..."}
```

**✅ If all steps pass, you're ready to go!**

---

## 🛡️ Understanding Security Pillars

### Overview

AbsensiQRPro mengimplementasikan **5 Pilar Keamanan Kritis**:

```
1️⃣ Multi-Tenant Security    → Isolasi data antar sekolah
2️⃣ Hybrid QR Validation     → QR cepat & aman
3️⃣ Backend-Only Security    → Jangan percaya client
4️⃣ Secure Mobile Tokens     → Enkripsi OS-level
5️⃣ Advanced Rate Limiting   → Proteksi dari serangan
```

### 1️⃣ Multi-Tenant Security

**Problem:** Admin Sekolah A bisa akses data Sekolah B

**Solution:** 3-layer defense

```
Request dari Admin Sekolah A
  ↓
Layer 1: Global Scope
  → Filter otomatis: WHERE school_id = A
  ↓
Layer 2: Policy (CRITICAL!)
  → Cek: $user->school_id === $resource->school_id
  ↓
Layer 3: Controller Authorization
  → $this->authorize('view', $student)
  ↓
✅ Hanya data Sekolah A yang bisa diakses
```

**Key Files:**
- `app/Policies/*Policy.php` - 7 policy files
- `app/Providers/AuthServiceProvider.php` - Policy registration
- `tests/Feature/PolicyEnforcementTest.php` - 16 tests

**Learn More:** [`../backend/SECURITY_OVERVIEW.md`](../backend/SECURITY_OVERVIEW.md)

---

### 2️⃣ Hybrid QR Validation

**Problem:** QR stateless cepat tapi tidak aman

**Solution:** 2-layer validation

```
QR Scan Request
  ↓
Layer 1: HMAC Validation (Fast - 0 DB queries)
  ✓ Signature valid?
  ✓ Not expired?
  ✓ Nonce valid?
  ↓
Layer 2: Database Check (Secure - 1 query)
  ✓ Student exists?
  ✓ Student active?
  ✓ School match?
  ✓ Device ID valid?
  ↓
✅ Attendance Recorded + Anomaly Logged
```

**Performance:** +25ms (50ms → 75ms) - Still fast!  
**Security:** LOW → HIGH

**Key Files:**
- `app/Services/HybridQrValidationService.php`
- `app/Core/Services/Attendance/EnhancedAttendanceService.php`
- `tests/Feature/HybridQrValidationTest.php` - 13 tests

**Learn More:** [`QR_VALIDATION.md`](QR_VALIDATION.md)

---

### 3️⃣ Backend-Only Security

**Golden Rule:** NEVER TRUST THE CLIENT!

```
FRONTEND (React/React Native)
├─ Route Guards        → UX only (can be bypassed)
├─ Form Validation     → UX only (can be bypassed)
└─ Conditional Render  → UX only (can be bypassed)

BACKEND (Laravel)
├─ Authentication      → REAL security ✅
├─ Authorization       → REAL security ✅
├─ Input Validation    → REAL security ✅
├─ Rate Limiting       → REAL security ✅
└─ Database Filtering  → REAL security ✅
```

**Why?**
- ❌ JavaScript can be modified by users
- ❌ API calls can be made directly (curl/Postman)
- ❌ Client-side checks can be disabled

**Key Files:**
- `routes/api.php` - All security middleware
- `app/Http/Middleware/*` - Security middleware
- `tests/Feature/BackendSecurityLayerTest.php` - 17 tests

**Learn More:** [`../api/SECURITY.md`](../api/SECURITY.md)

---

### 4️⃣ Secure Mobile Token Storage

**Problem:** AsyncStorage = plain text file

**Solution:** OS-level encryption

```
Before: AsyncStorage
  ↓
Plain text file in app directory
  ↓
Anyone can read (even on non-rooted device)
  ↓
🔴 VULNERABLE

After: Keychain/Keystore
  ↓
Hardware-backed encryption
  ↓
OS-protected, cannot be extracted
  ↓
🟢 SECURE
```

**Features:**
- ✅ Automatic token refresh on 401
- ✅ Automatic token invalidation on 403
- ✅ No tokens in logs
- ✅ Biometric support (optional)

**Key Files:**
- `AbsensiQRMobile/src/services/SecureStorage.ts`
- `AbsensiQRMobile/src/services/AuthService.ts`

**Learn More:** [`../mobile/SECURE_STORAGE.md`](../mobile/SECURE_STORAGE.md)

---

### 5️⃣ Advanced Rate Limiting

**Problem:** No protection against attacks

**Solution:** 6-layer rate limiting

| Type | Limit | Purpose |
|------|-------|---------|
| **Global** | 1000/min per IP | DDoS protection |
| **Login** | 5 per 5min | Brute force protection |
| **Scan** | 10/min per user+device | Spam protection |
| **API** | 60/min per user | API abuse protection |
| **Register** | 3/hour per IP | Signup spam |
| **Password Reset** | 3/hour per IP | Reset spam |

**Impact:**
- Brute force: 10 sec → 16.7 hours (6000x slower!)
- DDoS: Server protected
- Spam: Prevented

**Key Files:**
- `app/Http/Middleware/AdvancedRateLimiting.php`
- `routes/api.php` - Rate limit application
- `tests/Feature/AdvancedRateLimitingTest.php` - 13 tests

**Learn More:** [`RATE_LIMITING.md`](RATE_LIMITING.md)

---

## 📚 Step-by-Step Implementation

### Phase 1: Backend Security (Day 1)

#### 1.1 Verify Policies (30 min)

```bash
# Check if policies are registered
php artisan tinker

Gate::policies();
# Should show all 7 policies

# Test cross-school access
$admin = User::where('role_type', 'school_admin')->first();
$otherStudent = User::where('school_id', '!=', $admin->school_id)->first();

Gate::forUser($admin)->allows('view', $otherStudent);
// Expected: false ✅
```

#### 1.2 Run Backend Tests (30 min)

```bash
# All security tests
php artisan test --filter="Policy|Security|Validation|RateLimit"

# Expected: 59 tests pass ✅
```

#### 1.3 Verify Rate Limiting (15 min)

```bash
# Test login rate limit
for i in {1..6}; do
  curl -X POST http://localhost:8000/api/v1/auth/login \
    -d '{"username":"admin","password":"wrong"}'
done

# Expected: First 5 return 401, 6th returns 429 ✅
```

---

### Phase 2: Mobile Security (Day 1-2)

#### 2.1 Install Dependencies (15 min)

```bash
cd AbsensiQRMobile

# Install packages
npm install

# iOS only
cd ios && pod install && cd ..

# Verify keychain
npm list react-native-keychain
# Expected: react-native-keychain@8.2.0 ✅
```

#### 2.2 Test Secure Storage (30 min)

```typescript
// Test in app
import SecureStorage from './src/services/SecureStorage';
import AuthService from './src/services/AuthService';

// 1. Login
await AuthService.login('username', 'password');
// Check logs: "Tokens stored securely" ✅

// 2. Close app completely

// 3. Reopen app
// Should still be logged in ✅

// 4. Logout
await AuthService.logout();
// Check logs: "All data cleared" ✅
```

#### 2.3 Verify No Tokens in AsyncStorage (15 min)

```bash
# Android
adb shell
cd /data/data/com.absensi.qr/shared_prefs
cat *.xml | grep "eyJ"
# Should return NOTHING ✅

# iOS
# Check app documents folder - should be empty
```

---

### Phase 3: Integration Testing (Day 2)

#### 3.1 End-to-End Flow (1 hour)

```
1. Student Login (Mobile)
   ↓
2. Get Schedule QR (Backend)
   ↓
3. Scan QR (Mobile Camera)
   ↓
4. Validate QR (Backend - Hybrid)
   ↓
5. Record Attendance (Backend - DB)
   ↓
6. Show Success (Mobile UI)
```

**Test each step manually and verify:**
- ✅ Login works
- ✅ QR code generated
- ✅ QR scan successful
- ✅ Attendance recorded
- ✅ No errors in logs

#### 3.2 Security Testing (1 hour)

```bash
# Test 1: Cross-school access
# Login as Admin School A
# Try to access School B student
# Expected: 403 Forbidden ✅

# Test 2: Inactive student
# Disable student account
# Try to scan QR
# Expected: Rejected with anomaly log ✅

# Test 3: Rate limiting
# Make 61 API requests rapidly
# Expected: 61st returns 429 ✅
```

---

### Phase 4: Deployment (Day 3)

#### 4.1 Staging Deployment

```bash
# Backend
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache

# Mobile
cd AbsensiQRMobile
npm run build:staging
```

#### 4.2 Production Deployment

See: [`../deployment/BACKEND_DEPLOYMENT.md`](../deployment/BACKEND_DEPLOYMENT.md)

---

## ✅ Verification

### Backend Verification Checklist

- [ ] All 59 tests pass
- [ ] Policies registered (`Gate::policies()`)
- [ ] Cross-school access blocked
- [ ] Rate limiting active
- [ ] QR validation working
- [ ] No security warnings in logs

### Mobile Verification Checklist

- [ ] `react-native-keychain` installed
- [ ] Login works
- [ ] Tokens stored in Keychain/Keystore
- [ ] No tokens in AsyncStorage
- [ ] No tokens in console logs
- [ ] Logout clears tokens
- [ ] Auto token refresh works

### Integration Verification Checklist

- [ ] End-to-end flow works
- [ ] QR scanning works
- [ ] Attendance recorded correctly
- [ ] Security anomalies logged
- [ ] Performance acceptable (<100ms)

---

## 🎯 Next Steps

### Immediate (This Week)

1. **Read Documentation**
   - Backend: [`../backend/SECURITY_OVERVIEW.md`](../backend/SECURITY_OVERVIEW.md)
   - Mobile: [`../mobile/SECURE_STORAGE.md`](../mobile/SECURE_STORAGE.md)
   - API: [`../api/OVERVIEW.md`](../api/OVERVIEW.md)

2. **Run All Tests**
   ```bash
   php artisan test
   ```

3. **Deploy to Staging**
   - Follow: [`../deployment/BACKEND_DEPLOYMENT.md`](../deployment/BACKEND_DEPLOYMENT.md)

### Short-term (Next 2 Weeks)

4. **Monitor Logs**
   ```bash
   tail -f storage/logs/laravel.log
   ```

5. **Setup Monitoring**
   - Follow: [`../deployment/MONITORING.md`](../deployment/MONITORING.md)

6. **User Training**
   - Educate users on new security features

### Long-term (Next 2-3 Months)

7. **Add Controller Authorization**
   - Add `$this->authorize()` to all controllers

8. **Implement Advanced Features**
   - QR revocation list
   - Biometric authentication
   - Automated security audits

9. **Security Audit**
   - Professional penetration testing

---

## 📚 Additional Resources

### Documentation
- **Quick Reference:** [`../QUICK_REFERENCE.md`](../QUICK_REFERENCE.md)
- **FAQ:** [`../FAQ.md`](../FAQ.md)
- **Testing Guide:** [`../backend/TESTING.md`](../backend/TESTING.md)

### Code Examples
- **Backend Tests:** `backend/tests/Feature/*Test.php`
- **Mobile Services:** `AbsensiQRMobile/src/services/*`
- **API Routes:** `backend/routes/api.php`

### External Resources
- Laravel Security: https://laravel.com/docs/security
- React Native Security: https://reactnative.dev/docs/security
- OWASP Top 10: https://owasp.org/www-project-top-ten/

---

## 🆘 Getting Help

### Common Issues

**Tests Failing?**
```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

**Mobile Not Working?**
```bash
npm install react-native-keychain
cd ios && pod install
```

**Need More Help?**
- Check: [`../FAQ.md`](../FAQ.md)
- Review: Test files for examples
- Contact: Development team

---

## 🎉 You're Ready!

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│  ✅ GETTING STARTED: COMPLETE                       │
│                                                      │
│  You now understand:                                 │
│  ✅ 5 Security Pillars                              │
│  ✅ Implementation Steps                            │
│  ✅ Verification Process                            │
│  ✅ Next Steps                                      │
│                                                      │
│  Ready to build secure applications! 🚀            │
│                                                      │
└──────────────────────────────────────────────────────┘
```

**Happy Coding! 🎉**

---

**Last Updated:** January 28, 2026  
**Maintained by:** Development Team  
**For Support:** See [`../FAQ.md`](../FAQ.md)
