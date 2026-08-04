---
description: "Use when editing frontend-web routing, navigation guards, or role-based page flow; keep route ownership centralized and predictable."
applyTo: "frontend-web/src/App.tsx"
---

# Frontend-web routing guidance

- Treat `frontend-web/src/App.tsx` as the routing hub and extend the existing role-based flow instead of scattering route decisions.
- Keep public vs protected route behavior aligned with auth state from the shared auth store.
- When adding role-specific pages or redirects, prefer the existing dashboard mapping and route guard patterns already present in the app.
- Avoid duplicating navigation rules inside feature components if the same rule can live in the central router.
- Keep route-level loading, redirect, and error states consistent with the rest of the dashboard.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect `App.tsx`, route-linked pages, and any guard components that the route tree depends on.
- Validate with the narrowest relevant routing test, lint, or typecheck path first.
