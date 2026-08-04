---
description: "Use when editing frontend-web dashboard screens, summary widgets, layouts, or admin overview pages; keep dashboard state and composition consistent."
applyTo: "frontend-web/src/{components/dashboard,components/layout,pages/Admin,pages/SuperAdmin,pages/Principal,pages/Teacher,pages/Parent,pages/Student,modules/admin}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web dashboard guidance

- Treat dashboard pages and widgets as composed UI around shared summary data, not as a place to duplicate business logic.
- Prefer shared dashboard components such as `components/dashboard/*` and shared layouts over ad hoc page-level composition.
- Keep dashboard screens aligned with the existing role-based structure in `App.tsx` and the dashboard layout components.
- Reuse dashboard data hooks and services where they already exist, especially under `modules/admin/hooks/` and `services/`.
- Keep loading, empty, and error states consistent across dashboard screens.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the page, widget, and any hook or service that feeds it.
- Validate with the narrowest relevant dashboard test, lint, or typecheck path first.
