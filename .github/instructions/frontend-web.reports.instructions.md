---
description: "Use when editing frontend-web report pages, export flows, and report hooks; keep filters, loading states, and exports consistent."
applyTo: "frontend-web/src/{hooks,pages/Admin,pages/Principal,pages/SuperAdmin,modules/admin,services}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web reports guidance

- Treat report pages as orchestration layers around shared report data, filters, and export actions.
- Reuse the existing report hooks and services, especially `hooks/useExportJob.ts`, `modules/admin/hooks/useDailyReport.ts`, and `services/adminService.ts`.
- Keep date-range filters, pagination, export triggers, and loading/error states consistent across all report screens.
- Avoid embedding report aggregation or export logic directly in page components if a hook or service already owns that behavior.
- Keep report UI aligned with the role-based dashboard structure and shared API client patterns.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the target report page, the supporting hook/service, and any shared export or filter utility.
- Validate with the narrowest relevant report test, lint, or typecheck path first.
