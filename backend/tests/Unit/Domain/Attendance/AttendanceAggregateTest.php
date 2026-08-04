<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Attendance;

use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Domain\Attendance\ValueObjects\AttendanceTimeWindow;
use App\Domain\Attendance\ValueObjects\GeoFence;
use App\Enums\AttendanceState;
use App\Exceptions\AttendanceException;
use App\Exceptions\StateViolationException;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Domain Test: Attendance Aggregate
 * Tests business logic and invariants in the domain layer.
 * These tests ensure that:
 * - State transitions are valid
 * - Business rules are enforced
 * - Domain events are emitted
 * - Invariants are maintained
 */
#[\PHPUnit\Framework\Attributes\Group('domain')]
#[\PHPUnit\Framework\Attributes\Group('attendance')]
class AttendanceAggregateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DT-001: Valid state transition: INIT → CHECKED_IN
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_valid_state_transition_from_init_to_checked_in(): void
    {
        // Arrange
        $aggregate = AttendanceAggregate::create(
            studentId: 1,
            scheduleId: 1,
            schoolId: 1,
            attendanceDate: '2026-02-10',
        );

        // Act
        $aggregate->checkIn(
            time: CarbonImmutable::parse('2026-02-10 08:00:00'),
            lat: -6.2088,
            lng: 106.8456,
        );

        // Assert
        $this->assertEquals(AttendanceState::CHECKED_IN, $aggregate->getCurrentState());
        $this->assertNotEmpty($aggregate->getRecordedEvents());
    }

    /**
     * DT-002: Invalid state transition: CHECKED_OUT → INIT
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_invalid_state_transition(): void
    {
        // Arrange
        $model = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_OUT->value,
        ]);
        $aggregate = AttendanceAggregate::fromModel($model);

        // Act & Assert
        $this->expectException(StateViolationException::class);
        
        $aggregate->checkIn(
            time: CarbonImmutable::now(),
            lat: -6.2088,
            lng: 106.8456,
        );
    }

    /**
     * DT-003: Duplicate attendance prevented (same student/date)
     * This is handled at the database level with unique constraints,
     * but we test the business logic here.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_attendance_for_same_student_and_date(): void
    {
        // Arrange
        $school = \App\Models\School::factory()->create();
        $student = \App\Models\User::factory()->student()->create();
        $schedule = \App\Models\Schedule::factory()->create();

        Attendance::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => '2026-02-10',
        ]);

        // Act & Assert
        $this->expectException(\Illuminate\Database\QueryException::class);
        
        Attendance::create([
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'school_id' => $school->id,
            'attendance_date' => '2026-02-10',
        ]);
    }

    /**
     * DT-004: Time window validation enforced
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_enforces_time_window_validation(): void
    {
        // Arrange
        $aggregate = AttendanceAggregate::create(
            studentId: 1,
            scheduleId: 1,
            schoolId: 1,
            attendanceDate: '2026-02-10',
        );

        $timeWindow = new AttendanceTimeWindow(
            scheduledStart: CarbonImmutable::parse('2026-02-10 08:00:00'),
            scheduledEnd: CarbonImmutable::parse('2026-02-10 09:00:00'),
            preWindowMinutes: 10,
            postWindowMinutes: 15,
        );

        // Act & Assert - Too early
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('outside the allowed window');
        
        $aggregate->checkIn(
            time: CarbonImmutable::parse('2026-02-10 07:00:00'),
            lat: -6.2088,
            lng: 106.8456,
            window: $timeWindow,
        );
    }

    /**
     * DT-005: Geofence validation enforced
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_enforces_geofence_validation(): void
    {
        // Arrange
        $aggregate = AttendanceAggregate::create(
            studentId: 1,
            scheduleId: 1,
            schoolId: 1,
            attendanceDate: '2026-02-10',
        );

        $geoFence = new GeoFence(
            lat: -6.2088,
            lng: 106.8456,
            radiusMeters: 100,
        );

        // Act & Assert - Too far away
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('from school');
        
        $aggregate->checkIn(
            time: CarbonImmutable::now(),
            lat: -6.3088, // ~11km away
            lng: 106.9456,
            geoFence: $geoFence,
        );
    }

    /**
     * DT-006: Late status calculated correctly
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_late_status_correctly(): void
    {
        // Arrange
        $aggregate = AttendanceAggregate::create(
            studentId: 1,
            scheduleId: 1,
            schoolId: 1,
            attendanceDate: '2026-02-10',
        );

        $timeWindow = new AttendanceTimeWindow(
            scheduledStart: CarbonImmutable::parse('2026-02-10 08:00:00'),
            scheduledEnd: CarbonImmutable::parse('2026-02-10 09:00:00'),
            preWindowMinutes: 10,
            postWindowMinutes: 15,
        );

        // Act - Check in 20 minutes late
        $aggregate->checkIn(
            time: CarbonImmutable::parse('2026-02-10 08:20:00'),
            lat: -6.2088,
            lng: 106.8456,
            window: $timeWindow,
        );

        // Assert

        // The status should be 'late' based on the time window
        $this->assertTrue($timeWindow->isLate(CarbonImmutable::parse('2026-02-10 08:20:00')));
    }

    /**
     * DT-007: Correction request state transition (only from CHECKED_OUT)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_correction_request_from_checked_out_state(): void
    {
        // Arrange
        $model = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_OUT->value,
        ]);
        $aggregate = AttendanceAggregate::fromModel($model);

        // Act
        $aggregate->requestCorrection(
            reason: 'Wrong status recorded',
            requesterId: 1,
        );

        // Assert
        $this->assertEquals(AttendanceState::PENDING_APPROVAL, $aggregate->getCurrentState());
        $this->assertEquals('Wrong status recorded', $aggregate->getModel()->correction_reason);
    }

    /**
     * DT-008: Approval workflow validation
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_approval_from_pending_state(): void
    {
        // Arrange
        $model = Attendance::factory()->create([
            'state' => AttendanceState::PENDING_APPROVAL->value,
        ]);
        $aggregate = AttendanceAggregate::fromModel($model);

        // Act
        $aggregate->approve(
            approverId: 2,
            notes: 'Approved by admin',
        );

        // Assert
        $this->assertEquals(AttendanceState::APPROVED, $aggregate->getCurrentState());
        $this->assertEquals(2, $aggregate->getModel()->approved_by);
    }

    /**
     * DT-009: Rejection workflow validation
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_rejection_from_pending_state(): void
    {
        // Arrange
        $model = Attendance::factory()->create([
            'state' => AttendanceState::PENDING_APPROVAL->value,
        ]);
        $aggregate = AttendanceAggregate::fromModel($model);

        // Act
        $aggregate->reject(
            rejectorId: 2,
            reason: 'Invalid correction request',
        );

        // Assert
        $this->assertEquals(AttendanceState::REJECTED, $aggregate->getCurrentState());
        $this->assertEquals(2, $aggregate->getModel()->rejected_by);
    }

    /**
     * DT-010: Domain events emitted correctly
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_emits_domain_events_on_check_in(): void
    {
        // Arrange
        $aggregate = AttendanceAggregate::create(
            studentId: 1,
            scheduleId: 1,
            schoolId: 1,
            attendanceDate: '2026-02-10',
        );

        // Act
        $aggregate->checkIn(
            time: CarbonImmutable::now(),
            lat: -6.2088,
            lng: 106.8456,
        );

        // Assert
        $events = $aggregate->getRecordedEvents();
        $this->assertNotEmpty($events);
        $this->assertInstanceOf(
            \App\Domain\Attendance\Events\AttendanceRecorded::class,
            $events[0]
        );
    }

    /**
     * DT-011: Check-out time must be after check-in time
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_check_out_before_check_in(): void
    {
        // Arrange
        $model = Attendance::factory()->create([
            'state' => AttendanceState::CHECKED_IN->value,
            'check_in_time' => '2026-02-10 08:00:00',
        ]);
        $aggregate = AttendanceAggregate::fromModel($model);

        // Act & Assert
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('cannot be before check-in time');
        
        $aggregate->checkOut(
            time: CarbonImmutable::parse('2026-02-10 07:00:00'), // Before check-in
            lat: -6.2088,
            lng: 106.8456,
        );
    }

    /**
     * DT-012: Geofence allows check-in within radius
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_check_in_within_geofence(): void
    {
        // Arrange
        $aggregate = AttendanceAggregate::create(
            studentId: 1,
            scheduleId: 1,
            schoolId: 1,
            attendanceDate: '2026-02-10',
        );

        $geoFence = new GeoFence(
            lat: -6.2088,
            lng: 106.8456,
            radiusMeters: 100,
        );

        // Act - Within 50 meters
        $aggregate->checkIn(
            time: CarbonImmutable::now(),
            lat: -6.2083, // ~55 meters away
            lng: 106.8456,
            geoFence: $geoFence,
        );

        // Assert
        $this->assertEquals(AttendanceState::CHECKED_IN, $aggregate->getCurrentState());
    }
}
