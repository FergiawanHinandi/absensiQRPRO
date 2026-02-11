<?php

namespace Tests\Feature;

use App\Enums\AttendanceState;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\School;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AttendanceAuditLoggingTest
 * 
 * Tests for state machine audit logging functionality.
 * Verifies that all state transitions are properly logged with complete audit trail.
 */
class AttendanceAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $teacher;
    protected User $student;
    protected Schedule $schedule;
    protected Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        // Use SQLite for testing
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);

        $this->school = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        $this->teacher = User::factory()->create(['school_id' => $this->school->id]);
        $this->student = User::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create(['school_id' => $this->school->id]);
    }

    /** @test */
    public function it_logs_check_in_transition()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $initialLogCount = AttendanceLog::count();

        $this->attendance->checkIn(
            $this->teacher,
            -6.200000,
            106.816666,
            'device-123'
        );

        // Assert log was created
        $this->assertEquals($initialLogCount + 1, AttendanceLog::count());

        $log = AttendanceLog::latest()->first();
        
        $this->assertEquals('state_transition', $log->action);
        $this->assertEquals('init', $log->from_state);
        $this->assertEquals('checked_in', $log->to_state);
        $this->assertEquals($this->teacher->id, $log->performed_by);
        $this->assertEquals('Check-in recorded', $log->reason);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->user_agent);
    }

    /** @test */
    public function it_logs_check_out_transition()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        
        $initialLogCount = AttendanceLog::count();

        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        // Assert new log was created
        $this->assertEquals($initialLogCount + 1, AttendanceLog::count());

        $log = AttendanceLog::latest()->first();
        
        $this->assertEquals('state_transition', $log->action);
        $this->assertEquals('checked_in', $log->from_state);
        $this->assertEquals('checked_out', $log->to_state);
        $this->assertEquals($this->teacher->id, $log->performed_by);
        $this->assertEquals('Check-out recorded', $log->reason);
    }

    /** @test */
    public function it_logs_correction_request_transition()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        $initialLogCount = AttendanceLog::count();

        $this->attendance->requestCorrection($this->teacher, 'Wrong check-in time');

        $this->assertEquals($initialLogCount + 1, AttendanceLog::count());

        $log = AttendanceLog::latest()->first();
        
        $this->assertEquals('state_transition', $log->action);
        $this->assertEquals('checked_out', $log->from_state);
        $this->assertEquals('pending_approval', $log->to_state);
        $this->assertEquals($this->teacher->id, $log->performed_by);
        $this->assertStringContainsString('Wrong check-in time', $log->reason);
    }

    /** @test */
    public function it_logs_approval_transition()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->requestCorrection($this->teacher, 'Correction needed');

        $approver = User::factory()->create(['school_id' => $this->school->id]);
        $initialLogCount = AttendanceLog::count();

        $this->attendance->approve($approver, 'Approved after review');

        $this->assertEquals($initialLogCount + 1, AttendanceLog::count());

        $log = AttendanceLog::latest()->first();
        
        $this->assertEquals('state_transition', $log->action);
        $this->assertEquals('pending_approval', $log->from_state);
        $this->assertEquals('approved', $log->to_state);
        $this->assertEquals($approver->id, $log->performed_by);
        $this->assertEquals('Approved after review', $log->reason);
    }

    /** @test */
    public function it_logs_rejection_transition()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->requestCorrection($this->teacher, 'Correction needed');

        $rejector = User::factory()->create(['school_id' => $this->school->id]);
        $initialLogCount = AttendanceLog::count();

        $this->attendance->reject($rejector, 'Invalid correction request');

        $this->assertEquals($initialLogCount + 1, AttendanceLog::count());

        $log = AttendanceLog::latest()->first();
        
        $this->assertEquals('state_transition', $log->action);
        $this->assertEquals('pending_approval', $log->from_state);
        $this->assertEquals('rejected', $log->to_state);
        $this->assertEquals($rejector->id, $log->performed_by);
        $this->assertEquals('Invalid correction request', $log->reason);
    }

    /** @test */
    public function it_captures_attribute_changes_in_audit_log()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');

        $log = AttendanceLog::latest()->first();
        
        $this->assertNotNull($log->changes);
        $this->assertIsArray($log->changes);
        $this->assertArrayHasKey('state', $log->changes);
        $this->assertEquals('init', $log->changes['state']['from']);
        $this->assertEquals('checked_in', $log->changes['state']['to']);
    }

    /** @test */
    public function it_captures_device_information_in_audit_log()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->withHeaders([
            'X-Platform' => 'mobile',
            'X-App-Version' => '1.0.0',
            'X-Device-Model' => 'iPhone 13',
            'X-OS-Version' => 'iOS 15.0',
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');

        $log = AttendanceLog::latest()->first();
        
        $this->assertNotNull($log->device_info);
        $this->assertIsArray($log->device_info);
        $this->assertEquals('mobile', $log->device_info['platform']);
        $this->assertEquals('1.0.0', $log->device_info['app_version']);
        $this->assertEquals('iPhone 13', $log->device_info['device_model']);
        $this->assertEquals('iOS 15.0', $log->device_info['os_version']);
    }

    /** @test */
    public function it_maintains_complete_audit_trail_for_full_workflow()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        // Complete workflow: init -> checked_in -> checked_out -> pending_approval -> approved
        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->requestCorrection($this->teacher, 'Need correction');
        
        $approver = User::factory()->create(['school_id' => $this->school->id]);
        $this->attendance->approve($approver, 'Approved');

        // Should have 4 audit logs (one for each transition)
        $logs = AttendanceLog::where('attendance_id', $this->attendance->id)
            ->where('action', 'state_transition')
            ->orderBy('created_at', 'asc')
            ->get();

        $this->assertCount(4, $logs);

        // Verify transition sequence
        $this->assertEquals('init', $logs[0]->from_state);
        $this->assertEquals('checked_in', $logs[0]->to_state);

        $this->assertEquals('checked_in', $logs[1]->from_state);
        $this->assertEquals('checked_out', $logs[1]->to_state);

        $this->assertEquals('checked_out', $logs[2]->from_state);
        $this->assertEquals('pending_approval', $logs[2]->to_state);

        $this->assertEquals('pending_approval', $logs[3]->from_state);
        $this->assertEquals('approved', $logs[3]->to_state);
    }

    /** @test */
    public function audit_service_can_retrieve_complete_audit_trail()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        $service = new AttendanceAuditService();
        $trail = $service->getAuditTrail($this->attendance->id);

        $this->assertCount(2, $trail);
        $this->assertEquals('init', $trail[0]['from_state']);
        $this->assertEquals('checked_in', $trail[0]['to_state']);
        $this->assertEquals($this->teacher->name, $trail[0]['performed_by']);
    }

    /** @test */
    public function audit_service_can_generate_transition_statistics()
    {
        // Create multiple attendances with transitions
        for ($i = 0; $i < 3; $i++) {
            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today(),
            ]);
            $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        }

        $service = new AttendanceAuditService();
        $stats = $service->getTransitionStatistics($this->school->id);

        $this->assertArrayHasKey('total_transitions', $stats);
        $this->assertArrayHasKey('transitions_by_type', $stats);
        $this->assertEquals(3, $stats['total_transitions']);
    }

    /** @test */
    public function audit_service_can_generate_user_activity_report()
    {
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        $service = new AttendanceAuditService();
        $report = $service->getUserActivityReport($this->school->id);

        $this->assertGreaterThan(0, $report->count());
        $this->assertEquals($this->teacher->id, $report->first()['user_id']);
        $this->assertEquals(2, $report->first()['total_actions']);
    }

    /** @test */
    public function audit_service_can_detect_suspicious_activity()
    {
        // Create attendance with many rapid transitions (suspicious)
        $this->attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        // Simulate 6 transitions (more than threshold of 5)
        for ($i = 0; $i < 3; $i++) {
            $this->attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
            $this->attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
        }

        $service = new AttendanceAuditService();
        $suspicious = $service->detectSuspiciousActivity($this->school->id);

        $this->assertArrayHasKey('rapid_state_changes', $suspicious);
        $this->assertContains($this->attendance->id, $suspicious['rapid_state_changes']['attendance_ids']);
    }

    /** @test */
    public function audit_service_can_calculate_approval_metrics()
    {
        // Create attendances with different approval outcomes
        for ($i = 0; $i < 2; $i++) {
            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today(),
            ]);
            $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
            $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
            $attendance->requestCorrection($this->teacher, 'Correction needed');
            
            $approver = User::factory()->create(['school_id' => $this->school->id]);
            $attendance->approve($approver, 'Approved');
        }

        // Create one rejection
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);
        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
        $attendance->requestCorrection($this->teacher, 'Correction needed');
        
        $rejector = User::factory()->create(['school_id' => $this->school->id]);
        $attendance->reject($rejector, 'Rejected');

        $service = new AttendanceAuditService();
        $metrics = $service->getApprovalMetrics($this->school->id);

        $this->assertEquals(2, $metrics['total_approvals']);
        $this->assertEquals(1, $metrics['total_rejections']);
        $this->assertEquals(66.67, $metrics['approval_rate']);
        $this->assertEquals(33.33, $metrics['rejection_rate']);
    }
}
