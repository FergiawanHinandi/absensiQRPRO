<?php

namespace App\Exceptions;

use App\Enums\AttendanceState;
use Exception;

/**
 * StateViolationException - Thrown when illegal state transition is attempted
 *
 * Used by the attendance state machine to prevent invalid transitions.
 *
 * USAGE:
 * throw StateViolationException::illegalTransition(
 *     AttendanceState::CHECKED_OUT,
 *     AttendanceState::CHECKED_IN
 * );
 *
 * EXAMPLE ERROR MESSAGE:
 * "Transisi status tidak valid: checked_out → checked_in. Status checked_out hanya bisa beralih ke: pending_approval"
 */
class StateViolationException extends Exception
{
    protected AttendanceState $fromState;
    protected AttendanceState $toState;
    protected array $allowedTransitions;

    public function __construct(
        AttendanceState $fromState,
        AttendanceState $toState,
        string $message = '',
        int $code = 422
    ) {
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->allowedTransitions = $fromState->allowedTransitions();

        parent::__construct($message ?: $this->buildMessage(), $code);
    }

    /**
     * Create exception for illegal transition attempt
     */
    public static function illegalTransition(
        AttendanceState $from,
        AttendanceState $to
    ): self {
        return new self($from, $to);
    }

    /**
     * Create exception for final state modification attempt
     */
    public static function finalStateModification(AttendanceState $currentState): self
    {
        return new self(
            $currentState,
            $currentState,
            "Status '{$currentState->label()}' adalah status final dan tidak dapat diubah."
        );
    }

    /**
     * Create exception for check-in after checkout
     */
    public static function cannotCheckInAfterCheckOut(): self
    {
        return new self(
            AttendanceState::CHECKED_OUT,
            AttendanceState::CHECKED_IN,
            'Tidak dapat check-in setelah check-out. Silakan ajukan koreksi jika diperlukan.'
        );
    }

    /**
     * Create exception for duplicate check-in
     */
    public static function alreadyCheckedIn(): self
    {
        return new self(
            AttendanceState::CHECKED_IN,
            AttendanceState::CHECKED_IN,
            'Sudah melakukan check-in sebelumnya.'
        );
    }

    /**
     * Create exception for checkout without check-in
     */
    public static function mustCheckInFirst(): self
    {
        return new self(
            AttendanceState::INIT,
            AttendanceState::CHECKED_OUT,
            'Harus melakukan check-in terlebih dahulu sebelum check-out.'
        );
    }

    /**
     * Build default error message
     */
    private function buildMessage(): string
    {
        $allowedLabels = array_map(
            fn (AttendanceState $s) => $s->value,
            $this->allowedTransitions
        );

        $allowedStr = empty($allowedLabels)
            ? 'tidak ada transisi yang diizinkan (status final)'
            : implode(', ', $allowedLabels);

        return sprintf(
            "Transisi status tidak valid: %s → %s. Status '%s' hanya bisa beralih ke: %s",
            $this->fromState->value,
            $this->toState->value,
            $this->fromState->label(),
            $allowedStr
        );
    }

    /**
     * Get the current/source state
     */
    public function getFromState(): AttendanceState
    {
        return $this->fromState;
    }

    /**
     * Get the attempted target state
     */
    public function getToState(): AttendanceState
    {
        return $this->toState;
    }

    /**
     * Get allowed transitions from current state
     */
    public function getAllowedTransitions(): array
    {
        return $this->allowedTransitions;
    }

    /**
     * Convert to API response format
     */
    public function toArray(): array
    {
        return [
            'error' => 'state_violation',
            'message' => $this->getMessage(),
            'from_state' => $this->fromState->value,
            'to_state' => $this->toState->value,
            'allowed_transitions' => array_map(
                fn (AttendanceState $s) => $s->value,
                $this->allowedTransitions
            ),
        ];
    }
}
