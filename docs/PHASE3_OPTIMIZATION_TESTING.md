# Phase 3: Optimization & Testing Plan

## Overview
Week 7-8: Performance optimization, caching implementation, and comprehensive testing.

---

## 1. N+1 Query Fixes (18+ Methods)

### Priority Methods to Optimize

#### High Priority (Week 7, Days 1-3)
```php
// 1. TeacherDashboardController::getAlerts() - 2 hours
// BEFORE (N+1)
$alerts = Alert::where('teacher_id', $teacherId)->get();
foreach ($alerts as $alert) {
    $alert->student->name; // N+1
    $alert->class->name;   // N+1
}

// AFTER (Optimized)
$alerts = Alert::with(['student:id,name', 'class:id,name'])
    ->where('teacher_id', $teacherId)
    ->get();

// 2. TeacherDashboardController::getCorrectionList() - 2 hours
// AFTER
$corrections = AttendanceCorrection::with([
    'attendance.student:id,name',
    'attendance.schedule.subject:id,name',
    'requestedBy:id,name'
])->where('teacher_id', $teacherId)->get();

// 3. TeacherDashboardController::getMonthlyAnalytics() - 3 hours
// AFTER
$analytics = DB::table('attendances')
    ->select([
        'class_id',
        DB::raw('DATE(attendance_date) as date'),
        DB::raw('SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present'),
        DB::raw('COUNT(*) as total')
    ])
    ->where('teacher_id', $teacherId)
    ->groupBy('class_id', 'date')
    ->get();

// 4. AdminDashboardController::getStudentRiskList() - 3 hours
// AFTER
$riskStudents = Student::select([
    'students.id',
    'students.name',
    'students.class_id',
    DB::raw('(SELECT COUNT(*) FROM attendances WHERE student_id = students.id AND status = "present") as present_count'),
    DB::raw('(SELECT COUNT(*) FROM attendances WHERE student_id = students.id) as total_count')
])
->with('class:id,name')
->whereRaw('(SELECT COUNT(*) FROM attendances WHERE student_id = students.id AND status = "present") / (SELECT COUNT(*) FROM attendances WHERE student_id = students.id) < 0.75')
->get();
```

#### Medium Priority (Week 7, Days 4-5)
- PrincipalDashboardController::getSchoolOverview() - 3 hours
- PrincipalDashboardController::getTeacherPerformance() - 3 hours
- PrincipalDashboardController::getClassPerformance() - 3 hours
- AdminDashboardController::getClassStatistics() - 2 hours
- AdminDashboardController::getTeacherStatistics() - 2 hours

### Optimization Techniques

```php
// 1. Eager Loading
Model::with(['relation1', 'relation2'])->get();

// 2. Subquery Select
Model::select([
    'id',
    'name',
    DB::raw('(SELECT COUNT(*) FROM related WHERE ...) as count')
])->get();

// 3. Join Instead of Eager Loading (when needed)
Model::join('related', 'model.id', '=', 'related.model_id')
    ->select('model.*', 'related.field')
    ->get();

// 4. Lazy Eager Loading (when conditional)
$models = Model::all();
if ($needRelation) {
    $models->load('relation');
}
```

---

## 2. Caching Implementation

### Cache Strategy

```php
// config/cache.php - Add custom TTLs
return [
    'ttl' => [
        'dashboard_stats' => 300,      // 5 minutes
        'static_data' => 3600,         // 1 hour
        'user_permissions' => null,    // Session-based
        'api_responses' => 120,        // 2 minutes
    ],
];
```

### Implementation Examples

```php
// 1. Dashboard Statistics (5 min TTL)
public function getDashboardStats($schoolId)
{
    return Cache::remember("dashboard_stats_{$schoolId}", 300, function () use ($schoolId) {
        return [
            'total_students' => Student::where('school_id', $schoolId)->count(),
            'attendance_rate' => $this->calculateAttendanceRate($schoolId),
            // ... more stats
        ];
    });
}

// 2. Static Data (1 hour TTL)
public function getClassList($schoolId)
{
    return Cache::remember("classes_{$schoolId}", 3600, function () use ($schoolId) {
        return Classroom::where('school_id', $schoolId)
            ->select('id', 'name', 'grade')
            ->get();
    });
}

// 3. Tag-Based Cache Invalidation
// When attendance is created
Cache::tags(['attendance', "school_{$schoolId}"])->flush();

// When student is updated
Cache::tags(['students', "school_{$schoolId}"])->flush();
```

### Cache Invalidation Strategy

```php
// app/Observers/AttendanceObserver.php
class AttendanceObserver
{
    public function created(Attendance $attendance)
    {
        $this->clearCache($attendance);
    }

    public function updated(Attendance $attendance)
    {
        $this->clearCache($attendance);
    }

    private function clearCache(Attendance $attendance)
    {
        $schoolId = $attendance->student->school_id;
        
        Cache::tags([
            'attendance',
            "school_{$schoolId}",
            "student_{$attendance->student_id}",
            "class_{$attendance->class_id}"
        ])->flush();
    }
}
```

---

## 3. Comprehensive Testing

### Test Coverage Target: 90%+

### 3.1 Unit Tests (2 days)

```php
// tests/Unit/Services/AttendanceServiceTest.php
class AttendanceServiceTest extends TestCase
{
    public function test_can_record_attendance()
    {
        $service = new AttendanceService();
        $result = $service->recordAttendance([
            'student_id' => 1,
            'schedule_id' => 1,
            'status' => 'present',
        ]);
        
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('attendances', [
            'student_id' => 1,
            'status' => 'present',
        ]);
    }
    
    public function test_prevents_duplicate_attendance()
    {
        // Create existing attendance
        Attendance::factory()->create([
            'student_id' => 1,
            'schedule_id' => 1,
        ]);
        
        $service = new AttendanceService();
        $result = $service->recordAttendance([
            'student_id' => 1,
            'schedule_id' => 1,
        ]);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Attendance already recorded', $result['message']);
    }
}

// tests/Unit/Services/QrServiceTest.php
class QrServiceTest extends TestCase
{
    public function test_generates_valid_qr_token()
    {
        $service = new QrService();
        $token = $service->generateToken([
            'schedule_id' => 1,
            'expires_at' => now()->addMinutes(15),
        ]);
        
        $this->assertNotEmpty($token);
        $this->assertTrue($service->validateToken($token));
    }
    
    public function test_rejects_expired_token()
    {
        $service = new QrService();
        $token = $service->generateToken([
            'schedule_id' => 1,
            'expires_at' => now()->subMinutes(1),
        ]);
        
        $this->assertFalse($service->validateToken($token));
    }
}
```

### 3.2 Feature Tests (2 days)

```php
// tests/Feature/Api/StudentDashboardTest.php
class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_student_can_view_dashboard()
    {
        $student = User::factory()->student()->create();
        
        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/dashboard');
        
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'attendance_summary',
                    'schedule_today',
                    'recent_attendance',
                ]
            ]);
    }
    
    public function test_student_can_scan_qr()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create();
        $token = app(QrService::class)->generateToken([
            'schedule_id' => $schedule->id,
        ]);
        
        $response = $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/student/scan-qr', [
                'qr_token' => $token,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
            ]);
        
        $response->assertOk()
            ->assertJson(['success' => true]);
        
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
        ]);
    }
}
```

### 3.3 Integration Tests (1 day)

```php
// tests/Integration/PaymentFlowTest.php
class PaymentFlowTest extends TestCase
{
    public function test_complete_payment_flow()
    {
        // 1. Create school
        $school = School::factory()->create();
        
        // 2. Create payment
        $payment = Payment::factory()->create([
            'school_id' => $school->id,
            'status' => 'pending',
        ]);
        
        // 3. Simulate Midtrans webhook
        $response = $this->postJson('/api/webhooks/midtrans', [
            'order_id' => $payment->order_id,
            'transaction_status' => 'settlement',
            'gross_amount' => $payment->amount,
            'signature_key' => $this->generateSignature($payment),
        ]);
        
        $response->assertOk();
        
        // 4. Verify payment updated
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
        
        // 5. Verify package applied
        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'package_type' => $payment->package_type,
        ]);
    }
}
```

### 3.4 E2E Tests (2 days)

```php
// tests/E2E/StudentAttendanceFlowTest.php
class StudentAttendanceFlowTest extends TestCase
{
    public function test_student_complete_attendance_flow()
    {
        // Setup
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create();
        
        // 1. Student logs in
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'username' => $student->username,
            'password' => 'password',
        ]);
        
        $token = $loginResponse->json('data.token');
        
        // 2. Student views dashboard
        $dashboardResponse = $this->withToken($token)
            ->getJson('/api/v1/student/dashboard');
        
        $dashboardResponse->assertOk();
        
        // 3. Teacher generates QR
        $teacher = User::factory()->teacher()->create();
        $qrResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/generate-qr', [
                'schedule_id' => $schedule->id,
            ]);
        
        $qrToken = $qrResponse->json('data.token');
        
        // 4. Student scans QR
        $scanResponse = $this->withToken($token)
            ->postJson('/api/v1/student/scan-qr', [
                'qr_token' => $qrToken,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
            ]);
        
        $scanResponse->assertOk()
            ->assertJson(['data' => ['status' => 'present']]);
        
        // 5. Verify attendance recorded
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
        ]);
        
        // 6. Student views attendance history
        $historyResponse = $this->withToken($token)
            ->getJson('/api/v1/student/my-attendance');
        
        $historyResponse->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
```

---

## 4. Performance Benchmarks

### Target Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Page Load Time | < 3s | Lighthouse |
| API Response Time | < 500ms | Laravel Debugbar |
| Database Queries | < 10 per page | Laravel Debugbar |
| Cache Hit Rate | > 80% | Redis INFO |
| Test Coverage | > 90% | PHPUnit --coverage |

### Monitoring Queries

```sql
-- Check slow queries
SELECT 
    query_time,
    lock_time,
    rows_examined,
    sql_text
FROM mysql.slow_log
WHERE query_time > 1
ORDER BY query_time DESC
LIMIT 10;

-- Check cache hit rate
INFO stats
-- Look for: keyspace_hits / (keyspace_hits + keyspace_misses)
```

---

## 5. Implementation Timeline

### Week 7: Optimization
| Day | Task | Hours |
|-----|------|-------|
| Mon | N+1 fixes (methods 1-3) | 7h |
| Tue | N+1 fixes (methods 4-6) | 7h |
| Wed | N+1 fixes (methods 7-9) | 8h |
| Thu | N+1 fixes (methods 10-15) | 8h |
| Fri | Caching implementation | 8h |
| Sat | Cache testing & tuning | 6h |
| Sun | Performance benchmarking | 4h |

### Week 8: Testing
| Day | Task | Hours |
|-----|------|-------|
| Mon | Unit tests (Services) | 8h |
| Tue | Unit tests (Models) | 8h |
| Wed | Feature tests (API) | 8h |
| Thu | Feature tests (Auth) | 8h |
| Fri | Integration tests | 8h |
| Sat | E2E tests | 8h |
| Sun | E2E tests + Coverage report | 8h |

---

## 6. Success Criteria Checklist

- [ ] 0 N+1 query issues (verified with Laravel Debugbar)
- [ ] Cache hit rate > 80% (verified with Redis INFO)
- [ ] Test coverage > 90% (verified with PHPUnit)
- [ ] Page load time < 3s (verified with Lighthouse)
- [ ] 0 regression bugs (verified with test suite)
- [ ] All API endpoints < 500ms response time
- [ ] Database queries < 10 per request
- [ ] Memory usage < 128MB per request

---

## 7. Tools & Commands

```bash
# Run tests with coverage
php artisan test --coverage --min=90

# Check for N+1 queries
php artisan telescope:install
# Visit /telescope and check Queries tab

# Monitor cache
redis-cli INFO stats

# Performance profiling
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

**Target Completion**: End of Week 8
**Total Effort**: 96 hours (2 weeks)
