<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    use BelongsToSchool, HasFactory;

    /**
     * Event type constants
     */
    public const EVENT_OUTSIDE_SCHEDULE = 'outside_schedule';

    public const EVENT_OUTSIDE_RADIUS = 'outside_radius';

    public const EVENT_UNAPPROVED_DEVICE = 'unapproved_device';

    public const EVENT_DUPLICATE_ATTEMPT = 'duplicate_attempt';

    public const EVENT_MOCK_LOCATION = 'mock_location';

    public const EVENT_QR_REPLAY = 'qr_replay';

    public const EVENT_QR_OWNERSHIP_VIOLATION = 'qr_ownership_violation';

    public const EVENT_POOR_GPS_ACCURACY = 'poor_gps_accuracy';

    public const EVENT_SUSPICIOUS_DEVICE_CHANGE = 'suspicious_device_change';

    public const EVENT_IMPOSSIBLE_TRAVEL = 'impossible_travel';

    public const EVENT_UNAUTHORIZED_ACCESS = 'unauthorized_access';

    public const EVENT_RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';

    /**
     * Severity constants
     */
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'school_id',
        'user_id',
        'user_type',
        'event_type',
        'severity',
        'ip_address',
        'user_agent',
        'device_id',
        'latitude',
        'longitude',
        'context',
        'message',
        'is_resolved',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'context' => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    // Scopes
    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeResolved($query)
    {
        return $query->where('is_resolved', true);
    }

    public function scopeByType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Mark event as resolved
     */
    public function markAsResolved(int $resolverId, ?string $notes = null): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_by' => $resolverId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Get human-readable event type label
     */
    public function getEventTypeLabelAttribute(): string
    {
        return match ($this->event_type) {
            self::EVENT_OUTSIDE_SCHEDULE => 'Scan di Luar Jadwal',
            self::EVENT_OUTSIDE_RADIUS => 'Scan di Luar Radius',
            self::EVENT_UNAPPROVED_DEVICE => 'Perangkat Tidak Disetujui',
            self::EVENT_DUPLICATE_ATTEMPT => 'Percobaan Duplikat',
            self::EVENT_MOCK_LOCATION => 'Lokasi Palsu',
            self::EVENT_QR_REPLAY => 'QR Replay Attack',
            self::EVENT_QR_OWNERSHIP_VIOLATION => 'Pelanggaran Kepemilikan QR',
            self::EVENT_POOR_GPS_ACCURACY => 'Akurasi GPS Buruk',
            self::EVENT_SUSPICIOUS_DEVICE_CHANGE => 'Pergantian Perangkat Mencurigakan',
            self::EVENT_IMPOSSIBLE_TRAVEL => 'Perjalanan Tidak Mungkin',
            self::EVENT_UNAUTHORIZED_ACCESS => 'Akses Tidak Sah',
            self::EVENT_RATE_LIMIT_EXCEEDED => 'Batas Rate Terlampaui',
            default => $this->event_type,
        };
    }

    /**
     * Get severity badge color
     */
    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_LOW => 'green',
            self::SEVERITY_MEDIUM => 'yellow',
            self::SEVERITY_HIGH => 'orange',
            self::SEVERITY_CRITICAL => 'red',
            default => 'gray',
        };
    }
}
