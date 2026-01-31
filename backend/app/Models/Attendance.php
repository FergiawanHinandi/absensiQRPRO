<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use BelongsToSchool, HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id',
        'schedule_id',
        'class_id',
        'student_id',
        'attendance_date',
        'attendance_type',
        'status',
        'check_in_time',
        'check_out_time',
        'is_manual',
        'notes',
        'attachment_url',
        'recorded_by',
        'qr_code_id',
        'lat_in',
        'lng_in',
        'device_id_in',
        'request_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'is_manual' => 'boolean',
    ];

    // Relationships
    // school() defined in BelongsToSchool trait

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function logs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    // Scopes
    public function scopeToday($query)
    {
        return $query->whereDate('attendance_date', today());
    }

    public function scopePresent($query)
    {
        return $query->whereIn('status', ['present', 'late']);
    }

    public function scopeAbsent($query)
    {
        return $query->whereIn('status', ['absent', 'sick', 'permit', 'excused']);
    }
}
