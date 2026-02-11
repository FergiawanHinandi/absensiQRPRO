# API Endpoint Specifications - All Dashboards

## Overview
Complete API endpoint specifications untuk Student, Principal, Parent, dan Teacher dashboards.

---

## 1. Student Dashboard APIs

### Base URL: `/api/v1/student`

### 1.1 GET /dashboard
**Description**: Get student dashboard overview

**Authentication**: Required (Student role)

**Request**:
```http
GET /api/v1/student/dashboard
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "student": {
      "id": 123,
      "name": "John Doe",
      "nisn": "1234567890",
      "class_name": "XII IPA 1",
      "photo_url": "https://..."
    },
    "attendance_summary": {
      "rate": 87.5,
      "total": 40,
      "present": 32,
      "late": 3,
      "absent": 5,
      "trend": 2.5
    },
    "schedule_today": [
      {
        "id": 1,
        "subject": "Matematika",
        "teacher": "Pak Budi",
        "room": "Lab 1",
        "start_time": "08:00",
        "end_time": "09:30",
        "status": "upcoming"
      }
    ],
    "recent_attendance": [
      {
        "id": 1,
        "date": "2026-02-09",
        "subject": "Fisika",
        "status": "present",
        "check_in_time": "08:05",
        "teacher": "Pak Ahmad"
      }
    ],
    "upcoming_classes": [
      {
        "id": 2,
        "subject": "Kimia",
        "teacher": "Bu Siti",
        "room": "Lab 2",
        "date": "2026-02-10",
        "start_time": "10:00"
      }
    ],
    "notifications": [
      {
        "id": 1,
        "type": "attendance",
        "title": "Kehadiran Tercatat",
        "message": "Anda telah absen untuk kelas Matematika",
        "created_at": "2026-02-09T08:05:00+08:00",
        "read": false
      }
    ],
    "attendance_trend": [
      {
        "date": "2026-02-01",
        "rate": 85.0
      },
      {
        "date": "2026-02-02",
        "rate": 87.5
      }
    ]
  }
}
```

---

### 1.2 POST /scan-qr
**Description**: Scan QR code for attendance

**Authentication**: Required (Student role)

**Request**:
```http
POST /api/v1/student/scan-qr
Authorization: Bearer {token}
Content-Type: application/json

{
  "qr_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "latitude": -6.200000,
  "longitude": 106.816666,
  "device_id": "device-fingerprint-hash",
  "timestamp": "2026-02-09T08:05:00+08:00"
}
```

**Response** (200 OK - Success):
```json
{
  "success": true,
  "data": {
    "status": "present",
    "message": "Kehadiran berhasil dicatat",
    "attendance": {
      "id": 123,
      "student_id": 456,
      "schedule_id": 789,
      "status": "present",
      "check_in_time": "08:05:00",
      "latitude": -6.200000,
      "longitude": 106.816666,
      "created_at": "2026-02-09T08:05:00+08:00"
    },
    "class_info": {
      "subject": "Matematika",
      "teacher": "Pak Budi",
      "room": "Lab 1"
    }
  }
}
```

**Response** (200 OK - Late):
```json
{
  "success": true,
  "data": {
    "status": "late",
    "message": "Anda terlambat 5 menit",
    "attendance": {
      "id": 123,
      "status": "late",
      "check_in_time": "08:20:00",
      "late_duration_minutes": 5
    }
  }
}
```

**Response** (422 Unprocessable Entity - Validation Error):
```json
{
  "success": false,
  "message": "QR code tidak valid",
  "errors": {
    "qr_token": ["QR code sudah kadaluarsa"],
    "location": ["Lokasi Anda terlalu jauh dari sekolah"]
  }
}
```

**Response** (409 Conflict - Already Scanned):
```json
{
  "success": false,
  "message": "Anda sudah absen untuk kelas ini",
  "data": {
    "existing_attendance": {
      "id": 122,
      "status": "present",
      "check_in_time": "08:00:00"
    }
  }
}
```

---

### 1.3 GET /my-attendance
**Description**: Get student attendance history

**Authentication**: Required (Student role)

**Request**:
```http
GET /api/v1/student/my-attendance?start_date=2026-02-01&end_date=2026-02-09&status=present&page=1&per_page=20
Authorization: Bearer {token}
```

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start_date | string (YYYY-MM-DD) | No | Filter start date |
| end_date | string (YYYY-MM-DD) | No | Filter end date |
| status | string | No | Filter by status (present/late/absent) |
| class_id | integer | No | Filter by class |
| page | integer | No | Page number (default: 1) |
| per_page | integer | No | Items per page (default: 20) |

**Response** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "date": "2026-02-09",
      "subject": "Matematika",
      "teacher": "Pak Budi",
      "status": "present",
      "check_in_time": "08:05:00",
      "schedule_start": "08:00:00",
      "late_duration_minutes": 5,
      "room": "Lab 1"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 40,
    "last_page": 2
  },
  "summary": {
    "total": 40,
    "present": 32,
    "late": 3,
    "absent": 5,
    "rate": 87.5
  }
}
```

---

### 1.4 GET /my-schedule
**Description**: Get student class schedule

**Authentication**: Required (Student role)

**Request**:
```http
GET /api/v1/student/my-schedule?start_date=2026-02-09&end_date=2026-02-15
Authorization: Bearer {token}
```

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start_date | string (YYYY-MM-DD) | Yes | Week start date |
| end_date | string (YYYY-MM-DD) | Yes | Week end date |

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "schedules": [
      {
        "id": 1,
        "day_of_week": "monday",
        "date": "2026-02-09",
        "subject": "Matematika",
        "teacher": {
          "id": 10,
          "name": "Pak Budi",
          "photo_url": "https://..."
        },
        "room": "Lab 1",
        "start_time": "08:00",
        "end_time": "09:30",
        "is_active": true,
        "has_attended": true,
        "attendance_status": "present"
      }
    ],
    "week_summary": {
      "total_classes": 25,
      "attended": 20,
      "upcoming": 5
    }
  }
}
```

---

### 1.5 GET /notifications
**Description**: Get student notifications

**Authentication**: Required (Student role)

**Request**:
```http
GET /api/v1/student/notifications?type=attendance&read=false&page=1
Authorization: Bearer {token}
```

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| type | string | No | Filter by type (attendance/schedule/announcement) |
| read | boolean | No | Filter by read status |
| page | integer | No | Page number |

**Response** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "attendance",
      "title": "Kehadiran Tercatat",
      "message": "Anda telah absen untuk kelas Matematika",
      "icon": "check-circle",
      "created_at": "2026-02-09T08:05:00+08:00",
      "read": false,
      "read_at": null,
      "action_url": "/my-attendance"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 15,
    "last_page": 1
  },
  "unread_count": 5
}
```

---

### 1.6 POST /notifications/:id/mark-read
**Description**: Mark notification as read

**Authentication**: Required (Student role)

**Request**:
```http
POST /api/v1/student/notifications/1/mark-read
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Notifikasi ditandai sebagai dibaca"
}
```

---

### 1.7 PATCH /notifications/mark-all-read
**Description**: Mark all notifications as read

**Authentication**: Required (Student role)

**Request**:
```http
PATCH /api/v1/student/notifications/mark-all-read
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Semua notifikasi ditandai sebagai dibaca",
  "data": {
    "marked_count": 5
  }
}
```

---

## 2. Principal Dashboard APIs

### Base URL: `/api/v1/principal`

### 2.1 GET /school-overview
**Description**: Get school overview dashboard

**Authentication**: Required (Principal role)

**Request**:
```http
GET /api/v1/principal/school-overview
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "kpis": {
      "total_students": 1250,
      "total_teachers": 75,
      "total_classes": 35,
      "attendance_rate": 87.5,
      "attendance_rate_change": 2.5
    },
    "attendance_trend": [
      {
        "date": "2026-02-01",
        "rate": 85.0,
        "present": 1050,
        "late": 100,
        "absent": 100
      }
    ],
    "class_performance": [
      {
        "class_id": 1,
        "class_name": "XII IPA 1",
        "grade": "12",
        "rate": 92.5,
        "rank": 1
      }
    ],
    "teacher_performance": [
      {
        "teacher_id": 10,
        "teacher_name": "Pak Budi",
        "classes_taught": 5,
        "avg_attendance_rate": 90.0,
        "rank": 1
      }
    ],
    "grade_distribution": [
      {
        "grade": "10",
        "student_count": 400,
        "attendance_rate": 85.0
      }
    ],
    "top_classes": [
      {
        "id": 1,
        "name": "XII IPA 1",
        "rate": 92.5,
        "students": 35
      }
    ],
    "low_attendance_alerts": [
      {
        "id": 1,
        "class_id": 10,
        "class_name": "X IPS 2",
        "rate": 65.0,
        "severity": "high",
        "message": "Tingkat kehadiran di bawah 75%"
      }
    ]
  }
}
```

---

### 2.2 GET /attendance-monitoring
**Description**: Real-time attendance monitoring

**Authentication**: Required (Principal role)

**Request**:
```http
GET /api/v1/principal/attendance-monitoring?date=2026-02-09&grade=12&status=present
Authorization: Bearer {token}
```

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| date | string (YYYY-MM-DD) | No | Filter by date (default: today) |
| grade | string | No | Filter by grade |
| class_id | integer | No | Filter by class |
| status | string | No | Filter by status |

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "classes": [
      {
        "id": 1,
        "name": "XII IPA 1",
        "grade": "12",
        "total_students": 35,
        "present": 30,
        "late": 3,
        "absent": 2,
        "rate": 94.3,
        "status": "active",
        "current_session": {
          "subject": "Matematika",
          "teacher": "Pak Budi",
          "start_time": "08:00",
          "end_time": "09:30"
        }
      }
    ],
    "summary": {
      "total_classes": 35,
      "total_students": 1250,
      "total_present": 1050,
      "total_late": 100,
      "total_absent": 100,
      "overall_rate": 87.5
    },
    "timestamp": "2026-02-09T08:30:00+08:00"
  }
}
```

---

### 2.3 GET /teacher-performance
**Description**: Teacher performance analytics

**Authentication**: Required (Principal role)

**Request**:
```http
GET /api/v1/principal/teacher-performance?start_date=2026-02-01&end_date=2026-02-09&department=science
Authorization: Bearer {token}
```

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start_date | string (YYYY-MM-DD) | No | Filter start date |
| end_date | string (YYYY-MM-DD) | No | Filter end date |
| department | string | No | Filter by department |
| sort_by | string | No | Sort by field (rate/name/classes) |

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "teachers": [
      {
        "id": 10,
        "name": "Pak Budi",
        "department": "Science",
        "classes_taught": 5,
        "attendance_sessions": 40,
        "avg_attendance_rate": 90.0,
        "late_class_starts": 2,
        "performance_score": 92.5,
        "trend": 2.5,
        "rank": 1
      }
    ],
    "summary": {
      "total_teachers": 75,
      "avg_performance": 85.0,
      "top_performer": {
        "id": 10,
        "name": "Pak Budi",
        "score": 92.5
      },
      "needs_improvement": [
        {
          "id": 20,
          "name": "Bu Ani",
          "score": 70.0,
          "reason": "Tingkat kehadiran kelas rendah"
        }
      ]
    }
  }
}
```

---

### 2.4 GET /class-performance
**Description**: Class performance analytics

**Authentication**: Required (Principal role)

**Request**:
```http
GET /api/v1/principal/class-performance?start_date=2026-02-01&end_date=2026-02-09&grade=12
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "classes": [
      {
        "id": 1,
        "name": "XII IPA 1",
        "grade": "12",
        "total_students": 35,
        "avg_attendance_rate": 92.5,
        "trend": 2.5,
        "rank": 1,
        "students_below_75": 2,
        "performance_category": "excellent"
      }
    ],
    "summary": {
      "total_classes": 35,
      "avg_rate": 87.5,
      "best_class": {
        "id": 1,
        "name": "XII IPA 1",
        "rate": 92.5
      },
      "worst_class": {
        "id": 10,
        "name": "X IPS 2",
        "rate": 65.0
      }
    }
  }
}
```

---

### 2.5 GET /trend-analysis
**Description**: Attendance trend analysis

**Authentication**: Required (Principal role)

**Request**:
```http
GET /api/v1/principal/trend-analysis?start_date=2026-01-01&end_date=2026-02-09&granularity=daily
Authorization: Bearer {token}
```

**Query Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| start_date | string (YYYY-MM-DD) | Yes | Analysis start date |
| end_date | string (YYYY-MM-DD) | Yes | Analysis end date |
| granularity | string | No | daily/weekly/monthly (default: daily) |

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "trend_data": [
      {
        "date": "2026-02-01",
        "overall_rate": 85.0,
        "by_grade": {
          "10": 82.0,
          "11": 85.0,
          "12": 88.0
        }
      }
    ],
    "insights": [
      {
        "type": "trend",
        "message": "Tingkat kehadiran meningkat 2.5% dalam 7 hari terakhir",
        "severity": "info"
      },
      {
        "type": "anomaly",
        "message": "Penurunan tajam kehadiran pada tanggal 2026-02-05 (hari libur nasional)",
        "severity": "warning"
      }
    ],
    "forecast": [
      {
        "date": "2026-02-10",
        "predicted_rate": 87.5,
        "confidence": 0.85
      }
    ],
    "statistics": {
      "avg_rate": 87.5,
      "median_rate": 88.0,
      "std_deviation": 3.2,
      "best_day": {
        "date": "2026-02-08",
        "rate": 92.0
      },
      "worst_day": {
        "date": "2026-02-05",
        "rate": 75.0
      }
    }
  }
}
```

---

## 3. Parent Dashboard APIs

### Base URL: `/api/v1/parent`

### 3.1 GET /children
**Description**: Get parent's children list

**Authentication**: Required (Parent role)

**Request**:
```http
GET /api/v1/parent/children
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "children": [
      {
        "id": 123,
        "name": "John Doe",
        "nisn": "1234567890",
        "class_id": 1,
        "class_name": "XII IPA 1",
        "grade": "12",
        "photo_url": "https://...",
        "attendance_rate": 87.5,
        "status": "active"
      }
    ]
  }
}
```

---

### 3.2 GET /child/:id/attendance
**Description**: Get child attendance overview

**Authentication**: Required (Parent role)

**Request**:
```http
GET /api/v1/parent/child/123/attendance
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "child": {
      "id": 123,
      "name": "John Doe",
      "class_name": "XII IPA 1"
    },
    "attendance_rate": 87.5,
    "monthly_calendar": [
      {
        "date": "2026-02-01",
        "status": "present",
        "classes_attended": 5,
        "total_classes": 5
      }
    ],
    "recent_attendance": [
      {
        "id": 1,
        "date": "2026-02-09",
        "subject": "Matematika",
        "teacher": "Pak Budi",
        "status": "present",
        "check_in_time": "08:05:00"
      }
    ],
    "today_schedule": [
      {
        "id": 1,
        "subject": "Fisika",
        "teacher": "Pak Ahmad",
        "room": "Lab 1",
        "start_time": "10:00",
        "end_time": "11:30",
        "has_attended": false
      }
    ],
    "summary": {
      "total": 40,
      "present": 32,
      "late": 3,
      "absent": 5,
      "rate": 87.5
    }
  }
}
```

---

### 3.3 GET /child/:id/attendance-history
**Description**: Get child attendance history

**Authentication**: Required (Parent role)

**Request**:
```http
GET /api/v1/parent/child/123/attendance-history?start_date=2026-02-01&end_date=2026-02-09&status=present
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "date": "2026-02-09",
      "subject": "Matematika",
      "teacher": "Pak Budi",
      "status": "present",
      "check_in_time": "08:05:00",
      "room": "Lab 1"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 40,
    "last_page": 2
  },
  "summary": {
    "total": 40,
    "present": 32,
    "late": 3,
    "absent": 5,
    "rate": 87.5
  },
  "trend": [
    {
      "month": "2026-01",
      "rate": 85.0
    },
    {
      "month": "2026-02",
      "rate": 87.5
    }
  ]
}
```

---

### 3.4 GET /child/:id/alerts
**Description**: Get child attendance alerts

**Authentication**: Required (Parent role)

**Request**:
```http
GET /api/v1/parent/child/123/alerts?type=low_attendance&severity=high&read=false
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "alerts": [
      {
        "id": 1,
        "type": "low_attendance",
        "severity": "high",
        "title": "Tingkat Kehadiran Rendah",
        "message": "Tingkat kehadiran anak Anda turun di bawah 75% (saat ini 72%)",
        "date": "2026-02-09",
        "read": false,
        "acknowledged": false,
        "action_required": true
      },
      {
        "id": 2,
        "type": "consecutive_absence",
        "severity": "medium",
        "title": "Absen Berturut-turut",
        "message": "Anak Anda tidak hadir selama 3 hari berturut-turut",
        "date": "2026-02-08",
        "read": false,
        "acknowledged": false
      }
    ],
    "unread_count": 2
  }
}
```

---

### 3.5 POST /alerts/:id/acknowledge
**Description**: Acknowledge alert

**Authentication**: Required (Parent role)

**Request**:
```http
POST /api/v1/parent/alerts/1/acknowledge
Authorization: Bearer {token}
Content-Type: application/json

{
  "note": "Anak sakit, sudah ada surat dokter"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Alert berhasil diakui"
}
```

---

### 3.6 GET /child/:id/teachers
**Description**: Get child's teachers

**Authentication**: Required (Parent role)

**Request**:
```http
GET /api/v1/parent/child/123/teachers
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "teachers": [
      {
        "id": 10,
        "name": "Pak Budi",
        "subject": "Matematika",
        "email": "budi@school.com",
        "phone": "+62812345678",
        "photo_url": "https://..."
      }
    ]
  }
}
```

---

### 3.7 GET /announcements
**Description**: Get school announcements

**Authentication**: Required (Parent role)

**Request**:
```http
GET /api/v1/parent/announcements?category=general&start_date=2026-02-01
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "announcements": [
      {
        "id": 1,
        "title": "Libur Semester",
        "content": "Sekolah akan libur mulai tanggal...",
        "category": "holiday",
        "priority": "high",
        "published_at": "2026-02-01T10:00:00+08:00",
        "attachments": [
          {
            "id": 1,
            "filename": "jadwal-libur.pdf",
            "url": "https://..."
          }
        ]
      }
    ],
    "pinned": [
      {
        "id": 2,
        "title": "Pengumuman Penting",
        "content": "..."
      }
    ]
  }
}
```

---

## 4. Teacher Dashboard APIs

### Base URL: `/api/v1/teacher`

### 4.1 GET /my-classes
**Description**: Get teacher's assigned classes

**Authentication**: Required (Teacher role)

**Request**:
```http
GET /api/v1/teacher/my-classes
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "classes": [
      {
        "id": 1,
        "name": "XII IPA 1",
        "grade": "12",
        "subject": "Matematika",
        "total_students": 35,
        "avg_attendance_rate": 92.5,
        "schedule": [
          {
            "day": "monday",
            "start_time": "08:00",
            "end_time": "09:30",
            "room": "Lab 1"
          }
        ]
      }
    ]
  }
}
```

---

### 4.2 GET /class/:id/students
**Description**: Get class student list

**Authentication**: Required (Teacher role)

**Request**:
```http
GET /api/v1/teacher/class/1/students
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "students": [
      {
        "id": 123,
        "name": "John Doe",
        "nisn": "1234567890",
        "attendance_rate": 87.5,
        "total_present": 32,
        "total_late": 3,
        "total_absent": 5,
        "photo_url": "https://..."
      }
    ]
  }
}
```

---

### 4.3 GET /attendance-history
**Description**: Get teacher's attendance history

**Authentication**: Required (Teacher role)

**Request**:
```http
GET /api/v1/teacher/attendance-history?class_id=1&start_date=2026-02-01&end_date=2026-02-09
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "date": "2026-02-09",
      "class_name": "XII IPA 1",
      "subject": "Matematika",
      "total_students": 35,
      "present": 30,
      "late": 3,
      "absent": 2,
      "rate": 94.3
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 40,
    "last_page": 2
  }
}
```

---

### 4.4 POST /attendance-correction
**Description**: Request attendance correction

**Authentication**: Required (Teacher role)

**Request**:
```http
POST /api/v1/teacher/attendance-correction
Authorization: Bearer {token}
Content-Type: application/json

{
  "attendance_id": 123,
  "student_id": 456,
  "current_status": "absent",
  "new_status": "present",
  "reason": "Siswa hadir tetapi lupa scan QR",
  "evidence_url": "https://..."
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Permintaan koreksi berhasil diajukan",
  "data": {
    "correction_id": 789,
    "status": "pending",
    "submitted_at": "2026-02-09T10:00:00+08:00"
  }
}
```

---

### 4.5 GET /class/:id/attendance-report
**Description**: Get class attendance report

**Authentication**: Required (Teacher role)

**Request**:
```http
GET /api/v1/teacher/class/1/attendance-report?start_date=2026-02-01&end_date=2026-02-09
Authorization: Bearer {token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "class_info": {
      "id": 1,
      "name": "XII IPA 1",
      "grade": "12",
      "total_students": 35
    },
    "summary": {
      "total_sessions": 20,
      "avg_attendance_rate": 92.5,
      "total_present": 600,
      "total_late": 50,
      "total_absent": 50
    },
    "student_details": [
      {
        "student_id": 123,
        "student_name": "John Doe",
        "attendance_rate": 87.5,
        "present": 28,
        "late": 2,
        "absent": 5,
        "pattern": "consistent"
      }
    ],
    "daily_breakdown": [
      {
        "date": "2026-02-01",
        "present": 30,
        "late": 3,
        "absent": 2,
        "rate": 94.3
      }
    ]
  }
}
```

---

## Error Responses

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Unauthenticated",
  "errors": {
    "token": ["Token tidak valid atau sudah kadaluarsa"]
  }
}
```

### 403 Forbidden
```json
{
  "success": false,
  "message": "Unauthorized",
  "errors": {
    "permission": ["Anda tidak memiliki akses ke resource ini"]
  }
}
```

### 404 Not Found
```json
{
  "success": false,
  "message": "Resource tidak ditemukan"
}
```

### 422 Validation Error
```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "start_date": ["Format tanggal tidak valid"],
    "status": ["Status harus salah satu dari: present, late, absent"]
  }
}
```

### 500 Internal Server Error
```json
{
  "success": false,
  "message": "Terjadi kesalahan pada server",
  "error_id": "ERR-2026-02-09-12345"
}
```

---

## Rate Limiting

All API endpoints are rate-limited:

- **Student**: 60 requests/minute
- **Teacher**: 120 requests/minute
- **Principal**: 120 requests/minute
- **Parent**: 60 requests/minute

**Rate Limit Headers**:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1644393600
```

**Rate Limit Exceeded Response** (429):
```json
{
  "success": false,
  "message": "Terlalu banyak permintaan. Silakan coba lagi dalam beberapa menit.",
  "retry_after": 60
}
```

---

## Pagination

All list endpoints support pagination:

**Query Parameters**:
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 20, max: 100)

**Response Meta**:
```json
{
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 100,
    "last_page": 5,
    "from": 1,
    "to": 20
  }
}
```

---

## Summary

### Total Endpoints by Dashboard

| Dashboard | Endpoints | Methods |
|-----------|-----------|---------|
| Student | 7 | GET (5), POST (1), PATCH (1) |
| Principal | 5 | GET (5) |
| Parent | 8 | GET (6), POST (2) |
| Teacher | 5 | GET (3), POST (2) |
| **Total** | **25** | **GET (19), POST (5), PATCH (1)** |

### Authentication
All endpoints require Bearer token authentication with appropriate role permissions.

### Response Format
All responses follow consistent JSON format with `success`, `message`, and `data` fields.

### Error Handling
Comprehensive error responses with appropriate HTTP status codes and detailed error messages.
