<?php

namespace Tests\Feature;

use App\Enums\AttendanceState;
use App\Exceptions\StateViolationException;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\School;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AttendanceStateMachineTest
 * 
 * Comprehensive tests for state machine enforcement.
 * Verifies that direct status modification is blocked and all state transitions
 * go through the state machine methods.
 * 
 * Task 4.4: Write state tests (15 tests)
 */
class AttendanceStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $teacher;
    protected User $student;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['timezone' => 'Asia/Jakarta']);
        $this->teacher = User::factory()->create(['school_id' => $this->school->id]);
        $this->student = User::factory()->create(['school_id' => $this->school->id]);
        $this->schedule = Schedule::factory()->create(['school_id' => $this->school->id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // TEST 1-3: DIRECT STATUS/STATE MODIFICATION BLOCKING
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_status_modification_during_creation()
    {
        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Direct modification of status is prohibited');

        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'status' => 'present', // ❌ Should throw exception
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_status_modification_after_creation()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Direct modification of status is prohibited');

        $attendance->status = 'present'; // ❌ Should throw exception
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_state_modification_via_mass_assignment()
    {
        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Direct modification of state is prohibited');

        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'state' => 'checked_in', // ❌ Should throw exception
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // TEST 4-7: STATE MACHINE METHOD FUNCTIONALITY
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_check_in_via_state_machine()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());

        // ✅ Use state machine method
        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');

        $this->assertEquals(AttendanceState::CHECKED_IN, $attendance->fresh()->getCurrentState());
        $this->assertNotNull($attendance->check_in_time);
        $this->assertEquals($this->teacher->id, $attendance->recorded_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_check_out_via_state_machine()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        
        // ✅ Use state machine method
        $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        $this->assertEquals(AttendanceState::CHECKED_OUT, $attendance->fresh()->getCurrentState());
        $this->assertNotNull($attendance->check_out_time);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_correction_request_via_state_machine()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        // ✅ Use state machine method
        $attendance->requestCorrection($this->teacher, 'Wrong check-in time');

        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->fresh()->getCurrentState());
        $this->assertEquals('Wrong check-in time', $attendance->correction_reason);
        $this->assertEquals($this->teacher->id, $attendance->correction_requested_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_approval_via_state_machine()
    {
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

        // ✅ Use state machine method
        $attendance->approve($approver, 'Approved after review');

        $this->assertEquals(AttendanceState::APPROVED, $attendance->fresh()->getCurrentState());
        $this->assertEquals($approver->id, $attendance->approved_by);
        $this->assertEquals('Approved after review', $attendance->approval_notes);
    }

    // ─────────────────────────────────────────────────────────────────────
    // TEST 8-11: INVALID TRANSITION HANDLING
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_for_invalid_transition_check_out_before_check_in()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Must check-in first');

        // ❌ Cannot check out without checking in first
        $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_for_double_check_in()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Already checked in');

        // ❌ Cannot check in twice
        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_for_check_in_after_check_out()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Cannot check-in after check-out');

        // ❌ Cannot check in after checking out
        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_for_approval_from_non_pending_state()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');

        $approver = User::factory()->create(['school_id' => $this->school->id]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Illegal state transition');

        // ❌ Cannot approve from CHECKED_IN state (must be PENDING_APPROVAL)
        $attendance->approve($approver, 'Approved');
    }

    // ─────────────────────────────────────────────────────────────────────
    // TEST 12-13: AUDIT LOGGING VERIFICATION
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_all_state_transitions_to_audit_trail()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $initialLogCount = AttendanceLog::count();

        // Perform multiple transitions
        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');
        $attendance->checkOut($this->teacher, -6.200000, 106.816666, 'device-123');

        // Should have 2 new audit logs
        $this->assertEquals($initialLogCount + 2, AttendanceLog::count());

        $logs = AttendanceLog::where('attendance_id', $attendance->id)
            ->where('action', 'state_transition')
            ->orderBy('created_at', 'asc')
            ->get();

        // Verify first transition
        $this->assertEquals('init', $logs[0]->from_state);
        $this->assertEquals('checked_in', $logs[0]->to_state);
        $this->assertEquals($this->teacher->id, $logs[0]->performed_by);

        // Verify second transition
        $this->assertEquals('checked_in', $logs[1]->from_state);
        $this->assertEquals('checked_out', $logs[1]->to_state);
        $this->assertEquals($this->teacher->id, $logs[1]->performed_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_comprehensive_audit_data_in_logs()
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
        ]);

        $this->withHeaders([
            'X-Platform' => 'mobile',
            'X-App-Version' => '2.0.0',
        ]);

        $attendance->checkIn($this->teacher, -6.200000, 106.816666, 'device-123');

        $log = AttendanceLog::where('attendance_id', $attendance->id)
            ->where('action', 'state_transition')
            ->latest()
            ->first();

        // Verify comprehensive audit data
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->user_agent);
        $this->assertNotNull($log->device_info);
        $this->assertIsArray($log->device_info);
        $this->assertEquals('mobile', $log->device_info['platform']);
        $this->assertEquals('2.0.0', $log->device_info['app_version']);
        $this->assertNotNull($log->changes);
        $this->assertIsArray($log->changes);
        $this->assertArrayHasKey('state', $log->changes);
    }

    // ─────────────────────────────────────────────────────────────────────
    // TEST 14-15: FACTORY COMPLIANCE
    // ─────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function factory_creates_attendance_in_init_state_by_default()
    {
        $attendance = Attendance::factory()->create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
        ]);

        // Factory should create in INIT state (not directly set status)
        $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function factory_uses_state_machine_for_checked_in_state()
    {
        $attendance = Attendance::factory()
            ->checkedIn()
            ->create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
            ]);

        // Factory should use checkIn() method, not direct status assignment
        $this->assertEquals(AttendanceState::CHECKED_IN, $attendance->fresh()->getCurrentState());
        $this->assertNotNull($attendance->check_in_time);
        $this->assertNotNull($attendance->recorded_by);

        // Verify audit log was created
        $log = AttendanceLog::where('attendance_id', $attendance->id)
            ->where('action', 'state_transition')
            ->where('to_state', 'checked_in')
            ->first();

        $this->assertNotNull($log, 'Factory should use state machine which creates audit logs');
    }
}
