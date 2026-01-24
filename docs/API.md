# absensiQRPRO API Documentation

## Base URL
```
http://your-server/absensiQRPRO/website/backend/api
```

## Authentication

All endpoints except login require a teacher to be authenticated. Store teacher information locally after successful login.

## Endpoints

### 1. Teacher Login

**Endpoint:** `POST /login.php`

**Description:** Authenticate teacher and get teacher information.

**Request Body:**
```json
{
  "email": "budi@school.com",
  "password": "teacher123"
}
```

**Response (Success - 200):**
```json
{
  "message": "Login successful",
  "data": {
    "id": 1,
    "nip": "NIP001",
    "name": "Budi Santoso",
    "email": "budi@school.com",
    "phone": "081234567890",
    "photo": null
  }
}
```

**Response (Error - 401):**
```json
{
  "message": "Login failed. Invalid email or password."
}
```

---

### 2. Scan QR Code

**Endpoint:** `POST /scan_qr.php`

**Description:** Get student information by scanning QR code.

**Request Body:**
```json
{
  "qr_code": "QR-NIS001-2024"
}
```

**Response (Success - 200):**
```json
{
  "message": "Student found",
  "data": {
    "id": 1,
    "nis": "NIS001",
    "name": "Ahmad Fauzi",
    "class": "10-A",
    "qr_code": "QR-NIS001-2024",
    "photo": null
  }
}
```

**Response (Error - 404):**
```json
{
  "message": "Student not found."
}
```

---

### 3. Mark Attendance

**Endpoint:** `POST /mark_attendance.php`

**Description:** Mark student attendance.

**Request Body:**
```json
{
  "student_id": 1,
  "teacher_id": 1,
  "status": "present",
  "notes": "On time"
}
```

**Status Options:**
- `present` - Student is present
- `late` - Student is late
- `absent` - Student is absent
- `excused` - Student has valid excuse

**Response (Success - 201):**
```json
{
  "message": "Attendance marked successfully."
}
```

**Response (Error - 503):**
```json
{
  "message": "Unable to mark attendance. Student may have already been marked today."
}
```

---

### 4. Get Students

**Endpoint:** `GET /students.php`

**Description:** Get list of all students.

**Response (Success - 200):**
```json
{
  "records": [
    {
      "id": 1,
      "nis": "NIS001",
      "name": "Ahmad Fauzi",
      "class": "10-A",
      "qr_code": "QR-NIS001-2024",
      "photo": null,
      "created_at": "2024-01-24 10:00:00"
    },
    ...
  ]
}
```

**Response (Error - 404):**
```json
{
  "message": "No students found."
}
```

---

### 5. Get Attendance Records

**Endpoint:** `GET /attendance.php?date=YYYY-MM-DD`

**Description:** Get attendance records for a specific date.

**Query Parameters:**
- `date` (required) - Date in YYYY-MM-DD format

**Example:**
```
GET /attendance.php?date=2024-01-24
```

**Response (Success - 200):**
```json
{
  "records": [
    {
      "id": 1,
      "student_id": 1,
      "student_name": "Ahmad Fauzi",
      "nis": "NIS001",
      "class": "10-A",
      "teacher_id": 1,
      "teacher_name": "Budi Santoso",
      "date": "2024-01-24",
      "time": "07:30:00",
      "status": "present",
      "notes": null
    },
    ...
  ]
}
```

**Response (Error - 404):**
```json
{
  "message": "No attendance records found for this date."
}
```

---

## Error Codes

- **200** - Success
- **201** - Created (attendance marked successfully)
- **400** - Bad Request (missing required fields)
- **401** - Unauthorized (invalid credentials)
- **404** - Not Found (resource not found)
- **503** - Service Unavailable (database error or duplicate entry)

## Data Models

### Student
```json
{
  "id": "integer",
  "nis": "string (unique)",
  "name": "string",
  "class": "string",
  "qr_code": "string (unique)",
  "photo": "string|null",
  "created_at": "datetime"
}
```

### Teacher
```json
{
  "id": "integer",
  "nip": "string (unique)",
  "name": "string",
  "email": "string (unique)",
  "phone": "string|null",
  "photo": "string|null",
  "created_at": "datetime"
}
```

### Attendance
```json
{
  "id": "integer",
  "student_id": "integer",
  "teacher_id": "integer",
  "date": "date",
  "time": "time",
  "status": "enum (present|late|absent|excused)",
  "notes": "string|null",
  "created_at": "datetime"
}
```

## Rate Limiting

Currently, there is no rate limiting implemented. For production use, consider implementing rate limiting to prevent abuse.

## CORS

The API allows cross-origin requests from any origin. For production use, restrict this to specific domains in `config/cors.php`.
