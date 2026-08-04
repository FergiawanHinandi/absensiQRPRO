---
description: "Use when editing frontend-web auth, token storage, session lifecycle, or role/guard logic; keep auth state and credentials handling secure."
applyTo: "frontend-web/src/{modules,store,components,hooks,utils,services,lib}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web auth guidance

- Treat `frontend-web/src/modules/auth/stores/useAuthStore.ts` and the related auth helpers as the source of truth for login, logout, session restoration, and role state.
- Keep auth tokens in `sessionStorage` via `tokenStore`; do not move them to `localStorage` or add a second storage path.
- Preserve the current bearer injection and auth error handling flow in the shared API client instead of duplicating auth logic in components.
- Keep role checks, redirects, and guard behavior aligned with the centralized routing model in `frontend-web/src/App.tsx`.
- Use the shared toast helpers for auth feedback instead of `alert()`.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the auth store, shared API client, and any consuming screens or guards.
- Validate with the narrowest relevant auth test, lint, or typecheck path first.
