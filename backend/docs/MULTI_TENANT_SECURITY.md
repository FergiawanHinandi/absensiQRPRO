# Multi-Tenant Security Implementation

## 📋 Overview
Dokumen ini menjelaskan implementasi keamanan multi-tenant yang telah diterapkan pada sistem AbsensiQR Pro untuk mencegah akses data antar sekolah (cross-school data access).

## 🔒 Masalah yang Diperbaiki

### 1. **Syntax Error pada Model Attendance**
**Masalah**: Model `Attendance.php` memiliki syntax error fatal yang menyebabkan aplikasi tidak bisa berjalan.
- Trait `BelongsToSchool` dipanggil di luar kurung kurawal class
- Duplikasi import dan trait usage

**Perbaikan**: 
- Menambahkan kurung kurawal `{}` yang hilang
- Menghapus duplikasi import dan trait

### 2. **User Model Tidak Ter-scope**
**Masalah**: Model `User` tidak menggunakan trait `BelongsToSchool`, sehingga query tidak otomatis difilter berdasarkan `school_id`. Ini memungkinkan:
- Guru dari Sekolah A mengakses data siswa dari Sekolah B
- Admin dari Sekolah A memodifikasi data dari Sekolah B

**Perbaikan**:
```php
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, BelongsToSchool;
    // ...
}
```

### 3. **Tidak Ada Validasi Kepemilikan Data**
**Masalah**: Endpoint yang menerima ID dari user (student_id, schedule_id, class_id) tidak memvalidasi apakah data tersebut benar-benar milik sekolah yang sama.

**Perbaikan**: Membuat trait `ValidatesSchoolOwnership` yang dapat digunakan di semua controller.

## 🛠️ Implementasi

### Trait ValidatesSchoolOwnership

Trait ini menyediakan method helper untuk validasi kepemilikan data:

```php
trait ValidatesSchoolOwnership
{
    /**
     * Validasi bahwa model milik sekolah yang sama
     */
    protected function validateSchoolOwnership(Model $model, string $errorMessage = null): void
    {
        $user = auth()->user();
        
        // Skip validation untuk Super Admin
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            return;
        }

        if ($user && $user->school_id !== $model->school_id) {
            throw new AccessDeniedHttpException(
                $errorMessage ?? 'Akses ditolak: Data tidak ditemukan atau bukan milik sekolah Anda.'
            );
        }
    }

    /**
     * Validasi berdasarkan ID
     */
    protected function validateSchoolOwnershipById(
        string $modelClass, 
        int $id, 
        string $errorMessage = null
    ): Model {
        $model = $modelClass::findOrFail($id);
        $this->validateSchoolOwnership($model, $errorMessage);
        
        return $model;
    }
}
```

### Penerapan di Controller

#### AttendanceController
```php
class AttendanceController extends Controller
{
    use ValidatesSchoolOwnership;

    public function manual(ManualAttendanceRequest $request)
    {
        $validated = $request->validated();

        // SECURITY: Validate student and schedule belong to same school
        $student = $this->validateSchoolOwnershipById(
            \App\Models\User::class,
            $validated['student_id'],
            'Siswa tidak ditemukan atau bukan milik sekolah Anda.'
        );

        $schedule = $this->validateSchoolOwnershipById(
            \App\Models\Schedule::class,
            $validated['schedule_id'],
            'Jadwal tidak ditemukan atau bukan milik sekolah Anda.'
        );

        // ... rest of the code
    }
}
```

#### QrCodeController
```php
class QrCodeController extends Controller
{
    use ValidatesSchoolOwnership;

    public function generate(GenerateQrRequest $request)
    {
        $schedule = Schedule::findOrFail($request->input('schedule_id'));

        // SECURITY: Validate schedule belongs to same school
        $this->validateSchoolOwnership(
            $schedule, 
            'Jadwal tidak ditemukan atau bukan milik sekolah Anda.'
        );

        // ... rest of the code
    }
}
```

## 🧪 Testing

### Test Suite: MultiTenantSecurityTest

Test suite ini memverifikasi bahwa:

1. **Admin tidak bisa membuat absensi manual untuk siswa dari sekolah lain**
2. **Admin tidak bisa membuat absensi manual untuk jadwal dari sekolah lain**
3. **Guru tidak bisa generate QR untuk jadwal dari sekolah lain**
4. **Model User otomatis ter-scope berdasarkan school_id**
5. **Model Attendance otomatis ter-scope berdasarkan school_id**
6. **Super Admin tetap bisa mengakses semua data**

### Menjalankan Test

```bash
php artisan test --filter=MultiTenantSecurityTest
```

## 📊 Dampak Perbaikan

### Keamanan
- ✅ **Eliminasi Cross-School Access**: Tidak ada lagi kemungkinan akses data antar sekolah
- ✅ **Automatic Scoping**: Semua query otomatis difilter berdasarkan school_id
- ✅ **Explicit Validation**: Validasi eksplisit pada endpoint yang menerima ID

### Performa
- ✅ **Query Optimization**: Global scope mengurangi kemungkinan full table scan
- ✅ **Index Utilization**: Query otomatis menggunakan index pada school_id

### Maintainability
- ✅ **DRY Principle**: Validasi terpusat di trait, tidak perlu duplikasi kode
- ✅ **Consistent Error Messages**: Pesan error yang konsisten di seluruh aplikasi
- ✅ **Easy to Test**: Trait mudah di-test secara isolated

## 🔍 Checklist Keamanan

### Models
- [x] User model menggunakan BelongsToSchool trait
- [x] Attendance model menggunakan BelongsToSchool trait
- [x] Schedule model menggunakan BelongsToSchool trait (sudah ada)
- [x] QrCode model menggunakan BelongsToSchool trait (sudah ada)

### Controllers
- [x] AttendanceController memvalidasi kepemilikan data
- [x] QrCodeController memvalidasi kepemilikan data
- [x] StudentController menggunakan explicit where school_id (sudah aman)
- [x] TeacherController menggunakan explicit where school_id (sudah aman)

### Testing
- [x] Test untuk cross-school access prevention
- [x] Test untuk automatic scoping
- [x] Test untuk super admin bypass

## 🚀 Best Practices

### Saat Menambahkan Endpoint Baru

1. **Selalu gunakan trait ValidatesSchoolOwnership** di controller
2. **Validasi semua ID yang diterima dari user**:
   ```php
   $student = $this->validateSchoolOwnershipById(
       User::class, 
       $request->student_id
   );
   ```
3. **Jangan pernah query tanpa filter school_id** kecuali menggunakan model dengan BelongsToSchool trait
4. **Tulis test untuk setiap endpoint baru** yang menerima ID

### Saat Menambahkan Model Baru

1. **Tambahkan trait BelongsToSchool** jika model memiliki kolom school_id:
   ```php
   class NewModel extends Model
   {
       use BelongsToSchool;
   }
   ```
2. **Pastikan migration memiliki kolom school_id**
3. **Tambahkan foreign key constraint** untuk referential integrity

## 📝 Notes

- Super Admin (role: 'super-admin') **bypass** semua scoping untuk keperluan administrasi platform
- Scoping otomatis hanya berlaku untuk **authenticated users**
- Validasi dilakukan di **application layer** (controller) dan **database layer** (global scope)

## 🔗 Related Files

- `app/Traits/BelongsToSchool.php` - Trait untuk automatic scoping
- `app/Traits/ValidatesSchoolOwnership.php` - Trait untuk validasi kepemilikan
- `app/Scopes/SchoolScope.php` - Global scope untuk filtering
- `tests/Feature/MultiTenantSecurityTest.php` - Security test suite

## 📅 Changelog

### 2026-01-27
- ✅ Fixed syntax error di Attendance model
- ✅ Added BelongsToSchool trait ke User model
- ✅ Created ValidatesSchoolOwnership trait
- ✅ Applied validation di AttendanceController
- ✅ Applied validation di QrCodeController
- ✅ Created comprehensive security tests
- ✅ Created factory untuk Schedule dan Attendance
