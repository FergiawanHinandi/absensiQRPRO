# Super Admin Features Documentation

## Overview
Dokumentasi lengkap fitur-fitur Super Admin yang telah diimplementasikan untuk meningkatkan kontrol penuh terhadap platform AbsensiQRPro.

---

## 1. 🕵️ Impersonate (Login As)

### Deskripsi
Fitur yang memungkinkan Super Admin untuk login sebagai Admin Sekolah tanpa memerlukan password. Sangat berguna untuk troubleshooting dan customer support.

### Endpoint
```
POST /api/v1/super-admin/schools/{id}/impersonate
```

### Request
- **Headers**: `Authorization: Bearer {super_admin_token}`
- **Path Parameter**: `id` - ID sekolah yang ingin di-impersonate

### Response Success
```json
{
  "success": true,
  "message": "Impersonation verified.",
  "data": {
    "token": "new_token_for_school_admin",
    "user": {
      "id": 123,
      "name": "Admin Sekolah",
      "email": "admin@sekolah.com",
      "role_type": "admin",
      "school_id": 5,
      "school_name": "SMA Negeri 1 Jakarta"
    }
  }
}
```

### Cara Menggunakan (Frontend)
1. Klik tombol "Login" (ikon LogIn) di tabel Manajemen Sekolah
2. Konfirmasi dialog
3. Token dan user data otomatis tersimpan di localStorage
4. Redirect ke `/admin/dashboard`
5. Untuk kembali ke Super Admin, logout dan login ulang

### Audit Trail
Setiap impersonation dicatat di tabel `audit_logs` dengan action `impersonate_school`.

---

## 2. 📢 Broadcast Announcements

### Deskripsi
Sistem pengumuman global yang memungkinkan Super Admin mengirim notifikasi/informasi penting ke semua user atau role tertentu.

### Database Schema
**Table**: `announcements`
- `id`: Primary key
- `title`: Judul pengumuman
- `content`: Isi pengumuman (text)
- `type`: Enum ('info', 'warning', 'critical', 'success')
- `target_role`: Enum ('all', 'admin', 'school_admin', 'teacher', 'student')
- `is_active`: Boolean
- `expires_at`: Datetime (nullable)
- `timestamps`

### Endpoints

#### A. Create Announcement (Super Admin Only)
```
POST /api/v1/super-admin/announcements
```

**Request Body**:
```json
{
  "title": "Maintenance Server Malam Ini",
  "content": "Server akan maintenance pada 23:00 - 01:00 WIB. Mohon tidak melakukan absensi pada jam tersebut.",
  "type": "warning",
  "target_role": "all",
  "expires_at": "2026-01-22 01:00:00",
  "is_active": true
}
```

#### B. Get All Announcements (Super Admin Only)
```
GET /api/v1/super-admin/announcements?page=1&search=maintenance
```

#### C. Update Announcement
```
PUT /api/v1/super-admin/announcements/{id}
```

#### D. Delete Announcement
```
DELETE /api/v1/super-admin/announcements/{id}
```

#### E. Get Active Announcements (All Authenticated Users)
```
GET /api/v1/broadcasts
```

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Maintenance Server Malam Ini",
      "content": "Server akan maintenance...",
      "type": "warning",
      "target_role": "all",
      "created_at": "2026-01-21T14:00:00.000000Z"
    }
  ]
}
```

### Filtering Logic
- User dengan role `admin` atau `school_admin` akan melihat announcement dengan `target_role`: `all`, `admin`, atau `school_admin`
- User dengan role lain hanya melihat `all` dan role mereka sendiri
- Hanya announcement dengan `is_active = true` dan belum expired yang ditampilkan

### Use Cases
- Notifikasi maintenance
- Pengumuman fitur baru
- Peringatan sistem
- Update kebijakan platform

---

## 3. 💾 Database Backup Manager

### Deskripsi
Fitur untuk download backup database PostgreSQL dalam format SQL dump. Penting untuk disaster recovery.

### Endpoint
```
GET /api/v1/super-admin/system/backup
```

### Request
- **Headers**: `Authorization: Bearer {super_admin_token}`

### Response
- **Content-Type**: `application/octet-stream`
- **File**: `backup_YYYY-MM-DD_HHMMSS.sql`

### Cara Kerja
1. System membuat direktori `storage/app/backups/` jika belum ada
2. Menggunakan `pg_dump` command untuk export database
3. File disimpan sementara dengan nama timestamp
4. File di-stream sebagai download
5. File otomatis dihapus setelah download selesai
6. Aktivitas dicatat di audit logs

### Requirements
- PostgreSQL client tools (`pg_dump`) harus terinstall di server
- Environment variables DB credentials harus benar

### Audit Trail
Action: `database_backup`

---

## 4. 🛡️ Maintenance Mode Manager

### Deskripsi
Fitur untuk mengaktifkan/menonaktifkan maintenance mode pada aplikasi. Saat aktif, semua user (kecuali Super Admin dengan bypass token) tidak bisa mengakses aplikasi.

### Endpoints

#### A. Toggle Maintenance Mode
```
POST /api/v1/super-admin/system/maintenance
```

**Request Body**:
```json
{
  "enable": true
}
```

**Response**:
```json
{
  "success": true,
  "message": "Maintenance mode enabled",
  "data": {
    "maintenance_mode": true
  }
}
```

#### B. Get Maintenance Status
```
GET /api/v1/super-admin/system/maintenance/status
```

**Response**:
```json
{
  "success": true,
  "data": {
    "maintenance_mode": false,
    "status": "up"
  }
}
```

### Bypass Token
Super Admin dapat mengakses aplikasi saat maintenance mode dengan URL:
```
https://yourdomain.com/{bypass-secret}
```

Default secret: `super-admin-bypass-token`

### Audit Trail
- Action: `maintenance_mode_enabled`
- Action: `maintenance_mode_disabled`

---

## 5. 📊 Enhanced Dashboard

### Fitur yang Ditambahkan
1. **Real Revenue Tracking**: Data revenue dari tabel `payments` (bukan dummy)
2. **Real System Health**: Status database berdasarkan koneksi aktual
3. **Audit Logs Widget**: Menampilkan 5 aktivitas terbaru dari sistem

### Endpoint
```
GET /api/v1/super-admin/dashboard/stats
```

### Response (Updated)
```json
{
  "success": true,
  "data": {
    "stats": {
      "total_schools": 25,
      "school_growth": 12.5,
      "total_students": 5420,
      "total_teachers": 320,
      "attendance_today": 4850,
      "revenue_this_month": 125000000
    },
    "recent_schools": [...],
    "schools_by_level": {...},
    "top_schools": [...],
    "attendance_chart": [...],
    "system_status": [
      {
        "service": "API Server",
        "status": "operational",
        "uptime": "99.98%"
      },
      {
        "service": "Database",
        "status": "operational",
        "uptime": "99.95%"
      }
    ],
    "recent_activities": [
      {
        "id": 1,
        "user": "Super Admin",
        "school": "SMA Negeri 1",
        "action": "create_school",
        "description": "Created new school: SMA Negeri 1",
        "time": "2 minutes ago"
      }
    ]
  }
}
```

---

## 6. 📝 Automatic Audit Logging

### Deskripsi
Sistem otomatis mencatat semua aktivitas penting ke tabel `audit_logs` menggunakan Observer dan Event Listener.

### Events yang Dicatat

#### A. School Events (SchoolObserver)
- `create_school`: Saat sekolah baru dibuat
- `update_school`: Saat data sekolah diupdate
- `delete_school`: Saat sekolah dihapus

#### B. User Events (UserObserver)
- `create_user`: Saat user baru dibuat
- `update_user`: Saat data user diupdate
- `delete_user`: Saat user dihapus

#### C. Login Event (LogSuccessfulLogin Listener)
- `user_login`: Setiap kali user berhasil login

#### D. Payment Events (WebhookController)
- `payment_update`: Saat status pembayaran berubah

#### E. System Events
- `impersonate_school`: Saat Super Admin impersonate
- `database_backup`: Saat download backup
- `maintenance_mode_enabled/disabled`: Saat toggle maintenance

### Data yang Dicatat
- `user_id`: User yang melakukan aksi (nullable untuk system events)
- `school_id`: Sekolah terkait (nullable)
- `action`: Jenis aksi
- `description`: Deskripsi detail
- `ip_address`: IP address
- `user_agent`: Browser/client info
- `created_at`: Timestamp

---

## 7. 💳 Payment Webhook Integration

### Deskripsi
Endpoint untuk menerima notifikasi dari Payment Gateway (Midtrans/Xendit) dan otomatis update status pembayaran.

### Endpoint
```
POST /api/v1/webhooks/payment
```

**Note**: Endpoint ini PUBLIC (tidak perlu authentication) karena dipanggil oleh payment gateway.

### Request Body (Simulasi)
```json
{
  "transaction_id": "TRX-20260121-001",
  "status": "paid",
  "amount": 500000
}
```

### Status Mapping
- `settlement`, `capture`, `paid` → `paid`
- `deny`, `cancel`, `expire`, `failed` → `failed`
- Default → `pending`

### Cara Kerja
1. Terima webhook dari payment gateway
2. Log payload untuk debugging
3. Cari record `Payment` berdasarkan `transaction_id`
4. Update status dan `payment_date` (jika paid)
5. Catat ke audit logs
6. Return success response

---

## Security Considerations

### 1. Role-Based Access Control
- Semua endpoint Super Admin dilindungi middleware `role:super_admin`
- Impersonate hanya bisa dilakukan oleh Super Admin
- Backup dan Maintenance Mode hanya Super Admin

### 2. Audit Trail
- Semua aksi sensitif dicatat dengan IP dan User Agent
- Tidak bisa dihapus (soft delete recommended untuk production)

### 3. Maintenance Mode Bypass
- Gunakan secret token yang kuat
- Jangan share bypass URL ke user biasa

### 4. Database Backup
- File backup otomatis dihapus setelah download
- Simpan backup di tempat aman (cloud storage)

---

## Frontend Integration Guide

### 1. Impersonate Button
Lihat implementasi di: `frontend-web/src/pages/SuperAdmin/SchoolsManagement.tsx`

```typescript
const handleImpersonate = async (id: number, name: string) => {
    if (window.confirm(`Login sebagai Admin untuk sekolah "${name}"?`)) {
        const response = await apiClient.post(`/super-admin/schools/${id}/impersonate`);
        if (response.data.success) {
            localStorage.setItem('token', response.data.data.token);
            localStorage.setItem('user', JSON.stringify(response.data.data.user));
            window.location.href = '/admin/dashboard';
        }
    }
};
```

### 2. Announcements Widget
Fetch di dashboard:
```typescript
const fetchAnnouncements = async () => {
    const response = await apiClient.get('/broadcasts');
    setAnnouncements(response.data.data);
};
```

### 3. Backup Download
```typescript
const downloadBackup = async () => {
    const response = await apiClient.get('/super-admin/system/backup', {
        responseType: 'blob'
    });
    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `backup_${new Date().toISOString()}.sql`);
    document.body.appendChild(link);
    link.click();
};
```

### 4. Maintenance Mode Toggle
```typescript
const toggleMaintenance = async (enable: boolean) => {
    await apiClient.post('/super-admin/system/maintenance', { enable });
    // Refresh status
    fetchMaintenanceStatus();
};
```

---

## Testing Checklist

### Impersonate
- [ ] Super Admin bisa impersonate sekolah yang punya admin aktif
- [ ] Error 404 jika sekolah tidak punya admin
- [ ] Token baru valid dan bisa akses dashboard admin
- [ ] Audit log tercatat

### Announcements
- [ ] Super Admin bisa create/update/delete announcement
- [ ] User melihat announcement sesuai role mereka
- [ ] Expired announcement tidak muncul
- [ ] Inactive announcement tidak muncul

### Backup
- [ ] File SQL ter-download dengan benar
- [ ] File berisi data lengkap
- [ ] Audit log tercatat
- [ ] File temporary terhapus setelah download

### Maintenance Mode
- [ ] Mode aktif memblokir akses user biasa
- [ ] Super Admin bisa bypass dengan secret URL
- [ ] Toggle on/off berfungsi
- [ ] Status API akurat

---

## Troubleshooting

### Backup Gagal
**Error**: `pg_dump: command not found`
**Solution**: Install PostgreSQL client tools di server

### Maintenance Mode Tidak Bekerja
**Error**: User masih bisa akses
**Solution**: Clear cache Laravel: `php artisan cache:clear`

### Impersonate Error 404
**Error**: "Tidak ditemukan user Admin aktif"
**Solution**: Pastikan sekolah memiliki user dengan `role_type = 'admin'` atau `'school_admin'` dan `is_active = true`

---

## Future Enhancements

1. **Scheduled Backups**: Otomatis backup setiap hari via cron
2. **Cloud Backup**: Upload ke S3/Google Cloud Storage
3. **Announcement Templates**: Template siap pakai untuk pengumuman umum
4. **Multi-language Announcements**: Support bahasa Indonesia dan Inggris
5. **Push Notifications**: Kirim notifikasi real-time via WebSocket
6. **Backup Restore**: Fitur untuk restore dari backup file

---

**Last Updated**: 2026-01-21
**Version**: 1.0.0
