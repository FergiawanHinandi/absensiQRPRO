<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'start_date',
        'end_date',
        'semester',
        'is_active',
        'description',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the school that owns the academic year
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get classes for this academic year
     */
    public function classes()
    {
        return $this->hasMany(ClassModel::class);
    }

    /**
     * Get schedules for this academic year
     */
    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Scope to get active academic year
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get academic years for a specific school
     */
    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }
}
