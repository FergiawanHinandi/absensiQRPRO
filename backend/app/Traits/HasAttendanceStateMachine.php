<?php

namespace App\Traits;

use App\Enums\AttendanceState;
use App\Exceptions\StateViolationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HasAttendanceStateMachine - State Machine Implementation for Attendance Model
 *
 * ARCHITECTURE:
 * - All state changes go through this trait
 * - Validates transitions before applying
 * - Wraps changes in database transactions
 * - Logs all state transitions for audit trail
 *
 * USAGE:
 * $attendance->checkIn($teacher, $location);
 * $attendance->checkOut($teacher, $location);
 * $attendance->requestCorrection($reason);
 * $attendance->approve($approver);
 * $attendance->reject($approver, $reason);
 *
 * STATE DIAGRAM:
 *
 *     INIT ──checkIn()──> CHECKED_IN ──checkOut()──> CHECKED_OUT
 *                                                         │
 *                                         requestCorrection()
 *                                                         ▼
 *                                               PENDING_APPROVAL
 *                                                    │      │
 *                                          approve() │      │ reject()
 *                                                    ▼      ▼
 *                                              APPROVED  REJECTED
 *                                                           │
 *                                               requestCorrection() (retry)
 *                                                           │
 *                                                           ▼
 *                                               PENDING_APPROVAL
 */
trait HasAttendanceStateMachine
{
    /**
     * Boot the trait - register model events
     */
    public static function bootHasAttendanceStateMachine(): void
    {
        static::creating(function ($model) {
            // Set initial state if not provided
            if (empty($model->state)) {
                $model->state = AttendanceState::INIT->value;
            }
        });
    }

    /**
     * Get current state as enum
     */
    public function getCurrentState(): AttendanceState
    {
        // The 'state' attribute may already be cast to AttendanceState enum
        if ($this->state instanceof AttendanceState) {
            return $this->state;
        }
        
        return AttendanceState::tryFrom($this->state) ?? AttendanceState::INIT;
    }

    /**
     * Check if transition to target state is allowed
     */
    public function canTransitionTo(AttendanceState $targetState): bool
    {
        return $this->getCurrentState()->canTransitionTo($targetState);
    }

    /**
     * Transition to new state with validation
     *
     * @throws StateViolationException
     */
    protected function transitionTo(
        AttendanceState $newState,
        ?User $actor = null,
        ?string $reason = null
    ): self {
        $currentState = $this->getCurrentState();

        // Validate transition
        if (!$currentState->canTransitionTo($newState)) {
            throw StateViolationException::illegalTransition($currentState, $newState);
        }

        // Apply transition in transaction
        return DB::transaction(function () use ($newState, $currentState, $actor, $reason) {
            $previousState = $this->state;

            // Use internal method to bypass mutator protection
            $this->setStateInternal($newState);
            
            // Sync legacy status field for backward compatibility
            $this->syncLegacyStatus();
            
            $this->save();

            // Log the transition
            $this->logStateTransition($currentState, $newState, $actor, $reason);

            Log::info('Attendance state transition', [
                'attendance_id' => $this->id,
                'student_id' => $this->student_id,
                'from' => $previousState,
                'to' => $newState->value,
                'actor_id' => $actor?->id,
            ]);

            return $this;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC STATE TRANSITION METHODS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Record check-in
     *
     * Valid from: INIT
     * Results in: CHECKED_IN
     *
     * @throws StateViolationException
     */
    public function checkIn(
        User $recordedBy,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $deviceId = null
    ): self {
        $currentState = $this->getCurrentState();

        // Special error messages for common violations
        if ($currentState === AttendanceState::CHECKED_IN) {
            throw StateViolationException::alreadyCheckedIn();
        }

        if ($currentState === AttendanceState::CHECKED_OUT) {
            throw StateViolationException::cannotCheckInAfterCheckOut();
        }

        // Update attendance data
        $this->fill([
            'check_in_time' => school_now($this->schedule->school),
            'recorded_by' => $recordedBy->id,
            'lat_in' => $latitude,
            'lng_in' => $longitude,
            'device_id_in' => $deviceId,
        ]);

        return $this->transitionTo(AttendanceState::CHECKED_IN, $recordedBy, 'Check-in recorded');
    }

    /**
     * Record check-out
     *
     * Valid from: CHECKED_IN
     * Results in: CHECKED_OUT
     *
     * @throws StateViolationException
     */
    public function checkOut(
        User $recordedBy,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $deviceId = null
    ): self {
        $currentState = $this->getCurrentState();

        // Must check-in first
        if ($currentState === AttendanceState::INIT) {
            throw StateViolationException::mustCheckInFirst();
        }

        // Update attendance data
        $this->fill([
            'check_out_time' => school_now($this->schedule->school),
            'lat_out' => $latitude,
            'lng_out' => $longitude,
            'device_id_out' => $deviceId,
        ]);

        return $this->transitionTo(AttendanceState::CHECKED_OUT, $recordedBy, 'Check-out recorded');
    }

    /**
     * Request correction/modification approval
     *
     * Valid from: CHECKED_OUT, REJECTED
     * Results in: PENDING_APPROVAL
     *
     * @throws StateViolationException
     */
    public function requestCorrection(User $requestedBy, string $reason): self
    {
        $this->correction_reason = $reason;
        $this->correction_requested_by = $requestedBy->id;
        $this->correction_requested_at = school_now($this->schedule->school);

        return $this->transitionTo(
            AttendanceState::PENDING_APPROVAL,
            $requestedBy,
            "Correction requested: {$reason}"
        );
    }

    /**
     * Approve attendance/correction
     *
     * Valid from: PENDING_APPROVAL
     * Results in: APPROVED
     *
     * @throws StateViolationException
     */
    public function approve(User $approver, ?string $notes = null): self
    {
        $this->approved_by = $approver->id;
        $this->approved_at = school_now($this->schedule->school);
        $this->approval_notes = $notes;

        return $this->transitionTo(AttendanceState::APPROVED, $approver, $notes ?? 'Approved');
    }

    /**
     * Reject attendance/correction
     *
     * Valid from: PENDING_APPROVAL
     * Results in: REJECTED
     *
     * @throws StateViolationException
     */
    public function reject(User $rejector, string $reason): self
    {
        $this->rejected_by = $rejector->id;
        $this->rejected_at = school_now($this->schedule->school);
        $this->rejection_reason = $reason;

        return $this->transitionTo(AttendanceState::REJECTED, $rejector, $reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Scope: attendance in specific state
     */
    public function scopeInState($query, AttendanceState $state)
    {
        return $query->where('state', $state->value);
    }

    /**
     * Scope: attendance needing approval
     */
    public function scopePendingApproval($query)
    {
        return $query->where('state', AttendanceState::PENDING_APPROVAL->value);
    }

    /**
     * Scope: completed attendance (checked out or approved)
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('state', [
            AttendanceState::CHECKED_OUT->value,
            AttendanceState::APPROVED->value,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AUDIT TRAIL
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Log state transition to attendance_logs table with comprehensive audit data
     */
    protected function logStateTransition(
        AttendanceState $from,
        AttendanceState $to,
        ?User $actor,
        ?string $reason
    ): void {
        // Check if logs relationship exists (AttendanceLog model)
        if (!method_exists($this, 'logs')) {
            return;
        }

        // Capture changed attributes for audit trail
        $changes = $this->captureStateTransitionChanges($from, $to);

        // Get device information from request
        $deviceInfo = $this->captureDeviceInfo();

        try {
            $this->logs()->create([
                'attendance_id' => $this->id,
                'school_id' => $this->school_id,
                'user_id' => $actor?->id ?? $this->student_id,
                'action' => 'state_transition',
                'from_state' => $from->value,
                'to_state' => $to->value,
                'performed_by' => $actor?->id,
                'reason' => $reason,
                'changes' => $changes,
                'previous_status' => $from->value,
                'new_status' => $to->value,
                'notes' => $this->buildAuditNotes($from, $to, $reason),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device_info' => $deviceInfo,
                'created_at' => school_now($this->schedule->school),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Log error but don't fail the transaction
            Log::error('Failed to create attendance audit log', [
                'attendance_id' => $this->id,
                'from' => $from->value,
                'to' => $to->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Capture all attribute changes during state transition
     */
    protected function captureStateTransitionChanges(AttendanceState $from, AttendanceState $to): array
    {
        $changes = [
            'state' => [
                'from' => $from->value,
                'to' => $to->value,
            ],
        ];

        // Track specific field changes based on transition type
        $trackedFields = [
            'check_in_time',
            'check_out_time',
            'recorded_by',
            'lat_in',
            'lng_in',
            'lat_out',
            'lng_out',
            'device_id_in',
            'device_id_out',
            'correction_reason',
            'correction_requested_by',
            'correction_requested_at',
            'approved_by',
            'approved_at',
            'approval_notes',
            'rejected_by',
            'rejected_at',
            'rejection_reason',
        ];

        foreach ($trackedFields as $field) {
            if ($this->isDirty($field)) {
                $changes[$field] = [
                    'from' => $this->getOriginal($field),
                    'to' => $this->getAttribute($field),
                ];
            }
        }

        return $changes;
    }

    /**
     * Capture device information for audit trail
     */
    protected function captureDeviceInfo(): array
    {
        $request = request();
        
        return [
            'platform' => $request->header('X-Platform', 'web'),
            'app_version' => $request->header('X-App-Version'),
            'device_model' => $request->header('X-Device-Model'),
            'os_version' => $request->header('X-OS-Version'),
            'browser' => $this->parseBrowser($request->userAgent()),
        ];
    }

    /**
     * Parse browser information from user agent
     */
    protected function parseBrowser(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        // Simple browser detection
        if (str_contains($userAgent, 'Chrome')) {
            return 'Chrome';
        } elseif (str_contains($userAgent, 'Firefox')) {
            return 'Firefox';
        } elseif (str_contains($userAgent, 'Safari')) {
            return 'Safari';
        } elseif (str_contains($userAgent, 'Edge')) {
            return 'Edge';
        }

        return 'Unknown';
    }

    /**
     * Build human-readable audit notes
     */
    protected function buildAuditNotes(AttendanceState $from, AttendanceState $to, ?string $reason): string
    {
        $notes = sprintf(
            'State transition: %s → %s',
            $from->label(),
            $to->label()
        );

        if ($reason) {
            $notes .= sprintf(' | Reason: %s', $reason);
        }

        return $notes;
    }

    // ─────────────────────────────────────────────────────────────────────
    // STATE INSPECTION
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Check if attendance is in initial state
     */
    public function isInit(): bool
    {
        return $this->getCurrentState() === AttendanceState::INIT;
    }

    /**
     * Check if already checked in
     */
    public function isCheckedIn(): bool
    {
        return $this->getCurrentState() === AttendanceState::CHECKED_IN;
    }

    /**
     * Check if already checked out
     */
    public function isCheckedOut(): bool
    {
        return $this->getCurrentState() === AttendanceState::CHECKED_OUT;
    }

    /**
     * Check if pending approval
     */
    public function isPendingApproval(): bool
    {
        return $this->getCurrentState() === AttendanceState::PENDING_APPROVAL;
    }

    /**
     * Check if approved
     */
    public function isApproved(): bool
    {
        return $this->getCurrentState() === AttendanceState::APPROVED;
    }

    /**
     * Check if rejected
     */
    public function isRejected(): bool
    {
        return $this->getCurrentState() === AttendanceState::REJECTED;
    }

    /**
     * Check if in final state (cannot be modified)
     */
    public function isFinalState(): bool
    {
        return $this->getCurrentState()->isFinal();
    }

    /**
     * Check if attendance counts as "present" for reporting
     */
    public function countsAsPresent(): bool
    {
        return $this->getCurrentState()->isPresent();
    }
}
