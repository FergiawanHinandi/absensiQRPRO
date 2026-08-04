---
description: "Use when editing Laravel backend code under backend/; keep tenant-scoped models, service-layer boundaries, and backend test patterns aligned."
applyTo: "backend/**/*.{php,php.stub}"
---

# Backend index

Use the more specific backend instruction files for feature work:

- [backend routes](./backend.routes.instructions.md) — route grouping, middleware aliases, and endpoint ownership
- [backend services](./backend.services.instructions.md) — business logic, tenant boundaries, and security flows
- [backend tests](./backend.tests.instructions.md) — narrow, explicit backend test coverage

Keep this file as the lightweight entry point for the backend, and prefer the more specific instruction when a task matches one of those concerns.
