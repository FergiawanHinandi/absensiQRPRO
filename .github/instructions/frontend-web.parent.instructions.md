---
description: "Use when editing frontend-web parent pages or parent-facing UI; keep child overview, profile, and notification flows simple and consistent."
applyTo: "frontend-web/src/{pages/Parent,modules/parent,components/dashboard}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web parent guidance

- Treat parent-facing screens as read-oriented views around child status, notifications, and profile information.
- Reuse the existing parent pages and dashboard components, especially `modules/parent/pages/` and `components/dashboard/ParentNotificationCenter.tsx`.
- Keep parent profile, student list, and notification experiences aligned with the current dashboard design and role-based navigation.
- Avoid duplicating child-summary or notification logic across multiple parent screens.
- Keep loading, empty, and error states lightweight and predictable for parent-facing views.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the parent page plus the notification or profile component it depends on.
- Validate with the narrowest relevant parent test, lint, or typecheck path first.
