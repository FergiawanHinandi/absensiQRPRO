<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspiciousStudent extends Model
{
    use HasFactory, BelongsToSchool;

    protected $fillable = [
        'school_id',
        'student_id',
        'flag_reason',
        'violation_count',
        'evidence',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'flagged_at',
        'cleared_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'violation_count' => 'integer',
        'flagged_at' => 'datetime',
        'cleared_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the school that owns the suspicious student record.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the student.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the user who reviewed.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope for flagged students.
     */
    public function scopeFlagged($query)
    {
        return $query->where('status', 'flagged');
    }

    /**
     * Scope for under review.
     */
    public function scopeUnderReview($query)
    {
        return $query->where('status', 'under_review');
    }

    /**
     * Scope for school.
     */
    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Increment violation count.
     */
    public function incrementViolations()
    {
        $this->increment('violation_count');
    }

    /**
     * Mark as cleared.
     */
    public function markAsCleared($reviewerId, $notes = null)
    {
        $this->update([
            'status' => 'cleared',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'cleared_at' => now(),
        ]);
    }

    /**
     * Mark as confirmed.
     */
    public function markAsConfirmed($reviewerId, $notes = null)
    {
        $this->update([
            'status' => 'confirmed',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }
}
