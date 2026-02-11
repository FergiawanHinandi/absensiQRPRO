<?php

namespace App\Services;

use App\Enums\AttendanceState;
use App\Exceptions\AttendanceException;
use App\Exceptions\StateViolationException;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AttendanceStateMachineService
 *
 * SINGLE ENTRY POINT for all attendance state changes.
 * 
 * ARCHITECTURE RULES:
 * 1. ALL state changes MUST go through this service
 * 2. NO direct status/state modification allowed on model
 * 3. Each transition is validated and logged
 * 4. Race conditions are prevented via row-level locking
 *
 * VALID TRANSITIONS:
 * - INIT            → CHECKED_IN  (via checkIn)
 * - CHECKED_IN      → CHECKED_OUT (via checkOut)
 * - CHECKED_OUT     → PENDING_APPROVAL (via requestCorrection)
 * - PENDING_APPROVAL → APPROVED (via approve)
 * - PENDING_APPROVAL → REJECTED (via reject)
 * - REJECTED        → PENDING_APPROVAL (via requestCorrection retry)
 *
 * @see AttendanceState for state definitions
 * @see HasAttendanceStateMachine for model-level implementation
 */
class AttendanceStateMachineService
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private SecurityAlertService $alertService,
        private SecurityPolicyService $policyService,
    ) {}

    /**
     * Create new attendance record in INIT state
     *
     * @param array $data Must contain: school_id, schedule_id, student_id, attendance_date
     * @return Attendance
     */
    public function createInitial(array $data): Attendance
    {
        return DB::transaction(function () use ($data) {
            // Remove any status/state from input (security)
            unset($data['status'], $data['state']);

            // Use firstOrCreate to prevent duplicates
            $attendance = Attendance::firstOrCreate(
                [
                    'student_id' => $data['student_id'],
                    'schedule_id' => $data['schedule_id'],
                    'attendance_date' => $data['attendance_date'],
                    'school_id' => $data['school_id'],
                ],
                array_merge($data, [
                    'state' => AttendanceState::INIT->value,
                    'status' => 'alpha', // Default status for INIT state
                ])
            );
            
            // If newly created, set state properly
            if ($attendance->wasRecentlyCreated) {
                $attendance->setStateInternal(AttendanceState::INIT);
                $attendance->syncLegacyStatus();
                $attendance->save();

                Log::channel('attendance')->info('Attendance created in INIT state', [
                    'attendance_id' => $attendance->id,
                    'student_id' => $data['student_id'],
                    'schedule_id' => $data['schedule_id'],
                ]);
            }

            return $attendance;
        });
    }

    /**
     * Find or create attendance for today
     *
     * @param int $studentId
     * @param int $scheduleId
     * @param int $schoolId
     * @return Attendance
     */
    public function findOrCreateForToday(int $studentId, int $scheduleId, int $schoolId): Attendance
    {
        $lockKey = "attendance_lock:{$studentId}:{$scheduleId}:" . today()->format('Y-m-d');

        return Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS)->block(5, function () use ($studentId, $scheduleId, $schoolId) {
            $existing = Attendance::where('student_id', $studentId)
                ->where('schedule_id', $scheduleId)
                ->whereDate('attendance_date', today())
                ->first();

            if ($existing) {
                return $existing;
            }

            return $this->createInitial([
                'school_id' => $schoolId,
                'schedule_id' => $scheduleId,
                'student_id' => $studentId,
                'attendance_date' => today(),
            ]);
        });
    }

    /**
     * Perform check-in transition
     *
     * INIT → CHECKED_IN
     *
     * @param Attendance $attendance
     * @param User $recordedBy
     * @param float|null $latitude
     * @param float|null $longitude
     * @param string|null $deviceId
     * @return Attendance
     * @throws StateViolationException
     */
    public function checkIn(
        Attendance $attendance,
        User $recordedBy,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $deviceId = null
    ): Attendance {
        return $this->executeWithLock($attendance, function () use ($attendance, $recordedBy, $latitude, $longitude, $deviceId) {
            // Delegate to model's state machine method
            return $attendance->checkIn($recordedBy, $latitude, $longitude, $deviceId);
        });
    }

    /**
     * Perform check-out transition
     *
     * CHECKED_IN → CHECKED_OUT
     *
     * @param Attendance $attendance
     * @param User $recordedBy
     * @param float|null $latitude
     * @param float|null $longitude
     * @param string|null $deviceId
     * @return Attendance
     * @throws StateViolationException
     */
    public function checkOut(
        Attendance $attendance,
        User $recordedBy,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $deviceId = null
    ): Attendance {
        return $this->executeWithLock($attendance, function () use ($attendance, $recordedBy, $latitude, $longitude, $deviceId) {
            return $attendance->checkOut($recordedBy, $latitude, $longitude, $deviceId);
        });
    }

    /**
     * Request correction/approval
     *
     * CHECKED_OUT|REJECTED → PENDING_APPROVAL
     *
     * @param Attendance $attendance
     * @param User $requestedBy
     * @param string $reason
     * @return Attendance
     * @throws StateViolationException
     */
    public function requestCorrection(
        Attendance $attendance,
        User $requestedBy,
        string $reason
    ): Attendance {
        return $this->executeWithLock($attendance, function () use ($attendance, $requestedBy, $reason) {
            return $attendance->requestCorrection($requestedBy, $reason);
        });
    }

    /**
     * Approve attendance/correction
     *
     * PENDING_APPROVAL → APPROVED
     *
     * @param Attendance $attendance
     * @param User $approver
     * @param string|null $notes
     * @return Attendance
     * @throws StateViolationException
     */
    public function approve(
        Attendance $attendance,
        User $approver,
        ?string $notes = null
    ): Attendance {
        // Validate approver has permission
        $this->validateApprover($approver, $attendance);

        return $this->executeWithLock($attendance, function () use ($attendance, $approver, $notes) {
            return $attendance->approve($approver, $notes);
        });
    }

    /**
     * Reject attendance/correction
     *
     * PENDING_APPROVAL → REJECTED
     *
     * @param Attendance $attendance
     * @param User $rejector
     * @param string $reason
     * @return Attendance
     * @throws StateViolationException
     */
    public function reject(
        Attendance $attendance,
        User $rejector,
        string $reason
    ): Attendance {
        // Validate rejector has permission
        $this->validateApprover($rejector, $attendance);

        return $this->executeWithLock($attendance, function () use ($attendance, $rejector, $reason) {
            return $attendance->reject($rejector, $reason);
        });
    }

    /**
     * Manual attendance entry (for sick, permit, excused)
     * 
     * Creates attendance in CHECKED_IN state directly
     * Only allowed for specific status types
     *
     * @param array $data
     * @param User $recordedBy
     * @param string $manualStatus sick|permit|excused
     * @return Attendance
     */
    public function createManualAttendance(
        array $data,
        User $recordedBy,
        string $manualStatus
    ): Attendance {
        // Validate manual status type
        if (!in_array($manualStatus, ['sick', 'permit', 'excused', 'absent'])) {
            throw new AttendanceException("Status manual tidak valid: {$manualStatus}");
        }

        return DB::transaction(function () use ($data, $recordedBy, $manualStatus) {
            unset($data['status'], $data['state']);

            // Use firstOrCreate to prevent duplicates
            $attendance = Attendance::firstOrCreate(
                [
                    'student_id' => $data['student_id'],
                    'schedule_id' => $data['schedule_id'],
                    'attendance_date' => $data['attendance_date'],
                    'school_id' => $data['school_id'],
                ],
                array_merge($data, [
                    'is_manual' => true,
                    'attendance_type' => 'manual',
                    'recorded_by' => $recordedBy->id,
                    'notes' => $data['notes'] ?? "Manual entry: {$manualStatus}",
                    'state' => AttendanceState::CHECKED_IN->value,
                    'status' => $manualStatus,
                ])
            );

            // If newly created, set state properly
            if ($attendance->wasRecentlyCreated) {
                $attendance->setStateInternal(AttendanceState::CHECKED_IN);
                $attendance->attributes['status'] = $manualStatus;
                $attendance->save();
            }

            Log::channel('attendance')->info('Manual attendance created', [
                'attendance_id' => $attendance->id,
                'student_id' => $data['student_id'],
                'manual_status' => $manualStatus,
                'recorded_by' => $recordedBy->id,
            ]);

            return $attendance;
        });
    }

    /**
     * Execute operation with row-level lock
     */
    private function executeWithLock(Attendance $attendance, callable $operation): Attendance
    {
        $lockKey = "attendance_state_lock:{$attendance->id}";

        return Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS)->block(5, function () use ($attendance, $operation) {
            // Refresh with pessimistic lock
            $attendance->refresh();

            return DB::transaction(function () use ($operation) {
                return $operation();
            });
        });
    }

    /**
     * Validate user can approve/reject attendance
     */
    private function validateApprover(User $user, Attendance $attendance): void
    {
        $allowedRoles = ['admin', 'school_admin', 'principal', 'homeroom_teacher', 'super_admin'];

        if (!in_array($user->role_type, $allowedRoles)) {
            throw new AttendanceException('Anda tidak memiliki izin untuk menyetujui/menolak absensi.');
        }

        // School isolation check
        if ($user->role_type !== 'super_admin' && $user->school_id !== $attendance->school_id) {
            $this->alertService->alertUnauthorizedAccess(
                $user,
                'attendance_approval',
                $attendance->id,
                request()->ip()
            );
            throw new AttendanceException('Anda tidak dapat menyetujui absensi dari sekolah lain.');
        }
    }

    /**
     * Get valid transitions from current state
     *
     * @param Attendance $attendance
     * @return array
     */
    public function getAvailableTransitions(Attendance $attendance): array
    {
        $currentState = $attendance->getCurrentState();
        
        return array_map(
            fn(AttendanceState $state) => [
                'state' => $state->value,
                'label' => $state->label(),
                'action' => $this->getActionForTransition($currentState, $state),
            ],
            $currentState->allowedTransitions()
        );
    }

    /**
     * Get the action name for a transition
     */
    private function getActionForTransition(AttendanceState $from, AttendanceState $to): string
    {
        return match ([$from, $to]) {
            [AttendanceState::INIT, AttendanceState::CHECKED_IN] => 'checkIn',
            [AttendanceState::CHECKED_IN, AttendanceState::CHECKED_OUT] => 'checkOut',
            [AttendanceState::CHECKED_OUT, AttendanceState::PENDING_APPROVAL] => 'requestCorrection',
            [AttendanceState::PENDING_APPROVAL, AttendanceState::APPROVED] => 'approve',
            [AttendanceState::PENDING_APPROVAL, AttendanceState::REJECTED] => 'reject',
            [AttendanceState::REJECTED, AttendanceState::PENDING_APPROVAL] => 'requestCorrection',
            default => 'unknown',
        };
    }

    /**
     * Check if transition is allowed
     */
    public function canTransition(Attendance $attendance, AttendanceState $targetState): bool
    {
        return $attendance->canTransitionTo($targetState);
    }
}
