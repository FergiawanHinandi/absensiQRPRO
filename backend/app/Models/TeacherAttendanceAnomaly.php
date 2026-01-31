<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherAttendanceAnomaly extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'teacher_attendance_id',
        'anomaly_type',
        'severity',
        'details',
        'latitude',
        'longitude',
        'device_id',
        'is_reviewed',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'details' => 'array',
        'is_reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    // Relationships
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function attendance()
    {
        return $this->belongsTo(TeacherAttendance::class, 'teacher_attendance_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Scopes
    public function scopeUnreviewed($query)
    {
        return $query->where('is_reviewed', false);
    }

    public function scopeReviewed($query)
    {
        return $query->where('is_reviewed', true);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

    /**
     * Mark anomaly as reviewed
     */
    public function markAsReviewed(int $reviewerId, ?string $notes = null): void
    {
        $this->update([
            'is_reviewed' => true,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }
}
