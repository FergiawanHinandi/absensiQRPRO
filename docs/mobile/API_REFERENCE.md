# Mobile App API Endpoints - Complete Reference

## Base URL
```
Development: http://YOUR_LOCAL_IP:8000/api/v1
Production:  https://api.absensigrpro.com/api/v1
```

**Note:** Untuk testing local dari device fisik, gunakan IP komputer (bukan localhost). Contoh: `http://192.168.1.100:8000`

---

## 🔐 Authentication Endpoints

### 1. Login
**Endpoint:** `POST /auth/login`

**Request:**
```json
{
  "username": "student1",
  "password": "password"
}
```

**Response (Success):**
```json
{
  "success": true,
  "token": "2|2xK8...",
  "user": {
    "id": 5,
    "name": "Siswa Satu",
    "username": "student1",
    "email": "student1@student.com",
    "role_type": "student",
    "school_id": 1,
    "nis": "2024001",
    "nisn": "0012345678",
    "class_id": 1,
    "is_active": true
  }
}
```

**Response (Error - 401):**
```json
{
  "success": false,
  "message": "Invalid credentials"
}
```

**Mobile Implementation:**
```typescript
// api/auth.api.ts
export const login = async (username: string, password: string) => {
  const response = await apiClient.post('/auth/login', {
    username,
    password,
  });
  
  // Save token
  await storage.setToken(response.data.token);
  await storage.setUser(response.data.user);
  
  return response.data;
};
```

---

### 2. Get Current User
**Endpoint:** `GET /auth/me`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 5,
    "name": "Siswa Satu",
    "username": "student1",
    "email": "student1@student.com",
    "role_type": "student",
    "school_id": 1,
    "school": {
      "id": 1,
      "name": "SMP Negeri 1",
      "address": "Jl. Pendidikan No. 1"
    },
    "nis": "2024001",
    "class_id": 1,
    "activeClass": {
      "id": 1,
      "name": "7A",
      "grade_level": 7
    }
  }
}
```

**Mobile Implementation:**
```typescript
export const getCurrentUser = async () => {
  const response = await apiClient.get('/auth/me');
  return response.data.data;
};
```

---

### 3. Logout
**Endpoint:** `POST /auth/logout`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

**Mobile Implementation:**
```typescript
export const logout = async () => {
  await apiClient.post('/auth/logout');
  await storage.removeToken();
  await storage.removeUser();
};
```

---

## ✅ Attendance Endpoints

### 4. Check-in via QR Code
**Endpoint:** `POST /attendance/check-in`

**Headers:**
```
Authorization: Bearer {token}
```

**Request:**
```json
{
  "qr_code": "ABSENSI-123-1737612345-abc123def456",
  "latitude": -6.2088,
  "longitude": 106.8456
}
```

**Response (Success - HADIR):**
```json
{
  "success": true,
  "message": "Absensi berhasil dicatat",
  "data": {
    "id": 456,
    "student_id": 5,
    "schedule_id": 123,
    "status": "present",
    "check_in_time": "07:15:30",
    "attendance_date": "2026-01-23",
    "is_manual": false,
    "latitude": -6.2088,
    "longitude": 106.8456,
    "schedule": {
      "id": 123,
      "subject": {
        "name": "Matematika"
      },
      "class": {
        "name": "7A"
      },
      "teacher": {
        "name": "Pak Guru"
      },
      "start_time": "07:00:00",
      "end_time": "08:30:00"
    }
  }
}
```

**Response (Success - TERLAMBAT):**
```json
{
  "success": true,
  "message": "Absensi tercatat (Terlambat)",
  "data": {
    "status": "late",
    "check_in_time": "07:45:30",
    ...
  }
}
```

**Response (Error - QR Invalid):**
```json
{
  "success": false,
  "message": "QR Code tidak valid atau sudah kadaluarsa",
  "error": "INVALID_QR"
}
```

**Response (Error - Already Checked In):**
```json
{
  "success": false,
  "message": "Anda sudah melakukan absensi untuk sesi ini",
  "error": "ALREADY_CHECKED_IN"
}
```

**Response (Error - Wrong Location):**
```json
{
  "success": false,
  "message": "Lokasi Anda di luar jangkauan sekolah",
  "error": "LOCATION_OUT_OF_RANGE"
}
```

**Mobile Implementation:**
```typescript
export const checkIn = async (qrCode: string, location: {latitude: number, longitude: number}) => {
  try {
    const response = await apiClient.post('/attendance/check-in', {
      qr_code: qrCode,
      latitude: location.latitude,
      longitude: location.longitude,
    });
    return { success: true, data: response.data };
  } catch (error: any) {
    const errorCode = error.response?.data?.error;
    const message = error.response?.data?.message || 'Gagal melakukan absensi';
    return { success: false, error: errorCode, message };
  }
};
```

---

### 5. Get Attendance History
**Endpoint:** `GET /student/attendance-history`

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
```
page: number (default: 1)
per_page: number (default: 20)
start_date: string (YYYY-MM-DD) (optional)
end_date: string (YYYY-MM-DD) (optional)
```

**Example Request:**
```
GET /student/attendance-history?page=1&per_page=20&start_date=2026-01-01
```

**Response:**
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 456,
        "attendance_date": "2026-01-23",
        "check_in_time": "07:15:30",
        "status": "present",
        "is_manual": false,
        "schedule": {
          "subject": {
            "name": "Matematika"
          },
          "class": {
            "name": "7A"
          },
          "start_time": "07:00:00",
          "end_time": "08:30:00"
        }
      },
      {
        "id": 455,
        "attendance_date": "2026-01-22",
        "check_in_time": "09:05:00",
        "status": "late",
        "is_manual": false,
        "schedule": {
          "subject": {
            "name": "Bahasa Indonesia"
          }
        }
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 20,
      "total": 45,
      "last_page": 3
    }
  }
}
```

**Mobile Implementation:**
```typescript
export const getAttendanceHistory = async (params?: {
  page?: number;
  per_page?: number;
  start_date?: string;
  end_date?: string;
}) => {
  const response = await apiClient.get('/student/attendance-history', { params });
  return response.data.data;
};
```

---

### 6. Get Attendance Summary
**Endpoint:** `GET /student/attendance-summary`

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
```
month: number (1-12)
year: number (YYYY)
```

**Example:**
```
GET /student/attendance-summary?month=1&year=2026
```

**Response:**
```json
{
  "success": true,
  "data": {
    "month": 1,
    "year": 2026,
    "total_days": 20,
    "present": 15,
    "late": 3,
    "sick": 1,
    "permit": 0,
    "alpha": 1,
    "attendance_percentage": 75.0
  }
}
```

---

## 📅 Schedule Endpoints

### 7. Get Today's Schedule
**Endpoint:** `GET /student/schedule`

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
```
date: string (YYYY-MM-DD) (optional, default: today)
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "day_of_week": "monday",
      "start_time": "07:00:00",
      "end_time": "08:30:00",
      "room": "Lab Komputer",
      "subject": {
        "id": 5,
        "name": "Matematika"
      },
      "teacher": {
        "id": 10,
        "name": "Pak Budi"
      },
      "class": {
        "id": 1,
        "name": "7A"
      }
    },
    {
      "id": 124,
      "start_time": "08:30:00",
      "end_time": "10:00:00",
      "room": "Ruang 7A",
      "subject": {
        "name": "Bahasa Indonesia"
      },
      "teacher": {
        "name": "Bu Siti"
      }
    }
  ]
}
```

---

## 👤 Profile Endpoints

### 8. Get Student Profile Detail
**Endpoint:** `GET /student/profile`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 5,
    "name": "Siswa Satu",
    "nis": "2024001",
    "nisn": "0012345678",
    "email": "student1@student.com",
    "phone": "081234567890",
    "gender": "L",
    "birth_date": "2010-05-15",
    "birth_place": "Jakarta",
    "address": "Jl. Contoh No. 123",
    "class": {
      "id": 1,
      "name": "7A",
      "grade_level": 7
    },
    "school": {
      "id": 1,
      "name": "SMP Negeri 1",
      "address": "Jl. Pendidikan No. 1",
      "phone": "021-1234567"
    },
    "parents": [
      {
        "id": 20,
        "name": "Bapak Orang Tua",
        "phone": "081234567999",
        "relationship": "father"
      }
    ]
  }
}
```

---

## 🔔 Notification Endpoints (Future)

### 9. Get Notifications
**Endpoint:** `GET /student/notifications`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Pengumuman Libur",
      "message": "Besok tanggal 24 Jan libur...",
      "type": "announcement",
      "is_read": false,
      "created_at": "2026-01-23T10:00:00Z"
    }
  ]
}
```

---

## 📊 Error Response Format

**Standard Error Response:**
```json
{
  "success": false,
  "message": "Human readable error message",
  "error": "ERROR_CODE",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

**Common Error Codes:**
- `INVALID_QR` - QR code format salah atau expired
- `ALREADY_CHECKED_IN` - Sudah absen untuk sesi ini
- `LOCATION_OUT_OF_RANGE` - Lokasi di luar jangkauan sekolah
- `SESSION_NOT_ACTIVE` - Sesi belum dimulai atau sudah selesai
- `UNAUTHORIZED` - Token invalid atau expired
- `VALIDATION_ERROR` - Input data tidak valid

---

## 🧪 Testing Data

### Test Accounts
```
Student 1:
Username: student1
Password: password
Class: 7A

Student 2:
Username: student2
Password: password
Class: 7A

Student 3:
Username: student3
Password: password
Class: 7B
```

### Sample QR Codes (for testing without teacher dashboard)
```
Format: ABSENSI-{schedule_id}-{timestamp}-{hash}

Example:
ABSENSI-1-1737612345-abc123def
ABSENSI-2-1737612400-xyz789ghi
```

**Generate sample timestamp:** `Math.floor(Date.now() / 1000)`

---

## 🚀 Mobile App Network Configuration

### React Native - Allow HTTP (Development)

**Android** (`android/app/src/main/AndroidManifest.xml`):
```xml
<application
  android:usesCleartextTraffic="true"
  ...>
```

**iOS** (`ios/YourApp/Info.plist`):
```xml
<key>NSAppTransportSecurity</key>
<dict>
  <key>NSAllowsArbitraryLoads</key>
  <true/>
</dict>
```

### Environment Variables

Create `.env` file:
```env
API_BASE_URL=http://192.168.1.100:8000/api/v1
```

---

## 📝 Best Practices

1. **Token Refresh**: Token Sanctum default tidak expire, tapi handle 401 untuk logout otomatis
2. **Retry Logic**: Implement retry untuk network errors
3. **Offline Mode**: Cache data untuk offline viewing (history)
4. **Loading States**: Tampilkan loading indicator saat API call
5. **Error Handling**: Tampilkan error message yang user-friendly

---

**Last Updated**: 2026-01-23
