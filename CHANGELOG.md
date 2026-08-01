# Changelog

All notable changes to AbsensiQR Pro will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Security
- Rotated all exposed secrets (APP_KEY, QR_SECRET_KEY, REVERB keys, BACKUP_ENCRYPTION_KEY)
- Removed Sentry DSN from committed .env
- Changed default DB_PASSWORD placeholder to `CHANGE_ME_IN_PRODUCTION`
- Added explicit .gitignore rules for backend/.env and frontend-web/.env

### Added
- **Slack Alerting Service** (`App\Services\Alerting\SlackAlert`) - configurable webhook-based alerting for critical/warning/info/success notifications
- **Alerting config** (`config/alerting.php`) - centralized configuration for alert channels
- Integrated Slack alerts into RedisMonitoringService, AutoRemediationService, MonitorCacheStampede, and LogRedisCircuitBreakerEvent listeners
- **84 frontend tests** across 14 test files covering:
  - UI components: Button, Input, Badge, Alert
  - Common components: ErrorBoundary, Loading, ErrorMessage
  - Auth module: useAuthStore, LoginForm
  - Stores: maintenanceStore
  - Utilities: errorHandler, secureTokenStore
  - Pages: PlaceholderPage, MaintenancePage

### Changed
- Gated debug `console.log` statements behind `import.meta.env.DEV` checks
- Removed unnecessary render-time debug logs from Button, LoginForm, and main.tsx
- Moved 14 root-level markdown documentation files to `backend/docs/`
- Cleaned up PricingPage payment result logging

### Removed
- 30 `tmpclaude-*` temporary files from backend
- Debug scripts: `test_login.php`, `check_users.php`, `db_test.php`
- Test output files: `test_output.txt`, `test_output_2.txt`, `test_output_3.txt`
- Manual test scripts: `run-webhook-tests.bat`, `verify-webhook-tests.ps1`
- Backup artifacts: `*.pre_restore_backup` files from config/
- Zip archives: `routes.zip`, `config.zip`, `database.zip`
- Example file: `DashboardController.example.php`
- Frontend debug files: `debug-login.html`, `debug-login-test.js`
- Disabled component: `StudentCardManagement.tsx.disabled`
- Stale `.artisan_pid` file

## [1.0.0] - 2026-06-02

### Added
- Multi-tenant QR code attendance system
- 9-role system (Super Admin, School Admin, Principal, Vice Principal, Teacher, Homeroom Teacher, Staff, Student, Parent)
- QR Code dynamic generation with GPS validation
- Real-time WebSocket updates via Laravel Reverb
- React Native mobile app for students and teachers
- Payment integration with Midtrans
- WhatsApp notification support
- Comprehensive reporting with PDF/Excel export
- Redis monitoring with circuit breaker pattern
- Self-healing auto-remediation system
- Rate limiting and security middleware stack
- Health check endpoint at `/api/v1/health`
- Database indexing strategy for query optimization
- Docker multi-stage build support
- GitHub Actions CI/CD pipeline
- Prometheus + Grafana + Loki observability stack
- Disaster recovery procedures
- Blue/green deployment support

### Security
- CORS properly configured with specific origins
- Rate limiting on auth endpoints (5 attempts/minute)
- Sanctum token authentication with 30-minute expiry
- Role-based access control with Spatie permissions
- Payment webhook signature verification
- SQL injection protection via Eloquent
- XSS protection via security headers middleware
- Replay attack prevention on QR attendance
- Idempotency checks for attendance submissions
- Tenant isolation validation

---

*This changelog follows semantic versioning. For upgrade guides, see [docs/](docs/).*
