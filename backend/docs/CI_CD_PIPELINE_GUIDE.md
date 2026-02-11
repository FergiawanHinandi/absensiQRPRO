# CI/CD Pipeline Documentation
**AbsensiQRPro - Pipeline & Testing Guide**  
**Date:** February 7, 2026

---

## 1. Pipeline Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     CI/CD Pipeline Flow                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Push/PR                                                                 │
│      │                                                                   │
│      ▼                                                                   │
│  ┌──────────────────────┐                                               │
│  │  Security Audit      │  ← npm audit (HIGH+), composer audit          │
│  │  (Parallel)          │  ← Fails build if vulnerabilities found       │
│  └──────────────────────┘                                               │
│      │                                                                   │
│      ├────────────────────────────┐                                     │
│      ▼                            ▼                                     │
│  ┌──────────────────────┐  ┌──────────────────────┐                    │
│  │  Backend Tests       │  │  Frontend Tests      │                    │
│  │  - PHPUnit           │  │  - Vitest            │                    │
│  │  - PHPStan           │  │  - ESLint            │                    │
│  │  - Pint              │  │  - TypeScript check  │                    │
│  │  - ENV validation    │  │  - Build check       │                    │
│  │  - Migration check   │  │                      │                    │
│  └──────────────────────┘  └──────────────────────┘                    │
│      │                            │                                     │
│      └────────────┬───────────────┘                                     │
│                   ▼                                                      │
│  ┌──────────────────────┐                                               │
│  │  Pre-Deploy          │  ← Only on main/production                   │
│  │  Validation          │  ← deploy:validate command                   │
│  │  - Migration safety  │  ← Rollback test                             │
│  │  - Route validation  │                                               │
│  │  - Config cache test │                                               │
│  └──────────────────────┘                                               │
│      │                                                                   │
│      ▼ (production branch only)                                         │
│  ┌──────────────────────┐                                               │
│  │  Deploy              │  ← Requires approval                         │
│  └──────────────────────┘                                               │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Security Audit Requirements

### 2.1 NPM Audit (Frontend)

```yaml
# Fails on HIGH or CRITICAL vulnerabilities
npm audit --audit-level=high
```

| Severity | Action |
|----------|--------|
| Low | Logged, not blocking |
| Moderate | Logged, not blocking |
| **High** | ❌ **Fails build** |
| **Critical** | ❌ **Fails build** |

### 2.2 Composer Audit (Backend)

```yaml
# Fails on any vulnerability
composer audit
```

All vulnerabilities fail the build. No severity filtering.

---

## 3. Environment Validation

### 3.1 Boot-time Validation (AppServiceProvider)

| Variable | Requirement | Production Behavior |
|----------|-------------|---------------------|
| `APP_KEY` | ≥32 characters | **Throws exception** |
| `QR_SECRET_KEY` | ≥32 characters | **Throws exception** |
| `DB_CONNECTION` | Valid driver | **Throws exception** |
| `APP_DEBUG` | Must be `false` | **Throws exception** |

### 3.2 CLI Validation Commands

```bash
# Quick validation
php artisan env:validate

# Production-strict validation
php artisan env:validate --production

# Full pre-deploy check
php artisan deploy:validate

# Strict mode (fail on warnings)
php artisan deploy:validate --strict
```

---

## 4. Migration Safety

### 4.1 CI Migration Check

```yaml
# In CI pipeline
php artisan migrate --force
php artisan migrate:rollback --step=1 --force
php artisan migrate --force
```

This ensures:
- Migrations run without errors
- Rollback is possible
- Re-migration works

### 4.2 Deployment Migration

If migration fails during deployment:

```bash
# Automatic rollback
php artisan migrate --force || {
    echo "Migration failed! Rolling back..."
    php artisan migrate:rollback --step=1 --force
    exit 1
}
```

---

## 5. Test Case Checklist

### 5.1 Critical Attendance Flow Tests

These tests **MUST** pass before any deployment:

| # | Test Case | File | Priority |
|---|-----------|------|----------|
| 1 | QR token generation is valid | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 2 | QR token expires after timeout | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 3 | Tampered QR token is rejected | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 4 | Valid scan creates attendance record | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 5 | Scan out records checkout time | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 6 | Expired QR code is rejected | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 7 | Duplicate scan is rejected | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 8 | Idempotent request with same ID | `CriticalAttendanceFlowTest` | 🟠 High |
| 9 | Scan outside geofence is flagged | `CriticalAttendanceFlowTest` | 🟠 High |
| 10 | Mock location creates anomaly | `CriticalAttendanceFlowTest` | 🟠 High |
| 11 | Unauthenticated scan rejected | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 12 | Cross-school scan rejected | `CriticalAttendanceFlowTest` | 🔴 Critical |
| 13 | Rate limiting applied | `CriticalAttendanceFlowTest` | 🟠 High |

### 5.2 Authentication Tests

| # | Test Case | Priority |
|---|-----------|----------|
| 1 | Login with valid credentials succeeds | 🔴 Critical |
| 2 | Login with invalid credentials fails | 🔴 Critical |
| 3 | Rate limiting on login endpoint | 🟠 High |
| 4 | Token expiration works correctly | 🟠 High |
| 5 | Logout invalidates token | 🔴 Critical |
| 6 | Inactive user cannot login | 🔴 Critical |

### 5.3 Multi-tenant Isolation Tests

| # | Test Case | Priority |
|---|-----------|----------|
| 1 | User can only see own school data | 🔴 Critical |
| 2 | SchoolScope applied to all queries | 🔴 Critical |
| 3 | Cross-tenant data access blocked | 🔴 Critical |
| 4 | Super admin can access all schools | 🟠 High |

### 5.4 API Security Tests

| # | Test Case | Priority |
|---|-----------|----------|
| 1 | All endpoints require authentication | 🔴 Critical |
| 2 | Role-based access enforced | 🔴 Critical |
| 3 | Input validation on all endpoints | 🟠 High |
| 4 | SQL injection prevented | 🔴 Critical |
| 5 | XSS in responses prevented | 🟠 High |

---

## 6. Running Tests Locally

### 6.1 Full Test Suite

```bash
# Backend
cd backend
php artisan test

# Frontend
cd frontend-web
npm test

# Mobile
cd AbsensiQRMobile
npm test
```

### 6.2 Critical Tests Only

```bash
# Run only critical attendance tests
cd backend
php artisan test --filter="CriticalAttendanceFlow"

# Run attendance-related tests
php artisan test --filter="Attendance|Qr|Scan"
```

### 6.3 With Coverage

```bash
cd backend
php artisan test --coverage --min=60

cd frontend-web
npm test -- --coverage
```

---

## 7. Pre-Deploy Script

Create this script for manual deployments:

```bash
#!/bin/bash
# scripts/pre-deploy.sh

set -e

echo "🔍 Running pre-deployment checks..."

# 1. Security audit
echo "📦 Checking npm vulnerabilities..."
cd frontend-web
npm audit --audit-level=high
cd ..

echo "📦 Checking composer vulnerabilities..."
cd backend
composer audit
cd ..

# 2. Run tests
echo "🧪 Running backend tests..."
cd backend
php artisan test --testsuite=Feature --stop-on-failure
cd ..

# 3. Validate environment
echo "⚙️ Validating environment..."
cd backend
php artisan deploy:validate
cd ..

# 4. Build frontend
echo "🏗️ Building frontend..."
cd frontend-web
npm run build
cd ..

echo "✅ Pre-deployment checks passed!"
```

---

## 8. Workflow Files

### 8.1 Main CI/CD: `.github/workflows/ci.yml`

Full workflow with:
- Security audit job
- Backend tests job  
- Frontend tests job
- Pre-deploy validation job
- Deploy job (production only)

### 8.2 Security: `.github/workflows/security.yml`

Additional security checks:
- PHPStan static analysis
- ESLint security plugin
- Dependency scanning

---

## 9. Environment Variables for CI

```yaml
# Required in GitHub Secrets
POSTGRES_DB: absensi_test
POSTGRES_USER: postgres
POSTGRES_PASSWORD: postgres

# Optional (for production deploy)
PRODUCTION_HOST: your-server.com
PRODUCTION_USER: deploy
PRODUCTION_SSH_KEY: ${{ secrets.SSH_KEY }}
```

---

## 10. Troubleshooting

### npm audit fails but vulnerabilities are dev-only

```bash
# Check if vulnerabilities are in devDependencies
npm audit --production
```

If production audit passes, consider using `npm audit --audit-level=high --production` in CI.

### Composer audit false positive

```bash
# Ignore specific advisories (use with caution)
composer audit --ignore=ADVISORY-ID
```

### Migration fails in CI but works locally

1. Check database connection in CI environment
2. Ensure `.env` values are set correctly
3. Try `php artisan config:clear` before migrate

### Tests fail with "table not found"

```bash
# Ensure RefreshDatabase trait is used
# Or run migrations before tests
php artisan migrate:fresh --env=testing
```

---

## 11. Files Created/Modified

| File | Purpose |
|------|---------|
| [.github/workflows/ci.yml](../.github/workflows/ci.yml) | Main CI/CD pipeline |
| [app/Console/Commands/ValidateDeployment.php](../app/Console/Commands/ValidateDeployment.php) | Pre-deploy validation |
| [app/Console/Commands/ValidateEnvironment.php](../app/Console/Commands/ValidateEnvironment.php) | ENV validation |
| [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php) | Boot-time ENV validation |
| [tests/Feature/CriticalAttendanceFlowTest.php](../tests/Feature/CriticalAttendanceFlowTest.php) | Critical flow tests |
