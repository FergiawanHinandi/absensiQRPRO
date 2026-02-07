<?php

namespace App\Models;

use App\Scopes\SchoolScope;
use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StudentPermission Model
 *
 * Handles student sick/permit requests with multi-tenant isolation.
 *
 * @property int $id
 * @property int $student_id
 * @property int $school_id
 * @property int|null $class_id
 * @property string $type           sick|permit
 * @property string $reason
 * @property string|null $description
 * @property string|null $attachment_path
 * @property \Carbon\Carbon $start_date
 * @property \Carbon\Carbon $end_date
 * @property string $status         pending|approved|rejected
 * @property int|null $approved_by
 * @property \Carbon\Carbon|null $approved_at
 */
class StudentPermission extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'student_permissions';

    protected $fillable = [
        'student_id',
        'school_id',
        'class_id',
        'type',
        'reason',
        'description',
        'attachment_path',
        'start_date',
        'end_date',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ─────────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForClass($query, int $classId)
    {
        return $query->where('class_id', $classId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convert permission type to attendance status
     */
    public function getAttendanceStatus(): string
    {
        return $this->type === 'sick' ? 'sick' : 'permit';
    }

    /**
     * Check if this permission is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this permission is approved
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Get all dates covered by this permission
     *
     * @return \Illuminate\Support\Collection<\Carbon\Carbon>
     */
    public function getCoveredDates(): \Illuminate\Support\Collection
    {
        return collect($this->start_date->daysUntil($this->end_date->addDay()));
    }
}
