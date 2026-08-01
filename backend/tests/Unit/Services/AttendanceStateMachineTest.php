<?php

namespace Tests\Unit\Services;

use App\Enums\AttendanceState;
use App\Exceptions\StateViolationException;
use App\Models\Attendance;
use App\Models\School;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AttendanceStateMachineTest
 *
 * Comprehensive tests for the Attendance state machine implementation.
 * Verifies that:
 * 1. Valid state transitions work correctly
 * 2. Invalid state transitions throw exceptions
 * 3. Direct status/state modification is blocked
 * 4. Mass assignment of status/state is blocked
 * 5. State machine methods properly update related fields
 */
class AttendanceStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $admin;
    private User $student;
    private School $school;
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::factory()->create();

        // Create test users
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->admin = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'admin',
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        // Create test schedule
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
        ]);
    }

    // =========================================================================
    // VALID TRANSITIONS
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_attendance_in_init_state(): void
    {
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'request_id' => Str::uuid()->toString(),
        ]);

        $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());
        $this->assertTrue($attendance->isInit());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_transitions_from_init_to_checked_in(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $attendance->checkIn($this->teacher, 123.456, 78.910, 'device-001');

        $this->assertEquals(AttendanceState::CHECKED_IN, $attendance->getCurrentState());
        $this->assertTrue($attendance->isCheckedIn());
        $this->assertNotNull($attendance->check_in_time);
        $this->assertEquals($this->teacher->id, $attendance->recorded_by);
        $this->assertEquals(123.456, $attendance->lat_in);
        $this->assertEquals(78.910, $attendance->lng_in);
        $this->assertEquals('device-001', $attendance->device_id_in);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_transitions_from_checked_in_to_checked_out(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_IN);

        $attendance->checkOut($this->teacher, 123.456, 78.910, 'device-001');

        $this->assertEquals(AttendanceState::CHECKED_OUT, $attendance->getCurrentState());
        $this->assertTrue($attendance->isCheckedOut());
        $this->assertNotNull($attendance->check_out_time);
        $this->assertEquals(123.456, $attendance->lat_out);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_transitions_from_checked_out_to_pending_approval(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_OUT);

        $attendance->requestCorrection($this->student, 'Wrong check-in time');

        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->getCurrentState());
        $this->assertTrue($attendance->isPendingApproval());
        $this->assertEquals('Wrong check-in time', $attendance->correction_reason);
        $this->assertEquals($this->student->id, $attendance->correction_requested_by);
        $this->assertNotNull($attendance->correction_requested_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_transitions_from_pending_approval_to_approved(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::PENDING_APPROVAL);

        $attendance->approve($this->admin, 'Correction approved');

        $this->assertEquals(AttendanceState::APPROVED, $attendance->getCurrentState());
        $this->assertTrue($attendance->isApproved());
        $this->assertEquals($this->admin->id, $attendance->approved_by);
        $this->assertNotNull($attendance->approved_at);
        $this->assertEquals('Correction approved', $attendance->approval_notes);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_transitions_from_pending_approval_to_rejected(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::PENDING_APPROVAL);

        $attendance->reject($this->admin, 'Invalid correction request');

        $this->assertEquals(AttendanceState::REJECTED, $attendance->getCurrentState());
        $this->assertTrue($attendance->isRejected());
        $this->assertEquals($this->admin->id, $attendance->rejected_by);
        $this->assertNotNull($attendance->rejected_at);
        $this->assertEquals('Invalid correction request', $attendance->rejection_reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_retry_from_rejected_to_pending_approval(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::REJECTED);

        $attendance->requestCorrection($this->student, 'Please reconsider');

        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->getCurrentState());
    }

    // =========================================================================
    // INVALID TRANSITIONS - SHOULD THROW StateViolationException
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_checking_in_from_checked_in_state(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_IN);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Sudah melakukan check-in');

        $attendance->checkIn($this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_checking_in_from_checked_out_state(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_OUT);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Tidak dapat check-in setelah check-out');

        $attendance->checkIn($this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_checking_out_from_init_state(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Harus melakukan check-in terlebih dahulu');

        $attendance->checkOut($this->teacher);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_approving_from_init_state(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->expectException(StateViolationException::class);

        $attendance->approve($this->admin, 'Approved');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_rejecting_from_checked_in_state(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_IN);

        $this->expectException(StateViolationException::class);

        $attendance->reject($this->admin, 'Rejected');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_requesting_correction_from_init_state(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->expectException(StateViolationException::class);

        $attendance->requestCorrection($this->student, 'Need correction');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_approving_already_approved(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::APPROVED);

        $this->expectException(StateViolationException::class);

        $attendance->approve($this->admin, 'Double approve');
    }

    // =========================================================================
    // DIRECT MODIFICATION BLOCKING
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_status_modification_on_existing_record(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Use state machine methods');

        $attendance->status = 'present';
    }
    
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_status_modification_during_creation(): void
    {
        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Use state machine methods');

        // Attempt to create with status set directly
        $attendance = new Attendance([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'request_id' => Str::uuid()->toString(),
        ]);
        
        // This should throw exception
        $attendance->status = 'present';
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_state_modification_on_existing_record(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Use state machine methods');

        $attendance->state = AttendanceState::CHECKED_IN;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_state_string_modification_on_existing_record(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Use state machine methods');

        $attendance->state = 'checked_in';
    }

    // =========================================================================
    // MASS ASSIGNMENT PROTECTION
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ignores_status_in_mass_assignment(): void
    {
        // Laravel may throw MassAssignmentException when trying to create with guarded attributes
        // depending on configuration (Model::preventSilentlyDiscardingAttributes)
        try {
            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today()->subDays(99),
                'request_id' => Str::uuid()->toString(),
                'status' => 'present', // Should be ignored
            ]);

            // Status should NOT be 'present' because it's guarded
            // It should be based on initial state (INIT)
            $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            // This is also acceptable - Laravel prevents mass assignment of guarded attrs
            $this->assertStringContainsString('status', $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ignores_state_in_mass_assignment(): void
    {
        // Laravel may throw MassAssignmentException when trying to create with guarded attributes
        // depending on configuration (Model::preventSilentlyDiscardingAttributes)
        try {
            $attendance = Attendance::create([
                'school_id' => $this->school->id,
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today()->subDays(100),
                'request_id' => Str::uuid()->toString(),
                'state' => 'checked_in', // Should be ignored
            ]);

            // State should NOT be 'checked_in' because it's guarded
            // Default initial state should be INIT
            $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            // This is also acceptable - Laravel prevents mass assignment of guarded attrs
            $this->assertStringContainsString('state', $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ignores_status_in_update_via_fill(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);
        $originalState = $attendance->getCurrentState();

        // Laravel may throw MassAssignmentException when trying to fill guarded attributes
        // depending on configuration (Model::preventSilentlyDiscardingAttributes)
        // Either way, the state should not change
        try {
            $attendance->fill(['status' => 'present', 'notes' => 'Test note']);
            $attendance->save();
            
            // If no exception, verify state didn't change
            $this->assertEquals($originalState, $attendance->fresh()->getCurrentState());
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            // This is also acceptable - Laravel prevents mass assignment
            $this->assertStringContainsString('status', $e->getMessage());
        }
    }

    // =========================================================================
    // LEGACY STATUS SYNC
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_syncs_legacy_status_on_check_in(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $attendance->checkIn($this->teacher);

        $this->assertEquals('present', $attendance->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_syncs_legacy_status_on_approval(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::PENDING_APPROVAL);

        $attendance->approve($this->admin, 'Approved');

        $this->assertEquals('present', $attendance->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_syncs_legacy_status_on_rejection(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::PENDING_APPROVAL);

        $attendance->reject($this->admin, 'Rejected');

        $this->assertEquals('rejected', $attendance->fresh()->status);
    }

    // =========================================================================
    // STATE INSPECTION METHODS
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_correctly_reports_final_state(): void
    {
        $approved = $this->createAttendanceInState(AttendanceState::APPROVED);
        $this->assertTrue($approved->isFinalState());

        $checkedIn = $this->createAttendanceInState(AttendanceState::CHECKED_IN);
        $this->assertFalse($checkedIn->isFinalState());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_correctly_reports_counts_as_present(): void
    {
        $checkedIn = $this->createAttendanceInState(AttendanceState::CHECKED_IN);
        $this->assertTrue($checkedIn->countsAsPresent());

        $checkedOut = $this->createAttendanceInState(AttendanceState::CHECKED_OUT);
        $this->assertTrue($checkedOut->countsAsPresent());

        $approved = $this->createAttendanceInState(AttendanceState::APPROVED);
        $this->assertTrue($approved->countsAsPresent());

        $init = $this->createAttendanceInState(AttendanceState::INIT);
        $this->assertFalse($init->countsAsPresent());

        $rejected = $this->createAttendanceInState(AttendanceState::REJECTED);
        $this->assertFalse($rejected->countsAsPresent());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_correct_state_label(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_IN);
        $this->assertNotEmpty($attendance->state_label);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_correct_state_color(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::CHECKED_IN);
        $this->assertNotEmpty($attendance->state_color);
    }

    // =========================================================================
    // TRANSITION HELPERS
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function can_transition_to_returns_correct_value(): void
    {
        $attendance = $this->createAttendanceInState(AttendanceState::INIT);

        $this->assertTrue($attendance->canTransitionTo(AttendanceState::CHECKED_IN));
        $this->assertFalse($attendance->canTransitionTo(AttendanceState::CHECKED_OUT));
        $this->assertFalse($attendance->canTransitionTo(AttendanceState::APPROVED));
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private int $dateOffset = 0;

    /**
     * Create an attendance record in a specific state for testing
     * Each call creates a record for a unique date to avoid unique constraint violations
     */
    private function createAttendanceInState(AttendanceState $state): Attendance
    {
        $this->dateOffset++;
        
        $attendance = new Attendance([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today()->subDays($this->dateOffset),
            'request_id' => Str::uuid()->toString(),
            'attendance_type' => 'schedule', // Ensure consistent type
        ]);

        // Use internal method to set state directly for testing
        $attendance->setStateInternal($state);
        $attendance->syncLegacyStatus();
        $attendance->save();

        return $attendance->fresh();
    }
}
