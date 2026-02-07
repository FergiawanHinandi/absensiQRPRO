# Perbaikan Bug Login - AbsensiQRPro

## Tanggal: 2026-02-03

## Masalah
Ketika user mencoba login, sistem menampilkan error "Terjadi kendala teknis. Tim kami sedang menanganinya." dan tidak bisa mengarah ke dashboard.

## Root Cause
Terdapat **MassAssignmentException** pada dua model:

### 1. Model User
Field berikut tidak ada di `$fillable` array:
- `failed_login_attempts`
- `last_failed_login_at`
- `locked_until`

### 2. Model PersonalAccessToken (Laravel Sanctum)
Field berikut tidak ada di `$fillable` array:
- `device_fingerprint`
- `initial_ip`
- `initial_country`
- `platform`

## Solusi yang Diterapkan

### 1. Perbaikan Model User (`app/Models/User.php`)
✅ Menambahkan field security ke `$fillable`:
```php
protected $fillable = [
    // ... existing fields ...
    // Security fields for login rate limiting
    'failed_login_attempts',
    'last_failed_login_at',
    'locked_until',
];
```

✅ Menambahkan cast untuk datetime fields:
```php
protected function casts(): array
{
    return [
        // ... existing casts ...
        // Security fields
        'last_failed_login_at' => 'datetime',
        'locked_until' => 'datetime',
    ];
}
```

### 2. Membuat Custom PersonalAccessToken Model
✅ Membuat file baru: `app/Models/PersonalAccessToken.php`
- Extend dari `Laravel\Sanctum\PersonalAccessToken`
- Menambahkan field security ke `$fillable`

✅ Mendaftarkan custom model di `app/Providers/AppServiceProvider.php`:
```php
\Laravel\Sanctum\Sanctum::usePersonalAccessToken Model(\App\Models\PersonalAccessToken::class);
```

## Testing
✅ Login berhasil dengan kredensial:
- Username: `superadmin`
- Password: `password123`
- Response: Status 200 OK
- Token berhasil di-generate
- User data berhasil dikembalikan

## File yang Dimodifikasi
1. `backend/app/Models/User.php` - Menambahkan field ke $fillable dan casts
2. `backend/app/Models/PersonalAccessToken.php` - File baru (custom model)
3. `backend/app/Providers/AppServiceProvider.php` - Mendaftarkan custom model

## Dampak
- ✅ Login sekarang berfungsi normal
- ✅ Redirect ke dashboard berdasarkan role user berfungsi
- ✅ Token authentication berfungsi
- ✅ Security features (rate limiting, account locking) tetap berfungsi

## Catatan
Tidak ada perubahan pada database schema atau migrasi. Hanya perbaikan pada model untuk mengizinkan mass assignment pada field yang sudah ada di database.
