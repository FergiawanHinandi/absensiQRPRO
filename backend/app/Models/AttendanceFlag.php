<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceFlag extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'attendance_id',
        'student_id',
        'teacher_id',
        'flag_type',
        'severity',
        'details',
        'device_id',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
