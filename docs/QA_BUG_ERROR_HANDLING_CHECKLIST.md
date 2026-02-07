# QA Bug & Error Handling Checklist
## School Attendance System - AbsensiQRPro

**Document Version**: 1.0.0  
**Last Updated**: February 2, 2026  
**Author**: Senior QA Engineering Team  
**System**: AbsensiQRPro - School Attendance Management

---

## 📋 Table of Contents

1. [Admin Setup Flow](#1-admin-setup-flow)
2. [Student Card Generation](#2-student-card-generation)
3. [Teacher QR Attendance](#3-teacher-qr-attendance)
4. [Student Dashboard](#4-student-dashboard)
5. [Notification System](#5-notification-system)
6. [Reporting System](#6-reporting-system)
7. [Cross-System Concerns](#7-cross-system-concerns)
8. [Performance Safeguards](#8-performance-safeguards)
9. [Security Testing](#9-security-testing)

---

## 1. Admin Setup Flow

### 1.1 Edge Cases

#### School Registration
- [ ] **Empty school database** - First school registration
  - Expected: Successful creation with default settings
  - Error: None expected
  
- [ ] **Duplicate NPSN** - School with same NPSN already exists
  - Expected: `409 Conflict` with message "NPSN sudah terdaftar"
  - Prevention: Unique constraint on `schools.npsn`
  
- [ ] **Invalid NPSN format** - Non-numeric or wrong length
  - Expected: `422 Unprocessable Entity` with validation errors
  - Prevention: Client-side validation + backend regex validation
  
- [ ] **Package limit reached** - School tries to add more users than package allows
  - Expected: `403 Forbidden` with message "Batas paket tercapai"
  - Prevention: Check package limits before creation

#### Admin Account Creation
- [ ] **Duplicate username** - Username already exists in system
  - Expected: `409 Conflict` with message "Username sudah digunakan"
  - Prevention: Unique constraint on `users.username`
  
- [ ] **Duplicate email** - Email already registered
  - Expected: `409 Conflict` with message "Email sudah terdaftar"
  - Prevention: Unique constraint on `users.email`
  
- [ ] **Weak password** - Password doesn't meet requirements
  - Expected: `422 Unprocessable Entity` with password rules
  - Prevention: Password strength validation (min 8 chars, mixed case, numbers)
  
- [ ] **Invalid email format** - Malformed email address
  - Expected: `422 Unprocessable Entity` with validation error
  - Prevention: Email format validation

#### Class & Subject Setup
- [ ] **Duplicate class name** - Class name exists in same school
  - Expected: `409 Conflict` with message "Nama kelas sudah ada"
  - Prevention: Unique constraint on `classes(school_id, name)`
  
- [ ] **Class without homeroom teacher** - Creating class without assigning teacher
  - Expected: Allow creation, homeroom_teacher_id is nullable
  - Warning: Show warning in UI
  
- [ ] **Subject without grade level** - Creating subject without specifying grade
  - Expected: Allow creation, grade_level is nullable (for all grades)
  - Behavior: Subject available for all grades
  
- [ ] **Deleting class with students** - Attempting to delete non-empty class
  - Expected: `409 Conflict` with message "Kelas masih memiliki siswa"
  - Prevention: Check student count before deletion

### 1.2 Race Conditions

#### Concurrent Admin Creation
```
Scenario: Two admins creating users with same username simultaneously

Prevention:
1. Database unique constraint (PRIMARY)
2. Optimistic locking with version field
3. Transaction isolation level: READ COMMITTED

Test:
- Create 2 parallel requests with same username
- Expected: One succeeds (201), one fails (409)
- Verify: Only 1 record in database
```

#### Concurrent Package Limit Checks
```
Scenario: Multiple users being created when near package limit

Prevention:
1. Use database transaction with SELECT FOR UPDATE
2. Lock school record during user creation
3. Re-check limit after lock acquired

Code Example:
DB::transaction(function () use ($school, $userData) {
    $school = School::where('id', $school->id)
        ->lockForUpdate()
        ->first();
    
    if ($school->users()->count() >= $school->package->user_limit) {
        throw new PackageLimitException();
    }
    
    return User::create($userData);
});

Test:
- Create 10 parallel requests when limit is 5
- Expected: Exactly 5 succeed, 5 fail with limit error
```

### 1.3 Expected Error Responses

```json
// Validation Error (422)
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "username": ["Username sudah digunakan"],
    "email": ["Format email tidak valid"],
    "password": ["Password minimal 8 karakter"]
  }
}

// Package Limit Error (403)
{
  "success": false,
  "error": "PACKAGE_LIMIT_EXCEEDED",
  "message": "Batas paket Basic tercapai",
  "details": {
    "current": 50,
    "limit": 50,
    "resource": "students",
    "action": "Upgrade paket untuk menambah lebih banyak siswa"
  }
}

// Duplicate Resource (409)
{
  "success": false,
  "error": "DUPLICATE_RESOURCE",
  "message": "Username sudah terdaftar",
  "details": {
    "field": "username",
    "value": "admin123"
  }
}
```

### 1.4 Performance Safeguards

- [ ] **Bulk import validation** - Max 1000 records per import
  - Timeout: 60 seconds
  - Memory: 256MB limit
  - Chunking: Process in batches of 100
  
- [ ] **Search queries** - Indexed fields for fast lookup
  - Index: `users(username)`, `users(email)`, `schools(npsn)`
  - Query timeout: 5 seconds
  
- [ ] **Cascade deletion** - Prevent accidental mass deletion
  - Require confirmation for >10 records
  - Soft delete for recovery
  - Background job for >100 records

---

## 2. Student Card Generation

### 2.1 Edge Cases

#### Single Card Generation
- [ ] **Student without photo** - Generating card for student with no photo
  - Expected: `422 Unprocessable Entity` with message "Foto siswa belum diupload"
  - Prevention: Check photo existence before generation
  
- [ ] **Student already has active card** - Regenerating card
  - Expected: Invalidate old card, generate new one
  - Behavior: Old card marked as `revoked`, new card created
  
- [ ] **Invalid student ID** - Student doesn't exist
  - Expected: `404 Not Found` with message "Siswa tidak ditemukan"
  - Prevention: Validate student existence
  
- [ ] **Student from different school** - Admin trying to generate card for other school's student
  - Expected: `403 Forbidden` with message "Akses ditolak"
  - Prevention: Check school_id match

#### Bulk Card Generation
- [ ] **Empty class** - Generating cards for class with no students
  - Expected: `422 Unprocessable Entity` with message "Kelas tidak memiliki siswa"
  - Prevention: Check student count > 0
  
- [ ] **Class with 500+ students** - Large class bulk generation
  - Expected: Queue job, return `202 Accepted` with job ID
  - Behavior: Process in background, notify when complete
  
- [ ] **Some students without photos** - Partial bulk generation
  - Expected: Generate for students with photos, skip others
  - Response: Return success count and skipped list
  
- [ ] **Concurrent bulk generation** - Multiple bulk jobs for same class
  - Expected: Queue jobs sequentially, prevent duplicates
  - Prevention: Check for pending jobs before queueing

#### QR Code Generation
- [ ] **QR encryption failure** - Encryption service unavailable
  - Expected: `500 Internal Server Error` with retry mechanism
  - Fallback: Queue for retry, notify admin
  
- [ ] **QR token collision** - Extremely rare duplicate token
  - Expected: Regenerate with new nonce
  - Prevention: UUID + timestamp + random nonce
  
- [ ] **PDF generation failure** - PDF library error
  - Expected: `500 Internal Server Error` with error details
  - Retry: Automatic retry up to 3 times
  
- [ ] **Storage full** - No space for PDF files
  - Expected: `507 Insufficient Storage` with alert
  - Prevention: Monitor disk space, alert at 80%

### 2.2 Race Conditions

#### Concurrent Card Generation
```
Scenario: Admin clicks "Generate" multiple times rapidly

Prevention:
1. Idempotency key in request
2. Check for existing pending/active card
3. Lock student record during generation

Code Example:
DB::transaction(function () use ($student) {
    $student = Student::where('id', $student->id)
        ->lockForUpdate()
        ->first();
    
    // Check for active card
    $activeCard = $student->studentCards()
        ->where('status', 'active')
        ->first();
    
    if ($activeCard) {
        return $activeCard; // Return existing
    }
    
    return StudentCard::create([...]);
});

Test:
- Send 5 parallel card generation requests for same student
- Expected: Only 1 card created, others return existing card
```

#### Bulk Generation Queue
```
Scenario: Multiple admins trigger bulk generation for same class

Prevention:
1. Check for existing queued job
2. Use unique job ID based on class_id + date
3. Prevent duplicate jobs in queue

Code Example:
$jobId = "bulk_card_gen_{$classId}_" . now()->format('Ymd');

if (Cache::has($jobId)) {
    throw new JobAlreadyQueuedException();
}

Cache::put($jobId, true, 3600); // 1 hour lock

GenerateClassCardsJob::dispatch($classId)
    ->onQueue('card-generation');

Test:
- Trigger bulk generation from 3 different admin accounts
- Expected: Only 1 job queued, others receive "already processing" message
```

### 2.3 Expected Error Responses

```json
// Photo Missing (422)
{
  "success": false,
  "error": "PHOTO_REQUIRED",
  "message": "Foto siswa belum diupload",
  "details": {
    "student_id": 123,
    "student_name": "Ahmad Rizki",
    "action": "Upload foto siswa terlebih dahulu"
  }
}

// Bulk Generation Partial Success (207)
{
  "success": true,
  "message": "Kartu berhasil dibuat untuk 45 dari 50 siswa",
  "data": {
    "total": 50,
    "generated": 45,
    "skipped": 5,
    "skipped_students": [
      {
        "id": 101,
        "name": "Budi Santoso",
        "reason": "Foto belum diupload"
      },
      // ... 4 more
    ]
  }
}

// Job Queued (202)
{
  "success": true,
  "message": "Pembuatan kartu sedang diproses",
  "data": {
    "job_id": "bulk_card_gen_10_20260202",
    "status": "queued",
    "estimated_time": "5-10 menit",
    "notification": "Anda akan menerima notifikasi saat selesai"
  }
}
```

### 2.4 Performance Safeguards

- [ ] **PDF generation timeout** - Max 30 seconds per card
  - Retry: 3 attempts with exponential backoff
  - Fallback: Queue for manual review
  
- [ ] **Bulk generation limits** - Max 1000 cards per job
  - Chunking: Process 50 cards at a time
  - Memory: 512MB per worker
  
- [ ] **QR code caching** - Cache encrypted QR data
  - TTL: 24 hours
  - Storage: Redis
  
- [ ] **Storage optimization** - Compress PDFs
  - Compression: 70% quality
  - Cleanup: Delete cards older than 1 year (configurable)

---

## 3. Teacher QR Attendance

### 3.1 Edge Cases

#### QR Code Generation by Teacher
- [ ] **No active schedule** - Teacher generating QR without current class
  - Expected: `422 Unprocessable Entity` with message "Tidak ada jadwal aktif"
  - Prevention: Check current schedule before allowing generation
  
- [ ] **Outside attendance window** - Generating QR outside allowed time
  - Expected: `403 Forbidden` with message "Di luar jam absensi"
  - Prevention: Check against school attendance settings
  
- [ ] **Weekend/holiday** - Generating QR on non-school day
  - Expected: `403 Forbidden` with message "Bukan hari sekolah"
  - Prevention: Check school calendar
  
- [ ] **QR already active** - Teacher has unexpired QR code
  - Expected: Return existing QR or allow regeneration
  - Behavior: Invalidate old QR, generate new one

#### Student Scanning QR
- [ ] **Expired QR code** - Student scans after validity period
  - Expected: `410 Gone` with message "QR code sudah kadaluarsa"
  - Prevention: Check timestamp + validity window
  
- [ ] **Wrong class** - Student scans QR for different class
  - Expected: `403 Forbidden` with message "QR code bukan untuk kelas Anda"
  - Prevention: Validate student's class_id matches QR class_id
  
- [ ] **Already scanned** - Student scans same QR twice
  - Expected: `409 Conflict` with message "Anda sudah absen"
  - Prevention: Check existing attendance record for date
  
- [ ] **Replay attack** - Reusing old QR code
  - Expected: `410 Gone` with message "QR code tidak valid"
  - Prevention: Nonce validation + timestamp check
  
- [ ] **GPS outside radius** - Student location too far from school
  - Expected: `403 Forbidden` with message "Lokasi di luar jangkauan sekolah"
  - Prevention: Calculate distance, check against school radius
  
- [ ] **GPS disabled** - Student device has location services off
  - Expected: `422 Unprocessable Entity` with message "Aktifkan lokasi"
  - Prevention: Check for GPS coordinates in request

#### Attendance Status Determination
- [ ] **Scan at exact boundary** - Student scans at check_in_end time
  - Expected: Mark as "on time" (inclusive boundary)
  - Logic: `scan_time <= check_in_end`
  
- [ ] **Scan during late window** - Student scans after check_in_end but within threshold
  - Expected: Mark as "late"
  - Logic: `check_in_end < scan_time <= (check_in_end + late_threshold)`
  
- [ ] **Scan after late window** - Student scans too late
  - Expected: `403 Forbidden` with message "Waktu absensi telah berakhir"
  - Prevention: Reject scans after late threshold

### 3.2 Race Conditions

#### Concurrent QR Scans
```
Scenario: Multiple students scan same QR code simultaneously

Prevention:
1. Atomic attendance record creation
2. Unique constraint on (student_id, date, schedule_id)
3. Transaction with row locking

Code Example:
DB::transaction(function () use ($student, $qrData, $location) {
    // Check for existing attendance
    $existing = Attendance::where('student_id', $student->id)
        ->where('date', today())
        ->where('schedule_id', $qrData['schedule_id'])
        ->lockForUpdate()
        ->first();
    
    if ($existing) {
        throw new AlreadyAttendedException();
    }
    
    return Attendance::create([
        'student_id' => $student->id,
        'schedule_id' => $qrData['schedule_id'],
        'date' => today(),
        'status' => $this->determineStatus($scanTime),
        'latitude' => $location['lat'],
        'longitude' => $location['lng'],
    ]);
});

Test:
- 50 students scan same QR within 1 second
- Expected: All 50 get unique attendance records
- Verify: No duplicate records in database
```

#### QR Nonce Validation
```
Scenario: Replay attack with captured QR code

Prevention:
1. Store used nonces in Redis with TTL
2. Atomic check-and-set operation
3. Nonce expires with QR validity

Code Example:
$nonceKey = "qr_nonce:{$qrData['nonce']}";

// Atomic check and set
$wasUsed = Cache::add($nonceKey, true, $qrValidityMinutes * 60);

if (!$wasUsed) {
    throw new QRCodeReusedException();
}

// Continue with attendance recording...

Test:
- Capture valid QR code
- Try to scan 10 times with same nonce
- Expected: First scan succeeds, next 9 fail with "QR sudah digunakan"
```

### 3.3 Expected Error Responses

```json
// QR Expired (410)
{
  "success": false,
  "error": "QR_EXPIRED",
  "message": "QR code sudah kadaluarsa",
  "details": {
    "generated_at": "2026-02-02T07:00:00Z",
    "expired_at": "2026-02-02T07:05:00Z",
    "current_time": "2026-02-02T07:10:00Z",
    "action": "Minta guru untuk generate QR baru"
  }
}

// Wrong Class (403)
{
  "success": false,
  "error": "WRONG_CLASS",
  "message": "QR code bukan untuk kelas Anda",
  "details": {
    "your_class": "XII IPA 1",
    "qr_class": "XII IPA 2",
    "action": "Pastikan Anda scan QR untuk kelas Anda"
  }
}

// Already Attended (409)
{
  "success": false,
  "error": "ALREADY_ATTENDED",
  "message": "Anda sudah melakukan absensi hari ini",
  "details": {
    "attendance_time": "2026-02-02T07:02:15Z",
    "status": "present",
    "subject": "Matematika"
  }
}

// GPS Out of Range (403)
{
  "success": false,
  "error": "LOCATION_OUT_OF_RANGE",
  "message": "Lokasi Anda di luar jangkauan sekolah",
  "details": {
    "your_location": {
      "lat": -6.2088,
      "lng": 106.8456
    },
    "school_location": {
      "lat": -6.2000,
      "lng": 106.8400
    },
    "distance_meters": 1250,
    "allowed_radius": 500,
    "action": "Pastikan Anda berada di area sekolah"
  }
}
```

### 3.4 Performance Safeguards

- [ ] **QR validation caching** - Cache decrypted QR data
  - TTL: QR validity duration
  - Storage: Redis
  - Key: `qr_cache:{qr_hash}`
  
- [ ] **Nonce storage optimization** - Use Redis Sets
  - Automatic expiry with QR validity
  - Memory efficient
  
- [ ] **GPS calculation** - Use Haversine formula efficiently
  - Pre-calculate school coordinates
  - Index on location fields
  
- [ ] **Concurrent scan handling** - Queue system for high load
  - Max 100 concurrent scans per teacher QR
  - Queue overflow: Return "Please wait" message
  
- [ ] **Database connection pooling** - Prevent connection exhaustion
  - Min: 10 connections
  - Max: 100 connections
  - Timeout: 30 seconds

---

## 4. Student Dashboard

### 4.1 Edge Cases

#### Attendance History
- [ ] **No attendance records** - New student with no history
  - Expected: Empty state with message "Belum ada riwayat absensi"
  - UI: Show illustration and helpful text
  
- [ ] **Partial month data** - Student joined mid-month
  - Expected: Show only days after join date
  - Calculation: Attendance rate based on eligible days
  
- [ ] **Future dates** - Requesting attendance for future date
  - Expected: `422 Unprocessable Entity` with message "Tanggal tidak valid"
  - Prevention: Validate date <= today
  
- [ ] **Very old data** - Requesting data from 5 years ago
  - Expected: Return data if exists, warn if archived
  - Performance: May be slower for archived data

#### Student Profile
- [ ] **Missing profile photo** - Student without uploaded photo
  - Expected: Show default avatar
  - UI: Prompt to upload photo
  
- [ ] **Incomplete profile** - Missing optional fields
  - Expected: Show available data, mark missing fields
  - UI: Completion percentage indicator
  
- [ ] **Parent not linked** - Student without parent account
  - Expected: Show "Belum ada orang tua terdaftar"
  - Action: Provide parent registration link

#### Schedule View
- [ ] **No schedule for day** - Viewing weekend/holiday
  - Expected: Show "Tidak ada jadwal hari ini"
  - UI: Show next school day schedule
  
- [ ] **Schedule changes** - Teacher absent, substitute teacher
  - Expected: Show updated schedule with notification
  - Real-time: WebSocket update (if implemented)
  
- [ ] **Overlapping schedules** - Data inconsistency
  - Expected: Show warning, notify admin
  - Prevention: Validation during schedule creation

### 4.2 Race Conditions

#### Concurrent Profile Updates
```
Scenario: Student updates profile while parent updates same profile

Prevention:
1. Optimistic locking with updated_at field
2. Last-write-wins with conflict detection
3. Merge strategy for non-conflicting fields

Code Example:
public function updateProfile(Request $request, $expectedVersion)
{
    $student = Student::find($request->student_id);
    
    if ($student->updated_at->timestamp !== $expectedVersion) {
        throw new ConcurrentUpdateException([
            'message' => 'Profil telah diubah oleh pengguna lain',
            'current_version' => $student->updated_at->timestamp,
            'action' => 'Refresh halaman dan coba lagi'
        ]);
    }
    
    $student->update($request->validated());
    
    return response()->json([
        'success' => true,
        'version' => $student->fresh()->updated_at->timestamp
    ]);
}

Test:
- Parent and student update profile simultaneously
- Expected: One succeeds, other gets conflict error
- Verify: No data loss, user prompted to refresh
```

### 4.3 Expected Error Responses

```json
// No Data Available (200 with empty data)
{
  "success": true,
  "message": "Belum ada riwayat absensi",
  "data": {
    "attendances": [],
    "statistics": {
      "total_days": 0,
      "present": 0,
      "late": 0,
      "absent": 0,
      "rate": 0
    }
  }
}

// Concurrent Update (409)
{
  "success": false,
  "error": "CONCURRENT_UPDATE",
  "message": "Profil telah diubah oleh pengguna lain",
  "details": {
    "expected_version": 1706856000,
    "current_version": 1706856120,
    "action": "Refresh halaman dan coba lagi"
  }
}
```

### 4.4 Performance Safeguards

- [ ] **Pagination** - Limit attendance history
  - Default: 30 days
  - Max: 365 days per request
  - Cursor-based for large datasets
  
- [ ] **Data caching** - Cache frequently accessed data
  - Student profile: 1 hour TTL
  - Today's schedule: 30 minutes TTL
  - Attendance stats: 15 minutes TTL
  
- [ ] **Lazy loading** - Load data on demand
  - Initial load: Basic info only
  - On scroll: Load more history
  - On tab switch: Load tab data

---

## 5. Notification System

### 5.1 Edge Cases

#### Notification Delivery
- [ ] **Parent not registered** - Sending notification to non-existent parent
  - Expected: Log failure, skip notification
  - Fallback: Send to student only
  
- [ ] **Invalid phone number** - SMS to malformed number
  - Expected: Mark as failed, log error
  - Retry: No retry for invalid numbers
  
- [ ] **Email bounce** - Email address doesn't exist
  - Expected: Mark as bounced, disable email for user
  - Action: Notify admin to update contact
  
- [ ] **Push token expired** - Mobile device token invalid
  - Expected: Remove invalid token, try other channels
  - Cleanup: Delete expired tokens periodically

#### Notification Triggers
- [ ] **Multiple absences** - Student absent 3 days in a row
  - Expected: Send escalated notification to parent + admin
  - Frequency: Once per streak, not daily
  
- [ ] **Late arrival pattern** - Student late 5 times in 2 weeks
  - Expected: Send warning notification
  - Prevention: Don't spam, max 1 per week
  
- [ ] **Attendance rate drop** - Rate falls below 75%
  - Expected: Send alert to parent and student
  - Frequency: Once when threshold crossed, then weekly
  
- [ ] **Risk status change** - Student moves to high-risk
  - Expected: Immediate notification to all stakeholders
  - Priority: High priority delivery

#### Notification Preferences
- [ ] **User opted out** - User disabled notifications
  - Expected: Respect preference, don't send
  - Exception: Critical system notifications (security)
  
- [ ] **Quiet hours** - Notification during night time
  - Expected: Queue until morning (7 AM)
  - Exception: Emergency notifications
  
- [ ] **Channel preference** - User prefers SMS over email
  - Expected: Send via preferred channel first
  - Fallback: Try other channels if primary fails

### 5.2 Race Conditions

#### Duplicate Notification Prevention
```
Scenario: Same event triggers multiple notifications

Prevention:
1. Idempotency key based on event + user + date
2. Check for recent similar notifications
3. Deduplication window (5 minutes)

Code Example:
$notificationKey = md5(
    $event . $userId . $date . $notificationType
);

if (Cache::has("notif_sent:{$notificationKey}")) {
    Log::info('Duplicate notification prevented', [
        'key' => $notificationKey
    ]);
    return;
}

// Send notification
$this->sendNotification($user, $message);

// Mark as sent (5 minute window)
Cache::put("notif_sent:{$notificationKey}", true, 300);

Test:
- Trigger same event 3 times within 1 minute
- Expected: Only 1 notification sent
- Verify: Deduplication log entries
```

#### Concurrent Queue Processing
```
Scenario: Multiple workers processing notification queue

Prevention:
1. Job uniqueness based on notification ID
2. Database lock on notification record
3. Mark as processing before sending

Code Example:
public function handle()
{
    $notification = Notification::where('id', $this->notificationId)
        ->where('status', 'pending')
        ->lockForUpdate()
        ->first();
    
    if (!$notification) {
        return; // Already processed by another worker
    }
    
    $notification->update(['status' => 'processing']);
    
    try {
        $this->send($notification);
        $notification->update(['status' => 'sent']);
    } catch (Exception $e) {
        $notification->update(['status' => 'failed']);
        throw $e;
    }
}

Test:
- Queue 100 notifications
- Process with 5 workers simultaneously
- Expected: Each notification sent exactly once
- Verify: No duplicate sends, all marked correctly
```

### 5.3 Expected Error Responses

```json
// Delivery Failed (500)
{
  "success": false,
  "error": "NOTIFICATION_DELIVERY_FAILED",
  "message": "Gagal mengirim notifikasi",
  "details": {
    "notification_id": "notif_123",
    "channel": "email",
    "recipient": "parent@example.com",
    "reason": "SMTP connection timeout",
    "retry_count": 3,
    "next_retry": "2026-02-02T15:30:00Z"
  }
}

// Rate Limit Exceeded (429)
{
  "success": false,
  "error": "NOTIFICATION_RATE_LIMIT",
  "message": "Terlalu banyak notifikasi dalam waktu singkat",
  "details": {
    "limit": 10,
    "window": "1 hour",
    "retry_after": 3600,
    "action": "Coba lagi setelah 1 jam"
  }
}
```

### 5.4 Performance Safeguards

- [ ] **Queue management** - Separate queues by priority
  - High: Immediate processing
  - Normal: 1 minute delay
  - Low: 5 minute delay
  
- [ ] **Batch processing** - Group similar notifications
  - Batch size: 100 notifications
  - Interval: Every 5 minutes
  
- [ ] **Rate limiting** - Prevent notification spam
  - Per user: 10 notifications/hour
  - Per system: 1000 notifications/minute
  
- [ ] **Retry strategy** - Exponential backoff
  - Attempt 1: Immediate
  - Attempt 2: 5 minutes
  - Attempt 3: 30 minutes
  - Max attempts: 3

---

## 6. Reporting System

### 6.1 Edge Cases

#### Report Generation
- [ ] **Empty date range** - No data for selected period
  - Expected: Return empty report with message
  - UI: Show "Tidak ada data untuk periode ini"
  
- [ ] **Future date range** - Requesting report for future dates
  - Expected: `422 Unprocessable Entity` with validation error
  - Prevention: Validate end_date <= today
  
- [ ] **Very large date range** - Requesting 5 years of data
  - Expected: `422 Unprocessable Entity` with message "Periode terlalu panjang"
  - Limit: Max 1 year per report
  
- [ ] **Cross-year report** - Report spanning academic years
  - Expected: Allow but show warning
  - UI: Indicate academic year boundaries

#### Export Functionality
- [ ] **Large dataset export** - Exporting 10,000+ records
  - Expected: Queue job, return `202 Accepted`
  - Delivery: Email download link when ready
  
- [ ] **Concurrent export requests** - Multiple exports simultaneously
  - Expected: Queue all, process sequentially
  - Limit: Max 3 concurrent exports per user
  
- [ ] **Export timeout** - PDF generation takes >60 seconds
  - Expected: Job fails, retry automatically
  - Fallback: Reduce data scope, try again
  
- [ ] **Storage full** - No space for export files
  - Expected: `507 Insufficient Storage` with alert
  - Cleanup: Delete exports older than 7 days

#### Report Calculations
- [ ] **Division by zero** - Calculating rate with 0 total days
  - Expected: Return 0% or N/A
  - Prevention: Check denominator before division
  
- [ ] **Negative values** - Data inconsistency
  - Expected: Log error, show warning in report
  - Action: Trigger data integrity check
  
- [ ] **Missing class data** - Student moved classes mid-period
  - Expected: Show data for both classes separately
  - Calculation: Aggregate across classes

### 6.2 Race Conditions

#### Concurrent Report Generation
```
Scenario: Multiple admins generate same report simultaneously

Prevention:
1. Check for existing pending/processing report
2. Return existing report if available
3. Queue subsequent requests

Code Example:
$reportKey = md5($reportType . $dateRange . $filters);

// Check for existing report
$existing = Report::where('cache_key', $reportKey)
    ->where('status', 'completed')
    ->where('created_at', '>', now()->subHours(24))
    ->first();

if ($existing) {
    return $existing; // Return cached report
}

// Check for processing report
if (Cache::has("report_processing:{$reportKey}")) {
    return response()->json([
        'message' => 'Laporan sedang diproses',
        'status' => 'processing'
    ], 202);
}

// Generate new report
Cache::put("report_processing:{$reportKey}", true, 600);
GenerateReportJob::dispatch($reportKey, $params);

Test:
- 5 admins request same report within 10 seconds
- Expected: 1 report generated, others get cached result
- Verify: Only 1 generation job queued
```

### 6.3 Expected Error Responses

```json
// Report Queued (202)
{
  "success": true,
  "message": "Laporan sedang dibuat",
  "data": {
    "job_id": "report_gen_20260202_001",
    "status": "queued",
    "estimated_time": "3-5 menit",
    "notification": "Link download akan dikirim via email"
  }
}

// Date Range Too Large (422)
{
  "success": false,
  "error": "DATE_RANGE_TOO_LARGE",
  "message": "Periode laporan terlalu panjang",
  "details": {
    "requested_days": 1825,
    "max_allowed_days": 365,
    "action": "Pilih periode maksimal 1 tahun"
  }
}

// Export Limit Reached (429)
{
  "success": false,
  "error": "EXPORT_LIMIT_REACHED",
  "message": "Terlalu banyak export aktif",
  "details": {
    "active_exports": 3,
    "max_allowed": 3,
    "action": "Tunggu hingga export selesai atau batalkan export yang ada"
  }
}
```

### 6.4 Performance Safeguards

- [ ] **Query optimization** - Use indexed queries
  - Index: `attendances(date, school_id)`
  - Index: `attendances(student_id, date)`
  - Avoid: SELECT * queries
  
- [ ] **Data aggregation** - Pre-aggregate common reports
  - Daily summary: Cached for 1 hour
  - Monthly summary: Cached for 24 hours
  - Refresh: On data change
  
- [ ] **Export chunking** - Process large exports in chunks
  - Chunk size: 1000 records
  - Memory limit: 256MB per job
  
- [ ] **Timeout handling** - Set appropriate timeouts
  - PDF generation: 60 seconds
  - Excel generation: 120 seconds
  - Database query: 30 seconds

---

## 7. Cross-System Concerns

### 7.1 Authentication & Authorization

#### Session Management
- [ ] **Concurrent logins** - Same user logs in from multiple devices
  - Expected: Allow, track all sessions
  - Option: Configurable to force single session
  
- [ ] **Session expiry** - Token expires during active use
  - Expected: Refresh token automatically
  - Fallback: Redirect to login with return URL
  
- [ ] **Invalid token** - Malformed or tampered JWT
  - Expected: `401 Unauthorized` with clear message
  - Action: Clear local storage, redirect to login

#### Permission Checks
- [ ] **Role change during session** - User role updated by admin
  - Expected: Force re-login on next request
  - Implementation: Check role version in token
  
- [ ] **School deactivation** - School account disabled mid-session
  - Expected: `403 Forbidden` with message "Sekolah tidak aktif"
  - Action: Logout all school users
  
- [ ] **Package downgrade** - School downgrades, exceeds new limits
  - Expected: Read-only mode until compliance
  - UI: Show upgrade prompt

### 7.2 Data Integrity

#### Referential Integrity
- [ ] **Orphaned records** - Student deleted but attendance remains
  - Prevention: Soft delete students
  - Cleanup: Archive old data periodically
  
- [ ] **Cascade deletion** - Deleting class with students
  - Prevention: Prevent deletion if has students
  - Alternative: Move students to another class first
  
- [ ] **Foreign key violations** - Invalid references
  - Prevention: Database constraints
  - Handling: Return meaningful error message

#### Data Consistency
- [ ] **Clock skew** - Server and client time mismatch
  - Prevention: Use server time for all operations
  - Validation: Accept ±5 minute tolerance
  
- [ ] **Timezone issues** - Different timezones
  - Standard: Store all times in UTC
  - Display: Convert to school's timezone
  
- [ ] **Duplicate prevention** - Same data entered twice
  - Prevention: Unique constraints
  - UI: Disable submit button after click

### 7.3 Error Logging & Monitoring

#### Error Tracking
- [ ] **Unhandled exceptions** - Unexpected errors
  - Action: Log to Sentry/monitoring service
  - Response: Generic error message to user
  - Alert: Notify dev team for 5xx errors
  
- [ ] **Validation errors** - User input errors
  - Action: Log for analytics
  - Response: Specific field errors
  - No alert: Expected user errors
  
- [ ] **External service failures** - SMS/Email service down
  - Action: Log and queue for retry
  - Response: Acknowledge request, process async
  - Alert: If failure rate >10%

#### Performance Monitoring
- [ ] **Slow queries** - Database queries >1 second
  - Action: Log query and execution plan
  - Alert: If >5 slow queries/minute
  - Fix: Add indexes or optimize query
  
- [ ] **High memory usage** - Process using >80% memory
  - Action: Log memory snapshot
  - Alert: Immediate alert
  - Fix: Restart worker, investigate leak
  
- [ ] **API response time** - Endpoint taking >3 seconds
  - Action: Log request details
  - Alert: If p95 >3 seconds
  - Fix: Add caching or optimize

---

## 8. Performance Safeguards

### 8.1 Database Performance

#### Query Optimization
```sql
-- Bad: N+1 query problem
SELECT * FROM students;
-- Then for each student:
SELECT * FROM attendances WHERE student_id = ?;

-- Good: Eager loading
SELECT students.*, attendances.*
FROM students
LEFT JOIN attendances ON students.id = attendances.student_id
WHERE students.school_id = ?;

-- Index requirements:
CREATE INDEX idx_attendances_student_date ON attendances(student_id, date);
CREATE INDEX idx_students_school ON students(school_id);
CREATE INDEX idx_qr_nonces_nonce ON qr_nonces(nonce);
```

#### Connection Pooling
```php
// config/database.php
'mysql' => [
    'pool' => [
        'min' => 10,
        'max' => 100,
        'idle_timeout' => 60,
        'wait_timeout' => 30,
    ],
],
```

### 8.2 Caching Strategy

#### Cache Layers
```
1. Application Cache (Redis)
   - Session data: 2 hours
   - User profile: 1 hour
   - School settings: 24 hours
   
2. Query Cache (MySQL)
   - Static data: Enabled
   - Dynamic data: Disabled
   
3. HTTP Cache (CDN)
   - Static assets: 1 year
   - API responses: No cache (or short TTL)
```

#### Cache Invalidation
```php
// Event-based invalidation
event(new StudentUpdated($student));

// Listener
class InvalidateStudentCache
{
    public function handle(StudentUpdated $event)
    {
        Cache::tags(['student', "student:{$event->student->id}"])
            ->flush();
    }
}
```

### 8.3 Rate Limiting

#### API Rate Limits
```
Public endpoints: 60 requests/minute
Authenticated: 120 requests/minute
Admin: 300 requests/minute
QR scan: 10 requests/minute per student
Export: 5 requests/hour per user
```

#### Implementation
```php
// routes/api.php
Route::middleware(['throttle:qr-scan'])->group(function () {
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
});

// app/Providers/RouteServiceProvider.php
RateLimiter::for('qr-scan', function (Request $request) {
    return Limit::perMinute(10)
        ->by($request->user()?->id ?: $request->ip())
        ->response(function () {
            return response()->json([
                'error' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Terlalu banyak percobaan scan',
                'retry_after' => 60
            ], 429);
        });
});
```

### 8.4 Queue Management

#### Queue Configuration
```php
// config/queue.php
'connections' => [
    'high-priority' => [
        'driver' => 'redis',
        'queue' => 'high',
        'retry_after' => 90,
        'block_for' => 5,
    ],
    'default' => [
        'driver' => 'redis',
        'queue' => 'default',
        'retry_after' => 300,
    ],
    'low-priority' => [
        'driver' => 'redis',
        'queue' => 'low',
        'retry_after' => 600,
    ],
],
```

#### Job Monitoring
```php
// Monitor failed jobs
php artisan queue:failed

// Retry failed jobs
php artisan queue:retry all

// Clear failed jobs
php artisan queue:flush
```

---

## 9. Security Testing

### 9.1 Input Validation

#### SQL Injection Prevention
```php
// Bad
$users = DB::select("SELECT * FROM users WHERE username = '{$request->username}'");

// Good
$users = DB::table('users')
    ->where('username', $request->username)
    ->get();

// Test cases:
- Input: admin' OR '1'='1
- Expected: No SQL injection, treated as literal string
```

#### XSS Prevention
```php
// Bad
echo "<div>{$user->bio}</div>";

// Good
echo "<div>" . e($user->bio) . "</div>";

// Test cases:
- Input: <script>alert('XSS')</script>
- Expected: Escaped as &lt;script&gt;alert('XSS')&lt;/script&gt;
```

#### CSRF Protection
```php
// All POST/PUT/DELETE requests require CSRF token
// Test cases:
- Request without token: 419 CSRF token mismatch
- Request with invalid token: 419 CSRF token mismatch
- Request with valid token: Success
```

### 9.2 Authentication Security

#### Password Security
```php
// Requirements:
- Minimum 8 characters
- At least 1 uppercase
- At least 1 lowercase
- At least 1 number
- At least 1 special character

// Hashing
Hash::make($password); // bcrypt with cost 12

// Test cases:
- Weak password: Rejected with validation error
- Strong password: Accepted and hashed
- Hash verification: Correct password accepted
```

#### Brute Force Protection
```php
// Login throttling
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)
        ->by($request->input('username') . '|' . $request->ip());
});

// Test cases:
- 5 failed logins: Allowed
- 6th failed login: 429 Too Many Requests
- Wait 1 minute: Allowed again
```

### 9.3 Authorization Testing

#### Role-Based Access Control
```php
// Test matrix:
| Endpoint | super_admin | school_admin | teacher | student | parent |
|----------|-------------|--------------|---------|---------|--------|
| GET /admin/dashboard | ✅ | ✅ | ❌ | ❌ | ❌ |
| POST /admin/students | ✅ | ✅ | ❌ | ❌ | ❌ |
| GET /teacher/qr | ✅ | ✅ | ✅ | ❌ | ❌ |
| POST /attendance/scan | ✅ | ✅ | ✅ | ✅ | ❌ |
| GET /student/history | ✅ | ✅ | ✅ | ✅ (own) | ✅ (child) |
```

#### Data Isolation
```php
// Test cases:
- School A admin accessing School B data: 403 Forbidden
- Teacher accessing other teacher's QR: 403 Forbidden
- Student accessing other student's attendance: 403 Forbidden
- Parent accessing non-child's data: 403 Forbidden
```

---

## 10. Testing Execution Plan

### 10.1 Test Phases

#### Phase 1: Unit Testing (Week 1)
- [ ] Service layer functions
- [ ] Model methods
- [ ] Validation rules
- [ ] Helper functions

#### Phase 2: Integration Testing (Week 2)
- [ ] API endpoints
- [ ] Database transactions
- [ ] Queue jobs
- [ ] Event listeners

#### Phase 3: System Testing (Week 3)
- [ ] Complete user flows
- [ ] Cross-feature interactions
- [ ] Performance testing
- [ ] Security testing

#### Phase 4: User Acceptance Testing (Week 4)
- [ ] Real user scenarios
- [ ] Usability testing
- [ ] Edge case validation
- [ ] Production-like environment

### 10.2 Test Metrics

#### Coverage Targets
- Unit test coverage: >80%
- Integration test coverage: >70%
- Critical path coverage: 100%
- API endpoint coverage: 100%

#### Performance Targets
- API response time (p95): <500ms
- Database query time (p95): <100ms
- Page load time: <2 seconds
- Export generation: <60 seconds

#### Quality Targets
- Zero critical bugs in production
- <5 high-priority bugs per release
- <10 medium-priority bugs per release
- Bug fix time: <24 hours for critical

---

## 11. Bug Severity Classification

### Critical (P0) - Fix Immediately
- System completely down
- Data loss or corruption
- Security vulnerability
- Payment processing failure

### High (P1) - Fix within 24 hours
- Major feature broken
- Workaround exists but difficult
- Affects many users
- Performance degradation >50%

### Medium (P2) - Fix within 1 week
- Minor feature broken
- Easy workaround exists
- Affects some users
- Performance degradation <50%

### Low (P3) - Fix in next release
- Cosmetic issues
- Enhancement requests
- Affects few users
- No functional impact

---

## 12. Sign-Off

### QA Team Sign-Off
- **Lead QA Engineer**: _______________
- **Date**: _______________
- **Status**: [ ] Approved [ ] Needs Revision

### Development Team Sign-Off
- **Tech Lead**: _______________
- **Date**: _______________
- **Status**: [ ] Approved [ ] Needs Revision

### Product Team Sign-Off
- **Product Manager**: _______________
- **Date**: _______________
- **Status**: [ ] Approved [ ] Needs Revision

---

**Document Status**: ✅ Ready for Review  
**Next Review Date**: March 2, 2026  
**Version**: 1.0.0
