---
description: "Use when editing the React Native mobile app under AbsensiQRMobile/; keep storage, networking, camera, permissions, and navigation aligned."
applyTo: "AbsensiQRMobile/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Mobile index

Use the more specific mobile instruction files for feature work:

- [mobile auth](./mobile.auth.instructions.md) — secure storage, token refresh, and auth context behavior
- [mobile networking](./mobile.networking.instructions.md) — API clients, SSL pinning, and config
- [mobile camera](./mobile.camera.instructions.md) — QR scan flow, permission handling, and camera safety

Keep this file as the lightweight entry point for the mobile app, and prefer the more specific instruction when a task matches one of those concerns.
