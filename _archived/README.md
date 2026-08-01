# Archived: Flutter Mobile Prototype

This directory was moved here on 2025-06-02 as part of the project cleanup.

## Reason
The project adopted **React Native** (`AbsensiQRMobile/`) as the single mobile platform.
This Flutter prototype was incomplete (no `main.dart`, placeholder auth tokens, hardcoded base URL)
and maintaining two mobile stacks created ambiguity for contributors.

## Contents
- `lib/di/injection.dart` — GetIt dependency injection (auth token TODO)
- `lib/data/models/pending_attendance.dart` — Hive model
- `lib/data/repositories/attendance_local_repository.dart` — Local persistence
- `lib/domain/usecases/submit_attendance_usecase.dart` — Sync use case
- `lib/services/attendance_sync_service.dart` — Background sync service

## Restoring
If Flutter development resumes, move this back to the project root:
```bash
mv _archived/mobile_flutter ./mobile_flutter
```
