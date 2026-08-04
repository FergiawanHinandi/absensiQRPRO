---
description: "Use when editing frontend-web charts, graphs, trend widgets, or statistical visualizations; keep data shaping and chart rendering consistent."
applyTo: "frontend-web/src/{components/dashboard,pages/Admin,pages/SuperAdmin,pages/Principal,pages/Teacher,pages/Parent,pages/Student}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web charts guidance

- Treat chart components as presentation layers over already-shaped data; keep aggregate logic in hooks or services when possible.
- Reuse the existing Recharts patterns and responsive container patterns already present in the dashboard.
- Keep chart colors, legends, tooltips, and labels consistent with the surrounding dashboard UI.
- Avoid duplicating the same transformation logic in multiple chart components.
- Preserve accessibility and responsive behavior when adding or changing chart series.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the chart component, its data source, and any shared style or utility it depends on.
- Validate with the narrowest relevant chart test, lint, or typecheck path first.
