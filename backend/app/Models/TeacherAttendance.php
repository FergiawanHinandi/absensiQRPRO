<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherAttendance extends Model
{
    use BelongsToSchool, HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'attendance_date',
        'status',
        'check_in_time',
        'check_out_time',
        'lat_in',
        'lng_in',
        'lat_out',
        'lng_out',
        'accuracy_in',
        'accuracy_out',
        'device_id_in',
        'device_id_out',
        'distance_in',
        'distance_out',
        'is_manual',
        'recorded_by',
        'notes',
        'request_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'is_manual' => 'boolean',
        'lat_in' => 'decimal:8',
        'lng_in' => 'decimal:8',
        'lat_out' => 'decimal:8',
        'lng_out' => 'decimal:8',
        'accuracy_in' => 'float',
        'accuracy_out' => 'float',
        'distance_in' => 'float',
        'distance_out' => 'float',
    ];

    // Relationships
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function anomalies()
    {
        return $this->hasMany(TeacherAttendanceAnomaly::class);
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

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    public function scopeWithCheckIn($query)
    {
        return $query->whereNotNull('check_in_time');
    }

    public function scopeWithCheckOut($query)
    {
        return $query->whereNotNull('check_out_time');
    }

    /**
     * Check if teacher has already checked in today
     */
    public static function hasCheckedInToday(int $teacherId): bool
    {
        return self::where('teacher_id', $teacherId)
            ->whereDate('attendance_date', today())
            ->whereNotNull('check_in_time')
            ->exists();
    }

    /**
     * Check if teacher has already checked out today
     */
    public static function hasCheckedOutToday(int $teacherId): bool
    {
        return self::where('teacher_id', $teacherId)
            ->whereDate('attendance_date', today())
            ->whereNotNull('check_out_time')
            ->exists();
    }

    /**
     * Get today's attendance for a teacher (with lock if needed)
     */
    public static function getTodayForTeacher(int $teacherId, bool $lock = false): ?self
    {
        $query = self::where('teacher_id', $teacherId)
            ->whereDate('attendance_date', today());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
