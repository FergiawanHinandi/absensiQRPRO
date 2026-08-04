---
description: "Use when editing mobile networking, API clients, SSL pinning, or config code under AbsensiQRMobile/; keep transport behavior secure and consistent."
applyTo: "AbsensiQRMobile/src/{api,config,hooks,services,utils}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Mobile networking guidance

- Treat `AbsensiQRMobile/src/api/client.ts`, `AbsensiQRMobile/src/api/secureClient.ts`, `AbsensiQRMobile/src/api/index.ts`, and `AbsensiQRMobile/src/config/sslPinning.ts` as the core networking surface.
- Keep `react-native-config` usage, API base URL resolution, and emulator/production switching consistent with the existing implementation.
- Preserve SSL pinning behavior, fallback handling, and network error reporting instead of replacing them with a simpler fetch path.
- Keep request interceptors, token injection, retry handling, and offline-aware flows aligned across the API layer and dependent services.
- Avoid duplicating transport logic in screens or components; route network behavior through the shared API layer.
- Prefer the current source tree and mobile docs over README wording when they conflict.
- Before editing, inspect the API client, secure client, and related security/config modules that already define the transport contract.
- Validate with the narrowest relevant networking test or lint path first.
