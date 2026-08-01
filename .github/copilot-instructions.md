# AbsensiQRPro - AI Guide

## Architecture Overview
Monorepo with three apps sharing a common REST API:
- **backend/** — Laravel 11 API (PHP 8.2+), PostgreSQL, Laravel Sanctum auth, Reverb WebSocket
- **frontend-web/** — React 19 + Vite + TailwindCSS admin dashboard
- **AbsensiQRMobile/** — React Native teacher/student mobile app

API contract: [backend/docs/03_api_specification.md](backend/docs/03_api_specification.md)

## Multi-Tenancy (Critical)
All school-scoped models **must** use `BelongsToSchool` trait:
```php
use App\Traits\BelongsToSchool;
class MyModel extends Model {
    use BelongsToSchool;  // Auto-applies SchoolScope, auto-fills school_id
}
```
- [SchoolScope](backend/app/Scopes/SchoolScope.php) filters queries by `school_id` automatically; super_admin bypasses are logged
- Never query cross-tenant data without explicit `withoutGlobalScope(SchoolScope::class)` and policy authorization
- Models using this trait: Attendance, User, Schedule, Subject, QrCode, TeacherDevice, SecurityEvent, etc.

## Backend Patterns

### Controller → FormRequest → Service Flow
```php
// Controller stays thin - validation in Request, logic in Service
public function scan(AttendanceScanRequest $request, AttendanceService $service) {
    return $service->recordByTeacherScan($request->user(), $request->validated());
}
```
Services: [backend/app/Services/](backend/app/Services/) | Guide: [backend/docs/11_service_implementation.md](backend/docs/11_service_implementation.md)

### QR Token (LOCKED Architecture)
**NEVER use Laravel `encrypt()`** — use stateless HMAC tokens only:
```php
// Token format: base64(payload).HMAC_SHA256_signature
// Handled ONLY by QrService - no database lookups for validation
$token = app(QrService::class)->generate(['schedule_id' => $id, 'qr_id' => $qrId, 'type' => 'in']);
```
Architectural decision: [backend/docs/10_FINAL_DECISIONS.md](backend/docs/10_FINAL_DECISIONS.md) | Implementation: [backend/app/Services/QrService.php](backend/app/Services/QrService.php)

### Security Middleware Stack
Reuse registered aliases in routes instead of custom limits:
- `CheckUserActive` — blocks inactive accounts
- `CheckApiMaintenance` — returns 503 during maintenance
- `RateLimitBySchool` — per-school throttling (use `school.rate.limit:60,1` alias)
- `SecurityHeaders` — XSS/CSP protection

Middleware: [backend/app/Http/Middleware/](backend/app/Http/Middleware/)

## Frontend Web Patterns

### State & Data Fetching
- **Auth**: Zustand store in [useAuthStore.ts](frontend-web/src/modules/auth/stores/useAuthStore.ts) — tokens in sessionStorage via `tokenStore`, not localStorage
- **API**: [lib/api.ts](frontend-web/src/lib/api.ts) axios client auto-injects bearer, handles 401→redirect, 503→maintenance mode
- **Queries**: React Query with 5-min staleTime ([main.tsx](frontend-web/src/main.tsx))

### Notifications
Use `showToast.success()`, `.error()`, `.warning()` from [utils/toast.ts](frontend-web/src/utils/toast.ts) — never `alert()`.

### Role-Based Routing
[App.tsx](frontend-web/src/App.tsx) maps `role_type` to dashboard paths:
- `super_admin` → `/super-admin/dashboard`
- `admin`/`school_admin` → `/admin/dashboard`
- `teacher`/`homeroom_teacher` → `/teacher/dashboard`

## Mobile App Patterns

### Secure Storage
```typescript
import { storage } from '../utils/storage';
await storage.setToken(token);  // EncryptedStorage - await before navigation
```
[AbsensiQRMobile/src/utils/storage.ts](AbsensiQRMobile/src/utils/storage.ts)

### API Base URL
[core.ts](AbsensiQRMobile/src/api/core.ts) auto-detects emulator URLs (Android: `10.0.2.2`, iOS: `127.0.0.1`). Set `EXPO_PUBLIC_API_URL` in production.

### Attendance Contract
```typescript
// POST /v1/attendance/scan
interface ScanPayload { qr_token: string; lat?: number; lng?: number; }
interface AttendanceRecord { id, student_name, class, subject, time, status }
```
[AbsensiQRMobile/src/api/attendance.ts](AbsensiQRMobile/src/api/attendance.ts)

## Developer Workflow

### Start Everything
```bash
# Windows - opens 4 terminals (API, WebSocket, Queue, Frontend)
start-dev.bat

# Manual alternative
cd backend && php artisan serve
cd backend && php artisan reverb:start
cd backend && php artisan queue:listen --tries=3
cd frontend-web && npm run dev
```

### Run Tests
```bash
cd backend && php artisan test           # PHPUnit (keep queue running for async)
cd frontend-web && npm test              # Vitest
cd AbsensiQRMobile && npm test           # Jest
```

### Required ENV
```env
# backend/.env
QR_SECRET_KEY=your-32-char-minimum-key  # REQUIRED - separate from APP_KEY
REVERB_APP_ID/KEY/SECRET                 # WebSocket
DB_CONNECTION=pgsql                      # PostgreSQL in production

# frontend-web/.env
VITE_API_URL=http://localhost:8000/api/v1

# AbsensiQRMobile/.env
EXPO_PUBLIC_API_URL=http://your-server/api
```

## Key Reference Docs
- Security audit: [backend/docs/09_security_improvements.md](backend/docs/09_security_improvements.md), [backend/docs/12_AUDIT_FINDINGS.md](backend/docs/12_AUDIT_FINDINGS.md)
- UI/UX guidelines: [backend/docs/14_UI_UX_GUIDELINES.md](backend/docs/14_UI_UX_GUIDELINES.md)
- API spec: [backend/docs/03_api_specification.md](backend/docs/03_api_specification.md)
