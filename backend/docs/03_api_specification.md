# API Specification - Sistem Absensi QR Code

## API Overview

**Base URL:** `https://api.absensi-sekolah.com/api/v1`  
**Authentication:** Bearer Token (Laravel Sanctum)  
**Response Format:** JSON  
**Rate Limit:** 60 requests/minute per user

## Authentication Endpoints

### POST /auth/login

Login untuk semua role.

**Request:**
```json
{
  "username": "guru001",
  "password": "password123",
  "device_name": "Android - Samsung Galaxy S21"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "token": "1|abc123...",
    "token_type": "Bearer",
    "user": {
      "id": 123,
      "username": "guru001",
      "email": "guru@sekolah.com",
      "role": "teacher",
      "school_id": 1,
      "profile": {
        "full_name": "Budi Santoso",
        "nip": "197801012006041003",
        "photo_url": "https://..."
      }
    },
    "permissions": ["attendance.scan", "attendance.manual_input"]
  }
}
```

### POST /auth/logout

Revoke current token.

**Headers:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

### POST /auth/refresh

Refresh token (optional).

---

## Attendance Endpoints

### POST /attendance/scan

Scan QR code untuk absensi.

**Headers:** `Authorization: Bearer {token}`

**Request:**
```json
{
  "qr_token": "encrypted_token_here",
  "latitude": -6.200000,
  "longitude": 106.816666,
  "location_accuracy": 15.5,
  "device_info": {
    "device_id": "abc123",
    "os": "Android 13",
    "app_version": "1.0.0"
  }
}
```

**Response (200 - Success):**
```json
{
  "success": true,
  "message": "Absensi berhasil direkam",
  "data": {
    "attendance_id": 456,
    "student": {
      "id": 789,
      "name": "Ahmad Fauzi",
      "nisn": "0012345678"
    },
    "schedule": {
      "id": 101,
      "subject": "Matematika",
      "class": "VII-A",
      "time": "07:00 - 08:30"
    },
    "status": "present",
    "check_in_time": "2024-01-15T07:05:23+07:00",
    "is_late": false
  }
}
```

**Response (400 - QR Expired):**
```json
{
  "success": false,
  "error": "QR_EXPIRED",
  "message": "QR Code sudah kadaluarsa"
}
```

**Response (400 - Location Invalid):**
```json
{
  "success": false,
  "error": "LOCATION_INVALID",
  "message": "Lokasi Anda di luar area sekolah",
  "details": {
    "distance_meters": 350,
    "max_radius": 100
  }
}
```

**Response (409 - Already Scanned):**
```json
{
  "success": false,
  "error": "ALREADY_SCANNED",
  "message": "Anda sudah absen untuk jadwal ini",
  "data": {
    "attendance_id": 456,
    "check_in_time": "2024-01-15T07:05:23+07:00"
  }
}
```

### POST /attendance/manual

Input absensi manual oleh guru.

**Headers:** `Authorization: Bearer {token}`  
**Permission:** `attendance.manual_input`

**Request:**
```json
{
  "schedule_id": 101,
  "student_id": 789,
  "attendance_date": "2024-01-15",
  "status": "sick",
  "notes": "Sakit demam, ada surat dokter",
  "attachment_url": "https://storage/surat-sakit.pdf"
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "Absensi manual berhasil dicatat",
  "data": {
    "attendance_id": 457,
    "student_name": "Ahmad Fauzi",
    "status": "sick",
    "recorded_by": "Budi Santoso",
    "is_manual": true
  }
}
```

### GET /attendance/schedule/{schedule_id}

Get attendance list untuk jadwal tertentu.

**Headers:** `Authorization: Bearer {token}`

**Query Params:**
- `date` (optional): YYYY-MM-DD (default: today)

**Response (200):**
```json
{
  "success": true,
  "data": {
    "schedule": {
      "id": 101,
      "subject": "Matematika",
      "class": "VII-A",
      "teacher": "Budi Santoso",
      "time": "07:00 - 08:30",
      "date": "2024-01-15"
    },
    "statistics": {
      "total_students": 30,
      "present": 25,
      "late": 2,
      "absent": 3,
      "sick": 0,
      "permit": 0,
      "attendance_rate": 90.0
    },
    "students": [
      {
        "id": 789,
        "nisn": "0012345678",
        "name": "Ahmad Fauzi",
        "attendance": {
          "status": "present",
          "check_in_time": "2024-01-15T07:05:23+07:00",
          "is_manual": false
        }
      },
      {
        "id": 790,
        "nisn": "0012345679",
        "name": "Siti Nurhaliza",
        "attendance": null
      }
    ]
  }
}
```

### GET /attendance/student/{student_id}

Get attendance history siswa.

**Headers:** `Authorization: Bearer {token}`

**Query Params:**
- `start_date`: YYYY-MM-DD (required)
- `end_date`: YYYY-MM-DD (required)
- `class_id` (optional)

**Response (200):**
```json
{
  "success": true,
  "data": {
    "student": {
      "id": 789,
      "name": "Ahmad Fauzi",
      "nisn": "0012345678",
      "class": "VII-A"
    },
    "period": {
      "start_date": "2024-01-01",
      "end_date": "2024-01-31"
    },
    "summary": {
      "total_days": 20,
      "present": 17,
      "late": 1,
      "absent": 2,
      "sick": 0,
      "permit": 0,
      "attendance_rate": 90.0
    },
    "records": [
      {
        "date": "2024-01-15",
        "schedule": "Matematika",
        "status": "present",
        "check_in_time": "07:05:23",
        "is_manual": false
      }
    ]
  }
}
```

---

## QR Code Management

### POST /qr/generate

Generate QR code untuk schedule.

**Headers:** `Authorization: Bearer {token}`  
**Permission:** `school.qr_generate`

**Request:**
```json
{
  "schedule_id": 101,
  "qr_type": "in",
  "valid_from": "2024-01-15 06:45:00",
  "valid_until": "2024-01-15 08:30:00",
  "max_scans": null,
  "location_required": true
}
```

**Response (201):**
```json
{
  "success": true,
  "data": {
    "qr_id": 555,
    "token": "encrypted_token",
    "qr_image_url": "https://storage/qr/555.png",
    "qr_image_base64": "data:image/png;base64,...",
    "valid_from": "2024-01-15T06:45:00+07:00",
    "valid_until": "2024-01-15T08:30:00+07:00",
    "expires_in_minutes": 105
  }
}
```

### GET /qr/{qr_id}

Get QR code details.

**Response (200):**
```json
{
  "success": true,
  "data": {
    "qr_id": 555,
    "qr_image_url": "https://storage/qr/555.png",
    "schedule": {
      "subject": "Matematika",
      "class": "VII-A",
      "time": "07:00 - 08:30"
    },
    "is_active": true,
    "valid_until": "2024-01-15T08:30:00+07:00",
    "scan_count": 25,
    "max_scans": null
  }
}
```

### DELETE /qr/{qr_id}

Deactivate QR code.

**Response (200):**
```json
{
  "success": true,
  "message": "QR Code berhasil dinonaktifkan"
}
```

---

## Reports

### GET /reports/daily

Daily attendance report.

**Headers:** `Authorization: Bearer {token}`  
**Permission:** `reports.view_all`

**Query Params:**
- `date`: YYYY-MM-DD (default: today)
- `class_id` (optional)

**Response (200):**
```json
{
  "success": true,
  "data": {
    "date": "2024-01-15",
    "school_name": "SMP Negeri 1",
    "summary": {
      "total_students": 300,
      "total_present": 270,
      "total_late": 15,
      "total_absent": 15,
      "attendance_rate": 95.0
    },
    "by_class": [
      {
        "class_id": 10,
        "class_name": "VII-A",
        "total_students": 30,
        "present": 27,
        "late": 1,
        "absent": 2,
        "attendance_rate": 93.3
      }
    ]
  }
}
```

### GET /reports/student/{student_id}/summary

Student attendance summary report.

**Query Params:**
- `period`: 'weekly', 'monthly', 'semester', 'yearly'
- `start_date` (optional)
- `end_date` (optional)

**Response (200):**
```json
{
  "success": true,
  "data": {
    "student": {
      "id": 789,
      "name": "Ahmad Fauzi",
      "class": "VII-A"
    },
    "period": {
      "type": "monthly",
      "start_date": "2024-01-01",
      "end_date": "2024-01-31"
    },
    "summary": {
      "total_days": 20,
      "present": 17,
      "late": 1,
      "absent": 2,
      "attendance_rate": 90.0
    },
    "trend": [
      {
        "week": "Week 1",
        "attendance_rate": 100
      },
      {
        "week": "Week 2",
        "attendance_rate": 85
      }
    ]
  }
}
```

### POST /reports/export

Export report to PDF/Excel.

**Request:**
```json
{
  "report_type": "class_monthly",
  "class_id": 10,
  "start_date": "2024-01-01",
  "end_date": "2024-01-31",
  "format": "pdf"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "file_url": "https://storage/reports/class-10-jan-2024.pdf",
    "file_name": "Laporan_VII-A_Januari_2024.pdf",
    "expires_at": "2024-01-16T12:00:00+07:00"
  }
}
```

---

## User Management

### GET /users/me

Get current user profile.

**Response (200):**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "username": "guru001",
    "email": "guru@sekolah.com",
    "role": "teacher",
    "school": {
      "id": 1,
      "name": "SMP Negeri 1",
      "npsn": "12345678"
    },
    "profile": {
      "full_name": "Budi Santoso",
      "nip": "197801012006041003",
      "phone": "081234567890",
      "photo_url": "https://..."
    },
    "permissions": ["attendance.scan", "attendance.manual_input"]
  }
}
```

### PUT /users/me

Update current user profile.

**Request:**
```json
{
  "phone": "081234567890",
  "photo_url": "https://...",
  "device_token": "fcm_token_here"
}
```

---

## Schedules

### GET /schedules

Get schedules.

**Query Params:**
- `class_id` (optional)
- `teacher_id` (optional)
- `day_of_week` (optional): 'monday'-'sunday'
- `date` (optional): YYYY-MM-DD

**Response (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 101,
      "class": "VII-A",
      "subject": "Matematika",
      "teacher": "Budi Santoso",
      "day_of_week": "monday",
      "start_time": "07:00",
      "end_time": "08:30",
      "room": "R-101"
    }
  ]
}
```

---

## Error Response Format

All errors follow this structure:

```json
{
  "success": false,
  "error": "ERROR_CODE",
  "message": "Human readable error message",
  "details": {}
}
```

**Common Error Codes:**
- `UNAUTHORIZED` (401)
- `FORBIDDEN` (403)
- `NOT_FOUND` (404)
- `VALIDATION_ERROR` (422)
- `QR_EXPIRED` (400)
- `QR_INVALID` (400)
- `LOCATION_INVALID` (400)
- `ALREADY_SCANNED` (409)
- `RATE_LIMIT_EXCEEDED` (429)
- `SERVER_ERROR` (500)

---

## Webhooks (Optional Future)

Untuk notifikasi real-time ke sistem eksternal.

### Events:
- `attendance.created`
- `attendance.updated`
- `qr.generated`
- `report.generated`
