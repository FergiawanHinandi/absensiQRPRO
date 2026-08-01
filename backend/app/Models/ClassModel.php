<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassModel extends Model
{
    use BelongsToSchool, HasFactory, SoftDeletes;

    protected $table = 'classes';

    /**
     * Mass assignable attributes.
     * SECURITY: Explicit fillable prevents mass assignment attacks
     */
    protected $fillable = [
        'school_id',
        'academic_year_id',
        'name',
        'grade_level',
        'homeroom_teacher_id',
        'max_students',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_students' => 'integer',
    ];

    // Relationships
    public function homeroom_teacher()
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'class_students', 'class_id', 'student_id')
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }

    public function academic_year()
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
