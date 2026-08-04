---
description: "Use when editing frontend-web data fetching, API client, React Query, or server-state code; keep transport, caching, and mutation patterns consistent."
applyTo: "frontend-web/src/{lib,hooks,services,modules,store,utils}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web data-fetching guidance

- Treat `frontend-web/src/lib/api.ts` as the shared API client and prefer it over ad hoc fetch logic.
- Keep TanStack Query as the default server-state layer; preserve the existing 5-minute stale-time pattern unless the feature clearly needs different caching.
- Put request, mutation, and cache behavior in shared hooks or service layers rather than re-implementing it inside pages.
- Keep 401/503 handling, bearer injection, and API error normalization aligned with the current client behavior.
- Use the shared toast helpers for user-facing fetch and mutation failures instead of `alert()`.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the shared API client, the relevant query/mutation hooks, and the feature module that consumes them.
- Validate with the narrowest relevant data-fetching test, lint, or typecheck path first.
