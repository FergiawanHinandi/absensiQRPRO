# REST API Contract - AbsensiQRPro v1.0

## 1. Authentication Module

### Login
Untuk semua tipe user (Admin, Guru, Siswa).

*   **Endpoint**: `POST /api/v1/auth/login`
*   **Request Body**:
    ```json
    {
      "email": "guru@sekolah.com",
      "password": "password123",
      "device_id": "android_uuid_123" // Opsional untuk locking
    }
    ```
*   **Response (200)**:
    ```json
    {
      "success": true,
      "message": "Login berhasil",
      "data": {
        "token": "23|laravel_sanctum_token_string...",
        "user": {
          "id": 1,
          "name": "Budi Santoso",
          "role": "teacher",
          "school_id": 10
        }
      }
    }
    ```

### Get Current User (Me)
*   **Endpoint**: `GET /api/v1/auth/me`
*   **Headers**: `Authorization: Bearer <token>`
*   **Response**: Detail user object lengkap.

---

## 2. School Management (Super Admin)

### List Schools
*   **Endpoint**: `GET /api/v1/super-admin/schools`
*   **Query Params**: `page=1`, `search=NamaSekolah`
*   **Response**:
    ```json
    {
      "success": true,
      "data": [
        { "id": 1, "name": "SMA Negeri 1 Jakarta", "level": "SMA", "status": "active" }
      ],
      "meta": { "total": 50, "per_page": 10 }
    }
    ```

### Create School
*   **Endpoint**: `POST /api/v1/super-admin/schools`
*   **Body**: `{ "name": "SMK Telkom", "level": "SMK", "address": "Jl..." }`

---

## 3. Attendance Module (Core)

### Generate QR (Guru)
Guru membuat kode QR dinamis untuk kelas yang sedang diajar.

*   **Endpoint**: `POST /api/v1/teacher/qr/generate`
*   **Body**:
    ```json
    {
      "schedule_id": 105,
      "lat": -6.200, // Koordinat Guru (Optional untuk Dynamic Radius)
      "lng": 106.816,
      "radius": 50 // meter
    }
    ```
*   **Response**:
    ```json
    {
      "success": true,
      "data": {
        "qr_token": "v1|encrypted_string...",
        "valid_until": "2024-01-20T08:00:30Z"
      }
    }
    ```

### Scan QR (Siswa)
Siswa memindai QR code guru.

*   **Endpoint**: `POST /api/v1/attendance/scan`
*   **Body**:
    ```json
    {
      "token": "v1|encrypted_string...",
      "lat": -6.2001,
      "lng": 106.8161,
      "device_id": "android_uuid_123"
    }
    ```
*   **Response (200)**: `message: "Berhasil Absen: Hadir"`
*   **Response (400)**: `message: "Lokasi terlalu jauh (50m)"`

### Manual Attendance (Guru/Admin)
Untuk input manual jika siswa tidak membawa HP.

*   **Endpoint**: `POST /api/v1/teacher/attendance/manual`
*   **Body**:
    ```json
    {
      "student_id": 500,
      "schedule_id": 105,
      "status": "sick", // present, late, sick, permit, alpha
      "notes": "Sakit demam (Info WA)"
    }
    ```

### Teacher Permissions (Izin/Sakit)
*   **Endpoint**: `GET /api/v1/teacher/permissions` (List Request)
*   **Endpoint**: `POST /api/v1/teacher/permissions` (Create Request)
*   **Endpoint**: `PATCH /api/v1/teacher/permissions/{id}/status` (Approve/Reject)

---

## 4. Report Module

### Daily Recap (Guru Piket/Admin)
*   **Endpoint**: `GET /api/v1/reports/daily`
*   **Query**: `date=2024-01-20`
*   **Response**:
    ```json
    {
      "success": true,
      "data": {
        "date": "2024-01-20",
        "total_students": 1000,
        "stats": {
          "present": 950,
          "late": 10,
          "sick": 5,
          "permit": 5,
          "alpha": 30
        },
        "late_students": [
          { "name": "Budi", "class": "X-A", "time": "07:15" }
        ]
      }
    }
    ```

### Student History (Siswa/Ortu)
*   **Endpoint**: `GET /api/v1/student/attendance/history`
*   **Response**: List riwayat kehadiran bulan berjalan.
