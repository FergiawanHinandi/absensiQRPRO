---
description: "Use when editing frontend-web admin pages or admin module logic; keep operational dashboards, settings, and report flows consistent."
applyTo: "frontend-web/src/{pages/Admin,modules/admin}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web admin guidance

- Treat admin pages as orchestration layers around shared hooks, services, and dashboard components.
- Reuse the existing admin module hooks and services, especially `modules/admin/hooks/`, `modules/admin/hooks/useAdminService.ts`, and `services/adminService.ts`.
- Keep admin screens aligned with the role-based dashboard structure and the shared layout patterns already in the app.
- Avoid duplicating query, mutation, or export logic directly inside page components when a hook or service already owns it.
- Keep settings, attendance, billing, reporting, and operational screens consistent in loading, empty, and error states.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the target admin page plus any hooks or services that feed it.
- Validate with the narrowest relevant admin test, lint, or typecheck path first.
