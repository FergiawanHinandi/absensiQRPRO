<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance Daily Class Summary Model
 * 
 * Pre-aggregated daily attendance counts per class for dashboard optimization.
 * Updated in real-time via AttendanceObserver.
 * 
 * @property int $id
 * @property int $school_id
 * @property int $class_id
 * @property string $attendance_date
 * @property int $total_students
 * @property int $present_count
 * @property int $late_count
 * @property int $absent_count
 * @property int $sick_count
 * @property int $permit_count
 * @property int $excused_count
 * @property int $alpha_count
 * @property \Carbon\Carbon|null $last_updated_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class AttendanceDailyClassSummary extends Model
{
    use BelongsToSchool, HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'attendance_daily_class_summaries';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'school_id',
        'class_id',
        'attendance_date',
        'total_students',
        'present_count',
        'late_count',
        'absent_count',
        'sick_count',
        'permit_count',
        'excused_count',
        'alpha_count',
        'last_updated_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'attendance_date' => 'date',
        'total_students' => 'integer',
        'present_count' => 'integer',
        'late_count' => 'integer',
        'absent_count' => 'integer',
        'sick_count' => 'integer',
        'permit_count' => 'integer',
        'excused_count' => 'integer',
        'alpha_count' => 'integer',
        'last_updated_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get the school that owns the summary.
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the class that owns the summary.
     */
    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Scope a query to only include summaries for a specific date.
     */
    public function scopeForDate($query, string $date)
    {
        return $query->where('attendance_date', $date);
    }

    /**
     * Scope a query to only include summaries for today.
     */
    public function scopeToday($query)
    {
        return $query->where('attendance_date', today()->toDateString());
    }

    /**
     * Scope a query to only include summaries for a date range.
     */
    public function scopeDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('attendance_date', [$startDate, $endDate]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get the total attended students (present + late + sick + permit + excused).
     */
    public function getAttendedStudentsAttribute(): int
    {
        return $this->present_count 
            + $this->late_count 
            + $this->sick_count 
            + $this->permit_count 
            + $this->excused_count;
    }

    /**
     * Get the attendance rate as a percentage.
     */
    public function getAttendanceRateAttribute(): float
    {
        if ($this->total_students === 0) {
            return 0.0;
        }

        return round(($this->attended_students / $this->total_students) * 100, 2);
    }

    /**
     * Get the alpha rate as a percentage.
     */
    public function getAlphaRateAttribute(): float
    {
        if ($this->total_students === 0) {
            return 0.0;
        }

        return round(($this->alpha_count / $this->total_students) * 100, 2);
    }
}

