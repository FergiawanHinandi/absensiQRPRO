<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CRITICAL: Security Alert Model with proper defaults
 */
class SecurityAlert extends Model
{
    use HasFactory;

    // Severity constants
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    // Type constants
    public const TYPE_LOGIN = 'login';
    public const TYPE_BEHAVIOR_ANOMALY = 'behavior_anomaly';
    public const TYPE_GEOFENCE_VIOLATION = 'geofence_violation';
    public const TYPE_DEVICE_MISMATCH = 'device_mismatch';

    protected $fillable = [
        'school_id',
        'type',
        'severity',
        'description',
        'ip_address',
    ];

    protected $attributes = [
        'type' => 'login',
        'severity' => 'medium',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationship with School
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * CRITICAL: Safe create method with defaults
     */
    public static function createAlert(array $data): self
    {
        return self::create([
            'school_id' => $data['school_id'] ?? null,
            'type' => $data['type'] ?? 'login',
            'severity' => $data['severity'] ?? 'medium',
            'description' => $data['description'] ?? 'Security event detected',
            'ip_address' => $data['ip_address'] ?? request()->ip(),
        ]);
    }

    /**
     * CRITICAL: Log login attempt
     */
    public static function logLoginAttempt(int $schoolId = null, string $description = null, string $severity = 'medium'): void
    {
        try {
            self::createAlert([
                'school_id' => $schoolId,
                'type' => 'login',
                'severity' => $severity,
                'description' => $description ?? 'New login detected',
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't break login process
            \Log::error('Failed to create security alert', [
                'error' => $e->getMessage(),
                'school_id' => $schoolId,
                'description' => $description,
            ]);
        }
    }

    /**
     * Check if alert should trigger notification
     */
    public function shouldNotify(): bool
    {
        // Only notify for high and critical severity
        return in_array($this->severity, [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }
}