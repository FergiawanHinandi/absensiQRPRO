<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Attendance\Events\AttendanceApproved;
use App\Domain\Attendance\Events\AttendanceCheckedOut;
use App\Domain\Attendance\Events\AttendanceRecorded;
use App\Domain\Attendance\Events\CorrectionRequested;
use App\Domain\Attendance\ValueObjects\AttendanceStatus;
use App\Domain\Attendance\ValueObjects\AttendanceTimeWindow;
use App\Domain\Attendance\ValueObjects\GeoFence;
use App\Domain\Shared\AggregateRoot;
use App\Enums\AttendanceState;
use App\Exceptions\StateViolationException;
use App\Models\Attendance;
use Carbon\CarbonImmutable;

class AttendanceAggregateRoot extends AggregateRoot
{
    private Attendance $model;

    private function __construct(Attendance $model)
    {
        $this->model = $model;
    }

    /**
     * Reconstitute an aggregate from an existing Eloquent model.
     */
    public static function fromModel(Attendance $model): self
    {
        return new self($model);
    }

    /**
     * Create a new attendance aggregate for a student/schedule/date.
     */
    public static function create(
        int $studentId,
        int $scheduleId,
        int $schoolId,
        string $attendanceDate,
        ?int $classId = null,
        ?string $source = null,
        ?string $requestId = null,
    ): self {
        $model = new Attendance([
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'school_id' => $schoolId,
            'attendance_date' => $attendanceDate,
            'class_id' => $classId,
            'source' => $source ?? 'student_scan',
            'request_id' => $requestId,
        ]);

        return new self($model);
    }

    /**
     * Record a check-in with full invariant enforcement.
     */
    public function checkIn(
        CarbonImmutable $time,
        ?float $lat,
        ?float $lng,
        ?AttendanceTimeWindow $window = null,
        ?GeoFence $geoFence = null,
        ?int $recordedBy = null,
        ?string $deviceId = null,
    ): void {
        $currentState = $this->model->getCurrentState();

        // Invariant: can only check in from INIT state
        if (! $currentState->canTransitionTo(AttendanceState::CHECKED_IN)) {
            throw StateViolationException::illegalTransition(
                $currentState,
                AttendanceState::CHECKED_IN,
            );
        }

        // Invariant: must be within time window (if provided)
        if ($window !== null && ! $window->isWithinWindow($time)) {
            throw new \App\Exceptions\AttendanceException(
                'Check-in time is outside the allowed window. ' .
                "Window: {$window->windowOpens()->format('H:i')} - {$window->windowCloses()->format('H:i')}"
            );
        }

        // Invariant: must be within geo fence (if provided)
        if ($geoFence !== null && $lat !== null && $lng !== null) {
            if (! $geoFence->contains($lat, $lng)) {
                $distance = round($geoFence->distanceTo($lat, $lng));
                throw new \App\Exceptions\AttendanceException(
                    "Location is {$distance}m from school. Maximum allowed: {$geoFence->radiusMeters}m."
                );
            }
        }

        // Determine status based on lateness
        $status = AttendanceStatus::present();
        if ($window !== null && $window->isLate($time)) {
            $status = AttendanceStatus::late();
        }

        // Apply state transition
        $this->model->fill([
            'check_in_time' => $time,
            'lat_in' => $lat,
            'lng_in' => $lng,
            'recorded_by' => $recordedBy,
            'device_id_in' => $deviceId,
            'scanned_at' => $time,
        ]);

        // Use internal state change mechanism
        $this->model->setStateInternal(AttendanceState::CHECKED_IN);
        $this->model->syncLegacyStatus();

        // Record domain event
        $this->recordEvent(new AttendanceRecorded(
            attendanceId: $this->model->id ?? 0,
            studentId: (int) $this->model->student_id,
            scheduleId: (int) $this->model->schedule_id,
            schoolId: (int) $this->model->school_id,
            status: $status->value,
            checkInTime: $time->toIso8601String(),
            lat: $lat,
            lng: $lng,
            recordedBy: $this->model->source ?? 'student_scan',
            classId: $this->model->class_id ? (int) $this->model->class_id : null,
            attendanceDate: $this->model->attendance_date?->format('Y-m-d') ?? '',
            actorId: $recordedBy,
        ));
    }

    /**
     * Record a check-out.
     */
    public function checkOut(
        CarbonImmutable $time,
        ?float $lat = null,
        ?float $lng = null,
        ?int $recordedBy = null,
        ?string $deviceId = null,
    ): void {
        $currentState = $this->model->getCurrentState();

        if (! $currentState->canTransitionTo(AttendanceState::CHECKED_OUT)) {
            throw StateViolationException::illegalTransition(
                $currentState,
                AttendanceState::CHECKED_OUT,
            );
        }

        // Invariant: checkout time must be after check-in time
        $checkInTime = $this->model->check_in_time;
        if ($checkInTime && $time->isBefore(CarbonImmutable::parse($checkInTime))) {
            throw new \App\Exceptions\AttendanceException(
                'Check-out time cannot be before check-in time.'
            );
        }

        $this->model->fill([
            'check_out_time' => $time,
            'lat_out' => $lat,
            'lng_out' => $lng,
            'device_id_out' => $deviceId,
        ]);

        $this->model->setStateInternal(AttendanceState::CHECKED_OUT);
        $this->model->syncLegacyStatus();

        $this->recordEvent(new AttendanceCheckedOut(
            attendanceId: (int) $this->model->id,
            studentId: (int) $this->model->student_id,
            scheduleId: (int) $this->model->schedule_id,
            schoolId: (int) $this->model->school_id,
            checkOutTime: $time->toIso8601String(),
            lat: $lat,
            lng: $lng,
            actorId: $recordedBy,
        ));
    }

    /**
     * Request a correction for this attendance record.
     */
    public function requestCorrection(string $reason, int $requesterId): void
    {
        $currentState = $this->model->getCurrentState();

        if (! $currentState->canTransitionTo(AttendanceState::PENDING_APPROVAL)) {
            throw StateViolationException::illegalTransition(
                $currentState,
                AttendanceState::PENDING_APPROVAL,
            );
        }

        $this->model->fill([
            'correction_reason' => $reason,
            'correction_requested_by' => $requesterId,
            'correction_requested_at' => CarbonImmutable::now(),
        ]);

        $this->model->setStateInternal(AttendanceState::PENDING_APPROVAL);
        $this->model->syncLegacyStatus();

        $this->recordEvent(new CorrectionRequested(
            attendanceId: (int) $this->model->id,
            studentId: (int) $this->model->student_id,
            scheduleId: (int) $this->model->schedule_id,
            schoolId: (int) $this->model->school_id,
            reason: $reason,
            requesterId: $requesterId,
        ));
    }

    /**
     * Approve a pending correction.
     */
    public function approve(int $approverId, ?string $notes = null): void
    {
        $currentState = $this->model->getCurrentState();

        if (! $currentState->canTransitionTo(AttendanceState::APPROVED)) {
            throw StateViolationException::illegalTransition(
                $currentState,
                AttendanceState::APPROVED,
            );
        }

        $this->model->fill([
            'approved_by' => $approverId,
            'approved_at' => CarbonImmutable::now(),
            'approval_notes' => $notes,
        ]);

        $this->model->setStateInternal(AttendanceState::APPROVED);
        $this->model->syncLegacyStatus();

        $this->recordEvent(new AttendanceApproved(
            attendanceId: (int) $this->model->id,
            studentId: (int) $this->model->student_id,
            scheduleId: (int) $this->model->schedule_id,
            schoolId: (int) $this->model->school_id,
            approverId: $approverId,
        ));
    }

    /**
     * Reject a pending correction.
     */
    public function reject(int $rejectorId, string $reason): void
    {
        $currentState = $this->model->getCurrentState();

        if (! $currentState->canTransitionTo(AttendanceState::REJECTED)) {
            throw StateViolationException::illegalTransition(
                $currentState,
                AttendanceState::REJECTED,
            );
        }

        $this->model->fill([
            'rejected_by' => $rejectorId,
            'rejected_at' => CarbonImmutable::now(),
            'rejection_reason' => $reason,
        ]);

        $this->model->setStateInternal(AttendanceState::REJECTED);
        $this->model->syncLegacyStatus();
    }

    /**
     * Get the underlying Eloquent model.
     */
    public function getModel(): Attendance
    {
        return $this->model;
    }

    /**
     * Get the current state.
     */
    public function getCurrentState(): AttendanceState
    {
        return $this->model->getCurrentState();
    }
}
