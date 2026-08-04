---
description: "Use when editing the React dashboard under frontend-web/; keep auth, API access, routing, and toast behavior consistent."
applyTo: "frontend-web/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web index

Use the more specific frontend-web instruction files for feature work:

- [frontend-web auth](./frontend-web.auth.instructions.md) — auth store, session storage, role checks, and auth feedback
- [frontend-web routing](./frontend-web.routing.instructions.md) — `App.tsx`, route guards, and role-based navigation
- [frontend-web data-fetching](./frontend-web.data-fetching.instructions.md) — shared API client, TanStack Query, caching, and mutation patterns
- [frontend-web dashboard](./frontend-web.dashboard.instructions.md) — dashboard screens, summary widgets, and layout composition
- [frontend-web charts](./frontend-web.charts.instructions.md) — trend charts, graphs, and statistical visualizations
- [frontend-web reports](./frontend-web.reports.instructions.md) — report pages, filters, and export flows
- [frontend-web admin](./frontend-web.admin.instructions.md) — admin operational pages, settings, and module flows
- [frontend-web super-admin](./frontend-web.super-admin.instructions.md) — global admin pages, layout, and navigation
- [frontend-web security monitoring](./frontend-web.security-monitoring.instructions.md) — admin security monitoring screen and related security views
- [frontend-web billing](./frontend-web.billing.instructions.md) — packages, payments, invoices, and billing screens
- [frontend-web parent](./frontend-web.parent.instructions.md) — parent-facing profile, student list, and notification flows
- [frontend-web teacher](./frontend-web.teacher.instructions.md) — teacher attendance, schedule, and homeroom flows

Keep this file as the lightweight entry point for the dashboard, and prefer the more specific instruction when a task matches one of those concerns.
