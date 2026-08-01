<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use App\Traits\BelongsToSchool;

/**
 * Immutable Security Log Model
 *
 * This model represents tamper-proof audit trail entries with hash chaining.
 * Records cannot be updated or deleted - only inserted.
 *
 * @property int $id
 * @property string $event_type
 * @property int|null $user_id
 * @property int|null $school_id
 * @property string $description
 * @property array|null $metadata
 * @property string $previous_hash
 * @property string $current_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int $sequence_number
 * @property \Carbon\Carbon $created_at
 */
class ImmutableSecurityLog extends Model
{
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    /**
     * Disable timestamps auto-management (we only use created_at)
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     */
    protected $table = 'immutable_security_logs';

    /**
     * The attributes that are mass assignable.
     * Note: current_hash and previous_hash should only be set by the service
     */
    protected $fillable = [
        'event_type',
        'user_id',
        'school_id',
        'description',
        'metadata',
        'previous_hash',
        'current_hash',
        'ip_address',
        'user_agent',
        'sequence_number',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s', // Fixed format for hash consistency
        'sequence_number' => 'integer',
    ];

    /**
     * Event type constants
     */
    public const TYPE_GENESIS_BLOCK = 'GENESIS_BLOCK';

    public const TYPE_GEOFENCE_VIOLATION = 'GEOFENCE_VIOLATION';

    public const TYPE_DEVICE_MISMATCH = 'DEVICE_MISMATCH';

    public const TYPE_QR_REPLAY_ATTEMPT = 'QR_REPLAY_ATTEMPT';

    public const TYPE_UNAUTHORIZED_ACCESS = 'UNAUTHORIZED_ACCESS';

    public const TYPE_BEHAVIOR_ANOMALY = 'BEHAVIOR_ANOMALY';

    public const TYPE_INVESTIGATION_REPORT = 'INVESTIGATION_REPORT_GENERATED';

    public const TYPE_SECURITY_DASHBOARD_ACCESS = 'SECURITY_DASHBOARD_ACCESS';

    public const TYPE_LOGIN_ATTEMPT = 'LOGIN_ATTEMPT';

    public const TYPE_FAILED_AUTH = 'FAILED_AUTH';

    public const TYPE_RATE_LIMIT_BREACH = 'RATE_LIMIT_BREACH';

    public const TYPE_CROSS_SCHOOL_ATTEMPT = 'CROSS_SCHOOL_ATTEMPT';

    public const TYPE_ADMIN_ACTION = 'ADMIN_ACTION';

    public const TYPE_CONFIG_CHANGE = 'CONFIG_CHANGE';

    public const TYPE_INTEGRITY_CHECK = 'INTEGRITY_CHECK';

    public const TYPE_TAMPERING_DETECTED = 'TAMPERING_DETECTED';

    public const TYPE_SECURITY_EVENT = 'SECURITY_EVENT';

    public const TYPE_FAILED_ATTEMPT_SPIKE = 'FAILED_ATTEMPT_SPIKE';

    public const TYPE_RACE_CONDITION_BLOCKED = 'RACE_CONDITION_BLOCKED';

    public const TYPE_IMPOSSIBLE_TRAVEL = 'IMPOSSIBLE_TRAVEL';

    public const TYPE_BACKUP_FAILURE = 'BACKUP_FAILURE';

    public const TYPE_LOG_TAMPERING = 'LOG_TAMPERING_DETECTED';

    /**
     * Boot the model and add protection against modifications.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent updates at the application level
        static::updating(function ($model) {
            Log::channel('security')->critical('Attempted UPDATE on immutable_security_logs', [
                'record_id' => $model->id,
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
            ]);

            throw new \RuntimeException(
                'ImmutableSecurityLog records cannot be updated. This is a security violation attempt.'
            );
        });

        // Prevent deletes at the application level
        static::deleting(function ($model) {
            Log::channel('security')->critical('Attempted DELETE on immutable_security_logs', [
                'record_id' => $model->id,
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
            ]);

            throw new \RuntimeException(
                'ImmutableSecurityLog records cannot be deleted. This is a security violation attempt.'
            );
        });
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the user associated with this log entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the school associated with this log entry.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to filter by event type.
     */
    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope to filter by school.
     */
    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get entries in a date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to order by sequence for verification.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence_number');
    }

    // =========================================================================
    // HASH VERIFICATION
    // =========================================================================

    /**
     * Calculate the expected hash for this record.
     */
    public function calculateHash(): string
    {
        $hashInput = implode('|', [
            $this->event_type,
            $this->user_id ?? '',
            $this->school_id ?? '',
            $this->description,
            is_array($this->metadata) ? json_encode($this->metadata) : ($this->metadata ?? ''),
            $this->previous_hash,
            $this->created_at->format('Y-m-d H:i:s'), // Consistent format - no microseconds
            $this->sequence_number,
        ]);

        return hash('sha256', $hashInput);
    }

    /**
     * Verify if this record's hash is valid.
     */
    public function verifyHash(): bool
    {
        return $this->current_hash === $this->calculateHash();
    }

    /**
     * Verify chain link with previous record.
     */
    public function verifyChainLink(?self $previousRecord): bool
    {
        if ($this->sequence_number === 0) {
            // Genesis block - previous_hash should be all zeros
            return $this->previous_hash === str_repeat('0', 64);
        }

        if (! $previousRecord) {
            return false;
        }

        return $this->previous_hash === $previousRecord->current_hash;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the last record in the chain.
     */
    public static function getLastRecord(): ?self
    {
        return static::orderByDesc('sequence_number')->first();
    }

    /**
     * Get the next sequence number.
     */
    public static function getNextSequenceNumber(): int
    {
        $last = static::getLastRecord();

        return $last ? $last->sequence_number + 1 : 0;
    }

    /**
     * Check if this is the genesis block.
     */
    public function isGenesisBlock(): bool
    {
        return $this->event_type === self::EVENT_GENESIS && $this->sequence_number === 0;
    }

    /**
     * Get human-readable event type label.
     */
    public function getEventLabelAttribute(): string
    {
        return match ($this->event_type) {
            self::EVENT_GENESIS => 'Chain Initialized',
            self::EVENT_GEOFENCE_VIOLATION => 'Geofence Violation',
            self::EVENT_DEVICE_MISMATCH => 'Device Mismatch',
            self::EVENT_QR_REPLAY_ATTEMPT => 'QR Replay Attempt',
            self::EVENT_BEHAVIOR_ANOMALY => 'Behavior Anomaly',
            self::EVENT_INVESTIGATION_REPORT => 'Investigation Report Generated',
            self::EVENT_SECURITY_DASHBOARD_ACCESS => 'Security Dashboard Access',
            self::EVENT_LOGIN_ATTEMPT => 'Login Attempt',
            self::EVENT_FAILED_AUTH => 'Failed Authentication',
            self::EVENT_RATE_LIMIT_BREACH => 'Rate Limit Breach',
            self::EVENT_CROSS_SCHOOL_ATTEMPT => 'Cross-School Attempt',
            self::EVENT_ADMIN_ACTION => 'Admin Action',
            self::EVENT_CONFIG_CHANGE => 'Configuration Change',
            self::EVENT_INTEGRITY_CHECK => 'Integrity Check',
            self::EVENT_TAMPERING_DETECTED => 'Tampering Detected',
            default => $this->event_type,
        };
    }
}
