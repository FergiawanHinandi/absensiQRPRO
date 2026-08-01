# CI Minimum Gates

Dokumen ini adalah baseline gate yang wajib lolos sebelum merge ke `main` atau `production`.

## 1. Security Hygiene

- Tidak ada file rahasia ter-track (`.env`, private key, token dump, file kredensial lokal).
- Secret scan workflow harus `pass`.
- Dependency audit:
- Backend: `composer audit` tanpa vulnerability aktif.
- Frontend: `npm audit --audit-level=high` tanpa `high/critical`.

## 2. Backend Baseline

- Install dependency: `composer install`.
- Static check: `./vendor/bin/phpstan analyse --memory-limit=1G`.
- Test baseline minimal:
- `php artisan test tests/Unit/ExampleTest.php`
- `php artisan test tests/Feature/SecurityTest.php`
- `php artisan test tests/Feature/Tenancy/TenantIsolationTest.php`

## 3. Frontend Web Baseline

- Install dependency: `npm ci`.
- Lint: `npm run lint`.
- Unit test: `npm run test -- --run`.
- Build harus sukses: `npm run build`.

## 4. Mobile (React Native) Baseline

- Install dependency: `npm ci`.
- Unit test minimal harus hijau setelah konfigurasi Jest stabil.
- Jika masih ada test yang known-broken, issue harus dicatat dengan owner + target date.

## 5. Documentation Integrity

- Versi framework di dokumen harus sinkron dengan runtime (`php artisan about`).
- Klaim kelengkapan modul harus menyertakan tanggal verifikasi.
- Klaim readiness wajib didukung artefak CI/test terbaru.

## 6. Merge Policy

- Dilarang merge jika gate 1 atau gate 2 gagal.
- Exception hanya boleh via approval tertulis maintainer + link issue mitigasi.
