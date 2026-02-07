# End-to-End Test Scenarios - AbsensiQR Pro

## Test Environment Setup

### Prerequisites
- Backend API running on `http://localhost:8000`
- Frontend Web running on `http://localhost:5173`
- Mobile app installed on test device
- Test database with clean state
- Valid test credentials for all roles

### Test Data Setup
```sql
-- Test School
INSERT INTO schools (name, code, address) VALUES ('SMA Test Jakarta', 'SMATEST', 'Jakarta Selatan');

-- Test Users
INSERT INTO users (name, email, role) VALUES 
  ('Admin Test', 'admin@smatest.sch.id', 'school_admin'),
  ('Guru Matematika', 'guru.math@smatest.sch.id', 'teacher'),
  ('Siswa Test', 'siswa001@smatest.sch.id', 'student'),
  ('Orang Tua Test', 'ortu001@gmail.com', 'parent');
```

---

## Flow 1: School Admin Setup Academic Structure

### Test Scenario: Complete Academic Structure Setup
**Priority**: P0 (Critical)  
**Duration**: ~15 minutes  
**Role**: School Admin

### Test Steps

#### 1.1 Login as School Admin
```
POST /api/v1/auth/login
{
  "email": "admin@smatest.sch.id",
  "password": "password123"
}
```
**Expected Result**: 
- Status: 200
- Response contains `access_token` and user role `school_admin`
- Redirect to admin dashboard

#### 1.2 Create Academic Year
```
POST /api/v1/academic-years
{
  "name": "2024/2025",
  "start_date": "2024-07-15",
  "end_date": "2025-06-30",
  "is_active": true
}
```
**Expected Result**:
- Status: 201
- Academic year created with auto-generated ID
- Set as active academic year

#### 1.3 Create Grade Levels
```
POST /api/v1/grades
{
  "name": "Kelas X",
  "level": 10,
  "academic_year_id": 1
}
```
**Expected Result**:
- Status: 201
- Grade created and linked to academic year
- Repeat for Kelas XI, XII

#### 1.4 Create Classes
```
POST /api/v1/classes
{
  "name": "X-IPA-1",
  "grade_id": 1,
  "homeroom_teacher_id": 2,
  "capacity": 36
}
```
**Expected Result**:
- Status: 201
- Class created with homeroom teacher assignment
- Capacity validation applied

#### 1.5 Create Subjects
```
POST /api/v1/subjects
{
  "name": "Matematika",
  "code": "MTK",
  "credit_hours": 4
}
```
**Expected Result**:
- Status: 201
- Subject created with unique code
- Credit hours recorded

#### 1.6 Create Schedule
```
POST /api/v1/schedules
{
  "class_id": 1,
  "subject_id": 1,
  "teacher_id": 2,
  "day_of_week": 1,
  "start_time": "07:30",
  "end_time": "09:00",
  "room": "Lab Komputer 1"
}
```
**Expected Result**:
- Status: 201
- Schedule created without conflicts
- Time slot validation passed

### API Endpoints Involved
- `POST /api/v1/auth/login`
- `POST /api/v1/academic-years`
- `POST /api/v1/grades`
- `POST /api/v1/classes`
- `POST /api/v1/subjects`
- `POST /api/v1/schedules`
- `GET /api/v1/dashboard/admin` (verification)

### Edge Cases

#### 1.E1 Duplicate Academic Year
**Test**: Create academic year with same name
**Expected**: Status 422, validation error "Academic year already exists"

#### 1.E2 Schedule Conflict
**Test**: Create overlapping schedule for same teacher
**Expected**: Status 422, validation error "Teacher has conflicting schedule"

#### 1.E3 Invalid Time Range
**Test**: Create schedule with end_time before start_time
**Expected**: Status 422, validation error "End time must be after start time"

---

## Flow 2: Bulk Student Card Generation & Photo Review

### Test Scenario: Generate Student ID Cards with Photo Validation
**Priority**: P1 (High)  
**Duration**: ~20 minutes  
**Role**: School Admin

### Test Steps

#### 2.1 Upload Student Data (Excel)
```
POST /api/v1/students/bulk-import
Content-Type: multipart/form-data
{
  "file": student_data.xlsx,
  "class_id": 1
}
```
**Expected Result**:
- Status: 202 (Accepted)
- Job queued for background processing
- Returns job_id for tracking

#### 2.2 Monitor Import Progress
```
GET /api/v1/jobs/{job_id}/status
```
**Expected Result**:
- Status: 200
- Progress percentage and current status
- Error details if any validation fails

#### 2.3 Review Imported Students
```
GET /api/v1/students?class_id=1&status=pending_photo
```
**Expected Result**:
- Status: 200
- List of students without photos
- Student data properly imported

#### 2.4 Upload Student Photos (Bulk)
```
POST /api/v1/students/photos/bulk-upload
Content-Type: multipart/form-data
{
  "photos": [photo1.jpg, photo2.jpg, ...],
  "mapping": {"12345": "photo1.jpg", "12346": "photo2.jpg"}
}
```
**Expected Result**:
- Status: 202
- Photos queued for AI processing
- Face detection job initiated

#### 2.5 Review AI Photo Processing Results
```
GET /api/v1/students/photos/review
```
**Expected Result**:
- Status: 200
- Photos categorized: approved, needs_review, rejected
- AI confidence scores displayed

#### 2.6 Manual Photo Approval
```
PATCH /api/v1/students/{student_id}/photo/approve
{
  "action": "approve",
  "cropped_coordinates": {"x": 100, "y": 50, "width": 200, "height": 250}
}
```
**Expected Result**:
- Status: 200
- Photo approved and cropped
- Student status updated to "active"

#### 2.7 Generate Student ID Cards
```
POST /api/v1/students/cards/generate
{
  "class_ids": [1, 2, 3],
  "template": "standard",
  "include_qr": true
}
```
**Expected Result**:
- Status: 202
- Card generation job queued
- PDF generation initiated

#### 2.8 Download Generated Cards
```
GET /api/v1/students/cards/download/{batch_id}
```
**Expected Result**:
- Status: 200
- PDF file with all student cards
- QR codes embedded for each student

### API Endpoints Involved
- `POST /api/v1/students/bulk-import`
- `GET /api/v1/jobs/{job_id}/status`
- `GET /api/v1/students`
- `POST /api/v1/students/photos/bulk-upload`
- `GET /api/v1/students/photos/review`
- `PATCH /api/v1/students/{id}/photo/approve`
- `POST /api/v1/students/cards/generate`
- `GET /api/v1/students/cards/download/{batch_id}`

### Edge Cases

#### 2.E1 Invalid Excel Format
**Test**: Upload Excel with missing required columns
**Expected**: Status 422, detailed validation errors per row

#### 2.E2 Duplicate Student ID
**Test**: Import student with existing student_id
**Expected**: Status 422, "Student ID already exists" error

#### 2.E3 No Face Detected
**Test**: Upload photo without detectable face
**Expected**: Photo marked as "needs_review", manual approval required

#### 2.E4 Multiple Faces Detected
**Test**: Upload photo with multiple people
**Expected**: Photo flagged for manual review with face selection options

---

## Flow 3: Teacher Starts QR Attendance Session

### Test Scenario: Create and Manage Attendance Session
**Priority**: P0 (Critical)  
**Duration**: ~10 minutes  
**Role**: Teacher

### Test Steps

#### 3.1 Teacher Login
```
POST /api/v1/auth/login
{
  "email": "guru.math@smatest.sch.id",
  "password": "password123"
}
```
**Expected Result**:
- Status: 200
- Teacher role authenticated
- Access to teacher dashboard

#### 3.2 View Today's Schedule
```
GET /api/v1/teacher/schedule/today
```
**Expected Result**:
- Status: 200
- List of today's classes with time slots
- Current/upcoming sessions highlighted

#### 3.3 Start Attendance Session
```
POST /api/v1/attendance/sessions
{
  "schedule_id": 1,
  "session_type": "regular",
  "location": {
    "latitude": -6.2088,
    "longitude": 106.8456,
    "accuracy": 10
  }
}
```
**Expected Result**:
- Status: 201
- Session created with unique QR code
- QR expires in 5 minutes (configurable)
- Location recorded for validation

#### 3.4 Generate QR Code
```
GET /api/v1/attendance/sessions/{session_id}/qr
```
**Expected Result**:
- Status: 200
- Base64 encoded QR image
- QR contains encrypted session data
- Expiry timestamp included

#### 3.5 Monitor Live Attendance
```
WebSocket: /ws/attendance/{session_id}
```
**Expected Result**:
- Real-time attendance updates
- Student names appear as they scan
- Attendance count updates live

#### 3.6 Manual Attendance Entry
```
POST /api/v1/attendance/manual
{
  "session_id": 1,
  "student_id": 123,
  "status": "present",
  "notes": "Late arrival - excused"
}
```
**Expected Result**:
- Status: 201
- Manual attendance recorded
- Timestamp and teacher ID logged

#### 3.7 Close Attendance Session
```
PATCH /api/v1/attendance/sessions/{session_id}/close
{
  "absent_students": [124, 125],
  "notes": "Session completed normally"
}
```
**Expected Result**:
- Status: 200
- Session marked as closed
- Absent students automatically marked
- Final attendance summary generated

### API Endpoints Involved
- `POST /api/v1/auth/login`
- `GET /api/v1/teacher/schedule/today`
- `POST /api/v1/attendance/sessions`
- `GET /api/v1/attendance/sessions/{id}/qr`
- `WebSocket /ws/attendance/{session_id}`
- `POST /api/v1/attendance/manual`
- `PATCH /api/v1/attendance/sessions/{id}/close`

### Edge Cases

#### 3.E1 No Active Schedule
**Test**: Try to start session outside scheduled time
**Expected**: Status 422, "No active schedule found" error

#### 3.E2 Location Mismatch
**Test**: Start session from wrong location
**Expected**: Status 422, "Location validation failed" error

#### 3.E3 Duplicate Session
**Test**: Start session when one already active
**Expected**: Status 409, "Active session already exists" error

---

## Flow 4: Student Scans QR and Records Attendance

### Test Scenario: Student Mobile App Attendance Flow
**Priority**: P0 (Critical)  
**Duration**: ~5 minutes  
**Role**: Student (Mobile App)

### Test Steps

#### 4.1 Student Login (Mobile)
```
POST /api/v1/auth/login
{
  "email": "siswa001@smatest.sch.id",
  "password": "password123",
  "device_info": {
    "device_id": "android_123456",
    "platform": "android",
    "app_version": "1.0.0"
  }
}
```
**Expected Result**:
- Status: 200
- Student role authenticated
- Device fingerprint recorded
- Mobile-specific token issued

#### 4.2 Check Location Permission
**Mobile Action**: Request location permission
**Expected Result**:
- GPS permission granted
- Location accuracy within 10 meters
- Background location disabled

#### 4.3 Open QR Scanner
**Mobile Action**: Navigate to QR scanner screen
**Expected Result**:
- Camera permission granted
- QR scanner interface loaded
- Real-time camera preview active

#### 4.4 Scan QR Code
**Mobile Action**: Point camera at teacher's QR code
```
POST /api/v1/attendance/scan
{
  "qr_data": "encrypted_session_data",
  "location": {
    "latitude": -6.2088,
    "longitude": 106.8456,
    "accuracy": 8
  },
  "device_info": {
    "device_id": "android_123456",
    "timestamp": "2024-02-02T08:15:30Z"
  }
}
```
**Expected Result**:
- Status: 201
- Attendance recorded successfully
- Success message displayed
- Attendance time logged

#### 4.5 Verify Attendance Recorded
```
GET /api/v1/student/attendance/today
```
**Expected Result**:
- Status: 200
- Today's attendance status shown
- Subject and time details included
- Attendance marked as "present"

#### 4.6 View Attendance History
```
GET /api/v1/student/attendance/history?limit=30
```
**Expected Result**:
- Status: 200
- Last 30 days attendance records
- Present/absent/late status per subject
- Attendance percentage calculated

### API Endpoints Involved
- `POST /api/v1/auth/login`
- `POST /api/v1/attendance/scan`
- `GET /api/v1/student/attendance/today`
- `GET /api/v1/student/attendance/history`

### Edge Cases

#### 4.E1 Expired QR Code
**Test**: Scan QR code after 5-minute expiry
**Expected**: Status 410, "QR code has expired" error

#### 4.E2 Duplicate Scan
**Test**: Scan same QR code twice
**Expected**: Status 409, "Attendance already recorded" error

#### 4.E3 Wrong Location
**Test**: Scan QR from different location (>50m away)
**Expected**: Status 422, "Location validation failed" error

#### 4.E4 No Active Session
**Test**: Scan QR when session is closed
**Expected**: Status 410, "Attendance session has ended" error

#### 4.E5 Wrong Student
**Test**: Student not enrolled in class tries to scan
**Expected**: Status 403, "Not authorized for this session" error

---

## Flow 5: Student Views Dashboard Data

### Test Scenario: Student Dashboard and Analytics
**Priority**: P2 (Medium)  
**Duration**: ~8 minutes  
**Role**: Student (Mobile/Web)

### Test Steps

#### 5.1 Load Dashboard Overview
```
GET /api/v1/student/dashboard
```
**Expected Result**:
- Status: 200
- Today's schedule displayed
- Attendance percentage (current month)
- Recent attendance records
- Upcoming classes highlighted

#### 5.2 View Attendance Statistics
```
GET /api/v1/student/attendance/stats?period=monthly
```
**Expected Result**:
- Status: 200
- Monthly attendance percentage per subject
- Total present/absent/late counts
- Attendance trend chart data
- Comparison with class average

#### 5.3 View Schedule
```
GET /api/v1/student/schedule/weekly
```
**Expected Result**:
- Status: 200
- Weekly class schedule
- Teacher names and room numbers
- Time slots clearly displayed
- Current day highlighted

#### 5.4 View Grades (if available)
```
GET /api/v1/student/grades/current-semester
```
**Expected Result**:
- Status: 200
- Current semester grades per subject
- Assignment and exam scores
- GPA calculation
- Grade trends

#### 5.5 View Notifications
```
GET /api/v1/student/notifications?unread=true
```
**Expected Result**:
- Status: 200
- Unread notifications list
- Attendance alerts
- Schedule changes
- General announcements

#### 5.6 Update Profile
```
PATCH /api/v1/student/profile
{
  "phone": "+628123456789",
  "emergency_contact": "+628987654321",
  "address": "Jakarta Selatan"
}
```
**Expected Result**:
- Status: 200
- Profile updated successfully
- Changes reflected immediately
- Parent notification sent (if configured)

### API Endpoints Involved
- `GET /api/v1/student/dashboard`
- `GET /api/v1/student/attendance/stats`
- `GET /api/v1/student/schedule/weekly`
- `GET /api/v1/student/grades/current-semester`
- `GET /api/v1/student/notifications`
- `PATCH /api/v1/student/profile`

### Edge Cases

#### 5.E1 No Data Available
**Test**: New student with no attendance history
**Expected**: Empty state with helpful message, no errors

#### 5.E2 Network Offline (Mobile)
**Test**: Load dashboard without internet connection
**Expected**: Cached data displayed with offline indicator

#### 5.E3 Semester Transition
**Test**: View data during semester change period
**Expected**: Clear indication of current vs previous semester data

---

## Flow 6: Parent Receives Notification

### Test Scenario: Parent Notification System
**Priority**: P1 (High)  
**Duration**: ~12 minutes  
**Role**: Parent

### Test Steps

#### 6.1 Configure Notification Preferences
```
POST /api/v1/parent/notification-settings
{
  "whatsapp_enabled": true,
  "email_enabled": true,
  "sms_enabled": false,
  "attendance_alerts": true,
  "grade_alerts": true,
  "schedule_changes": true
}
```
**Expected Result**:
- Status: 200
- Preferences saved successfully
- Verification messages sent to configured channels

#### 6.2 Student Attendance Triggers Notification
**Trigger**: Student scans attendance (from Flow 4)
**Background Process**:
```
Event: AttendanceRecorded
Listener: SendParentNotification
Queue Job: ProcessWhatsAppNotification
```
**Expected Result**:
- WhatsApp message sent within 2 minutes
- Message contains: student name, subject, time, status
- Delivery status tracked

#### 6.3 Parent Receives WhatsApp Notification
**WhatsApp Message Format**:
```
🎓 AbsensiQR Pro - SMA Test Jakarta

Siswa: Ahmad Rizki (12345)
Mata Pelajaran: Matematika
Waktu: 08:15 WIB
Status: ✅ HADIR

Kelas: X-IPA-1
Guru: Ibu Sari Matematika

Lihat detail: https://app.absensiQR.com/parent/attendance
```
**Expected Result**:
- Message received on parent's WhatsApp
- All information accurate and formatted properly
- Link works and redirects to parent portal

#### 6.4 Parent Views Attendance via Link
```
GET /api/v1/parent/student/{student_id}/attendance/today
```
**Expected Result**:
- Status: 200
- Today's complete attendance record
- All subjects and their status
- Time stamps for each attendance

#### 6.5 Absence Notification (Late Alert)
**Trigger**: Student doesn't scan within 15 minutes of class start
**Background Process**:
```
Scheduled Job: CheckMissingAttendance (runs every 15 minutes)
Queue Job: SendAbsenceAlert
```
**Expected Result**:
- Alert sent to parent via WhatsApp
- Message indicates potential absence
- Instructions to contact school if needed

#### 6.6 Parent Portal Login
```
POST /api/v1/auth/login
{
  "email": "ortu001@gmail.com",
  "password": "password123"
}
```
**Expected Result**:
- Status: 200
- Parent role authenticated
- Access to child's data only

#### 6.7 View Child's Dashboard
```
GET /api/v1/parent/children/{child_id}/dashboard
```
**Expected Result**:
- Status: 200
- Child's attendance summary
- Recent activity timeline
- Academic performance overview
- Upcoming schedule

### API Endpoints Involved
- `POST /api/v1/parent/notification-settings`
- `GET /api/v1/parent/student/{id}/attendance/today`
- `POST /api/v1/auth/login`
- `GET /api/v1/parent/children/{id}/dashboard`
- Background: WhatsApp API integration
- Background: Email service integration

### Edge Cases

#### 6.E1 Invalid WhatsApp Number
**Test**: Configure notification with invalid phone number
**Expected**: Status 422, phone number validation error

#### 6.E2 WhatsApp Service Down
**Test**: Send notification when WhatsApp API is unavailable
**Expected**: Fallback to email, retry mechanism activated

#### 6.E3 Multiple Children
**Test**: Parent with multiple children receives notifications
**Expected**: Each notification clearly identifies which child

#### 6.E4 Notification Spam Prevention
**Test**: Multiple rapid attendance changes
**Expected**: Notifications batched, max 1 per 5 minutes per student

---

## Flow 7: School Admin Exports Monthly Report

### Test Scenario: Comprehensive Monthly Reporting
**Priority**: P1 (High)  
**Duration**: ~15 minutes  
**Role**: School Admin

### Test Steps

#### 7.1 Access Reports Dashboard
```
GET /api/v1/admin/reports/dashboard
```
**Expected Result**:
- Status: 200
- Available report types listed
- Quick stats for current month
- Recent report generation history

#### 7.2 Configure Monthly Report Parameters
```
POST /api/v1/reports/attendance/monthly
{
  "month": "2024-02",
  "classes": [1, 2, 3],
  "subjects": ["all"],
  "format": "excel",
  "include_charts": true,
  "include_summary": true,
  "email_recipients": ["admin@smatest.sch.id"]
}
```
**Expected Result**:
- Status: 202 (Accepted)
- Report generation job queued
- Job ID returned for tracking
- Estimated completion time provided

#### 7.3 Monitor Report Generation Progress
```
GET /api/v1/reports/jobs/{job_id}/status
```
**Expected Result**:
- Status: 200
- Progress percentage (0-100%)
- Current processing stage
- ETA for completion

#### 7.4 Download Generated Report
```
GET /api/v1/reports/download/{report_id}
```
**Expected Result**:
- Status: 200
- Excel file download initiated
- File contains multiple sheets:
  - Summary Dashboard
  - Daily Attendance
  - Student Statistics
  - Teacher Performance
  - Class Comparisons

#### 7.5 Verify Report Content
**Excel Sheet 1: Summary Dashboard**
- Total students: 108
- Overall attendance rate: 87.5%
- Most attended subject: Matematika (92%)
- Least attended subject: Olahraga (78%)
- Peak attendance time: 07:30-08:30

**Excel Sheet 2: Daily Attendance**
- Date-wise attendance for each class
- Present/Absent/Late counts
- Daily attendance percentages
- Holiday and weekend exclusions

**Excel Sheet 3: Student Statistics**
- Individual student attendance rates
- Students with <80% attendance highlighted
- Perfect attendance students listed
- Chronic absenteeism alerts

#### 7.6 Schedule Automated Reports
```
POST /api/v1/reports/schedule
{
  "report_type": "monthly_attendance",
  "schedule": "0 0 1 * *",
  "recipients": ["admin@smatest.sch.id", "principal@smatest.sch.id"],
  "format": "pdf",
  "auto_email": true
}
```
**Expected Result**:
- Status: 201
- Automated report scheduled
- Cron job created
- Confirmation email sent

#### 7.7 Export Student Cards Report
```
POST /api/v1/reports/student-cards
{
  "classes": [1, 2, 3],
  "include_photos": true,
  "include_qr": true,
  "format": "pdf"
}
```
**Expected Result**:
- Status: 202
- Student cards report queued
- PDF with all student information
- QR codes for each student included

### API Endpoints Involved
- `GET /api/v1/admin/reports/dashboard`
- `POST /api/v1/reports/attendance/monthly`
- `GET /api/v1/reports/jobs/{job_id}/status`
- `GET /api/v1/reports/download/{report_id}`
- `POST /api/v1/reports/schedule`
- `POST /api/v1/reports/student-cards`

### Edge Cases

#### 7.E1 Large Dataset Timeout
**Test**: Generate report for school with 5000+ students
**Expected**: Background processing, progress updates, no timeout errors

#### 7.E2 No Data Available
**Test**: Generate report for month with no attendance data
**Expected**: Empty report with clear message, no errors

#### 7.E3 Corrupted Data
**Test**: Generate report with some corrupted attendance records
**Expected**: Report generated with data quality warnings

#### 7.E4 Storage Limit Exceeded
**Test**: Generate multiple large reports exceeding storage quota
**Expected**: Graceful error, cleanup of old reports, notification sent

---

## Cross-Flow Integration Tests

### Integration Test 1: Complete Student Journey
**Duration**: ~45 minutes
**Flows**: 1 → 2 → 3 → 4 → 5 → 6

1. Admin sets up academic structure
2. Admin imports students and generates cards
3. Teacher starts attendance session
4. Student scans QR and records attendance
5. Student views updated dashboard
6. Parent receives notification

**Success Criteria**: All flows complete without errors, data consistency maintained

### Integration Test 2: Multi-Class Attendance Session
**Duration**: ~30 minutes
**Scenario**: Teacher handles 3 different classes in sequence

1. Start session for Class A (07:30-08:30)
2. Students scan and record attendance
3. Close session and start new session for Class B (08:30-09:30)
4. Handle late arrivals and manual entries
5. Generate attendance summary for all classes

**Success Criteria**: No session conflicts, accurate attendance records, proper session transitions

### Integration Test 3: System Load Test
**Duration**: ~60 minutes
**Scenario**: 500 students scanning QR simultaneously

1. 10 teachers start sessions simultaneously
2. 500 students scan QR codes within 5-minute window
3. Monitor system performance and response times
4. Verify all attendance records are accurate
5. Check notification delivery success rate

**Success Criteria**: <2 second response time, 99.9% success rate, no data loss

---

## Performance Benchmarks

### API Response Time Targets
- Authentication: <500ms
- QR Generation: <1000ms
- Attendance Scan: <2000ms
- Dashboard Load: <1500ms
- Report Generation: <30 seconds (background)

### Concurrent User Limits
- Simultaneous QR scans: 1000 users
- Active attendance sessions: 100 sessions
- Report generation: 10 concurrent reports
- WebSocket connections: 500 connections

### Data Volume Limits
- Students per school: 10,000
- Attendance records per day: 50,000
- Monthly report size: <50MB
- Photo storage per student: <2MB

---

## Test Automation Framework

### Recommended Tools
- **API Testing**: Postman/Newman, REST Assured
- **Mobile Testing**: Appium, Detox
- **Web Testing**: Cypress, Playwright
- **Load Testing**: JMeter, Artillery
- **Database Testing**: DBUnit, Testcontainers

### CI/CD Integration
```yaml
# .github/workflows/e2e-tests.yml
name: E2E Tests
on: [push, pull_request]
jobs:
  e2e-tests:
    runs-on: ubuntu-latest
    steps:
      - name: Setup Test Environment
        run: |
          docker-compose up -d
          npm run test:e2e:setup
      - name: Run API Tests
        run: newman run e2e-tests.postman_collection.json
      - name: Run Mobile Tests
        run: detox test --configuration ios.sim.release
      - name: Generate Test Report
        run: allure generate --clean
```

### Test Data Management
- Use database seeders for consistent test data
- Implement test data cleanup after each test suite
- Use factories for generating realistic test data
- Maintain separate test database for isolation

This comprehensive test suite covers all critical user journeys and edge cases for your school attendance system. Each test scenario includes detailed steps, expected results, and API endpoints to ensure thorough validation of the system's functionality.