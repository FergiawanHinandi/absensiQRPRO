# Secure Admin Commands Documentation

## 🔒 Overview
Dokumen ini menjelaskan Artisan Commands yang aman untuk menggantikan script PHP berbahaya yang sebelumnya ada di root backend.

## ⚠️ Security Improvements

### ❌ SEBELUM (Tidak Aman)
```
backend/
├── check_admin.php          ⚠️ Bisa diakses via HTTP
├── reset_admin_passwords.php ⚠️ Tidak ada konfirmasi
├── delete_test.php          ⚠️ Tidak ada logging
└── check_*.php              ⚠️ Tidak ada audit trail
```

**Risiko**:
- File bisa diakses via browser jika server salah konfigurasi
- Tidak ada konfirmasi untuk operasi berbahaya
- Tidak ada logging untuk audit trail
- Tidak ada validasi input

### ✅ SESUDAH (Aman)
```
app/Console/Commands/
├── CheckAdminAccounts.php    ✅ CLI only
├── ResetAdminPassword.php    ✅ Dengan konfirmasi
└── DeleteTestSchool.php      ✅ Dengan logging
```

**Keamanan**:
- ✅ Hanya bisa dijalankan via CLI (tidak bisa via HTTP)
- ✅ Meminta konfirmasi untuk operasi berbahaya
- ✅ Semua aksi tercatat di activity log
- ✅ Validasi input yang ketat
- ✅ Safety checks untuk mencegah kesalahan

---

## 📋 Available Commands

### 1. Check Admin Accounts

**Command**: `php artisan admin:check`

**Deskripsi**: Melihat daftar admin accounts dan statusnya.

**Usage**:
```bash
# Check all admin accounts
php artisan admin:check

# Check only school admins
php artisan admin:check --role=school_admin

# Check admins for specific school
php artisan admin:check --school=1

# Combine filters
php artisan admin:check --role=school_admin --school=1
```

**Output**:
```
🔍 Checking admin accounts...

Found 3 admin account(s):

+----+---------------+---------------------------+-------------+--------+-----------+------------------+--------------+
| ID | Name          | Email                     | Role        | Active | School ID | School Name      | School Active|
+----+---------------+---------------------------+-------------+--------+-----------+------------------+--------------+
| 1  | Super Admin   | superadmin@absensi.com    | super_admin | ✅ Yes | N/A       | N/A              | N/A          |
| 2  | Admin SD      | admin@sdmongisidi.sch.id  | school_admin| ✅ Yes | 1         | SD Mongisidi     | ✅           |
| 3  | Admin SMP     | admin@smpdemo.com         | school_admin| ❌ No  | 2         | SMP Demo         | ✅           |
+----+---------------+---------------------------+-------------+--------+-----------+------------------+--------------+
```

**Security Features**:
- ✅ Read-only operation (tidak mengubah data)
- ✅ Logged untuk audit trail
- ✅ CLI only

---

### 2. Reset Admin Password

**Command**: `php artisan admin:reset-password`

**Deskripsi**: Reset password admin dengan aman.

**Usage**:
```bash
# Interactive mode (recommended)
php artisan admin:reset-password admin@example.com

# With password option (not recommended for production)
php artisan admin:reset-password admin@example.com --password=newpassword123

# Skip confirmation (dangerous!)
php artisan admin:reset-password admin@example.com --force
```

**Interactive Flow**:
```
📋 User Information:
+-------------+---------------------------+
| Field       | Value                     |
+-------------+---------------------------+
| ID          | 2                         |
| Name        | Admin SD                  |
| Email       | admin@sdmongisidi.sch.id  |
| Role        | school_admin              |
| School ID   | 1                         |
| School Name | SD Mongisidi              |
| Active      | ✅ Yes                    |
+-------------+---------------------------+

Enter new password:
> ********

Confirm new password:
> ********

⚠️  WARNING: You are about to reset the password for this admin account.
Do you want to continue? (yes/no) [no]:
> yes

✅ Password successfully reset for 'admin@sdmongisidi.sch.id'

🔐 IMPORTANT: This action has been logged for security audit.
```

**Security Features**:
- ✅ Email validation
- ✅ Role verification (hanya admin yang bisa direset)
- ✅ Password confirmation
- ✅ Minimum 8 characters
- ✅ Requires confirmation (kecuali --force)
- ✅ Comprehensive logging dengan IP address
- ✅ CLI only

**Activity Log**:
```json
{
  "command": "admin:reset-password",
  "email": "admin@sdmongisidi.sch.id",
  "user_id": 2,
  "role": "school_admin",
  "school_id": 1,
  "ip": "CLI"
}
```

---

### 3. Delete Test School

**Command**: `php artisan school:delete-test`

**Deskripsi**: Menghapus sekolah test/dummy beserta semua data terkait.

**Usage**:
```bash
# Delete by school ID
php artisan school:delete-test --id=5

# Delete by school name (partial match)
php artisan school:delete-test --name="Dummy"

# Skip confirmation (very dangerous!)
php artisan school:delete-test --id=5 --force
```

**Interactive Flow**:
```
📋 School Information:
+----------+--------------------------------+
| Field    | Value                          |
+----------+--------------------------------+
| ID       | 5                              |
| Name     | Sekolah Dummy (Siap Hapus)     |
| Email    | dummy@example.com              |
| Phone    | N/A                            |
| Active   | ✅ Yes                         |
| Package  | basic                          |
| Created  | 2026-01-20 10:30:00            |
+----------+--------------------------------+

⚠️  Related data that will be deleted:
+--------------------------------------+-------+
| Type                                 | Count |
+--------------------------------------+-------+
| Users (Students, Teachers, Admins)   | 15    |
| Classes                              | 3     |
| Schedules                            | 12    |
| Attendance Records                   | 45    |
| QR Codes                             | 8     |
+--------------------------------------+-------+

🚨 DANGER ZONE 🚨
This action is IRREVERSIBLE and will delete ALL related data!

Are you absolutely sure you want to delete this school? (yes/no) [no]:
> yes

Type the school name to confirm:
> Sekolah Dummy (Siap Hapus)

🗑️  Deleting school and related data...
✅ School 'Sekolah Dummy (Siap Hapus)' (ID: 5) has been deleted successfully.
🔐 This action has been logged for security audit.
```

**Security Features**:
- ✅ Requires either --id or --name
- ✅ Shows all related data before deletion
- ✅ **Safety check**: Prevents deletion if users > 100 or attendances > 1000
- ✅ Double confirmation required
- ✅ Must type exact school name to confirm
- ✅ Transaction-based (rollback on error)
- ✅ Comprehensive logging
- ✅ CLI only

**Safety Check Example**:
```
❌ SAFETY CHECK FAILED: This school has too much data to be a test school.
   Users: 250 | Attendances: 1500
   If you really need to delete this, please do it manually via database.
```

**Activity Log**:
```json
{
  "command": "school:delete-test",
  "school_id": 5,
  "school_name": "Sekolah Dummy (Siap Hapus)",
  "stats": {
    "users": 15,
    "classes": 3,
    "schedules": 12,
    "attendances": 45,
    "qr_codes": 8
  },
  "ip": "CLI"
}
```

---

## 🔍 Audit Trail

Semua command ini terintegrasi dengan **Spatie Activity Log**. Anda bisa melihat log di database:

```sql
SELECT * FROM activity_log 
WHERE description LIKE '%CLI command%' 
ORDER BY created_at DESC;
```

Atau via Artisan:
```bash
php artisan tinker
>>> \Spatie\Activitylog\Models\Activity::latest()->take(10)->get();
```

---

## 🚫 File yang Dihapus

File-file berbahaya berikut telah dihapus dari root backend:

- ❌ `check_admin.php`
- ❌ `reset_admin_passwords.php`
- ❌ `delete_test.php`
- ❌ `check_password.php`
- ❌ `check_status.php`
- ❌ `check_users.php`
- ❌ `test_toggle.php`

**Alasan penghapusan**:
1. Bisa diakses via HTTP jika server salah konfigurasi
2. Tidak ada mekanisme autentikasi
3. Tidak ada logging
4. Tidak ada konfirmasi untuk operasi berbahaya
5. Hardcoded credentials/data

---

## 🎯 Best Practices

### DO ✅
- Selalu gunakan Artisan commands untuk operasi admin
- Review output sebelum konfirmasi
- Gunakan `--help` untuk melihat opsi yang tersedia
- Periksa activity log secara berkala

### DON'T ❌
- Jangan gunakan `--force` di production tanpa review
- Jangan hardcode password di command line (gunakan interactive mode)
- Jangan membuat script PHP di root project
- Jangan skip konfirmasi untuk operasi berbahaya

---

## 📝 Examples

### Scenario 1: Lupa Password Admin
```bash
# Step 1: Check admin account
php artisan admin:check --role=school_admin

# Step 2: Reset password (interactive)
php artisan admin:reset-password admin@sdmongisidi.sch.id
```

### Scenario 2: Cleanup Test Data
```bash
# Step 1: Find test schools
php artisan admin:check --school=5

# Step 2: Delete test school
php artisan school:delete-test --id=5
```

### Scenario 3: Audit Admin Accounts
```bash
# Check all admins
php artisan admin:check

# Check specific school
php artisan admin:check --school=1

# Check activity log
php artisan tinker
>>> \Spatie\Activitylog\Models\Activity::where('description', 'like', '%admin%')->latest()->get();
```

---

## 🔗 Related Files

- `app/Console/Commands/CheckAdminAccounts.php`
- `app/Console/Commands/ResetAdminPassword.php`
- `app/Console/Commands/DeleteTestSchool.php`

---

## 📅 Changelog

### 2026-01-27
- ✅ Created CheckAdminAccounts command
- ✅ Created ResetAdminPassword command
- ✅ Created DeleteTestSchool command
- ✅ Deleted all dangerous PHP scripts from root
- ✅ Integrated with Spatie Activity Log
- ✅ Added comprehensive safety checks
