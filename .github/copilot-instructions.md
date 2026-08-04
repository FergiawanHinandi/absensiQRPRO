# AbsensiQRPro - AI Guide

AbsensiQRPro is a monorepo with three active apps:

- `backend/` — Laravel API
- `frontend-web/` — React 19 + Vite admin dashboard
- `AbsensiQRMobile/` — React Native app for teachers and students

## Source of truth

- Backend: `backend/README.md`, `.github/instructions/backend.index.instructions.md`
- Frontend-web: `frontend-web/README.md`, `.github/instructions/frontend-web.index.instructions.md`
- Mobile: `AbsensiQRMobile/README.md`, `.github/instructions/mobile.index.instructions.md`

## Non-negotiables

- School-scoped backend models must use `BelongsToSchool` and respect `SchoolScope`.
- Never cross tenant boundaries unless the scope is explicitly bypassed and authorization is checked.
- QR tokens are HMAC-based through `QrService`; never replace them with Laravel `encrypt()`.
- Frontend auth tokens live in `sessionStorage` via `tokenStore`; do not move them to `localStorage`.
- Use `showToast.success()`, `.error()`, or `.warning()` instead of `alert()`.
- Mobile token storage must stay encrypted; await the save before navigating.

## When in doubt

- Prefer the app README plus the matching instruction index over stale prose in older docs.
- Keep changes aligned with the current source tree.
- Use the narrowest relevant test set after every change.
