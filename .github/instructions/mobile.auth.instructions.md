---
description: "Use when editing mobile authentication, secure storage, token refresh, or auth context code under AbsensiQRMobile/; keep credentials and session handling secure."
applyTo: "AbsensiQRMobile/src/{api,contexts,services,utils}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Mobile auth guidance

- Treat `AbsensiQRMobile/src/services/AuthService.ts`, `AbsensiQRMobile/src/contexts/AuthContext.tsx`, `AbsensiQRMobile/src/utils/storage.ts`, and related auth helpers as the source of truth for session handling.
- Keep tokens out of logs, out of AsyncStorage, and out of ad hoc caches; secure storage must stay encrypted and consistent across reads/writes.
- Preserve the existing refresh, invalidation, and logout behavior instead of inventing a second auth flow.
- If you change auth state shape or storage behavior, update the consuming API client and any auth-sensitive screens together.
- Prefer the current source tree and mobile docs over README wording when they conflict.
- Before editing, check the auth and storage modules first, then the relevant screens or services that consume them.
- Validate with the narrowest relevant mobile auth test or lint path first.
