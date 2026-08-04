---
description: "Use when editing backend tests under backend/tests/; keep test scope narrow, explicit, and aligned with the repository's security and tenant rules."
applyTo: "backend/tests/**/*.{php,php.stub}"
---

# Backend tests guidance

- Treat `backend/tests/` as the source of truth for backend behavior checks across Unit, Feature, Integration, Security, and Resilience coverage.
- Prefer the smallest test scope that proves the change, then widen only if the behavior crosses layers or app boundaries.
- Keep tests deterministic: avoid external dependencies, isolate tenant data, and assert authorization and scope behavior where relevant.
- When touching QR, attendance, auth, middleware, or tenant-sensitive code, include both the happy path and the security/negative path.
- Use the existing test structure and helpers in the repo before inventing new testing patterns.
- Align test expectations with the current API spec and service contracts, not stale README wording.
- After editing tests, run the narrowest relevant PHPUnit subset first; expand only if the focused run leaves gaps.
