# Login Accounts Reference

**Last verified:** 2026-06-12

Referensi ini berisi akun aktif yang saat ini bisa dipakai login di AbsensiQRPro.

> Default password demo untuk akun seed adalah: `password`

## Akun Super Admin

| Username | Email | Role |
|---|---|---|
| `superadmin` | `super@admin.com` | `super_admin` |

## Akun Sekolah SMP

| Username | Email | Role |
|---|---|---|
| `admin_smp` | `admin.smp@demo.com` | `school_admin` |
| `guru_smp` | `guru.smp@demo.com` | `homeroom_teacher` |
| `siswasmp` | `siswa.smp@demo.com` | `student` |
| `ortu_smp` | `ortu.smp@demo.com` | `parent` |

## Akun Sekolah SMA

| Username | Email | Role |
|---|---|---|
| `admin_sma` | `admin.sma@demo.com` | `school_admin` |
| `guru_sma` | `guru.sma@demo.com` | `homeroom_teacher` |
| `siswasma` | `siswa.sma@demo.com` | `student` |
| `ortu_sma` | `ortu.sma@demo.com` | `parent` |

## Akun Sekolah SMK

| Username | Email | Role |
|---|---|---|
| `admin_smk` | `admin.smk@demo.com` | `school_admin` |
| `guru_smk` | `guru.smk@demo.com` | `homeroom_teacher` |
| `siswasmk` | `siswa.smk@demo.com` | `student` |
| `ortu_smk` | `ortu.smk@demo.com` | `parent` |

## Role yang tersedia untuk login saat ini

- `super_admin`
- `school_admin`
- `homeroom_teacher`
- `student`
- `parent`

## Role yang belum punya akun aktif saat ini

- `admin`
- `principal`
- `vice_principal`
- `teacher`
- `staff`

## Catatan

- Semua akun di atas saat ini berstatus aktif.
- Login bisa tetap ditolak jika sekolah akun nonaktif atau kredensial salah.
- Untuk `teacher`, ada validasi perangkat pada login jika `device_id` dipakai.
