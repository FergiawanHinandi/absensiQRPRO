---
name: qr-security
description: "Use when working on QR token generation, validation, replay protection, nonce handling, or any attendance path that touches QR security and tenant isolation."
---

# QR Security

Use this skill when you are touching QR generation, scan validation, or any code path that could affect attendance security.

## Non-negotiables
- QR tokens are HMAC-based and must go through `QrService`.
- Never replace QR token logic with Laravel `encrypt()`.
- Validate expiry, nonce/replay protection, schedule/QR identifiers, and token type together.
- Keep school boundaries intact; do not cross tenant data unless the scope bypass is explicit and authorized.
- Use the existing policies, scopes, and service-layer boundaries instead of ad hoc validation in controllers.

## Best references
- `backend/docs/10_FINAL_DECISIONS.md`
- `backend/docs/03_api_specification.md`
- `backend/docs/11_service_implementation.md`
- `backend/app/Services/QrService.php`
- `backend/app/Traits/`
- `backend/app/Scopes/`

## Use this skill for
- QR generation or regeneration flows
- Scan endpoint changes
- Replay / nonce / expiry logic
- QR-related tests and security regression fixes
- Any change that might weaken tenant isolation

## Output expectations
- State the security risk first.
- List the affected files or layers.
- Call out any required tests or edge cases before making edits.
