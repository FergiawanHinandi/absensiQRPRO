<?php

namespace App\Models;

use App\Enums\AttendanceState;
use App\Traits\BelongsToSchool;
use App\Traits\HasAttendanceStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Attendance Model with State Machine
 *
 * STATE MACHINE:
 * All status changes MUST go through state machine methods:
 * - checkIn($recordedBy, $lat, $lng, $deviceId)
 * - checkOut($recordedBy, $lat, $lng, $deviceId)
 * - requestCorrection($requestedBy, $reason)
 * - approve($approver, $notes)
 * - reject($rejector, $reason)
 *
 * Direct status modification is PROHIBITED.
 *
 * @property string $state Current state (from AttendanceState enum)
 * @see AttendanceState for valid states and transitions
 */
class Attendance extends Model
{
    use BelongsToSchool, HasAttendanceStateMachine, HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id',
        'schedule_id',
        'class_id',
        'student_id',
        'attendance_date',
        'attendance_type',
        'state',           // NEW: State machine state
        'status',          // LEGACY: Keep for backward compatibility
        'check_in_time',
        'check_out_time',
        'is_manual',
        'notes',
        'attachment_url',
        'recorded_by',
        'qr_code_id',
        'lat_in',
        'lng_in',
        'lat_out',         // NEW: Check-out location
        'lng_out',         // NEW: Check-out location
        'device_id_in',
        'device_id_out',   // NEW: Check-out device
        'request_id',
        // Approval workflow fields
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

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'correction_requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'is_manual' => 'boolean',
        'state' => AttendanceState::class,
    ];

    /**
     * Default attribute values
     */
    protected $attributes = [
        'state' => 'init',
        'is_manual' => false,
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    // school() defined in BelongsToSchool trait

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function correctionRequester()
    {
        return $this->belongsTo(User::class, 'correction_requested_by');
    }

    public function logs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopeToday($query)
    {
        return $query->whereDate('attendance_date', today());
    }

    /**
     * @deprecated Use scopeInState(AttendanceState::CHECKED_IN) or countsAsPresent()
     */
    public function scopePresent($query)
    {
        return $query->whereIn('state', [
            AttendanceState::CHECKED_IN->value,
            AttendanceState::CHECKED_OUT->value,
            AttendanceState::APPROVED->value,
        ]);
    }

    /**
     * @deprecated Use scopeInState(AttendanceState::INIT)
     */
    public function scopeAbsent($query)
    {
        return $query->where('state', AttendanceState::INIT->value);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get human-readable state label
     */
    public function getStateLabelAttribute(): string
    {
        return $this->getCurrentState()->label();
    }

    /**
     * Get state badge color for UI
     */
    public function getStateColorAttribute(): string
    {
        return $this->getCurrentState()->color();
    }
}
