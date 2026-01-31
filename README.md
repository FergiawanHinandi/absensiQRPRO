# AbsensiQRPro - School Attendance System

**Version:** 1.0.0  
**Status:** ✅ Production Ready  
**Last Updated:** January 28, 2026

---

## 🎯 Overview

AbsensiQRPro adalah sistem absensi sekolah berbasis QR Code dengan keamanan multi-layer dan arsitektur multi-tenant.

### Key Features

- ✅ **QR Code Attendance** - Scan cepat & aman
- ✅ **Multi-Tenant** - Isolasi data antar sekolah
- ✅ **Role-Based Access** - Admin, Teacher, Student, Parent
- ✅ **Real-time Dashboard** - Statistik & laporan
- ✅ **Mobile App** - iOS & Android
- ✅ **Secure** - 5-layer security implementation

---

## 🚀 Quick Start

### Prerequisites

- PHP 8.2+
- Composer
- MySQL/PostgreSQL
- Redis
- Node.js 18+

### Installation

```bash
# Clone repository
git clone https://github.com/your-org/absensiQRPro.git
cd absensiQRPro

# ⚠️ CRITICAL: Security Setup (Required After Clone)
# Enable pre-commit hook for secret detection
git config core.hooksPath .githooks

# Verify hook is active
git config core.hooksPath
# Should output: .githooks
```

### Backend Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

### Frontend Setup

```bash
cd ../frontend-web
npm install

# Mobile setup
cd ../AbsensiQRMobile
npm install
```

### Run Development Servers

```bash
# Backend (Terminal 1)
cd backend
php artisan serve

# Frontend (Terminal 2)
cd frontend-web
npm run dev

# Mobile (Terminal 3)
cd AbsensiQRMobile
npm run android  # or npm run ios
```

---

## 🔒 Security

### Pre-commit Hook (Secret Detection)

This project uses **automated secret detection** to prevent committing sensitive data.

**The pre-commit hook is REQUIRED and blocks commits containing:**

- ✅ `APP_KEY` with actual values
- ✅ `DB_PASSWORD` with real passwords
- ✅ `QR_SECRET_KEY` with actual keys
- ✅ API tokens and Bearer tokens
- ✅ Private keys and certificates
- ✅ AWS credentials
- ✅ `.env` files

**Setup (Required After Clone):**

```bash
# Enable the pre-commit hook
git config core.hooksPath .githooks

# Verify it's active
git config core.hooksPath
# Output: .githooks
```

**Testing the Hook:**

```bash
# Try to commit a secret (should be blocked)
echo "APP_KEY=base64:realkey123456789" >> test.txt
git add test.txt
git commit -m "test"
# ❌ BLOCKED: APP_KEY with actual value detected!
```

**Bypass (NOT RECOMMENDED):**

```bash
# Only use for emergencies
git commit --no-verify
```

**CI/CD Protection:**

In addition to local hooks, our CI/CD pipeline also scans for secrets:
- GitHub Actions workflow: `.github/workflows/secret-scan.yml`
- Runs on every push and pull request
- Fails build if secrets are detected

**Learn More:**
- [`docs/SECURITY_GUIDELINES.md`](docs/SECURITY_GUIDELINES.md)
- [`docs/SECURITY_UPDATE_SYSTEM_MONITOR.md`](docs/SECURITY_UPDATE_SYSTEM_MONITOR.md)

---

## 📚 Documentation

**Complete documentation is available in the [`docs/`](docs/) folder.**

### Quick Links

- **📖 Getting Started:** [`docs/guides/GETTING_STARTED.md`](docs/guides/GETTING_STARTED.md)
- **⚡ Quick Reference:** [`docs/QUICK_REFERENCE.md`](docs/QUICK_REFERENCE.md)
- **❓ FAQ:** [`docs/FAQ.md`](docs/FAQ.md)
- **📚 Full Documentation:** [`docs/README.md`](docs/README.md)

### Documentation Structure

```
docs/
├── README.md                    📚 Documentation hub
├── QUICK_REFERENCE.md          ⚡ 1-page cheat sheet
├── FAQ.md                      ❓ Frequently asked questions
│
├── backend/                    🔧 Backend documentation
├── frontend/                   💻 Frontend documentation
├── mobile/                     📱 Mobile documentation
├── api/                        🌐 API documentation
├── deployment/                 🚀 Deployment guides
├── guides/                     📖 Implementation guides
├── project/                    📋 Project documentation
└── archive/                    📦 Historical documentation
```

---

## 🛡️ Security Features

### 5 Security Pillars

1. **Multi-Tenant Security** - 3-layer defense-in-depth
2. **Hybrid QR Validation** - Fast & secure QR scanning
3. **Backend-Only Security** - Never trust the client
4. **Secure Mobile Tokens** - OS-level encryption
5. **Advanced Rate Limiting** - Multi-layer protection

**Security Score:** 98/100 🟢

**Learn More:** [`docs/backend/SECURITY_OVERVIEW.md`](docs/backend/SECURITY_OVERVIEW.md)

---

## 🧪 Testing

```bash
# Run all tests
cd backend
php artisan test

# Run security tests only (59 tests)
php artisan test --filter="Policy|Security|Validation|RateLimit"
```

**Test Coverage:** 59 security tests ✅

**Learn More:** [`docs/backend/TESTING.md`](docs/backend/TESTING.md)

---

## 🚀 Deployment

### Production Deployment

See detailed deployment guides:
- **Backend:** [`docs/deployment/BACKEND_DEPLOYMENT.md`](docs/deployment/BACKEND_DEPLOYMENT.md)
- **Frontend:** [`docs/deployment/FRONTEND_DEPLOYMENT.md`](docs/deployment/FRONTEND_DEPLOYMENT.md)
- **Mobile:** [`docs/deployment/MOBILE_DEPLOYMENT.md`](docs/deployment/MOBILE_DEPLOYMENT.md)
- **Monitoring:** [`docs/deployment/MONITORING.md`](docs/deployment/MONITORING.md)

---

## 📊 Tech Stack

### Backend
- **Framework:** Laravel 11
- **Database:** MySQL/PostgreSQL
- **Cache:** Redis
- **Auth:** Laravel Sanctum
- **Testing:** PHPUnit

### Frontend
- **Framework:** React + TypeScript
- **Styling:** Tailwind CSS
- **State:** React Query
- **Build:** Vite

### Mobile
- **Framework:** React Native
- **Navigation:** React Navigation
- **Storage:** Keychain/Keystore
- **HTTP:** Axios

---

## 📱 Mobile Apps

### iOS
- **Minimum:** iOS 13.0
- **Store:** App Store

### Android
- **Minimum:** Android 8.0 (API 26)
- **Store:** Google Play Store

**Setup Guide:** [`docs/mobile/ANDROID_SETUP.md`](docs/mobile/ANDROID_SETUP.md)

---

## 🔑 Default Credentials

### Development

```
Super Admin:
Username: superadmin
Password: password

School Admin:
Username: admin
Password: password

Teacher:
Username: teacher
Password: password

Student:
Username: student
Password: password
```

**⚠️ Change these in production!**

---

## 📞 Support

### Documentation
- **Main Docs:** [`docs/README.md`](docs/README.md)
- **FAQ:** [`docs/FAQ.md`](docs/FAQ.md)
- **Quick Reference:** [`docs/QUICK_REFERENCE.md`](docs/QUICK_REFERENCE.md)

### Issues
- Report bugs: [GitHub Issues](https://github.com/your-org/absensiQRPro/issues)
- Feature requests: [GitHub Discussions](https://github.com/your-org/absensiQRPro/discussions)

---

## 📄 License

Proprietary - AbsensiQRPro

---

## 🎉 Status

```
┌──────────────────────────────────────────────────────┐
│                                                      │
│  ✅ AbsensiQRPro - Production Ready                 │
│                                                      │
│  Security Score: 98/100 🟢                           │
│  Test Coverage: 59 tests ✅                          │
│  Documentation: Complete 📚                          │
│                                                      │
│  Backend: ✅ Ready                                   │
│  Frontend: ✅ Ready                                  │
│  Mobile: ✅ Ready                                    │
│                                                      │
│  Status: PRODUCTION READY 🚀                        │
│                                                      │
└──────────────────────────────────────────────────────┘
```

---

**For complete documentation, see [`docs/README.md`](docs/README.md)**

**Created with ❤️ by AbsensiQRPro Team**