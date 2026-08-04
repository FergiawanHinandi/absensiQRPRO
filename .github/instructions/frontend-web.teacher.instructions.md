---
description: "Use when editing frontend-web teacher pages, teacher services, or teacher module logic; keep attendance, schedule, and homeroom flows aligned."
applyTo: "frontend-web/src/{pages/Teacher,modules/teacher,services}/**/*.{ts,tsx,js,jsx,mjs,cjs}"
---

# Frontend-web teacher guidance

- Treat teacher screens and teacher services as the source of truth for attendance, schedules, reports, and homeroom-related flows.
- Reuse `services/teacherService.ts`, `modules/teacher/hooks/useSchedules.ts`, and `modules/teacher/services/attendanceApi.ts` instead of duplicating teacher API logic in pages.
- Keep teacher QR, manual attendance, schedule, session history, login history, password change, and homeroom pages aligned with the shared teacher experience.
- Avoid scattering teacher-specific request logic across multiple components when a shared service or hook already exists.
- Keep loading, empty, error, and permission-sensitive states consistent across teacher-facing screens.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the teacher page plus any shared service, hook, or module page it depends on.
- Validate with the narrowest relevant teacher test, lint, or typecheck path first.
