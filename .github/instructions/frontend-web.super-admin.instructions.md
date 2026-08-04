---
description: "Use when editing frontend-web super-admin pages, layouts, or navigation; keep global admin flows, system pages, and security screens consistent."
applyTo: "frontend-web/src/{pages/SuperAdmin,components/layout,config}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web super-admin guidance

- Treat super-admin screens as system-level control surfaces for schools, packages, roles, security, and platform health.
- Reuse the existing super-admin layouts, navigation config, and page composition patterns instead of creating a separate shell.
- Keep global management pages such as schools, packages, feature flags, audit logs, rate limits, and system management aligned with the same UX and permission model.
- Avoid duplicating the role-based access logic that already lives in the central app routing and layout configuration.
- Keep global settings and system pages consistent in loading, empty, error, and permission-denied states.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the target super-admin page plus the layout and navigation files it depends on.
- Validate with the narrowest relevant super-admin test, lint, or typecheck path first.
