# API Documentation - AbsensiQR Pro

## Base URL
- **Development**: `http://localhost:8000/api/v1`
- **Production**: `https://api.absensiQR.com/v1`

---

## Authentication

### Login
**Endpoint**: `POST /auth/login`

**Request Body**:
```json
{
  "username": "siswa001",
  "password": "password123",
  "device_id": "abc123xyz" // Optional, for mobile device binding
}
```

**Success Response (201)**:
```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "token": "1|abc123...",
    "user": {
      "id": 1,
      "name": "Ahmad Siswa",
      "username": "siswa001",
      "email": "siswa001@smp1.com",
      "role_type": "student",
      "school_id": 1,
      "school": {
        "id": 1,
        "name": "SMP Negeri 1",
        "school_level": "SMP"
      }
    }
  }
}
```

**Error Response (422)**:
```json
{
  "success": false,
  "message": "Kredensial tidak valid",
  "errors": {
    "username": ["Kredensial tidak valid"]
  },
  "meta": {
    "error_code": "VALIDATION_ERROR"
  }
}
```

---

## Attendance (Student)

### Scan QR Code
**Endpoint**: `POST /attendance/scan`  
**Auth**: Required (Bearer Token)  
**Role**: `student`

**Request Body**:
```json
{
  "token": "encrypted_qr_payload",
  "latitude": -6.2088,
  "longitude": 106.8456,
  "accuracy": 10.5,
  "device_info": {
    "device_id": "abc123xyz",
    "os": "Android",
    "model": "Samsung Galaxy A52"
  }
}
```

**Success Response (201)**:
```json
{
  "success": true,
  "message": "Absensi berhasil",
  "data": {
    "attendance": {
      "id": 456,
      "status": "present",
      "check_in_time": "2026-01-20T07:15:30+08:00",
      "attendance_date": "2026-01-20"
    }
  }
}
```

**Error Responses**:

**400 - QR Expired**:
```json
{
  "success": false,
  "message": "QR Code sudah kadaluarsa (35 detik). Silakan scan ulang.",
  "meta": {
    "error_code": "QR_EXPIRED"
  }
}
```

**400 - Device Mismatch**:
```json
{
  "success": false,
  "message": "Perangkat tidak dikenali. Harap gunakan HP Anda sendiri yang terdaftar.",
  "meta": {
    "error_code": "DEVICE_MISMATCH"
  }
}
```

**400 - Geofence Violation**:
```json
{
  "success": false,
  "message": "Anda berada di luar radius sekolah (250m).",
  "meta": {
    "error_code": "GEOFENCE_VIOLATION"
  }
}
```

**409 - Duplicate Attendance**:
```json
{
  "success": false,
  "message": "Anda sudah melakukan absensi untuk jadwal ini.",
  "meta": {
    "error_code": "DUPLICATE_ATTENDANCE"
  }
}
```

---

## QR Management (Teacher)

### Generate QR Code
**Endpoint**: `POST /qr/generate`  
**Auth**: Required  
**Role**: `teacher`, `homeroom_teacher`

**Request Body**:
```json
{
  "schedule_id": 123,
  "qr_type": "in",
  "expiry_minutes": 5
}
```

**Success Response (201)**:
```json
{
  "success": true,
  "message": "QR Code berhasil dibuat",
  "data": {
    "qr_code": {
      "id": 789,
      "token": "encrypted_payload_abc123",
      "qr_type": "in",
      "valid_until": "2026-01-20T07:20:00+08:00",
      "is_active": true
    }
  }
}
```

---

## Reports (Admin)

### Daily Report
**Endpoint**: `GET /reports/daily`  
**Auth**: Required  
**Role**: `admin`, `school_admin`

**Query Parameters**:
- `date` (optional): YYYY-MM-DD format, default: today

**Success Response (200)**:
```json
{
  "success": true,
  "data": {
    "total_students": 120,
    "attendance_rate": 85,
    "present": 98,
    "late": 5,
    "sick": 2,
    "alpha": 15
  }
}
```

---

## HTTP Status Codes

| Code | Meaning | Use Case |
|------|---------|----------|
| 200 | OK | GET request berhasil |
| 201 | Created | POST berhasil (login, absensi) |
| 204 | No Content | DELETE berhasil |
| 400 | Bad Request | Payload tidak valid |
| 401 | Unauthorized | Token invalid/expired |
| 403 | Forbidden | Role tidak punya akses |
| 404 | Not Found | Resource tidak ditemukan |
| 409 | Conflict | Duplicate entry |
| 422 | Unprocessable Entity | Validasi gagal |
| 500 | Internal Server Error | Bug backend |

---

## Error Codes Reference

| Code | Description |
|------|-------------|
| `VALIDATION_ERROR` | Input validation failed |
| `UNAUTHORIZED` | Token invalid/expired |
| `FORBIDDEN` | Insufficient permissions |
| `NOT_FOUND` | Resource not found |
| `QR_EXPIRED` | QR code sudah kadaluarsa |
| `DEVICE_MISMATCH` | Device ID tidak cocok |
| `GEOFENCE_VIOLATION` | Lokasi di luar radius |
| `DUPLICATE_ATTENDANCE` | Sudah absen sebelumnya |
| `RATE_LIMIT_EXCEEDED` | Terlalu banyak request |

---

## Rate Limiting

- **Default**: 60 requests per minute per IP
- **Login**: 5 attempts per minute per IP
- **Scan QR**: 10 scans per minute per user

**Rate Limit Headers**:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1642665600
```

**429 Response**:
```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "meta": {
    "error_code": "RATE_LIMIT_EXCEEDED",
    "retry_after": 60
  }
}
```

---

## Versioning Strategy

- **Current**: `v1`
- **Breaking Changes**: Increment major version (`v2`)
- **Non-Breaking**: Add fields without version change
- **Deprecation**: 6 months notice before removal

**Example Migration**:
```
v1 (current): /api/v1/attendance/scan
v2 (future):  /api/v2/attendance/scan
```

---

## Security Headers

All API responses include:
```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000
```
