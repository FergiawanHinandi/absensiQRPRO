---
description: "Use when editing frontend-web security monitoring screens or security dashboard logic; keep sensitive operational views consistent and minimal."
applyTo: "frontend-web/src/pages/Admin/SecurityMonitoring.tsx"
---

# Frontend-web security monitoring guidance

- Treat `frontend-web/src/pages/Admin/SecurityMonitoring.tsx` as the main security monitoring view for admins.
- Reuse the existing security dashboard hooks and nearby security pages instead of rebuilding the data flow inline.
- Keep security charts, summaries, tables, and alerts aligned with the existing dashboard styling and interaction patterns.
- Avoid logging or exposing sensitive operational details beyond what the screen already needs to show.
- Keep loading, refresh, empty, and error states consistent with the rest of the admin dashboard.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the security monitoring page and its supporting hooks or shared components.
- Validate with the narrowest relevant security-monitoring test, lint, or typecheck path first.
