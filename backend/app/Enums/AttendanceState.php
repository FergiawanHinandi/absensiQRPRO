<?php

namespace App\Enums;

/**
 * AttendanceState - Explicit State Machine for Attendance
 *
 * STATE TRANSITION DIAGRAM:
 *
 *     ┌─────────────────────────────────────────────────────────────────┐
 *     │                    ATTENDANCE STATE MACHINE                     │
 *     └─────────────────────────────────────────────────────────────────┘
 *
 *                              ┌──────────┐
 *                              │   INIT   │  (Initial state)
 *                              └────┬─────┘
 *                                   │ checkIn()
 *                                   ▼
 *                           ┌──────────────┐
 *                           │  CHECKED_IN  │
 *                           └──────┬───────┘
 *                                  │ checkOut()
 *                                  ▼
 *                          ┌───────────────┐
 *                          │  CHECKED_OUT  │
 *                          └───────┬───────┘
 *                                  │ requestCorrection()
 *                                  ▼
 *                        ┌──────────────────┐
 *                        │ PENDING_APPROVAL │
 *                        └────────┬─────────┘
 *                           ┌─────┴─────┐
 *                  approve()│           │reject()
 *                           ▼           ▼
 *                    ┌──────────┐ ┌──────────┐
 *                    │ APPROVED │ │ REJECTED │
 *                    └──────────┘ └──────────┘
 *
 * VALID TRANSITIONS:
 * - INIT            → CHECKED_IN (via checkIn)
 * - CHECKED_IN      → CHECKED_OUT (via checkOut)
 * - CHECKED_OUT     → PENDING_APPROVAL (via requestCorrection)
 * - PENDING_APPROVAL → APPROVED (via approve)
 * - PENDING_APPROVAL → REJECTED (via reject)
 * - REJECTED        → PENDING_APPROVAL (via requestCorrection - retry)
 *
 * ILLEGAL TRANSITIONS (will throw StateViolationException):
 * - CHECKED_OUT → CHECKED_IN (cannot check-in again after checkout)
 * - APPROVED    → any state (final state)
 * - any → INIT (cannot reset to initial)
 */
enum AttendanceState: string
{
    case INIT = 'init';
    case CHECKED_IN = 'checked_in';
    case CHECKED_OUT = 'checked_out';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /**
     * Get allowed transitions from this state
     *
     * @return array<AttendanceState>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::INIT => [self::CHECKED_IN],
            self::CHECKED_IN => [self::CHECKED_OUT],
            self::CHECKED_OUT => [self::PENDING_APPROVAL],
            self::PENDING_APPROVAL => [self::APPROVED, self::REJECTED],
            self::REJECTED => [self::PENDING_APPROVAL], // Allow retry
            self::APPROVED => [], // Final state - no transitions allowed
        };
    }

    /**
     * Check if transition to target state is allowed
     */
    public function canTransitionTo(AttendanceState $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Check if this is a final state
     */
    public function isFinal(): bool
    {
        return $this === self::APPROVED;
    }

    /**
     * Check if attendance is considered "present" for reporting
     */
    public function isPresent(): bool
    {
        return in_array($this, [
            self::CHECKED_IN,
            self::CHECKED_OUT,
            self::APPROVED,
        ], true);
    }

    /**
     * Get human-readable label (Indonesian)
     */
    public function label(): string
    {
        return match ($this) {
            self::INIT => 'Belum Absen',
            self::CHECKED_IN => 'Sudah Masuk',
            self::CHECKED_OUT => 'Sudah Pulang',
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
        };
    }

    /**
     * Get badge color for UI
     */
    public function color(): string
    {
        return match ($this) {
            self::INIT => 'gray',
            self::CHECKED_IN => 'blue',
            self::CHECKED_OUT => 'green',
            self::PENDING_APPROVAL => 'yellow',
            self::APPROVED => 'emerald',
            self::REJECTED => 'red',
        };
    }

    /**
     * Map legacy status to new state
     *
     * @param string $legacyStatus Old status value (present, late, absent, etc.)
     * @return self
     */
    public static function fromLegacyStatus(string $legacyStatus): self
    {
        return match ($legacyStatus) {
            'present', 'late' => self::CHECKED_IN,
            'absent', 'sick', 'permit', 'excused' => self::INIT,
            'checked_in' => self::CHECKED_IN,
            'checked_out' => self::CHECKED_OUT,
            'pending_approval' => self::PENDING_APPROVAL,
            'approved' => self::APPROVED,
            'rejected' => self::REJECTED,
            default => self::INIT,
        };
    }
}
