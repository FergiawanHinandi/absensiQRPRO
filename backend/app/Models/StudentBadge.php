<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StudentBadge Model
 * 
 * Represents badges earned by students in the gamification system.
 * Tenant-scoped through student relationship.
 */
class StudentBadge extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'school_id',
        'student_id',
        'badge_id',
        'earned_at',
        'metadata',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the student who earned this badge
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Get the badge definition
     */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
