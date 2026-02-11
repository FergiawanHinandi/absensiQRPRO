<?php

namespace Tests\Unit\Models;

use App\Enums\AttendanceState;
use App\Exceptions\StateViolationException;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AttendanceStateMachineTest
 *
 * Comprehensive test suite for Attendance Aggregate Root and State Machine.
 *
 * COVERAGE:
 * - All valid state transitions
 * - All invalid state transitions
 * - Double check-in prevention
 * - Checkout before check-in prevention
 * - Approval before checkout prevention
 * - Direct status modification blocking
 * - Transaction rollback on failure
 * - Audit trail logging
 *
 * @group attendance
 * @group state-machine
 */
class AttendanceStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;
    protected User $student;
    protected User $admin;
    protected Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->teacher = User::factory()->create(['role_type' => 'teacher']);
        $this->student = User::factory()->create(['role_type' => 'student']);
        $this->admin = User::factory()->create(['role_type' => 'admin']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // VALID TRANSITIONS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_can_transition_from_init_to_checked_in()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $attendance->checkIn(
            $this->teacher,
            latitude: -6.2088,
            longitude: 106.8456,
            deviceId: 'device-123'
        );

        $this->assertEquals(AttendanceState::CHECKED_IN, $attendance->getCurrentState());
        $this->assertNotNull($attendance->check_in_time);
        $this->assertEquals($this->teacher->id, $attendance->recorded_by);
        $this->assertEquals(-6.2088, $attendance->lat_in);
        $this->assertEquals(106.8456, $attendance->lng_in);
        $this->assertEquals('device-123', $attendance->device_id_in);
        $this->assertEquals('present', $attendance->status); // Legacy status synced
    }

    /** @test */
    public function it_can_transition_from_checked_in_to_checked_out()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_IN,
            'student_id' => $this->student->id,
            'check_in_time' => now()->subHours(2),
        ]);

        $attendance->checkOut(
            $this->teacher,
            latitude: -6.2088,
            longitude: 106.8456,
            deviceId: 'device-123'
        );

        $this->assertEquals(AttendanceState::CHECKED_OUT, $attendance->getCurrentState());
        $this->assertNotNull($attendance->check_out_time);
        $this->assertEquals(-6.2088, $attendance->lat_out);
        $this->assertEquals(106.8456, $attendance->lng_out);
        $this->assertEquals('device-123', $attendance->device_id_out);
        $this->assertEquals('present', $attendance->status); // Legacy status synced
    }

    /** @test */
    public function it_can_transition_from_checked_out_to_pending_approval()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_OUT,
            'student_id' => $this->student->id,
        ]);

        $attendance->requestCorrection($this->student, 'Forgot to check out on time');

        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->getCurrentState());
        $this->assertEquals('Forgot to check out on time', $attendance->correction_reason);
        $this->assertEquals($this->student->id, $attendance->correction_requested_by);
        $this->assertNotNull($attendance->correction_requested_at);
        $this->assertEquals('pending', $attendance->status); // Legacy status synced
    }

    /** @test */
    public function it_can_transition_from_pending_approval_to_approved()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::PENDING_APPROVAL,
            'student_id' => $this->student->id,
        ]);

        $attendance->approve($this->admin, 'Correction approved');

        $this->assertEquals(AttendanceState::APPROVED, $attendance->getCurrentState());
        $this->assertEquals($this->admin->id, $attendance->approved_by);
        $this->assertNotNull($attendance->approved_at);
        $this->assertEquals('Correction approved', $attendance->approval_notes);
        $this->assertEquals('present', $attendance->status); // Legacy status synced
    }

    /** @test */
    public function it_can_transition_from_pending_approval_to_rejected()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::PENDING_APPROVAL,
            'student_id' => $this->student->id,
        ]);

        $attendance->reject($this->admin, 'Invalid correction request');

        $this->assertEquals(AttendanceState::REJECTED, $attendance->getCurrentState());
        $this->assertEquals($this->admin->id, $attendance->rejected_by);
        $this->assertNotNull($attendance->rejected_at);
        $this->assertEquals('Invalid correction request', $attendance->rejection_reason);
        $this->assertEquals('rejected', $attendance->status); // Legacy status synced
    }

    /** @test */
    public function it_can_transition_from_rejected_to_pending_approval_for_retry()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::REJECTED,
            'student_id' => $this->student->id,
        ]);

        $attendance->requestCorrection($this->student, 'Retry with more details');

        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->getCurrentState());
        $this->assertEquals('Retry with more details', $attendance->correction_reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // INVALID TRANSITIONS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_cannot_check_in_twice()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_IN,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Sudah melakukan check-in sebelumnya');

        $attendance->checkIn($this->teacher);
    }

    /** @test */
    public function it_cannot_check_in_after_check_out()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_OUT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Tidak dapat check-in setelah check-out');

        $attendance->checkIn($this->teacher);
    }

    /** @test */
    public function it_cannot_check_out_before_check_in()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Harus melakukan check-in terlebih dahulu');

        $attendance->checkOut($this->teacher);
    }

    /** @test */
    public function it_cannot_request_correction_before_check_out()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_IN,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->requestCorrection($this->student, 'Some reason');
    }

    /** @test */
    public function it_cannot_approve_before_pending()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_OUT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->approve($this->admin);
    }

    /** @test */
    public function it_cannot_reject_before_pending()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_IN,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->reject($this->admin, 'Some reason');
    }

    /** @test */
    public function it_cannot_modify_approved_state()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::APPROVED,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->requestCorrection($this->student, 'Try to modify approved');
    }

    /** @test */
    public function it_cannot_transition_from_init_to_checked_out()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->checkOut($this->teacher);
    }

    /** @test */
    public function it_cannot_transition_from_init_to_pending_approval()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->requestCorrection($this->student, 'Invalid');
    }

    // ─────────────────────────────────────────────────────────────────────
    // DIRECT MODIFICATION BLOCKING
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_blocks_direct_status_modification_via_update()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Modifikasi langsung pada \'status\' tidak diizinkan');

        $attendance->update(['status' => 'present']);
    }

    /** @test */
    public function it_blocks_direct_state_modification_via_update()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);
        $this->expectExceptionMessage('Modifikasi langsung pada \'state\' tidak diizinkan');

        $attendance->update(['state' => AttendanceState::CHECKED_IN]);
    }

    /** @test */
    public function it_blocks_direct_status_modification_via_attribute_setter()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->status = 'present';
        $attendance->save();
    }

    /** @test */
    public function it_blocks_direct_state_modification_via_attribute_setter()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->expectException(StateViolationException::class);

        $attendance->state = AttendanceState::CHECKED_IN;
        $attendance->save();
    }

    // ─────────────────────────────────────────────────────────────────────
    // TRANSACTION ROLLBACK
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_rolls_back_transaction_on_invalid_transition()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
            'notes' => 'Original notes',
        ]);

        try {
            // Try invalid transition
            $attendance->checkOut($this->teacher);
        } catch (StateViolationException $e) {
            // Expected exception
        }

        // Refresh from database
        $attendance->refresh();

        // State should remain unchanged
        $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());
        $this->assertEquals('Original notes', $attendance->notes);
        $this->assertNull($attendance->check_out_time);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AUDIT TRAIL
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_logs_state_transitions()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $attendance->checkIn($this->teacher);

        // Check if log was created
        $this->assertDatabaseHas('attendance_logs', [
            'attendance_id' => $attendance->id,
            'action' => 'state_transition',
            'performed_by' => $this->teacher->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STATE INSPECTION METHODS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_provides_state_inspection_methods()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_IN,
            'student_id' => $this->student->id,
        ]);

        $this->assertTrue($attendance->isCheckedIn());
        $this->assertFalse($attendance->isInit());
        $this->assertFalse($attendance->isCheckedOut());
        $this->assertFalse($attendance->isPendingApproval());
        $this->assertFalse($attendance->isApproved());
        $this->assertFalse($attendance->isRejected());
        $this->assertFalse($attendance->isFinalState());
        $this->assertTrue($attendance->countsAsPresent());
    }

    /** @test */
    public function it_identifies_final_state()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::APPROVED,
            'student_id' => $this->student->id,
        ]);

        $this->assertTrue($attendance->isFinalState());
        $this->assertTrue($attendance->isApproved());
        $this->assertTrue($attendance->countsAsPresent());
    }

    // ─────────────────────────────────────────────────────────────────────
    // COMPLETE WORKFLOW TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_completes_full_happy_path_workflow()
    {
        // 1. Create attendance in INIT state
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);
        $this->assertEquals(AttendanceState::INIT, $attendance->getCurrentState());

        // 2. Check in
        $attendance->checkIn($this->teacher, -6.2088, 106.8456, 'device-123');
        $this->assertEquals(AttendanceState::CHECKED_IN, $attendance->getCurrentState());

        // 3. Check out
        $attendance->checkOut($this->teacher, -6.2088, 106.8456, 'device-123');
        $this->assertEquals(AttendanceState::CHECKED_OUT, $attendance->getCurrentState());

        // 4. Request correction
        $attendance->requestCorrection($this->student, 'Need to adjust time');
        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->getCurrentState());

        // 5. Approve
        $attendance->approve($this->admin, 'Approved');
        $this->assertEquals(AttendanceState::APPROVED, $attendance->getCurrentState());
        $this->assertTrue($attendance->isFinalState());
    }

    /** @test */
    public function it_completes_rejection_and_retry_workflow()
    {
        // 1. Create attendance and go to PENDING_APPROVAL
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);
        $attendance->checkIn($this->teacher);
        $attendance->checkOut($this->teacher);
        $attendance->requestCorrection($this->student, 'First attempt');

        // 2. Reject
        $attendance->reject($this->admin, 'Insufficient evidence');
        $this->assertEquals(AttendanceState::REJECTED, $attendance->getCurrentState());

        // 3. Retry with more details
        $attendance->requestCorrection($this->student, 'Second attempt with evidence');
        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $attendance->getCurrentState());

        // 4. Approve on second attempt
        $attendance->approve($this->admin, 'Approved with evidence');
        $this->assertEquals(AttendanceState::APPROVED, $attendance->getCurrentState());
    }

    // ─────────────────────────────────────────────────────────────────────
    // LEGACY STATUS SYNC
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function it_syncs_legacy_status_field_on_state_change()
    {
        $attendance = Attendance::factory()->create([
            'state' => AttendanceState::INIT,
            'student_id' => $this->student->id,
        ]);

        $this->assertEquals('absent', $attendance->status);

        $attendance->checkIn($this->teacher);
        $this->assertEquals('present', $attendance->status);

        $attendance->checkOut($this->teacher);
        $this->assertEquals('present', $attendance->status);

        $attendance->requestCorrection($this->student, 'Test');
        $this->assertEquals('pending', $attendance->status);

        $attendance->approve($this->admin);
        $this->assertEquals('present', $attendance->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GUARD TESTS
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function status_and_state_are_not_in_fillable()
    {
        $fillable = (new Attendance())->getFillable();

        $this->assertNotContains('status', $fillable);
        $this->assertNotContains('state', $fillable);
    }

    /** @test */
    public function status_and_state_are_in_guarded()
    {
        $guarded = (new Attendance())->getGuarded();

        $this->assertContains('status', $guarded);
        $this->assertContains('state', $guarded);
    }
}
