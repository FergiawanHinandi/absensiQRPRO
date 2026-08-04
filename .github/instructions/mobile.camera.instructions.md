---
description: "Use when editing mobile camera, QR scan, permission, or scan-screen code under AbsensiQRMobile/; keep capture, permissions, and scan flow safe."
applyTo: "AbsensiQRMobile/src/{components,navigation,screens,services,utils,hooks}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Mobile camera guidance

- Treat `AbsensiQRMobile/src/screens/attendance/ScanQRScreen.tsx` and `AbsensiQRMobile/src/navigation/StudentNavigator.tsx` as the primary QR scan flow.
- Keep `react-native-vision-camera` and `vision-camera-code-scanner` usage aligned with the existing scan lifecycle and permission flow.
- Request and handle camera permissions explicitly before activating scanning, and keep the UI/UX path consistent with the current screen behavior.
- Preserve duplicate-scan protection, pause/resume behavior, and any security guard wrappers around sensitive scan screens.
- Reuse existing location, validation, and offline-sync helpers when scan behavior depends on them.
- Prefer the current source tree and mobile docs over README wording when they conflict.
- Before editing, inspect the scan screen, navigation, and any helper components/services it depends on.
- Validate with the narrowest relevant mobile camera test or lint path first.
