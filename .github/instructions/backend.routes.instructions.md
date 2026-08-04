---
description: "Use when editing Laravel route files under backend/routes/; keep route grouping, middleware aliases, and endpoint ownership consistent."
applyTo: "backend/routes/**/*.php"
---

# Backend routes guidance

- Treat `backend/routes/` as the routing map for API, health, metrics, monitoring, rollback, and other operational endpoints.
- Prefer the existing route files (`api.php`, `api_monitoring.php`, `api_secure_files.php`, `api_rollback.php`, `health.php`, `metrics.php`, `web.php`, `console.php`) instead of adding new route files.
- Keep route files thin: group endpoints, apply middleware aliases, and delegate logic to controllers/services.
- Use the registered middleware aliases already present in the app before introducing custom route wrappers.
- When adding or changing routes, check the API spec and the relevant service implementation first.
- Avoid cross-tenant exposure in route definitions; authorization and scope checks still apply even when the endpoint is public-facing.
- Validate route changes with the narrowest relevant backend test set after editing.
