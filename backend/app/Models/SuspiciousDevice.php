<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToSchool;

class SuspiciousDevice extends Model
{
    use HasFactory;
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    protected $fillable = [
        'school_id',
        'device_id',
        'unique_students_count',
        'scan_attempt_count',
        'failed_attempt_count',
        'student_ids',
        'ip_addresses',
        'risk_level',
        'blocked',
        'blocked_at',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'unique_students_count' => 'integer',
        'scan_attempt_count' => 'integer',
        'failed_attempt_count' => 'integer',
        'student_ids' => 'array',
        'ip_addresses' => 'array',
        'blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get the school.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Scope for school.
     */
    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Scope for high risk devices.
     */
    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }

    /**
     * Scope for blocked devices.
     */
    public function scopeBlocked($query)
    {
        return $query->where('blocked', true);
    }

    /**
     * Add student to device.
     */
    public function addStudent($studentId)
    {
        $studentIds = $this->student_ids ?? [];

        if (! in_array($studentId, $studentIds)) {
            $studentIds[] = $studentId;
            $this->update([
                'student_ids' => $studentIds,
                'unique_students_count' => count($studentIds),
            ]);

            // Update risk level based on student count
            $this->updateRiskLevel();
        }
    }

    /**
     * Add IP address to device.
     */
    public function addIpAddress($ipAddress)
    {
        $ipAddresses = $this->ip_addresses ?? [];

        if (! in_array($ipAddress, $ipAddresses)) {
            $ipAddresses[] = $ipAddress;
            $this->update(['ip_addresses' => $ipAddresses]);
        }
    }

    /**
     * Increment scan attempts.
     */
    public function incrementScans($failed = false)
    {
        $this->increment('scan_attempt_count');

        if ($failed) {
            $this->increment('failed_attempt_count');
        }

        $this->update(['last_seen_at' => now()]);
        $this->updateRiskLevel();
    }

    /**
     * Update risk level based on metrics.
     */
    public function updateRiskLevel()
    {
        $riskLevel = 'low';

        // Multiple students using same device
        if ($this->unique_students_count >= 5) {
            $riskLevel = 'critical';
        } elseif ($this->unique_students_count >= 3) {
            $riskLevel = 'high';
        } elseif ($this->unique_students_count >= 2) {
            $riskLevel = 'medium';
        }

        // High failure rate
        $failureRate = $this->scan_attempt_count > 0
            ? ($this->failed_attempt_count / $this->scan_attempt_count)
            : 0;

        if ($failureRate > 0.5 && $this->scan_attempt_count > 10) {
            $riskLevel = $riskLevel === 'critical' ? 'critical' : 'high';
        }

        $this->update(['risk_level' => $riskLevel]);
    }

    /**
     * Block device.
     */
    public function block()
    {
        $this->update([
            'blocked' => true,
            'blocked_at' => now(),
        ]);
    }

    /**
     * Unblock device.
     */
    public function unblock()
    {
        $this->update([
            'blocked' => false,
            'blocked_at' => null,
        ]);
    }
}
