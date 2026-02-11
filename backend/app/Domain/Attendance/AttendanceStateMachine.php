<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Enums\AttendanceState;
use App\Exceptions\StateViolationException;

/**
 * Domain-level state machine for Attendance.
 *
 * Extracted from the HasAttendanceStateMachine trait to provide
 * a pure domain representation of state transitions without
 * Eloquent model coupling.
 */
class AttendanceStateMachine
{
    public function __construct(
        private AttendanceState $currentState,
    ) {}

    public function getCurrentState(): AttendanceState
    {
        return $this->currentState;
    }

    /**
     * Validate and execute a state transition.
     *
     * @throws StateViolationException
     */
    public function transitionTo(AttendanceState $targetState): AttendanceState
    {
        if (! $this->canTransitionTo($targetState)) {
            throw StateViolationException::illegalTransition(
                $this->currentState,
                $targetState,
            );
        }

        $this->currentState = $targetState;

        return $this->currentState;
    }

    public function canTransitionTo(AttendanceState $targetState): bool
    {
        return $this->currentState->canTransitionTo($targetState);
    }

    public function isFinalState(): bool
    {
        return $this->currentState->isFinal();
    }

    /**
     * Get allowed transitions from current state.
     *
     * @return AttendanceState[]
     */
    public function getAvailableTransitions(): array
    {
        return $this->currentState->allowedTransitions();
    }

    /**
     * Map legacy status to the appropriate state.
     */
    public static function fromLegacyStatus(string $legacyStatus): self
    {
        return new self(AttendanceState::fromLegacyStatus($legacyStatus));
    }
}
