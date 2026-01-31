# AbsensiQRPro - AI Guide
## Architecture Snapshot
- Single repo operating Laravel API, React dashboard, and React Native app; all clients talk to REST endpoints described in [backend/docs/03_api_specification.md](backend/docs/03_api_specification.md).
- Tenancy lives on school_id; apply [backend/app/Traits/BelongsToSchool.php](backend/app/Traits/BelongsToSchool.php) to models to inherit the global filter defined in [backend/app/Scopes/SchoolScope.php](backend/app/Scopes/SchoolScope.php) with automatic super_admin bypass.
- Launch the whole stack with [start-dev.bat](start-dev.bat), which spawns artisan serve, reverb:start, queue:listen, and npm run dev; keep the worker window open to surface audit logs.
## Backend Principles
- Controllers stay thin: FormRequests feed service classes in [backend/app/Services](backend/app/Services) as mandated by [backend/docs/11_service_implementation.md](backend/docs/11_service_implementation.md).
- Attendance scans funnel through [backend/app/Services/AttendanceService.php](backend/app/Services/AttendanceService.php), consuming StudentQrService payloads, validating class membership, enforcing request_id idempotency, and logging anomalies to the attendance channel.
- QR tokens remain stateless HMAC payload.signature strings handled exclusively by [backend/app/Services/QrService.php](backend/app/Services/QrService.php); the non-negotiable contract is in [backend/docs/10_FINAL_DECISIONS.md](backend/docs/10_FINAL_DECISIONS.md).
- Security middleware is layered: CheckUserActive, CheckApiMaintenance, RateLimitBySchool, and device checks live in [backend/app/Http/Middleware](backend/app/Http/Middleware); reuse their throttle aliases instead of hard-coded limits.
- Always scope queries by school_id or reuse provided scopes; only model explicit super_admin bypasses when required by policies in [backend/app/Policies](backend/app/Policies).
## Frontend Web
- React 19 + Vite boots a shared QueryClient with five-minute staleTime in [frontend-web/src/main.tsx](frontend-web/src/main.tsx); align data hooks with that cache window.
- Axios wrapper [frontend-web/src/lib/api.ts](frontend-web/src/lib/api.ts) injects bearer tokens, toggles maintenance mode via Zustand, and handles ACCOUNT_INACTIVE/SCHOOL_INACTIVE responses to mirror backend middleware.
- Auth state lives in [frontend-web/src/modules/auth/stores/useAuthStore.ts](frontend-web/src/modules/auth/stores/useAuthStore.ts); ProtectedRoute in [frontend-web/src/App.tsx](frontend-web/src/App.tsx) checks role_type and redirects to the correct dashboard.
- Use [frontend-web/src/utils/toast.ts](frontend-web/src/utils/toast.ts) for feedback and keep payload shapes identical to backend DTOs to avoid adapter layers.
## Mobile App
- Persist auth via EncryptedStorage helpers in [AbsensiQRMobile/src/utils/storage.ts](AbsensiQRMobile/src/utils/storage.ts); await writes before navigating so guards read fresh state.
- HTTP clients in [AbsensiQRMobile/src/api/core.ts](AbsensiQRMobile/src/api/core.ts) (and its counterparts) auto-select emulator-safe base URLs when env vars are missing—keep base path changes synchronized across clients.
- Attendance scanning posts to /v1/attendance/scan and expects the AttendanceRecord contract defined in [AbsensiQRMobile/src/api/attendance.ts](AbsensiQRMobile/src/api/attendance.ts); coordinate schema updates with the backend response from AttendanceService.
## Developer Workflow
- Preferred stack startup is [start-dev.bat](start-dev.bat); manual alternative is php artisan serve, php artisan reverb:start, php artisan queue:listen --tries=3, and npm run dev from frontend-web.
- Regression commands: php artisan test (backend), npm test (frontend-web), npm test (AbsensiQRMobile); queues must run to observe async side effects.
- Configure QR_SECRET_KEY, Reverb, and storage drivers in backend .env; align VITE_API_URL and EXPO_PUBLIC_API_URL to the same host for end-to-end tests.
## Reference Docs
- Review [backend/docs/09_security_improvements.md](backend/docs/09_security_improvements.md) and [backend/docs/12_AUDIT_FINDINGS.md](backend/docs/12_AUDIT_FINDINGS.md) before touching security-sensitive flows.
- Match UX and navigation expectations using [backend/docs/14_UI_UX_GUIDELINES.md](backend/docs/14_UI_UX_GUIDELINES.md) and mirror role menus across web and mobile entry points.
