# Arsitektur Sistem Absensi QR Code - SD/SMP/SMA/SMK

## 1. Arsitektur Sistem

### 1.1 Overview Arsitektur

```mermaid
graph TB
    subgraph "Client Layer"
        MA[Mobile App - Student]
        MT[Mobile App - Teacher]
        WA[Web Admin Dashboard]
    end
    
    subgraph "API Gateway Layer"
        AG[API Gateway/Load Balancer]
        RL[Rate Limiter]
        AUTH[Authentication Middleware]
    end
    
    subgraph "Application Layer - Laravel 11"
        API[REST API Controllers]
        SVC[Service Layer]
        REPO[Repository Pattern]
        
        subgraph "Core Services"
            QRS[QR Service]
            ABS[Attendance Service]
            NTFS[Notification Service]
            REPORTS[Report Service]
        end
    end
    
    subgraph "Data Layer"
        PG[(PostgreSQL - Main DB)]
        RDS[(Redis Cache)]
        S3[File Storage - S3/MinIO]
        QUEUE[Queue - Redis/SQS]
    end
    
    subgraph "External Services"
        FCM[Firebase Cloud Messaging]
        SMS[SMS Gateway - Optional]
        EMAIL[Email Service]
    end
    
    MA --> AG
    MT --> AG
    WA --> AG
    AG --> RL
    RL --> AUTH
    AUTH --> API
    API --> SVC
    SVC --> REPO
    SVC --> QRS
    SVC --> ABS
    SVC --> NTFS
    SVC --> REPORTS
    REPO --> PG
    SVC --> RDS
    SVC --> S3
    SVC --> QUEUE
    NTFS --> FCM
    NTFS --> SMS
    NTFS --> EMAIL
```

### 1.2 Technology Stack

#### Backend (API)
- **Framework**: Laravel 11 (PHP 8.2+)
- **Database**: PostgreSQL 15+ (ACID compliance, JSON support)
- **Cache**: Redis 7+
- **Queue**: Laravel Queue with Redis driver
- **Storage**: MinIO / AWS S3 (untuk QR codes & laporan)
- **Authentication**: Laravel Sanctum (API tokens)
- **Authorization**: Spatie Laravel Permission (RBAC)

#### Mobile App
- **Option 1 (Recommended)**: Flutter 3.x
  - Single codebase untuk iOS & Android
  - Performance native-like
  - Rich UI components
  
- **Option 2**: React Native
  - JavaScript/TypeScript ecosystem
  - Large community support

#### Web Admin
- **Framework**: Laravel Blade / Inertia.js + Vue 3
- **UI**: TailwindCSS / Bootstrap 5
- **Charts**: Chart.js / ApexCharts

### 1.3 Deployment Architecture

```mermaid
graph LR
    subgraph "Production Environment"
        LB[Load Balancer - Nginx]
        
        subgraph "Application Servers"
            APP1[Laravel App 1]
            APP2[Laravel App 2]
            APP3[Laravel App N]
        end
        
        subgraph "Database Cluster"
            PGPRIMARY[(PG Primary)]
            PGREPLICA1[(PG Replica 1)]
            PGREPLICA2[(PG Replica 2)]
        end
        
        subgraph "Cache & Queue"
            REDISMASTER[(Redis Master)]
            REDISSLAVE[(Redis Replica)]
        end
        
        subgraph "Storage"
            MINIO[MinIO Cluster]
        end
    end
    
    LB --> APP1
    LB --> APP2
    LB --> APP3
    APP1 --> PGPRIMARY
    APP2 --> PGPRIMARY
    APP3 --> PGPRIMARY
    PGPRIMARY --> PGREPLICA1
    PGPRIMARY --> PGREPLICA2
    APP1 --> REDISMASTER
    APP2 --> REDISMASTER
    APP3 --> REDISMASTER
    REDISMASTER --> REDISSLAVE
    APP1 --> MINIO
    APP2 --> MINIO
    APP3 --> MINIO
```

---

## 2. Role & Permission Structure

### 2.1 Hirarki Role

```mermaid
graph TD
    SA[Super Admin] --> SCHAD[School Admin]
    SCHAD --> PRIN[Principal/Kepala Sekolah]
    PRIN --> VPD[Vice Principal]
    PRIN --> TEACH[Teacher/Guru]
    PRIN --> STAFF[Staff TU]
    TEACH --> WALI[Wali Kelas]
    SCHAD --> STU[Student/Siswa]
    WALI --> STU
```

### 2.2 Role Definitions & Permissions

| Role | Kode | Permissions | Deskripsi |
|------|------|-------------|-----------|
| **Super Admin** | `super_admin` | `*.*` (all) | Manage multiple schools, system config |
| **School Admin** | `school_admin` | `school.*`, `users.*`, `classes.*`, `attendance.*` | Manage satu sekolah & semua data |
| **Principal** | `principal` | `reports.*`, `teachers.*`, `students.*`, `attendance.view` | Kepala sekolah - view & approve reports |
| **Vice Principal** | `vice_principal` | `reports.view`, `teachers.view`, `students.*`, `attendance.view` | Wakil kepala sekolah |
| **Teacher** | `teacher` | `attendance.scan`, `attendance.manual`, `students.view`, `classes.view` | Guru - scan QR, input manual |
| **Homeroom Teacher** | `homeroom_teacher` | `teacher.*`, `students.manage_class`, `attendance.report_class` | Wali kelas - manage kelas sendiri |
| **Staff TU** | `staff` | `students.*`, `attendance.export`, `reports.generate` | Staff administrasi |
| **Student** | `student` | `attendance.self_view`, `schedule.view` | Siswa - view absensi sendiri |
| **Parent** | `parent` | `attendance.view_child`, `reports.view_child` | Orang tua - view absensi anak (future) |

### 2.3 Permission Structure

```
attendance.*
├── attendance.scan
├── attendance.manual_input
├── attendance.approve
├── attendance.export
├── attendance.view_all
├── attendance.view_own
└── attendance.modify

students.*
├── students.create
├── students.update
├── students.delete
├── students.view
└── students.import

classes.*
├── classes.create
├── classes.update
├── classes.delete
├── classes.assign_students
└── classes.assign_teachers

reports.*
├── reports.generate
├── reports.export
├── reports.view_all
└── reports.approve

school.*
├── school.settings
├── school.qr_generate
└── school.academic_year
```

---

## 3. Flow Absensi QR Code

### 3.1 QR Code Generation Flow

```mermaid
sequenceDiagram
    participant Admin
    participant API
    participant QRService
    participant DB
    participant Storage

    Admin->>API: Generate QR for Class/Schedule
    API->>API: Validate permission
    API->>QRService: createQRCode(schedule_id, params)
    QRService->>QRService: Generate unique token (UUID + timestamp)
    QRService->>QRService: Create QR payload with encryption
    QRService->>Storage: Save QR image
    QRService->>DB: Store QR metadata (token, schedule, validity)
    DB-->>QRService: QR record created
    QRService-->>API: QR data + image URL
    API-->>Admin: QR Code ready
```

### 3.2 Attendance Scanning Flow

```mermaid
sequenceDiagram
    participant Student as Student (Mobile)
    participant API
    participant QRValidator
    participant LocationService
    participant AttendanceService
    participant DB
    participant Notification

    Student->>API: POST /attendance/scan {qr_token, location, device_info}
    API->>API: Authenticate user (Sanctum)
    API->>QRValidator: Validate QR token
    
    alt QR Expired
        QRValidator-->>API: QR expired/invalid
        API-->>Student: Error: QR tidak valid
    else QR Valid
        QRValidator->>LocationService: Verify GPS location
        
        alt Location Invalid
            LocationService-->>API: Location out of range
            API-->>Student: Error: Lokasi tidak valid
        else Location Valid
            LocationService->>AttendanceService: Process attendance
            AttendanceService->>DB: Check duplicate scan
            
            alt Already Scanned
                DB-->>AttendanceService: Duplicate found
                AttendanceService-->>API: Already present
                API-->>Student: Sudah absen sebelumnya
            else New Scan
                AttendanceService->>DB: Create attendance record
                AttendanceService->>DB: Log attendance_logs
                DB-->>AttendanceService: Success
                AttendanceService->>Notification: Send confirmation
                Notification-->>Student: Push notification
                AttendanceService-->>API: Attendance saved
                API-->>Student: Success: Absensi berhasil
            end
        end
    end
```

### 3.3 Manual Attendance Flow (Teacher)

```mermaid
sequenceDiagram
    participant Teacher
    participant API
    participant AttendanceService
    participant DB
    participant Notification

    Teacher->>API: POST /attendance/manual {student_id, status, note}
    API->>API: Check permission (attendance.manual_input)
    API->>AttendanceService: Create manual attendance
    AttendanceService->>DB: Insert attendance record
    AttendanceService->>DB: Log in attendance_logs (manual_flag=true)
    DB-->>AttendanceService: Success
    AttendanceService->>Notification: Notify student & admin
    AttendanceService-->>API: Attendance created
    API-->>Teacher: Success
```

### 3.4 Real-time Attendance Dashboard Flow

```mermaid
sequenceDiagram
    participant Admin as Admin/Teacher
    participant API
    participant Cache
    participant DB
    participant WebSocket

    Admin->>API: GET /attendance/realtime/class/{class_id}
    API->>Cache: Check Redis cache
    
    alt Cache Hit
        Cache-->>API: Return cached data
    else Cache Miss
        API->>DB: Query attendance + students
        DB-->>API: Attendance data
        API->>Cache: Store in Redis (TTL: 60s)
    end
    
    API-->>Admin: Attendance list + stats
    
    Note over Admin,WebSocket: Real-time updates via WebSocket/Pusher
    
    loop Every scan
        API->>WebSocket: Broadcast attendance event
        WebSocket-->>Admin: Update UI real-time
    end
```

---

## 4. Keamanan & Scalability

### 4.1 Security Measures

1. **QR Code Security**
   - Time-based expiration (default: 5-15 menit)
   - One-time use option
   - Encrypted payload: `AES-256-CBC(schedule_id + timestamp + nonce)`
   - Digital signature verification

2. **Location Verification**
   - GPS coordinate validation (radius: 50-200m dari sekolah)
   - IP whitelist untuk Web Admin
   - Device fingerprinting (prevent emulator abuse)

3. **API Security**
   - Rate limiting: 60 requests/minute per user
   - JWT token with refresh mechanism
   - CORS policy strict
   - SQL injection prevention (Eloquent ORM)
   - XSS protection (Laravel built-in)
   - CSRF tokens untuk web

4. **Data Protection**
   - Encryption at rest (database encryption)
   - Encryption in transit (HTTPS/TLS 1.3)
   - Personal data anonymization in logs
   - GDPR compliance ready

### 4.2 Scalability Features

1. **Horizontal Scaling**
   - Stateless API servers
   - Load balancer ready
   - Auto-scaling K8s deployment

2. **Database Optimization**
   - Read replicas untuk reports
   - Partitioning by academic_year
   - Proper indexing strategy

3. **Caching Strategy**
   - Redis untuk session & cache
   - QR code metadata cache (5-15 min TTL)
   - Dashboard data cache (1 min TTL)
   - Query result cache untuk reports

4. **Queue Processing**
   - Async notification (email, push)
   - Report generation queue
   - Bulk import/export queue

---

## 5. Multi-Tenant Considerations

### 5.1 Tenant Isolation

```php
// School-based tenancy
- schools table sebagai tenant root
- Semua data linked ke school_id
- Global scope untuk auto-filtering
- Middleware tenant validation
```

### 5.2 Data Isolation Strategy

| Strategy | Implementation |
|----------|----------------|
| **Database Level** | Single database, school_id in all tables |
| **Schema Level** | Future: Separate schema per school (PostgreSQL) |
| **Performance** | Indexed school_id, partitioning by school |
| **Backup** | Per-school backup script |

---

## 6. API Endpoints Overview

Lihat `api_specification.md` untuk detail lengkap.

**Core Endpoints:**
- `POST /api/v1/auth/login`
- `POST /api/v1/attendance/scan`
- `POST /api/v1/attendance/manual`
- `GET /api/v1/attendance/schedule/{id}`
- `POST /api/v1/qr/generate`
- `GET /api/v1/reports/daily`

---

## 7. Next Steps

1. Review arsitektur ini dengan stakeholder
2. Finalize database schema (lihat `database_schema.md`)
3. Setup Laravel 11 project structure
4. Implement authentication & RBAC
5. Develop QR Service & Attendance Service
6. Build Mobile App (Flutter/React Native)
7. Testing & deployment
