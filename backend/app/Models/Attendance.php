<?php

namespace App\Models;

use App\Enums\AttendanceState;
use App\Traits\BelongsToSchool;
use App\Traits\HasAttendanceStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

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
    use BelongsToSchool, HasAttendanceStateMachine, HasFactory, SoftDeletes, LogsActivity;

    /**
     * SECURITY: 'status' and 'state' are EXCLUDED from $fillable
     * All state changes MUST go through state machine methods.
     * @see HasAttendanceStateMachine
     */
    protected $fillable = [
        'school_id',
        'schedule_id',
        'class_id',
        'student_id',
        'attendance_date',
        'attendance_type',
        'session_type',
        // 'state' - REMOVED: Use state machine methods only
        // 'status' - REMOVED: Legacy field, use state machine
        'check_in_time',
        'check_out_time',
        'scanned_at',
        'is_manual',
        'source',
        'notes',
        'attachment_url',
        'recorded_by',
        'verified_by',
        'qr_code_id',
        'lat_in',
        'lng_in',
        'lat_out',
        'lng_out',
        'device_id_in',
        'device_id_out',
        'request_id',
        // Approval workflow fields - managed by state machine
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

    /**
     * SEC-02: Guard for fields that must NEVER be mass-assignable.
     * 'id' — auto-increment, tidak boleh diisi manual.
     * 'status' dan 'state' — hanya boleh diubah via state machine methods:
     *   checkIn(), checkOut(), approve(), reject().
     *
     * $fillable sudah didefinisikan di atas sebagai whitelist.
     * $guarded di sini sebagai lapisan keamanan tambahan (defense-in-depth).
     */
    protected $guarded = ['id', 'status', 'state'];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'scanned_at' => 'datetime',
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
        'status' => 'absent', // Default status for INIT state
        'is_manual' => false,
    ];

    /**
     * Boot method - set default state during creation
     */
    protected static function boot()
    {
        parent::boot();

        // Ensure default state is set during creation
        static::creating(function ($attendance) {
            // Set default state directly in attributes array (bypassing mutator)
            if (empty($attendance->attributes['state'])) {
                $attendance->attributes['state'] = 'init';
            }
            
            // Sync legacy status from state
            if (empty($attendance->attributes['status'])) {
                $attendance->attributes['status'] = 'absent'; // Default for INIT state
            }
            
            // Set default request_id if not provided
            if (empty($attendance->attributes['request_id'])) {
                $attendance->attributes['request_id'] = \Illuminate\Support\Str::uuid()->toString();
            }
        });
    }

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

    // ─────────────────────────────────────────────────────────────────────
    // MUTATORS - BLOCK DIRECT STATUS/STATE MODIFICATION
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Block direct status modification ALWAYS (except when unguarded for testing)
     * 
     * Status is automatically synced from state via syncLegacyStatus()
     * 
     * @throws \App\Exceptions\StateViolationException
     */
    public function setStatusAttribute($value): void
    {
        // Allow direct assignment when model is unguarded (for testing purposes)
        if (static::isUnguarded()) {
            $this->attributes['status'] = $value;
            return;
        }
        
        // ✅ Block direct modification in normal operation
        throw \App\Exceptions\StateViolationException::directModificationBlocked(
            'status',
            'Use state machine methods: checkIn(), checkOut(), approve(), reject()'
        );
    }

    /**
     * Block direct state modification via mass assignment
     * State can ONLY be changed via transitionTo() in HasAttendanceStateMachine
     * 
     * @throws \App\Exceptions\StateViolationException
     */
    public function setStateAttribute($value): void
    {
        // Allow direct assignment when model is unguarded (for testing purposes)
        if (static::isUnguarded()) {
            $this->attributes['state'] = $value instanceof \App\Enums\AttendanceState 
                ? $value->value 
                : $value;
            return;
        }
        
        // Allow only from state machine (via internal flag)
        if (!$this->isInternalStateChange) {
            throw \App\Exceptions\StateViolationException::directModificationBlocked(
                'state',
                'Use state machine methods: checkIn(), checkOut(), approve(), reject()'
            );
        }
        
        $this->attributes['state'] = $value instanceof \App\Enums\AttendanceState 
            ? $value->value 
            : $value;
    }

    /**
     * Flag to allow internal state changes from state machine
     */
    protected bool $isInternalStateChange = false;

    /**
     * Allow state machine to set state internally
     */
    public function setStateInternal(\App\Enums\AttendanceState $state): void
    {
        $this->isInternalStateChange = true;
        $this->state = $state;
        $this->isInternalStateChange = false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // LEGACY STATUS SYNC
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Sync legacy status field with state (for backward compatibility)
     * Called automatically after state transitions
     */
    public function syncLegacyStatus(): void
    {
        $this->attributes['status'] = match($this->getCurrentState()) {
            \App\Enums\AttendanceState::INIT => 'absent',
            \App\Enums\AttendanceState::CHECKED_IN => 'present',
            \App\Enums\AttendanceState::CHECKED_OUT => 'present',
            \App\Enums\AttendanceState::PENDING_APPROVAL => 'pending',
            \App\Enums\AttendanceState::APPROVED => 'present',
            \App\Enums\AttendanceState::REJECTED => 'rejected',
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // ACTIVITY LOG CONFIGURATION (Spatie)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Configure activity logging for state transitions
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'state',
                'status',
                'check_in_time',
                'check_out_time',
                'recorded_by',
                'approved_by',
                'rejected_by',
                'correction_requested_by',
                'correction_reason',
                'approval_notes',
                'rejection_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('attendance_state_machine')
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Attendance record created',
                'updated' => 'Attendance state changed',
                'deleted' => 'Attendance record deleted',
                default => "Attendance {$eventName}",
            });
    }
}
