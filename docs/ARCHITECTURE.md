# absensiQRPRO - System Architecture

## Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     absensiQRPRO System                      │
└─────────────────────────────────────────────────────────────┘

┌──────────────────┐          ┌──────────────────┐
│   Web Browser    │          │   Mobile App     │
│   (Admin)        │          │   (Teacher)      │
│                  │          │                  │
│  - Dashboard     │          │  - Login         │
│  - Student List  │          │  - QR Scanner    │
│  - Attendance    │          │  - History       │
│  - Reports       │          │  - Mark Present  │
└────────┬─────────┘          └────────┬─────────┘
         │                             │
         │                             │
         └─────────────┬───────────────┘
                       │
                       │ HTTP/REST API
                       │
         ┌─────────────▼──────────────┐
         │      Backend API           │
         │      (PHP)                 │
         │                            │
         │  - /login.php              │
         │  - /scan_qr.php            │
         │  - /mark_attendance.php    │
         │  - /students.php           │
         │  - /attendance.php         │
         └─────────────┬──────────────┘
                       │
                       │ PDO/MySQL
                       │
         ┌─────────────▼──────────────┐
         │      MySQL Database        │
         │                            │
         │  - students                │
         │  - teachers                │
         │  - attendance              │
         └────────────────────────────┘
```

## Component Details

### 1. Web Browser (Admin Interface)

**Purpose:** Administrative dashboard for school staff

**Features:**
- View real-time attendance statistics
- Manage student database
- Generate QR codes for student cards
- View attendance reports by date
- Print student ID cards

**Technology:** HTML, CSS, JavaScript

**Pages:**
- `index.html` - Dashboard with statistics
- `students.html` - Student management and QR generation
- `attendance.html` - Attendance records viewer

### 2. Mobile App (Teacher Interface)

**Purpose:** Mobile application for teachers to scan student QR codes

**Features:**
- Teacher authentication
- Real-time QR code scanning
- Quick attendance marking
- View daily attendance history
- Offline storage (login session)

**Technology:** React Native

**Screens:**
- `LoginScreen` - Teacher authentication
- `HomeScreen` - Dashboard and navigation
- `ScannerScreen` - QR code scanner with camera
- `AttendanceHistoryScreen` - View attendance records

### 3. Backend API (Business Logic)

**Purpose:** RESTful API for data management

**Endpoints:**

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/login.php` | POST | Teacher authentication |
| `/scan_qr.php` | POST | Get student by QR code |
| `/mark_attendance.php` | POST | Record attendance |
| `/students.php` | GET | Get all students |
| `/attendance.php` | GET | Get attendance by date |

**Technology:** PHP 7.4+, PDO

**Models:**
- `Student.php` - Student CRUD operations
- `Teacher.php` - Teacher management and authentication
- `Attendance.php` - Attendance record management

### 4. Database (Data Layer)

**Purpose:** Persistent storage for all system data

**Tables:**

**students**
- id (PK)
- nis (unique)
- name
- class
- qr_code (unique)
- photo
- timestamps

**teachers**
- id (PK)
- nip (unique)
- name
- email (unique)
- password (hashed)
- phone
- photo
- timestamps

**attendance**
- id (PK)
- student_id (FK → students)
- teacher_id (FK → teachers)
- date
- time
- status (enum: present, late, absent, excused)
- notes
- timestamps

**Technology:** MySQL 5.7+

## Data Flow

### Attendance Recording Flow

```
1. Teacher opens mobile app
   ↓
2. Login with credentials
   ↓
3. Navigate to QR Scanner
   ↓
4. Scan student QR code on ID card
   ↓
5. Mobile app sends QR code to API
   ↓
6. API validates QR and returns student info
   ↓
7. Mobile app shows student details
   ↓
8. Teacher confirms attendance status
   ↓
9. Mobile app sends attendance data to API
   ↓
10. API saves to database
    ↓
11. Success confirmation to mobile app
    ↓
12. Admin can view on web dashboard
```

### QR Code Generation Flow

```
1. Admin accesses students page
   ↓
2. Clicks "Tampilkan QR" button
   ↓
3. JavaScript generates QR code from student's qr_code field
   ↓
4. QR code displayed inline
   ↓
5. Admin can print using browser print function
   ↓
6. QR code printed on student ID card
```

## Security Features

1. **Password Hashing:**
   - Teacher passwords stored using `password_hash()` (bcrypt)
   - Verified using `password_verify()`

2. **SQL Injection Prevention:**
   - PDO prepared statements used throughout
   - All inputs bound as parameters

3. **CORS Configuration:**
   - Headers configured for mobile app access
   - Can be restricted to specific domains in production

4. **Unique Constraints:**
   - QR codes are unique per student
   - Email addresses unique per teacher
   - One attendance record per student per day

## Deployment Architecture

### Development Environment
```
┌─────────────────────────────────────┐
│     localhost / 127.0.0.1           │
│                                     │
│  ┌──────────────────────────────┐  │
│  │  XAMPP / WAMP / LAMP         │  │
│  │                              │  │
│  │  - Apache Web Server         │  │
│  │  - MySQL Database            │  │
│  │  - PHP Runtime               │  │
│  └──────────────────────────────┘  │
│                                     │
│  Website: /htdocs/absensiQRPRO     │
│                                     │
└─────────────────────────────────────┘

Mobile App Development:
- Android Emulator or Physical Device
- iOS Simulator (macOS only)
- Connected to localhost API
```

### Production Environment
```
┌─────────────────────────────────────┐
│     Cloud Server / VPS              │
│     (e.g., DigitalOcean, AWS)      │
│                                     │
│  ┌──────────────────────────────┐  │
│  │  Web Server (Apache/Nginx)   │  │
│  │  - SSL Certificate (HTTPS)   │  │
│  └──────────────────────────────┘  │
│                                     │
│  ┌──────────────────────────────┐  │
│  │  MySQL Database              │  │
│  │  - Backup enabled            │  │
│  └──────────────────────────────┘  │
│                                     │
│  Domain: school.example.com         │
│                                     │
└─────────────────────────────────────┘

Mobile App:
- Compiled APK for Android
- Distributed via:
  - Direct download
  - Internal app store
  - Google Play Store (optional)
```

## Scalability Considerations

### Current Capacity
- Small to medium schools (500-2000 students)
- Single server deployment
- Synchronous processing

### Future Enhancements
1. **Caching Layer:**
   - Redis for session management
   - Cache frequently accessed student data

2. **Load Balancing:**
   - Multiple web servers for high traffic
   - Database replication for read operations

3. **Queue System:**
   - Async processing for bulk operations
   - Background jobs for reports

4. **CDN Integration:**
   - Static assets delivery
   - Image optimization

5. **Microservices:**
   - Separate authentication service
   - Dedicated QR processing service
   - Independent reporting engine

## Monitoring & Maintenance

### Key Metrics to Monitor

1. **Performance:**
   - API response time
   - Database query performance
   - Mobile app load time

2. **Usage:**
   - Daily active teachers
   - Attendance records per day
   - QR scans per hour (peak times)

3. **Errors:**
   - Failed login attempts
   - QR scan failures
   - Database connection errors

### Regular Maintenance Tasks

1. **Daily:**
   - Monitor error logs
   - Check attendance submission rate

2. **Weekly:**
   - Database backup verification
   - Review system performance

3. **Monthly:**
   - Update dependencies
   - Security patches
   - Archive old attendance data

## Integration Points

### Potential Integrations

1. **SMS Gateway:**
   - Send parent notifications
   - Absence alerts

2. **Email Service:**
   - Daily attendance reports
   - Teacher notifications

3. **School Management System:**
   - Export to existing systems
   - Import student data

4. **Payment Gateway:**
   - Premium features (future)

5. **Cloud Storage:**
   - Backup student photos
   - Archive attendance data

---

**Document Version:** 1.0  
**Last Updated:** January 2024
