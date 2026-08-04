---
description: "Use when editing Laravel service classes under backend/app/Services/; keep business logic, tenant boundaries, and security flows inside the service layer."
applyTo: "backend/app/Services/**/*.{php,php.stub}"
---

# Backend services guidance

- Treat `backend/app/Services/` as the main home for business logic, orchestration, domain rules, and cross-cutting application behavior.
- Keep services focused and composable; controllers should call services instead of re-implementing rules inline.
- Preserve tenant isolation, authorization checks, and audit/logging behavior inside service methods when that logic matters.
- For QR, attendance, monitoring, security, and rollback flows, reuse the existing service classes and patterns before introducing a new pattern.
- Prefer the current service implementations, manifests, and docs over older README prose when they disagree.
- Before editing, inspect the adjacent service files and the relevant backend docs, especially the API spec and service implementation guide.
- If a change affects a service contract, update or add the narrowest relevant tests that cover the affected branch of logic.
