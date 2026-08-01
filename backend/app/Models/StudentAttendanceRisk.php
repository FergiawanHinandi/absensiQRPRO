<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAttendanceRisk extends Model
{
    use HasFactory, BelongsToSchool;

    protected $table = 'student_attendance_risk';

    protected $fillable = [
        'student_id',
        'school_id',
        'risk_score',
        'risk_level',
        'risky_weekday',
        'factors_json',
        'calculated_at',
    ];

    protected $casts = [
        'factors_json'   => 'array',
        'calculated_at'  => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
