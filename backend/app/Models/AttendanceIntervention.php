<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceIntervention extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'student_id',
        'teacher_id',
        'action_taken',
        'notes',
    ];

    /**
     * Action types
     */
    public const ACTION_CALL_PARENT = 'call_parent';

    public const ACTION_COUNSELING = 'counseling';

    public const ACTION_WARNING = 'warning';

    public const ACTION_HOME_VISIT = 'home_visit';

    public const ACTION_OTHER = 'other';

    /**
     * Get the student associated with the intervention.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Get the teacher who performed the intervention.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
