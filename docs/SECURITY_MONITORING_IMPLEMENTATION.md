# Security Monitoring Dashboard - Implementation Guide

## Overview

This guide explains how to integrate the Security Monitoring Dashboard into the school attendance system.

---

## 1. Database Setup

### Run Migration

```bash
php artisan migrate
```

This will create three tables:
- `security_events` - Stores all security events
- `suspicious_students` - Tracks flagged students
- `suspicious_devices` - Tracks suspicious devices

---

## 2. Integration with Attendance Service

### Example: Logging Security Events

Add security event logging to your `AttendanceService`:

```php
use App\Services\SecurityMonitoringService;

class AttendanceService
{
    protected SecurityMonitoringService $securityService;
    
    public function __construct(SecurityMonitoringService $securityService)
    {
        $this->securityService = $securityService;
    }
    
    public function scanQR($qrData, $studentId, $deviceId, $latitude, $longitude)
    {
        // Validate QR code
        if ($this->isQRExpired($qrData)) {
            // Log security event
            $this->securityService->logEvent([
                'school_id' => auth()->user()->school_id,
                'user_id' => auth()->id(),
                'student_id' => $studentId,
                'event_type' => 'expired_qr_scan',
                'severity' => 'medium',
                'device_id' => $deviceId,
                'details' => [
                    'qr_generated_at' => $qrData['timestamp'],
                    'qr_expires_at' => $qrData['expires_at'],
                    'scan_attempted_at' => now()->toDateTimeString(),
                ],
            ]);
            
            throw new QRExpiredException();
        }
        
        // Validate location
        if (!$this->isLocationValid($latitude, $longitude)) {
            $this->securityService->logEvent([
                'school_id' => auth()->user()->school_id,
                'user_id' => auth()->id(),
                'student_id' => $studentId,
                'event_type' => 'location_mismatch',
                'severity' => 'high',
                'device_id' => $deviceId,
                'details' => [
                    'student_location' => ['lat' => $latitude, 'lng' => $longitude],
                    'school_location' => $this->getSchoolLocation(),
                    'distance_meters' => $this->calculateDistance($latitude, $longitude),
                ],
            ]);
            
            throw new LocationMismatchException();
        }
        
        // Check if device is blocked
        if ($this->securityService->isDeviceBlocked(auth()->user()->school_id, $deviceId)) {
            $this->securityService->logEvent([
                'school_id' => auth()->user()->school_id,
                'user_id' => auth()->id(),
                'student_id' => $studentId,
                'event_type' => 'unauthorized_access',
                'severity' => 'critical',
                'device_id' => $deviceId,
                'details' => [
                    'reason' => 'Device is blocked',
                ],
            ]);
            
            throw new DeviceBlockedException();
        }
        
        // Process attendance...
    }
}
```

---

## 3. Common Security Event Scenarios

### Scenario 1: Invalid QR Attempt

```php
$this->securityService->logEvent([
    'school_id' => $schoolId,
    'student_id' => $studentId,
    'event_type' => 'invalid_qr_attempt',
    'severity' => 'medium',
    'device_id' => $deviceId,
    'details' => [
        'reason' => 'Invalid QR signature',
        'qr_hash' => substr($qrHash, 0, 20),
    ],
]);
```

### Scenario 2: Rate Limit Violation

```php
$this->securityService->logEvent([
    'school_id' => $schoolId,
    'student_id' => $studentId,
    'event_type' => 'rate_limit_violation',
    'severity' => 'high',
    'device_id' => $deviceId,
    'details' => [
        'attempts_count' => $attemptCount,
        'time_window' => '1 minute',
        'limit' => 10,
    ],
]);
```

### Scenario 3: QR Sharing Suspected

```php
$this->securityService->logEvent([
    'school_id' => $schoolId,
    'student_id' => $studentId,
    'event_type' => 'qr_sharing_suspected',
    'severity' => 'critical',
    'device_id' => $deviceId,
    'details' => [
        'qr_nonce' => $qrNonce,
        'original_device' => $originalDeviceId,
        'new_device' => $currentDeviceId,
        'time_difference_seconds' => 5,
    ],
]);
```

---

## 4. Auto-Flagging Logic

The `SecurityMonitoringService` automatically flags students when thresholds are exceeded:

### Automatic Flagging

```php
// This happens automatically when you log an event
$this->securityService->logEvent([...]);

// The service will:
// 1. Count violations in last 7 days
// 2. Check if threshold exceeded (>3 violations)
// 3. Automatically create SuspiciousStudent record
// 4. Track device usage
```

### Manual Flagging

```php
// If you need to manually flag a student
$suspiciousStudent = SuspiciousStudent::create([
    'school_id' => $schoolId,
    'student_id' => $studentId,
    'flag_reason' => 'qr_sharing_suspected',
    'violation_count' => 1,
    'evidence' => [[
        'reason' => 'Manual review',
        'details' => 'Teacher reported suspicious behavior',
        'detected_at' => now()->toDateTimeString(),
    ]],
    'status' => 'flagged',
    'flagged_at' => now(),
]);
```

---

## 5. Frontend Integration

### Dashboard Cards Component

```typescript
// SecurityDashboard.tsx
import { useSecuritySummary } from '@/hooks/useSecurityMonitoring';

export default function SecurityDashboard() {
  const { data: summary } = useSecuritySummary();
  
  return (
    <div className="grid grid-cols-4 gap-4">
      <DashboardCard
        title="Total Events Today"
        value={summary.total_events_today}
        icon={<AlertTriangle />}
      />
      <DashboardCard
        title="High Severity Alerts"
        value={summary.high_severity_alerts}
        icon={<AlertCircle />}
        variant="danger"
      />
      <DashboardCard
        title="Flagged Students"
        value={summary.flagged_students_count}
        icon={<Users />}
        variant="warning"
      />
      <DashboardCard
        title="Suspicious Devices"
        value={summary.suspicious_devices_count}
        icon={<Smartphone />}
        variant="warning"
      />
    </div>
  );
}
```

### Security Events Table

```typescript
// SecurityEventsTable.tsx
import { useSecurityEvents } from '@/hooks/useSecurityMonitoring';

export default function SecurityEventsTable() {
  const [filters, setFilters] = useState({
    severity: '',
    event_type: '',
    start_date: '',
    end_date: '',
  });
  
  const { data, isLoading } = useSecurityEvents(filters);
  
  return (
    <div>
      <Filters filters={filters} onChange={setFilters} />
      
      <Table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Student</th>
            <th>Event Type</th>
            <th>Severity</th>
            <th>Device</th>
            <th>IP Address</th>
          </tr>
        </thead>
        <tbody>
          {data?.events.map(event => (
            <tr key={event.id}>
              <td>{formatDate(event.created_at)}</td>
              <td>{event.student?.name}</td>
              <td>{event.event_type}</td>
              <td>
                <Badge variant={getSeverityVariant(event.severity)}>
                  {event.severity}
                </Badge>
              </td>
              <td>{event.device_id}</td>
              <td>{event.ip_address}</td>
            </tr>
          ))}
        </tbody>
      </Table>
      
      <Pagination data={data?.pagination} />
    </div>
  );
}
```

### Suspicious Students Table

```typescript
// SuspiciousStudentsTable.tsx
import { useSuspiciousStudents, useReviewStudent } from '@/hooks/useSecurityMonitoring';

export default function SuspiciousStudentsTable() {
  const { data } = useSuspiciousStudents();
  const { mutate: reviewStudent } = useReviewStudent();
  
  const handleReview = (studentId: number, status: string, notes: string) => {
    reviewStudent({ studentId, status, notes });
  };
  
  return (
    <Table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Class</th>
          <th>Flag Reason</th>
          <th>Violations</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        {data?.suspicious_students.map(item => (
          <tr key={item.id}>
            <td>{item.student.name}</td>
            <td>{item.student.class.name}</td>
            <td>{item.flag_reason}</td>
            <td>{item.violation_count}</td>
            <td>
              <Badge variant={getStatusVariant(item.status)}>
                {item.status}
              </Badge>
            </td>
            <td>
              <ReviewModal
                student={item}
                onReview={handleReview}
              />
            </td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}
```

---

## 6. React Query Hooks

Create custom hooks for data fetching:

```typescript
// hooks/useSecurityMonitoring.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { securityApi } from '@/services/securityApi';

export function useSecuritySummary(date?: string) {
  return useQuery({
    queryKey: ['security-summary', date],
    queryFn: () => securityApi.getSummary(date),
  });
}

export function useSecurityEvents(filters: any) {
  return useQuery({
    queryKey: ['security-events', filters],
    queryFn: () => securityApi.getEvents(filters),
  });
}

export function useSuspiciousStudents(status?: string) {
  return useQuery({
    queryKey: ['suspicious-students', status],
    queryFn: () => securityApi.getSuspiciousStudents(status),
  });
}

export function useSuspiciousDevices(riskLevel?: string) {
  return useQuery({
    queryKey: ['suspicious-devices', riskLevel],
    queryFn: () => securityApi.getSuspiciousDevices(riskLevel),
  });
}

export function useReviewStudent() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ studentId, status, notes }: any) =>
      securityApi.reviewStudent(studentId, status, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suspicious-students'] });
      toast.success('Status berhasil diupdate');
    },
  });
}

export function useToggleDeviceBlock() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ deviceId, blocked }: any) =>
      securityApi.toggleDeviceBlock(deviceId, blocked),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suspicious-devices'] });
      toast.success('Status perangkat berhasil diupdate');
    },
  });
}
```

---

## 7. Testing

### Unit Tests

```php
// tests/Unit/SecurityMonitoringServiceTest.php
class SecurityMonitoringServiceTest extends TestCase
{
    public function test_logs_security_event()
    {
        $service = new SecurityMonitoringService();
        
        $event = $service->logEvent([
            'school_id' => 1,
            'student_id' => 50,
            'event_type' => 'invalid_qr_attempt',
            'severity' => 'medium',
            'device_id' => 'device_123',
        ]);
        
        $this->assertDatabaseHas('security_events', [
            'student_id' => 50,
            'event_type' => 'invalid_qr_attempt',
        ]);
    }
    
    public function test_auto_flags_student_after_threshold()
    {
        $service = new SecurityMonitoringService();
        
        // Create 4 violations
        for ($i = 0; $i < 4; $i++) {
            $service->logEvent([
                'school_id' => 1,
                'student_id' => 50,
                'event_type' => 'invalid_qr_attempt',
                'severity' => 'medium',
            ]);
        }
        
        // Student should be flagged
        $this->assertDatabaseHas('suspicious_students', [
            'student_id' => 50,
            'status' => 'flagged',
        ]);
    }
}
```

### Integration Tests

```php
// tests/Feature/SecurityMonitoringApiTest.php
class SecurityMonitoringApiTest extends TestCase
{
    public function test_admin_can_view_security_events()
    {
        $admin = User::factory()->create(['role_type' => 'school_admin']);
        
        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/security/events');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'events',
                    'pagination',
                ],
            ]);
    }
    
    public function test_admin_can_review_suspicious_student()
    {
        $admin = User::factory()->create(['role_type' => 'school_admin']);
        $suspicious = SuspiciousStudent::factory()->create();
        
        $response = $this->actingAs($admin)
            ->postJson("/api/v1/admin/security/suspicious-students/{$suspicious->id}/review", [
                'status' => 'cleared',
                'notes' => 'False positive',
            ]);
        
        $response->assertStatus(200);
        
        $this->assertDatabaseHas('suspicious_students', [
            'id' => $suspicious->id,
            'status' => 'cleared',
        ]);
    }
}
```

---

## 8. Monitoring & Alerts

### Set Up Alerts

Create a scheduled job to send alerts for high-severity events:

```php
// app/Console/Commands/SendSecurityAlerts.php
class SendSecurityAlerts extends Command
{
    public function handle()
    {
        $schools = School::all();
        
        foreach ($schools as $school) {
            $highSeverityCount = SecurityEvent::where('school_id', $school->id)
                ->whereIn('severity', ['high', 'critical'])
                ->whereDate('created_at', today())
                ->where('reviewed', false)
                ->count();
            
            if ($highSeverityCount > 10) {
                // Send alert to school admin
                $admins = User::where('school_id', $school->id)
                    ->where('role_type', 'school_admin')
                    ->get();
                
                foreach ($admins as $admin) {
                    $admin->notify(new HighSecurityAlertsNotification($highSeverityCount));
                }
            }
        }
    }
}
```

### Schedule in Kernel

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('security:send-alerts')
        ->hourly();
}
```

---

## 9. Performance Optimization

### Index Optimization

Ensure these indexes exist:

```sql
CREATE INDEX idx_security_events_school_date ON security_events(school_id, created_at);
CREATE INDEX idx_security_events_type_severity ON security_events(event_type, severity);
CREATE INDEX idx_suspicious_students_status ON suspicious_students(school_id, status);
CREATE INDEX idx_suspicious_devices_risk ON suspicious_devices(school_id, risk_level);
```

### Caching

Cache frequently accessed data:

```php
// Cache security summary for 5 minutes
$summary = Cache::remember("security_summary_{$schoolId}_" . today(), 300, function () use ($schoolId) {
    return $this->securityService->getDashboardMetrics($schoolId);
});
```

---

## 10. Deployment Checklist

- [ ] Run migrations
- [ ] Seed test data (optional)
- [ ] Configure scheduled jobs
- [ ] Set up monitoring alerts
- [ ] Test all API endpoints
- [ ] Verify auto-flagging logic
- [ ] Check frontend integration
- [ ] Review security event logging
- [ ] Test device blocking
- [ ] Verify role-based access

---

**Last Updated**: February 2, 2026  
**Version**: 1.0.0
